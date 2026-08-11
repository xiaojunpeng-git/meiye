<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$module = file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/cashier/CashierV3CashierModule.php'
);
$dispatcher = file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/CashierV3ActionDispatcher.php'
);
$repository = file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/settlement/ThinkPhpCashierV3CheckoutRequestRepository.php'
);
$rebuilder = file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutDraftAuthorityRebuilder.php'
);

$passed = 0;
$failed = 0;
function checkoutDraftProjectionOk(string $name, bool $condition): void
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

$commandBlock = static function (string $command) use ($module): string {
    if (!is_string($module)) {
        return '';
    }
    $start = strpos($module, "registerCommand('{$command}'");
    if ($start === false) {
        return '';
    }
    $next = strpos($module, "registerCommand('", $start + 1);
    return substr($module, $start, $next === false ? null : $next - $start);
};
$loopBlock = static function (string $needle, string $nextNeedle) use ($module): string {
    if (!is_string($module)) {
        return '';
    }
    $start = strpos($module, $needle);
    if ($start === false) {
        return '';
    }
    $next = strpos($module, $nextNeedle, $start + strlen($needle));
    return substr($module, $start, $next === false ? null : $next - $start);
};

$prepare = $commandBlock('prepare-checkout');
$payment = $loopBlock(
    "foreach (['add-payment-method', 'update-payment-line', 'remove-payment-line'] as \$action)",
    "foreach (['apply-balance-payment', 'remove-balance-payment', 'update-balance-payment'] as \$action)"
);
$balance = $loopBlock(
    "foreach (['apply-balance-payment', 'remove-balance-payment', 'update-balance-payment'] as \$action)",
    "if (\$handlers->hasCommand('return-to-payment-edit'))"
);
$returnToEdit = $commandBlock('return-to-payment-edit');
$submission = $commandBlock('prepare-checkout-submission');
$finalSubmission = $commandBlock('submit-checkout');

checkoutDraftProjectionOk(
    'checkout preparation returns the authoritative root before payment editing',
    strpos($prepare, "'return_root_state' => true") !== false
);
checkoutDraftProjectionOk(
    'external payment draft mutations request a narrow checkout projection',
    strpos($payment, "'_checkoutProjectionRequestId'") !== false
    && strpos($payment, "'return_root_state' => true") === false
    && strpos($payment, "'checkout_request'") !== false
);
checkoutDraftProjectionOk(
    'balance payment draft mutations request a narrow checkout projection',
    strpos($balance, "'_checkoutProjectionRequestId'") !== false
    && strpos($balance, "'return_root_state' => true") === false
    && strpos($balance, "'checkout_request'") !== false
);
checkoutDraftProjectionOk(
    'dispatcher reads the committed checkout projection before deciding on a full root rebuild',
    is_string($dispatcher)
    && strpos($dispatcher, 'readCheckoutDraftProjection') !== false
    && strpos($dispatcher, 'attachCheckoutDraftProjection') !== false
    && strpos($dispatcher, '&& $checkoutDraftProjection === null') !== false
);
checkoutDraftProjectionOk(
    'narrow checkout projection resolves the committed exact request instead of guessing the latest draft',
    is_string($dispatcher)
    && strpos($dispatcher, '->readEditingRequest(') !== false
    && is_string($repository)
    && strpos($repository, 'readEditingProjectionByRequestId') !== false
    && strpos($repository, "->where('request_id', \$requestId)") !== false
    && strpos($repository, "'exactRequestProjection' => true") !== false
);
checkoutDraftProjectionOk(
    'payment draft rebuild accepts workspace-only edits on its locked exact request',
    is_string($rebuilder)
    && strpos($rebuilder, "'exactRequestProjection' => true") !== false
);
checkoutDraftProjectionOk(
    'balance-conflict recovery reopens the persisted payment edit with a root projection',
    strpos($returnToEdit, "'return_root_state' => true") !== false
);
checkoutDraftProjectionOk(
    'submission preparation returns the token and version required by final settlement',
    strpos($submission, "'return_root_state' => true") !== false
    && strpos($submission, "'checkout_request'") !== false
);
checkoutDraftProjectionOk(
    'final settlement returns the rebuilt authoritative root so success is never rendered as result unknown',
    strpos($finalSubmission, "'return_root_state' => true") !== false
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
