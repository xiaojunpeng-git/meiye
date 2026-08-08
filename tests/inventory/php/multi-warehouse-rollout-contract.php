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
rolloutAssert('non-default warehouse creation is closed at controller and service boundaries',
    strpos($source['warehouseService'], 'InventoryV3RolloutPolicy::assertMultiWarehouseEnabled();') !== false
    && strpos($source['warehouseController'], '多仓库建仓本期暂不开放。') !== false);
rolloutAssert('store and platform warehouse selectors return the default warehouse only',
    substr_count($source['storeLocations'], "->where('is_default', 1)") >= 1
    && substr_count($source['platformLocations'], "->where('is_default', 1)") >= 1);
rolloutAssert('the V3 inventory navigation restores cross-store transfer while keeping non-default warehouses deferred',
    strpos($source['app'], "const deferredPageKeys = new Set(['warehouse'])") !== false
    && strpos($source['app'], 'if (deferredPageKeys.has(item.key)) return false') !== false
    && strpos($source['app'], "{ key: 'transfer', label: '调拨管理'") !== false
    && strpos($source['app'], "{ key: 'warehouse', label: '仓库设置'") === false);
rolloutAssert('cross-store transfer writes a draft, a source fact at dispatch, and a target fact only at receipt',
    strpos($source['crossTransferService'], "'document_status' => 'DRAFT'") !== false
    && strpos($source['crossTransferService'], "'cross_transfer_out'") !== false
    && strpos($source['crossTransferService'], "'cross_transfer_in'") !== false
    && strpos($source['crossTransferController'], 'dispatchForStore') !== false
    && strpos($source['crossTransferController'], 'receiveForStore') !== false);

echo "MULTI_WAREHOUSE_ROLLOUT_CONTRACT_RESULT failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
