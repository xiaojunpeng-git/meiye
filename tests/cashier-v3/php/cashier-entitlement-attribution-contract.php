<?php
declare(strict_types=1);

/**
 * Backend contract for checkout line attribution boundaries.
 *
 * Entitlement service rows only carry craftsmen. Guide and sales-manager
 * attribution are sale-line concerns and must never be reintroduced into an
 * entitlement authority snapshot. Checkout preparation is also eventless:
 * formal sales, payments, inventory, service and performance facts belong to
 * the final submit transaction.
 */

$root = dirname(__DIR__, 3);
$backend = $root . '/后端代码/app/services/cashier/v3';
$workspacePath = $backend . '/cashier/CashierV3CashierWorkspaceServices.php';
$preparationPath = $backend . '/settlement/CashierV3CheckoutPreparationServices.php';
$workspace = is_file($workspacePath) ? (string)file_get_contents($workspacePath) : '';
$preparation = is_file($preparationPath) ? (string)file_get_contents($preparationPath) : '';

$passed = 0;
$failed = 0;

function entitlementContractCheck(string $id, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$id}\n";
        return;
    }
    $failed++;
    echo "FAIL {$id}" . ($detail === '' ? '' : ": {$detail}") . "\n";
}

entitlementContractCheck(
    'EAC-01',
    strpos($workspace, '$guides = $isSale ? $this->decodeStoredGuideSelections($line, $lineKey) : [];') !== false,
    'guide attribution must be sale-line-only'
);
entitlementContractCheck(
    'EAC-02',
    strpos($workspace, 'if ($isSale && array_key_exists(\'guideSelections\', $settings))') !== false,
    'guide settings must not be applied to entitlement rows'
);
entitlementContractCheck(
    'EAC-03',
    strpos($workspace, '$salesManagers = $isSale ? $this->decodeStoredSalesManagerSelections($line, $lineKey) : [];') !== false
        && strpos($workspace, 'if ($isSale && $hasSalesManagers)') !== false,
    'sales-manager attribution must be sale-line-only'
);
entitlementContractCheck(
    'EAC-04',
    strpos($preparation, '仅创建可编辑的结账请求') !== false
        && strpos($preparation, 'prepare-checkout-submission 在第三步确认时重读并校验') !== false,
    'prepare checkout must remain eventless'
);
entitlementContractCheck(
    'EAC-05',
    strpos($preparation, 'persistSalesOrderInTx') === false
        && strpos($preparation, 'persistPaymentCollectionInTx') === false
        && strpos($preparation, 'persistEntitlementCompletionInTx') === false
        && strpos($preparation, 'persistInventoryCompletionInTx') === false,
    'preparation must not invoke final settlement writers'
);
entitlementContractCheck(
    'EAC-06',
    strpos($workspace, '$guides = $lineRole === self::ROLE_SALE') !== false
        && strpos($workspace, '$salesManagers = $lineRole === self::ROLE_SALE') !== false,
    'legacy entitlement attribution must be stripped from public drafts'
);

echo sprintf("cashier-entitlement-attribution-contract: passed=%d failed=%d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
