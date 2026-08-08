<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$request = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryStockRequestServices.php');
$transfer = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryCrossSubjectTransferServices.php');
$hqController = (string)file_get_contents($root . '/后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformHqStockRequest.php');
$storeController = (string)file_get_contents($root . '/后端代码/app/controller/store/product/inventory/InventoryStockRequest.php');
$adminRoutes = (string)file_get_contents($root . '/后端代码/route/admin.php');
$storeRoutes = (string)file_get_contents($root . '/后端代码/route/store.php');
$migration = (string)file_get_contents($root . '/后端代码/database/upgrades/2026-08-05-库存V3总部请货方/02-正式升级.sql');
$failed = 0;
function hqRequestAssert(string $name, bool $ok): void { global $failed; echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL; if (!$ok) $failed++; }

hqRequestAssert('HQ request party is derived from a writable HQ location and never client input',
    strpos($request, 'applyForHeadquarters') !== false && strpos($request, 'writableLocation($adminInfo, $hqLocationId)') !== false
    && strpos($request, "'requestPartyType' = 'HQ'") === false && strpos($request, "'requestPartyType'] = 'HQ'") !== false);
hqRequestAssert('request authority persists requester identity snapshots with supplier identity',
    strpos($request, "'request_party_type' => \$scope['requestPartyType']") !== false
    && strpos($request, "'request_party_name_snapshot' => \$scope['requestPartyName']") !== false
    && strpos($request, "'supply_party_type' => \$supplier['type']") !== false);
hqRequestAssert('supplier directories are instance-local active stores and exclude a store requester itself',
    strpos($request, 'suppliersForStore') !== false && strpos($request, 'suppliersForHeadquarters') !== false
    && strpos($request, "where('is_del', 0)->where('is_show', 1)") !== false && strpos($request, "where('id', '<>', (int)\$scope['storeId'])") !== false);
hqRequestAssert('transfer request linkage matches explicit request-party identity including HQ',
    strpos($transfer, "\$requestPartyType !== \$target['partyType']") !== false
    && strpos($transfer, "\$requestPartyId !== (int)\$target['partyId']") !== false
    && strpos($transfer, '$legacyStoreParty') !== false
    && strpos($transfer, 'assertSameTenant') !== false);
hqRequestAssert('platform and store supplier endpoints are registered',
    strpos($hqController, 'InventoryPlatformHqStockRequest') !== false && strpos($storeController, 'function suppliers') !== false
    && strpos($adminRoutes, 'v3/hq/request/suppliers') !== false && strpos($adminRoutes, 'v3/hq/request/apply') !== false
    && strpos($storeRoutes, 'v3/request/suppliers') !== false);
hqRequestAssert('migration keeps historical requests unchanged and adds an indexed HQ-safe query key',
    strpos($migration, 'request_party_type') !== false && strpos($migration, 'UPDATE eb_inventory_stock_request_document') === false
    && strpos($migration, 'idx_request_party_status') !== false && strpos($migration, 'inventory-v3-platform-warehouse-manage') !== false);
echo "HQ_REQUEST_PARTY_CONTRACT_RESULT failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
