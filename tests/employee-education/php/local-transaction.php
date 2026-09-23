<?php

/**
 * 员工学历本地事务回归：只在显式指定的测试库运行，所有写入及审计最终回滚。
 * 覆盖保存、同值幂等、旧版本拒绝、审计，以及报表六档投影。
 */

require '/var/www/html/vendor/autoload.php';

use app\services\employee\EmployeePersonCompleteWriteServices;
use app\services\report\EmployeeDashboardServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->initialize();
$database = (string)Db::query('SELECT DATABASE() AS db')[0]['db'];
if ($database === '' || $database !== (string)getenv('EMPLOYEE_EDUCATION_TEST_DB')) {
    throw new RuntimeException('测试库名称未显式匹配，拒绝运行写入回归');
}

$employee = Db::name('employee')->where('is_del', 0)->where('education', '')
    ->field('id,education,education_version')->find();
if (!$employee) {
    throw new RuntimeException('没有可供回滚测试的未填写学历员工');
}
$id = (int)$employee['id'];
$version = (int)$employee['education_version'];
$service = app()->make(EmployeePersonCompleteWriteServices::class);
$save = new ReflectionMethod($service, 'saveEducationInTx');
$save->setAccessible(true);
$audit = ['operator_id' => 1, 'operator_name' => '本地事务测试',
    'operator_ip' => '127.0.0.1', 'request_id' => 'employee-education-local-rollback'];
$auditBefore = (int)Db::name('employee_change_log')->where('employee_id', $id)
    ->where('action', EmployeePersonCompleteWriteServices::ACTION_EDUCATION)->count();

Db::startTrans();
try {
    $first = $save->invoke($service, $id, '本科以上', $version, $audit);
    if ($first['education'] !== '本科以上' || $first['education_version'] !== $version + 1) {
        throw new RuntimeException('学历首次保存或版本递增失败');
    }
    $readback = Db::name('employee')->where('id', $id)->field('education,education_version')->find();
    if ($readback['education'] !== '本科以上' || (int)$readback['education_version'] !== $version + 1) {
        throw new RuntimeException('数据库读回与保存结果不一致');
    }
    $same = $save->invoke($service, $id, '本科以上', $version + 1, $audit);
    if ($same['education_version'] !== $version + 1) {
        throw new RuntimeException('同值保存不应递增版本');
    }
    $staleRejected = false;
    try {
        $save->invoke($service, $id, '大专', $version, $audit);
    } catch (Throwable $error) {
        $staleRejected = strpos($error->getMessage(), '学历已被其他人修改') !== false;
    }
    if (!$staleRejected) {
        throw new RuntimeException('旧版本写入未被拒绝');
    }
    $auditAfter = (int)Db::name('employee_change_log')->where('employee_id', $id)
        ->where('action', EmployeePersonCompleteWriteServices::ACTION_EDUCATION)->count();
    if ($auditAfter !== $auditBefore + 1) {
        throw new RuntimeException('审计记录数量不正确');
    }

    // 同一员工跨任职只计一次；未填写的历史值归“其他”，六档顺序与产品选项一致。
    $report = app()->make(EmployeeDashboardServices::class);
    $overview = new ReflectionMethod($report, 'overview');
    $overview->setAccessible(true);
    $rows = $overview->invoke($report, [
        ['employee_id' => $id, 'education' => '本科以上'],
        ['employee_id' => $id, 'education' => '本科以上'],
        ['employee_id' => $id + 1000000, 'education' => ''],
    ], date('Y-m-d'))['education'];
    $labels = array_column($rows, 'label');
    if ($labels !== EmployeePersonCompleteWriteServices::EDUCATION_LEVELS
        || array_sum(array_column($rows, 'value')) !== 2
        || $rows[0]['value'] !== 1 || $rows[5]['value'] !== 1) {
        throw new RuntimeException('报表六档投影、历史空值或去重不正确');
    }
    echo "PASS 保存/读回/版本冲突/同值幂等/审计/报表六档\n";
} finally {
    Db::rollback();
}

$restored = Db::name('employee')->where('id', $id)->field('education,education_version')->find();
$auditRestored = (int)Db::name('employee_change_log')->where('employee_id', $id)
    ->where('action', EmployeePersonCompleteWriteServices::ACTION_EDUCATION)->count();
if ($restored['education'] !== $employee['education']
    || (int)$restored['education_version'] !== $version || $auditRestored !== $auditBefore) {
    throw new RuntimeException('回滚后员工主档或审计未恢复');
}
echo "PASS 事务回滚后主档与审计恢复原状\n";
