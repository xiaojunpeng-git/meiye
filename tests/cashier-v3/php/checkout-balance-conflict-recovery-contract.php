<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$balanceDrafts = file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutBalanceDraftServices.php'
);
$module = file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/cashier/CashierV3CashierModule.php'
);
$normalizer = file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/CashierV3RequestNormalizer.php'
);

$passed = 0;
$failed = 0;
function balanceRecoveryOk(string $name, bool $condition): void
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

balanceRecoveryOk(
    'recovery locks only a prepared checkout aggregate',
    is_string($balanceDrafts)
    && strpos($balanceDrafts, 'function returnToPaymentEditInTx(array $scope): array') !== false
    && strpos($balanceDrafts, 'lockAggregateForSubmitInTx(') !== false
    && strpos($balanceDrafts, 'CashierV3CheckoutSettlementKernel::saveDraft([') !== false
);
balanceRecoveryOk(
    'recovery locks the current member balance and preserves external payment drafts',
    is_string($balanceDrafts)
    && strpos($balanceDrafts, 'lockSnapshotInTx($memberId, $operator, $dataScope)') !== false
    && strpos($balanceDrafts, 'paymentDetailsPreserved') !== false
    && strpos($balanceDrafts, "'eventless' => true") !== false
);
balanceRecoveryOk(
    'recovery lowers an insufficient whole-yuan balance without changing payment lines',
    is_string($balanceDrafts)
    && strpos($balanceDrafts, 'intdiv(max(0, (int)$account[\'totalCents\']), 100) * 100') !== false
    && strpos($balanceDrafts, 'min($plannedAmount, $availableWholeCents, $remainingAfterExternal)') !== false
    && strpos($balanceDrafts, 'self::emptyBalance()') !== false
);
balanceRecoveryOk(
    'return-to-payment-edit is production-wired and uses the strict checkout identity payload',
    is_string($module)
    && strpos($module, "registerCommand('return-to-payment-edit'") !== false
    && strpos($module, 'registerReturnToPaymentEditPolicy($dispatcher)') !== false
    && is_string($normalizer)
    && strpos($normalizer, "'return-to-payment-edit',") !== false
    && strpos($normalizer, 'normalizeCheckoutSubmissionPreparation') !== false
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
