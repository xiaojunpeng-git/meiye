<?php
/**
 * Static contract for the final checkout submit boundary.
 *
 * The submit handler must fail before settlement when a required schema
 * dependency is missing, and the HTTP command boundary must preserve that
 * failure as a bound V3 result instead of leaking a raw exception.
 */

$backendRoot = getenv('CASHIER_V3_BACKEND_ROOT');
$backendRoot = is_string($backendRoot) && $backendRoot !== ''
    ? rtrim($backendRoot, '/')
    : __DIR__ . '/../../../后端代码';

$saleSubmission = file_get_contents(
    $backendRoot . '/app/services/cashier/v3/settlement/CashierV3SaleOnlyCheckoutSubmissionServices.php'
);
$gateway = file_get_contents(
    $backendRoot . '/app/services/cashier/v3/CashierV3CommandGatewayServices.php'
);
$failureEnvelope = file_get_contents(
    $backendRoot . '/app/services/cashier/v3/CashierV3CommandFailureEnvelopeServices.php'
);
$commandController = file_get_contents(
    $backendRoot . '/app/controller/cashier/v3/Command.php'
);

$passed = 0;
$failed = 0;

function submitContractAssert(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}" . ($detail === '' ? '' : ": {$detail}") . "\n";
}

submitContractAssert(
    'final submit checks card-operation settlement schema before business writes',
    str_contains($gateway, 'if ($canonicalAction === \'submit-checkout\')')
        && str_contains($gateway, 'assertFinalCheckoutDependenciesReady')
        && str_contains($gateway, "'eb_cashier_v3_card_operation_settlement'")
        && str_contains($saleSubmission, "'eb_cashier_v3_card_operation_settlement'")
        && str_contains($saleSubmission, 'checkout_submission_tables_missing')
        && str_contains($saleSubmission, 'ACTION_DEPENDENCY_NOT_READY')
);
submitContractAssert(
    'unexpected submit exceptions become deterministic failed envelopes',
    str_contains($failureEnvelope, 'function fromThrowable')
        && str_contains($failureEnvelope, 'cashier_v3_unexpected_command_failure')
        && str_contains($failureEnvelope, '结账提交失败，业务数据已回滚')
        && str_contains($failureEnvelope, 'boundIdempotencyKey')
);
submitContractAssert(
    'missing schema errors are operator-safe and classified as dependency failures',
    str_contains($failureEnvelope, 'Base table or view not found')
        && str_contains($failureEnvelope, 'ACTION_DEPENDENCY_NOT_READY')
        && str_contains($failureEnvelope, 'SQL/schema details never reach')
);
submitContractAssert(
    'command controller catches non-domain exceptions at the HTTP boundary',
    str_contains($commandController, 'catch (\\Throwable $exception)')
        && str_contains($commandController, '->fromThrowable($body, $exception)')
);

if ($failed > 0) {
    fwrite(STDERR, "checkout-submit-failure-envelope-contract: {$failed} failed\n");
    exit(1);
}

echo "checkout-submit-failure-envelope-contract: {$passed} passed, 0 failed\n";
