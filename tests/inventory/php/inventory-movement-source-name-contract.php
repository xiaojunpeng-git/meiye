<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\product\inventory\InventoryMovementAnalyticsServices;
use app\services\product\inventory\InventoryMovementDisplayServices;

// 来源代码供事实关联与过滤使用；中文名称只作为出入库统计的展示值，未知代码在接口保留但展示中文兜底。
$service = (new ReflectionClass(InventoryMovementAnalyticsServices::class))->newInstanceWithoutConstructor();
$sourceTypeName = new ReflectionMethod(InventoryMovementAnalyticsServices::class, 'sourceTypeName');
$sourceTypeName->setAccessible(true);
$cases = [
    'cashier_sale' => '收银结账',
    'manual_outbound' => '手工出库',
    'cross_transfer_withdraw' => '调拨撤回入库',
    'unknown_source' => '其他库存业务',
    'cross_transfer_in' => '调拨入库', 'cross_transfer_out' => '调拨出库',
    'manual_inbound_reversal' => '手工入库作废', 'salon_usage_issue_reversal' => '院装领用作废退库',
];
$failed = 0;
foreach ($cases as $sourceType => $expected) {
    $actual = $sourceTypeName->invoke($service, $sourceType);
    $valid = $actual === $expected;
    echo ($valid ? 'PASS ' : 'FAIL ') . $sourceType . ': ' . $actual . PHP_EOL;
    if (!$valid) $failed++;
}
$displayTypeNames = (new ReflectionClass(InventoryMovementDisplayServices::class))->getConstant('TYPE_NAMES');
$valid = ($displayTypeNames['cross_transfer_withdraw'] ?? null) === '调拨撤回入库';
echo ($valid ? 'PASS ' : 'FAIL ') . 'movement detail cross_transfer_withdraw' . PHP_EOL;
if (!$valid) $failed++;
echo "INVENTORY_MOVEMENT_SOURCE_NAME_CONTRACT_RESULT failed={$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
