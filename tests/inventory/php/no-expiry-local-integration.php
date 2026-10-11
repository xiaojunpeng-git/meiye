<?php
declare(strict_types=1);

/** 本机容器限定；合成商品、库存、单据和事实全部在外层事务中回滚，不修改真实商品配置。 */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();

use think\facade\Db;
use app\services\product\inventory\InventoryManualInboundServices;
use app\services\product\inventory\InventoryStockCountServices;
use app\services\product\inventory\InventoryStockCountDraftServices;
use app\services\product\inventory\InventoryCatalogStockProjection;
use app\services\product\inventory\InventoryHqLocationServices;
use app\services\product\inventory\InventoryPlatformHqCatalogServices;
use app\services\product\inventory\InventoryStoreCatalogServices;
use app\services\product\inventory\InventoryV3ExcelImportServices;

if (!getenv('MOHE_LOCAL_DB_HOSTNAME') || config('database.connections.mysql.hostname') !== 'mysql'
    || Db::query('SELECT @@hostname AS host')[0]['host'] !== getenv('MOHE_LOCAL_DB_HOSTNAME')) {
    throw new RuntimeException('Local database guard failed');
}
function expiryCheck(bool $ok, string $name): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $name);
    echo 'PASS ' . $name . PHP_EOL;
}
function expiryReject(callable $operation, string $reason): void {
    try { $operation(); } catch (Throwable $error) {
        expiryCheck($error->getMessage() === $reason, 'reject ' . $reason . ' got ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $reason);
}
$tag = 'expiry-' . bin2hex(random_bytes(6));
$failed = false;
Db::startTrans();
try {
    $adminId = (int)Db::name('system_admin')->insertGetId(['account' => $tag, 'pwd' => '', 'real_name' => 'TEST-expiry', 'level' => 0, 'status' => 1, 'is_del' => 0]);
    $admin = ['id' => $adminId, 'real_name' => 'TEST-expiry'];
    $location = Db::name('inventory_location')->where('location_type', 'HQ')->where('location_status', 'ACTIVE')->where('is_default', 1)->find();
    if (!$location) throw new RuntimeException('Local HQ fixture required');
    $locationId = (int)$location['id'];
    $scope = (new InventoryHqLocationServices())->scope($location, $adminId);
    $scope['operatorType'] = 'PLATFORM_ADMIN';
    $scope['operatorName'] = 'TEST-expiry';
    $products = [];
    foreach ([0, 1] as $flag) {
        $unique = bin2hex(random_bytes(4));
        $id = (int)Db::name('store_product')->insertGetId(['type' => 0, 'relation_id' => 0, 'is_inventory' => 1,
            'is_del' => 0, 'is_verify' => 1, 'is_show' => 0, 'product_type' => 0, 'store_name' => $tag . '-' . $flag, 'no_expiry_management' => $flag]);
        $skuId = (int)Db::name('store_product_attr_value')->insertGetId(['product_id' => $id, 'type' => 0,
            'unique' => $unique, 'suk' => 'default', 'stock_unit' => 'box']);
        $products[$flag] = ['product_id' => $id, 'sku_id' => $skuId, 'sku_unique' => $unique,
            'batch_no' => '', 'quantity' => '3', 'unit_cost' => '2.50', 'manufactured_date' => '', 'expire_date' => ''];
    }
    // 与商品保存共用 DAO，验证新增列能写回并重新读取，而不只存在于前端或原生 SQL 夹具。
    $productService = app()->make(\app\services\product\product\StoreProductServices::class);
    $productService->update($products[1]['product_id'], ['no_expiry_management' => 0]);
    expiryCheck((int)$productService->get($products[1]['product_id'])['no_expiry_management'] === 0, 'product DAO persists and reloads switch off');
    $productService->update($products[1]['product_id'], ['no_expiry_management' => 1]);
    expiryCheck((int)$productService->get($products[1]['product_id'])['no_expiry_management'] === 1, 'product DAO persists and reloads switch on');
    $inbound = new InventoryManualInboundServices();
    $command = ['idempotency_key' => $tag . '-in', 'business_date' => date('Y-m-d'), 'remark' => $tag, 'lines' => [$products[1]]];
    $first = $inbound->createForHeadquarters($scope, $location, $command);
    expiryCheck($inbound->createForHeadquarters($scope, $location, $command)['lines'][0]['idempotent'], 'blank batch/dates inbound replays once');
    $stock = Db::name('inventory_stock')->where('consumable_product_id', $products[1]['product_id'])->find();
    $batch = Db::name('inventory_batch')->where('stock_id', $stock['id'])->find();
    expiryCheck(strpos($batch['batch_no'], 'AUTO-IN-') === 0 && $batch['manufactured_date'] === null && $batch['expire_date'] === null,
        'internal cost layer has no fabricated dates');
    $command['idempotency_key'] .= '-different-cost'; $command['lines'][0]['unit_cost'] = '4.00';
    $inbound->createForHeadquarters($scope, $location, $command);
    expiryCheck((int)Db::name('inventory_batch')->where('stock_id', $stock['id'])->count() === 2, 'different inbound retains separate cost layers');
    $catalog = (new InventoryPlatformHqCatalogServices())->search($admin, $locationId, $tag);
    $row = array_values(array_filter($catalog['list'], fn($row) => (int)$row['sku_id'] === $products[1]['sku_id']))[0];
    expiryCheck((int)$row['no_expiry_management'] === 1 && $row['available_quantity'] === '6', 'catalog returns persisted flag and total across batches');
    expiryCheck((new InventoryCatalogStockProjection())->decorate((string)$location['tenant_id'], $locationId + 999999, [$products[1]])[0]['available_quantity'] === '0', 'other warehouse cannot leak stock');
    expiryCheck((new InventoryCatalogStockProjection())->decorate('another-tenant', $locationId, [$products[1]])[0]['available_quantity'] === '0', 'other tenant cannot leak stock');
    $command['idempotency_key'] .= '-managed'; $command['lines'] = [$products[0]];
    expiryReject(fn() => $inbound->createForHeadquarters($scope, $location, $command), 'inventory_manual_inbound_dates_required');
    $command['lines'][0]['batch_no'] = $tag; $command['lines'][0]['manufactured_date'] = '2026-01-01'; $command['lines'][0]['expire_date'] = '2027-01-01';
    $inbound->createForHeadquarters($scope, $location, $command);
    $command['idempotency_key'] .= '-mixed'; $command['lines'] = [$products[1], $products[0]];
    $before = (int)Db::name('inventory_batch_movement_fact')->count();
    expiryReject(fn() => $inbound->createForHeadquarters($scope, $location, $command), 'inventory_manual_inbound_dates_required');
    expiryCheck((int)Db::name('inventory_batch_movement_fact')->count() === $before, 'mixed invalid inbound rolls back whole command');

    $counts = new InventoryStockCountServices();
    $line = ['product_id' => $products[1]['product_id'], 'sku_id' => $products[1]['sku_id'], 'sku_unique' => $products[1]['sku_unique'],
        'counted_quantity' => '8', 'surplus_batch_no' => '', 'surplus_unit_cost' => '3.00', 'surplus_manufactured_date' => '', 'surplus_expire_date' => ''];
    $count = ['idempotency_key' => $tag . '-count', 'business_date' => date('Y-m-d'), 'remark' => $tag, 'lines' => [$line]];
    $result = $counts->confirmForHeadquarters($admin, $locationId, $count);
    expiryCheck($counts->confirmForHeadquarters($admin, $locationId, $count)['idempotent'], 'blank batch/date count gain replays once');
    $countedLine = Db::name('inventory_stock_count_line')->where('document_id', $result['count_document_id'])->find();
    expiryCheck((int)$countedLine['difference_quantity_units'] === 2 && strpos($countedLine['surplus_batch_no'], 'AUTO-COUNT-') === 0
        && $countedLine['surplus_expire_date'] === null, 'count gain records two units with internal layer and null expiry');
    $count['idempotency_key'] .= '-no-cost'; $count['lines'][0]['counted_quantity'] = '9'; $count['lines'][0]['surplus_unit_cost'] = '';
    expiryReject(fn() => $counts->confirmForHeadquarters($admin, $locationId, $count), 'inventory_stock_count_surplus_batch_required');
    $count['idempotency_key'] .= '-loss'; $count['lines'][0]['counted_quantity'] = '5';
    $counts->confirmForHeadquarters($admin, $locationId, $count);
    expiryCheck((int)Db::name('inventory_stock')->where('id', $stock['id'])->value('available_quantity_units') === 5,
        'count loss allocates existing cost layers without requiring batch or dates');
    $count['idempotency_key'] .= '-same';
    $counts->confirmForHeadquarters($admin, $locationId, $count);
    expiryCheck((int)Db::name('inventory_stock')->where('id', $stock['id'])->value('available_quantity_units') === 5, 'zero difference creates no balance change');
    $count['idempotency_key'] .= '-managed'; $count['lines'][0] = array_merge($line, array_intersect_key($products[0], array_flip(['product_id','sku_id','sku_unique'])));
    expiryReject(fn() => $counts->confirmForHeadquarters($admin, $locationId, $count), 'inventory_stock_count_surplus_batch_required');

    $drafts = new InventoryStockCountDraftServices();
    $draft = $drafts->saveForHeadquarters($admin, $locationId, ['draft_id' => 0, 'expected_version' => 0,
        'business_date' => date('Y-m-d'), 'remark' => $tag, 'lines' => [array_merge($line, ['book_quantity' => '5', 'counted_quantity' => '5'])]]);
    expiryCheck((int)$drafts->detailForHeadquarters($admin, $locationId, $draft['draft_id'])['lines'][0]['no_expiry_management'] === 1,
        'draft reload rehydrates current product setting');
    // 旧草稿尚未填写批号时不应被隐藏输入框锁死；已有批号身份仍必须保留。
    $pending = array_merge($line, ['book_quantity' => '5', 'count_mode' => 'BATCH']);
    $pendingDraft = $drafts->saveForHeadquarters($admin, $locationId, ['business_date' => date('Y-m-d'), 'lines' => [$pending]]);
    $restored = $drafts->detailForHeadquarters($admin, $locationId, $pendingDraft['draft_id'])['lines'][0];
    expiryCheck(($restored['count_mode'] ?? 'SKU') === 'SKU', 'old unfinished batch draft resumes without requiring a batch number');
    $pending['surplus_batch_no'] = $batch['batch_no'];
    $batchDraft = $drafts->saveForHeadquarters($admin, $locationId, ['business_date' => date('Y-m-d'), 'lines' => [$pending]]);
    $restored = $drafts->detailForHeadquarters($admin, $locationId, $batchDraft['draft_id'])['lines'][0];
    expiryCheck($restored['count_mode'] === 'BATCH' && $restored['surplus_batch_no'] === $batch['batch_no'], 'existing draft batch identity remains intact');
    Db::name('store_product')->where('id', $products[1]['product_id'])->update(['no_expiry_management' => 0]);
    expiryCheck((int)$drafts->detailForHeadquarters($admin, $locationId, $draft['draft_id'])['lines'][0]['no_expiry_management'] === 0, 'draft cannot freeze or forge exemption');
    $replay = ['idempotency_key' => $tag . '-in', 'business_date' => date('Y-m-d'), 'remark' => $tag, 'lines' => [$products[1]]];
    expiryCheck($inbound->createForHeadquarters($scope, $location, $replay)['lines'][0]['idempotent'], 'inbound replay survives later flag change');
    expiryCheck(Db::name('inventory_batch')->where('id', $batch['id'])->value('expire_date') === null, 'flag change never rewrites historical dates');

    // Excel is another inbound entrance; the same catalogue configuration must control mandatory data.
    $validate = new ReflectionMethod(InventoryV3ExcelImportServices::class, 'validateRows'); $validate->setAccessible(true);
    $excel = [$products[1]['product_id'], $products[1]['sku_unique'], '', '', '', '', '', '1', '0', date('Y-m-d'), '采购入库', '', '', '', '2.50', ''];
    Db::name('store_product')->where('id', $products[1]['product_id'])->update(['no_expiry_management' => 1]);
    [$errors, $parsed] = $validate->invoke(new InventoryV3ExcelImportServices(), 'inbound', 0, [['row' => 2, 'data' => $excel]], 'HQ');
    expiryCheck(count($parsed['lines']) === 1 && !$errors, 'Excel accepts exempt blank batch and dates');
    Db::name('inventory_stock')->where('id', $stock['id'])->update(['available_quantity_units' => 125, 'quantity_scale' => 2]);
    expiryCheck((new InventoryCatalogStockProjection())->decorate((string)$location['tenant_id'], $locationId, [$products[1]])[0]['available_quantity'] === '1.25', 'catalog formats fractional stock using authoritative SKU scale');
    // 仅把本事务的合成目录/余额改为门店夹具，真实门店及其库存不写入。
    $storeLocation = Db::name('inventory_location')->where('tenant_id', '0')->where('location_type', 'STORE')
        ->whereNotIn('id', Db::name('inventory_stock')->where('available_quantity_units', '>', 0)->column('location_id'))
        ->where('is_default', 1)->where('location_status', 'ACTIVE')->find();
    if (!$storeLocation) throw new RuntimeException('Local store location fixture required');
    $storeId = (int)$storeLocation['store_id'];
    Db::name('store_product')->where('id', $products[1]['product_id'])->update(['type' => 1, 'relation_id' => $storeId]);
    Db::name('inventory_stock')->where('id', $stock['id'])->update(['location_id' => $storeLocation['id'], 'store_id' => $storeId, 'available_quantity_units' => 5, 'quantity_scale' => 0]);
    $storeCatalog = new InventoryStoreCatalogServices();
    $storeRows = $storeCatalog->search($storeId, $tag)['list'];
    expiryCheck(count($storeRows) === 1 && $storeRows[0]['available_quantity'] === '5'
        && (int)$storeRows[0]['no_expiry_management'] === 1, 'store catalog shares persisted setting and SKU stock total');
    $existing = new ReflectionMethod(InventoryStoreCatalogServices::class, 'positiveBatchCountCatalog'); $existing->setAccessible(true);
    $rows = $existing->invoke($storeCatalog, $storeId, 1, 50, true)['list'];
    $active = array_values(array_filter($rows, fn($row) => (int)$row['sku_id'] === $products[1]['sku_id']))[0];
    expiryCheck(!$active['count_requires_batch_identity'], 'active exempt stock permits total count');
    Db::name('store_product')->where('id', $products[1]['product_id'])->update(['is_del' => 1]);
    $rows = $existing->invoke($storeCatalog, $storeId, 1, 50, true)['list'];
    $retired = array_values(array_filter($rows, fn($row) => (int)$row['sku_id'] === $products[1]['sku_id']))[0];
    expiryCheck($retired['count_requires_batch_identity'] && (int)$retired['no_expiry_management'] === 1, 'retired exempt stock retains real batch identity without losing exemption');
} catch (Throwable $error) { $failed = true; echo $error->getMessage() . PHP_EOL . $error->getTraceAsString() . PHP_EOL; }
finally { Db::rollback(); echo "ROLLBACK synthetic expiry fixtures and inventory facts\n"; }
expiryCheck(!Db::name('system_admin')->where('account', $tag)->count(), 'no synthetic administrator remains');
exit($failed ? 1 : 0);
