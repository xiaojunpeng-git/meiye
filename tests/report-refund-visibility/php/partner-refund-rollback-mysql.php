<?php

declare(strict_types=1);

// Local-only integration fixture: cloned facts exist inside one transaction
// and are rolled back even when an assertion fails.
ob_start();
require __DIR__ . '/tk-member-salesperson-local-mysql.php';
ob_end_clean();

use app\services\report\StoreUnifiedReportServices;
use think\facade\Db;

$sale = Db::name('cashier_v3_sale_fact')->alias('s')
    ->join('cashier_v3_report_sale_dimension_fact d',
        'd.tenant_id=s.tenant_id AND d.store_id=s.store_id AND d.sale_fact_id=s.fact_id')
    ->where('s.store_id', 71)->where('s.status', 'effective')
    ->where('s.source_type', 'product')->where('d.partner_category_id_snapshot', '>', 0)
    ->where('d.partner_default_ratio_snapshot', 30)
    ->fieldRaw('s.*,d.partner_name_snapshot,d.partner_default_ratio_snapshot,0 card_holder_id,0 card_component_id')->find();
if (!$sale) throw new RuntimeException('Local partner-sale fixture is unavailable.');
$cardSale = Db::name('cashier_v3_card_sale_category_allocation_fact')->alias('cc')
    ->join('cashier_v3_sale_fact s', 's.fact_id=cc.sale_fact_id AND s.tenant_id=cc.tenant_id')
    ->join('cashier_v3_card_purchase_receipt receipt',
        "receipt.receipt_id=cc.card_receipt_id AND receipt.tenant_id=cc.tenant_id AND receipt.status='completed'")
    ->join('store_order_cart_info cart',
        'cart.oid=receipt.legacy_order_id AND cart.product_id=cc.component_product_id')
    ->where('s.store_id', 16)->where('s.status', 'effective')->where('cc.status', 'effective')
    ->where('cc.partner_category_id_snapshot', '>', 0)
    ->fieldRaw('s.*,cc.partner_name_snapshot,cc.partner_default_ratio_snapshot,'
        . 'receipt.card_holder_id,cart.id card_component_id')->find();
if (!$cardSale) throw new RuntimeException('Local partner-card fixture is unavailable.');
$original = Db::name('cashier_v3_order_lifecycle_operation')
    ->where('operation_no', 'TK2610130001')->find();
$adjustment = Db::name('cashier_v3_refund_adjustment')
    ->where('operation_id', (string)$original['operation_id'])->find();
$payment = Db::name('cashier_v3_payment_fact')
    ->where('order_id', (string)$original['operation_id'])
    ->where('fact_type', 'manual_refund_adjustment')->find();
if (!$original || !$adjustment || !$payment) throw new RuntimeException('Local refund template is unavailable.');
$operationTemplate = $original;
$adjustmentTemplate = $adjustment;
$paymentTemplate = $payment;

$service = new StoreUnifiedReportServices();
$range = ['start_date' => '2026-10-13', 'end_date' => '2026-10-13'];
foreach (['direct' => $sale, 'card' => $cardSale] as $kind => $fixture) {
$sale = $fixture;
$storeId = (int)$sale['store_id'];
$baselineMember = $service->query($storeId, $range + ['report' => 'member_consumption_detail']);
$baselinePartner = $service->query($storeId, $range + ['report' => 'partner_item_detail']);
$baselineSummary = $service->query($storeId, $range + ['report' => 'partner_item_summary']);
$baselineAnalysis = $service->query($storeId, $range + ['report' => 'store_item_analysis']);
$token = strtoupper(bin2hex(random_bytes(8)));
$operationId = 'OLO-TEST-' . $token;
$operationNo = 'TKTEST-' . $token;
$amount = 10000;
$original = $operationTemplate;
$adjustment = $adjustmentTemplate;
$payment = $paymentTemplate;

Db::startTrans();
try {
    unset($original['id'], $adjustment['id'], $payment['id']);
    $original['operation_id'] = $operationId;
    $original['operation_no'] = $operationNo;
    $original['command_idempotency_key'] = 'test-refund-' . $token;
    $original['immutable_fingerprint'] = hash('sha256', $operationId);
    $original['store_id'] = (int)$sale['store_id'];
    $original['member_id'] = (int)$sale['member_id'];
    $original['source_type'] = $kind === 'card' ? 'card' : 'document';
    $original['source_order_id'] = (string)$sale['order_id'];
    $original['source_order_no_snapshot'] = (string)$sale['order_no_snapshot'];
    $original['amount_cents'] = -$amount;
    $original['cash_refund_cents'] = $amount;
    $original['business_date'] = '2026-10-13';
    Db::name('cashier_v3_order_lifecycle_operation')->insert($original);

    $adjustment['adjustment_id'] = 'R26-TEST-' . $token;
    $adjustment['operation_id'] = $operationId;
    $adjustment['command_idempotency_key'] = $original['command_idempotency_key'];
    $adjustment['store_id'] = (int)$sale['store_id'];
    $adjustment['member_id'] = (int)$sale['member_id'];
    $adjustment['target_type'] = $kind === 'card' ? 'card' : 'document';
    $adjustment['card_holder_id'] = (int)$sale['card_holder_id'];
    $adjustment['card_component_id'] = (int)$sale['card_component_id'];
    $adjustment['source_order_id'] = (string)$sale['order_id'];
    $adjustment['source_line_id'] = (string)$sale['source_line_id'];
    $adjustment['source_sales_order_id'] = (string)$sale['order_id'];
    $adjustment['source_sales_line_id'] = (string)$sale['source_line_id'];
    $adjustment['source_order_no_snapshot'] = (string)$sale['order_no_snapshot'];
    $adjustment['target_name_snapshot'] = (string)$sale['item_name_snapshot'];
    $adjustment['refund_amount_cents'] = $amount;
    $adjustment['business_date'] = '2026-10-13';
    Db::name('cashier_v3_refund_adjustment')->insert($adjustment);

    $payment['fact_id'] = 'R26P-TEST-' . $token;
    $payment['business_event_no'] = 'test-refund-' . $token;
    $payment['natural_key'] = 'manual_refund_adjustment:' . $operationId;
    $payment['command_idempotency_key'] = $original['command_idempotency_key'];
    $payment['immutable_fingerprint'] = hash('sha256', $payment['fact_id']);
    $payment['checkout_request_id'] = $operationId;
    $payment['order_id'] = $operationId;
    $payment['order_no_snapshot'] = $operationNo;
    $payment['source_line_id'] = (string)$sale['source_line_id'];
    $payment['store_id'] = (int)$sale['store_id'];
    $payment['store_name_snapshot'] = (string)$sale['store_name_snapshot'];
    $payment['organization_id'] = (string)$sale['organization_id'];
    $payment['organization_path_snapshot'] = (string)$sale['organization_path_snapshot'];
    $payment['member_id'] = (int)$sale['member_id'];
    $payment['member_name_snapshot'] = (string)$sale['member_name_snapshot'];
    $payment['collection_reference'] = (string)$sale['order_no_snapshot'];
    $payment['payment_authority_key'] = $payment['natural_key'];
    $payment['business_date'] = '2026-10-13';
    $payment['amount_cents'] = -$amount;
    Db::name('cashier_v3_payment_fact')->insert($payment);

    $member = $service->query($storeId, $range + ['report' => 'member_consumption_detail', '_internal_all' => true]);
    $memberRows = array_values(array_filter($member['records'], static fn(array $row): bool =>
        (string)($row['order_no_snapshot'] ?? '') === $operationNo));
    $ratio = (int)$sale['partner_default_ratio_snapshot'];
    refundVisibilityCheck(count($memberRows) === 1 && $memberRows[0]['receipt_total'] === '-100'
        && $memberRows[0]['partner_performance'] === (string)-$ratio
        && $memberRows[0]['actual_cash_performance'] === (string)(-100 + $ratio),
        "{$kind} refund has one negative member row with original {$ratio}-percent share");
    refundVisibilityCheck((int)$member['total'] === (int)$baselineMember['total'] + 1,
        "{$kind} member count includes the refund once");
    refundVisibilityCheck((int)$member['summary_row']['receipt_total']
        === (int)$baselineMember['summary_row']['receipt_total'] - 100
        && (int)$member['summary_row']['partner_performance']
            === (int)$baselineMember['summary_row']['partner_performance'] - $ratio,
        "{$kind} member totals reflect both cash and original partner-share reversal");
    $memberByPartner = $service->query($storeId, $range + [
        'report' => 'member_consumption_detail', 'partner_name' => (string)$sale['partner_name_snapshot'],
        '_internal_all' => true,
    ]);
    refundVisibilityCheck(in_array($operationNo, array_column($memberByPartner['records'], 'order_no_snapshot'), true),
        "{$kind} member partner filter retains the refunded target");

    $partner = $service->query($storeId, $range + ['report' => 'partner_item_detail', '_internal_all' => true]);
    $partnerRows = array_values(array_filter($partner['records'], static fn(array $row): bool =>
        (string)($row['order_no_snapshot'] ?? '') === $operationNo));
    refundVisibilityCheck(count($partnerRows) === 1 && $partnerRows[0]['deal_amount'] === '-100'
        && $partnerRows[0]['original_sale_no'] === (string)$sale['order_no_snapshot']
        && $partnerRows[0]['quantity'] === 0,
        "{$kind} partner detail adds one dated refund row without repeating sale quantity");
    refundVisibilityCheck((int)$partner['total'] === (int)$baselinePartner['total'] + 1,
        "{$kind} partner detail total includes the refund once");
    $partnerByName = $service->query($storeId, $range + [
        'report' => 'partner_item_detail', 'partner_name' => (string)$sale['partner_name_snapshot'],
        '_internal_all' => true,
    ]);
    refundVisibilityCheck(in_array($operationNo, array_column($partnerByName['records'], 'order_no_snapshot'), true),
        "{$kind} partner report filter retains the refunded target");
    $categoryPath = (string)$memberRows[0]['category'];
    refundVisibilityCheck($categoryPath !== '', "{$kind} refund retains its selected product category");
    $partnerByCategory = $service->query($storeId, $range + [
        'report' => 'partner_item_detail', 'category_path_exact' => $categoryPath,
        '_internal_all' => true,
    ]);
    refundVisibilityCheck(in_array($operationNo, array_column($partnerByCategory['records'], 'order_no_snapshot'), true),
        "{$kind} exact category drilldown retains the refund");

    $summary = $service->query($storeId, $range + ['report' => 'partner_item_summary']);
    refundVisibilityCheck((int)$summary['summary_row']['sale_amount']
        === (int)$baselineSummary['summary_row']['sale_amount'] - 100,
        "{$kind} partner summary deducts the same one-hundred-yuan refund");

    $analysis = $service->query($storeId, $range + ['report' => 'store_item_analysis']);
    $before = $baselineAnalysis['summary_row'];
    $after = $analysis['summary_row'];
    refundVisibilityCheck((int)$after['item_analysis_cash_today'] === (int)$before['item_analysis_cash_today'] - 100
        && (int)$after['item_analysis_share_today'] === (int)$before['item_analysis_share_today'] - $ratio
        && (int)$after['item_analysis_actual_today'] === (int)$before['item_analysis_actual_today'] - 100 + $ratio,
        "{$kind} store-item cash, share and after-split reverse the original ratio once");
    $categoryCashKeys = array_filter(array_keys($after), static fn(string $key): bool =>
        (bool)preg_match('/^item_analysis_category_\\d+_cash$/', $key));
    $categoryDelta = 0;
    foreach ($categoryCashKeys as $key) $categoryDelta += (int)$after[$key] - (int)($before[$key] ?? 0);
    refundVisibilityCheck($categoryDelta === -100,
        "{$kind} refund belongs to exactly one current-product category");
} finally {
    Db::rollback();
}
refundVisibilityCheck(!Db::name('cashier_v3_order_lifecycle_operation')
    ->where('operation_id', $operationId)->find(), "{$kind} synthetic refund is fully rolled back");
}
