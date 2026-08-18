<?php
declare(strict_types=1);

$backendRoot = dirname(__DIR__, 3) . '/后端代码';

require_once $backendRoot . '/vendor/autoload.php';
require_once $backendRoot . '/app/services/cashier/v3/CashierV3ResultCode.php';
require_once $backendRoot . '/app/services/cashier/v3/CashierV3CommandException.php';
require_once $backendRoot . '/app/services/cashier/v3/CashierV3AliasResolver.php';
require_once $backendRoot . '/app/services/cashier/v3/CashierV3RequestNormalizer.php';
require_once $backendRoot . '/app/services/cashier/v3/CashierV3CommandGatewayServices.php';

use app\services\cashier\v3\CashierV3CommandException;
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
    'action' => 'update-checkout-business-source',
    'clientSessionId' => 'SESSION-00000000-0000-4000-8000-000000000001',
    'stateContextId' => 'SC-business-source-transport',
    'correlationId' => 'CORR-business-source-transport',
    'command' => [
        'action' => 'update-checkout-business-source',
        'idempotencyKey' => 'CMD-00000000-0000-4000-8000-000000000001',
        'contexts' => [],
    ],
    // Session middleware metadata must not be treated as command payload.
    'store_id' => 1,
    'checkoutRequestId' => 'CKR-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    'checkoutRequestVersion' => 1,
    'preparationRequestId' => 'CHECKOUT_PREPARE-00000000-0000-4000-8000-000000000001',
    'preparationToken' => 'CKPT-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
    'primarySourceId' => 4,
    'secondarySourceId' => 0,
    'sourceSelectionVersion' => 0,
    'rewardAmountCents' => 0,
];

$payload = CashierV3CommandGatewayServices::stripTransportFields($request);
$check(
    'BUSINESS-SOURCE-TRANSPORT-01 session store metadata is stripped',
    !array_key_exists('store_id', $payload)
);

$normalized = CashierV3RequestNormalizer::normalize('update-checkout-business-source', $payload)['normalized'];
$check(
    'BUSINESS-SOURCE-TRANSPORT-02 rewardAmountCents is accepted and retained',
    $normalized['rewardAmountCents'] === 0
        && $normalized['primarySourceId'] === 4
        && $normalized['secondarySourceId'] === 0
);

$withReward = $payload;
$withReward['rewardAmountCents'] = '123';
$normalizedWithReward = CashierV3RequestNormalizer::normalize('update-checkout-business-source', $withReward)['normalized'];
$check(
    'BUSINESS-SOURCE-TRANSPORT-03 integer-string reward amount is normalized to cents',
    $normalizedWithReward['rewardAmountCents'] === 123
);

$tooLarge = $payload;
$tooLarge['rewardAmountCents'] = 100000000001;
$rejected = false;
try {
    CashierV3RequestNormalizer::normalize('update-checkout-business-source', $tooLarge);
} catch (CashierV3CommandException $exception) {
    $rejected = true;
}
$check(
    'BUSINESS-SOURCE-TRANSPORT-04 oversized reward amount is rejected',
    $rejected
);

echo sprintf('checkout-business-source-transport-contract: %d passed, %d failed', $passed, $failed) . PHP_EOL;
exit($failed > 0 ? 1 : 0);
