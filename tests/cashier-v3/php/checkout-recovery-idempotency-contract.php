<?php
declare(strict_types=1);

$backend = dirname(__DIR__, 3) . '/后端代码/app/services/cashier/v3/settlement';
require_once $backend . '/CashierV3CheckoutSettlementContractException.php';
require_once $backend . '/CashierV3CheckoutProjectionServices.php';

use app\services\cashier\v3\settlement\CashierV3CheckoutProjectionServices;
use app\services\cashier\v3\settlement\CashierV3CheckoutSettlementContractException;

$passed = 0;
$failed = 0;

function recoveryOk(string $name, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}\n";
}

$uuid = '290cf8c0-7da5-4cb0-a042-15e00b78cbc3';
recoveryOk(
    'ready request restores the final key paired to its persisted preparation key',
    CashierV3CheckoutProjectionServices::recoveredOriginalIdempotencyKey(
        'ready_for_submit',
        'CHECKOUT_PREPARE-' . $uuid
    ) === 'CHECKOUT-' . $uuid
);
recoveryOk(
    'editing request does not claim an original final key',
    CashierV3CheckoutProjectionServices::recoveredOriginalIdempotencyKey(
        'editing',
        'CHECKOUT_PREPARE-' . $uuid
    ) === ''
);

$failedClosed = false;
try {
    CashierV3CheckoutProjectionServices::recoveredOriginalIdempotencyKey(
        'ready_for_submit',
        'CHECKOUT_PREPARE-invalid'
    );
} catch (CashierV3CheckoutSettlementContractException $exception) {
    $failedClosed = $exception->reason()
        === 'checkout_projection_preparation_idempotency_key_invalid';
}
recoveryOk('ready request with an invalid preparation key fails closed', $failedClosed);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
