<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3) . '/后端代码/app/services/cashier/v3';
require_once $root . '/CashierV3ResultCode.php';
require_once $root . '/CashierV3CommandException.php';
require_once $root . '/CashierV3IdempotencyKeyServices.php';

use app\services\cashier\v3\CashierV3IdempotencyKeyServices;

$required = [
    'CASHIER_LINE_DEBT',
    'CASHIER_APPLY_SALESPEOPLE_ALL',
    'CASHIER_MORE',
    'CHECKOUT_BALANCE_RECOVERY',
    'ENTITLEMENT_ADD',
    'ENTITLEMENT_SELECTOR',
    'ROOM_CASHIER_INTENT',
];
$registered = CashierV3IdempotencyKeyServices::registeredPrefixes();
$missing = array_values(array_diff($required, $registered));
if ($missing !== []) {
    fwrite(STDERR, 'Missing prefixes: ' . implode(', ', $missing) . PHP_EOL);
    exit(1);
}

$service = new CashierV3IdempotencyKeyServices();
foreach ($required as $prefix) {
    $key = $prefix . '-123e4567-e89b-42d3-a456-426614174000';
    if ($service->normalizeIdempotencyKey($key) !== $key) {
        fwrite(STDERR, 'Normalization failed: ' . $prefix . PHP_EOL);
        exit(1);
    }
}

echo 'CASHIER_IDEMPOTENCY_PREFIX_CONTRACT_OK' . PHP_EOL;
