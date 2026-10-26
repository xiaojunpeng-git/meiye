<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryManualInboundReadServices.php');
$controller = file_get_contents($root . '/后端代码/app/controller/store/product/inventory/InventoryManualInbound.php');
$routes = file_get_contents($root . '/后端代码/route/store.php');
$adminRoutes = file_get_contents($root . '/后端代码/route/admin.php');
$api = file_get_contents($root . '/前端代码/inventory-vue3/src/services/inventoryApi.js');
$modal = file_get_contents($root . '/前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue');

$failed = 0;
function inboundDetailAssert(string $name, bool $condition): void
{
    global $failed;
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$condition) $failed++;
}

inboundDetailAssert('manual inbound detail is derived only from settled immutable inbound facts',
    $service !== false
    && strpos($service, "Db::name('inventory_batch_movement_fact')") !== false
    && strpos($service, "->where('f.source_type', 'manual_inbound')") !== false
    && strpos($service, "->where('f.fact_status', 'SETTLED')") !== false
    && strpos($service, 'inventory_manual_inbound_detail_missing') !== false);

inboundDetailAssert('manual inbound detail binds facts to the authenticated store default location',
    $service !== false
    && strpos($service, "->where('f.store_id', \$storeId)") !== false
    && strpos($service, "->where('f.location_id', (int)\$location['id'])") !== false
    && strpos($service, "->where('store_id', \$storeId)") !== false
    && strpos($service, "->where('is_default', 1)") !== false
    && strpos($service, 'inventory_manual_inbound_detail_scope_denied') !== false);

inboundDetailAssert('manual inbound detail includes batch snapshots and actual operation time',
    $service !== false
    && strpos($service, "->join('inventory_batch b', 'b.id=f.batch_id')") !== false
    && strpos($service, "->join('inventory_stock s', 's.id=f.stock_id')") !== false
    && strpos($service, 'f.occurred_at') !== false
    && strpos($service, "'operation_at'") !== false);

inboundDetailAssert('manual inbound detail applies the server-side cost visibility policy before projection',
    $controller !== false
    && strpos($controller, 'InventoryStoreAccessPolicy') !== false
    && strpos($controller, "in_array(\n                'inventory.cost.view'") !== false
    && strpos($service, "\$fact['unit_cost_cents'] = null") !== false
    && strpos($service, "\$fact['cost_amount_cents'] = null") !== false);

inboundDetailAssert('store route and inventory client use the protected V3 inbound detail authority',
    $routes !== false
    && strpos($routes, "Route::get('v3/inbound/:id/detail', 'product.inventory.InventoryManualInbound/detail')") !== false
    && strpos($routes, "->pattern(['id' => '[A-Za-z0-9-]+'])") !== false
    && $api !== false
    && strpos($api, 'inboundDetail(id) { return request(`/v3/inbound/${encodeURIComponent(String(id || \'\'))}/detail`) }') !== false);

inboundDetailAssert('platform and store inbound detail routes accept UUID source ids',
    $adminRoutes !== false
    && strpos($adminRoutes, "Route::get('v3/hq/inbound/:id/detail', 'v1.product.inventory.InventoryPlatformHqInbound/detail')->pattern(['id' => '[A-Za-z0-9-]+'])") !== false
    && $routes !== false
    && strpos($routes, "Route::get('v3/inbound/:id/detail', 'product.inventory.InventoryManualInbound/detail')->pattern(['id' => '[A-Za-z0-9-]+'])") !== false);

inboundDetailAssert('detail modal consumes the fact operation time with an audit-time fallback',
    $modal !== false
    && strpos($modal, 'detail.document.operation_at || detail.document.recorded_at') !== false
    && strpos($modal, 'detail?.document') !== false
    && strpos($modal, 'detail.lines?.length') !== false);

echo "INVENTORY_MANUAL_INBOUND_DETAIL_CONTRACT_RESULT failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
