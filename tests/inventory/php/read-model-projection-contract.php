<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$movement = file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryMovementQueryServices.php');
$count = file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryStockCountQueryServices.php');
$countController = file_get_contents($root . '/后端代码/app/controller/store/product/inventory/InventoryStockCountQuery.php');
$usage = file_get_contents($root . '/后端代码/app/services/product/inventory/InventorySalonUsageServices.php');
$app = file_get_contents($root . '/前端代码/inventory-vue3/src/App.vue');
$storeReadModel = file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryStoreReadModelServices.php');
$storeReadController = file_get_contents($root . '/后端代码/app/controller/store/product/inventory/InventoryStoreReadModel.php');
$platformWarehouse = file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryPlatformWarehouseServices.php');
$storeRoutes = file_get_contents($root . '/后端代码/route/store.php');
$operationalProvider = file_get_contents($root . '/后端代码/app/services/product/inventory/query/InventoryOperationalUnifiedQueryProvider.php');
$requestQuery = file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryStockRequestQueryServices.php');
$crossTransfer = file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryCrossSubjectTransferServices.php');

$failed = 0;
function readModelAssert(string $name, bool $condition): void
{
    global $failed;
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$condition) $failed++;
}

readModelAssert('movement rows project the warehouse that owns each immutable fact',
    $movement !== false
    && strpos($movement, "leftJoin('inventory_location l', 'l.id=f.location_id')") !== false
    && strpos($movement, "'l.location_name', 'l.location_code'") !== false);

readModelAssert('count variance amount is signed from settled count facts instead of current inventory estimates',
    $count !== false
    && strpos($count, "Db::name('inventory_batch_movement_fact')") !== false
    && strpos($count, "whereIn('source_type', ['stock_count_gain', 'stock_count_loss'])") !== false
    && strpos($count, 'CASE WHEN direction = 1 THEN cost_amount_cents ELSE -cost_amount_cents END') !== false
    && strpos($count, "'change_amount_cents'") !== false);

readModelAssert('count variance amount has the same server-side cost permission gate as other store read models',
    $countController !== false
    && strpos($countController, 'InventoryStoreAccessPolicy') !== false
    && strpos($countController, "in_array('inventory.cost.view'") !== false
    && strpos($count, '$canViewCost ? (int)($amountByDocument') !== false
    && strpos($count, ': null') !== false);

readModelAssert('salon usage list returns item count and the effective server date range',
    $usage !== false
    && strpos($usage, "leftJoin('inventory_salon_usage_line l', 'l.document_id=d.id')") !== false
    && strpos($usage, 'COUNT(l.id) detail_count') !== false
    && strpos($usage, "'from' => \$from, 'to' => \$to") !== false);

readModelAssert('inventory page displays the authority fields with correct units and labels',
    $app !== false
    && strpos($app, "'商品摘要'") !== false
    && strpos($app, "'操作时间'") !== false
    && strpos($app, "'耗材项数'") !== false
    && strpos($app, 'centsMoney(row.change_amount_cents)') !== false
    && strpos($app, '业务日期：{{ listDateRange.from }} 至 {{ listDateRange.to }}') !== false);

readModelAssert('operational time falls back to the immutable recorded time when legacy occurred time is zero',
    $operationalProvider !== false
    && substr_count($operationalProvider, 'COALESCE(NULLIF(f.occurred_at, 0), f.recorded_at)') >= 2
    && strpos($movement, "'operation_at'") !== false
    && strpos($count, "'d.recorded_at' => 'operation_at'") !== false
    && strpos($requestQuery, 'd.recorded_at operation_at') !== false
    && strpos($crossTransfer, 'd.recorded_at operation_at') !== false
    && strpos($usage, 'd.recorded_at operation_at') !== false);

readModelAssert('dashboard and product summary are read from settled batch facts rather than UI fixtures',
    $storeReadModel !== false
    && strpos($storeReadModel, 'InventoryBatchStockQueryProvider') !== false
    && strpos($storeReadModel, "'product_id' => \$productId") !== false
    && strpos($storeReadModel, "'inventory_amount_cents'") !== false
    && strpos($app, "const client = mode.value === 'platform' ? platformInventoryApi : inventoryApi") !== false
    && strpos($app, 'dashboard.value = await client.dashboard()') !== false
    && strpos($app, "client.list('productSummary'") !== false);

readModelAssert('inventory home keeps quantity metrics while the server redacts cost metrics without cost permission',
    $storeReadController !== false
    && strpos($storeReadController, 'dashboard((int)$this->storeId, (int)$this->storeStaffId, true)') !== false
    && $platformWarehouse !== false
    && strpos($platformWarehouse, "...(array)(\$access['features'] ?? []),\n            'inventory.cost.view',") === false
    && strpos($platformWarehouse, "'stock_amount_cents' => \$totalCents") !== false
    && strpos($platformWarehouse, "'can_view_cost' => \$canViewCost") !== false
    && strpos($app, '无查看金额权限') !== false
    && strpos($app, 'dashboard.value.stock_amount_cents || 0') === false);

readModelAssert('product detail exposes authoritative batches and movement facts behind an authenticated endpoint',
    $storeReadModel !== false
    && strpos($storeReadModel, "Db::name('inventory_batch_movement_fact')") !== false
    && strpos($storeReadController, 'productDetail') !== false
    && strpos($storeRoutes, "v3/product-summary/:productId/detail") !== false
    && strpos($app, 'await inventoryApi.productDetail(row.product_id)') !== false);

echo "INVENTORY_READ_MODEL_PROJECTION_CONTRACT_RESULT failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
