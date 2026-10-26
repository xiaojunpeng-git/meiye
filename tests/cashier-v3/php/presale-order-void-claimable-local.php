<?php
declare(strict_types=1);

/** Local regression: a voided pure-presale sale must enter the claimable closure, without touching stock. */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\order\CashierV3OrderLifecycleServices;
use app\services\cashier\v3\presale\CashierV3PresaleClaimServices;
use think\facade\Db;

$app = new \think\App($backend . '/');
$app->initialize();
$database = config('database.connections.mysql');
if (($database['hostname'] ?? '') !== 'mysql' || ($database['database'] ?? '') !== 'ruihao') {
    throw new RuntimeException('local_ruihao_database_required');
}

$orderNo = 'XS26101100010';
$order = (array)Db::name('cashier_v3_sales_order')->where('order_no', $orderNo)->find();
if (!$order) throw new RuntimeException('presale_order_fixture_missing');
$tenantId = (string)$order['tenant_id'];
$orderId = (string)$order['order_id'];
$repaired = (array)Db::name(CashierV3PresaleClaimServices::CLAIMABLE_TABLE)
    ->where('tenant_id', $tenantId)->where('source_order_id', $orderId)->where('source_kind', 'PRESALE')->find();
if (!$repaired || (string)$repaired['claim_status'] !== 'CLOSED_AFTER_SALE_REVERSAL'
    || (string)$repaired['close_operation_id'] !== 'OLO-4BC1CBAA36E96C419C97E78647A912433E5D7368') {
    throw new RuntimeException('repaired_presale_claimable_changed');
}

$scope = new CashierV3DataScopeContext(1, 1, (int)$order['store_id'], $tenantId,
    (string)$order['organization_id'], [(int)$order['store_id']], CashierV3DataScopeContext::MODE_STORES,
    [], false, '', 'local-test', [], []);
$method = new ReflectionMethod(CashierV3OrderLifecycleServices::class, 'occurredCheckoutEvents');
$method->setAccessible(true);

$events = $method->invoke(new CashierV3OrderLifecycleServices(), [
    'checkoutRequestId' => (string)$order['checkout_request_id'], 'sourceId' => $orderId,
], $scope);
if (($events['presale'] ?? false) !== true) throw new RuntimeException('pure_presale_event_plan_missing');

// Use any still-open, unclaimed presale line for the rollback-only closure test;
// the repaired historical row must remain closed and is never reopened by a test.
$claimable = [];
$candidates = Db::name(CashierV3PresaleClaimServices::CLAIMABLE_TABLE)
    ->where('tenant_id', $tenantId)->where('source_kind', 'PRESALE')
    ->where('claim_status', 'AVAILABLE')->where('claimed_quantity', 0)
    ->order('id', 'desc')->limit(100)->select()->toArray();
foreach ($candidates as $candidate) {
    if ((int)Db::name(CashierV3PresaleClaimServices::CLAIM_TABLE)
        ->where('tenant_id', $tenantId)->where('claimable_line_id', (string)$candidate['claimable_line_id'])
        ->count() === 0) {
        $claimable = (array)$candidate;
        break;
    }
}
if (!$claimable) throw new RuntimeException('open_presale_claimable_fixture_missing');
Db::startTrans();
try {
    $closed = (new CashierV3PresaleClaimServices())->closeForSalesOrderReversalInTx(
        $tenantId, (string)$claimable['source_order_id'], 'OLO-R11-ROLLBACK-TEST', time()
    );
    if ($closed < 1 || (string)Db::name(CashierV3PresaleClaimServices::CLAIMABLE_TABLE)
        ->where('id', (int)$claimable['id'])->value('claim_status') !== 'CLOSED_AFTER_SALE_REVERSAL') {
        throw new RuntimeException('pure_presale_claimable_not_closed');
    }
} finally {
    Db::rollback();
}
if ((string)Db::name(CashierV3PresaleClaimServices::CLAIMABLE_TABLE)
    ->where('id', (int)$claimable['id'])->value('claim_status') !== 'AVAILABLE') {
    throw new RuntimeException('rollback_did_not_restore_fixture');
}

// Closing a delivered order's remaining eligibility must not void its settled
// outbound document or create stock. The actual return remains a separate act.
$delivered = Db::name(CashierV3PresaleClaimServices::CLAIMABLE_TABLE)
    ->where('tenant_id', $tenantId)->where('source_kind', 'PRESALE')
    ->where('claimed_quantity', '>', 0)->whereIn('claim_status', ['AVAILABLE', 'FULLY_CLAIMED'])
    ->order('id', 'asc')->limit(20)->select()->toArray();
$deliveredLine = [];
$deliveredClaim = [];
foreach ($delivered as $candidate) {
    $settled = (array)Db::name(CashierV3PresaleClaimServices::CLAIM_TABLE)
        ->where('tenant_id', $tenantId)->where('claimable_line_id', (string)$candidate['claimable_line_id'])
        ->where('claim_status', 'SETTLED')->find();
    if ($settled) {
        $deliveredLine = (array)$candidate;
        $deliveredClaim = $settled;
        break;
    }
}
if (!$deliveredClaim) throw new RuntimeException('delivered_presale_fixture_missing');
$allocation = (array)Db::name(CashierV3PresaleClaimServices::CLAIM_BATCH_TABLE)
    ->where('tenant_id', $tenantId)->where('claim_id', (string)$deliveredClaim['claim_id'])->find();
if (!$allocation) throw new RuntimeException('delivered_presale_batch_missing');
$stockBefore = (int)Db::name('inventory_stock')->where('id', (int)$allocation['stock_id'])->value('available_quantity_units');
Db::startTrans();
try {
    (new CashierV3PresaleClaimServices())->closeForSalesOrderReversalInTx(
        $tenantId, (string)$deliveredLine['source_order_id'], 'OLO-R11-DELIVERED-ROLLBACK', time()
    );
    $claimStatus = (string)Db::name(CashierV3PresaleClaimServices::CLAIM_TABLE)
        ->where('id', (int)$deliveredClaim['id'])->value('claim_status');
    $stockAfter = (int)Db::name('inventory_stock')->where('id', (int)$allocation['stock_id'])->value('available_quantity_units');
    if ($claimStatus !== 'SETTLED' || $stockAfter !== $stockBefore) {
        throw new RuntimeException('delivered_presale_was_restocked');
    }
} finally {
    Db::rollback();
}
echo "PRESALE_ORDER_VOID_CLAIMABLE_LOCAL_OK\n";
