<?php
/** 当前门店清空真实控制器/真库回归：仅本地 Docker，所有夹具与写入最终回滚。 */
require '/var/www/html/vendor/autoload.php';

use app\services\employee\EmployeePersonCompleteWriteServices;
use app\services\organization\EmployeeDataScopeServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();
if (getenv('PERSON_CLEAR_STORE_LOCAL_TEST') !== '1'
    || config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('仅允许显式授权的本机 Docker 测试库');
}

class ClearStoreLocalController extends \app\controller\admin\v1\merchant\SystemStoreStaff
{
    /** 只提供测试身份与请求，不替换权限、业务、幂等或事务方法。 */
    public function __construct(array $post, int $adminType = 0, int $agentId = 0)
    {
        $this->request = (new \app\Request())->withPost($post)
            ->withHeader(['x-request-token' => $post['request_token']]);
        $this->services = app()->make(\app\services\store\SystemStoreStaffServices::class);
        $this->adminId = 1;
        $this->adminType = $adminType;
        $this->agentId = $agentId;
        $this->adminInfo = ['id' => 1, 'level' => 0, 'admin_type' => $adminType,
            'account' => 'local-clear-test', 'real_name' => '本地清空任职测试'];
    }
}
function clearStoreAssert(bool $ok, string $name): void
{
    if (!$ok) throw new RuntimeException('FAIL ' . $name);
    echo 'PASS ', $name, "\n";
}
function clearStoreToken(): string
{
    return '70b00000-0000-4000-8000-' . bin2hex(random_bytes(6));
}

Db::startTrans();
try {
    $store = Db::name('organization_store')->alias('os')
        ->join('system_store s', 's.id=os.store_id')->join('organization o', 'o.id=os.org_id')
        ->where('s.is_del', 0)->where('o.is_del', 0)->field('os.store_id,os.org_id')->find();
    $storeId = (int)$store['store_id'];
    $orgId = (int)$store['org_id'];
    $employeeId = (int)Db::name('employee')->insertGetId([
        'name' => 'R7清空任职事务测试', 'phone' => '19900007103', 'auth_version' => 1,
        'employment_type_code' => 'internal', 'employment_type_version' => 1, 'status' => 1,
    ]);
    Db::name('organization_employee')->insert([
        'org_id' => $orgId, 'employee_id' => $employeeId, 'source' => 'local-clear-test',
    ]);
    $staff = Db::name('system_store_staff')->where('status', 1)->where('is_del', 0)->find();
    unset($staff['id'], $staff['active_employee_id']);
    $staff = array_merge($staff, ['employee_id' => $employeeId, 'store_id' => $storeId,
        'staff_name' => 'R7清空任职事务测试', 'phone' => '19900007103', 'uid' => 0,
        'account' => '', 'pwd' => '', 'roles' => '', 'status' => 1, 'is_del' => 0,
        'is_cashier' => 1, 'is_manager' => 1, 'is_store' => 1, 'order_status' => 1]);
    $staffId = (int)Db::name('system_store_staff')->insertGetId($staff);
    $positionId = (int)Db::name('position')->where('status', 1)->value('id');
    Db::name('staff_job_position')->insert([
        'employee_id' => $employeeId, 'store_id' => $storeId, 'staff_id' => $staffId,
        'position_id' => $positionId, 'request_token' => clearStoreToken(), 'start_time' => time() - 100,
    ]);
    // 员工原有总部岗位必须保留；门店岗位不能被清空操作复制到总部。
    Db::name('staff_job_position')->insert([
        'employee_id' => $employeeId, 'store_id' => 0, 'staff_id' => 0,
        'position_id' => $positionId, 'request_token' => clearStoreToken(), 'start_time' => time() - 100,
    ]);
    foreach (['store', 'mobile'] as $channel) {
        Db::name('staff_channel_entry')->insert([
            'employee_id' => $employeeId, 'store_id' => $storeId, 'staff_id' => $staffId,
            'channel' => $channel, 'status' => 1, 'request_token' => clearStoreToken(),
        ]);
    }
    Db::name('employee_merchant_session')->insert([
        'employee_id' => $employeeId, 'store_id' => $storeId, 'token_hash' => hash('sha256', clearStoreToken()),
        'auth_version' => 1, 'expire_time' => time() + 3600,
    ]);
    $scopeId = (int)Db::name('employee_data_scope')->insertGetId([
        'employee_id' => $employeeId, 'source_type' => 'store', 'source_store_id' => $storeId,
        'scope_mode' => 'store_self', 'store_ids' => json_encode([$storeId]), 'request_token' => clearStoreToken(),
    ]);
    $complete = app()->make(EmployeePersonCompleteWriteServices::class);
    $read = $complete->getComplete($employeeId, $staffId, 'hq', true);
    $input = ['staff_name' => $read['staff_name'], 'phone' => $read['phone'], 'org_id' => $orgId,
        'store_id' => 0, 'scope_mode' => 'personal', 'clear_current_store' => 1,
        'clear_store_staff_id' => $staffId, 'clear_store_id' => $storeId,
        'current_store_version' => $read['current_store_version'], 'status' => 1, 'status_version' => 0,
        'request_token' => clearStoreToken()];
    $save = static function (array $post, bool $legacy = false) use ($app, $employeeId, $staffId): array {
        return $app->invokeMethod([new ClearStoreLocalController($post),
            $legacy ? 'saveStaff' : 'savePersonComplete'], ['id' => $legacy ? $staffId : $employeeId])->getData();
    };
    $reject = static function (array $post, string $text) use ($save): void {
        $post['request_token'] = clearStoreToken();
        $out = $save($post);
        clearStoreAssert((int)$out['status'] === 400 && strpos($out['msg'], $text) !== false,
            '拒绝 ' . $text . '：' . $out['msg']);
    };
    $reject(array_merge($input, ['current_store_version' => 999999]), '任职已发生变化');
    $reject(array_merge($input, ['clear_store_id' => $storeId + 100000]), '任职已发生变化');
    $reject(array_merge($input, ['clear_store_staff_id' => $staffId + 100000]), '任职与员工不匹配');
    $reject(array_merge($input, ['position_ids' => [$positionId]]), '不能同时修改岗位');
    $reject(array_merge($input, ['status' => 0]), '不能同时办理员工离职');
    // 旧员工可能从版本 0 开始；它是有效快照，不能要求先改其他资料才能清空。
    Db::execute('SAVEPOINT clear_zero_version');
    Db::name('employee')->where('id', $employeeId)->update(['auth_version' => 0]);
    $zeroInput = array_merge($input, ['current_store_version' => 0, 'request_token' => clearStoreToken()]);
    $zeroResult = $save($zeroInput);
    clearStoreAssert((int)$zeroResult['status'] === 200, '历史授权版本 0 可正常清空');
    Db::execute('ROLLBACK TO SAVEPOINT clear_zero_version');
    $denied = $app->invokeMethod([new ClearStoreLocalController($input, 3, 999999), 'savePersonComplete'],
        ['id' => $employeeId])->getData();
    clearStoreAssert((int)$denied['status'] === 400 && $denied['msg'] === '无权操作该店员',
        '代理不能通过清空目标门店绕过原任职范围校验');
    $reject(array_merge($input, ['scope_mode' => 'store']), '任职');
    clearStoreAssert((int)Db::name('system_store_staff')->where('id', $staffId)->value('status') === 1
        && (int)Db::name('staff_channel_entry')->where('staff_id', $staffId)->where('status', 1)->count() === 2
        && !Db::name('employee_change_log')->where('employee_id', $employeeId)
            ->where('action', 'staff_current_store_clear')->find(), '后置数据范围失败原子回滚任职、入口与审计');
    $result = $save($input);
    clearStoreAssert((int)$result['status'] === 200 && (int)$result['data']['store_id'] === 0
        && (int)$result['data']['staff_id'] === 0, '真实路径清空任职并返回无店身份');
    $ended = Db::name('system_store_staff')->where('id', $staffId)->find();
    clearStoreAssert((int)$ended['store_id'] === $storeId && (int)$ended['status'] === 0
        && (int)$ended['is_del'] === 0 && $ended['roles'] === '' && (int)$ended['is_cashier'] === 0
        && (int)$ended['is_manager'] === 0 && (int)$ended['is_store'] === 0 && (int)$ended['order_status'] === 0,
        '历史任职门店及主键保留，旧角色与门店入口投影关闭');
    clearStoreAssert((int)Db::name('employee')->where('id', $employeeId)->value('status') === 1
        && (int)Db::name('organization_employee')->where('employee_id', $employeeId)->where('is_del', 0)->count() === 1,
        '员工仍在职且组织关系保留');
    clearStoreAssert((int)Db::name('staff_job_position')->where('staff_id', $staffId)->where('status', 1)->count() === 0
        && (int)Db::name('staff_job_position')->where('employee_id', $employeeId)->where('staff_id', 0)->count() === 1
        && (int)Db::name('staff_job_position')->where('employee_id', $employeeId)->where('staff_id', 0)->value('status') === 1,
        '仅结束原任职岗位，已有总部岗位保留且不复制新岗位');
    clearStoreAssert((int)Db::name('staff_channel_entry')->where('staff_id', $staffId)->where('status', 1)->count() === 0,
        '原任职全部渠道入口关闭');
    clearStoreAssert((int)Db::name('staff_tenure_period')->where('staff_id', $staffId)->where('action', 'clear')->count() === 1
        && (int)Db::name('staff_tenure_period')->where('staff_id', $staffId)->where('action', 'leave')->count() === 0,
        '缺失历史期间时补结束事实，不计为离职');
    clearStoreAssert((int)Db::name('employee')->where('id', $employeeId)->value('auth_version') > 1,
        '统一授权版本递增，使旧会话失效');
    clearStoreAssert((int)Db::name('employee_merchant_session')->where('employee_id', $employeeId)->value('status') === 0,
        '已有商家会话实际撤销');
    clearStoreAssert((int)Db::name('employee_data_scope')->where('id', $scopeId)->value('is_del') === 1,
        '旧门店来源的数据授权撤销');
    $scopeSvc = app()->make(EmployeeDataScopeServices::class);
    clearStoreAssert($scopeSvc->resolveCurrentActiveStoreIds($employeeId) === [], '当前任职数据范围不再包含原门店');
    $fresh = $complete->getComplete($employeeId, 0, 'hq', true);
    clearStoreAssert((int)$fresh['store_id'] === 0 && (int)$fresh['staff_id'] === 0
        && (int)$fresh['status'] === 1, '刷新详情不复活历史任职');
    $hintRead = $complete->getComplete($employeeId, $staffId, 'hq', true);
    clearStoreAssert((int)$hintRead['store_id'] === 0 && (int)$hintRead['staff_id'] === 0,
        '带旧任职提示的编辑读取同样不回填旧门店');
    $staffService = app()->make(\app\services\store\SystemStoreStaffServices::class);
    $list = $staffService->getStoreStaffList(['keyword' => '19900007103']);
    clearStoreAssert((int)$list['count'] === 1 && (int)$list['list'][0]['store_id'] === 0
        && (int)$list['list'][0]['status'] === 1 && (int)$list['list'][0]['is_organization_direct'] === 1,
        '人员列表投影为无店直属且保持在职');
    $activeList = $staffService->getStoreStaffList(['keyword' => '19900007103', 'status' => 1]);
    $leftList = $staffService->getStoreStaffList(['keyword' => '19900007103', 'status' => 0]);
    clearStoreAssert((int)$activeList['count'] === 1 && count($activeList['list']) === 1
        && (int)$leftList['count'] === 0 && $leftList['list'] === [], '在职/离职筛选与列表合计使用同一清空口径');
    $replay = $save($input);
    clearStoreAssert($replay['data'] === $result['data']
        && (int)Db::name('employee_change_log')->where('employee_id', $employeeId)
            ->where('action', 'staff_current_store_clear')->count() === 1, '相同令牌回放且不重复结束或审计');
    $reject($input, '任职已发生变化');
    $conflict = $input;
    $conflict['staff_name'] = '不能覆盖的姓名';
    $conflictResult = $save($conflict);
    clearStoreAssert((int)$conflictResult['status'] === 400 && strpos($conflictResult['msg'], 'CONFLICT') !== false,
        '同令牌不同内容冲突，不覆盖员工资料');

    // 独立 savepoint 验证旧 staff 路由同样能清空，保留外层回滚夹具。
    Db::execute('SAVEPOINT clear_legacy');
    Db::name('system_store_staff')->where('id', $staffId)->update(['status' => 1]);
    Db::name('staff_tenure_period')->insert([
        'employee_id' => $employeeId, 'store_id' => $storeId, 'staff_id' => $staffId,
        'start_time' => time() - 10, 'request_token' => clearStoreToken(),
    ]);
    $legacy = $input;
    $legacy['request_token'] = clearStoreToken();
    $legacy['current_store_version'] = (int)Db::name('employee')->where('id', $employeeId)->value('auth_version');
    $legacyResult = $save($legacy, true);
    clearStoreAssert((int)$legacyResult['status'] === 200, '旧任职路由保持兼容');
    clearStoreAssert((int)Db::name('staff_tenure_period')->where('staff_id', $staffId)->where('status', 1)->count() === 0
        && (int)Db::name('staff_tenure_period')->where('staff_id', $staffId)->count() === 2,
        '已有进行中任职期间时只关闭原期间，不重复补建');
    Db::execute('ROLLBACK TO SAVEPOINT clear_legacy');
} finally {
    Db::rollback();
}
clearStoreAssert(!Db::name('employee')->where('phone', '19900007103')->find(), '全部测试数据回滚');
