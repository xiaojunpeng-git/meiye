<?php
declare(strict_types=1);

$backendRoot = dirname(__DIR__, 3) . '/后端代码';

require_once $backendRoot . '/vendor/autoload.php';
require_once $backendRoot . '/app/services/cashier/v3/CashierV3ResultCode.php';
require_once $backendRoot . '/app/services/cashier/v3/CashierV3CommandException.php';
require_once $backendRoot . '/app/services/cashier/v3/CashierV3AliasResolver.php';
require_once $backendRoot . '/app/services/cashier/v3/CashierV3RequestNormalizer.php';
require_once $backendRoot . '/app/services/cashier/v3/CashierV3CommandGatewayServices.php';

use app\services\cashier\v3\CashierV3CommandGatewayServices;
use app\services\cashier\v3\CashierV3RequestNormalizer;

$passed = 0;
$failed = 0;

$check = static function (string $name, bool $condition) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo '[PASS] ' . $name . PHP_EOL;
        return;
    }
    $failed++;
    echo '[FAIL] ' . $name . PHP_EOL;
};

$request = [
    'action' => 'add-payment-method',
    'clientSessionId' => 'SESSION-00000000-0000-4000-8000-000000000001',
    'stateContextId' => 'SC-transport-contract',
    'correlationId' => 'CORR-transport-contract',
    'command' => [
        'action' => 'add-payment-method',
        'idempotencyKey' => 'CMD-00000000-0000-4000-8000-000000000001',
        'contexts' => [],
    ],
    // ForceStoreSessionMiddleware adds this before the V3 controller receives the body.
    'store_id' => 1,
    'checkoutRequestId' => 'CKR-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    'checkoutRequestVersion' => 1,
    'preparationRequestId' => 'CHECKOUT_PREPARE-00000000-0000-4000-8000-000000000001',
    'preparationToken' => 'CKPT-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
    'paymentMethodId' => 'other_collection',
];

$payload = CashierV3CommandGatewayServices::stripTransportFields($request);
$check(
    'PAYMENT-TRANSPORT-01 session-injected store is removed before strict payment normalization',
    !array_key_exists('store_id', $payload)
);

$normalized = CashierV3RequestNormalizer::normalize('add-payment-method', $payload)['normalized'];
$check(
    'PAYMENT-TRANSPORT-02 strict payment payload accepts the normalized HTTP envelope',
    $normalized === [
        'checkoutRequestId' => 'CKR-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        'checkoutRequestVersion' => 1,
        'preparationRequestId' => 'CHECKOUT_PREPARE-00000000-0000-4000-8000-000000000001',
        'preparationToken' => 'CKPT-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        'paymentMethodId' => 'other_collection',
    ]
);

echo sprintf('checkout-payment-transport-contract: %d passed, %d failed', $passed, $failed) . PHP_EOL;
exit($failed > 0 ? 1 : 0);
