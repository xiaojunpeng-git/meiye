<?php
/** 本机组织表单事务回归：真实服务与数据库，所有业务变动在外层事务中回滚。 */
declare(strict_types=1);
require '/var/www/html/vendor/autoload.php';
use think\facade\Db;
use app\services\organization\OrganizationWorkspaceWriteServices;
(new think\App('/var/www/html/'))->initialize();
if ((string)config('database.connections.mysql.hostname') !== 'mysql') {
    throw new RuntimeException('拒绝连接非本地数据库');
}
$assert = static function ($ok, $label) {
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    echo 'PASS ' . $label . PHP_EOL;
};
$reject = static function ($fn, $message) use ($assert) {
    try { $fn(); } catch (Throwable $e) {
        $assert(strpos($e->getMessage(), $message) !== false, 'reject ' . $message . ' (' . $e->getMessage() . ')');
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $message);
};
$service = app()->make(OrganizationWorkspaceWriteServices::class);
$storeId = 179;
$before = Db::name('system_store')->where('id', $storeId)->find();
$oldOrg = (int)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
$target = (int)Db::name('organization')->where('is_del', 0)->where('id', '<>', $oldOrg)->order('id', 'desc')->value('id');
if (!$before || !$target) throw new RuntimeException('缺少本地回归数据');
$data = ['name' => $before['name'], 'product_category_status' => $before['product_category_status'],
    'product_change_price_status' => $before['product_change_price_status']];
$admin = ['id' => 1, 'account' => 'local-regression', 'level' => 0, 'admin_type' => 0];
$token = static function () { return ['body_token' => sprintf('%s-%s-4%s-a%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), substr(bin2hex(random_bytes(2)), 0, 3), substr(bin2hex(random_bytes(2)), 0, 3), bin2hex(random_bytes(6)))]; };
Db::startTrans();
try {
    $ctx = $token();
    $saved = $service->saveStoreWithOrganization($storeId, $target, $oldOrg, $data, [], $admin, $ctx);
    $assert((int)Db::name('organization_store')->where('store_id', $storeId)->value('org_id') === $target, 'binding persisted');
    $assert(!$saved['replay'], 'first write');
    $assert($service->saveStoreWithOrganization($storeId, $target, $oldOrg, $data, [], $admin, $ctx)['replay'], 'same request replay');
    $reject(fn() => $service->saveStoreWithOrganization($storeId, $target, $oldOrg, $data, [], $admin, $token()), '已被修改');
    $reject(fn() => $service->saveStoreWithOrganization($storeId, 0, $target, $data, [], $admin, $token()), '不能清空');
    $reject(fn() => $service->saveStoreWithOrganization($storeId, 2147483647, $target, $data, [], $admin, $token()), '不存在');
    // 普通编辑不再因级别被拒；无效登录身份仍不能调整门店归属。
    $denied = $admin; $denied['id'] = 0;
    $reject(fn() => $service->saveStoreWithOrganization($storeId, $oldOrg, $target, $data, [], $denied, $token()), '登录身份');
    // 故意让审计失败：资料名称和组织绑定都必须恢复，不能吞错。
    OrganizationWorkspaceWriteServices::armCliAuditFaultOnce();
    $changed = $data; $changed['name'] = 'R27事务回滚探针';
    $reject(fn() => $service->saveStoreWithOrganization($storeId, $oldOrg, $target, $changed, [], $admin, $token()), '审计故障');
    $assert(Db::name('system_store')->where('id', $storeId)->value('name') === $before['name'], 'profile rollback');
    $assert((int)Db::name('organization_store')->where('store_id', $storeId)->value('org_id') === $target, 'binding rollback');
} finally { Db::rollback(); }
$assert((int)Db::name('organization_store')->where('store_id', $storeId)->value('org_id') === $oldOrg, 'test leaves original binding');
