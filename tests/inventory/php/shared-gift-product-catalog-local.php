<?php
declare(strict_types=1);

/** Guarded local real-DB regression: all synthetic catalog rows are rolled back; no gifts are issued. */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();

use think\facade\Db;
use app\services\product\inventory\InventoryStoreCatalogServices;
use app\services\product\inventory\InventoryPlatformHqCatalogServices;
use app\services\cashier\v3\member\CashierV3DirectGiftIssuanceServices;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;

if (!getenv('MOHE_LOCAL_DB_HOSTNAME') || config('database.connections.mysql.hostname') !== 'mysql'
    || Db::query('SELECT @@hostname AS host')[0]['host'] !== getenv('MOHE_LOCAL_DB_HOSTNAME')) throw new RuntimeException('Local database guard failed');
function catalogCheck(bool $ok, string $name): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $name);
    echo 'PASS ' . $name . PHP_EOL;
}
$tag = 'selector-' . bin2hex(random_bytes(6));
$failed = false;
Db::startTrans();
try {
    // A synthetic store with no default warehouse proves gift selection does not require inventory setup.
    $storeId = (int)Db::name('system_store')->insertGetId(['name' => $tag, 'is_del' => 0, 'is_show' => 1]);
    $skus = [];
    foreach ([
        'sale' => ['is_show' => 1], 'warehouse' => ['is_show' => 0],
        'unmanaged' => ['is_inventory' => 0], 'pending' => ['is_verify' => 0],
        'rejected' => ['is_verify' => -1], 'recycled' => ['is_del' => 1],
        'forced_down' => ['is_show' => -1], 'project' => ['product_type' => 6],
        'foreign' => ['relation_id' => $storeId + 100000],
    ] as $name => $overrides) {
        $productId = (int)Db::name('store_product')->insertGetId(array_merge([
            'type' => 1, 'relation_id' => $storeId, 'product_type' => 0, 'is_inventory' => 1,
            'is_verify' => 1, 'is_del' => 0, 'is_show' => 0, 'store_name' => $tag . '-' . $name,
        ], $overrides));
        $skus[$name] = (int)Db::name('store_product_attr_value')->insertGetId([
            'product_id' => $productId, 'type' => 0, 'unique' => bin2hex(random_bytes(4)), 'suk' => 'default', 'stock_unit' => 'box', 'bar_code' => $tag . '-' . $name,
        ]);
    }
    $catalog = new InventoryStoreCatalogServices();
    $gift = $catalog->search($storeId, $tag, 0, 1, 50, false, false, false, false, false);
    $expected = [$skus['sale'], $skus['warehouse'], $skus['unmanaged']]; sort($expected);
    $actual = array_map('intval', array_column($gift['list'], 'sku_id')); sort($actual);
    catalogCheck($actual === $expected && $gift['total'] === 3, 'gift includes sale, warehouse and unmanaged physical products only');
    catalogCheck(count(array_filter($gift['list'], fn($row) => $row['available_quantity'] !== '0')) === 0, 'no warehouse means zero stock, not a gift denial');
    $page = $catalog->search($storeId, $tag, 0, 2, 1, false, false, false, false, false);
    catalogCheck(count($page['list']) === 1 && $page['total'] === 3 && $page['page'] === 2, 'server pagination retains complete eligible total');
    $scope = new CashierV3OperatorScope($storeId, 1, '', '0');
    $normalize = new ReflectionMethod(CashierV3DirectGiftIssuanceServices::class, 'normalizeItems');
    $normalize->setAccessible(true);
    $service = new CashierV3DirectGiftIssuanceServices();
    foreach (['sale', 'warehouse', 'unmanaged'] as $name) {
        $result = $normalize->invoke($service, [['kind' => 'product', 'catalogItemId' => $skus[$name], 'quantity' => 2]], $scope, time());
        catalogCheck($result[0]['skuId'] === $skus[$name] && $result[0]['quantity'] === 2, 'submit rechecks and accepts ' . $name);
    }
    foreach (['pending', 'rejected', 'recycled', 'forced_down', 'project', 'foreign'] as $name) {
        $rejected = false;
        try { $normalize->invoke($service, [['kind' => 'product', 'catalogItemId' => $skus[$name], 'quantity' => 1]], $scope, time()); }
        catch (Throwable $error) { $rejected = strpos($error->getMessage(), '所选赠送品项已失效') !== false; }
        catalogCheck($rejected, 'submit rejects ' . $name);
    }
    // Supply a synthetic warehouse so the inventory query can run with its normal strict location requirement.
    Db::name('inventory_location')->insert(['tenant_id' => '0', 'store_id' => $storeId, 'location_type' => 'STORE',
        'location_code' => $tag, 'location_name' => $tag, 'location_status' => 'ACTIVE', 'is_default' => 1]);
    $inventory = $catalog->search($storeId, $tag);
    $actual = array_map('intval', array_column($inventory['list'], 'sku_id')); sort($actual);
    $expected = [$skus['sale'], $skus['warehouse'], $skus['pending'], $skus['rejected'], $skus['forced_down']]; sort($expected);
    catalogCheck($actual === $expected, 'inventory uses participation switch without sales or review status restrictions');
    catalogCheck((int)$catalog->barcode($storeId, $tag . '-pending')['sku_id'] === $skus['pending'], 'store barcode shares inventory-only qualification');
    // The platform uses the same policy against HQ ownership, never the store's location or credentials.
    $hq = Db::name('inventory_location')->where('location_type', 'HQ')->where('is_default', 1)->where('location_status', 'ACTIVE')->find();
    if (!$hq) throw new RuntimeException('Local HQ fixture required');
    $adminId = (int)Db::name('system_admin')->insertGetId(['account' => $tag, 'pwd' => '', 'real_name' => 'TEST-selector', 'level' => 0, 'status' => 1, 'is_del' => 0]);
    Db::name('store_product')->where('relation_id', $storeId)->where('store_name', 'like', $tag . '%')->update(['type' => 0, 'relation_id' => 0]);
    $hqCatalog = new InventoryPlatformHqCatalogServices();
    $hqResult = $hqCatalog->search(['id' => $adminId], (int)$hq['id'], $tag);
    $actual = array_map('intval', array_column($hqResult['list'], 'sku_id')); sort($actual);
    catalogCheck($actual === $expected, 'HQ selector uses same switch-only inventory policy');
    catalogCheck((int)$hqCatalog->barcode(['id' => $adminId], (int)$hq['id'], $tag . '-pending')['sku_id'] === $skus['pending'], 'HQ barcode shares inventory-only qualification');
    $action = CashierV3ActionManifest::all()['query-gift-products'];
    catalogCheck($action['type'] === 'projection' && $action['permission'] === 'cashier.v3.cashier.gift', 'gift query has only gift feature permission');
} catch (Throwable $error) { $failed = true; fwrite(STDERR, $error->getMessage() . PHP_EOL . $error->getTraceAsString() . PHP_EOL); }
finally { Db::rollback(); echo "ROLLBACK synthetic product catalog and store\n"; }
catalogCheck((int)Db::name('system_store')->where('name', $tag)->count() === 0, 'no synthetic store remains');
exit($failed ? 1 : 0);
