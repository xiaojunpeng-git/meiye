<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$files = [
    'policy' => $root . '/后端代码/app/services/product/inventory/InventoryV3RolloutPolicy.php',
    'transferService' => $root . '/后端代码/app/services/product/inventory/InventoryBatchTransferServices.php',
    'transferController' => $root . '/后端代码/app/controller/store/product/inventory/InventoryBatchTransfer.php',
    'crossTransferService' => $root . '/后端代码/app/services/product/inventory/InventoryCrossSubjectTransferServices.php',
    'crossTransferController' => $root . '/后端代码/app/controller/store/product/inventory/InventoryCrossSubjectTransfer.php',
    'warehouseService' => $root . '/后端代码/app/services/product/inventory/InventoryPlatformWarehouseCommandServices.php',
    'warehouseController' => $root . '/后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformWarehouse.php',
    'storeLocations' => $root . '/后端代码/app/services/product/inventory/InventoryStoreWarehouseServices.php',
    'platformLocations' => $root . '/后端代码/app/services/product/inventory/InventoryPlatformWarehouseServices.php',
    'app' => $root . '/前端代码/inventory-vue3/src/App.vue',
];
$source = array_map(static fn(string $path): string => (string)file_get_contents($path), $files);
$failed = 0;

function rolloutAssert(string $name, bool $condition): void
{
    global $failed;
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . "\n";
    if (!$condition) $failed++;
}

rolloutAssert('the rollout policy keeps multi-warehouse disabled with a stable error code',
    strpos($source['policy'], 'MULTI_WAREHOUSE_ENABLED = false') !== false
    && strpos($source['policy'], "MULTI_WAREHOUSE_DEFERRED_CODE = 'inventory_multi_warehouse_deferred'") !== false);
rolloutAssert('transfer reads and writes are closed at the service boundary',
    substr_count($source['transferService'], 'InventoryV3RolloutPolicy::assertMultiWarehouseEnabled();') >= 2);
rolloutAssert('store transfer endpoints return an explicit Chinese rollout response',
    substr_count($source['transferController'], '多仓库调拨本期暂不开放。') === 2
    && strpos($source['transferController'], 'InventoryV3RolloutPolicy::MULTI_WAREHOUSE_DEFERRED_CODE') !== false);
rolloutAssert('HQ warehouse creation has its own settings permission and never creates store warehouses',
    strpos($source['warehouseService'], "'inventory.location.settings'") !== false
    && strpos($source['warehouseService'], "'location_type' => 'HQ'") !== false
    && strpos($source['warehouseService'], "'location_type' => 'STORE'") === false);
rolloutAssert('store selector still returns defaults while the platform opens all HQ locations',
    strpos($source['storeLocations'], "->where('is_default', 1)") !== false
    && strpos($source['platformLocations'], "\$types->where('location_type', 'HQ')") !== false
    && strpos($source['platformLocations'], "\$store->where('location_type', 'STORE')->where('is_default', 1)") !== false);
// The navigation entry was removed in R14; keep the existing settings page for authorized direct access.
rolloutAssert('warehouse settings are absent from navigation while the settings page remains',
    strpos($source['app'], "{ key: 'warehouse', label: '仓库设置'") === false
    && strpos($source['app'], "warehouse: {") !== false
    && strpos($source['app'], "const deferredPageKeys = new Set(['warehouse'])") === false);
rolloutAssert('cross-store transfer writes a draft, a source fact at dispatch, and a target fact only at receipt',
    strpos($source['crossTransferService'], "'document_status' => 'DRAFT'") !== false
    && strpos($source['crossTransferService'], "'cross_transfer_out'") !== false
    && strpos($source['crossTransferService'], "'cross_transfer_in'") !== false
    && strpos($source['crossTransferController'], 'dispatchForStore') !== false
    && strpos($source['crossTransferController'], 'receiveForStore') !== false);

echo "MULTI_WAREHOUSE_ROLLOUT_CONTRACT_RESULT failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
