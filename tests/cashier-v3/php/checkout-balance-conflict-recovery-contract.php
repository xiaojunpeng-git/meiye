<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$balanceDraftsPath = $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutBalanceDraftServices.php';
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

balanceRecoveryOk('legacy balance draft service is removed', !is_file($balanceDraftsPath));
balanceRecoveryOk(
    'normalizer no longer exposes return-to-payment-edit',
    strpos($normalizer, "'return-to-payment-edit',") === false
        && strpos($module, "registerCommand('return-to-payment-edit'") === false
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
