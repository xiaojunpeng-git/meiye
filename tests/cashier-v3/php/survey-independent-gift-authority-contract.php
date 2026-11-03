<?php

require_once __DIR__ . '/../../../后端代码/app/services/cashier/v3/checkout/CashierV3IndependentGiftAuthority.php';

use app\services\cashier\v3\checkout\CashierV3IndependentGiftAuthority as Authority;

// 固定历史问卷赠送的来源形状，并覆盖隐藏订单、退款、过期及误识别边界。
$detail = [
    'id' => 72352, 'uid' => 10314, 'oid' => 127880, 'cart_id' => '74327',
    'cart_type' => 0, 'source_type' => '', 'product_id' => 9298,
    'product_type' => 6, 'is_gift' => 1, 'is_writeoff' => 0,
    'write_times' => 1, 'write_surplus_times' => 1,
    'write_start' => 1793163746, 'write_end' => 1824699746,
];
$order = [
    'id' => 127880, 'uid' => 10314, 'mark' => '完成问卷赠送·任务#6',
    'paid' => 1, 'is_del' => 0, 'is_system_del' => 1,
    'is_user_del' => 0, 'refund_status' => 0,
    'terminal_action' => 0, 'card_upgrade_use_oid' => 0,
];
$now = 1793692800;
$check = static function (bool $condition, string $case): void {
    if (!$condition) {
        throw new RuntimeException($case);
    }
};
$check(Authority::isSupported($detail, $order, $now), 'survey reward remains usable');
foreach ([
    ['detail' => ['is_gift' => 0]],
    ['detail' => ['write_surplus_times' => 0]],
    ['detail' => ['cart_type' => 2]],
    ['detail' => ['uid' => 10315]],
    ['detail' => ['write_end' => $now - 1]],
    ['order' => ['mark' => '普通赠送订单']],
    ['order' => ['refund_status' => 1]],
    ['order' => ['terminal_action' => 1]],
    ['order' => ['is_system_del' => 2]],
] as $index => $change) {
    $candidateDetail = array_replace($detail, $change['detail'] ?? []);
    $candidateOrder = array_replace($order, $change['order'] ?? []);
    $check(!Authority::isSupported($candidateDetail, $candidateOrder, $now), 'reject invalid source ' . $index);
}
$migrationDetail = array_replace($detail, [
    'cart_id' => 'RH-PG-17', 'cart_type' => 2, 'source_type' => 'migration',
    'write_start' => 0, 'write_end' => 0,
]);
$migrationOrder = array_replace($order, ['mark' => 'migration_gift', 'is_system_del' => 0]);
$check(Authority::isSupported($migrationDetail, $migrationOrder, $now), 'prior migrated gift remains usable');
echo "survey-independent-gift-authority-contract: OK\n";
