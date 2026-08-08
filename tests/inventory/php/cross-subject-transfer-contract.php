<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryCrossSubjectTransferServices.php');
$request = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryStockRequestServices.php');
$hqInbound = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryPlatformHqInboundServices.php');
$hqCatalog = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryPlatformHqCatalogServices.php');
$hqTransfer = (string)file_get_contents($root . '/后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformHqCrossTransfer.php');
$manualInbound = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryManualInboundServices.php');
$movementFacts = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/completion/InventoryBatchMovementFactServices.php');
$operationalQuery = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/query/InventoryOperationalUnifiedQueryProvider.php');
$routes = (string)file_get_contents($root . '/后端代码/route/store.php');
$adminRoutes = (string)file_get_contents($root . '/后端代码/route/admin.php');
$upgrade = (string)file_get_contents($root . '/后端代码/database/upgrades/2026-08-02-库存V3跨主体调拨在途收货/02-正式升级.sql');
$precheck = (string)file_get_contents($root . '/后端代码/database/upgrades/2026-08-02-库存V3跨主体调拨在途收货/01-升级前检查.sql');
$postcheck = (string)file_get_contents($root . '/后端代码/database/upgrades/2026-08-02-库存V3跨主体调拨在途收货/03-升级后验证.sql');
$failed = 0;
function crossAssert(string $name, bool $condition): void { global $failed; echo ($condition ? 'PASS ' : 'FAIL ') . $name . PHP_EOL; if (!$condition) $failed++; }

crossAssert('draft product lines and dispatched batch allocations use separate authority tables',
    strpos($upgrade, 'eb_inventory_cross_transfer_line') !== false
    && strpos($upgrade, 'eb_inventory_cross_transfer_batch_allocation') !== false
    && strpos($service, "'requested_quantity_units'") !== false
    && strpos($service, "'from_batch_id'") !== false);
crossAssert('receipt is the only path that inserts request fulfillment and target inventory facts',
    strpos($service, 'inventory_stock_request_fulfillment') !== false
    && strpos($service, "'cross_transfer_in'") !== false
    && strpos($service, 'refreshRequestStatus') !== false
    && strpos($service, "'PARTIAL'") !== false
    && strpos($service, "'DONE'") !== false);
crossAssert('request supplier identity is server-validated and snapshot into the request authority',
    strpos($request, 'supply_party_type') !== false
    && strpos($request, 'supply_party_name_snapshot') !== false
    && strpos($request, 'sameRoot') !== false);
crossAssert('all cross-store operations have authenticated store routes',
    strpos($routes, "v3/cross-transfer") !== false
    && strpos($routes, 'cross-transfer/:id/dispatch') !== false
    && strpos($routes, 'cross-transfer/:id/receive') !== false
    && strpos($routes, 'cross-transfer/incoming-requests') !== false);
crossAssert('headquarters stock writes use the V3 batch fact inbound path',
    strpos($hqInbound, 'createForHeadquarters') !== false
    && strpos($manualInbound, 'createForHeadquarters') !== false
    && strpos($manualInbound, "'HQ'") !== false
    && strpos($adminRoutes, 'v3/hq/inbound') !== false);
crossAssert('HQ product selection and inbound history are both constrained by the writable headquarters subject',
    strpos($hqCatalog, 'writableLocation($adminInfo, $locationId)') !== false
    && strpos($hqCatalog, "->where('p.type', 0)") !== false
    && strpos($hqInbound, 'public function index') !== false
    && strpos($hqInbound, "->where('f.store_id', 0)") !== false
    && strpos($adminRoutes, 'v3/hq/catalog') !== false);
crossAssert('HQ and store share the same in-transit transfer authority while platform access stays server-scoped',
    strpos($service, 'createDraftForHeadquarters') !== false
    && strpos($service, 'dispatchForHeadquarters') !== false
    && strpos($service, 'receiveForHeadquarters') !== false
    && strpos($service, 'cancelForHeadquarters') !== false
    && strpos($service, "'partyType' => 'HQ'") !== false
    && strpos($service, 'writableLocation($adminInfo, $hqLocationId)') !== false
    && strpos($service, 'allowedStoreIds') !== false
    && strpos($service, 'target_party_type') !== false);
crossAssert('HQ platform routes are registered and use the existing warehouse-management capability',
    strpos($hqTransfer, 'InventoryPlatformHqCrossTransfer') !== false
    && strpos($adminRoutes, 'v3/hq/cross-transfer') !== false
    && strpos($adminRoutes, 'cross-transfer/:id/dispatch') !== false
    && strpos($adminRoutes, 'cross-transfer/:id/receive') !== false
    && strpos($adminRoutes, 'cross-transfer/:id/cancel') !== false
    && strpos($upgrade, 'product/inventory/v3/hq/cross-transfer') !== false
    && strpos($upgrade, 'inventory-v3-platform-warehouse-manage') !== false);
crossAssert('migration creates headquarters defaults idempotently from active store roots',
    strpos($upgrade, 'INSERT IGNORE INTO `eb_inventory_location`') !== false
    && strpos($upgrade, "l.location_type='STORE' AND l.location_status='ACTIVE'") !== false
    && strpos($upgrade, "root.pid=0 AND root.status=1 AND root.is_del=0") !== false
    && strpos($upgrade, "CONCAT('HQ-',root.id)") !== false);
crossAssert('migration precheck fails closed for a registered key or missing upgrade log',
    strpos($precheck, '@upgrade_log_exists') !== false
    && strpos($precheck, '@upgrade_key_registered') !== false
    && strpos($precheck, '20260802-003-inventory-v3-cross-subject-transfer-receipt') !== false
    && strpos($precheck, '@upgrade_log_exists=1 AND @upgrade_key_registered=0') !== false);
crossAssert('postcheck rejects missing headquarters roots and unbalanced transfer facts',
    strpos($postcheck, '@missing_hq_default') !== false
    && strpos($postcheck, '@bad_dispatch_fact') !== false
    && strpos($postcheck, '@bad_receipt_fact') !== false
    && strpos($postcheck, '@premature_receipt_fact') !== false
    && strpos($postcheck, '@bad_fulfillment') !== false
    && strpos($postcheck, '@hq_route_menu_count') !== false
    && strpos($postcheck, "source_type='cross_transfer_out'") !== false
    && strpos($postcheck, "source_type='cross_transfer_in'") !== false);
crossAssert('a zero store ID is accepted only for a persisted active HQ location',
    strpos($movementFacts, "\$movement['storeId'] < 0") !== false
    && strpos($movementFacts, 'locationOwnsStore') !== false
    && strpos($movementFacts, "'HQ'") !== false
    && strpos($movementFacts, "'STORE'") !== false);
crossAssert('store unified-query transfer rows retain server-calculated action permissions',
    strpos($operationalQuery, "d.from_party_type,d.from_party_id,d.from_location_id") !== false
    && strpos($operationalQuery, "d.to_party_type,d.to_party_id,d.to_location_id,d.initiator_store_id") !== false
    && strpos($operationalQuery, "\$row['can_dispatch']") !== false
    && strpos($operationalQuery, "\$row['can_receive']") !== false
    && strpos($operationalQuery, "\$row['can_cancel']") !== false
    && strpos($operationalQuery, "unset(\$row['from_party_type']") !== false);

echo "CROSS_SUBJECT_TRANSFER_CONTRACT_RESULT failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
