<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$controller = (string)file_get_contents($root . '/后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformWarehouse.php');
$context = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/query/InventoryPlatformUnifiedQueryContextFactory.php');
$statistics = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/query/InventoryStatisticsUnifiedQueryProvider.php');
$contract = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/query/InventoryStatisticsUnifiedQueryContract.php');
$routes = (string)file_get_contents($root . '/后端代码/route/admin.php');
$migration = (string)file_get_contents($root . '/后端代码/database/upgrades/2026-08-05-库存V3总部请货方/02-正式升级.sql');
$failed = 0;
function platformOperationalAssert(string $name, bool $ok): void { global $failed; echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL; if (!$ok) $failed++; }

platformOperationalAssert('platform operational query only accepts registered inventory pages and server scope',
    strpos($controller, 'InventoryStatisticsUnifiedQueryContract::PAGE_CODES') !== false
    && strpos($controller, "'inventory_platform_uq_scope_forbidden'") !== false
    && strpos($controller, '$this->selectedHqLocationId($payload)') !== false);
platformOperationalAssert('selected HQ location must belong to the authenticated platform scope',
    strpos($context, 'in_array($selectedHqLocationId, $hqIds, true)') !== false
    && strpos($context, '所选总部仓不在当前平台库存范围内') !== false);
platformOperationalAssert('platform store narrowing keeps the selected store id in the resolved context',
    strpos($context, "'store_id' => \$subject === 'STORE' ? \$selectedStoreId : 0") !== false);
platformOperationalAssert('movement statistics reuse the authenticated location scope without a store staff identity',
    strpos($statistics, 'listForLocations($locationIds') !== false
    && strpos($statistics, 'InventoryMovementAnalyticsServices())->list($storeId, $operatorId') === false);
platformOperationalAssert('expiry query exposes authoritative batch balance quantity',
    strpos($contract, "field('batch_balance_quantity', '当前剩余库存'") !== false);
platformOperationalAssert('platform route and middleware permission record are both registered',
    strpos($routes, 'v3/unified-query/operational') !== false
    && strpos($migration, 'product/inventory/v3/unified-query/operational') !== false);

echo "PLATFORM_OPERATIONAL_QUERY_CONTRACT_RESULT failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
