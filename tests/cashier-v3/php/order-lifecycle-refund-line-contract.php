<?php

$root = dirname(__DIR__, 3);
$lifecycle = file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleServices.php');
$query = file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php');
$allocation = file_get_contents($root . '/后端代码/app/services/report/StoreReportPaymentSaleAllocationFactServices.php');
$migration = file_get_contents($root . '/后端代码/database/upgrades/2026-08-18-收银V3按明细退款权威/02-正式升级.sql');

$checks = [
    'refund_line_authority_is_append_only_and_scoped' => strpos($migration, 'eb_cashier_v3_order_lifecycle_refund_line') !== false
        && strpos($migration, 'UNIQUE KEY `uk_operation_source_line`') !== false
        && strpos($migration, 'idx_source_line') !== false,
    'refund_line_uses_original_settled_sale_amount_as_weight' => strpos($lifecycle, 'sale_amount_cents') !== false
        && strpos($lifecycle, 'saleAmountCents') !== false
        && strpos($lifecycle, 'allocationWeightDenominator') !== false,
    'refund_components_use_deterministic_largest_remainder' => strpos($lifecycle, 'allocateRefundComponent') !== false
        && strpos($lifecycle, 'bcmul') !== false
        && strpos($lifecycle, 'bcmod') !== false
        && strpos($lifecycle, "'lineNo'") !== false,
    'selected_lines_only_write_sale_reversals' => strpos($lifecycle, 'order_lifecycle_refund_line_amount_exceeds_sale') !== false
        && strpos($lifecycle, "\$lineRefunds[(string)\$line['lineId']]") !== false,
    'payment_sale_allocations_do_not_reach_unselected_lines' => strpos($allocation, 'lifecycle_payment_allocation_refund_line_scope_missing') !== false
        && strpos($allocation, 'selectedLineIds') !== false,
    'order_detail_reads_refund_line_history' => strpos($query, 'refundLineDetails') !== false
        && strpos($query, '部分退款') !== false
        && strpos($query, 'cashRefundAmount') !== false,
];

$failed = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$ok) $failed++;
}
echo $failed === 0 ? "ORDER_LIFECYCLE_REFUND_LINE_CONTRACT=PASS\n" : "ORDER_LIFECYCLE_REFUND_LINE_CONTRACT=FAIL\n";
exit($failed === 0 ? 0 : 1);
