<?php
/**
 * 本地事务回归：指定门店只授权显式 ID，拒绝无效/越权 ID，结束时回滚全部权限和审计写入。
 * 仅允许在本机 mohe-mysql 容器内运行，绝不连接客户线上实例。
 */
declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';

use app\services\organization\EmployeeDataScopeServices;
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
