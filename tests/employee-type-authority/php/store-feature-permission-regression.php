<?php
declare(strict_types=1);

/**
 * 仅供指定本地数据库的事务回滚回归，不创建员工、不永久修改现有类型。
 * 环境必须明确给出数据库名、MySQL主机指纹与目标任职，防止误跑生产实例。
 * 入口身份和功能权限由已有中间件负责；此处测试同一人员类型事务及目标边界。
 */
if (getenv('STAFF_PERMISSION_LOCAL_TEST') !== '1') {
    throw new RuntimeException('explicit local-only test flag required');
}
error_reporting(E_ERROR | E_PARSE);
$root = getenv('MEIYE_TEST_BACKEND_ROOT') ?: '/var/www/html';
require_once $root . '/vendor/autoload.php';
(new think\App())->initialize();

use app\services\employee\EmployeeTypeAuthorityServices;
use app\http\middleware\cashier\CashierCheckRoleMiddleware;
use think\facade\Db;

function sfpAssert(string $name, bool $condition): void
{
    if (!$condition) throw new RuntimeException('FAIL ' . $name);
    echo 'PASS ', $name, "\n";
}

function sfpRejection(callable $call, string $message): bool
{
    try { $call(); } catch (Throwable $e) { return strpos($e->getMessage(), $message) !== false; }
    return false;
}

try {
    $connection = Db::query('SELECT DATABASE() AS db,@@hostname AS host')[0];
    sfpAssert('explicit local database and host fingerprint',
        getenv('STAFF_PERMISSION_TEST_DB') !== false
        && getenv('STAFF_PERMISSION_TEST_HOST') !== false
        && $connection['db'] === getenv('STAFF_PERMISSION_TEST_DB')
        && $connection['host'] === getenv('STAFF_PERMISSION_TEST_HOST'));
    $staffId = (int)getenv('STAFF_PERMISSION_TEST_TARGET_STAFF');
    $operatorId = (int)getenv('STAFF_PERMISSION_TEST_OPERATOR');
    $staff = Db::name('system_store_staff')->where('id', $staffId)->where('is_del', 0)->find();
    sfpAssert('existing local target and organization session', $staff !== null && $operatorId > 0);
    $storeId = (int)$staff['store_id'];
    $employeeId = (int)$staff['employee_id'];
    $session = Db::name('cashier_v3_store_session')->where('id', $operatorId)
        ->where('store_id', $storeId)->where('status', 1)->where('is_del', 0)->find();
    sfpAssert('organization session id has no matching current-store assignment', $session !== null
        && Db::name('system_store_staff')->where('id', $operatorId)->where('store_id', $storeId)
            ->where('status', 1)->where('is_del', 0)->count() === 0);
    $before = Db::name('employee')->where('id', $employeeId)->find();
    $beforeHash = hash('sha256', json_encode($before));
    $auditCount = Db::name('employee_change_log')->where('employee_id', $employeeId)->count();
    $service = app()->make(EmployeeTypeAuthorityServices::class);
    $admin = ['id' => $operatorId, 'admin_type' => 3, 'level' => 1];
    $audit = ['operator_id' => $operatorId, 'operator_name' => 'local rollback regression',
        'request_id' => 'r52-staff-feature-local-only', 'operator_ip' => '127.0.0.1'];

    // 必须始终回滚：同时覆盖真实保存、审计与旧版本保护，不能给业务数据留测试变更。
    Db::startTrans();
    try {
        $code = (string)$before['employment_type_code'];
        $version = (int)$before['employment_type_version'];
        $same = $service->saveTypeInTx($employeeId, $code, $version, $admin, $audit, 'store', $staffId, $storeId);
        sfpAssert('authorized organization operator can save unchanged type',
            !$same['changed'] && $same['employment_type_version'] === $version);
        $newCode = $code === 'partner' ? 'internal' : 'partner';
        $changed = $service->saveTypeInTx($employeeId, $newCode, $version, $admin, $audit, 'store', $staffId, $storeId);
        sfpAssert('real type update advances version once', $changed['changed']
            && $changed['employment_type_code'] === $newCode && $changed['employment_type_version'] === $version + 1);
        $log = Db::name('employee_change_log')->where('employee_id', $employeeId)
            ->where('request_id', $audit['request_id'])->order('id desc')->find();
        sfpAssert('type update keeps store audit and operator identity', $log !== null
            && $log['source'] === 'store' && (int)$log['operator_id'] === $operatorId);
        sfpAssert('stale version cannot overwrite', sfpRejection(function () use ($service, $employeeId, $newCode, $version, $admin, $audit, $staffId, $storeId): void {
            $service->saveTypeInTx($employeeId, $newCode, $version, $admin, $audit, 'store', $staffId, $storeId);
        }, '人员类型已被其他操作修改'));
        sfpAssert('target outside forced store is rejected', sfpRejection(function () use ($service, $employeeId, $newCode, $version, $admin, $audit, $staffId, $storeId): void {
            $service->saveTypeInTx($employeeId, $newCode, $version + 1, $admin, $audit, 'store', $staffId, $storeId + 1);
        }, '员工不属于当前门店'));
        sfpAssert('forged target employee is rejected', sfpRejection(function () use ($service, $employeeId, $newCode, $version, $admin, $audit, $staffId, $storeId): void {
            $service->saveTypeInTx($employeeId + 1, $newCode, $version + 1, $admin, $audit, 'store', $staffId, $storeId);
        }, '员工不属于当前门店'));
        sfpAssert('missing authenticated identity is rejected', sfpRejection(function () use ($service, $employeeId, $staffId, $storeId): void {
            $service->assertStoreManagePermission([], $employeeId, $staffId, $storeId);
        }, '无法确认门店任职'));
        sfpAssert('HQ type permission remains independent', sfpRejection(function () use ($service, $admin): void {
            $service->assertManagePermission($admin);
        }, '当前账号无人员类型管理权限'));
    } finally {
        Db::rollback();
    }
    sfpAssert('employee fields and audit count unchanged after rollback',
        hash('sha256', json_encode(Db::name('employee')->where('id', $employeeId)->find())) === $beforeHash
        && Db::name('employee_change_log')->where('employee_id', $employeeId)->count() === $auditCount);

    // 入口仍沿用统一权限，防止仅删除服务限制却意外删除新增/编辑功能门禁。
    $reflection = new ReflectionClass(CashierCheckRoleMiddleware::class);
    $gate = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('directOperationFeature');
    $method->setAccessible(true);
    sfpAssert('existing staff edit and create route feature mapping is unchanged',
        $method->invoke($gate, 'cashierapi/v3/management/staff/620', 'POST') === 'cashier.v3.staff.edit'
        && $method->invoke($gate, 'cashierapi/v3/management/staff/0', 'POST') === 'cashier.v3.staff.create');
    $middleware = file_get_contents($root . '/app/http/middleware/cashier/CashierCheckRoleMiddleware.php');
    $authority = file_get_contents($root . '/app/services/employee/EmployeeTypeAuthorityServices.php');
    sfpAssert('feature denial and read-only session gate remain; obsolete operator lookup removed',
        strpos($middleware, "!in_array(\$requiredFeature, \$features, true)") !== false
        && strpos($middleware, "!empty(\$cashierInfo['_cashier_v3_delegated'])") !== false
        && strpos($authority, '当前账号无本门店人员类型管理权限') === false
        && strpos($authority, '$operatorStaffId') === false);
    echo "STORE_FEATURE_PERMISSION_REGRESSION=PASS\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
