<?php
/**
 * 本地事务回归：指定门店只授权显式 ID，拒绝无效/越权 ID，结束时回滚全部权限和审计写入。
 * 仅允许在本机 mohe-mysql 容器内运行，绝不连接客户线上实例。
 */
declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';

use app\services\organization\EmployeeDataScopeServices;
use app\services\mobile\merchant\MobileMerchantSessionServices;
use app\services\mobile\merchant\MobileMerchantRequestContextResolver;
use app\services\mobile\dashboard\MobileMerchantDashboardServices;
use think\facade\Db;

(new think\App('/var/www/html/'))->initialize();
if ((string)config('database.connections.mysql.hostname') !== 'mysql') {
    throw new RuntimeException('拒绝连接非本地测试数据库');
}

$candidate = Db::query('SELECT e.id FROM eb_employee e LEFT JOIN eb_employee_data_scope s ON s.employee_id=e.id AND s.is_del=0 AND s.status=1 WHERE e.is_del=0 AND s.id IS NULL ORDER BY e.id DESC LIMIT 1');
$employeeId = (int)($candidate[0]['id'] ?? 0);
$allStoreIds = array_map('intval', Db::name('system_store')->where('is_del', 0)->order('id', 'desc')->limit(3)->column('id'));
$storeIds = array_slice($allStoreIds, 0, 2);
if ($employeeId <= 0 || count($allStoreIds) !== 3) {
    throw new RuntimeException('本地测试数据不足');
}

$service = app()->make(EmployeeDataScopeServices::class);
$session = (new ReflectionClass(MobileMerchantSessionServices::class))->newInstanceWithoutConstructor();
$isPersonalScope = new ReflectionMethod(MobileMerchantSessionServices::class, 'isPersonalScope');
$isPersonalScope->setAccessible(true);
$dashboard = (new ReflectionClass(MobileMerchantDashboardServices::class))->newInstanceWithoutConstructor();
$resolver = (new ReflectionClass(MobileMerchantRequestContextResolver::class))->newInstanceWithoutConstructor();
$requestActions = new ReflectionMethod(MobileMerchantRequestContextResolver::class, 'availableActions');
$requestActions->setAccessible(true);
$superAdmin = ['id' => 1, 'level' => 0, 'admin_type' => 0];
$payload = [
    'source_type' => EmployeeDataScopeServices::SOURCE_HQ,
    'source_store_id' => 0,
    'scope_mode' => EmployeeDataScopeServices::MODE_STORE_SELECTED,
    'store_ids' => $storeIds,
    'org_ids' => [],
];
$assert = static function (bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    echo 'PASS ' . $label . PHP_EOL;
};
$rejects = static function (callable $write, string $label) use ($assert): void {
    try {
        $write();
    } catch (Throwable $error) {
        $assert(true, $label);
        return;
    }
    $assert(false, $label);
};

Db::startTrans();
try {
    $defaultSuperWhere = [];
    $service->applyOrderListScope($defaultSuperWhere, $employeeId, 'admin', 0, $superAdmin);
    $assert((string)($defaultSuperWhere['employee_data_scope']['mode'] ?? '') === 'all', 'super-admin without explicit hq personal remains full scope');
    $saved = $service->saveScopeInTx($employeeId, $payload, $superAdmin, ['request_token' => 'local-selected-store-regression']);
    $expected = $storeIds;
    sort($expected);
    $assert($saved['store_ids'] === $expected && $saved['org_ids'] === [], 'persists only selected stores');
    $assert($service->resolveEffectiveStoreIds($employeeId, 0) === $expected, 'resolves exact selected stores');

    $legacyStoreSource = [
        'source_type' => EmployeeDataScopeServices::SOURCE_STORE,
        'source_store_id' => $allStoreIds[2],
        'scope_mode' => EmployeeDataScopeServices::MODE_STORE_SELF,
    ];
    $service->saveScopeInTx($employeeId, $legacyStoreSource, $superAdmin);
    $assert($service->resolveEffectiveStoreIds($employeeId, 0) === $expected, 'selected stores override other source grants');

    // 总部从“指定门店”改回“个人”时，保留的门店来源行不能重新授予整店数据。
    $personal = $payload;
    $personal['scope_mode'] = EmployeeDataScopeServices::MODE_PERSONAL;
    $personal['store_ids'] = [];
    $service->saveScopeInTx($employeeId, $personal, $superAdmin);
    $assert($service->resolveEffectiveStoreIds($employeeId, 0) === [], 'hq personal overrides legacy store grant');
    $assert($service->resolveEffectiveStoreIds($employeeId, 0, $superAdmin) === [], 'explicit hq personal also narrows a super-admin identity');
    $orderWhere = [];
    $service->applyOrderListScope($orderWhere, $employeeId, 'admin', 0, $superAdmin);
    $assert((string)($orderWhere['employee_data_scope']['mode'] ?? '') !== 'all', 'order reports do not bypass explicit hq personal');
    $assert($service->resolveEffectiveStoreIds($employeeId, $allStoreIds[2]) === [], 'hq personal stays personal inside a store context');
    $assert($isPersonalScope->invoke($session, $employeeId) === true, 'mobile session recognizes hq personal despite legacy store grant');
    $personalActions = $requestActions->invoke($resolver, ['rules' => '401009,401007'], 'PERSONAL_SELF');
    $storeActions = $requestActions->invoke($resolver, ['rules' => '401009,401007'], 'STORES');
    $assert(!in_array('MERCHANT_HOME_VIEW', $personalActions, true) && in_array('MERCHANT_WAREHOUSE_VIEW', $personalActions, true), 'personal mobile scope retains data entry but hides whole-store home');
    $assert(in_array('MERCHANT_HOME_VIEW', $storeActions, true), 'store scope retains granted home capability');
    $rejects(static function () use ($dashboard, $employeeId, $allStoreIds): void {
        $dashboard->home(['employeeId' => $employeeId, 'dataScopeMode' => 'PERSONAL_SELF', 'visibleStoreIds' => [$allStoreIds[2]]], []);
    }, 'personal request cannot read whole-store merchant home');
    $service->saveScopeInTx($employeeId, $payload, $superAdmin);
    $assert($service->resolveEffectiveStoreIds($employeeId, 0) === $expected, 'switching back to selected stores restores only explicit grants');
    $assert($isPersonalScope->invoke($session, $employeeId) === false, 'mobile session restores selected-store scope');

    Db::name('system_store')->where('id', $storeIds[0])->update(['is_del' => 1]);
    $assert($service->resolveEffectiveStoreIds($employeeId, 0) === [$storeIds[1]], 'deleted selected store stops resolving');
    Db::name('system_store')->where('id', $storeIds[0])->update(['is_del' => 0]);

    $invalid = $payload;
    $invalid['store_ids'] = [$storeIds[0], 999999999];
    $rejects(static fn() => $service->saveScopeInTx($employeeId, $invalid, $superAdmin), 'rejects nonexistent store');

    $rejects(static fn() => $service->saveScopeInTx($employeeId, $payload, ['id' => 999999999, 'level' => 1, 'admin_type' => 1]), 'rejects stores outside operator grant');

    $storeSource = $payload;
    $storeSource['source_type'] = EmployeeDataScopeServices::SOURCE_STORE;
    $storeSource['source_store_id'] = $storeIds[0];
    $rejects(static fn() => $service->saveScopeInTx($employeeId, $storeSource, $superAdmin), 'store source cannot grant cross-store scope');
} finally {
    Db::rollback();
}

$assert((int)Db::name('employee_data_scope')->where('employee_id', $employeeId)->where('is_del', 0)->where('status', 1)->count() === 0, 'transaction leaves no grant');
