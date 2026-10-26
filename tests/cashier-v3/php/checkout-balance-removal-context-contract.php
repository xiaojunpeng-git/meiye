<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$modulePath = $root . '/后端代码/app/services/cashier/v3/cashier/CashierV3CashierModule.php';
$discoveryPath = $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutBalanceAuthorityDiscovery.php';
$draftPath = $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutBalanceDraftServices.php';

foreach ([$modulePath, $discoveryPath] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "FAIL missing file: {$path}\n");
        exit(1);
    }
}

if (is_file($draftPath)) {
    fwrite(STDERR, "FAIL legacy balance draft service still exists\n");
    exit(1);
}

$module = (string)file_get_contents($modulePath);
$discovery = (string)file_get_contents($discoveryPath);

$policyStart = strpos($module, 'private static function registerBalanceDraftPolicies');
$policyEnd = strpos($module, 'private static function registerSubmitCheckoutPolicy', $policyStart ?: 0);
$policy = $policyStart === false
    ? ''
    : substr($module, $policyStart, $policyEnd === false ? null : $policyEnd - $policyStart);

checkBalanceRemovalContext(
    'member-balance discovery cannot be invoked for removal',
    strpos($discovery, "ACTION_REMOVE") === false
        && strpos($discovery, "'remove-balance-payment'") === false
);

echo "PASS checkout balance removal context contract\n";

function checkBalanceRemovalContext(string $label, bool $condition): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "FAIL {$label}\n");
    exit(1);
}
