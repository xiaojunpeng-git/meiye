<?php
declare(strict_types=1);

/**
 * 本地真实库事务冒烟：挑一笔已结算销售收款，更正后核对原事实不变、
 * 有效方式投影、审计、幂等及旧值冲突，再整笔回滚。不留下测试事实。
 */
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\order\CashierV3PaymentMethodCorrectionServices;
use app\services\cashier\v3\order\CashierV3SalesOrderQueryServices;
use app\services\report\StoreUnifiedReportPhaseTwoServices;
use app\services\report\StoreUnifiedReportServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();
$tenant = '0';
$row = Db::name('cashier_v3_payment_collection')->alias('c')
    ->join('cashier_v3_sales_order o', 'o.tenant_id=c.tenant_id AND o.order_id=c.sales_order_id')
    ->where('c.tenant_id', $tenant)->where('c.collection_direction', 'forward')
    ->where('c.collection_status', 'settled')->where('c.payment_method', 'cash')
    ->where('c.amount_cents', '>', 0)->where('o.order_status', 'settled')
    ->where('o.order_direction', 'forward')
    ->field('c.collection_id,c.sales_order_id,c.amount_cents,c.store_id,c.payment_method')
    ->order('c.id', 'desc')->find();
if (!$row) throw new RuntimeException('没有可测试的本地现金正式收款');
$storeId = (int)$row['store_id'];
$operatorId = (int)Db::name('system_store_staff')->where('store_id', $storeId)->where('status', 1)->value('id');
$organizationId = (string)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
if (!$operatorId || $organizationId === '') throw new RuntimeException('本地门店操作员或组织缺失');
$operator = new CashierV3OperatorScope($storeId, $operatorId, $organizationId, $tenant);
$profile = (array)Db::name('system_store_staff')->where('id', $operatorId)->find();
$scope = new CashierV3DataScopeContext($operatorId, $operatorId, $storeId, $tenant, $organizationId, [$storeId],
    CashierV3DataScopeContext::MODE_STORES, [], false, '', 'payment-method-smoke',
    ['cashier.v3.order_center'], $profile);
$service = new CashierV3PaymentMethodCorrectionServices();
$entry = $service->entry(['orderId' => $row['sales_order_id'], 'collectionId' => $row['collection_id']], $operator, $scope);
if (count($entry['payments']) !== 1 || (int)$entry['payments'][0]['amountCents'] !== (int)$row['amount_cents']) {
    throw new RuntimeException('编辑入口金额与正式收款不一致');
}
$target = null;
foreach ($entry['methods'] as $method) if ($method['code'] !== 'cash') { $target = $method['code']; break; }
if ($target === null) throw new RuntimeException('无可更正的测试方式');
$recorder = new CashierV3BusinessEventRecorder();
$key = 'PAYMENT-METHOD-LOCAL-' . substr(hash('sha256', (string)$row['collection_id']), 0, 20);
$payload = ['orderId' => (string)$row['sales_order_id'], 'collectionId' => (string)$row['collection_id'],
    'expectedMethod' => 'cash', 'paymentMethod' => $target, 'reason' => '本地事务回滚测试'];
$beforeFact = Db::name('cashier_v3_payment_fact')->where('tenant_id', $tenant)
    ->where('order_id', (string)$row['sales_order_id'])->where('source_line_id', (string)$row['collection_id'])
    ->where('fact_direction', 'forward')->find();
if (!$beforeFact) throw new RuntimeException('正式收款没有统一收款事实');
$factId = (string)$beforeFact['fact_id'];
$beforeAllocation = Db::name('cashier_v3_payment_sale_allocation_fact')->where('tenant_id', $tenant)
    ->where('payment_fact_id', $factId)->select()->toArray();
$businessDate = (string)Db::name('cashier_v3_sales_order')->where('order_id', $row['sales_order_id'])->value('business_date');
$range = ['start' => $businessDate, 'end' => $businessDate];
$marketBefore = (new StoreUnifiedReportPhaseTwoServices())->query('market_performance', [$storeId], $range, []);
$result = [];
Db::startTrans();
try {
    $execution = $recorder->newExecution('correct-sales-payment-method', $key, $operator, $scope,
        'payment-method-local-smoke');
    $context = ['operator_scope' => $operator, 'data_scope' => $scope,
        'event_recorder' => $recorder, 'event_execution' => $execution,
        'event_contract' => CashierV3ActionManifest::eventContractFor('correct-sales-payment-method'),
        'action' => 'correct-sales-payment-method', 'payload' => $payload, 'idempotency_key' => $key];
    $result = $service->executeInTx($context);
    $replay = $service->executeInTx($context);
    if (empty($replay['replayed']) || $replay['operationId'] !== $result['operationId']) {
        throw new RuntimeException('同幂等键未正确重放');
    }
    $collection = Db::name('cashier_v3_payment_collection')->where('collection_id', $row['collection_id'])->find();
    $fact = Db::name('cashier_v3_payment_fact')->where('fact_id', $factId)->find();
    $allocations = Db::name('cashier_v3_payment_sale_allocation_fact')->where('tenant_id', $tenant)
        ->where('payment_fact_id', $factId)->select()->toArray();
    $current = Db::name('cashier_v3_payment_method_current')->where('tenant_id', $tenant)
        ->where('collection_id', $row['collection_id'])->find();
    $entryAfter = $service->entry(['orderId' => $row['sales_order_id'],
        'collectionId' => $row['collection_id']], $operator, $scope);
    if ((string)$collection['payment_method'] !== 'cash' || (string)$fact['payment_method'] !== 'cash'
        || (string)($current['current_method'] ?? '') !== $target
        || (string)$entryAfter['payments'][0]['paymentMethodCode'] !== $target
        || (int)$collection['amount_cents'] !== (int)$row['amount_cents']
        || (int)$fact['amount_cents'] !== (int)$beforeFact['amount_cents']) {
        throw new RuntimeException('原事实、有效方式或金额不一致');
    }
    foreach ($allocations as $allocation) if ((string)$allocation['payment_method'] !== 'cash') {
        throw new RuntimeException('原销售分摊事实被改写');
    }
    if (count($allocations) !== count($beforeAllocation)) throw new RuntimeException('销售分摊条数被更改');
    $audit = Db::name('cashier_v3_payment_method_correction')->where('operation_id', $result['operationId'])->find();
    if (!$audit || (string)$audit['before_method'] !== 'cash' || (string)$audit['after_method'] !== $target
        || (int)$audit['amount_cents'] !== (int)$row['amount_cents']) {
        throw new RuntimeException('更正审计缺失或金额不一致');
    }
    $report = new StoreUnifiedReportPhaseTwoServices();
    $marketAfter = $report->query('market_performance', [$storeId], $range, []);
    $beforeStore = array_values(array_filter((array)$marketBefore['records'],
        static fn(array $item): bool => (int)($item['store_id'] ?? 0) === $storeId))[0] ?? [];
    $afterStore = array_values(array_filter((array)$marketAfter['records'],
        static fn(array $item): bool => (int)($item['store_id'] ?? 0) === $storeId))[0] ?? [];
    if ($beforeStore === [] || $afterStore === []
        || (int)($beforeStore['payment_cash_cents'] ?? 0)
            - (int)($afterStore['payment_cash_cents'] ?? 0) !== (int)$row['amount_cents']
        || (int)($afterStore['payment_' . $target . '_cents'] ?? 0)
            - (int)($beforeStore['payment_' . $target . '_cents'] ?? 0) !== (int)$row['amount_cents']
        || (int)($beforeStore['total_performance_cents'] ?? -1)
            !== (int)($afterStore['total_performance_cents'] ?? -2)) {
        throw new RuntimeException('市场业绩汇总未按新方式重分类或总额发生变化');
    }
    $newChannel = $report->query('market_detail', [$storeId], $range,
        ['payment_method_code' => $target, 'metric_code' => 'amount']);
    $oldChannel = $report->query('market_detail', [$storeId], $range,
        ['payment_method_code' => 'cash', 'metric_code' => 'amount']);
    $isOriginalOrder = static function (array $item) use ($row): bool {
        return (string)($item['order_id'] ?? '') === (string)$row['sales_order_id']
            && (int)($item['amount_cents'] ?? 0) > 0;
    };
    if (!array_filter((array)$newChannel['records'], $isOriginalOrder)
        || array_filter((array)$oldChannel['records'], $isOriginalOrder)) {
        throw new RuntimeException('统一市场明细下钻没有按有效方式重新归类');
    }
    // 收款明细是报表导出的同源查询；原订单要落入新方式，旧方式不能再命中。
    $unified = new StoreUnifiedReportServices();
    $paymentReport = ['report' => 'sales', 'dataset' => 'payment',
        'start_date' => $businessDate, 'end_date' => $businessDate, 'page_size' => 100];
    $newPayments = $unified->query($storeId, $paymentReport + ['payment_method' => $target]);
    $oldPayments = $unified->query($storeId, $paymentReport + ['payment_method' => 'cash']);
    if (!array_filter((array)$newPayments['records'], $isOriginalOrder)
        || array_filter((array)$oldPayments['records'], $isOriginalOrder)) {
        throw new RuntimeException('统一收款明细未按有效方式筛选');
    }
    $orderQuery = new CashierV3SalesOrderQueryServices();
    $detail = $orderQuery->salesOrderDetail(['orderId' => $row['sales_order_id']], $operator, $scope);
    $effectivePayment = static function (?array $record) use ($row, $target): bool {
        foreach ((array)($record['paymentDetails'] ?? []) as $payment) {
            if ((string)($payment['id'] ?? '') === (string)$row['collection_id']
                && (string)($payment['paymentMethodCode'] ?? '') === $target) return true;
        }
        return false;
    };
    if (!$effectivePayment($detail)) throw new RuntimeException('订单中心详情没有展示有效方式');
    // 全部结果导出复用窄索引装配器，同一笔收款必须与详情一致。
    $orderKey = Db::name('cashier_v3_sales_order')->where('order_id', $row['sales_order_id'])
        ->field('id,settled_at,checkout_request_id,order_id')->find();
    // 窄索引把销售主键编码成偶数，奇数保留给纯服务单据。
    $orderKey['id'] = (int)$orderKey['id'] * 2;
    $exportRows = $orderQuery->hydrateIndexKeys([$orderKey + ['legacy' => false]],
        ['dateFrom' => $businessDate, 'dateTo' => $businessDate, 'status' => 'normal'], $operator, $scope);
    if (count($exportRows) !== 1 || !$effectivePayment($exportRows[0])) {
        throw new RuntimeException('订单中心导出装配未使用有效方式');
    }
    $stale = $context;
    $stale['idempotency_key'] = $key . '-STALE';
    try {
        $service->executeInTx($stale);
        throw new RuntimeException('旧方式冲突未拒绝');
    } catch (CashierV3CommandException $expected) {
        if (strpos($expected->getMessage(), '其他操作') === false) throw $expected;
    }
    $result['allocationCount'] = count($allocations);
    Db::rollback();
} catch (Throwable $error) {
    Db::rollback();
    throw $error;
}
$restored = Db::name('cashier_v3_payment_collection')->where('collection_id', $row['collection_id'])->find();
if ((string)$restored['payment_method'] !== 'cash'
    || Db::name('cashier_v3_payment_method_correction')->where('operation_id', $result['operationId'])->count()
    || Db::name('cashier_v3_payment_method_current')->where('collection_id', $row['collection_id'])->count()) {
    throw new RuntimeException('回滚未恢复原订单或留下审计');
}
echo json_encode(['result' => 'PASS', 'orderId' => $row['sales_order_id'],
    'collectionId' => $row['collection_id'], 'targetMethod' => $target,
    'allocationCount' => $result['allocationCount']], JSON_UNESCAPED_UNICODE) . PHP_EOL;
