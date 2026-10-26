<?php
declare(strict_types=1);

$backend = getenv('INVENTORY_BACKEND_ROOT');
$backend = is_string($backend) && $backend !== ''
    ? rtrim($backend, '/')
    : __DIR__ . '/../../../后端代码';

require_once $backend . '/app/services/product/inventory/completion/InventoryCompletionContractException.php';
require_once $backend . '/app/services/product/inventory/completion/InventoryEntitlementCompletionContract.php';
require_once $backend . '/app/services/product/inventory/completion/InventoryEntitlementCompletionDiscovery.php';

use app\services\product\inventory\completion\InventoryEntitlementCompletionDiscovery as Discovery;

$passed = 0;
$failed = 0;

function inventoryGatewayAssert(string $name, bool $condition): void
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

$snapshot = [
    'contractVersion' => 'inventory-entitlement-completion-provider-v1',
    'lockedInventoryResources' => [[
        'kind' => 'inventory_batch',
        'id' => '10',
        'lockOrder' => 55,
        'lockedVersion' => 4,
        'roles' => ['inventory_batch:99:10'],
    ], [
        'kind' => 'inventory_stock',
        'id' => '10',
        'lockOrder' => 50,
        'lockedVersion' => 2,
        'roles' => ['inventory_stock:10'],
    ], [
        'kind' => 'inventory_stock',
        'id' => '2',
        'lockOrder' => 50,
        'lockedVersion' => 3,
        'roles' => ['inventory_stock:2'],
    ], [
        'kind' => 'inventory_shortage_cursor',
        'id' => '2:7:0',
        'lockOrder' => 56,
        'lockedVersion' => 1,
        'roles' => [
            'inventory_shortage_cursor:line-b:2',
            'inventory_shortage_cursor:line-a:2',
        ],
    ]],
];

$resources = Discovery::gatewayResourcesFromLockedSnapshot($snapshot);
inventoryGatewayAssert(
    'Gateway resources preserve global order and numeric same-kind IDs',
    array_column($resources, 'kind') === [
        'inventory_stock',
        'inventory_stock',
        'inventory_batch',
        'inventory_shortage_cursor',
    ]
        && $resources[0]['id'] === '2'
        && $resources[1]['id'] === '10'
);
inventoryGatewayAssert(
    'Gateway resources are read dependencies with frozen provider contract',
    count(array_filter($resources, static function (array $row): bool {
        return $row['accessMode'] === 'read'
            && $row['providerContractVersion'] === 'inventory-completion-gateway-resource-v1';
    })) === count($resources)
);
inventoryGatewayAssert(
    'Gateway authority fingerprint is stable and role order is canonical',
    preg_match('/^[a-f0-9]{64}$/D', $resources[3]['authorityFingerprint']) === 1
        && $resources[3]['roles'] === [
            'inventory_shortage_cursor:line-a:2',
            'inventory_shortage_cursor:line-b:2',
        ]
        && Discovery::gatewayResourcesFromLockedSnapshot($snapshot) === $resources
);

$discoverySource = (string)file_get_contents(
    $backend . '/app/services/product/inventory/completion/InventoryEntitlementCompletionDiscovery.php'
);
$versionSource = (string)file_get_contents(
    $backend . '/app/services/cashier/v3/checkout/provider/CashierV3InventoryResourceVersionProvider.php'
);
$adapterSource = (string)file_get_contents(
    $backend . '/app/services/cashier/v3/checkout/provider/CashierV3InventoryCompletionGatewayAdapter.php'
);

inventoryGatewayAssert(
    'pre-lock discovery is read-only',
    strpos($discoverySource, '->lock(true)') === false
        && strpos($discoverySource, 'Db::execute(') === false
        && strpos($discoverySource, '->insert(') === false
        && strpos($discoverySource, '->update(') === false
        && strpos($discoverySource, '->delete(') === false
);
inventoryGatewayAssert(
    'missing recipe remains fail closed',
    strpos($discoverySource, 'inventory_recipe_not_ready') !== false
        && strpos($discoverySource, "->where('status', 1)") !== false
        && strpos($discoverySource, 'inventory_recipe_details_empty') !== false
);
inventoryGatewayAssert(
    'discovery covers every inventory Gateway kind and stable cursor',
    strpos($discoverySource, "'inventory_policy'") !== false
        && strpos($discoverySource, "'inventory_recipe'") !== false
        && strpos($discoverySource, "'inventory_stock'") !== false
        && strpos($discoverySource, "'inventory_batch'") !== false
        && strpos($discoverySource, "'inventory_shortage_cursor'") !== false
        && strpos($discoverySource, 'shortageCursorResourceId(') !== false
);
inventoryGatewayAssert(
    'version provider enforces DataScope and delegates advances to inventory facts',
    strpos($versionSource, 'implements CashierV3DataScopedVersionProvider') !== false
        && strpos($versionSource, 'CashierV3EntitlementProviderDataScope::assertStore') !== false
        && strpos($versionSource, 'inventory_version_advance_owned_by_inventory_fact_writer') !== false
        && strpos($versionSource, 'INSERT IGNORE INTO `eb_inventory_shortage_cost_cursor`') !== false
);
inventoryGatewayAssert(
    'adapter separates discover revalidate and transaction-only persistence',
    strpos($adapterSource, 'function discover(') !== false
        && strpos($adapterSource, 'function revalidateAfterGatewayLocks(') !== false
        && strpos($adapterSource, 'function lockSnapshotAfterGatewayLocks(') !== false
        && strpos($adapterSource, 'function persistCompletionInTx(') !== false
        && strpos($adapterSource, '->persistCompletion(') !== false
);

echo "INVENTORY_GATEWAY_ADAPTER_RESULT passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
