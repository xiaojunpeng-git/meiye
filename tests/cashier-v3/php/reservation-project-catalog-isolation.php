<?php
/** 预约项目目录不得因整店某张卡的坏关联而无法打开。无数据库写入。 */

$backend = dirname(__DIR__, 3) . '/后端代码';
foreach ([
    'app/services/cashier/v3/CashierV3ResultCode.php',
    'app/services/cashier/v3/CashierV3CommandException.php',
    'app/services/cashier/v3/CashierV3ResourceScope.php',
    'app/services/cashier/v3/CashierV3ResourceKindCatalog.php',
    'app/services/cashier/v3/CashierV3OperatorScope.php',
    'app/services/cashier/v3/CashierV3DataScopeContext.php',
    'app/services/cashier/v3/cashier/CashierV3CashierReadinessGuard.php',
    'app/services/cashier/v3/cashier/CashierV3SaleCatalogAuthority.php',
    'app/services/cashier/v3/cashier/ThinkPhpCashierV3SaleCatalogAuthority.php',
    'app/services/product/product/StoreProductSaleUsagePolicy.php',
    'app/services/cashier/v3/cashier/CashierV3SaleCatalogServices.php',
] as $file) require_once $backend . '/' . $file;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\cashier\CashierV3SaleCatalogAuthority;
use app\services\cashier\v3\cashier\CashierV3SaleCatalogServices;

function reservationProjectRow(int $productId, int $skuId, int $show): array
{
    return [
        'product_id' => $productId, 'product_pid' => 0, 'product_type' => 1,
        'product_relation_id' => 7, 'product_product_type' => 6,
        'product_store_name' => '护理项目', 'product_cate_id' => '10',
        'product_keyword' => '', 'product_unit_name' => '次',
        'product_is_show' => $show, 'product_is_del' => 0, 'product_is_verify' => 1,
        'product_is_inventory' => 0, 'product_allow_negative_stock' => 1,
        'product_card_num' => 0, 'product_card_num_type' => 0,
        'product_card_rule_type' => '', 'product_card_rule_version' => 0,
        'product_card_choice_limit' => 0, 'product_card_shared_times' => 0,
        'sku_id' => $skuId, 'sku_product_id' => $productId,
        'sku_product_type' => 6, 'sku_unique' => 'SKU-' . $skuId,
        'sku_suk' => '默认', 'sku_price' => '128.50', 'sku_ot_price' => '128.50',
        'sku_stock' => '0.0000', 'sku_code' => '', 'sku_bar_code' => '',
        'sku_is_show' => 1, 'sku_type' => 0, 'sku_write_times' => 0,
        'sku_write_valid' => 0, 'sku_write_days' => 0,
        'sku_write_start' => 0, 'sku_write_end' => 0,
        'category_names' => ['护理'],
    ];
}

final class BrokenCardReservationAuthority implements CashierV3SaleCatalogAuthority
{
    public function listStoreItems(int $storeId): array
    {
        throw new RuntimeException('整店销售目录含坏卡关联，预约不得读取');
    }

    public function listReservationProjects(int $storeId): array
    {
        return $storeId === 7
            ? [reservationProjectRow(101, 1001, 1), reservationProjectRow(102, 1002, 0)]
            : [];
    }

    public function listCustomCardProjects(int $storeId): array { return []; }
    public function lockStoreItemBySkuId(int $storeId, int $skuId) { return null; }
    public function readStoreItemBySkuId(int $storeId, int $skuId) { return null; }
    public function readStoreItemAvailabilityBySkuId(int $storeId, int $skuId) { return null; }
    public function lockStoreResourceRow(int $storeId, string $kind, int $resourceId): bool { return false; }
}

$scope = new CashierV3OperatorScope(7, 99, 'org-1', 'tenant-1');
$dataScope = new CashierV3DataScopeContext(
    99, 88, 7, 'tenant-1', 'org-1', [7], CashierV3DataScopeContext::MODE_STORES,
    [], false, '', 'permission-v1', ['cashier.v3.cashier'], ['name' => 'tester']
);
$service = new CashierV3SaleCatalogServices(new BrokenCardReservationAuthority());
$items = $service->reservationProjects($scope, $dataScope);
if (count($items) !== 1 || (int)$items[0]['productId'] !== 101
    || (int)$items[0]['skuId'] !== 1001 || !empty($items[0]['disabled'])) {
    throw new RuntimeException('预约目录未隔离坏卡关联或仓库项目');
}

$noScope = new CashierV3DataScopeContext(
    99, 88, 7, 'tenant-1', 'org-1', [], CashierV3DataScopeContext::MODE_NONE,
    [], false, '', 'permission-v1', [], ['name' => 'tester']
);
try {
    $service->reservationProjects($scope, $noScope);
    throw new RuntimeException('无门店数据权限仍可读取预约项目');
} catch (CashierV3CommandException $e) {
    if ($e->getResultCode() !== 'PERMISSION_DENIED') throw $e;
}

$module = file_get_contents($backend . '/app/services/cashier/v3/reservation/CashierV3ReservationModule.php');
if (strpos($module, '$catalog->reservationProjects($operator, $dataScope)') === false) {
    throw new RuntimeException('预约编辑器未调用独立项目目录');
}

echo "RESERVATION_PROJECT_CATALOG_ISOLATION=PASS\n";
