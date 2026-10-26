<?php
declare(strict_types=1);

/** Local-only platform store-stock detail check; synthetic role/account rows are rolled back. */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\product\inventory\InventoryPlatformAccessPolicy;
use app\services\product\inventory\InventoryPlatformStoreStockDetailServices;
use app\services\query\UnifiedQueryException;
use think\facade\Db;

if (!getenv('MOHE_LOCAL_DB_HOSTNAME')
    || config('database.connections.mysql.hostname') !== 'mysql'
    || (string)(Db::query('SELECT @@hostname AS host')[0]['host'] ?? '') !== getenv('MOHE_LOCAL_DB_HOSTNAME')) {
    throw new RuntimeException('Local database guard failed');
}

function checkPlatformStockDetail(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
    echo 'PASS ' . $label . PHP_EOL;
}

$menuId = (int)Db::name('system_menus')->where('unique_auth', 'admin-inventory-details')
    ->where('type', 1)->where('is_del', 0)->value('id');
$sample = Db::name('inventory_stock')->alias('s')
    ->join('inventory_location l', 'l.id=s.location_id')
    ->where('s.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
    ->where('l.location_type', 'STORE')->where('l.location_status', 'ACTIVE')->where('l.is_default', 1)
    ->where('s.available_quantity_units', '>', 0)->where('s.consumable_product_id', '>', 0)
    ->field('l.id location_id,l.store_id,s.consumable_product_id product_id')->find();
$hqId = (int)Db::name('inventory_location')->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
    ->where('location_type', 'HQ')->value('id');
if ($menuId <= 0 || !$sample || $hqId <= 0) throw new RuntimeException('Local stock menu, store stock and HQ location required');

Db::startTrans();
try {
    $tag = 'platform-stock-detail-' . bin2hex(random_bytes(5));
    $roleId = (int)Db::name('system_role')->insertGetId([
        'role_name' => $tag, 'rules' => (string)$menuId, 'status' => 1,
    ]);
    $adminId = (int)Db::name('system_admin')->insertGetId([
        'account' => $tag, 'real_name' => $tag, 'roles' => (string)$roleId,
        'level' => 1, 'status' => 1, 'is_del' => 0, 'admin_type' => 0,
    ]);
    $admin = ['id' => $adminId];
    $policy = new InventoryPlatformAccessPolicy();
    checkPlatformStockDetail($policy->allowsLegacyMenuRead($adminId,
        'product/inventory/v3/store/product-summary/<productid>/detail', 'get'), 'stock menu opens exact platform detail route');
    $service = new InventoryPlatformStoreStockDetailServices();
    $detail = $service->productDetail($admin, (int)$sample['location_id'], (int)$sample['product_id']);
    checkPlatformStockDetail((int)$detail['product']['product_id'] === (int)$sample['product_id']
        && ($detail['reconciliation']['status'] ?? '') === 'MATCHED'
        && is_array($detail['movements'] ?? null), 'platform store detail reuses settled stock and movement projection');
    checkPlatformStockDetail(!array_filter($detail['movements'], static fn(array $fact): bool =>
        (int)$fact['store_id'] !== (int)$sample['store_id']), 'movement rows stay in the selected store');
    $location = Db::name('inventory_location')->where('id', (int)$sample['location_id'])
        ->field('store_name_snapshot')->find();
    $storeName = trim((string)($location['store_name_snapshot'] ?? ''));
    if ($storeName === '') {
        $storeName = trim((string)Db::name('system_store')->where('id', (int)$sample['store_id'])->value('name'));
    }
    $displayName = $storeName !== '' ? $storeName : '门店' . (int)$sample['store_id'];
    checkPlatformStockDetail(!array_filter($detail['movements'], static fn(array $fact): bool =>
        (string)$fact['location_name'] !== $displayName), 'movement warehouse displays the selected store name');
    try {
        $service->productDetail($admin, $hqId, (int)$sample['product_id']);
        throw new RuntimeException('HQ location unexpectedly accepted as a store');
    } catch (UnifiedQueryException $expected) {
        checkPlatformStockDetail(true, 'HQ location cannot be passed as a store warehouse');
    }
    Db::name('system_role')->where('id', $roleId)->update(['rules' => '']);
    try {
        $service->productDetail($admin, (int)$sample['location_id'], (int)$sample['product_id']);
        throw new RuntimeException('Revoked stock menu unexpectedly reads detail');
    } catch (UnifiedQueryException $expected) {
        checkPlatformStockDetail(true, 'revoked stock menu closes detail');
    }
} finally {
    Db::rollback();
}
