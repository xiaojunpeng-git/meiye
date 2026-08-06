<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$modulePath = $root . '/后端代码/app/services/cashier/v3/cashier/CashierV3CashierModule.php';
$discoveryPath = $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutBalanceAuthorityDiscovery.php';
$draftPath = $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutBalanceDraftServices.php';

foreach ([$modulePath, $discoveryPath, $draftPath] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "FAIL missing file: {$path}\n");
        exit(1);
    }
}

$module = (string)file_get_contents($modulePath);
$discovery = (string)file_get_contents($discoveryPath);
$draft = (string)file_get_contents($draftPath);

$policyStart = strpos($module, 'private static function registerBalanceDraftPolicies');
$policyEnd = strpos($module, 'private static function registerSubmitCheckoutPolicy', $policyStart ?: 0);
$policy = $policyStart === false
    ? ''
    : substr($module, $policyStart, $policyEnd === false ? null : $policyEnd - $policyStart);

checkBalanceRemovalContext(
    'balance draft commands still require workspace and checkout request versions',
    strpos($policy, "['cashier_workspace', 'checkout_request']") !== false
);
checkBalanceRemovalContext(
    'remove balance draft skips member-balance server discovery',
    strpos($policy, "if (\$action !== 'remove-balance-payment')") !== false
        && strpos($policy, "['checkout_member_balance']") !== false
        && strpos($policy, "['member_balance']") !== false
);
checkBalanceRemovalContext(
    'member-balance discovery cannot be invoked for removal',
    strpos($discovery, "ACTION_REMOVE") === false
        && strpos($discovery, "'remove-balance-payment'") === false
);
checkBalanceRemovalContext(
    'remove balance draft remains an eventless checkout-request edit',
    strpos($draft, "private const ACTION_REMOVE = 'remove-balance-payment';") !== false
        && strpos($draft, "\$snapshot['balanceDeduction'] = self::emptyBalance();") !== false
        && strpos($draft, 'persistKernelPlanInTx') !== false
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
