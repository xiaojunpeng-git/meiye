<?php

declare(strict_types=1);

// Run in the local cashier PHP container via: docker exec -i mohe-cashier-app php /dev/stdin < this-file.
// Only a temporary attribution revision is inserted; it is rolled back even on failure.
require '/var/www/html/vendor/autoload.php';

use app\services\report\StoreUnifiedReportServices;
use think\facade\Db;

(new think\App('/var/www/html/'))->initialize();
$operation = (array)Db::name('cashier_v3_order_lifecycle_operation')
    ->where('operation_no', 'TK2610140002')->where('operation_type', 'refund')
    ->where('status', 'succeeded')->find();
if ($operation === []) {
    fwrite(STDERR, "BLOCKED local refund fixture TK2610140002 is absent\n");
    exit(2);
}
$adjustment = (array)Db::name('cashier_v3_refund_adjustment')
    ->where('operation_id', $operation['operation_id'])->where('status', 'effective')->find();
$sale = (array)Db::name('cashier_v3_sale_fact')
    ->where('order_id', (string)$adjustment['source_sales_order_id'])
    ->where('source_line_id', (string)$adjustment['source_sales_line_id'])
    ->where('fact_direction', 'forward')->where('status', 'effective')->find();
$component = (array)Db::name('store_order_cart_info')
    ->where('id', (int)$adjustment['card_component_id'])->find();
if ($sale === [] || $component === []) throw new RuntimeException('refund source card component is not verifiable');
$holder = (array)Db::name('user_card_holder')->where('id', (int)$adjustment['card_holder_id'])
    ->where('uid', (int)$adjustment['member_id'])->field('oid')->find();
$receipt = (array)Db::name('cashier_v3_card_purchase_receipt')
    ->where('card_holder_id', (int)$adjustment['card_holder_id'])
    ->where('legacy_order_id', (int)($holder['oid'] ?? 0))
    ->where('sales_order_id', (string)$adjustment['source_sales_order_id'])
    ->where('sales_order_line_id', (string)$adjustment['source_sales_line_id'])
    ->where('status', 'completed')->find();
if ($receipt === [] || (int)$component['oid'] !== (int)($holder['oid'] ?? 0)) {
    throw new RuntimeException('refund component has no exact completed purchase receipt');
}
$allocations = (int)Db::name('cashier_v3_card_sale_category_allocation_fact')
    ->where('sale_fact_id', (string)$sale['fact_id'])
    ->where('component_product_id', (int)$component['product_id'])
    ->where('status', 'effective')->count();
if ($allocations !== 0) throw new RuntimeException('fixture no longer covers missing allocation fallback');

$version = (int)Db::name('cashier_v3_refund_attribution_revision')
    ->where('operation_id', $operation['operation_id'])->max('revision_no');
$managerTable = $version > 0 ? 'cashier_v3_refund_attribution_revision_person' : 'cashier_v3_refund_attribution';
$managerQuery = Db::name($managerTable)->where('operation_id', $operation['operation_id'])
    ->where('role', 'sales_manager');
if ($version > 0) $managerQuery->where('revision_no', $version);
$manager = (array)$managerQuery->find();
if ($manager === []) throw new RuntimeException('refund has no current manager fixture');

$paymentCents = (int)Db::name('cashier_v3_payment_fact')
    ->where('order_id', $operation['operation_id'])->where('status', 'effective')
    ->where('fact_direction', 'adjustment')->where('amount_cents', '<', 0)
    ->whereIn('fact_type', ['manual_refund_adjustment', 'payment_refunded'])
    ->sum('amount_cents');
if ($paymentCents >= 0) throw new RuntimeException('refund cash payment fact is absent');

Db::startTrans();
$failure = null;
try {
    // The real refund has no allocation fact; the completed receipt is its
    // independent proof of the original paid card component.
    $date = (string)$operation['business_date'];
    $result = (new StoreUnifiedReportServices())->query([(int)$operation['store_id']], [
        'report' => 'salesperson_large_order_statistics',
        'start_date' => $date, 'end_date' => $date, 'page' => 1, 'limit' => 100,
    ]);
    if ((string)($result['metric_version'] ?? '') !== 'beauty-large-order-refund-net-v4') {
        throw new RuntimeException('receipt fallback must expose its own report metric version');
    }
    $refundRows = array_values(array_filter((array)$result['records'], static function (array $row) use ($operation): bool {
        return (string)($row['order_id'] ?? '') === (string)$operation['operation_id'];
    }));
    $managerRows = array_values(array_filter($refundRows, static function (array $row) use ($manager): bool {
        return (string)($row['role_snapshot'] ?? '') === 'sales_manager'
            && (int)($row['employee_id'] ?? 0) === (int)$manager['employee_id'];
    }));
    if (count($managerRows) !== 1 || (int)round((float)$managerRows[0]['daily_cash'] * 100) !== $paymentCents) {
        throw new RuntimeException('current refund manager must receive the cash-refund amount once');
    }
    $personRows = array_values(array_filter($refundRows, static function (array $row): bool {
        return (string)($row['role_snapshot'] ?? '') === 'salesperson';
    }));
    if (count($personRows) !== 3) throw new RuntimeException('refund salesperson deductions are incomplete');
    $hasEarlierMonthCash = false;
    foreach ($personRows as $row) {
        if ((int)round((float)$row['daily_cash'] * 100) >= 0
            || (string)($row['refund_no'] ?? '') !== (string)$operation['operation_no']
            || (string)($row['business_date'] ?? '') !== $date) {
            throw new RuntimeException('refund row has the wrong sign, day, or document number');
        }
        if ((int)round((float)$row['cumulative_cash'] * 100)
            > (int)round((float)$row['daily_cash'] * 100)) $hasEarlierMonthCash = true;
    }
    if (!$hasEarlierMonthCash) throw new RuntimeException('selected refund day lost prior month sales');
    $displayedCents = 0;
    foreach ((array)$result['records'] as $row) $displayedCents += (int)round((float)$row['daily_cash'] * 100);
    if ((int)round((float)$result['summary_row']['daily_cash'] * 100) !== $displayedCents) {
        throw new RuntimeException('the total row differs from the selected-date records');
    }
    foreach ((array)$result['columns'] as $column) {
        $explanation = trim((string)($column['source_explanation'] ?? ''));
        if (mb_strlen($explanation) < 10 || strpos($explanation, '按本报表已确认的统一事实口径') !== false) {
            throw new RuntimeException('column explanation is not useful: ' . (string)$column['key']);
        }
    }
    if ($version > 0) {
        // A complete newer revision may intentionally contain no manager.
        // It must not reveal the original or the previous revision's manager.
        $revision = (array)Db::name('cashier_v3_refund_attribution_revision')
            ->where('operation_id', $operation['operation_id'])
            ->where('revision_no', $version)->find();
        unset($revision['id']);
        $revision['revision_no'] = $version + 1;
        $revision['revision_id'] = 'R17-LOCAL-CLEAR-' . substr(hash('sha256', (string)$operation['operation_id']), 0, 24);
        $revision['command_idempotency_key'] = $revision['revision_id'];
        $revision['immutable_fingerprint'] = hash('sha256', $revision['revision_id']);
        $revision['new_snapshot_json'] = '[]';
        $revision['created_at'] = time();
        Db::name('cashier_v3_refund_attribution_revision')->insert($revision);
        $cleared = (new StoreUnifiedReportServices())->query([(int)$operation['store_id']], [
            'report' => 'salesperson_large_order_statistics',
            'start_date' => $date, 'end_date' => $date, 'page' => 1, 'limit' => 100,
        ]);
        $remainingPeople = 0;
        foreach ((array)$cleared['records'] as $row) {
            if ((string)($row['order_id'] ?? '') !== (string)$operation['operation_id']) continue;
            if ((string)($row['role_snapshot'] ?? '') === 'sales_manager') {
                throw new RuntimeException('cleared manager must not fall back to an old attribution');
            }
            if ((string)($row['role_snapshot'] ?? '') === 'salesperson') $remainingPeople++;
        }
        if ($remainingPeople !== count($personRows)) {
            throw new RuntimeException('manager clear changed salesperson refund deductions');
        }
    }
    echo 'PASS refund salespeople=' . count($personRows) . ' manager=1 cash_cents=' . $paymentCents . "\n";
    echo "PASS refund date, receipt fallback, manager clear, explanations and rollback\n";
} catch (Throwable $error) {
    $failure = $error;
} finally {
    Db::rollback();
}
if ($failure !== null) {
    fwrite(STDERR, 'FAIL ' . $failure->getMessage() . "\n");
    exit(1);
}
