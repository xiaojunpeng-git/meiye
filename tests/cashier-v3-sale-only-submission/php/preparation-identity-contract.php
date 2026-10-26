<?php
declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

$root = dirname(__DIR__, 3);
$base = $root . '/后端代码/app/services/cashier/v3';

require $base . '/CashierV3ResultCode.php';
require $base . '/CashierV3CommandException.php';
require $base . '/settlement/CashierV3SaleOnlyCheckoutSubmissionServices.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\settlement\CashierV3SaleOnlyCheckoutSubmissionServices;

$passed = 0;
$failed = 0;

function preparationIdentityOk(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}" . ($detail === '' ? '' : ': ' . $detail) . "\n";
}

function preparationIdentityFailure(ReflectionMethod $method, array $payload): array
{
    try {
        $method->invoke(null, $payload);
    } catch (CashierV3CommandException $exception) {
        return [
            'code' => $exception->getResultCode(),
            'reason' => (string)($exception->getDetail()['reason'] ?? ''),
        ];
    }
    return ['code' => '', 'reason' => ''];
}

$assertPayload = new ReflectionMethod(
    CashierV3SaleOnlyCheckoutSubmissionServices::class,
    'assertPayload'
);
$isSupportedSaleProduct = new ReflectionMethod(
    CashierV3SaleOnlyCheckoutSubmissionServices::class,
    'isSupportedSaleProduct'
);
if (PHP_VERSION_ID < 80100) {
    $assertPayload->setAccessible(true);
    $isSupportedSaleProduct->setAccessible(true);
}

$payload = [
    'checkoutRequestId' => 'CKR-' . str_repeat('a', 40),
    'checkoutRequestVersion' => 3,
    'preparationRequestId' => 'CHECKOUT_PREPARE-00000000-0000-4000-8000-000000000001',
    'preparationToken' => 'CKPT-' . str_repeat('b', 64),
];

$validFailure = preparationIdentityFailure($assertPayload, $payload);
preparationIdentityOk(
    'SO-SUB-PREP-01 final submission accepts the frozen CHECKOUT_PREPARE creation identity',
    $validFailure === ['code' => '', 'reason' => ''],
    json_encode($validFailure)
);

$wrongNamespace = $payload;
$wrongNamespace['preparationRequestId'] = 'CHECKOUT-00000000-0000-4000-8000-000000000001';
$wrongFailure = preparationIdentityFailure($assertPayload, $wrongNamespace);
preparationIdentityOk(
    'SO-SUB-PREP-02 final command namespace cannot masquerade as the preparation identity',
    $wrongFailure['code'] === CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE
        && $wrongFailure['reason'] === 'checkout_submit_payload_invalid',
    json_encode($wrongFailure)
);

$broadNamespace = $payload;
$broadNamespace['preparationRequestId'] = 'CHECKOUT_PREPARE-extra-00000000-0000-4000-8000-000000000001';
$broadFailure = preparationIdentityFailure($assertPayload, $broadNamespace);
preparationIdentityOk(
    'SO-SUB-PREP-03 preparation identity uses the exact registered UUID namespace',
    $broadFailure['code'] === CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE
        && $broadFailure['reason'] === 'checkout_submit_payload_invalid',
    json_encode($broadFailure)
);

preparationIdentityOk(
    'SO-SUB-PREP-04 final submission accepts an ordinary product only as a product sale line',
    $isSupportedSaleProduct->invoke(null, 'product', ['id' => 100, 'pid' => 100, 'product_type' => 0]) === true
        && $isSupportedSaleProduct->invoke(null, 'card', ['id' => 100, 'pid' => 100, 'product_type' => 0]) === false
);

preparationIdentityOk(
    'SO-SUB-PREP-05 final submission accepts ordinary card packages and the legacy custom-card shell',
    $isSupportedSaleProduct->invoke(null, 'card', ['id' => 200, 'pid' => 200, 'product_type' => 5]) === true
        && $isSupportedSaleProduct->invoke(null, 'card', ['id' => 80556, 'pid' => 8154, 'product_type' => 6]) === true
        && $isSupportedSaleProduct->invoke(null, 'card', ['id' => 8154, 'pid' => 8000, 'product_type' => 6]) === true
);

preparationIdentityOk(
    'SO-SUB-PREP-06 a non-custom project cannot masquerade as a card purchase',
    $isSupportedSaleProduct->invoke(null, 'card', ['id' => 300, 'pid' => 300, 'product_type' => 6]) === false
        && $isSupportedSaleProduct->invoke(null, 'product', ['id' => 300, 'pid' => 300, 'product_type' => 6]) === false
);

echo 'SALE_ONLY_PREPARATION_IDENTITY assertions=' . ($passed + $failed)
    . " passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
