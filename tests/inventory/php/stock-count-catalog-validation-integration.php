<?php
declare(strict_types=1);

$backend = getenv('BACKEND_ROOT') ?: '/workspace/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\product\inventory\InventoryStockCountCatalogException;
use app\services\product\inventory\InventoryStockCountServices;
use think\facade\Config;
use think\facade\Db;

/** Boots the isolated inventory fixture database; this test must never target a customer instance. */
$database = getenv('DB_DATABASE') ?: 'inventory_manual_inbound_test_20260730';
if ($database !== 'inventory_manual_inbound_test_20260730') {
    throw new RuntimeException('stock_count_catalog_test_database_denied');
}
$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
foreach ([
    ['database.type', 'mysql'], ['DATABASE_TYPE', 'mysql'],
    ['database.hostname', getenv('DB_HOST') ?: 'mysql'], ['DATABASE_HOSTNAME', getenv('DB_HOST') ?: 'mysql'],
    ['database.hostport', getenv('DB_PORT') ?: '3306'], ['DATABASE_HOSTPORT', getenv('DB_PORT') ?: '3306'],
    ['database.database', $database], ['DATABASE_DATABASE', $database],
    ['cache.driver', 'file'], ['CACHE_DRIVER', 'file'],
] as [$key, $value]) {
    $app->env->set($key, $value);
}
$property = new ReflectionProperty($app, 'envName');
$property->setAccessible(true);
$property->setValue($app, 'inventory_stock_count_catalog_test_skip_dotenv');
$app->initialize();
Config::set(['default' => 'file'], 'cache');

/** Upserts deterministic fixture identities so repeat runs remain isolated and idempotent. */
function catalogFixture(string $table, array $identity, array $row): void
{
    $query = Db::name($table);
    foreach ($identity as $field => $value) {
        $query->where($field, $value);
    }
    $existing = $query->find();
    if ($existing) {
        $query = Db::name($table);
        foreach ($identity as $field => $value) {
            $query->where($field, $value);
        }
        $query->update($row);
        return;
    }
    Db::name($table)->insert($row);
}

catalogFixture('system_store', ['id' => 99190], ['id' => 99190, 'name' => 'TEST-盘点失效目录门店', 'is_del' => 0, 'is_show' => 1]);
catalogFixture('system_store_staff', ['id' => 991900], ['id' => 991900, 'store_id' => 99190, 'status' => 1, 'is_del' => 0]);
catalogFixture('organization', ['id' => 99190], ['id' => 99190, 'pid' => 99000, 'name' => 'TEST-盘点失效目录组织', 'is_del' => 0]);
catalogFixture('organization_store', ['store_id' => 99190], ['store_id' => 99190, 'org_id' => 99190]);
catalogFixture('inventory_location', ['id' => 991900], [
    'id' => 991900, 'tenant_id' => '0', 'organization_id' => '99190', 'organization_path' => '/99000/99190/',
    'organization_name_snapshot' => 'TEST-盘点失效目录组织', 'location_type' => 'STORE', 'owner_id' => 99190,
    'location_code' => 'TEST-DELETED-CATALOG-STORE', 'location_name' => '默认门店仓', 'store_id' => 99190,
    'store_name_snapshot' => 'TEST-盘点失效目录门店', 'is_default' => 1, 'location_status' => 'ACTIVE',
    'version' => 1, 'created_at' => time(), 'updated_at' => time(),
]);
foreach ([991901, 991902] as $offset => $productId) {
    $skuId = $productId * 10 + 1;
    catalogFixture('store_product', ['id' => $productId], [
        'id' => $productId, 'type' => 1, 'relation_id' => 99190, 'is_del' => 1, 'is_inventory' => 1,
        'store_name' => 'TEST-已删除盘点商品' . ($offset + 1), 'code' => 'TEST-DELETED-' . $productId,
        'bar_code' => '', 'salon_stock_enabled' => 0, 'sort' => 1, 'keyword' => 'TEST-已删除盘点商品',
    ]);
    catalogFixture('store_product_attr_value', ['id' => $skuId], [
        'id' => $skuId, 'product_id' => $productId, 'type' => 0, 'unique' => 'deleted-sku-' . $skuId,
        'suk' => '默认', 'bar_code' => '', 'code' => 'TEST-DELETED-SKU-' . $skuId, 'stock_unit' => '件',
    ]);
}

$key = 'TEST-count-deleted-catalog-' . bin2hex(random_bytes(6));
$invalidLines = [];
try {
    (new InventoryStockCountServices())->confirm(99190, 991900, [
        'idempotency_key' => $key,
        'business_date' => '2026-10-01',
        'remark' => 'TEST-失效目录整单回滚',
        'lines' => [
            ['product_id' => 991901, 'sku_id' => 9919011, 'sku_unique' => 'deleted-sku-9919011', 'counted_quantity' => '1', 'surplus_batch_no' => 'TEST-1', 'surplus_unit_cost' => '1', 'surplus_manufactured_date' => '2026-10-01', 'surplus_expire_date' => '2027-10-01'],
            ['product_id' => 991902, 'sku_id' => 9919021, 'sku_unique' => 'deleted-sku-9919021', 'counted_quantity' => '2', 'surplus_batch_no' => 'TEST-2', 'surplus_unit_cost' => '2', 'surplus_manufactured_date' => '2026-10-01', 'surplus_expire_date' => '2027-10-01'],
        ],
    ]);
} catch (InventoryStockCountCatalogException $exception) {
    $invalidLines = $exception->invalidLines();
}

$documentCount = (int)Db::name('inventory_stock_count_document')->where('idempotency_key', $key)->count();
$stockCount = (int)Db::name('inventory_stock')->where('store_id', 99190)->whereIn('consumable_product_id', [991901, 991902])->count();
if (array_column($invalidLines, 'line_index') !== [1, 2] || $documentCount !== 0 || $stockCount !== 0) {
    throw new RuntimeException('stock_count_catalog_validation_integration_failed');
}

echo "STOCK_COUNT_CATALOG_VALIDATION_INTEGRATION_OK\n";
