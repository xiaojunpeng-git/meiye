<?php
declare(strict_types=1);

/**
 * Backend contract for checkout line attribution boundaries.
 *
 * Entitlement service rows carry craftsmen and guide rounds, but no salespeople
 * or sales-manager attribution. Checkout preparation is also eventless:
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
    strpos($workspace, '$guides = $this->decodeStoredGuideSelections($line, $lineKey);') !== false,
    'entitlement guide selection must survive the workspace draft'
);
entitlementContractCheck(
    'EAC-02',
    strpos($workspace, 'if ($hasGuides) {') !== false
        && strpos($preparation, "'guideSelections' => is_array(\$line['guideSelections'] ?? null)") !== false,
    'guide settings must enter the entitlement checkout snapshot'
);
entitlementContractCheck(
    'EAC-03',
    strpos($workspace, '$salesManagers = $isSale ? $this->decodeStoredSalesManagerSelections($line, $lineKey) : [];') !== false
        && strpos($workspace, 'if ($isSale && $hasSalesManagers)') !== false,
    'sales-manager attribution must be sale-line-only'
);
entitlementContractCheck(
    'EAC-04',
    strpos($preparation, 'public function prepareInTx(') !== false
        && strpos($preparation, 'CashierV3CheckoutSettlementKernel::saveDraft(') !== false,
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
    strpos($workspace, '$guides = $this->decodeStoredGuideSelections($row, $lineKey);') !== false
        && strpos($workspace, '$salesManagers = $lineRole === self::ROLE_SALE') !== false,
    'entitlement guide must be visible while sales manager remains sale-only'
);

echo sprintf("cashier-entitlement-attribution-contract: passed=%d failed=%d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
