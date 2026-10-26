<?php
/** Local-only route authorization check. Synthetic menu grants and account are rolled back. */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();

use app\Request;
use app\services\product\inventory\InventoryPlatformAccessPolicy;
use app\services\system\SystemRoleServices;
use mohe\exceptions\AuthException;
use think\facade\Db;

if (!getenv('MOHE_LOCAL_DB_HOSTNAME')
    || config('database.connections.mysql.hostname') !== 'mysql'
    || Db::query('SELECT @@hostname AS host')[0]['host'] !== getenv('MOHE_LOCAL_DB_HOSTNAME')) {
    throw new RuntimeException('Local database guard failed');
}

function checkPresaleAccess(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
    echo 'PASS ' . $label . PHP_EOL;
}

class PresaleAccessRequest extends Request
{
    public $testRule = '';
    public $testMethod = 'get';
    public function rule() { return new class($this->testRule) {
        private $rule;
        public function __construct(string $rule) { $this->rule = $rule; }
        public function getRule(): string { return $this->rule; }
    }; }
    public function method(bool $origin = false): string { return $this->testMethod; }
}

$tag = 'presale-access-' . bin2hex(random_bytes(5));
$roles = app()->make(SystemRoleServices::class);
$policy = new InventoryPlatformAccessPolicy();
$request = new PresaleAccessRequest();
Db::startTrans();
try {
    // Read permission is the exact existing page menu. Write requires both native inventory capabilities.
    $menuIds = [];
    foreach (['admin-stock-manage', InventoryPlatformAccessPolicy::AUTH_VIEW,
        InventoryPlatformAccessPolicy::AUTH_WAREHOUSE_MANAGE] as $auth) {
        $menuIds[$auth] = (int)Db::name('system_menus')->insertGetId([
            'menu_name' => $tag . '-' . $auth, 'type' => 1, 'auth_type' => 1,
            'unique_auth' => $auth, 'is_del' => 0,
        ]);
    }
    $roleId = (int)Db::name('system_role')->insertGetId([
        'role_name' => $tag, 'rules' => (string)$menuIds['admin-stock-manage'], 'status' => 1,
    ]);
    $adminId = (int)Db::name('system_admin')->insertGetId([
        'account' => $tag, 'real_name' => $tag, 'roles' => (string)$roleId,
        'level' => 1, 'status' => 1, 'is_del' => 0, 'admin_type' => 0,
    ]);
    $request->adminInfo = ['id' => $adminId, 'roles' => [$roleId], 'level' => 1];
    foreach (['presale-claims', 'presale-claims/scope', 'presale-claims/<id>'] as $route) {
        $request->testRule = 'adminapi/product/inventory/v3/' . $route;
        checkPresaleAccess($roles->verifiAuth($request), 'page menu opens GET ' . $route);
    }
    $request->testRule = 'adminapi/product/inventory/v3/presale-claims/claim';
    $request->testMethod = 'post';
    try { $roles->verifiAuth($request); throw new RuntimeException('Page menu unexpectedly writes stock'); }
    catch (AuthException $expected) { echo 'PASS page menu cannot claim stock' . PHP_EOL; }
    checkPresaleAccess(!$policy->allowsFunctionRoute($adminId, 'product/inventory/v3/presale-claims/<id>/void', 'post'),
        'page menu cannot void a claim');

    Db::name('system_role')->where('id', $roleId)->update([
        'rules' => implode(',', array_values($menuIds)),
    ]);
    foreach (['presale-claims/claim', 'presale-claims/<id>/void'] as $route) {
        $request->testRule = 'adminapi/product/inventory/v3/' . $route;
        checkPresaleAccess($roles->verifiAuth($request), 'page plus native manage opens POST ' . $route);
    }
    checkPresaleAccess(!$policy->allowsFunctionRoute($adminId, 'product/inventory/v3/presale-claims/<id>/unknown', 'post'),
        'unknown command remains denied');
    Db::name('system_role')->where('id', $roleId)->update([
        'rules' => (string)$menuIds['admin-stock-manage'],
    ]);
    checkPresaleAccess(!$policy->allowsFunctionRoute($adminId, 'product/inventory/v3/presale-claims/claim', 'post'),
        'revoked native manage closes claim');
    Db::name('system_role')->where('id', $roleId)->update(['rules' => '']);
    $request->testMethod = 'get';
    $request->testRule = 'adminapi/product/inventory/v3/presale-claims';
    try { $roles->verifiAuth($request); throw new RuntimeException('Revoked page menu unexpectedly reads claims'); }
    catch (AuthException $expected) { echo 'PASS revoked page menu closes list' . PHP_EOL; }
} finally {
    Db::rollback();
}
