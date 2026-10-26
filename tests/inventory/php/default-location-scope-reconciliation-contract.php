<?php
declare(strict_types=1);

$backend = getenv('INVENTORY_BACKEND_ROOT');
$backend = is_string($backend) && $backend !== ''
    ? rtrim($backend, '/')
    : __DIR__ . '/../../../后端代码';

$source = (string)file_get_contents(
    $backend . '/app/services/product/inventory/InventoryManualInboundServices.php'
);

$passed = 0;
$failed = 0;
function defaultLocationScopeAssert(string $name, bool $condition): void
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

defaultLocationScopeAssert(
    'existing default store locations reconcile organization snapshots before inbound writes',
    strpos($source, 'reconcileDefaultLocationScope((array)$rows[0], $scope, $now') !== false
        && strpos($source, 'private function reconcileDefaultLocationScope') !== false
);
defaultLocationScopeAssert(
    'reconciliation refreshes only current location dimensions and advances the location version',
    strpos($source, "'organization_id' => (string)\$scope['organizationId']") !== false
        && strpos($source, "'organization_path' => (string)\$scope['organizationPath']") !== false
        && strpos($source, "'organization_name_snapshot' => (string)\$scope['organizationName']") !== false
        && strpos($source, "'store_name_snapshot' => (string)\$scope['storeName']") !== false
        && strpos($source, "\$changes['version'] = (int)\$location['version'] + 1") !== false
        && strpos($source, "Db::name('inventory_stock')->update(\$changes)") === false
        && strpos($source, "Db::name('inventory_batch')->update(\$changes)") === false
        && strpos($source, "Db::name('inventory_batch_movement_fact')->update(\$changes)") === false
);
defaultLocationScopeAssert(
    'identity mismatches still fail closed instead of being repaired as organization snapshots',
    strpos($source, 'private function assertDefaultLocationIdentity') !== false
        && strpos($source, "(int)(\$location['owner_id'] ?? 0) !== (int)\$scope['storeId']") !== false
        && strpos($source, "(int)(\$location['store_id'] ?? 0) !== (int)\$scope['storeId']") !== false
        && strpos($source, "(string)(\$location['tenant_id'] ?? '') !== (string)\$scope['tenantId']") !== false
);
defaultLocationScopeAssert(
    'organization moves refresh current stock scope while idempotent facts retain their historical scope snapshots',
    strpos($source, 'private function reconcileStockScope') !== false
        && strpos($source, "'organization_path' => (string)\$location['organization_path']") !== false
        && strpos($source, "(string)\$fact['organization_path'] !== (string)\$location['organization_path']") === false
        && strpos($source, "(string)\$stock['organization_path'] !== (string)\$location['organization_path']") === false
);

echo "INVENTORY_DEFAULT_LOCATION_SCOPE_RECONCILIATION_RESULT passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
