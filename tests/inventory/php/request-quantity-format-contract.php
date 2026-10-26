<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/后端代码/app/services/product/inventory/InventoryStockRequestQueryServices.php';

use app\services\product\inventory\InventoryStockRequestQueryServices;

$service = (new ReflectionClass(InventoryStockRequestQueryServices::class))->newInstanceWithoutConstructor();
$decimal = new ReflectionMethod(InventoryStockRequestQueryServices::class, 'decimal');

$cases = [
    'integer trailing zeros are preserved' => [10000, 0, '10000'],
    'scaled whole quantity trims fractional zeros only' => [1000, 2, '10'],
    'scaled fractional quantity remains exact' => [125, 2, '1.25'],
    'scaled sub-unit quantity keeps its leading zero' => [5, 2, '0.05'],
];

$failed = 0;
foreach ($cases as $name => [$units, $scale, $expected]) {
    $actual = $decimal->invoke($service, $units, $scale);
    $passed = $actual === $expected;
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . ($passed ? '' : " expected={$expected} actual={$actual}") . PHP_EOL;
    if (!$passed) $failed++;
}

echo "REQUEST_QUANTITY_FORMAT_CONTRACT_RESULT failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
