<?php
declare(strict_types=1);

/**
 * 单一所属组织真实事务回归：仅允许本地 Docker ruihao 库，全部夹具最终回滚。
 * 测试正式保存服务只替换 organization_employee，不得改动管理员授权、门店任职或岗位。
 */
require '/var/www/html/vendor/autoload.php';

use app\services\organization\OrganizationEmployeeServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();
if (getenv('SINGLE_ORGANIZATION_LOCAL_TEST') !== '1'
    || config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('仅允许显式授权的本机 Docker 测试库');
}

function singleOrganizationAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $name);
    }
    echo 'PASS ', $name, "\n";
}

Db::startTrans();
try {
    $organizations = Db::name('organization')
        ->where('is_del', 0)
        ->order('id', 'asc')
        ->limit(2)
        ->field('id')
        ->select()
        ->toArray();
    if (count($organizations) < 2) {
        throw new RuntimeException('本地库缺少两个可用组织');
    }
    $orgA = (int)$organizations[0]['id'];
    $orgB = (int)$organizations[1]['id'];
    $now = time();
    $employeeId = (int)Db::name('employee')->insertGetId([
        'name' => '单一所属组织回滚测试',
        'phone' => '19900007221',
        'status' => 1,
        'is_del' => 0,
        'add_time' => $now,
        'update_time' => $now,
    ]);
    $oldMembershipId = (int)Db::name('organization_employee')->insertGetId([
        'org_id' => $orgA,
        'employee_id' => $employeeId,
        'job_title' => '旧所属组织',
        'source' => 'local-rollback-test',
        'status' => 1,
        'is_del' => 0,
        'add_time' => $now,
        'update_time' => $now,
    ]);

    // 独立关系使用最小合法夹具，避免测试结果依赖本地库是否已有样本。
    Db::name('organization_admin')->insert([
        'employee_id' => $employeeId,
        'org_id' => $orgA,
        'name' => '单一所属组织回滚测试',
        'phone' => '19900007221',
        'scope_mode' => 'inherit',
        'add_time' => $now,
        'update_time' => $now,
    ]);
    $staffId = (int)Db::name('system_store_staff')->insertGetId([
        'employee_id' => $employeeId,
        'store_id' => 1,
        'staff_name' => '单一所属组织回滚测试',
        'phone' => '19900007221',
        'status' => 1,
        'is_del' => 0,
        'add_time' => $now,
    ]);
    Db::name('staff_job_position')->insert([
        'employee_id' => $employeeId,
        'store_id' => 1,
        'staff_id' => $staffId,
        'position_id' => 1,
        'status' => 1,
        'start_time' => $now,
        'request_token' => 'single-org-' . bin2hex(random_bytes(8)),
        'add_time' => $now,
        'update_time' => $now,
    ]);

    $before = [
        'admins' => (int)Db::name('organization_admin')->where('employee_id', $employeeId)->count(),
        'staff' => (int)Db::name('system_store_staff')->where('employee_id', $employeeId)->count(),
        'positions' => (int)Db::name('staff_job_position')->where('employee_id', $employeeId)->count(),
    ];

    /** @var OrganizationEmployeeServices $service */
    $service = app()->make(OrganizationEmployeeServices::class);
    $saveCurrentOrganization = static function () use ($service, $orgB, $employeeId): array {
        return $service->save([
        'org_id' => $orgB,
        'employee_id' => $employeeId,
        'job_title' => '当前所属组织',
        'status' => 1,
    ], [
        'source' => 'local-rollback-test',
        'operator_id' => 1,
        'operator_name' => 'Codex local test',
        'request_id' => 'single-org-' . bin2hex(random_bytes(8)),
    ], ['use_outer_transaction' => true]);
    };

    // 模拟完整人员保存后置步骤失败，组织替换和审计必须跟随外层事务回滚。
    Db::execute('SAVEPOINT single_organization_failure');
    $saveCurrentOrganization();
    Db::execute('ROLLBACK TO SAVEPOINT single_organization_failure');
    singleOrganizationAssert((int)Db::name('organization_employee')->where('id', $oldMembershipId)
        ->where('is_del', 0)->where('status', 1)->count() === 1
        && (int)Db::name('organization_employee')->where('employee_id', $employeeId)
            ->where('org_id', $orgB)->count() === 0
        && (int)Db::name('employee_change_log')->where('employee_id', $employeeId)->count() === 0,
        '外层事务失败完整回滚组织替换和审计');

    $result = $saveCurrentOrganization();

    $active = Db::name('organization_employee')
        ->where('employee_id', $employeeId)
        ->where('is_del', 0)
        ->where('status', 1)
        ->select()
        ->toArray();
    singleOrganizationAssert(count($active) === 1 && (int)$active[0]['org_id'] === $orgB,
        '切换后只保留新组织为当前所属');
    $old = Db::name('organization_employee')->where('id', $oldMembershipId)->find();
    singleOrganizationAssert((int)$old['is_del'] === 1 && (int)$old['status'] === 0,
        '旧所属关系软关闭且历史记录保留');
    singleOrganizationAssert($result['replaced_org_employee_ids'] === [$oldMembershipId],
        '保存结果返回被替换关系');
    singleOrganizationAssert((int)Db::name('employee_change_log')
        ->where('employee_id', $employeeId)
        ->where('action', 'organization_employee_replace')
        ->where('target_id', $oldMembershipId)
        ->count() === 1, '组织替换生成独立审计');
    singleOrganizationAssert($before === [
        'admins' => (int)Db::name('organization_admin')->where('employee_id', $employeeId)->count(),
        'staff' => (int)Db::name('system_store_staff')->where('employee_id', $employeeId)->count(),
        'positions' => (int)Db::name('staff_job_position')->where('employee_id', $employeeId)->count(),
    ], '组织管理授权、门店任职和岗位完全不变');
} finally {
    Db::rollback();
}

singleOrganizationAssert(!Db::name('employee')->where('phone', '19900007221')->find(), '全部测试数据回滚');
