<?php

declare(strict_types=1);

/** 第22轮跨组织岗位回归：只写本地 Docker 测试库，事务结束后全部回滚。 */
require '/var/www/html/vendor/autoload.php';

use app\services\report\EmployeeCurrentPositionDimensionServices;
use app\services\report\StoreUnifiedReportPhaseSixServices;
use app\services\report\StoreUnifiedReportPhaseTwoServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();
if (getenv('R22_CROSS_ORG_POSITION_LOCAL_TEST') !== '1'
    || config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('仅允许显式授权的本机 Docker 测试库');
}

function r22PositionAssert(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException('FAIL ' . $message);
    echo 'PASS ', $message, "\n";
}

function r22PositionToken(): string
{
    return '22000000-0000-4000-8000-' . bin2hex(random_bytes(6));
}

Db::startTrans();
try {
    $storeIds = array_map('intval', Db::name('system_store')->where('is_del', 0)
        ->order('id', 'asc')->limit(2)->column('id'));
    r22PositionAssert(count($storeIds) === 2, '取得两个本地门店作为跨组织边界');
    [$homeStoreId, $businessStoreId] = $storeIds;

    $employeeId = (int)Db::name('employee')->insertGetId([
        'name' => 'R22跨组织员工', 'phone' => '19900220001',
        'employment_type_code' => 'internal', 'employment_type_version' => 1,
        'status' => 1, 'is_del' => 0,
    ]);
    $staffId = (int)Db::name('system_store_staff')->insertGetId([
        'employee_id' => $employeeId, 'store_id' => $homeStoreId,
        'staff_name' => 'R22跨组织员工', 'phone' => '19900220001',
        'status' => 1, 'is_del' => 0,
    ]);
    $homePositionId = (int)Db::name('position')->insertGetId([
        'name' => 'R22原组织岗位', 'status' => 1, 'remark' => 'R22本地回滚夹具',
    ]);
    $directPositionId = (int)Db::name('position')->insertGetId([
        'name' => 'R22直属岗位', 'status' => 1, 'remark' => 'R22本地回滚夹具',
    ]);
    Db::name('staff_job_position')->insert([
        'employee_id' => $employeeId, 'store_id' => $homeStoreId, 'staff_id' => $staffId,
        'position_id' => $homePositionId, 'status' => 1, 'start_time' => time() - 100,
        'request_token' => r22PositionToken(), 'operator_name' => 'R22',
    ]);
    Db::name('staff_job_position')->insert([
        'employee_id' => $employeeId, 'store_id' => 0, 'staff_id' => 0,
        'position_id' => $directPositionId, 'status' => 1, 'start_time' => time() - 100,
        'request_token' => r22PositionToken(), 'operator_name' => 'R22',
    ]);

    $reader = new EmployeeCurrentPositionDimensionServices();
    $labels = $reader->resolve([
        ['store_id' => $homeStoreId, 'employee_id' => $employeeId],
        ['store_id' => $businessStoreId, 'employee_id' => $employeeId],
    ]);
    r22PositionAssert(($labels[$homeStoreId . '|' . $employeeId] ?? '') === 'R22原组织岗位',
        '业务门店有任职时优先显示该门店岗位');
    r22PositionAssert(($labels[$businessStoreId . '|' . $employeeId] ?? '') === 'R22直属岗位',
        '跨组织业务没有本店任职时显示员工直属岗位');

    Db::name('staff_job_position')->where('employee_id', $employeeId)->where('staff_id', 0)
        ->update(['status' => 0, 'end_time' => time()]);
    $fallback = $reader->resolve([['store_id' => $businessStoreId, 'employee_id' => $employeeId]]);
    r22PositionAssert(($fallback[$businessStoreId . '|' . $employeeId] ?? '') === 'R22原组织岗位',
        '没有直属岗位时稳定回退其他组织的有效任职岗位');

    Db::name('system_store_staff')->where('id', $staffId)->update(['status' => 0]);
    $inactive = $reader->resolve([['store_id' => $businessStoreId, 'employee_id' => $employeeId]]);
    r22PositionAssert(!isset($inactive[$businessStoreId . '|' . $employeeId]),
        '失效员工关系对应的旧岗位不再显示');

    $salarySource = file_get_contents('/var/www/html/app/services/report/StoreUnifiedReportPhaseSixServices.php');
    $largeOrderSource = file_get_contents('/var/www/html/app/services/report/StoreUnifiedReportPhaseTwoServices.php');
    r22PositionAssert(substr_count($salarySource, 'new EmployeeCurrentPositionDimensionServices()') === 1,
        '薪资汇总和明细共用同一岗位事实投影');
    r22PositionAssert(strpos($largeOrderSource, "'salesperson' => '销售人/销售经理', 'position_name' => '岗位'") !== false,
        '大单统计把岗位紧邻销售人列并复用页面导出列契约');

    $range = ['start' => '2026-10-01', 'end' => '2026-10-18'];
    $phaseSix = new StoreUnifiedReportPhaseSixServices();
    $summary = $phaseSix->query('phase_six_salary_summary', [$businessStoreId], $range, ['_internal_all' => true]);
    $detail = $phaseSix->query('phase_six_salary_detail', [$businessStoreId], $range, ['_internal_all' => true]);
    $largeOrder = (new StoreUnifiedReportPhaseTwoServices())->query(
        'salesperson_large_order_statistics', [$businessStoreId], $range, ['_internal_all' => true]
    );
    $columnKeys = static fn(array $report): array => array_column($report['columns'] ?? [], 'key');
    r22PositionAssert(in_array('position_name', $columnKeys($summary), true)
        && in_array('position_name', $columnKeys($detail), true),
        '薪资汇总和明细运行时都返回岗位列');
    r22PositionAssert(strpos((string)($summary['metric_version'] ?? ''), 'current-position-v2') !== false
        && ($summary['metric_version'] ?? '') === ($detail['metric_version'] ?? ''),
        '薪资汇总和明细共享跨组织当前岗位口径版本');
    $largeOrderKeys = $columnKeys($largeOrder);
    r22PositionAssert(($largeOrderKeys[array_search('salesperson', $largeOrderKeys, true) + 1] ?? '') === 'position_name',
        '大单统计运行时岗位列紧邻销售人且查询成功');
} finally {
    Db::rollback();
}

r22PositionAssert(!Db::name('employee')->where('phone', '19900220001')->find(), '全部本地测试夹具已回滚');
echo "R22_CROSS_ORGANIZATION_POSITION=PASS\n";
