<?php

$root = dirname(__DIR__, 3);
require_once $root . '/后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleServices.php';

use app\services\cashier\v3\order\CashierV3OrderLifecycleServices;

$reflection = new ReflectionClass(CashierV3OrderLifecycleServices::class);
$service = $reflection->newInstance();

$normalize = $reflection->getMethod('singleLinePersonnelInput');
$normalized = $normalize->invoke($service, [
    'targetOrderLineId' => 'LINE-1',
    'targetRole' => 'salesperson',
    'personnel' => [[
        'orderLineId' => 'LINE-1',
        'role' => 'salesperson',
        'staffId' => 11,
        'allocationWeight' => 100,
        'performanceAmountCents' => 66600,
        'performanceAmountManual' => true,
    ]],
]);

$allocate = $reflection->getMethod('adjustedSalespersonAmounts');
$single = $allocate->invoke($service, 39900, $normalized['personnel']);
$mixed = $allocate->invoke($service, 100000, [
    [
        'allocationWeight' => 50,
        'performanceAmountCents' => 66600,
        'performanceAmountManual' => true,
    ],
    [
        'allocationWeight' => 50,
        'performanceAmountCents' => 50000,
        'performanceAmountManual' => false,
    ],
]);

$checks = [
    'manual_666_yuan_is_normalized_as_66600_cents' => ($normalized['personnel'][0]['performanceAmountCents'] ?? null) === 66600
        && ($normalized['personnel'][0]['performanceAmountManual'] ?? null) === true,
    'single_manual_amount_overrides_original_line_amount' => $single === [66600],
    'manual_amount_does_not_rebalance_automatic_people' => $mixed === [66600, 50000],
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$passed) $failed = true;
}

if ($failed) exit(1);
echo 'ORDER_PERSONNEL_MANUAL_PERFORMANCE_AMOUNT=PASS' . PHP_EOL;
