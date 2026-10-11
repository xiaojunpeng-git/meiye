<?php
/**
 * R9 local-only integration: synthetic employee/account/position inside one rollback transaction.
 * Require the caller to identify the local Docker database server; never run against RH production.
 * Tokens are removed in finally, no passwords/tokens or real personnel rows are printed/changed.
 */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();

use think\facade\Db;
use app\services\employee\EmployeeInternalLoginServices;
use app\services\system\admin\SystemAdminServices;
use app\services\system\SystemMenusServices;
use app\services\system\SystemRoleServices;
use mohe\exceptions\AdminException;
use mohe\exceptions\AuthException;

$expected = getenv('MOHE_LOCAL_DB_HOSTNAME');
$server = Db::query('SELECT @@hostname AS host')[0]['host'];
if (!$expected || $server !== $expected || config('database.connections.mysql.hostname') !== 'mysql') {
    throw new RuntimeException('Local Docker database guard failed');
}
function expectR9(bool $passed, string $name): void {
    if (!$passed) throw new RuntimeException($name);
    echo 'PASS ' . $name . PHP_EOL;
}
/** A real role-service request boundary without dispatching a business controller. */
class R9PermissionRequest extends \app\Request {
    public $testRule = '';
    public $testMethod = 'get';
    public function rule() { return new class($this->testRule) {
        private $value;
        public function __construct($value) { $this->value = $value; }
        public function getRule() { return $this->value; }
    }; }
    public function method(bool $origin = false): string { return $this->testMethod; }
}

$tokens = [];
$tag = 'r9-' . bin2hex(random_bytes(6));
$password = bin2hex(random_bytes(20));
$entry = app()->make(EmployeeInternalLoginServices::class);
$login = app()->make(SystemAdminServices::class);
$menus = app()->make(SystemMenusServices::class);
$roles = app()->make(SystemRoleServices::class);
Db::startTrans();
try {
    $employeeId = (int)Db::name('employee')->insertGetId(['name' => $tag, 'status' => 1, 'is_del' => 0]);
    Db::name('employee_internal_account')->insert([
        'employee_id' => $employeeId, 'account' => $tag, 'pwd' => password_hash($password, PASSWORD_BCRYPT), 'status' => 1
    ]);
    $positionId = (int)Db::name('position')->insertGetId(['name' => $tag, 'status' => 1, 'use_platform' => 1]);
    $bindingId = (int)Db::name('staff_job_position')->insertGetId([
        'employee_id' => $employeeId, 'position_id' => $positionId, 'request_token' => $tag, 'status' => 1
    ]);
    expectR9($entry->employeeHasPlatformEntry($employeeId), 'enabled entry without menu rules permits login');
    $result = $login->login($tag, $password, 'admin');
    $tokens[] = $result['token'];
    expectR9(!empty($result['token']) && $result['menus'] === [] && $result['unique_auth'] === [], 'real password login returns session with empty menus and permissions');
    $adminId = (int)$result['user_info']['id'];
    Db::name('system_admin')->where('id', $adminId)->update(['phone' => $tag]);
    // Controller-owned SMS verification is not bypassed here; exercise its already-verified service path only.
    $sms = $login->login($tag, '', 'admin', true);
    $tokens[] = $sms['token'];
    expectR9(!empty($sms['token']) && $sms['menus'] === [] && $sms['unique_auth'] === [], 'verified SMS service path permits empty menus');
    expectR9($menus->getMenusList([], 1) === [[], []], 'empty roles do not inherit all permissions');
    expectR9(!empty($menus->getMenusList([], 0)[0]), 'legacy root menu scope unchanged');
    $emptyRole = (int)Db::name('system_role')->insertGetId(['role_name' => $tag, 'rules' => '', 'status' => 1]);
    expectR9($menus->getMenusList([$emptyRole], 1) === [[], []], 'empty role rules do not inherit all permissions');
    $controller = (new ReflectionClass(\app\controller\admin\Common::class))->newInstanceWithoutConstructor();
    foreach (['adminInfo' => ['level' => 1, 'roles' => []], 'adminType' => 0] as $property => $value) {
        $field = new ReflectionProperty(\app\controller\admin\AuthController::class, $property);
        $field->setAccessible(true);
        $field->setValue($controller, $value);
    }
    $search = $controller->menusList($menus, $roles)->getData();
    expectR9($search['data'] === [], 'empty permission menu search cannot share root cache');
    $rootMenu = (int)Db::name('system_menus')->where('pid', 0)->where('type', 1)->where('auth_type', 1)
        ->where('is_show', 1)->where('is_del', 0)->value('id');
    Db::name('system_role')->where('id', $emptyRole)->update(['rules' => (string)$rootMenu]);
    expectR9(!empty($menus->getMenusList([$emptyRole], 1)[0]), 'explicitly granted visible menu still returned');

    $registered = $roles->getAllRoles(2);
    $api = null;
    foreach ($registered as $row) {
        if (!empty($row['api_url']) && !empty($row['methods']) && !in_array($row['api_url'], ['menusList', 'setting/admin/logout'], true)) {
            $api = $row; break;
        }
    }
    if (!$api) throw new RuntimeException('No registered API fixture');
    $request = new R9PermissionRequest();
    $request->testRule = 'adminapi/' . $api['api_url'];
    $request->testMethod = strtolower($api['methods']);
    $request->adminInfo = ['roles' => []];
    try { $roles->verifiAuth($request); throw new RuntimeException('Empty permissions accepted registered API'); }
    catch (AuthException $expectedError) { echo 'PASS empty permissions deny registered business API' . PHP_EOL; }
    $request->testRule = 'adminapi/menusList';
    expectR9($roles->verifiAuth($request) === true, 'authenticated menu refresh remains available');

    foreach ([['position', $positionId, 'use_platform', 0], ['position', $positionId, 'status', 0],
        ['staff_job_position', $bindingId, 'status', 0], ['staff_job_position', $bindingId, 'end_time', time()],
        ['staff_job_position', $bindingId, 'is_del', 1]] as $case) {
        [$table, $id, $column, $value] = $case;
        $old = Db::name($table)->where('id', $id)->value($column);
        Db::name($table)->where('id', $id)->update([$column => $value]);
        expectR9(!$entry->employeeHasPlatformEntry($employeeId), 'disabled/ended entry denied: ' . $table . '.' . $column);
        try { $login->login($tag, $password, 'admin'); throw new RuntimeException('No entry accepted password login'); }
        catch (AdminException $error) { expectR9($error->getMessage() === '无权登录', 'password denial message'); }
        try { $login->verifyLogin($tag, '', true, 0); throw new RuntimeException('SMS path bypassed entry'); }
        catch (AdminException $error) { expectR9($error->getMessage() === '无权登录', 'SMS denial message'); }
        Db::name($table)->where('id', $id)->update([$column => $old]);
    }
} finally {
    Db::rollback();
    foreach ($tokens as $token) app()->make(\mohe\services\CacheService::class)->clearToken(md5($token));
}
expectR9(!Db::name('employee_internal_account')->where('account', $tag)->count(), 'synthetic fixture rolled back');
