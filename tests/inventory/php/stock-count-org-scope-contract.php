<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/后端代码/app/services/product/inventory/InventoryErrorMessage.php';

use app\services\product\inventory\InventoryErrorMessage;

/** Organization moves repair current warehouse/stock scope, not settled historical facts. */
$count = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryStockCountServices.php');
$organization = (string)file_get_contents($root . '/后端代码/app/services/organization/OrganizationManageServices.php');
$repair = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryStoreScopeReconciliationServices.php');
$locationError = InventoryErrorMessage::from(new RuntimeException('inventory_stock_count_location_invalid'));
$stockError = InventoryErrorMessage::from(new RuntimeException('inventory_store_stock_scope_invalid'));
$passed = strpos($count, '->reconcileLocation($rows[0], $scope, time())') !== false
    && strpos($organization, 'reconcileMovedOrganization($id, $time)') !== false
    && strpos($organization, 'reconcileBoundStore($storeId, $orgId, time())') !== false
    && strpos($repair, "Db::name('inventory_stock')->where('location_id', \$locationId)") !== false
    && strpos($repair, "Db::name('inventory_location')->where('id', \$locationId)") !== false
    && strpos($repair, "Db::name('inventory_batch_movement_fact')->update(") === false
    && strpos($repair, "Db::name('inventory_stock_count_document')->update(") === false
    && $locationError['message'] === '当前门店没有唯一的默认仓库，请联系管理员处理。'
    && $stockError['message'] === '门店库存归属异常，请联系管理员处理。';
echo $passed ? "STOCK_COUNT_ORG_SCOPE_CONTRACT=PASS\n" : "STOCK_COUNT_ORG_SCOPE_CONTRACT=FAIL\n";
exit($passed ? 0 : 1);
