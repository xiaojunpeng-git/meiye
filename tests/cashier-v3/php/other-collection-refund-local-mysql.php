<?php
declare(strict_types=1);

/** Local-only rollback probe: an existing other-collection sale may not create a cash refund. */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\order\CashierV3UnifiedRefundServices;
use think\facade\Db;

(new think\App($backend . '/'))->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('This refund probe runs only against local Docker ruihao.');
}

// Select an existing settled line; the test does not insert or change any business record.
$samples = Db::name('cashier_v3_sales_order')->alias('so')
    ->join('cashier_v3_checkout_payment_draft d', 'd.request_id=so.checkout_request_id AND d.tenant_id=so.tenant_id')
    ->join('cashier_v3_sales_order_line l', 'l.order_id=so.order_id AND l.tenant_id=so.tenant_id')
    ->where('d.payment_method', 'other_collection')->where('d.draft_status', 'committed')
    ->where('so.order_status', 'settled')->where('so.order_direction', 'forward')
    ->where('l.line_status', 'settled')->where('l.line_direction', 'forward')
    ->whereIn('l.item_type', ['project', 'product'])->where('l.member_id', '>', 0)
    ->where('l.sale_amount_cents', '>', 0)
    ->field('so.order_id,so.tenant_id,so.store_id,so.operator_id,so.organization_id,l.order_line_id,l.member_id')
    ->limit(100)->select()->toArray();
if ($samples === []) throw new RuntimeException('No local other-collection line fixture is available.');

$service = new CashierV3UnifiedRefundServices();
$snapshotMethod = (new ReflectionClass($service))->getMethod('lineSnapshot');
$snapshotMethod->setAccessible(true);
$sample = null;
foreach ($samples as $row) {
    if ((int)$row['operator_id'] <= 0) continue;
    $state = $snapshotMethod->invoke($service, (string)$row['order_line_id'],
        (int)$row['member_id'], (string)$row['tenant_id'], false);
    if (!$state['voided'] && $state['remainingAmountCents'] > 0) {
        $sample = $row;
        if ($state['canRefund'] || $state['cashRefundRemainingCents'] !== 0
            || !str_contains($state['unavailableReason'], '只能整单作废')) {
            throw new RuntimeException('Other-collection line was exposed as cash-refundable.');
        }
        break;
    }
}
if (!$sample) throw new RuntimeException('No eligible local other-collection line fixture is available.');
echo "PASS historical other-collection line is visible but refund-disabled\n";

// Card purchases are linked through the issuance receipt rather than the
// legacy holder order number; cover that second refund entry independently.
$cardRows = Db::name('cashier_v3_card_purchase_receipt')->alias('cpr')
    ->join('cashier_v3_sales_order so', 'so.order_id=cpr.sales_order_id AND so.tenant_id=cpr.tenant_id')
    ->join('cashier_v3_checkout_payment_draft d', 'd.request_id=so.checkout_request_id AND d.tenant_id=so.tenant_id')
    ->join('user_card_holder h', 'h.id=cpr.card_holder_id')
    ->where('d.payment_method', 'other_collection')->where('d.draft_status', 'committed')
    ->where('cpr.status', 'completed')->where('h.is_del', 0)
    ->field('cpr.card_holder_id,cpr.tenant_id,h.uid')->limit(100)->select()->toArray();
$cardMethod = (new ReflectionClass($service))->getMethod('cardSnapshot');
$cardMethod->setAccessible(true);
$cardChecked = false;
foreach ($cardRows as $cardRow) {
    try {
        $card = $cardMethod->invoke($service, (int)$cardRow['card_holder_id'],
            (int)$cardRow['uid'], (string)$cardRow['tenant_id'], false);
    } catch (CashierV3CommandException $error) {
        continue;
    }
    if ($card['voided'] || $card['remainingAmountCents'] <= 0) continue;
    if ($card['canRefund'] || $card['cashRefundRemainingCents'] !== 0
        || !str_contains($card['unavailableReason'], '只能整单作废')) {
        throw new RuntimeException('Other-collection card was exposed as cash-refundable.');
    }
    $cardChecked = true;
    break;
}
if (!$cardChecked) throw new RuntimeException('No eligible local other-collection card fixture is available.');
echo "PASS historical other-collection card is visible but refund-disabled\n";

$storeId = (int)$sample['store_id'];
$operatorId = (int)$sample['operator_id'];
$tenantId = (string)$sample['tenant_id'];
$organizationId = (string)$sample['organization_id'];
$operator = new CashierV3OperatorScope($storeId, $operatorId, $organizationId, $tenantId);
$scope = new CashierV3DataScopeContext($operatorId, $operatorId, $storeId, $tenantId,
    $organizationId, [$storeId], CashierV3DataScopeContext::MODE_STORES, [], false,
    '', 'local-refund-guard', ['cashier.v3.order.refund'], []);

Db::startTrans();
try {
    $service->executeInTx([
        'operator_scope' => $operator,
        'data_scope' => $scope,
        'idempotency_key' => 'local-other-collection-refund-guard-' . bin2hex(random_bytes(8)),
        'payload' => ['mode' => 'document', 'memberId' => (int)$sample['member_id'],
            'lineId' => (string)$sample['order_line_id'], 'refundAmount' => '1',
            'balanceAmount' => '0', 'balanceKind' => 'bonus', 'voidTarget' => false,
            'reason' => '本地退款门禁回归'],
    ]);
    throw new RuntimeException('A direct refund command bypassed the other-collection guard.');
} catch (CashierV3CommandException $error) {
    if (!str_contains($error->getMessage(), '只能整单作废')) throw $error;
    echo "PASS transaction-time refund command rejects the original other-collection order\n";
} finally {
    Db::rollback();
}
