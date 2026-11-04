<?php
declare(strict_types=1);

/** Local transaction: inventory picker, list scope and warehouse candidates follow the dashboard's employee scope. */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\presale\CashierV3PresaleClaimServices;
use app\services\organization\OrganizationScopeService;
use app\services\product\inventory\InventoryPlatformAccessPolicy;
use app\services\product\inventory\InventoryPlatformWarehouseServices;
use app\services\product\inventory\query\InventoryOperationalUnifiedQueryRegistrar;
use app\services\product\inventory\query\InventoryPlatformUnifiedQueryContextFactory;
use app\services\query\UnifiedQueryContextFactory;
use app\services\query\UnifiedQueryException;
use app\services\query\UnifiedQueryPageRegistry;
use think\facade\Db;

if (!getenv('MOHE_LOCAL_DB_HOSTNAME')
    || config('database.connections.mysql.hostname') !== 'mysql'
    || (string)(Db::query('SELECT @@hostname AS host')[0]['host'] ?? '') !== getenv('MOHE_LOCAL_DB_HOSTNAME')) {
    throw new RuntimeException('Local database guard failed');
}

$scope = app()->make(OrganizationScopeService::class);
$rootId = $scope->resolveGroupRootOrgId();
$allStoreIds = Db::name('inventory_location')->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
    ->where('location_type', 'STORE')->where('location_status', 'ACTIVE')->where('is_default', 1)
    ->column('store_id');
$candidates = $scope->resolveDashboardStoreIds($rootId, 0, array_values(array_unique(array_map('intval', $allStoreIds))));
$candidates = array_values(array_unique(array_filter(array_map('intval', $candidates))));
if (count($candidates) < 2) throw new RuntimeException('Two local default store warehouses are required');
[$allowedStoreId, $forbiddenStoreId] = array_slice($candidates, 0, 2);

$auths = ['admin-stock-manage', 'admin-stock-request', 'admin-inventory-details', 'admin-inventory-count'];
$menuIds = Db::name('system_menus')->whereIn('unique_auth', $auths)->where('type', 1)->where('is_del', 0)->column('id');
if (count($menuIds) < count($auths)) throw new RuntimeException('Local inventory page menus are required');

Db::startTrans();
try {
    $token = 'inventory-dashboard-scope-' . bin2hex(random_bytes(6));
    $employeeId = 900000000 + random_int(1, 1000000);
    $roleId = (int)Db::name('system_role')->insertGetId([
        'role_name' => $token, 'rules' => implode(',', $menuIds), 'status' => 1,
    ]);
    $adminId = (int)Db::name('system_admin')->insertGetId([
        'account' => $token, 'real_name' => $token, 'roles' => (string)$roleId,
        'employee_id' => $employeeId, 'level' => 1, 'status' => 1, 'is_del' => 0, 'admin_type' => 0,
    ]);
    Db::name('employee_data_scope')->insert([
        'employee_id' => $employeeId, 'source_type' => 'hq', 'source_store_id' => 0,
        'scope_mode' => 'store_selected', 'store_ids' => json_encode([$allowedStoreId]),
        'org_ids' => '[]', 'status' => 1, 'is_del' => 0, 'request_token' => $token,
    ]);

    $policy = new InventoryPlatformAccessPolicy();
    $write = $policy->resolve(['id' => $adminId]);
    foreach ([
        '预售领用' => $policy->forPresaleRead($write),
        '库存查询' => $policy->forStockRead($write),
        '请货管理' => $policy->forOperationalRead($write, 'inventory_request'),
        '盘点查询' => $policy->forCountRead($write),
    ] as $label => $read) {
        if ($read['store_ids'] !== [$allowedStoreId]
            || in_array($forbiddenStoreId, $read['store_ids'], true)
            || $read['features'] !== $write['features']) {
            throw new RuntimeException($label . ' did not retain the dashboard scope and original write grants');
        }
    }
    $presalePicker = (new CashierV3PresaleClaimServices())->scopeForPlatform(['id' => $adminId]);
    if ($presalePicker['allowed_store_ids'] !== [$allowedStoreId]) {
        throw new RuntimeException('Presale picker exceeds dashboard scope');
    }
    $locations = (new InventoryPlatformWarehouseServices())->resolvedLocations(['id' => $adminId])['locations'];
    $visibleStoreIds = array_values(array_unique(array_map('intval', array_column(array_filter($locations,
        static fn(array $location): bool => (string)$location['location_type'] === 'STORE'), 'store_id'))));
    if ($visibleStoreIds !== [$allowedStoreId]) throw new RuntimeException('Warehouse candidates exceed dashboard scope');
    $factory = new InventoryPlatformUnifiedQueryContextFactory(new UnifiedQueryContextFactory(
        UnifiedQueryPageRegistry::fromRegistrars([], [new InventoryOperationalUnifiedQueryRegistrar()])
    ));
    foreach (['inventory_request', 'inventory_count'] as $pageCode) {
        try {
            $factory->make($adminId, ['id' => $adminId], date('Y-m-d'), $forbiddenStoreId, 'STORE', $pageCode);
            throw new RuntimeException($pageCode . ' accepted a store outside the dashboard scope');
        } catch (UnifiedQueryException $expected) {
            if (strpos($expected->getMessage(), '不在当前平台库存范围') === false) throw $expected;
        }
    }
    // 同一员工若暂时绑定到内置 admin 超管，仍须保留全部门店读取范围。
    $systemAdminId = (int)Db::name('system_admin')->where('account', 'admin')->where('level', 0)
        ->where('is_del', 0)->value('id');
    if ($systemAdminId <= 0) throw new RuntimeException('Local built-in admin account is required');
    Db::name('system_admin')->where('id', $systemAdminId)->update(['employee_id' => $employeeId]);
    $adminAccess = $policy->resolveByAdminId($systemAdminId);
    foreach ([$policy->forStockRead($adminAccess), $policy->forPresaleRead($adminAccess)] as $adminRead) {
        if (!in_array($forbiddenStoreId, $adminRead['store_ids'], true)) {
            throw new RuntimeException('Built-in admin account was restricted by employee data scope');
        }
    }
    echo "PLATFORM_INVENTORY_DASHBOARD_SCOPE_OK\n";
} finally {
    Db::rollback();
}
