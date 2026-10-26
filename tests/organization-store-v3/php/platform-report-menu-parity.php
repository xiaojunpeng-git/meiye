<?php
/** Local rollback regression: each granted report menu reaches the login tree. */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();

use think\facade\Db;
use app\services\system\SystemMenusServices;
use app\services\system\SystemRoleServices;

if (!getenv('MOHE_LOCAL_DB_HOSTNAME') || config('database.connections.mysql.hostname') !== 'mysql'
    || Db::query('SELECT @@hostname AS host')[0]['host'] !== getenv('MOHE_LOCAL_DB_HOSTNAME')) {
    throw new RuntimeException('Local Docker database guard failed');
}
function menuParityCheck(bool $ok, string $name): void {
    if (!$ok) throw new RuntimeException($name);
    echo 'PASS ' . $name . PHP_EOL;
}
function flattenReportMenus(array $nodes): array {
    $result = [];
    foreach ($nodes as $node) {
        $result[(int)$node['id']] = $node;
        $result += flattenReportMenus($node['children'] ?? []);
    }
    return $result;
}
$account = getenv('MOHE_TEST_PLATFORM_ACCOUNT');
$admin = Db::name('system_admin')->where('account', $account)->find();
if (!$admin || !(int)$admin['employee_id']) throw new RuntimeException('Employee account required');
$roles = array_map('intval', explode(',', $admin['roles']));
$roleService = app()->make(SystemRoleServices::class);
$menus = app()->make(SystemMenusServices::class);
$grants = array_map('intval', $roleService->getRoleIds($roles));
$before = Db::name('system_menus')->order('id')->column('is_show', 'id');
$sql = file_get_contents('/var/www/html/database/upgrades/2026-10-14-岗位已授权数据目录显示/02-正式升级.sql');
$tokens = [];
Db::startTrans();
try {
    Db::execute($sql);
    menuParityCheck(Db::execute($sql) === 0, 'directory migration is idempotent');
    $after = Db::name('system_menus')->order('id')->column('is_show', 'id');
    $changed = array_keys(array_diff_assoc($after, $before));
    $restored = Db::name('system_menus')->whereIn('unique_auth', ['admin-report-sale', 'admin-report-other-reports'])->column('id');
    menuParityCheck(!array_diff($changed, $restored), 'no unrelated menu visibility changed');
    menuParityCheck($grants === array_map('intval', $roleService->getRoleIds($roles)), 'role grants remain unchanged');
    [$tree, $auth] = $menus->getMenusList($roles, (int)$admin['level']);
    $flat = flattenReportMenus($tree);
    $root = (int)Db::name('system_menus')->where('unique_auth', 'admin-report')->value('id');
    $rows = Db::name('system_menus')->where('type', 1)->where('is_del', 0)->select()->toArray();
    $byId = array_column($rows, null, 'id');
    $expected = [];
    // Exercise the report controller's real authorization decision using the
    // local account projection, without issuing writes or forging HTTP tokens.
    $controllerClass = new ReflectionClass(\app\controller\admin\v1\report\UnifiedReport::class);
    $controller = $controllerClass->newInstanceWithoutConstructor();
    foreach (['adminInfo' => $admin, 'adminType' => (int)$admin['admin_type']] as $property => $value) {
        $field = $controllerClass->getProperty($property);
        $field->setAccessible(true);
        $field->setValue($controller, $value);
    }
    $reportGate = $controllerClass->getMethod('canAccessReport');
    $reportGate->setAccessible(true);
    $reportCount = 0;
    foreach ($rows as $row) {
        if ((int)$row['auth_type'] !== 1 || !(int)$row['is_show'] || (int)$row['is_show_path']
            || !in_array((int)$row['id'], $grants, true)) continue;
        $parent = (int)$row['pid']; $seen = [];
        while ($parent && $parent !== $root && isset($byId[$parent]) && !isset($seen[$parent])) {
            $seen[$parent] = true;
            $parent = (int)$byId[$parent]['pid'];
        }
        if ($parent !== $root) continue;
        $expected[] = (int)$row['id'];
        menuParityCheck(isset($flat[$row['id']]), 'login menu includes ' . $row['menu_name']);
        if ($row['unique_auth']) menuParityCheck(in_array($row['unique_auth'], $auth, true), 'permission includes ' . $row['unique_auth']);
        if (preg_match('/^admin-report-(?:store-operations|six-dimension|phase-four|phase-six)-(.+)$/', $row['unique_auth'], $match)) {
            menuParityCheck($reportGate->invoke($controller, $match[1]), 'report controller permits ' . $match[1]);
            $reportCount++;
        }
    }
    $groups = array_values(array_filter($rows, static function ($row) use ($root, $grants) {
        return (int)$row['pid'] === $root && (int)$row['auth_type'] === 1 && in_array((int)$row['id'], $grants, true);
    }));
    echo json_encode(['groups' => array_column($groups, 'menu_name'), 'granted_data_menus' => count($expected), 'report_permission_checks' => $reportCount], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    // Test a fresh password login with the same saved job rules without touching
    // the user's password/session. All synthetic rows and tokens are discarded.
    $tag = 'menu-parity-' . bin2hex(random_bytes(6));
    $password = bin2hex(random_bytes(20));
    $employee = (int)Db::name('employee')->insertGetId(['name' => $tag, 'status' => 1, 'is_del' => 0]);
    Db::name('employee_internal_account')->insert([
        'employee_id' => $employee, 'account' => $tag, 'pwd' => password_hash($password, PASSWORD_BCRYPT), 'status' => 1,
    ]);
    $position = (int)Db::name('position')->insertGetId(['name' => $tag, 'status' => 1, 'use_platform' => 1]);
    Db::name('staff_job_position')->insert([
        'employee_id' => $employee, 'position_id' => $position, 'request_token' => $tag, 'status' => 1,
    ]);
    Db::name('job_position_channel_rule')->insert([
        'position_id' => $position, 'channel' => 'platform', 'rules' => implode(',', $grants), 'status' => 1,
    ]);
    $login = app()->make(\app\services\system\admin\SystemAdminServices::class)->login($tag, $password, 'admin');
    $tokens[] = $login['token'];
    $fresh = flattenReportMenus($login['menus']);
    menuParityCheck(!array_diff($expected, array_keys($fresh)), 'fresh password login retains all granted data menus');
    // Unselected siblings, empty roles and disabled roles must never inherit grants.
    foreach ($rows as $row) {
        if ((int)$row['pid'] === $root || !isset($flat[$row['id']])) continue;
        if (in_array((int)$row['id'], [2531, 2532, 2533], true) && !in_array((int)$row['id'], $grants, true)) {
            throw new RuntimeException('Unselected target permission leaked');
        }
    }
    menuParityCheck($menus->getMenusList([], 1) === [[], []], 'empty role remains empty');
    Db::name('system_role')->whereIn('id', $roles)->update(['status' => 0]);
    menuParityCheck($menus->getMenusList($roles, 1) === [[], []], 'disabled roles remain empty');
} finally {
    Db::rollback();
    foreach ($tokens as $token) app()->make(\mohe\services\CacheService::class)->clearToken(md5($token));
}
echo 'PASS all test writes rolled back' . PHP_EOL;
