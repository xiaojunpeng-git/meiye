<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$source = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/member/CashierV3RechargeModule.php'
);

$checks = [
    'recharge accepts the unified allocation weight' => strpos($source, "array_key_exists('allocationWeight', \$allocation)") !== false,
    'selected weights must total one hundred' => strpos($source, '$usesWeights && $total !== 100') !== false,
    'allocation is calculated in whole yuan' => strpos($source, 'intdiv(intdiv($principalCents, 100) *') !== false
        && strpos($source, '$amountCents % 100 !== 0') !== false,
    'the last salesperson receives the integer remainder' => strpos($source, '$principalCents - $allocatedCents') !== false,
    'staff authority is still reloaded for the current store' => strpos($source, "->where('ss.store_id', \$operator->storeId())") !== false
        && strpos($source, "->where('ss.cashier_salesperson_enabled', 1)") !== false,
];

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$passed) $failed++;
}
exit($failed === 0 ? 0 : 1);
