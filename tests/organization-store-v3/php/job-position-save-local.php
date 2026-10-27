<?php
/** 岗位保存真实本地回归：仅 Docker 测试库，合成账号/岗位及幂等写入全部回滚。 */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
// 框架 CLI 异常展示不能把测试失败伪装成退出码 0。
set_exception_handler(static function (Throwable $error): void {
    file_put_contents('php://stderr', $error->getMessage() . PHP_EOL);
    exit(1);
});

use think\facade\Db;
use app\services\organization\JobPositionPolicyServices;
use app\services\organization\OrganizationWorkspaceWriteGate;

if (getenv('JOB_POSITION_LOCAL_TEST') !== '1'
    || config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('Local Docker database only');
}
function jpAssert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo 'PASS ', $message, PHP_EOL;
}
function jpToken(): array {
    return ['header_token' => '98000000-0000-4000-8000-' . bin2hex(random_bytes(6))];
}
function jpReject(callable $call, string $expected): void {
    try { $call(); } catch (Throwable $error) {
        jpAssert(strpos($error->getMessage(), $expected) !== false, $expected);
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $expected);
}

/** 只构造本地请求与合成身份，保存控制器、幂等和事务实现全部使用正本。 */
class JobPositionSaveLocalController extends \app\controller\admin\v1\organization\Organization {
    public function __construct(array $data, array $admin) {
        $this->request = (new \app\Request())->withServer(['REQUEST_METHOD' => 'POST'])->withPost($data)
            ->withHeader(['x-request-token' => $data['request_token']]);
        $this->adminInfo = $admin;
        $this->adminId = $admin['id'];
        $this->adminType = $admin['admin_type'];
    }
}

$tag = 'jp-save-' . bin2hex(random_bytes(6));
$svc = app()->make(JobPositionPolicyServices::class);
$gate = app()->make(OrganizationWorkspaceWriteGate::class);
Db::startTrans();
try {
    $menu = (int)Db::name('system_menus')->where('unique_auth', 'admin-store-region-job_positions')
        ->where('is_del', 0)->value('id');
    jpAssert($menu > 0, '现有岗位策略菜单可用');
    $role = (int)Db::name('system_role')->insertGetId(['role_name' => $tag, 'rules' => (string)$menu, 'status' => 1]);
    $id = (int)Db::name('system_admin')->insertGetId([
        'account' => $tag, 'pwd' => '', 'real_name' => $tag, 'level' => 1, 'admin_type' => 0,
        'status' => 1, 'is_del' => 0, 'roles' => (string)$role,
    ]);
    $admin = ['id' => $id, 'level' => 1, 'admin_type' => 0, 'account' => $tag];
    jpAssert(!$gate->assertSuperAdmin($admin)['ok'], '合成账号不是超级管理员');
    $data = ['name' => $tag, 'status' => 1, 'use_platform' => 1, 'platform_rules' => [$menu]];
    $ctx = jpToken();
    $first = $svc->savePosition($data, $admin, $ctx);
    $positionId = (int)$first['data']['id'];
    jpAssert($positionId > 0 && !$first['replay'], '已授权普通账号保存成功');
    $detail = $svc->getPositionDetail($positionId);
    jpAssert($detail['position']['name'] === $tag, '保存后详情回读一致');
    $replay = $svc->savePosition($data, $admin, $ctx);
    jpAssert($replay['replay'] && $replay['data'] === $first['data'], '重复提交只回放不重复创建');
    $conflict = $data;
    $conflict['remark'] = 'different payload';
    jpReject(static function () use ($svc, $conflict, $admin, $ctx) {
        $svc->savePosition($conflict, $admin, $ctx);
    }, 'IDEMPOTENCY_TOKEN_CONFLICT');
    jpReject(static function () use ($svc, $data, $admin) { $svc->savePosition($data, $admin, []); }, '缺少请求令牌');
    $data['id'] = $positionId;
    $data['remark'] = 'local rollback edit';
    $edit = $svc->savePosition($data, $admin, jpToken());
    jpAssert((int)$edit['data']['version'] === 2
        && Db::name('position')->where('id', $positionId)->value('remark') === 'local rollback edit',
        '普通授权账号可编辑岗位并更新版本');
    $data['request_token'] = jpToken()['header_token'];
    $response = (new JobPositionSaveLocalController($data, $admin))->job_position_save()->getData();
    jpAssert((int)$response['status'] === 200 && (int)$response['data']['version'] === 3,
        '真实保存控制器处理普通账号请求并落库: ' . json_encode($response, JSON_UNESCAPED_UNICODE));
    $invalid = $data;
    $invalid['name'] = '';
    $invalid['request_token'] = jpToken()['header_token'];
    $response = (new JobPositionSaveLocalController($invalid, $admin))->job_position_save()->getData();
    jpAssert((int)$response['status'] === 400 && $response['msg'] === '请填写岗位名称', '原有业务校验保留');
    $status = $svc->setPositionStatus($positionId, 0, $admin, jpToken());
    jpAssert((int)$status['data']['status'] === 0
        && (int)Db::name('position')->where('id', $positionId)->value('status') === 0,
        '岗位功能授权账号可切换启停状态');
    Db::name('system_role')->where('id', $role)->update(['status' => 0]);
    jpReject(static function () use ($svc, $data, $admin) { $svc->savePosition($data, $admin, jpToken()); }, '无权保存岗位');
    jpReject(static function () use ($svc, $positionId, $admin) {
        $svc->setPositionStatus($positionId, 1, $admin, jpToken());
    }, '无权保存岗位');
    Db::name('system_role')->where('id', $role)->update(['status' => 1]);
    Db::name('system_admin')->where('id', $id)->update(['status' => 0]);
    jpReject(static function () use ($svc, $data, $admin) { $svc->savePosition($data, $admin, jpToken()); }, '无权保存岗位');
    jpReject(static function () use ($svc, $data) { $svc->savePosition($data, [], jpToken()); }, '无权保存岗位');
} finally {
    Db::rollback();
}
jpAssert(!Db::name('system_admin')->where('account', $tag)->find()
    && !Db::name('position')->where('name', $tag)->find(), '测试账号、岗位及写入全部回滚');
