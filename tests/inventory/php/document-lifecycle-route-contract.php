<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$storeRoutes = (string)file_get_contents($root . '/后端代码/route/store.php');
$adminRoutes = (string)file_get_contents($root . '/后端代码/route/admin.php');
$storeInbound = (string)file_get_contents($root . '/后端代码/app/controller/store/product/inventory/InventoryManualInbound.php');
$storeOutbound = (string)file_get_contents($root . '/后端代码/app/controller/store/product/inventory/InventoryManualOutbound.php');
$hqInbound = (string)file_get_contents($root . '/后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformHqInbound.php');
$hqOutbound = (string)file_get_contents($root . '/后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformHqOutbound.php');
$failed = 0;
function routeCheck(string $name, bool $condition): void { global $failed; echo ($condition ? 'PASS ' : 'FAIL ') . $name . PHP_EOL; if (!$condition) $failed++; }

routeCheck('store routes expose manual void request termination and transfer reversal',
    strpos($storeRoutes, "v3/inbound/:id/void") !== false
    && strpos($storeRoutes, "v3/outbound/:id/void") !== false
    && strpos($storeRoutes, "v3/request/terminate/:id") !== false
    && strpos($storeRoutes, "v3/cross-transfer/:id/reverse") !== false);
routeCheck('platform routes expose the same lifecycle under the HQ boundary',
    strpos($adminRoutes, "v3/hq/inbound/:id/void") !== false
    && strpos($adminRoutes, "v3/hq/outbound/:id/void") !== false
    && strpos($adminRoutes, "v3/hq/request/:id/terminate") !== false
    && strpos($adminRoutes, "v3/hq/cross-transfer/:id/reverse") !== false);
routeCheck('manual void controllers inject the authenticated scope and exact source id',
    strpos($storeInbound, "'source_id' => \$id") !== false
    && strpos($storeInbound, 'reverseForStore') !== false
    && strpos($storeOutbound, "'source_id' => \$id") !== false
    && strpos($storeOutbound, 'reverseForStore') !== false);
routeCheck('platform manual void strips client scope from the command payload',
    strpos($hqInbound, "'source_id' => \$id") !== false
    && strpos($hqInbound, 'hq_location_id') !== false
    && strpos($hqOutbound, "'source_id' => \$id") !== false
    && strpos($hqOutbound, 'hq_location_id') !== false);

echo "INVENTORY_DOCUMENT_LIFECYCLE_ROUTE_RESULT failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
