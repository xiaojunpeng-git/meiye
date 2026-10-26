<?php

$root = dirname(__DIR__, 3);
require_once $root . '/后端代码/app/services/cashier/v3/cashier/CashierV3EntitlementActualAmountAllocator.php';

use app\services\cashier\v3\cashier\CashierV3EntitlementActualAmountAllocator as Allocator;

$replacement = ['sourceType' => 'cashier_v3_project_replacement'];
$upgrade = ['sourceType' => 'cashier_v3_project_upgrade'];

if (Allocator::remainingForSnapshot('2393.33', 1, 0, $replacement) !== '2393.33') {
    fwrite(STDERR, "replacement cent amount was not preserved\n");
    exit(1);
}
if (Allocator::allocateForSnapshot('100.01', 3, 0, 2, $upgrade) !== '66.66'
    || Allocator::allocateForSnapshot('100.01', 3, 2, 1, $upgrade) !== '33.35') {
    fwrite(STDERR, "cent allocation did not preserve the final remainder\n");
    exit(1);
}
try {
    Allocator::remainingForSnapshot('2393.33', 1, 0, []);
    fwrite(STDERR, "legacy snapshot unexpectedly accepted cent amount\n");
    exit(1);
} catch (InvalidArgumentException $expected) {
}
echo "operation-cent entitlement contract passed\n";
