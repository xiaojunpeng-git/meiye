<?php
declare(strict_types=1);

$backend = getenv('CHECKOUT_RESOURCE_PLAN_BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require_once $backend . '/app/services/cashier/v3/settlement/CashierV3CheckoutSettlementContractException.php';
require_once $backend . '/app/services/cashier/v3/settlement/CashierV3CheckoutSettlementCanonicalizer.php';
require_once $backend . '/app/services/cashier/v3/CashierV3ResourceScope.php';
require_once $backend . '/app/services/cashier/v3/CashierV3ResourceKindCatalog.php';
require_once $backend . '/app/services/cashier/v3/settlement/CashierV3CheckoutVerifiedResourcePlan.php';

use app\services\cashier\v3\settlement\CashierV3CheckoutSettlementContractException;
use app\services\cashier\v3\settlement\CashierV3CheckoutVerifiedResourcePlan;

$passed = 0;
$failed = 0;
function resourcePlanOk(string $name, bool $condition, string $detail = ''): void
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
function resourcePlanReason(callable $callback): string
{
    try {
        $callback();
    } catch (CashierV3CheckoutSettlementContractException $exception) {
        return $exception->reason();
    } catch (Throwable $throwable) {
        return 'UNEXPECTED:' . get_class($throwable) . ':' . $throwable->getMessage();
    }
    return '';
}
function resourcePlanRow(
    string $kind,
    string $id,
    int $lockOrder,
    int $version,
    array $roles,
    string $accessMode = 'read',
    string $scopeType = 'tenant',
    string $scopeId = 'merchant-1'
): array {
    return [
        'tenantId' => 'merchant-1',
        'storeId' => 7,
        'kind' => $kind,
        'id' => $id,
        'scopeType' => $scopeType,
        'scopeId' => $scopeId,
        'lockOrder' => $lockOrder,
        'expectedVersion' => $version,
        'roles' => $roles,
        'accessMode' => $accessMode,
        'providerContractVersion' => 'provider-' . $kind . '-v1',
        'authorityFingerprint' => hash('sha256', $kind . '|' . $id . '|' . $version),
    ];
}

$rows = [
    resourcePlanRow('inventory_batch', '10', 55, 8, ['inventory_batch:2:10']),
    resourcePlanRow('member', '93', 10, 4, ['member'], 'read'),
    resourcePlanRow('inventory_batch', '2', 55, 5, ['inventory_batch:2:2'], 'mutate', 'store', '7'),
    resourcePlanRow('member', '93', 10, 4, ['checkout_member'], 'mutate'),
];
$plan = CashierV3CheckoutVerifiedResourcePlan::fromServerVerifiedAuthorityRows(
    'merchant-1', 7, 3, $rows
);
$resources = $plan->resources();
resourcePlanOk('duplicate physical member rows merge roles and strongest access mode',
    $plan->resourceCount() === 3
        && $plan->roleCount() === 4
        && $resources[0]['kind'] === 'member'
        && $resources[0]['roles'] === ['checkout_member', 'member']
        && $resources[0]['accessMode'] === 'mutate');
resourcePlanOk('same-kind positive decimal resource ids use overflow-safe numeric order',
    array_column(array_slice($resources, 1), 'id') === ['2', '10']);
resourcePlanOk('trusted contexts contain only kind id expectedVersion',
    $plan->trustedContexts()[0] === [
        'kind' => 'member', 'id' => '93', 'expectedVersion' => 4,
    ]);
resourcePlanOk('row and plan fingerprints are stable',
    preg_match('/^[a-f0-9]{64}$/D', $resources[0]['rowFingerprint']) === 1
        && preg_match('/^[a-f0-9]{64}$/D', $plan->fingerprint()) === 1
        && $plan->fingerprint() === CashierV3CheckoutVerifiedResourcePlan::fromServerVerifiedAuthorityRows(
            'merchant-1', 7, 3, array_reverse($rows)
        )->fingerprint());

$opaqueRows = [
    resourcePlanRow('inventory_batch', 'A2', 55, 5, ['opaque:A2'], 'mutate', 'store', '7'),
    resourcePlanRow('inventory_batch', 'A10', 55, 5, ['opaque:A10'], 'mutate', 'store', '7'),
];
$opaquePlan = CashierV3CheckoutVerifiedResourcePlan::fromServerVerifiedAuthorityRows(
    'merchant-1', 7, 3, $opaqueRows
);
resourcePlanOk('same-kind opaque resource ids retain byte order',
    array_column($opaquePlan->resources(), 'id') === ['A10', 'A2']);

$globalLockRows = [
    resourcePlanRow('cashier_workspace', '7', 150, 2, ['workspace'], 'mutate', 'store', '7'),
    resourcePlanRow('inventory_batch', '2', 55, 3, ['inventory_batch:2'], 'mutate', 'store', '7'),
    resourcePlanRow('catalog_card_definition', '9', 41, 3, ['catalog_card_definition'], 'read', 'store', '7'),
    resourcePlanRow('inventory_stock', '2', 50, 3, ['inventory_stock:2'], 'mutate', 'store', '7'),
    resourcePlanRow('catalog_sku', '9', 43, 3, ['catalog_sku'], 'read', 'store', '7'),
    resourcePlanRow('inventory_recipe', '9', 47, 3, ['inventory_recipe']),
    resourcePlanRow('catalog_product', '9', 42, 3, ['catalog_product'], 'read', 'store', '7'),
    resourcePlanRow('inventory_policy', '9', 46, 3, ['inventory_policy']),
    resourcePlanRow('inventory_batch', '10', 55, 3, ['inventory_batch:10'], 'mutate', 'store', '7'),
    resourcePlanRow(
        'inventory_shortage_cursor',
        '9',
        56,
        3,
        ['inventory_shortage_cursor'],
        'mutate',
        'store',
        '7'
    ),
];
$globalLockPlan = CashierV3CheckoutVerifiedResourcePlan::fromServerVerifiedAuthorityRows(
    'merchant-1', 7, 3, $globalLockRows
);
$globalLockResources = $globalLockPlan->resources();
resourcePlanOk('catalog inventory and workspace share the global canonical lock sequence',
    array_column($globalLockResources, 'kind') === [
        'catalog_card_definition', 'catalog_product', 'catalog_sku', 'inventory_policy',
        'inventory_recipe', 'inventory_stock', 'inventory_batch', 'inventory_batch',
        'inventory_shortage_cursor', 'cashier_workspace',
    ]
        && array_column(array_slice($globalLockResources, 6, 2), 'id') === ['2', '10']);

$badLockOrder = [resourcePlanRow('member', '93', 11, 4, ['member'])];
resourcePlanOk('authority row with non-catalog lock order fails closed',
    resourcePlanReason(static function () use ($badLockOrder): void {
        CashierV3CheckoutVerifiedResourcePlan::fromServerVerifiedAuthorityRows(
            'merchant-1', 7, 3, $badLockOrder
        );
    }) === 'checkout_resource_plan_lock_order_mismatch');

$inconsistent = $rows;
$inconsistent[3]['expectedVersion'] = 5;
resourcePlanOk('same physical resource cannot carry conflicting versions',
    resourcePlanReason(static function () use ($inconsistent): void {
        CashierV3CheckoutVerifiedResourcePlan::fromServerVerifiedAuthorityRows(
            'merchant-1', 7, 3, $inconsistent
        );
    }) === 'checkout_resource_plan_duplicate_inconsistent');

$duplicateRole = [
    resourcePlanRow('member', '93', 10, 4, ['same-role']),
    resourcePlanRow('member_balance', '93', 20, 7, ['same-role']),
];
resourcePlanOk('one logical role cannot map to two physical resources',
    resourcePlanReason(static function () use ($duplicateRole): void {
        CashierV3CheckoutVerifiedResourcePlan::fromServerVerifiedAuthorityRows(
            'merchant-1', 7, 3, $duplicateRole
        );
    }) === 'checkout_resource_plan_role_duplicate');

$badScope = [resourcePlanRow('inventory_stock', '4', 50, 2, ['stock:4'], 'mutate', 'store', '8')];
resourcePlanOk('canonical tenant and store scope are enforced',
    resourcePlanReason(static function () use ($badScope): void {
        CashierV3CheckoutVerifiedResourcePlan::fromServerVerifiedAuthorityRows(
            'merchant-1', 7, 3, $badScope
        );
    }) === 'checkout_resource_plan_canonical_scope_mismatch');

$badVersion = [resourcePlanRow('member', '93', 10, 4, ['member'])];
$badVersion[0]['expectedVersion'] = 0;
resourcePlanOk('expectedVersion must be positive',
    resourcePlanReason(static function () use ($badVersion): void {
        CashierV3CheckoutVerifiedResourcePlan::fromServerVerifiedAuthorityRows(
            'merchant-1', 7, 3, $badVersion
        );
    }) === 'checkout_resource_plan_positive_int_invalid');

$badAccess = [resourcePlanRow('member', '93', 10, 4, ['member'])];
$badAccess[0]['accessMode'] = 'write_anything';
resourcePlanOk('accessMode is closed to read or mutate',
    resourcePlanReason(static function () use ($badAccess): void {
        CashierV3CheckoutVerifiedResourcePlan::fromServerVerifiedAuthorityRows(
            'merchant-1', 7, 3, $badAccess
        );
    }) === 'checkout_resource_plan_access_mode_invalid');

$badFingerprint = [resourcePlanRow('member', '93', 10, 4, ['member'])];
$badFingerprint[0]['authorityFingerprint'] = str_repeat('g', 64);
resourcePlanOk('provider authority fingerprint must be canonical sha256',
    resourcePlanReason(static function () use ($badFingerprint): void {
        CashierV3CheckoutVerifiedResourcePlan::fromServerVerifiedAuthorityRows(
            'merchant-1', 7, 3, $badFingerprint
        );
    }) === 'checkout_resource_plan_fingerprint_invalid');

$overLimit = [];
for ($index = 1; $index <= CashierV3CheckoutVerifiedResourcePlan::MAX_RESOURCES + 1; $index++) {
    $overLimit[] = resourcePlanRow('inventory_batch', (string)$index, 55, 1, ['batch:' . $index], 'read', 'store', '7');
}
resourcePlanOk('physical input budget is capped at ten thousand',
    resourcePlanReason(static function () use ($overLimit): void {
        CashierV3CheckoutVerifiedResourcePlan::fromServerVerifiedAuthorityRows(
            'merchant-1', 7, 3, $overLimit
        );
    }) === 'checkout_resource_plan_count_invalid');

echo "CHECKOUT_RESOURCE_PLAN_CONTRACT passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
