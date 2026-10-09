<?php
declare(strict_types=1);

$backend = getenv('BACKEND_ROOT') ?: '/workspace/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\product\inventory\InventoryStockCountCatalogException;
use app\services\product\inventory\InventoryStockCountServices;
use app\services\product\inventory\InventoryStockCountQueryServices;
use app\services\product\inventory\InventoryStoreCatalogServices;
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

// 夹具重置和盘点确认置于外层事务；历史测试可能遗留另一默认仓，只在本次事务内隔离。
Db::startTrans();
try {
Db::name('inventory_location')->where('store_id', 99190)->where('id', '<>', 991900)->update(['is_default' => 0]);
catalogFixture('system_store', ['id' => 99190], ['id' => 99190, 'name' => 'TEST-盘点失效目录门店', 'is_del' => 0, 'is_show' => 1]);
catalogFixture('system_store_staff', ['id' => 991900], ['id' => 991900, 'store_id' => 99190, 'status' => 1, 'is_del' => 0]);
catalogFixture('organization', ['id' => 99190], ['id' => 99190, 'pid' => 99000, 'name' => 'TEST-盘点失效目录组织', 'is_del' => 0]);
catalogFixture('organization_store', ['store_id' => 99190], ['store_id' => 99190, 'org_id' => 99190]);
// 隔离夹具库的旧 staff 表无 staff_name；用真实组织会话契约绕过旧列，不改客户或测试库结构。
$countSession = ['_cashier_v3_organization' => true, 'id' => 991900, 'store_id' => 99190,
    'employee_id' => 991900, 'session_mode' => 'store_organization', 'real_name' => 'TEST-盘点人员',
    'operation_features' => ['cashier.v3.inventory.count']];
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
$stockCountBefore = (int)Db::name('inventory_stock')->where('store_id', 99190)->whereIn('consumable_product_id', [991901, 991902])->count();
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
    ], $countSession);
} catch (InventoryStockCountCatalogException $exception) {
    $invalidLines = $exception->invalidLines();
}

$documentCount = (int)Db::name('inventory_stock_count_document')->where('idempotency_key', $key)->count();
$stockCount = (int)Db::name('inventory_stock')->where('store_id', 99190)->whereIn('consumable_product_id', [991901, 991902])->count();
if (array_column($invalidLines, 'line_index') !== [1, 2] || $documentCount !== 0 || $stockCount !== $stockCountBefore) {
    throw new RuntimeException('stock_count_catalog_validation_integration_failed');
}

// 清零候选以现存批次为准：覆盖下架商品及无商品/规格目录的历史库存；重复运行前重置隔离夹具。
foreach ([
    [9919010, 991901, 9919011, 'deleted-sku-9919011', 5],
    [9919030, 991903, 9919031, 'orphan-sku-9919031', 4],
] as [$stockId, $productId, $skuId, $unique, $quantity]) {
    catalogFixture('inventory_stock', ['id' => $stockId], [
        'id' => $stockId, 'tenant_id' => '0', 'organization_id' => '99190', 'organization_path' => '/99000/99190/',
        'location_id' => 991900, 'store_id' => 99190, 'consumable_product_id' => $productId,
        'sku_id' => $skuId, 'product_unique' => $unique, 'stock_status' => 'GOOD', 'stock_unit' => '件',
        'quantity_scale' => 0, 'available_quantity_units' => $quantity, 'estimated_unit_cost_cents' => 250,
        'version' => 1, 'created_at' => time(), 'updated_at' => time(),
    ]);
}
foreach ([
    [99190101, 9919010, 'TEST-RETIRED-A', 2, 'TEST-已删除盘点商品1'],
    [99190102, 9919010, 'TEST-RETIRED-B', 3, 'TEST-已删除盘点商品1'],
    [99190301, 9919030, 'TEST-ORPHAN', 4, 'TEST-目录缺失库存'],
] as [$batchId, $stockId, $batchNo, $quantity, $name]) {
    catalogFixture('inventory_batch', ['id' => $batchId], [
        'id' => $batchId, 'stock_id' => $stockId, 'batch_no' => $batchNo,
        'manufactured_date' => '2026-01-01', 'expire_date' => '2027-01-01', 'received_at' => time(),
        'available_quantity_units' => $quantity, 'unit_cost_cents' => 250, 'cost_allocated_quantity_units' => 0,
        'batch_status' => 'ACTIVE', 'version' => 1, 'product_name_snapshot' => $name,
        'sku_name_snapshot' => '默认', 'barcode_snapshot' => '', 'created_at' => time(), 'updated_at' => time(),
    ]);
}
$zeroCatalog = (new InventoryStoreCatalogServices())->search(99190, '', 0, 1, 50, true, true, false, true);
$zeroRows = array_column($zeroCatalog['list'], null, 'sku_id');
if (!isset($zeroRows[9919011], $zeroRows[9919031])
    || count($zeroRows[9919011]['count_batches']) !== 2
    || count($zeroRows[9919031]['count_batches']) !== 1
    || $zeroRows[9919031]['product_name'] !== 'TEST-目录缺失库存'
    || $zeroRows[9919011]['count_batches'][0]['unit_cost'] !== null) {
    throw new RuntimeException('stock_count_zero_candidates_missing_retired_batches');
}
// 批次和总余额失衡时“一键全部”必须失败，绝不可漏盘后仍提示全部清零。
Db::name('inventory_stock')->where('id', 9919030)->update(['available_quantity_units' => 5]);
$balanceRejected = false;
try {
    (new InventoryStoreCatalogServices())->search(99190, '', 0, 1, 50, true, true, false, true);
} catch (RuntimeException $exception) {
    $balanceRejected = $exception->getMessage() === 'inventory_stock_count_batch_balance_mismatch';
} finally {
    Db::name('inventory_stock')->where('id', 9919030)->update(['available_quantity_units' => 4]);
}
if (!$balanceRejected) throw new RuntimeException('stock_count_zero_balance_mismatch_not_rejected');

/** A retired SKU can only retain or reduce a real batch; gains, SKU mode and fabricated batches remain invalid. */
function retiredLine(string $counted, string $batchNo, bool $batchMode = true): array
{
    return ['product_id' => 991901, 'sku_id' => 9919011, 'sku_unique' => 'deleted-sku-9919011',
        'counted_quantity' => $counted,
        'surplus_batch_no' => $batchNo, 'surplus_unit_cost' => '',
        'surplus_manufactured_date' => '', 'surplus_expire_date' => '',
        'expected_book_quantity' => '0', 'count_mode' => $batchMode ? 'BATCH' : 'SKU'];
}
foreach ([retiredLine('3', 'TEST-RETIRED-A'), retiredLine('0', 'TEST-RETIRED-A', false), retiredLine('0', 'TEST-FORGED')] as $line) {
    $rejected = false;
    try {
        (new InventoryStockCountServices())->confirm(99190, 991900, [
            'idempotency_key' => 'TEST-count-retired-reject-' . bin2hex(random_bytes(6)),
            'business_date' => '2026-10-03', 'remark' => 'TEST-拒绝无效清零', 'lines' => [$line],
        ], $countSession);
    } catch (InventoryStockCountCatalogException $exception) {
        $rejected = count($exception->invalidLines()) === 1;
    }
    if (!$rejected) throw new RuntimeException('stock_count_retired_gain_or_forged_batch_accepted');
}
$retiredResult = (new InventoryStockCountServices())->confirm(99190, 991900, [
    'idempotency_key' => 'TEST-count-retired-loss-' . bin2hex(random_bytes(6)),
    'business_date' => '2026-10-03', 'remark' => 'TEST-失效目录持平及盘亏',
    'lines' => [retiredLine('2', 'TEST-RETIRED-A'), retiredLine('2', 'TEST-RETIRED-B')],
], $countSession);
if ((int)Db::name('inventory_stock')->where('id', 9919010)->value('available_quantity_units') !== 4
    || (int)Db::name('inventory_stock_count_line')->where('document_id', $retiredResult['count_document_id'])->count() !== 2
    || (int)Db::name('inventory_batch_movement_fact')->where('source_id', $retiredResult['count_no'])->count() !== 1) {
    throw new RuntimeException('stock_count_retired_unchanged_or_loss_failed');
}
$orphanLine = ['product_id' => 991903, 'sku_id' => 9919031, 'sku_unique' => 'orphan-sku-9919031',
    'counted_quantity' => '0', 'surplus_batch_no' => 'TEST-ORPHAN',
    'surplus_unit_cost' => '', 'surplus_manufactured_date' => '', 'surplus_expire_date' => '',
    'expected_book_quantity' => '0', 'count_mode' => 'BATCH'];
$zeroResult = (new InventoryStockCountServices())->confirm(99190, 991900, [
    'idempotency_key' => 'TEST-count-retired-zero-' . bin2hex(random_bytes(6)),
    'business_date' => '2026-10-03', 'remark' => 'TEST-失效目录仅盘亏至零',
    'lines' => [retiredLine('0', 'TEST-RETIRED-A'), retiredLine('0', 'TEST-RETIRED-B'), $orphanLine],
], $countSession);
if ((int)Db::name('inventory_stock')->where('id', 9919010)->value('available_quantity_units') !== 0
    || (int)Db::name('inventory_stock')->where('id', 9919030)->value('available_quantity_units') !== 0
    || (int)Db::name('inventory_stock_count_line')->where('document_id', $zeroResult['count_document_id'])->count() !== 3) {
    throw new RuntimeException('stock_count_retired_zero_not_settled');
}
$detail = (new InventoryStockCountQueryServices())->detail(99190, 991900, (int)$zeroResult['count_document_id'], false, $countSession);
if ($detail['lines'][2]['product_name'] !== 'TEST-目录缺失库存') {
    throw new RuntimeException('stock_count_retired_snapshot_name_missing');
}

echo "STOCK_COUNT_CATALOG_VALIDATION_INTEGRATION_OK\n";
} finally { Db::rollback(); echo "ROLLBACK catalog validation fixtures and count events\n"; }
