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
use app\services\product\inventory\query\InventoryStatisticsUnifiedQueryRegistrar;
use app\services\query\UnifiedQueryContextFactory;
use app\services\query\UnifiedQueryException;
use app\services\query\UnifiedQueryPageRegistry;
use app\services\query\UnifiedQueryRuntime;
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
    if ($policy->canReadHqDashboard($write)) {
        throw new RuntimeException('Restricted employee unexpectedly sees group headquarters inventory');
    }
    try {
        (new InventoryPlatformWarehouseServices())->dashboard(['id' => $adminId]);
        throw new RuntimeException('Restricted employee unexpectedly reads group headquarters metrics');
    } catch (UnifiedQueryException $expected) {
        if ($expected->getErrorCode() !== 'INVENTORY_PLATFORM_ACCESS_DENIED') throw $expected;
    }
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
    // 首页门店主体只聚合当前员工门店；即使直接改请求参数也不能读取另一门店或总部仓。
    $dashboardService = new InventoryPlatformWarehouseServices();
    $storeDashboard = $dashboardService->dashboard(['id' => $adminId], 0, 'STORE');
    $singleDashboard = $dashboardService->dashboard(['id' => $adminId], 0, 'STORE', $allowedStoreId);
    if (($storeDashboard['subject'] ?? '') !== 'STORE' || (int)$storeDashboard['visible_store_count'] !== 1
        || $storeDashboard['stock_product_count'] !== $singleDashboard['stock_product_count']
        || $storeDashboard['stock_amount_cents'] !== $singleDashboard['stock_amount_cents']) {
        throw new RuntimeException('Restricted store dashboard did not retain the selected warehouse facts');
    }
    if ($policy->forDashboardStoreRead($write)['store_ids'] !== [$allowedStoreId]
        || $policy->forOperationalRead($write, 'inventory_statistics_expiry')['store_ids'] !== [$allowedStoreId]) {
        throw new RuntimeException('Dashboard statistics scope exceeds employee store authorization');
    }
    try {
        $dashboardService->dashboard(['id' => $adminId], 0, 'STORE', $forbiddenStoreId);
        throw new RuntimeException('Store dashboard accepted a forbidden store');
    } catch (InvalidArgumentException $expected) {
        if ($expected->getMessage() !== 'inventory_dashboard_store_scope_invalid') throw $expected;
    }
    try {
        $dashboardService->dashboard(['id' => $adminId], 1, 'STORE');
        throw new RuntimeException('Store dashboard accepted an HQ warehouse ID');
    } catch (InvalidArgumentException $expected) {
        if ($expected->getMessage() !== 'inventory_dashboard_scope_invalid') throw $expected;
    }
    // 两店批次事实可加；跨店调拨或请货单在两个角色均可见时仍只计一张。
    Db::name('employee_data_scope')->where('employee_id', $employeeId)
        ->update(['store_ids' => json_encode([$allowedStoreId, $forbiddenStoreId])]);
    $both = $dashboardService->dashboard(['id' => $adminId], 0, 'STORE');
    $first = $dashboardService->dashboard(['id' => $adminId], 0, 'STORE', $allowedStoreId);
    $second = $dashboardService->dashboard(['id' => $adminId], 0, 'STORE', $forbiddenStoreId);
    $organization = $dashboardService->dashboard(['id' => $adminId], 0, 'STORE', 0, $rootId);
    if ($both['visible_store_count'] !== 2 || $organization['stock_amount_cents'] !== $both['stock_amount_cents']
        || $both['stock_amount_cents'] !== $first['stock_amount_cents'] + $second['stock_amount_cents']
        || $both['in_transit_count'] > $first['in_transit_count'] + $second['in_transit_count']
        || $both['count_pending_count'] !== $first['count_pending_count'] + $second['count_pending_count']
        || $both['request_pending_count'] > $first['request_pending_count'] + $second['request_pending_count']
        || $both['expiring_90_batch_count'] !== $first['expiring_90_batch_count'] + $second['expiring_90_batch_count']) {
        throw new RuntimeException('Two-store dashboard differs from its authorized store facts');
    }
    // 后续既有断言继续使用单门店权限身份。
    Db::name('employee_data_scope')->where('employee_id', $employeeId)
        ->update(['store_ids' => json_encode([$allowedStoreId])]);
    $factory = new InventoryPlatformUnifiedQueryContextFactory(new UnifiedQueryContextFactory(
        UnifiedQueryPageRegistry::fromRegistrars([], [new InventoryOperationalUnifiedQueryRegistrar(), new InventoryStatisticsUnifiedQueryRegistrar()])
    ));
    $statisticsContext = $factory->make($adminId, ['id' => $adminId], date('Y-m-d'), 0, 'STORE', 'inventory_statistics_expiry');
    $statisticsLocations = array_values(array_map('intval', (array)$statisticsContext['scope_dimensions']['location_id']));
    $allowedLocationIds = array_values(array_map('intval', array_column(array_filter($locations,
        static fn(array $location): bool => (string)$location['location_type'] === 'STORE'), 'id')));
    if ($statisticsLocations !== $allowedLocationIds) throw new RuntimeException('Statistics drilldown diverges from dashboard warehouse scope');
    $runtime = UnifiedQueryRuntime::runtime();
    foreach ([
        'inventory_transfer' => ['metric' => 'in_transit_count', 'filters' => [['field' => 'status_name', 'operator' => 'eq', 'value' => '在途']]],
        'inventory_request' => ['metric' => 'request_pending_count', 'filters' => [['field' => 'status_name', 'operator' => 'in', 'value' => ['申请中', '部分履约']]]],
        'inventory_count' => ['metric' => 'count_pending_count', 'filters' => [['field' => 'status_name', 'operator' => 'neq', 'value' => 'CONFIRMED'], ['field' => 'status_name', 'operator' => 'neq', 'value' => 'CANCELLED']]],
    ] as $pageCode => $case) {
        $queryContext = (new InventoryPlatformUnifiedQueryContextFactory($runtime['contextFactory']))
            ->make($adminId, ['id' => $adminId], date('Y-m-d'), 0, 'STORE', $pageCode);
        $result = $runtime['providers']->resolve($pageCode)->query($queryContext, [
            'pageCode' => $pageCode, 'page' => 1, 'limit' => 20,
            'topFilterConditions' => $case['filters'],
        ]);
        if ((int)$result['total'] !== (int)$storeDashboard[$case['metric']]) {
            $raw = $runtime['providers']->resolve($pageCode)->query($queryContext, ['pageCode' => $pageCode, 'page' => 1, 'limit' => 20]);
            throw new RuntimeException($pageCode . ' drilldown total ' . (int)$result['total'] . ' differs from dashboard metric ' . (int)$storeDashboard[$case['metric']] . '; raw ' . (int)$raw['total'] . '; statuses ' . json_encode(array_column($raw['records'], 'status_name'), JSON_UNESCAPED_UNICODE));
        }
    }
    foreach (['inventory_request', 'inventory_count'] as $pageCode) {
        try {
            $factory->make($adminId, ['id' => $adminId], date('Y-m-d'), $forbiddenStoreId, 'STORE', $pageCode);
            throw new RuntimeException($pageCode . ' accepted a store outside the dashboard scope');
        } catch (UnifiedQueryException $expected) {
            if (strpos($expected->getMessage(), '不在当前平台库存范围') === false) throw $expected;
        }
    }
    // 库存首页菜单足以查看授权门店首页；不因此补发库存查询或单据页菜单。
    $overviewMenuId = (int)Db::name('system_menus')->where('unique_auth', 'admin-stock-manage')->where('is_del', 0)->value('id');
    Db::name('system_role')->where('id', $roleId)->update(['rules' => (string)$overviewMenuId]);
    $overviewOnly = $policy->resolveByAdminId($adminId);
    if ($policy->canReadHqDashboard($overviewOnly)
        || $policy->forDashboardStoreRead($overviewOnly)['store_ids'] !== [$allowedStoreId]
        || $dashboardService->dashboard(['id' => $adminId], 0, 'STORE')['subject'] !== 'STORE') {
        throw new RuntimeException('Overview-only role cannot read its authorized store dashboard');
    }
    try {
        $policy->forStockRead($overviewOnly);
        throw new RuntimeException('Overview-only role gained stock page permission');
    } catch (UnifiedQueryException $expected) {
        if ($expected->getErrorCode() !== 'INVENTORY_PLATFORM_ACCESS_DENIED') throw $expected;
    }
    Db::name('system_role')->where('id', $roleId)->update(['rules' => implode(',', $menuIds)]);
    // 同一员工若暂时绑定到内置 admin 超管，仍须保留全部门店读取范围。
    $systemAdminId = (int)Db::name('system_admin')->where('account', 'admin')->where('level', 0)
        ->where('is_del', 0)->value('id');
    if ($systemAdminId <= 0) throw new RuntimeException('Local built-in admin account is required');
    Db::name('system_admin')->where('id', $systemAdminId)->update(['employee_id' => $employeeId]);
    $adminAccess = $policy->resolveByAdminId($systemAdminId);
    if (!$policy->canReadHqDashboard($adminAccess)) throw new RuntimeException('Built-in admin headquarters exemption failed');
    foreach ([$policy->forStockRead($adminAccess), $policy->forPresaleRead($adminAccess)] as $adminRead) {
        if (!in_array($forbiddenStoreId, $adminRead['store_ids'], true)) {
            throw new RuntimeException('Built-in admin account was restricted by employee data scope');
        }
    }
    // 集团全部门店数据权限才可看共用总部仓；仅库存首页菜单不能替代这份数据授权。
    $allReportStores = array_values(array_filter(array_map('intval', Db::name('system_store')
        ->where('is_del', 0)->where('name', '<>', '总部')->column('id'))));
    Db::name('employee_data_scope')->where('employee_id', $employeeId)
        ->update(['store_ids' => json_encode($allReportStores)]);
    if (!$policy->canReadHqDashboard($write)) throw new RuntimeException('Full group scope cannot read headquarters dashboard');
    echo "PLATFORM_INVENTORY_DASHBOARD_SCOPE_OK\n";
} finally {
    Db::rollback();
}
