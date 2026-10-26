<?php
declare(strict_types=1);

/** Fifth-round boundary test: new labor amounts stay integer while old snapshots are only input. */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
$root = dirname(__DIR__, 2);
require $root . '/后端代码/vendor/autoload.php';
require $root . '/后端代码/app/services/cashier/v3/LegacyCraftsmanPerformanceDefault.php';

use app\services\cashier\v3\LegacyCraftsmanPerformanceDefault as DefaultValue;

$cases = [
    ['169.49', '169'],
    ['169.50', '170'],
    ['0', '0'],
];
foreach ($cases as [$raw, $expected]) {
    if (DefaultValue::actualUnitYuan($raw) !== $expected) {
        throw new RuntimeException("integer rounding failed for {$raw}");
    }
}
if (DefaultValue::splitYuan('170', 3) !== [56, 56, 58]) {
    throw new RuntimeException('staff split did not preserve the integer total');
}
if (DefaultValue::relationUnitYuan(['craftsman_default_mode' => 'default_unit_price']) !== null) {
    throw new RuntimeException('default unit price must leave this service amount intact');
}
foreach ([0 => '0', 17000 => '170'] as $cents => $expected) {
    if (DefaultValue::relationUnitYuan([
        'craftsman_default_mode' => 'manual_amount',
        'craftsman_default_unit_amount_cents' => $cents,
    ]) !== $expected) {
        throw new RuntimeException("manual card amount failed for {$cents}");
    }
}
foreach ([17050, -100] as $invalid) {
    try {
        DefaultValue::relationUnitYuan([
            'craftsman_default_mode' => 'manual_amount',
            'craftsman_default_unit_amount_cents' => $invalid,
        ]);
    } catch (\think\exception\ValidateException $expected) {
        continue;
    }
    throw new RuntimeException("invalid card amount {$invalid} was accepted");
}
echo "010 legacy craftsman default PASS\n";
