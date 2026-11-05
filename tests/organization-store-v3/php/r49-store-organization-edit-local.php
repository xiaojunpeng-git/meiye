<?php
/** 本机真实权限/数据库回归；仅创建事务夹具，所有资料、归属、审计和幂等结果最终回滚。 */
declare(strict_types=1);
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
set_exception_handler(static function (Throwable $e): void { file_put_contents('php://stderr', $e->getMessage() . PHP_EOL); exit(1); });

use think\facade\Db;
use app\services\organization\OrganizationWorkspaceWriteServices;
use app\services\organization\OrganizationScopeService;
use app\services\system\SystemRoleServices;

if (getenv('R49_LOCAL_TEST') !== '1' || config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') throw new RuntimeException('LOCAL_ONLY');
function r49ok(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    echo 'PASS ', $label, PHP_EOL;
}
function r49reject(callable $fn, string $message): void {
    try { $fn(); } catch (Throwable $e) {
        r49ok(strpos($e->getMessage(), $message) !== false, '拒绝 ' . $message . ': ' . $e->getMessage()); return;
    }
    throw new RuntimeException('Expected rejection: ' . $message);
}
function r49ctx(): array { return ['body_token' => '49000000-0000-4000-8000-' . bin2hex(random_bytes(6))]; }
class R49PermissionRequest extends \app\Request {
    public function rule() { return new class { public function getRule(): string { return 'adminapi/store/store/<id>'; } }; }
    public function method(bool $origin = false): string { return 'post'; }
}
/** 真实门店控制器入口，原生路由鉴权在上方单独调用同一服务验证。 */
class R49StoreController extends \app\controller\admin\v1\store\SystemStore {
    public function __construct(array $post, array $admin) {
        $this->request = (new \app\Request())->withServer(['REQUEST_METHOD' => 'POST'])->withPost($post);
        $this->services = app()->make(\app\services\store\SystemStoreServices::class);
        $this->adminInfo = $admin;
    }
}

$tag = 'r49-' . bin2hex(random_bytes(4));
$svc = app()->make(OrganizationWorkspaceWriteServices::class);
Db::startTrans();
try {
    $menu = (int)Db::name('system_menus')->where('api_url', 'store/store/<id>')->where('methods', 'POST')->value('id');
    r49ok($menu > 0, '使用已有门店保存接口权限，不新增组织编辑权限');
    $role = (int)Db::name('system_role')->insertGetId(['role_name' => $tag, 'rules' => (string)$menu, 'status' => 1]);
    $adminId = (int)Db::name('system_admin')->insertGetId(['account' => $tag, 'level' => 1, 'admin_type' => 0,
        'status' => 1, 'is_del' => 0, 'roles' => (string)$role]);
    $admin = ['id' => $adminId, 'account' => $tag, 'level' => 1, 'admin_type' => 0, 'roles' => [$role]];
    $request = new R49PermissionRequest(); $request->adminInfo = $admin;
    $roles = app()->make(SystemRoleServices::class);
    r49ok($roles->verifiAuth($request) === true, '普通编辑账号通过原生接口鉴权');
    $deniedRequest = new R49PermissionRequest(); $deniedRequest->adminInfo = array_replace($admin, ['roles' => []]);
    r49reject(static function () use ($roles, $deniedRequest) { $roles->verifiAuth($deniedRequest); }, 'permission');
    $root = (int)Db::name('organization')->where('is_del', 0)->where('pid', 0)->value('id');
    $org = (int)Db::name('organization')->insertGetId(['pid' => $root, 'name' => $tag]);
    $child = (int)Db::name('organization')->insertGetId(['pid' => $org, 'name' => $tag . '-child']);
    $outside = (int)Db::name('organization')->insertGetId(['pid' => $root, 'name' => $tag . '-outside']);
    $store = (int)Db::name('system_store')->insertGetId(['name' => $tag, 'is_del' => 0, 'is_show' => 1]);
    $anchorStore = (int)Db::name('system_store')->insertGetId(['name' => $tag . '-anchor', 'is_del' => 0, 'is_show' => 1]);
    $outsideStore = (int)Db::name('system_store')->insertGetId(['name' => $tag . '-outside', 'is_del' => 0, 'is_show' => 1]);
    Db::name('organization_store')->insertAll([['org_id' => $child, 'store_id' => $store],
        ['org_id' => $child, 'store_id' => $anchorStore],
        ['org_id' => $outside, 'store_id' => $outsideStore]]);
    $oa = (int)Db::name('organization_admin')->insertGetId(['org_id' => $org, 'admin_id' => $adminId]);
    OrganizationScopeService::clearCache();
    $data = ['name' => $tag . '-edited', 'product_category_status' => 0, 'product_change_price_status' => 0];
    $ctx = r49ctx();
    $saved = $svc->saveStoreWithOrganization($store, $org, $child, $data, [], $admin, $ctx);
    r49ok(!$saved['replay'], '非超管真实保存门店归属成功');
    r49ok((int)Db::name('organization_store')->where('store_id', $store)->value('org_id') === $org
        && Db::name('system_store')->where('id', $store)->value('name') === $data['name'], '刷新读回资料及归属同时保存');
    $audit = Db::name('organization_change_log')->where('request_token', $ctx['body_token'])->find();
    r49ok($audit && (int)$audit['operator_id'] === $adminId, '审计记录真实普通账号');
    $replay = $svc->saveStoreWithOrganization($store, $org, $child, $data, [], $admin, $ctx);
    r49ok($replay['replay'] && $replay['data'] === $saved['data'], '重复保存只回放，不重复变更');
    $post = ['name' => $data['name'], 'phone' => '19900004900', 'address' => '测试地区',
        'detailed_address' => '测试详细地址', 'day_time' => ['10:00:00', '22:00:00'], 'delivery_type' => ['2'],
        'organization_id' => $child, 'expected_organization_id' => $child, 'request_token' => r49ctx()['body_token']];
    // 显式让表单持有已失效的原归属，验证业务错误经过 HTTP JSON 契约返回前端。
    $post['expected_organization_id'] = $outside;
    $response = (new R49StoreController($post, $admin))->save($store)->getData();
    r49ok((int)$response['status'] !== 200 && strpos($response['msg'], '已被修改') !== false,
        '真实控制器返回可显示的并发错误，不抛为 HTTP 500');
    r49reject(static function () use ($svc, $store, $org, $child, $data, $admin) {
        $svc->saveStoreWithOrganization($store, $org, $child, $data, [], $admin, r49ctx());
    }, '已被修改');
    r49reject(static function () use ($svc, $store, $org, $outside, $data, $admin) {
        $svc->saveStoreWithOrganization($store, $outside, $org, $data, [], $admin, r49ctx());
    }, '所属组织超出');
    r49reject(static function () use ($svc, $outsideStore, $org, $outside, $data, $admin) {
        $svc->saveStoreWithOrganization($outsideStore, $org, $outside, $data, [], $admin, r49ctx());
    }, '门店超出');
    r49reject(static function () use ($svc, $store, $org, $child, $data, $admin) {
        $svc->saveStoreWithOrganization($store, $child, $org, $data, [], $admin, []);
    }, '缺少请求令牌');
    r49reject(static function () use ($svc, $store, $org, $data, $admin) {
        $svc->saveStoreWithOrganization($store, 0, $org, $data, [], $admin, r49ctx());
    }, '不能清空');
    // 故障在绑定审计阶段发生，资料、归属及幂等请求必须一起撤回。
    $faultCtx = r49ctx(); OrganizationWorkspaceWriteServices::armCliAuditFaultOnce();
    r49reject(static function () use ($svc, $store, $org, $child, $data, $admin, $faultCtx) {
        $data['name'] = 'r49-must-rollback';
        $svc->saveStoreWithOrganization($store, $child, $org, $data, [], $admin, $faultCtx);
    }, '审计故障');
    r49ok((int)Db::name('organization_store')->where('store_id', $store)->value('org_id') === $org
        && Db::name('system_store')->where('id', $store)->value('name') === $data['name']
        && !Db::name('organization_write_idempotency')->where('request_token', $faultCtx['body_token'])->count(), '失败不留下部分保存');
    r49reject(static function () use ($svc, $store, $child, $admin) { $svc->bindStore($store, $child, $admin, r49ctx()); }, '无权限');
    r49reject(static function () use ($svc, $org, $admin) { $svc->deleteOrganization($org, $admin, r49ctx()); }, '无权限');
    Db::name('organization_admin')->where('id', $oa)->update(['is_del' => 1]);
    r49reject(static function () use ($svc, $store, $org, $child, $data, $admin, $ctx) {
        $svc->saveStoreWithOrganization($store, $org, $child, $data, [], $admin, $ctx);
    }, '门店超出');
    r49ok(true, '撤销范围后旧成功令牌也不能重放');
} finally { Db::rollback(); OrganizationScopeService::clearCache(); }
r49ok(!Db::name('system_admin')->where('account', $tag)->count()
    && !Db::name('system_store')->whereLike('name', $tag . '%')->count(), '全部本地测试数据回滚');
echo "R49_PASS\n";
