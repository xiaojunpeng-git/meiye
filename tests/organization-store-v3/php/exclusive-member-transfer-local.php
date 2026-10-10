<?php

/** 专属会员真实 SQL/事务回归；仅本机 Docker 库，所有夹具和业务写入最终回滚。 */
require '/var/www/html/vendor/autoload.php';

use app\services\store\StaffExclusiveMemberServices;
use app\services\cashier\v3\member\CashierV3MemberExclusiveSelectionServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao'
    || getenv('EXCLUSIVE_TRANSFER_LOCAL_TEST') !== '1') {
    throw new RuntimeException('仅允许显式启用的本机 Docker 回滚测试');
}

function exclusiveAssert(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException($label);
    echo 'PASS ', $label, "\n";
}
function exclusiveToken(): string { return '71a00000-0000-4000-8000-' . bin2hex(random_bytes(6)); }
function exclusiveReject(callable $call, string $contains): void
{
    try { $call(); } catch (Throwable $e) {
        exclusiveAssert(strpos($e->getMessage(), $contains) !== false, '拒绝：' . $contains);
        return;
    }
    throw new RuntimeException('未拒绝：' . $contains);
}

// 只替换 HTTP 请求及登录身份，不替换控制器、服务、权限、SQL 或事务。
class ExclusiveTransferLocalController extends \app\controller\admin\v1\merchant\SystemStoreStaff
{
    public function __construct(array $input, bool $post = false)
    {
        $this->request = new \app\Request();
        $this->request->setMethod($post ? 'POST' : 'GET');
        $this->request->withServer(['REQUEST_METHOD' => $post ? 'POST' : 'GET']);
        $this->request = $post ? $this->request->withPost($input) : $this->request->withGet($input);
        $this->adminInfo = ['id' => 1, 'level' => 0, 'admin_type' => 0, 'account' => 'exclusive-local-test'];
    }
}

$service = new StaffExclusiveMemberServices();
$admin = ['id' => 1, 'level' => 0, 'admin_type' => 0, 'account' => 'exclusive-local-test'];
$now = strtotime('2026-10-04 10:00:00');
Db::startTrans();
try {
    $stores = Db::name('organization_store')->alias('os')->join('system_store s', 's.id=os.store_id')
        ->where('s.is_del', 0)->column('s.id');
    exclusiveAssert(count($stores) >= 2, '本地库存在两个独立门店');
    $store = (int)$stores[0];
    $otherStore = (int)$stores[1];
    $people = [];
    foreach (['小李', '小王', '小张', '小黄', '跨店员工', '停用员工', '不可选员工'] as $index => $name) {
        $employee = (int)Db::name('employee')->insertGetId(['name' => $name, 'phone' => '19900710' . sprintf('%03d', $index)]);
        $staff = (int)Db::name('system_store_staff')->insertGetId([
            'employee_id' => $employee, 'store_id' => $index === 4 ? $otherStore : $store,
            'staff_name' => $name, 'status' => $index === 5 ? 0 : 1,
            'cashier_salesperson_enabled' => $index === 6 ? 0 : 1,
            'cashier_craftsman_enabled' => 0,
        ]);
        $people[] = ['staff_id' => $staff, 'employee_id' => $employee,
            'store_id' => $index === 4 ? $otherStore : $store, 'staff_name' => $name];
    }
    [$li, $wang, $zhang, $huang, $foreign, $inactive, $ineligible] = $people;
    // 普通管理员仅获人员维护功能与一个精确门店，不授予集团范围。
    $menuId = (int)Db::name('system_menus')->where('unique_auth', 'setting-staff-index')->value('id');
    $roleId = (int)Db::name('system_role')->insertGetId(['role_name' => 'exclusive-local-test', 'rules' => (string)$menuId, 'level' => 1, 'status' => 1]);
    $limitedAdminId = (int)Db::name('system_admin')->insertGetId(['account' => 'exclusive-local-' . bin2hex(random_bytes(3)),
        'employee_id' => $li['employee_id'], 'roles' => (string)$roleId, 'level' => 1, 'status' => 1]);
    Db::name('employee_data_scope')->insert(['employee_id' => $li['employee_id'], 'source_type' => 'hq',
        'source_store_id' => 0, 'scope_mode' => 'store_selected', 'store_ids' => json_encode([$store]), 'org_ids' => '[]']);
    $limitedAdmin = ['id' => $limitedAdminId, 'level' => 1, 'admin_type' => 0, 'employee_id' => $li['employee_id']];
    $uids = [];
    foreach (['肖君鹏', '首位转出', '旧单人关系', '仅旧销售人', '跨店会员', '已注销会员', '去重会员'] as $index => $name) {
        $uid = (int)Db::name('user')->insertGetId(['nickname' => '昵称' . $index, 'real_name' => $name,
            'phone' => '19900711' . sprintf('%03d', $index), 'salesman_id' => $wang['staff_id'], 'salesman_time' => 123,
            'is_del' => $index === 5 ? 1 : 0]);
        $uids[] = $uid;
        Db::name('store_user')->insert(['uid' => $uid, 'store_id' => $index === 4 ? $otherStore : $store]);
        $assigned = $index === 0 ? [$li, $wang] : ($index === 6 ? [$li, $wang, $zhang] : [$wang]);
        if ($index === 3) continue;
        foreach ($assigned as $order => $person) {
            if ($index === 2) break;
            Db::name('member_exclusive_service_selection')->insert($person + ['member_id' => $uid,
                'sort_order' => $order + 1, 'created_at' => $now, 'updated_at' => $now]);
        }
        $first = $assigned[0];
        Db::name('member_exclusive_service')->insert($first + ['member_id' => $uid, 'bound_at' => $now,
            'store_name' => '本地回滚测试', 'created_at' => $now, 'updated_at' => $now]);
        Db::name('cashier_v3_entitlement_resource_version')->insert(['resource_kind' => 'member',
            'resource_id' => (string)$uid, 'member_id' => $uid, 'source_fingerprint' => str_repeat('a', 64), 'current_version' => 7]);
    }
    $allUsersBefore = Db::name('user')->whereIn('uid', $uids)->order('uid')->select()->toArray();
    $allStaffBefore = Db::name('system_store_staff')->whereIn('id', array_column($people, 'staff_id'))->order('id')->select()->toArray();
    $beforeLi = Db::name('member_exclusive_service_selection')->where('member_id', $uids[0])->where('staff_id', $li['staff_id'])->find();
    $page = $service->members($wang['staff_id'], [], $admin);
    exclusiveAssert($page['count'] === 4, '多人关系及旧单人关系可见，旧销售人/跨店/注销会员不混入');
    exclusiveAssert(array_keys($page['list'][0]) === ['nickname', 'real_name', 'phone', 'bound_at'], '接口只包含昵称、姓名、手机号、绑定时间');
    exclusiveAssert($service->members($wang['staff_id'], ['keyword' => '肖君鹏'], $admin)['count'] === 1, '客户姓名搜索');
    exclusiveAssert($service->members($wang['staff_id'], ['keyword' => '昵称0'], $admin)['count'] === 1, '客户昵称搜索');
    exclusiveAssert($service->members($wang['staff_id'], ['keyword' => '19900711000'], $admin)['count'] === 1, '手机号搜索');
    exclusiveAssert(count($service->members($wang['staff_id'], ['page' => 2, 'limit' => 2], $admin)['list']) === 2, '分页与计数一致');
    exclusiveAssert($service->members($wang['staff_id'], ['date_from' => '2026-10-04', 'date_to' => '2026-10-04'], $admin)['count'] === 4, '绑定日期含结束日');
    exclusiveReject(fn() => $service->members($wang['staff_id'], ['date_from' => '2026-02-30'], $admin), '格式无效');
    exclusiveReject(fn() => $service->prepare(-1, $admin), '没有有效');
    exclusiveReject(fn() => $service->prepare($wang['staff_id'], ['id' => 1, 'level' => 0, 'admin_type' => 3]), '人员维护');
    exclusiveAssert($service->prepare($wang['staff_id'], $limitedAdmin)['count'] === 4, '普通人员维护管理员可操作明确授权门店');
    exclusiveReject(fn() => $service->prepare($foreign['staff_id'], $limitedAdmin), '数据权限范围');
    Db::name('employee_store_isolation')->insert(['employee_id' => $li['employee_id'], 'store_id' => $store, 'status' => 1]);
    exclusiveReject(fn() => $service->prepare($wang['staff_id'], $limitedAdmin), '数据权限范围');
    Db::name('employee_store_isolation')->where('employee_id', $li['employee_id'])->where('store_id', $store)->delete();
    $invalidPath = $app->invokeMethod([new ExclusiveTransferLocalController([]), 'prepareExclusiveMemberTransfer'], ['id' => $wang['staff_id'] . 'abc'])->getData();
    exclusiveAssert((int)$invalidPath['status'] === 400 && strpos($invalidPath['msg'], '员工标识无效') !== false, '畸形路径不截断为其他员工');
    $preview = $service->prepare($wang['staff_id'], $admin);
    $eligible = array_column($preview['recipients'], 'staff_id');
    exclusiveAssert($preview['count'] === 4 && in_array($zhang['staff_id'], $eligible, true)
        && !in_array($wang['staff_id'], $eligible, true) && !in_array($foreign['staff_id'], $eligible, true)
        && !in_array($inactive['staff_id'], $eligible, true) && !in_array($ineligible['staff_id'], $eligible, true), '候选人仅同店有效可选员工，不含自己');
    $payload = ['recipient_ids' => [$zhang['staff_id'], $huang['staff_id']], 'snapshot' => $preview['snapshot'], 'request_token' => exclusiveToken()];
    foreach ([$foreign, $inactive, $ineligible] as $invalid) {
        $invalidPayload = $payload;
        $invalidPayload['recipient_ids'] = [$invalid['staff_id']];
        $invalidPayload['request_token'] = exclusiveToken();
        exclusiveReject(fn() => $service->transfer($wang['staff_id'], $invalidPayload, $admin), '必须是同门店');
    }
    $self = $payload;
    $self['recipient_ids'] = [$wang['staff_id']];
    exclusiveReject(fn() => $service->transfer($wang['staff_id'], $self, $admin), '本人');
    // 模拟预览后另一窗口改变关系：必须拒绝整个批次。
    Db::name('member_exclusive_service_selection')->where('member_id', $uids[0])->where('staff_id', $li['staff_id'])->inc('version')->update();
    exclusiveReject(fn() => $service->transfer($wang['staff_id'], $payload, $admin), '绑定已变化');
    Db::name('member_exclusive_service_selection')->where('id', $beforeLi['id'])->update(['version' => $beforeLi['version']]);
    // 在最后一个会员审计处制造唯一键冲突，验证前面已经写入的会员也全部回滚。
    $failed = $payload;
    $failed['request_token'] = exclusiveToken();
    $faultKey = 'exclusive_transfer:' . $uids[6] . ':' . $failed['request_token'];
    Db::name('member_exclusive_service_selection_change')->insert(['change_key' => $faultKey,
        'member_id' => $uids[6], 'previous_json' => '[]', 'current_json' => '[]', 'occurred_at' => $now]);
    $selectionBeforeFailure = Db::name('member_exclusive_service_selection')->whereIn('member_id', $uids)->order('id')->select()->toArray();
    exclusiveReject(fn() => $service->transfer($wang['staff_id'], $failed, $admin), 'Duplicate entry');
    exclusiveAssert($selectionBeforeFailure === Db::name('member_exclusive_service_selection')->whereIn('member_id', $uids)->order('id')->select()->toArray()
        && !Db::name('organization_write_idempotency')->where('request_token', $failed['request_token'])->find(), '中途保存失败整批回滚，幂等结果也不残留');
    Db::name('member_exclusive_service_selection_change')->where('change_key', $faultKey)->delete();
    $response = $app->invokeMethod([new ExclusiveTransferLocalController($payload, true), 'transferExclusiveMembers'], ['id' => $wang['staff_id']])->getData();
    if ((int)$response['status'] !== 200) throw new RuntimeException('控制器提交失败：' . json_encode($response, JSON_UNESCAPED_UNICODE));
    exclusiveAssert($response['status'] === 200 && $response['data']['count'] === 4, '真实控制器路径提交成功且一次转移全部四名会员');
    $selection = new CashierV3MemberExclusiveSelectionServices();
    exclusiveAssert(array_map('intval', array_column($selection->records($uids[0]), 'staff_id')) === [$li['staff_id'], $zhang['staff_id'], $huang['staff_id']], '小李、小王替换为小李、小张、小黄');
    exclusiveAssert(array_map('intval', array_column($selection->records($uids[6]), 'staff_id')) === [$li['staff_id'], $huang['staff_id'], $zhang['staff_id']], '已绑定接收人去重且保留原位置');
    exclusiveAssert(array_map('intval', array_column($selection->records($uids[2]), 'staff_id')) === [$zhang['staff_id'], $huang['staff_id']], '旧单人关系转为多人关系且会员档案读取一致');
    exclusiveAssert((int)Db::name('member_exclusive_service')->where('member_id', $uids[1])->value('staff_id') === $zhang['staff_id'], '首位转出同步旧单人投影');
    $afterLi = Db::name('member_exclusive_service_selection')->where('id', $beforeLi['id'])->find();
    exclusiveAssert($afterLi === $beforeLi, '其他服务人原关系、版本和绑定时间完整保留');
    exclusiveAssert($allUsersBefore === Db::name('user')->whereIn('uid', $uids)->order('uid')->select()->toArray(), '会员档案及销售人等全部主字段不变');
    exclusiveAssert($allStaffBefore === Db::name('system_store_staff')->whereIn('id', array_column($people, 'staff_id'))->order('id')->select()->toArray(), '员工任职、权限和状态完全不变');
    exclusiveAssert((int)Db::name('cashier_v3_entitlement_resource_version')->where('resource_kind', 'member')->where('resource_id', (string)$uids[0])->value('current_version') === 8, '会员资源版本推进，旧编辑请求失效');
    $history = Db::name('member_exclusive_service_selection_change')->whereIn('member_id', $uids)->select()->toArray();
    exclusiveAssert(count($history) === 4 && (int)$history[0]['operator_id'] === 1, '每会员一条不可覆盖前后关系审计');
    $replay = $service->transfer($wang['staff_id'], $payload, $admin);
    exclusiveAssert($replay['replay'] === true && $replay['data']['count'] === 4
        && Db::name('member_exclusive_service_selection_change')->whereIn('member_id', $uids)->count() === 4, '重复请求回放，不重复审计');
    $conflict = $payload;
    $conflict['recipient_ids'] = [$huang['staff_id']];
    exclusiveReject(fn() => $service->transfer($wang['staff_id'], $conflict, $admin), 'IDEMPOTENCY_TOKEN_CONFLICT');
    exclusiveAssert($service->members($wang['staff_id'], [], $admin)['count'] === 0
        && $service->members($zhang['staff_id'], [], $admin)['count'] === 4, '转出列表归零，接收列表读取新绑定');
    $targetPage = $service->members($zhang['staff_id'], ['keyword' => '昵称0'], $admin);
    exclusiveAssert($targetPage['list'][0]['bound_at'] !== date('Y-m-d H:i:s', $now), '新接收人员显示本次实际绑定时间');
    $retainedPage = $service->members($zhang['staff_id'], ['keyword' => '昵称6'], $admin);
    exclusiveAssert($retainedPage['list'][0]['bound_at'] === date('Y-m-d H:i:s', $now), '已绑定接收人保留原绑定时间');
    exclusiveAssert((int)Db::name('member_exclusive_service_selection')->where('member_id', $uids[4])->where('staff_id', $wang['staff_id'])->value('status') === 1, '跨店会员的关系不受影响');
} finally {
    Db::rollback();
}
exclusiveAssert(!Db::name('employee')->where('phone', '19900710000')->find()
    && !Db::name('user')->where('phone', '19900711000')->find(), '夹具和业务写入已全部回滚');
