<?php

$root = dirname(__DIR__, 3);
$backendRoot = $root . '/后端代码';
require $backendRoot . '/app/services/cashier/v3/CashierV3ResultCode.php';
require $backendRoot . '/app/services/cashier/v3/CashierV3CommandException.php';
require $backendRoot . '/app/services/cashier/v3/CashierV3ResourceScope.php';
require $backendRoot . '/app/services/cashier/v3/CashierV3OperatorScope.php';
require $backendRoot . '/app/services/cashier/v3/CashierV3DataScopeContext.php';
require $backendRoot . '/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\order\CashierV3OrderCenterRecordQueryServices;

$passed = 0;
$failed = 0;

function recordOk(string $label, bool $condition, $detail = null): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[PASS] {$label}\n";
        return;
    }
    $failed++;
    echo "[FAIL] {$label}";
    if ($detail !== null) {
        echo ' :: ' . json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    echo "\n";
}

function recordScope(string $mode, $stores, array $features = ['cashier.v3.order_center']): CashierV3DataScopeContext
{
    return new CashierV3DataScopeContext(
        71,
        701,
        133,
        'tenant:default',
        'organization:8',
        $stores,
        $mode,
        [],
        $mode === CashierV3DataScopeContext::MODE_ALL,
        $mode === CashierV3DataScopeContext::MODE_ALL ? 'test-super' : '',
        'permission-v2',
        $features,
        ['account' => 'order-center-record-test']
    );
}

$operator = new CashierV3OperatorScope(133, 71, 'organization:8', 'tenant:default');
$orderCenterSource = (string)file_get_contents($backendRoot . '/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php');
$salesOrderQuerySource = (string)file_get_contents($backendRoot . '/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php');
$orderProjectionSource = (string)file_get_contents($root . '/前端代码/cashier-v3/src/services/cashierV3OrderProjectionContract.js');
$calls = [];
$reader = function (string $operation, array $context) use (&$calls): array {
    $calls[] = ['operation' => $operation, 'context' => $context];
    if ($operation === 'counts') {
        return ['recharge' => 17, 'refund' => 7, 'debt' => 2, 'service' => 3, 'supplement' => 0, 'gift' => 1, 'card_operation' => 4];
    }
    return [
        'records' => [[
            'id' => 'legacy-card-upgrade:1',
            'operationType' => 'card_upgrade',
            'operationTypeLabel' => '卡升级',
        ]],
        'total' => 1,
    ];
};
$service = new CashierV3OrderCenterRecordQueryServices($reader);
$storesScope = recordScope(CashierV3DataScopeContext::MODE_STORES, [133]);

$page = $service->queryRecords([
    'recordType' => 'card_operation',
    'keyword' => '卡升级',
    'storeIds' => [133, 999],
    'page' => 1,
    'pageSize' => 20,
], $operator, $storesScope);
$queryCall = $calls[0]['context']['criteria'] ?? [];
recordOk('卡操作中文类型转换为严格操作码', ($queryCall['operationType'] ?? '') === 'card_upgrade'
    && ($queryCall['keyword'] ?? 'x') === '', $queryCall);
recordOk('客户端门店只能与服务端门店范围取交集', ($queryCall['allowedStoreIds'] ?? null) === [133], $queryCall);
recordOk('合法订单中心记录返回 offset 分页契约', $page['recordType'] === 'card_operation'
    && $page['total'] === 1
    && $page['page'] === 1
    && $page['pageSize'] === 20);
recordOk('服务权益来源不向页面泄漏内部英文类型码', strpos($orderCenterSource, "'time_card' => '时间卡权益'") !== false
    && strpos($orderCenterSource, "'count_card' => '次数卡权益'") !== false
    && strpos($orderCenterSource, "'卡项权益（' . \$kind") === false);
recordOk('退款记录只读取收银 V3 退款生命周期事实，不兼容旧退货表',
    strpos($orderCenterSource, "CashierV3OrderLifecycleServices::OPERATION_TABLE") !== false
    && strpos($orderCenterSource, "->where('rlo.operation_type', 'refund')") !== false
    && strpos($orderCenterSource, "->where('rlo.status', 'succeeded')") !== false
    && strpos($orderCenterSource, "Db::name('store_order_refund')") === false
    && strpos($orderCenterSource, "'refundStatus' => '已退款作废'") !== false);
recordOk('退款页签使用退款记录名称', strpos($orderCenterSource, "['key' => 'refund', 'label' => '退款记录'") !== false);
recordOk('订单中心投影合同统一升级至退款事实 v3',
    strpos($orderCenterSource, "CONTRACT_VERSION = 'cashier-v3.order-center.v3'") !== false
    && strpos($salesOrderQuerySource, "CONTRACT_VERSION = 'cashier-v3.order-center.v3'") !== false
    && strpos($orderProjectionSource, "ORDER_CONTRACT_VERSION = 'cashier-v3.order-center.v3'") !== false);

$beforeBlocked = count($calls);
$blocked = $service->queryRecords([
    'recordType' => 'card_operation',
    'storeIds' => [999],
], $operator, $storesScope);
recordOk('完全越权门店 fail-closed 且不访问读取器', $blocked['total'] === 0
    && $blocked['dataStatus'] === 'permission_filtered'
    && count($calls) === $beforeBlocked);

$servicePage = $service->queryRecords([
    'recordType' => 'service',
    'topFilters' => [
        ['field' => 'business_date', 'value' => '2026-08-02'],
        ['field' => 'member_name', 'value' => '会员甲'],
        ['field' => 'unexpected_sql', 'value' => 'should-not-pass'],
    ],
    'sorts' => [
        ['field' => 'service_completed_at', 'direction' => 'asc'],
        ['field' => 'unexpected_sql', 'direction' => 'desc'],
    ],
], $operator, $storesScope);
$serviceCall = $calls[count($calls) - 1]['context']['criteria'] ?? [];
recordOk('服务记录进入同一只读查询契约', $servicePage['recordType'] === 'service'
    && $servicePage['total'] === 1
    && count($calls) === $beforeBlocked + 1);
recordOk('服务统一查询只接受白名单筛选与排序字段', ($serviceCall['topFilters'] ?? []) === [
    'business_date' => '2026-08-02', 'member_name' => '会员甲',
] && ($serviceCall['sorts'] ?? []) === [[
    'field' => 'service_completed_at', 'direction' => 'asc',
]], $serviceCall);

$invalid = $service->queryRecords(['recordType' => 'unknown'], $operator, $storesScope);
recordOk('不在订单中心清单中的记录类型被拒绝', $invalid['recordType'] === ''
    && $invalid['total'] === 0
    && count($calls) === $beforeBlocked + 1);

$counts = $service->counts($operator, $storesScope);
recordOk('页签数量使用同一服务端 DataScope', $counts === [
    'recharge' => 17,
    'refund' => 7,
    'debt' => 2,
    'service' => 3,
    'supplement' => 0,
    'gift' => 1,
    'card_operation' => 4,
], $counts);

$partition = $service->augmentInitialPartition([
    'total' => 2472,
    'recordsByType' => ['sales' => []],
    'pagesByType' => ['sales' => ['total' => 2472]],
    'statusOptionsByType' => ['sales' => []],
], $operator, $storesScope);
recordOk('根分区包含权威欠款管理且销售数量并入同一数字映射', count($partition['businessTypes']) === 8
    && $partition['countsByType']['sales'] === 2472
    && $partition['countsByType']['debt'] === 2
    && $partition['countsByType']['service'] === 3
    && $partition['countsByType']['card_operation'] === 4);

$source = file_get_contents($backendRoot . '/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php');
recordOk('初始化统计为服务记录提供空筛选与排序，不能因缺省键让订单中心降级为空页', strpos($source, "'topFilters' => []") !== false
    && strpos($source, "'sorts' => []") !== false);
recordOk('服务记录只读取完成态服务事实，不从销售订单回推', strpos($source, "Db::name('cashier_v3_entitlement_service_fact')") !== false
    && strpos($source, "->where('sf.service_status', 'completed')") !== false
    && strpos($source, "case 'service':") !== false);
recordOk('服务记录使用会员详情同源的可见服务单号并携带会员编号', strpos($source, "'sf.service_record_no'") !== false
    && strpos($source, "'serviceRecordNo' => (string)(\$row['service_record_no'] ?: \$row['service_fact_id'])") !== false
    && strpos($source, "'memberId' => (int)\$row['member_id']") !== false);
recordOk('劳动业绩只汇总有效正向的劳动事实', strpos($source, "->where('performance_type', 'labor_performance_allocated')") !== false
    && strpos($source, "->where('fact_direction', 'forward')") !== false
    && strpos($source, "->where('status', 'effective')") !== false);
recordOk('补交记录同时读取销售与充值 V3 权威补交和旧兼容记录，展示不回写旧表', strpos($source, "readV3SalesDebtRepayments") !== false
    && strpos($source, "cashier_v3_debt_repayment_collection") !== false
    && strpos($source, "readV3RechargeDebtRepayments") !== false
    && strpos($source, "cashier_v3_recharge_debt_repayment") !== false
    && strpos($source, "readLegacySupplements") !== false
    && strpos($source, "不写入旧 store_debt_repay") !== false
    && strpos($source, "不得为订单中心展示而回写旧 store_debt_repay") !== false);
recordOk('欠款管理直接读取欠款主表和 V3 权威映射，不从销售订单拼算余额', strpos($source, 'private function readDebts') !== false
    && strpos($source, "Db::name('store_debt')") !== false
    && strpos($source, "cashier_v3_debt_authority") !== false
    && strpos($source, "cashier_v3_recharge_debt_authority") !== false
    && strpos($source, "bcsub(\$totalDebt, \$repaidDebt, 2)") !== false
    && strpos($source, "case 'debt':") !== false);
recordOk('充值套餐赠送从 V3 事实读取且排除其旧权益投影，避免项目和赠券漏记或重复', strpos($source, "readV3RechargeGifts") !== false
    && strpos($source, "cashier_v3_gift_fact") !== false
    && strpos($source, "cashier_v3_recharge_gift_authority") !== false
    && strpos($source, "not like', '%cashier_v3_recharge_gift%") !== false);
recordOk('充值订单收款方式读取真实组合收款，不误用会员渠道代码', strpos($source, "'r.combination_info'") !== false
    && strpos($source, 'rechargePaymentLabel($row)') !== false
    && strpos($source, 'channel_type describes the member channel') !== false);
recordOk('V3 充值现金业绩只读取有效收款事实并按门店和充值单号净额聚合', strpos($source, 'private function readRechargeEconomics') !== false
    && strpos($source, "Db::name('cashier_v3_payment_fact')") !== false
    && strpos($source, "->where('pf.source_document_type', 'recharge')") !== false
    && strpos($source, "->where('pf.status', 'effective')") !== false
    && strpos($source, "->whereIn('pf.store_id'") !== false
    && strpos($source, "->whereIn('pf.order_no_snapshot'") !== false
    && strpos($source, 'SUM(pf.amount_cents) AS actual_received_cents') !== false
    && strpos($source, "->group('pf.store_id,pf.order_no_snapshot')") !== false);
recordOk('V3 充值事实存在即使冲销后净额为零也为 ready，旧充值仍为 not_ready', strpos($source, 'array_key_exists($economicsKey, $rechargeEconomics)') !== false
    && strpos($source, "'actualReceivedAmount' => \$hasV3PaymentFacts") !== false
    && strpos($source, "'economicsDataStatus' => \$hasV3PaymentFacts ? 'ready' : 'not_ready'") !== false
    && strpos($source, 'must not filter to forward facts only') !== false);
recordOk('充值订单状态和筛选读取成功的追加式退款作废操作，不回写旧充值主表',
    strpos($source, 'rechargeLifecycleOperationQuery') !== false
    && strpos($source, 'readRechargeLifecycleOperations') !== false
    && strpos($source, "->where('rlo.status', 'succeeded')") !== false
    && strpos($source, "\$lifecycleOperation === 'void'") !== false
    && strpos($source, "\$lifecycleOperation === 'refund'") !== false
    && strpos($source, "Db::name('user_recharge')->alias('r')") !== false
    && strpos($source, "Db::name('user_recharge')->where") === false);

$paymentLabel = new ReflectionMethod(CashierV3OrderCenterRecordQueryServices::class, 'rechargePaymentLabel');
recordOk('充值订单组合收款展示真实方式并去重', $paymentLabel->invoke($service, [
    'channel_type' => 'cashier',
    'recharge_type' => 'unionpay',
    'combination_info' => json_encode([
        ['paymentMethod' => 'wechat'],
        ['paymentMethod' => 'alipay'],
        ['paymentMethod' => 'wechat'],
    ], JSON_UNESCAPED_UNICODE),
]) === '微信、支付宝');
recordOk('旧充值订单组合收款缺失时回退充值方式', $paymentLabel->invoke($service, [
    'channel_type' => 'cashier',
    'recharge_type' => 'unionpay',
    'combination_info' => '',
]) === '银联刷卡');

$noneCalls = 0;
$noneService = new CashierV3OrderCenterRecordQueryServices(function () use (&$noneCalls): array {
    $noneCalls++;
    return [];
});
$noneCounts = $noneService->counts($operator, recordScope(CashierV3DataScopeContext::MODE_NONE, []));
recordOk('NONE 权限数量全部为零且不访问读取器', array_sum($noneCounts) === 0 && $noneCalls === 0);

$noFeature = $service->queryRecords(
    ['recordType' => 'recharge'],
    $operator,
    recordScope(CashierV3DataScopeContext::MODE_STORES, [133], [])
);
recordOk('缺少订单中心功能权限时不返回任何记录', $noFeature['total'] === 0
    && $noFeature['dataStatus'] === 'permission_filtered');

echo "ASSERT_PASSED={$passed}\n";
echo "ASSERT_FAILED={$failed}\n";
echo $failed === 0 ? "ORDER_CENTER_RECORDS_READONLY=PASS\n" : "ORDER_CENTER_RECORDS_READONLY=FAIL\n";
exit($failed === 0 ? 0 : 1);
