<?php
/** Real controller/database regression. All synthetic business rows roll back. */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
use app\services\employee\EmployeePersonCompleteWriteServices;
use app\services\employee\EmployeeStaffWriteServices;
use app\services\store\SystemStoreStaffServices;

if (getenv('MOHE_LOCAL_DB_HOSTNAME') !== Db::query('SELECT @@hostname host')[0]['host']
    || config('database.connections.mysql.hostname') !== 'mysql') {
    throw new RuntimeException('Explicit local Docker database required');
}
class CraftsmanDefaultController extends \app\controller\admin\v1\merchant\SystemStoreStaff
{
    public function __construct(array $post, bool $allowed = true)
    {
        $this->request = (new \app\Request())->withPost($post)
            ->withHeader(['x-request-token' => $post['request_token']]);
        $this->services = app()->make(SystemStoreStaffServices::class);
        $this->adminId = $allowed ? 1 : 99999999;
        $this->adminType = 0;
        $this->agentId = 0;
        $this->adminInfo = ['id' => $this->adminId, 'level' => $allowed ? 0 : 1,
            'admin_type' => 0, 'account' => 'local-craftsman-test', 'roles' => ''];
    }
}
function craftCheck($ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    echo "PASS $label\n";
}
function craftToken(): string { return '50c00000-0000-4000-8000-' . bin2hex(random_bytes(6)); }

Db::startTrans();
try {
    $place = Db::name('organization_store')->alias('os')->join('system_store s', 's.id=os.store_id')
        ->join('organization o', 'o.id=os.org_id')->where('s.is_del', 0)->where('o.is_del', 0)
        ->field('os.store_id,os.org_id')->find();
    $employee = (int)Db::name('employee')->insertGetId([
        'name' => 'R50 craftsman rollback', 'phone' => '19900005083', 'status' => 1,
    ]);
    Db::name('organization_employee')->insert([
        'org_id' => $place['org_id'], 'employee_id' => $employee, 'source' => 'local-test',
    ]);
    $service = app()->make(EmployeePersonCompleteWriteServices::class);
    $read = $service->getComplete($employee, 0, 'hq', false);
    craftCheck($read['craftsman_performance_type'] === 'commission' && $read['craftsman_setting_version'] === 1,
        'unset legacy default and positive version');
    $post = ['staff_name' => $read['staff_name'], 'phone' => $read['phone'],
        'org_id' => $place['org_id'], 'store_id' => 0, 'scope_mode' => 'personal',
        'craftsman_performance_type' => 'commission_labor', 'craftsman_setting_version' => 1,
        'request_token' => craftToken()];
    $save = static function ($input, $allowed = true) use ($employee) {
        return app()->invokeMethod([new CraftsmanDefaultController($input, $allowed), 'savePersonComplete'],
            ['id' => $employee])->getData();
    };
    $first = $save($post);
    craftCheck($first['status'] === 200, 'real controller save: ' . ($first['msg'] ?? ''));
    craftCheck($first['data']['craftsman_performance_type'] === 'commission_labor'
        && $first['data']['craftsman_setting_version'] === 2, 'response reflects persisted setting');
    craftCheck($save($post)['status'] === 200, 'same request token replays success');
    $fresh = $service->getComplete($employee, 0, 'hq', false);
    craftCheck($fresh['craftsman_performance_type'] === 'commission_labor', 'fresh detail reads database');
    craftCheck(!Db::name('system_store_staff')->where('employee_id', $employee)->count(), 'no fake zero-store assignment');
    $rows = [['employee_id' => $employee, 'is_organization_direct' => 1],
        ['employee_id' => $employee, 'is_organization_direct' => 0, 'craftsman_performance_type' => 'labor']];
    $project = new ReflectionMethod(SystemStoreStaffServices::class, 'projectNoStoreCraftsmanSettings');
    $project->setAccessible(true);
    $project->invokeArgs(app()->make(SystemStoreStaffServices::class), [&$rows]);
    craftCheck($rows[0]['craftsman_performance_type'] === 'commission_labor'
        && $rows[1]['craftsman_performance_type'] === 'labor', 'list matches default; store projection unchanged');
    foreach (['missing', 'stale', 'invalid', 'denied'] as $case) {
        $bad = $post; $bad['request_token'] = craftToken();
        if ($case === 'missing') unset($bad['craftsman_setting_version']);
        if ($case === 'invalid') { $bad['craftsman_setting_version'] = 2; $bad['craftsman_performance_type'] = 'invalid'; }
        $out = $save($bad, $case !== 'denied');
        craftCheck($out['status'] !== 200, 'reject ' . $case . ': ' . $out['msg']);
    }
    $post['craftsman_setting_version'] = 2; $post['request_token'] = craftToken();
    craftCheck($save($post)['data']['craftsman_setting_version'] === 2, 'same value no new version');
    $post['craftsman_performance_type'] = 'labor'; $post['request_token'] = craftToken();
    craftCheck($save($post)['data']['craftsman_setting_version'] === 3, 'second edit persists');
    $auditCount = Db::name('employee_change_log')->where('employee_id', $employee)->where('action', 'craftsman_default_update')->count();
    craftCheck($auditCount === 2, 'exactly two setting audits');
    // A later orchestration failure must roll back the earlier default write.
    $post['craftsman_setting_version'] = 3; $post['craftsman_performance_type'] = 'commission';
    $post['scope_mode'] = 'store'; $post['request_token'] = craftToken();
    craftCheck($save($post)['status'] !== 200, 'post-write scope failure');
    craftCheck(Db::name('employee')->where('id', $employee)->value('default_craftsman_performance_type') === 'labor'
        && Db::name('employee_change_log')->where('employee_id', $employee)->where('action', 'craftsman_default_update')->count() === 2,
        'failed command rolls back setting and audit');

    // Phone transfer and other assignment writers omit the type: inherit once.
    $writer = app()->make(EmployeeStaffWriteServices::class);
    $assignment = ['store_id' => $place['store_id'], 'staff_name' => $read['staff_name'],
        'phone' => $read['phone'], 'status' => 1];
    $created = $writer->saveStaffAssignment(0, $assignment, ['source' => 'admin'], ['use_outer_transaction' => true]);
    $staff = (int)$created['staff_id'];
    craftCheck(Db::name('system_store_staff')->where('id', $staff)->value('craftsman_performance_type') === 'labor', 'new assignment inherits saved default');
    Db::name('system_store_staff')->where('id', $staff)->update(['craftsman_performance_type' => 'commission']);
    $writer->saveStaffAssignment($staff, $assignment, ['source' => 'admin'], ['use_outer_transaction' => true]);
    craftCheck(Db::name('system_store_staff')->where('id', $staff)->value('craftsman_performance_type') === 'commission', 'existing assignment is not overwritten');
} finally { Db::rollback(); }
craftCheck(!Db::name('employee')->where('id', $employee)->count(), 'all synthetic rows rolled back');
