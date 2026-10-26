<?php
declare(strict_types=1);

/** Local-only authority regression: synthetic menu/account rows are always rolled back. */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();

use app\services\product\inventory\InventoryPlatformAccessPolicy;
use app\services\product\inventory\InventoryPlatformWarehouseServices;
use app\services\product\inventory\query\InventoryBatchStockQueryContract;
use app\services\product\inventory\query\InventoryBatchStockUnifiedQueryRegistrar;
use app\services\product\inventory\query\InventoryPlatformUnifiedQueryContextFactory;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\query\UnifiedQueryContextFactory;
use app\services\query\UnifiedQueryPageRegistry;
use think\facade\Db;

if (!getenv('MOHE_LOCAL_DB_HOSTNAME')
    || config('database.connections.mysql.hostname') !== 'mysql'
    || (string)(Db::query('SELECT @@hostname AS host')[0]['host'] ?? '') !== getenv('MOHE_LOCAL_DB_HOSTNAME')) {
    throw new RuntimeException('Local database guard failed');
}

function stockScopeAssert(bool $condition, string $name): void
{
    if (!$condition) throw new RuntimeException($name);
    echo 'PASS ' . $name . PHP_EOL;
}

$menuId = (int)Db::name('system_menus')->where('unique_auth', 'admin-inventory-details')
    ->where('type', 1)->where('is_del', 0)->value('id');
$storeIds = array_values(array_unique(array_filter(array_map('intval', Db::name('inventory_location')
    ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
    ->where('location_type', 'STORE')->where('location_status', 'ACTIVE')->where('is_default', 1)
    ->column('store_id')))));
sort($storeIds, SORT_NUMERIC);
if ($menuId <= 0 || !$storeIds) throw new RuntimeException('Local stock menu and active store warehouse required');

Db::startTrans();
try {
    $tag = 'stock-read-' . bin2hex(random_bytes(5));
    $roleId = (int)Db::name('system_role')->insertGetId([
        'role_name' => $tag, 'rules' => (string)$menuId, 'status' => 1,
    ]);
    $adminId = (int)Db::name('system_admin')->insertGetId([
        'account' => $tag, 'real_name' => $tag, 'roles' => (string)$roleId,
        'level' => 1, 'status' => 1, 'is_del' => 0, 'admin_type' => 0,
    ]);
    $admin = ['id' => $adminId];
    $policy = new InventoryPlatformAccessPolicy();
    $original = $policy->resolve($admin);
    stockScopeAssert($original['store_ids'] === [] && !empty($original['headquarters_only']), 'stock-menu account starts without organization store scope');
    stockScopeAssert(!$policy->canCreate($original, 'inbound') && !in_array('inventory.location.manage', $original['features'], true), 'stock menu does not grant inventory writes');

    $stockAccess = $policy->forStockRead($original);
    stockScopeAssert($stockAccess['store_ids'] === $storeIds, 'stock-only scope includes every active default store warehouse');
    stockScopeAssert($original['store_ids'] === [] && $original['permission_version'] !== $stockAccess['permission_version'], 'read derivation leaves original account scope unchanged');

    $locations = (new InventoryPlatformWarehouseServices())->resolvedLocations($admin, false, true)['locations'];
    $locationStoreIds = array_values(array_unique(array_filter(array_map('intval', array_column(array_filter(
        $locations, static fn (array $row): bool => (string)$row['location_type'] === 'STORE'
    ), 'store_id')))));
    sort($locationStoreIds, SORT_NUMERIC);
    stockScopeAssert($locationStoreIds === $storeIds, 'stock picker resolves the same complete store warehouse set');

    $registry = UnifiedQueryPageRegistry::fromRegistrars([], [new InventoryBatchStockUnifiedQueryRegistrar()]);
    $factory = new InventoryPlatformUnifiedQueryContextFactory(new UnifiedQueryContextFactory($registry));
    $context = $factory->make($adminId, $admin, date('Y-m-d'), $storeIds[0], 'STORE', InventoryBatchStockQueryContract::PAGE_CODE);
    stockScopeAssert((int)$context['store_id'] === $storeIds[0] && count($context['scope_dimensions']['location_id']) >= 1, 'stock query accepts an authorized store');
    try {
        $factory->make($adminId, $admin, date('Y-m-d'), 0, 'STORE', 'inventory_inbound');
        throw new RuntimeException('inbound page unexpectedly authorized');
    } catch (\app\services\query\UnifiedQueryException $expected) {
        stockScopeAssert(true, 'stock menu cannot read the inbound page');
    }
} finally {
    Db::rollback();
}
