<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\product\inventory\InventoryMovementAnalyticsServices;

// 来源代码供事实关联与过滤使用；中文名称只作为出入库统计的展示值，未知代码仍原样显示以便排查。
$service = (new ReflectionClass(InventoryMovementAnalyticsServices::class))->newInstanceWithoutConstructor();
$sourceTypeName = new ReflectionMethod(InventoryMovementAnalyticsServices::class, 'sourceTypeName');
$cases = [
    'cashier_sale' => '收银销售出库',
    'manual_outbound' => '手工出库',
    'unknown_source' => 'unknown_source',
];
$failed = 0;
foreach ($cases as $sourceType => $expected) {
    $actual = $sourceTypeName->invoke($service, $sourceType);
    $valid = $actual === $expected;
    echo ($valid ? 'PASS ' : 'FAIL ') . $sourceType . ': ' . $actual . PHP_EOL;
    if (!$valid) $failed++;
}
echo "INVENTORY_MOVEMENT_SOURCE_NAME_CONTRACT_RESULT failed={$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
