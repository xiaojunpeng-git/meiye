<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryStockRequestServices.php');
$query = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryStockRequestQueryServices.php');
$storeController = (string)file_get_contents($root . '/后端代码/app/controller/store/product/inventory/InventoryStockRequest.php');
$platformController = (string)file_get_contents($root . '/后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformHqStockRequest.php');
$storeRoutes = (string)file_get_contents($root . '/后端代码/route/store.php');
$platformRoutes = (string)file_get_contents($root . '/后端代码/route/admin.php');
$migration = (string)file_get_contents($root . '/后端代码/database/upgrades/2026-10-02-库存V3请货受理方/02-正式升级.sql');

$checks = [
    'directory is tenant scoped and maintained outside warehouse tables' => str_contains($migration, 'eb_inventory_stock_request_acceptance_party') && str_contains($migration, 'uk_tenant_party_code'),
    'initial directory values are seeded without creating warehouses' => str_contains($migration, "'HEADQUARTERS','总部'") && str_contains($migration, "'OFFICE','办公室'") && str_contains($migration, "'PROCUREMENT','采购部'"),
    'request document keeps id plus immutable name snapshot' => str_contains($migration, '`acceptance_party_id`') && str_contains($migration, '`acceptance_party_name_snapshot`'),
    'write service validates the active directory only for HQ supplier' => str_contains($service, "if ((string)\$supplier['type'] !== 'HQ') return ['id' => 0, 'name' => ''];") && str_contains($service, "where('status', 1)"),
    'acceptance party participates in request idempotency fingerprint' => str_contains($service, '$supplierId, $acceptancePartyId, $lines'),
    'read model filters acceptance party only inside HQ supplier scope' => str_contains($query, "strtoupper(trim(\$supplyPartyType)) === 'HQ' && \$acceptancePartyId > 0"),
    'store and platform expose authenticated acceptance directories' => str_contains($storeController, 'acceptancePartiesForStore') && str_contains($platformController, 'acceptancePartiesForHeadquarters') && str_contains($storeRoutes, 'v3/request/acceptance-parties') && str_contains($platformRoutes, 'v3/hq/request/acceptance-parties'),
];

$failed = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$ok) $failed++;
}
echo "REQUEST_ACCEPTANCE_PARTY_CONTRACT failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
