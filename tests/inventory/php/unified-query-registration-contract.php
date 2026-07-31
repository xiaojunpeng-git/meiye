<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
require $root . '/后端代码/vendor/autoload.php';

use app\services\product\inventory\query\InventoryBatchStockQueryContract;
use app\services\product\inventory\query\InventoryBatchStockUnifiedQueryRegistrar;
use app\services\query\UnifiedQueryPageRegistry;

$failed = 0;
function uqInventoryAssert(string $name, bool $condition): void
{
    global $failed;
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . "\n";
    if (!$condition) $failed++;
}

$registry = UnifiedQueryPageRegistry::fromRegistrars([], [new InventoryBatchStockUnifiedQueryRegistrar()]);
$page = $registry->page(InventoryBatchStockQueryContract::PAGE_CODE);
$fields = $page['fields'];

uqInventoryAssert('inventory batch UQ page code and stable row key are registered once',
    $registry->isFrozen()
    && $page['pageCode'] === 'inventory_batch_stock'
    && $page['stableRowKey'] === 'batch_balance_id');
uqInventoryAssert('inventory UQ fields reuse the authoritative batch projection contract',
    isset($fields['product_name'], $fields['batch_balance_quantity'], $fields['expire_date'], $fields['received_date'])
    && $fields['batch_unit_cost']['permission'] === InventoryBatchStockQueryContract::PERMISSION_COST);
uqInventoryAssert('inventory UQ keyword and feature contracts remain inventory-owned',
    $page['keywordFields'] === ['product_name', 'sku_name', 'product_code', 'barcode', 'batch_no']
    && $page['requiredFeature'] === InventoryBatchStockQueryContract::PERMISSION_VIEW
    && $page['exportFeature'] === InventoryBatchStockQueryContract::PERMISSION_EXPORT);
uqInventoryAssert('inventory UQ requires a server-calculated warehouse scope dimension',
    $page['scopeDimensions'] === ['location_id']);

$config = file_get_contents($root . '/后端代码/config/unified_query.php');
uqInventoryAssert('runtime config registers inventory registrar provider and worker resolver together',
    $config !== false
    && strpos($config, 'InventoryBatchStockUnifiedQueryRegistrar::class') !== false
    && strpos($config, 'InventoryBatchStockUnifiedQueryProvider::class') !== false
    && strpos($config, 'InventoryBatchStockUnifiedQueryWorkerContextResolver::class') !== false);

$providerSource = file_get_contents($root . '/后端代码/app/services/product/inventory/query/InventoryBatchStockUnifiedQueryProvider.php');
$contextSource = file_get_contents($root . '/后端代码/app/services/product/inventory/query/InventoryStoreUnifiedQueryContextFactory.php');
uqInventoryAssert('provider injects DataScope before UQ calculation and never accepts client locations',
    $providerSource !== false
    && strpos($providerSource, 'sourceRows([') !== false
    && strpos($providerSource, 'dataScope($context)') !== false
    && strpos($providerSource, 'InventoryBatchStockDataScope') !== false);
uqInventoryAssert('store context calculates location scope from the authenticated store',
    $contextSource !== false
    && strpos($contextSource, "where('store_id', \$storeId)") !== false
    && strpos($contextSource, "'scope_dimensions' => ['location_id' => array_keys(\$locationIds)]") !== false);
uqInventoryAssert('store unified query derives cost visibility from the dedicated role capability while retaining non-sensitive controls',
    $contextSource !== false
    && strpos($contextSource, 'InventoryStoreAccessPolicy') !== false
    && strpos($contextSource, '(new InventoryStoreAccessPolicy())->features($storeId, $operatorId)') !== false
    && strpos($contextSource, "'manage_shared_fields' => true") !== false
    && strpos($contextSource, "'share_tenant_fields' => true") !== false);

$workerSource = file_get_contents($root . '/后端代码/app/services/product/inventory/query/InventoryBatchStockUnifiedQueryWorkerContextResolver.php');
uqInventoryAssert('inventory export worker rebuilds server scope instead of role-based fail-closed rejection',
    $workerSource !== false
    && strpos($workerSource, 'InventoryStoreUnifiedQueryContextFactory') !== false
    && strpos($workerSource, 'InventoryPlatformUnifiedQueryContextFactory') !== false
    && strpos($workerSource, "'origin_store_id'") !== false
    && strpos($workerSource, 'UNIFIED_QUERY_EXPORT_PERMISSION_REVOKED') !== false);

$controllerSource = file_get_contents($root . '/后端代码/app/controller/store/product/inventory/InventoryUnifiedQuery.php');
$commandSource = file_get_contents($root . '/后端代码/app/services/product/inventory/query/InventoryUnifiedQueryCommandServices.php');
$routeSource = file_get_contents($root . '/后端代码/route/store.php');
uqInventoryAssert('inventory UQ exposes capability, command and export-task endpoints without accepting client scope',
    $controllerSource !== false
    && strpos($controllerSource, 'function capabilities()') !== false
    && strpos($controllerSource, 'function command(') !== false
    && strpos($controllerSource, 'function exportTask(') !== false
    && $routeSource !== false
    && strpos($routeSource, "v3/unified-query/capabilities") !== false
    && strpos($routeSource, "v3/unified-query/commands") !== false);
uqInventoryAssert('store UQ strips only the canonical store id marked as server-injected by the session middleware',
    strpos($controllerSource, 'forceStoreSessionInjectedGetStoreId') !== false
    && strpos($controllerSource, 'forceStoreSessionInjectedPostStoreId') !== false
    && strpos($controllerSource, "unset(\$raw['store_id'])") !== false
    && strpos($controllerSource, "unset(\$input['store_id'])") !== false);
uqInventoryAssert('inventory UQ commands persist idempotent receipts and rederive query preference versions server-side',
    $commandSource !== false
    && strpos($commandSource, "inventory_unified_query_command_receipt") !== false
    && strpos($commandSource, "query_preference_version") !== false
    && strpos($commandSource, "INVENTORY_UNIFIED_QUERY_IDEMPOTENCY_CONFLICT") !== false
    && strpos($commandSource, "scope_dimensions") !== false);

$platformContextSource = file_get_contents($root . '/后端代码/app/services/product/inventory/query/InventoryPlatformUnifiedQueryContextFactory.php');
$platformControllerSource = file_get_contents($root . '/后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformWarehouse.php');
$adminRouteSource = file_get_contents($root . '/后端代码/route/admin.php');
uqInventoryAssert('platform UQ derives active warehouses server-side and accepts only a verified narrowing selection',
    $platformContextSource !== false
    && strpos($platformContextSource, 'selectedLocationId') !== false
    && strpos($platformContextSource, "'scope_dimensions' => ['location_id' => array_keys(\$locationIds)]") !== false
    && $platformControllerSource !== false
    && strpos($platformControllerSource, 'selectedLocationId') !== false
    && strpos($platformControllerSource, 'unifiedExportTask') !== false
    && $adminRouteSource !== false
    && strpos($adminRouteSource, 'v3/unified-query/export-task/:taskNo') !== false);

$exportAuthMigration = file_get_contents($root . '/后端代码/database/upgrades/2026-07-31-库存V3平台导出动态路由权限修复/02-正式升级.sql');
uqInventoryAssert('platform export polling permission matches the resolved ThinkPHP route while route declaration keeps its placeholder syntax',
    $exportAuthMigration !== false
    && strpos($exportAuthMigration, 'product/inventory/v3/unified-query/export-task/<taskNo>') !== false
    && strpos($exportAuthMigration, "api_url = 'product/inventory/v3/unified-query/export-task/<taskNo>'") !== false);

echo "INVENTORY_UNIFIED_QUERY_REGISTRATION_RESULT failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
