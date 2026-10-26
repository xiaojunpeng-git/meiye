<?php

$root = dirname(__DIR__, 3);
$query = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php'
);
$overlay = (string)file_get_contents(
    $root . '/前端代码/cashier-v3/src/components/order/SalesOrderDetailOverlay.vue'
);

$checks = [
    'authority_header_selects_order_note' => strpos(
        $query,
        "o.original_amount_cents,o.discount_amount_cents,o.sale_amount_cents,o.order_version,o.order_note"
    ) !== false,
    'authority_projection_exposes_order_note' => strpos(
        $query,
        "'orderNote' => (string)(\$header['order_note'] ?? ''),"
    ) !== false && strpos(
        $query,
        "\$mapped['orderNote'] = (string)(\$header['order_note'] ?? '');"
    ) !== false,
    'detail_overlay_renders_header_note' => strpos(
        $overlay,
        "{ label: '订单备注', value: pickValue(sourceOrder.value, ['orderNote', 'remark', 'note']), wide: true }"
    ) !== false,
];

$failed = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$ok) $failed++;
}
echo $failed === 0
    ? "ORDER_HEADER_NOTE_AUTHORITY_CONTRACT=PASS\n"
    : "ORDER_HEADER_NOTE_AUTHORITY_CONTRACT=FAIL\n";
exit($failed === 0 ? 0 : 1);
