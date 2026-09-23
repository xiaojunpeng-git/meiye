<?php

/**
 * 员工离职本地事务回归：仅在显式测试库执行，所有员工、任职、权限及记录写入最终回滚。
 * 覆盖状态版本、离职记录、重复保存幂等、离职看板计数，以及原状态完整恢复。
 */

require '/var/www/html/vendor/autoload.php';

use app\services\employee\EmployeeStaffWriteServices;
use app\services\employee\EmployeePersonCompleteWriteServices;
use app\services\report\EmployeeDashboardServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->initialize();
$database = (string)Db::query('SELECT DATABASE() AS db')[0]['db'];
if ($database === '' || $database !== (string)getenv('EMPLOYEE_DEPARTURE_TEST_DB')) {
    throw new RuntimeException('测试库名称未显式匹配，拒绝运行离职写入回归');
}

$staff = Db::name('system_store_staff')->alias('ss')
    ->join('employee e', 'e.id=ss.employee_id')
    ->where('ss.status', 1)->where('ss.is_del', 0)
    ->where('e.status', 1)->where('e.is_del', 0)
    ->field('ss.*,e.status employee_status,e.status_version')
    ->order('ss.id', 'asc')->find();
if (!$staff) {
    throw new RuntimeException('没有可供回滚测试的在职员工');
}
$employeeId = (int)$staff['employee_id'];
$staffId = (int)$staff['id'];
$storeId = (int)$staff['store_id'];
$version = (int)$staff['status_version'];
$logBefore = (int)Db::name('employee_change_log')->where('employee_id', $employeeId)
    ->where('action', EmployeeStaffWriteServices::ACTION_GLOBAL_LEAVE)->count();

Db::startTrans();
try {
    $service = app()->make(EmployeeStaffWriteServices::class);
    $ctx = [
        'operator_id' => 1,
        'operator_name' => '本地事务测试',
        'operator_ip' => '127.0.0.1',
        'request_id' => 'employee-departure-local-rollback',
        'reason' => '人员编辑切换为离职',
    ];
    $first = $service->syncEmploymentStatusInCurrentTransaction($employeeId, 0, $version, $ctx);
    if ($first['status'] !== 0 || $first['status_version'] !== $version + 1 || !$first['changed']) {
        throw new RuntimeException('首次离职或状态版本递增失败');
    }
    $departure = Db::name('staff_tenure_period')->where('employee_id', $employeeId)
        ->where('staff_id', $staffId)->where('action', 'leave')->where('is_del', 0)
        ->order('id', 'desc')->find();
    if (!$departure || (int)$departure['end_time'] <= 0) {
        throw new RuntimeException('未生成可统计的离职任职事实');
    }
    $same = $service->syncEmploymentStatusInCurrentTransaction($employeeId, 0, $version + 1, $ctx);
    if ($same['changed'] || $same['status_version'] !== $version + 1) {
        throw new RuntimeException('重复离职不应新增记录或递增版本');
    }
    $logAfter = (int)Db::name('employee_change_log')->where('employee_id', $employeeId)
        ->where('action', EmployeeStaffWriteServices::ACTION_GLOBAL_LEAVE)->count();
    if ($logAfter !== $logBefore + 1) {
        throw new RuntimeException('离职审计记录数量不正确');
    }
    $staleRejected = false;
    try {
        $service->syncEmploymentStatusInCurrentTransaction($employeeId, 1, $version, $ctx);
    } catch (Throwable $error) {
        $staleRejected = strpos($error->getMessage(), '在职状态已被其他人修改') !== false;
    }
    if (!$staleRejected) {
        throw new RuntimeException('旧状态版本未被拒绝');
    }
    $dashboard = app()->make(EmployeeDashboardServices::class);
    $count = new ReflectionMethod($dashboard, 'departureCount');
    $count->setAccessible(true);
    $periodCount = $count->invoke($dashboard, [$storeId], [
        'start' => date('Y-m-d'),
        'end' => date('Y-m-d'),
    ], '');
    if ($periodCount < 1) {
        throw new RuntimeException('员工看板未统计本次离职事实');
    }
    // 真实完整保存会先恢复当前任职，再同步员工主状态；测试按相同顺序
    // 模拟，避免把主状态服务误测成“自动恢复全部账号与权限”。
    Db::name('system_store_staff')->where('id', $staffId)->update(['status' => 1]);
    $resume = $service->syncEmploymentStatusInCurrentTransaction($employeeId, 1, $version + 1, $ctx);
    $activeTenure = Db::name('staff_tenure_period')->where('employee_id', $employeeId)
        ->where('staff_id', $staffId)->where('status', 1)->where('end_time', 0)->where('is_del', 0)->find();
    if ($resume['status'] !== 1 || $resume['status_version'] !== $version + 2 || !$activeTenure) {
        throw new RuntimeException('恢复在职或新任职期间创建失败');
    }
    echo "PASS 状态版本/版本冲突/离职事实/重复保存/恢复在职/审计/看板流失人数\n";
} finally {
    Db::rollback();
}

$restoredEmployee = Db::name('employee')->where('id', $employeeId)->field('status,status_version')->find();
$restoredStaff = Db::name('system_store_staff')->where('id', $staffId)->field('status')->find();
if ((int)$restoredEmployee['status'] !== 1 || (int)$restoredEmployee['status_version'] !== $version
    || (int)$restoredStaff['status'] !== 1) {
    throw new RuntimeException('事务回滚后员工或任职状态未恢复');
}
echo "PASS 回滚恢复\n";

// 页面保存走的是人员完整保存编排，不能只验证底层状态切换。这里使用同一份
// 详情契约构造编辑请求，并在外层事务中验证离职事实后整体回滚，避免测试污染人员档案。
Db::startTrans();
try {
    /** @var EmployeePersonCompleteWriteServices $complete */
    $complete = app()->make(EmployeePersonCompleteWriteServices::class);
    $detail = $complete->getComplete($employeeId, $staffId, 'hq', true);
    $token = sprintf('00000000-0000-4000-8000-%012d', $employeeId);
    $input = [
        'employee_id' => $employeeId,
        'staff_id' => $staffId,
        'org_id' => (int)($detail['org_id'] ?? 0),
        'store_id' => (int)($detail['store_id'] ?? 0),
        'staff_name' => (string)($detail['staff_name'] ?? ''),
        'phone' => (string)($detail['phone'] ?? ''),
        'avatar' => (string)($detail['avatar'] ?? ''),
        'scope_mode' => (string)($detail['scope']['scope_mode'] ?? 'personal'),
        'org_ids' => (array)($detail['scope']['org_ids'] ?? []),
        'store_ids' => [],
        'can_choose' => (int)($detail['can_choose'] ?? 1),
        'cashier_salesperson_enabled' => (int)($detail['cashier_salesperson_enabled'] ?? 1),
        'cashier_craftsman_enabled' => (int)($detail['cashier_craftsman_enabled'] ?? 1),
        'craftsman_performance_type' => (string)($detail['craftsman_performance_type'] ?? 'commission'),
        'is_fencheng' => (int)($detail['is_fencheng'] ?? 0),
        'mobile_enabled' => (int)($detail['mobile_enabled'] ?? 0),
        'employment_type_code' => (string)($detail['employment_type_code'] ?? 'internal'),
        'employment_type_version' => (int)($detail['employment_type_version'] ?? 0),
        'education' => (string)($detail['education'] ?? ''),
        'education_version' => (int)($detail['education_version'] ?? 0),
        'status' => 0,
        'status_version' => (int)($detail['status_version'] ?? 0),
        'request_token' => $token,
    ];
    $result = $complete->saveComplete($input, [
        'id' => 1,
        'account' => 'local-regression',
        'real_name' => '本地事务测试',
        'level' => 0,
        'admin_type' => 0,
    ], [
        'header_token' => $token,
        'body_token' => $token,
        'operator_ip' => '127.0.0.1',
    ], 'hq');
    if ((int)($result['data']['status'] ?? 1) !== 0
        || empty($result['data']['departure_records'])) {
        throw new RuntimeException('人员完整保存未生成离职状态或离职记录');
    }
    echo "PASS 人员完整编辑保存离职\n";
} finally {
    Db::rollback();
}

$restoredAfterComplete = Db::name('employee')->where('id', $employeeId)
    ->field('status,status_version')->find();
if ((int)$restoredAfterComplete['status'] !== 1
    || (int)$restoredAfterComplete['status_version'] !== $version) {
    throw new RuntimeException('完整保存回滚后员工状态未恢复');
}
echo "PASS 人员完整保存回滚恢复\n";
