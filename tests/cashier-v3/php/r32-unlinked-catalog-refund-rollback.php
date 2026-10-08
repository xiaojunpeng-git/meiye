<?php
declare(strict_types=1);

/** Local-only transaction probe: a catalog refund cannot modify a source sale, stock or card rights. */
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\order\CashierV3RefundSalespersonServices;
use app\services\cashier\v3\order\CashierV3OrderCenterRecordQueryServices;
use app\services\cashier\v3\order\CashierV3UnifiedRefundServices;
use app\services\report\StoreUnifiedReportPhaseSixServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->initialize();
if ((string)config('database.connections.mysql.hostname') !== 'mysql'
    || (string)config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('Local ruihao DB only.');
}
$staff = (array)Db::name('system_store_staff')->alias('s')
    ->join('organization_store os', 'os.store_id=s.store_id')
    ->where('s.status', 1)->where('s.is_del', 0)->where('s.level', 0)
    ->field('s.*,os.org_id')->find();
$storeId = (int)$staff['store_id'];
$operator = new CashierV3OperatorScope($storeId, (int)$staff['id'], (string)$staff['org_id'], '0');
$scope = new CashierV3DataScopeContext((int)$staff['id'], (int)$staff['id'], $storeId, '0',
    (string)$staff['org_id'], [$storeId], CashierV3DataScopeContext::MODE_STORES, [], false,
    '', 'r32-catalog-refund', ['cashier.v3.order_center', 'cashier.v3.order.refund', 'cashier.v3.order.staff_adjust'], $staff);
$memberId = 978855;
$service = new CashierV3UnifiedRefundServices();
$items = $service->candidates(['mode' => 'catalog', 'memberId' => $memberId], $operator, $scope)['items'];
$kinds = array_values(array_unique(array_map(static fn(array $row): string => (string)$row['kind'], $items)));
foreach (['项目', '产品', '卡项'] as $kind) {
    if (!in_array($kind, $kinds, true)) throw new RuntimeException('Catalog picker omitted ' . $kind);
}
$item = $items[0] ?? null;
if (!$item || !in_array((string)$item['kind'], ['项目', '产品', '卡项'], true)) throw new RuntimeException('Catalog fixture unavailable.');
$selection = (new CashierV3RefundSalespersonServices())->selection([
    'mode' => 'catalog', 'memberId' => $memberId, 'catalogItemId' => (int)$item['skuId'],
], $operator, $scope);
$person = $selection['salespeople'][0] ?? null;
$replacement = $selection['salespeople'][1] ?? null;
if (!$person || !$replacement || $selection['sourceSalesOrderId'] !== '' || $selection['sourceSalesLineId'] !== '') {
    throw new RuntimeException('Unlinked refund invented a source sale or lacks a salesperson.');
}
$dispatcher = CashierV3Bootstrap::dispatcher();
$hex = bin2hex(random_bytes(16));
$key = 'CMD-' . substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4'
    . substr($hex, 13, 3) . '-8' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
$date = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
Db::startTrans();
try {
    $payload = ['mode' => 'catalog', 'memberId' => $memberId, 'catalogItemId' => (int)$item['skuId'],
        'holderId' => 0, 'componentId' => 0, 'lineId' => '', 'refundAmount' => '33',
        'balanceAmount' => '0', 'balanceKind' => 'bonus', 'voidTarget' => false,
        'validThrough' => 0, 'businessDate' => $date, 'reason' => 'R32本地回滚验证',
        'salespeople' => [['staffId' => (int)$person['staffId'], 'allocationWeight' => 100]]];
    // No source order exists to cap a balance grant or receive a void; reject both at the service boundary.
    foreach ([['balanceAmount' => '1'], ['voidTarget' => true]] as $tamper) {
        try {
            $service->executeInTx(['operator_scope' => $operator, 'data_scope' => $scope,
                'payload' => array_replace($payload, $tamper)]);
            throw new RuntimeException('Forged balance/void request was accepted.');
        } catch (\app\services\cashier\v3\CashierV3CommandException $expected) {
            // Rejection must happen before any business event or fact is written.
        }
    }
    $invoke = static fn() => $dispatcher->gateway()->execute('refund-card-or-line',
        ['idempotencyKey' => $key, 'contexts' => []], $payload, $operator,
        ['client_session_id' => 'SESSION-' . substr($key, 4), 'operator_profile' => $staff],
        $dispatcher->handlers()->requireCommand('refund-card-or-line'), $scope,
        CashierV3ActionManifest::requireAction('refund-card-or-line'));
    $result = $invoke();
    if (($result['status'] ?? '') !== 'success') throw new RuntimeException('Refund failed: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
    $operationId = (string)$result['data']['unifiedRefund']['operationId'];
    $row = (array)Db::name('cashier_v3_refund_adjustment')->where('operation_id', $operationId)->find();
    if ((string)$row['target_type'] !== 'catalog' || (int)$row['catalog_item_id'] !== (int)$item['skuId']
        || (string)$row['catalog_kind'] !== (string)$item['kind'] || (string)$row['source_order_id'] !== ''
        || (string)$row['source_line_id'] !== '' || (int)$row['balance_credit_cents'] !== 0) {
        throw new RuntimeException('Unlinked refund persisted a false source or wrong catalog snapshot.');
    }
    $facts = Db::name('cashier_v3_performance_fact')->where('order_id', $operationId)
        ->where('performance_type', 'refund_sales_deduction')->column('amount_cents');
    if (array_map('intval', $facts) !== [-3300]) throw new RuntimeException('Negative salary fact mismatch.');
    if ((int)Db::name('cashier_v3_payment_fact')->where('order_id', $operationId)
        ->where('fact_type', 'manual_refund_adjustment')->sum('amount_cents') !== -3300) {
        throw new RuntimeException('Cash refund fact mismatch.');
    }
    if ((int)Db::name('cashier_v3_balance_fact')->where('order_id', $operationId)->count() !== 0) {
        throw new RuntimeException('Unlinked refund credited member balance.');
    }
    $salary = (new StoreUnifiedReportPhaseSixServices())->query('phase_six_salary_detail', [$storeId],
        ['start' => $date, 'end' => $date], ['document_no' => (string)$result['data']['unifiedRefund']['operationNo'], '_internal_all' => true]);
    $salaryCents = array_sum(array_map(static fn(array $record): int => (int)($record['cash_amount_cents'] ?? 0), $salary['records']));
    if ($salaryCents !== -3300) throw new RuntimeException('Salary detail did not deduct the refund.');
    $list = (new CashierV3OrderCenterRecordQueryServices())->queryRecords([
        'recordType' => 'refund', 'keyword' => (string)$result['data']['unifiedRefund']['operationNo'],
        'dateFrom' => $date, 'dateTo' => $date,
    ], $operator, $scope);
    $record = $list['records'][0] ?? null;
    if (!$record || (string)$record['sourceOrderNo'] !== ''
        || !str_contains((string)$record['refundSummary'], '无原单退款')) {
        throw new RuntimeException('Refund list fabricated a source order or lost catalog context.');
    }
    $replay = $invoke();
    if (($replay['data']['unifiedRefund']['operationId'] ?? '') !== $operationId
        || (int)Db::name('cashier_v3_refund_adjustment')->where('operation_id', $operationId)->count() !== 1) {
        throw new RuntimeException('Idempotent replay duplicated refund: ' . json_encode($replay, JSON_UNESCAPED_UNICODE));
    }
    // 无原单退款的改派和改日期只能移动本笔负向事实；不得补造原销售或第二笔资金退款。
    $personnel = new CashierV3RefundSalespersonServices();
    $entry = $personnel->entry(['recordId' => $operationId], $operator, $scope);
    if ((int)$entry['recordVersion'] !== 1) throw new RuntimeException('Catalog refund personnel cannot be opened.');
    $adjustHex = bin2hex(random_bytes(16));
    $adjustKey = 'CMD-' . substr($adjustHex, 0, 8) . '-' . substr($adjustHex, 8, 4) . '-4'
        . substr($adjustHex, 13, 3) . '-8' . substr($adjustHex, 17, 3) . '-' . substr($adjustHex, 20, 12);
    $changed = $dispatcher->gateway()->execute('adjust-refund-personnel',
        ['idempotencyKey' => $adjustKey, 'contexts' => []], [
            'recordId' => $operationId, 'recordVersion' => 1, 'reason' => 'R32无原单改派回滚验证',
            'personnel' => [['staffId' => (int)$replacement['staffId'], 'allocationWeight' => 100]],
        ], $operator, ['client_session_id' => 'SESSION-' . substr($adjustKey, 4), 'operator_profile' => $staff],
        $dispatcher->handlers()->requireCommand('adjust-refund-personnel'), $scope,
        CashierV3ActionManifest::requireAction('adjust-refund-personnel'));
    if (($changed['status'] ?? '') !== 'success') throw new RuntimeException('Catalog refund salesperson adjustment failed.');
    $entry = $personnel->entry(['recordId' => $operationId], $operator, $scope);
    if ((int)$entry['recordVersion'] !== 2
        || (int)($entry['currentSalespeople'][0]['staffId'] ?? 0) !== (int)$replacement['staffId']) {
        throw new RuntimeException('Catalog refund salesperson adjustment was not persisted.');
    }
    $previousDate = (new DateTimeImmutable($date, new DateTimeZone('Asia/Shanghai')))
        ->modify('-1 day')->format('Y-m-d');
    $dateHex = bin2hex(random_bytes(16));
    $dateKey = 'CMD-' . substr($dateHex, 0, 8) . '-' . substr($dateHex, 8, 4) . '-4'
        . substr($dateHex, 13, 3) . '-8' . substr($dateHex, 17, 3) . '-' . substr($dateHex, 20, 12);
    $dateChange = $dispatcher->gateway()->execute('correct-refund-date',
        ['idempotencyKey' => $dateKey, 'contexts' => []], [
            'recordType' => 'refund', 'recordId' => $operationId, 'field' => 'business_date',
            'expectedValue' => $date, 'newValue' => $previousDate,
        ], $operator, ['client_session_id' => 'SESSION-' . substr($dateKey, 4), 'operator_profile' => $staff],
        $dispatcher->handlers()->requireCommand('correct-refund-date'), $scope,
        CashierV3ActionManifest::requireAction('correct-refund-date'));
    if (($dateChange['status'] ?? '') !== 'success') throw new RuntimeException('Catalog refund date correction failed.');
    $factDateCount = (int)Db::name('cashier_v3_performance_fact')->where('order_id', $operationId)
        ->where('performance_type', 'refund_sales_deduction')->where('business_date', $previousDate)->count();
    $paymentDate = (string)Db::name('cashier_v3_payment_fact')->where('order_id', $operationId)
        ->where('fact_type', 'manual_refund_adjustment')->value('business_date');
    if ($factDateCount !== 3 || $paymentDate !== $previousDate
        || (int)Db::name('cashier_v3_payment_fact')->where('order_id', $operationId)->count() !== 1
        || (string)Db::name('cashier_v3_refund_adjustment')->where('operation_id', $operationId)
            ->value('source_sales_order_id') !== '') {
        throw new RuntimeException('Catalog refund correction changed cash/source identity or left stale fact dates.');
    }
    echo "R32 catalog local rollback probe passed: snapshot, replay, salesperson reassignment, date correction.\n";
} finally {
    Db::rollback();
}
