<?php

declare(strict_types=1);

// 只在本地副本执行；用真实回执验证导购事实，然后无条件回滚所有写入。
if (getenv('ENTITLEMENT_GUIDE_LOCAL_ROLLBACK_TEST') !== '1') {
    throw new RuntimeException('Explicit local rollback test flag required');
}

$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';
(new \think\App(rtrim($backend, '/\\') . '/'))->initialize();

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\report\CashierV3GuideRoundFactServices;
use think\facade\Db;

$receipts = Db::name('cashier_v3_entitlement_completion_receipt')
    ->where('status', 'completed')->order('id', 'desc')->limit(100)->select()->toArray();
$sample = null;
foreach ($receipts as $receipt) {
    $service = Db::name('cashier_v3_entitlement_service_fact')
        ->where('tenant_id', (string)$receipt['tenant_id'])
        ->where('checkout_request_id', (string)$receipt['checkout_request_id'])
        ->where('service_status', 'completed')->find();
    if (!$service || (int)$receipt['member_id'] <= 0 || (int)$receipt['operator_id'] <= 0) continue;
    $existing = Db::name(CashierV3GuideRoundFactServices::TABLE)
        ->where('tenant_id', (string)$receipt['tenant_id'])
        ->where('order_id', (string)$receipt['receipt_id'])
        ->where('source_line_id', (string)$service['source_line_id'])->find();
    if ($existing) continue;
    $sample = [$receipt, $service];
    break;
}
if ($sample === null) throw new RuntimeException('No eligible local entitlement receipt fixture');
[$receipt, $service] = $sample;
$employee = Db::name('employee')->where('status', 1)->where('is_del', 0)
    ->where('employment_type_version', '>', 0)->where('name', '<>', '')->find();
if (!$employee) throw new RuntimeException('No eligible local guide fixture');

$tenant = (string)$receipt['tenant_id'];
$storeId = (int)$receipt['store_id'];
$operatorId = (int)$receipt['operator_id'];
$organization = (string)$receipt['organization_id'];
$operator = new CashierV3OperatorScope($storeId, $operatorId, $organization, $tenant);
$scope = new CashierV3DataScopeContext(
    $operatorId, 0, $storeId, $tenant, $organization, [$storeId],
    CashierV3DataScopeContext::MODE_STORES, [], false, '', 'local-test', [], []
);
$authority = [
    'tenant_id' => $tenant,
    'organization_id' => $organization,
    'store_id' => $storeId,
    'member_id' => (int)$receipt['member_id'],
    'order_id' => (string)$receipt['receipt_id'],
    'order_no_snapshot' => (string)$receipt['receipt_id'],
    'checkout_request_id' => (string)$receipt['checkout_request_id'],
    'business_date' => (string)$receipt['business_date'],
    'operator_id' => $operatorId,
    'command_idempotency_key' => 'local-rollback-' . (string)$receipt['receipt_id'],
    'occurred_at' => (int)$receipt['occurred_at'],
    'recorded_at' => (int)$receipt['recorded_at'],
];

Db::startTrans();
try {
    $writer = new CashierV3GuideRoundFactServices();
    $result = $writer->persistInTx($authority, [
        (string)$service['source_line_id'] => [[
            'employeeId' => (int)$employee['id'], 'guideRoundNo' => 0,
        ]],
    ], $operator, $scope);
    if (($result['inserted'] ?? 0) !== 1 || ($result['guide_count'] ?? 0) !== 1) {
        throw new RuntimeException('Entitlement guide fact was not inserted once');
    }
    $stored = Db::name(CashierV3GuideRoundFactServices::TABLE)
        ->where('tenant_id', $tenant)->where('order_id', (string)$receipt['receipt_id'])
        ->where('source_line_id', (string)$service['source_line_id'])->find();
    if (!$stored) throw new RuntimeException('Entitlement guide fact is absent inside transaction');
    // 预检复用销售单与权益回执的统一历史查询，确保回执子查询可执行。
    $available = $writer->assertAvailableInTx($authority, [
        (string)$service['source_line_id'] => [[
            'employeeId' => (int)$employee['id'], 'guideRoundNo' => 0,
        ]],
    ], $operator, $scope);
    if (($available['guide_count'] ?? 0) !== 1) throw new RuntimeException('Entitlement guide history preflight failed');
    echo "PASS entitlement guide fact persists and joins completed receipt inside transaction\n";
} finally {
    Db::rollback();
}

$remaining = Db::name(CashierV3GuideRoundFactServices::TABLE)
    ->where('tenant_id', $tenant)->where('order_id', (string)$receipt['receipt_id'])
    ->where('source_line_id', (string)$service['source_line_id'])->count();
if ((int)$remaining !== 0) throw new RuntimeException('Local test rollback left guide facts behind');
echo "PASS rollback left no guide facts\n";
