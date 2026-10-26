<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$source = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/member/CashierV3RechargeModule.php'
);

$checks = [
    'recharge accepts the unified allocation weight' => strpos($source, "(int)(\$row['allocationWeight'] ?? 0)") !== false,
    'independent position groups each require one hundred percent' => strpos($source, "'recharge_salespeople_group_weight_invalid'") !== false
        && strpos($source, "'independent:'") !== false,
    'allocation uses actual collected principal rather than recharge principal' => strpos($source, 'resolveSalespeopleForPreparation(array $allocations, int $cashPerformanceCents') !== false
        && strpos($source, '$cashPerformanceCents - $allocated') !== false,
    'manual performance amount stays final' => strpos($source, "'performanceAmountManual' => (bool)\$selection['performanceAmountManual']") !== false
        && strpos($source, 'foreach ($manualIndexes as $index) $result[$index][\'amountCents\']') !== false,
    'prepared snapshot is used without staff re-query at final checkout' => strpos($source, 'normalizePreparedSalespeopleSnapshot') !== false
        && strpos($source, "'recharge_checkout_salespeople_snapshot'") !== false,
    'staff authority is still reloaded for the current store' => strpos($source, "->where('ss.store_id', \$operator->storeId())") !== false
        && strpos($source, "->where('ss.cashier_salesperson_enabled', 1)") !== false,
];

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$passed) $failed++;
}
exit($failed === 0 ? 0 : 1);
