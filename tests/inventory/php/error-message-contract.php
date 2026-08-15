<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/后端代码/app/services/product/inventory/InventoryErrorMessage.php';

use app\services\product\inventory\InventoryErrorMessage;

$cases = [
    'inventory_salon_usage_return_exceeds_issue' => '退回数量不能超过原领用数量。',
    'inventory_batch_transfer_input_invalid' => '调拨信息不完整，请检查调入仓和明细。',
    'inventory_stock_request_line_invalid' => '请货明细不合法，请检查商品和数量。',
    'inventory_platform_warehouse_idempotency_conflict' => '本次建仓内容与已提交记录不一致，请刷新后重新操作。',
    'inventory_manual_inbound_sku_not_found' => '所选商品规格已失效，请重新选择商品。',
    'inventory_manual_inbound_dates_required' => '请为每个入库商品填写生产日期和到期日。',
    'inventory_manual_inbound_default_location_ambiguous' => '当前门店存在多个默认库存仓，请联系管理员处理。',
    'inventory_manual_inbound_location_scope_invalid' => '当前门店默认库存仓归属异常，请联系管理员处理。',
    'inventory_manual_inbound_stock_changed' => '库存数据刚发生变化，请刷新后重新提交。',
    'inventory_manual_inbound_batch_cost_conflict' => '同一批次的入库单价必须保持一致，请更换批次或核对单价。',
];
$failed = 0;
foreach ($cases as $code => $message) {
    $actual = InventoryErrorMessage::from(new RuntimeException($code));
    $passed = $actual === ['code' => $code, 'message' => $message];
    echo ($passed ? 'PASS ' : 'FAIL ') . $code . PHP_EOL;
    if (!$passed) $failed++;
}
$unknown = InventoryErrorMessage::from(new RuntimeException('inventory_unknown_failure'));
$failed += $unknown === ['code' => 'inventory_unknown_failure', 'message' => '库存操作未完成，请核对数据后重试。'] ? 0 : 1;
echo ($unknown === ['code' => 'inventory_unknown_failure', 'message' => '库存操作未完成，请核对数据后重试。'] ? 'PASS ' : 'FAIL ') . 'inventory fallback stays Chinese' . PHP_EOL;
$notInventory = InventoryErrorMessage::from(new RuntimeException('plain failure'));
$failed += $notInventory === null ? 0 : 1;
echo ($notInventory === null ? 'PASS ' : 'FAIL ') . 'non-inventory failure is not intercepted' . PHP_EOL;
echo "INVENTORY_ERROR_MESSAGE_CONTRACT_RESULT failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
