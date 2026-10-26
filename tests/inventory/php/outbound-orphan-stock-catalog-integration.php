<?php
declare(strict_types=1);

$backend = getenv('BACKEND_ROOT') ?: '/var/www/html';
require $backend . '/vendor/autoload.php';

use app\services\product\inventory\InventoryStoreCatalogServices;
use think\facade\Config;
use think\facade\Db;

// 只在白名单隔离库测试同名新目录与历史库存；所有夹具在事务结束时回滚。
$database = getenv('DB_DATABASE') ?: 'inventory_manual_inbound_test_20260730';
if ($database !== 'inventory_manual_inbound_test_20260730') {
    throw new RuntimeException('outbound_catalog_test_database_denied');
}
$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
foreach ([
    ['database.type', 'mysql'], ['DATABASE_TYPE', 'mysql'],
    ['database.hostname', getenv('DB_HOST') ?: 'mysql'], ['DATABASE_HOSTNAME', getenv('DB_HOST') ?: 'mysql'],
    ['database.hostport', getenv('DB_PORT') ?: '3306'], ['DATABASE_HOSTPORT', getenv('DB_PORT') ?: '3306'],
    ['database.database', $database], ['DATABASE_DATABASE', $database],
    ['cache.driver', 'file'], ['CACHE_DRIVER', 'file'],
] as [$key, $value]) $app->env->set($key, $value);
$envName = new ReflectionProperty($app, 'envName');
$envName->setAccessible(true);
$envName->setValue($app, 'outbound_orphan_catalog_test_skip_dotenv');
$app->initialize();
Config::set(['default' => 'file'], 'cache');
if ((string)Config::get('database.connections.mysql.database') !== $database) {
    throw new RuntimeException('outbound_catalog_test_database_denied');
}

$storeId = 992960;
$locationId = 9929600;
$currentProductId = 9929601;
$orphanProductId = 9929602;
$orphanSkuId = 99296021;
$stockId = 9929600;
$batchId = 9929600;
$catalog = new InventoryStoreCatalogServices();
Db::startTrans();
try {
    Db::name('inventory_location')->insert([
        'id' => $locationId, 'tenant_id' => '0', 'organization_id' => '992960',
        'organization_path' => '/992960/', 'organization_name_snapshot' => 'TEST-库存组织',
        'location_type' => 'STORE', 'owner_id' => $storeId, 'location_code' => 'TEST-OUTBOUND-ORPHAN',
        'location_name' => '默认门店仓', 'store_id' => $storeId, 'store_name_snapshot' => 'TEST-出库门店',
        'is_default' => 1, 'location_status' => 'ACTIVE', 'version' => 1,
        'created_at' => time(), 'updated_at' => time(),
    ]);
    Db::name('store_product')->insert([
        'id' => $currentProductId, 'type' => 1, 'product_type' => 0, 'relation_id' => $storeId,
        'is_del' => 0, 'is_inventory' => 1, 'is_show' => 0, 'store_name' => 'TEST-魅力青春霜套盒',
        'code' => '', 'bar_code' => '', 'salon_stock_enabled' => 0, 'sort' => 1,
        'keyword' => 'TEST-魅力青春霜套盒',
    ]);
    Db::name('store_product_attr_value')->insert([
        'id' => 99296011, 'product_id' => $currentProductId, 'type' => 0,
        'unique' => 'test-current-empty', 'suk' => '默认', 'bar_code' => '', 'code' => '', 'stock_unit' => '件',
    ]);
    // 历史入库主体已经不在现行商品目录；不能把其“套”库存算到同名“件”SKU 上。
    Db::name('inventory_stock')->insert([
        'id' => $stockId, 'tenant_id' => '0', 'organization_id' => '992960',
        'organization_path' => '/992960/', 'location_id' => $locationId, 'store_id' => $storeId,
        'consumable_product_id' => $orphanProductId, 'sku_id' => $orphanSkuId,
        'product_unique' => 'test-historic-stock', 'stock_status' => 'GOOD', 'stock_unit' => '套',
        'quantity_scale' => 0, 'available_quantity_units' => 1, 'estimated_unit_cost_cents' => 0,
        'version' => 1, 'created_at' => time(), 'updated_at' => time(),
    ]);
    Db::name('inventory_batch')->insert([
        'id' => $batchId, 'stock_id' => $stockId, 'batch_no' => 'TEST-OUTBOUND-ORPHAN',
        'product_name_snapshot' => 'TEST-魅力青春霜套盒', 'sku_name_snapshot' => '默认',
        'product_code_snapshot' => '', 'barcode_snapshot' => '', 'available_quantity_units' => 1,
        'batch_status' => 'ACTIVE', 'unit_cost_cents' => 0, 'version' => 1,
        'created_at' => time(), 'updated_at' => time(),
    ]);

    $ordinary = $catalog->search($storeId, 'TEST-魅力青春霜套盒');
    if ($ordinary['total'] !== 1 || (int)$ordinary['list'][0]['product_id'] !== $currentProductId
        || $ordinary['list'][0]['available_quantity'] !== '0') {
        throw new RuntimeException('outbound_catalog_fixture_identity_invalid');
    }
    $outbound = $catalog->search($storeId, 'TEST-魅力青春霜套盒', 0, 1, 20, false, false, false, false, true, true);
    $row = $outbound['list'][0] ?? [];
    if ($outbound['total'] !== 1 || (int)($row['product_id'] ?? 0) !== $orphanProductId
        || (int)($row['sku_id'] ?? 0) !== $orphanSkuId
        || ($row['sku_unique'] ?? '') !== 'test-historic-stock'
        || ($row['stock_unit'] ?? '') !== '套' || ($row['available_quantity'] ?? '') !== '1') {
        throw new RuntimeException('outbound_catalog_orphan_stock_missing');
    }
    $byCode = $catalog->search($storeId, 'test-historic-stock', 0, 1, 20, false, false, false, false, true, true);
    if ($byCode['total'] !== 1 || (int)$byCode['list'][0]['sku_id'] !== $orphanSkuId) {
        throw new RuntimeException('outbound_catalog_code_search_failed');
    }
    try {
        $catalog->search($storeId + 1, 'TEST-魅力青春霜套盒', 0, 1, 20, false, false, false, false, true, true);
        throw new RuntimeException('outbound_catalog_cross_store_leak');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'inventory_catalog_source_scope_denied') throw $exception;
    }

    Db::name('inventory_stock')->where('id', $stockId)->update(['available_quantity_units' => 2]);
    try {
        $catalog->search($storeId, 'TEST-魅力青春霜套盒', 0, 1, 20, false, false, false, false, true, true);
        throw new RuntimeException('outbound_catalog_batch_mismatch_accepted');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'inventory_stock_count_batch_balance_mismatch') throw $exception;
    }
    echo "OUTBOUND_ORPHAN_STOCK_CATALOG_OK\n";
} finally {
    Db::rollback();
}
