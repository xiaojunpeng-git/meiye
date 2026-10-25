<?php
/** Verify group capacity keeps physical stock protection while cards/projects use their own sale places. */

error_reporting(E_ALL & ~E_DEPRECATED);
require __DIR__ . '/../../后端代码/vendor/autoload.php';

use app\services\activity\combination\StoreCombinationServices;
use think\exception\ValidateException;

$service = (new ReflectionClass(StoreCombinationServices::class))->newInstanceWithoutConstructor();
$capacity = new ReflectionMethod(StoreCombinationServices::class, 'prepareGroupCapacity');
$requiresCap = new ReflectionMethod(StoreCombinationServices::class, 'requiresCatalogStockCap');
$check = static function (bool $ok, string $case): void {
    if (!$ok) throw new RuntimeException($case);
    echo "PASS {$case}\n";
};
$fails = static function (callable $fn): bool {
    try {
        $fn();
        return false;
    } catch (ValidateException $e) {
        return true;
    }
};

foreach ([5, 6] as $type) {
    $protect = $requiresCap->invoke($service, ['product_type' => $type, 'is_inventory' => 0]);
    [$rows, $quota, $stock] = $capacity->invoke($service, [
        ['quota' => 4, 'stock' => 0],
        ['quota' => 3, 'stock' => 0],
    ], $protect, 0);
    $check($protect === false && $quota === 7 && $stock === 7 && $rows[0]['stock'] === 4 && $rows[1]['stock'] === 3,
        "rights type {$type}: zero catalog stock uses the configured group places");
}
$strict = $requiresCap->invoke($service, ['product_type' => 0, 'is_inventory' => 1, 'allow_negative_stock' => 0]);
[$rows, $quota, $stock] = $capacity->invoke($service, [['quota' => 3, 'stock' => 5]], $strict, 5);
$check($quota === 3 && $stock === 3 && $rows[0]['stock'] === 3, 'physical group stock is its own quota');
$check($fails(static fn() => $capacity->invoke($service, [['quota' => 6, 'stock' => 5]], $strict, 5)),
    'physical quota above catalog stock is rejected');
foreach ([
    ['is_inventory' => 0, 'allow_negative_stock' => 0],
    ['is_inventory' => 1, 'allow_negative_stock' => 1],
] as $settings) {
    $protect = $requiresCap->invoke($service, ['product_type' => 0] + $settings);
    [$rows, $quota, $stock] = $capacity->invoke($service, [['quota' => 6, 'stock' => 0]], $protect, 0);
    $check(!$protect && $quota === 6 && $stock === 6 && $rows[0]['stock'] === 6,
        'physical product without stock cap still limits its own group places');
}
$check($fails(static fn() => $capacity->invoke($service, [['quota' => 0, 'stock' => 0]], false, 0)),
    'zero group places are rejected');
$check($fails(static fn() => $capacity->invoke($service, [['quota' => 1.5, 'stock' => 0]], false, 0)),
    'fractional group places are rejected');
echo "R62 GROUP CAPACITY PASS\n";
