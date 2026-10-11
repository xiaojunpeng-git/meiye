<?php
/**
 * Local database regression for leaf-only platform grants. All menu/role fixtures
 * live inside one rollback transaction; no login tokens, real grants or stock writes.
 */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();

use think\facade\Db;
use app\services\system\SystemMenusServices;

if (!getenv('MOHE_LOCAL_DB_HOSTNAME') || config('database.connections.mysql.hostname') !== 'mysql'
    || Db::query('SELECT @@hostname AS host')[0]['host'] !== getenv('MOHE_LOCAL_DB_HOSTNAME')) {
    throw new RuntimeException('Local Docker database guard failed');
}
function menuAncestorCheck(bool $ok, string $name): void {
    if (!$ok) throw new RuntimeException($name);
    echo 'PASS ' . $name . PHP_EOL;
}
/** Flatten navigation IDs without treating their presence as API authorization. */
function menuAncestorIds(array $tree): array {
    $ids = [];
    foreach ($tree as $node) {
        $ids[] = (int)$node['id'];
        $ids = array_merge($ids, menuAncestorIds($node['children'] ?? []));
    }
    sort($ids);
    return $ids;
}

$tag = 'ancestor-' . bin2hex(random_bytes(5));
$service = app()->make(SystemMenusServices::class);
$tokens = [];
Db::startTrans();
try {
    $insert = static function (int $pid, string $name, array $extra = []) use ($tag): int {
        return (int)Db::name('system_menus')->insertGetId(array_merge([
            'pid' => $pid, 'menu_name' => $tag . '-' . $name, 'menu_path' => '/admin/' . $tag . '/' . $name,
            'type' => 1, 'auth_type' => 1, 'is_show' => 1, 'is_del' => 0, 'unique_auth' => $tag . '-' . $name,
        ], $extra));
    };
    $root = $insert(0, 'root');
    $group = $insert($root, 'group');
    $leaf = $insert($group, 'leaf');
    $otherLeaf = $insert($group, 'other-leaf');
    $sibling = $insert($group, 'ungranted-sibling');
    $otherRoot = $insert(0, 'ungranted-root');
    $api = $insert($leaf, 'api', ['auth_type' => 2, 'is_show' => 0]);
    $role = (int)Db::name('system_role')->insertGetId(['role_name' => $tag, 'status' => 1, 'rules' => (string)$leaf]);
    $query = static function (array $ids) use ($role, $service): array {
        Db::name('system_role')->where('id', $role)->update(['rules' => implode(',', $ids)]);
        return $service->getMenusList([$role], 1);
    };
    [$menus, $auth] = $query([$leaf]);
    $expected = [$root, $group, $leaf]; sort($expected);
    menuAncestorCheck(menuAncestorIds($menus) === $expected, 'leaf-only grant restores full navigation ancestry');
    menuAncestorCheck($auth === [$tag . '-leaf'], 'ancestor permission identifiers are not granted');
    menuAncestorCheck(!in_array($sibling, menuAncestorIds($menus), true)
        && !in_array($otherRoot, menuAncestorIds($menus), true), 'ungranted siblings and roots remain absent');
    menuAncestorCheck(Db::name('system_role')->where('id', $role)->value('rules') === (string)$leaf, 'navigation assembly does not mutate stored grants');
    [$menus, $auth] = $query([$leaf, $otherLeaf, $api]);
    $expected = [$root, $group, $leaf, $otherLeaf]; sort($expected);
    menuAncestorCheck(menuAncestorIds($menus) === $expected, 'shared ancestors are deduplicated across granted leaves');
    $expectedAuth = [$tag . '-leaf', $tag . '-other-leaf', $tag . '-api']; sort($expectedAuth); sort($auth);
    menuAncestorCheck($auth === $expectedAuth, 'API grants remain exactly the original rule union');
    [$menus, $auth] = $query([$api]);
    menuAncestorCheck($menus === [] && $auth === [$tag . '-api'], 'API-only grant does not invent a navigation page');
    menuAncestorCheck($query([]) === [[], []] && $service->getMenusList([], 1) === [[], []], 'truly empty grants remain an empty workspace');

    // 复现瑞昊账号的 39 个授权 ID，仅赋给事务内的本地合成角色，不改真实员工。
    $rhRules = [3,170,171,172,173,174,175,176,177,178,578,1944,1945,1946,1947,1948,1949,1950,1951,1952,2007,2011,2012,2013,2014,2099,2126,2127,2128,2129,2130,2131,2132,2133,2134,2135,2280,2281,2282];
    [$rhMenus, $rhAuth] = $query($rhRules);
    menuAncestorCheck(count($rhMenus) === 1 && (int)$rhMenus[0]['id'] === 1570, 'reported grant shape restores the product navigation root');
    $rhIds = menuAncestorIds($rhMenus);
    menuAncestorCheck(!array_diff([1,3,1943,1944,1945,1946,1947,2007,2011,2013,2099], $rhIds), 'reported authorized product and inventory pages are reachable');
    menuAncestorCheck(!array_intersect([2,2008,7,12,1572], $rhIds), 'reported grant does not expose product list, salon usage or other sections');
    $originalAuth = Db::name('system_menus')->whereIn('id', $rhRules)->where('type', 1)->where('is_del', 0)
        ->where('unique_auth', '<>', '')->column('unique_auth');
    sort($rhAuth); sort($originalAuth);
    menuAncestorCheck($rhAuth === $originalAuth && count($rhAuth) === 20, 'reported original 20 permission identifiers are unchanged');

    // 真正经过统一账号密码登录及岗位投影，避免只测树函数而漏掉登录组合路径。
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
        'position_id' => $position, 'channel' => 'platform', 'rules' => implode(',', $rhRules), 'status' => 1,
    ]);
    $login = app()->make(\app\services\system\admin\SystemAdminServices::class)->login($tag, $password, 'admin');
    $tokens[] = $login['token'];
    menuAncestorCheck(!empty($login['token']) && menuAncestorIds($login['menus']) === $rhIds, 'real password login returns reachable authorized navigation');
    $loginAuth = $login['unique_auth']; sort($loginAuth);
    menuAncestorCheck($loginAuth === $originalAuth, 'real login does not mint ancestor or sibling permissions');

    foreach ([['is_show' => 0], ['is_del' => 1], ['type' => 2], ['auth_type' => 2]] as $invalid) {
        Db::name('system_menus')->where('id', $group)->update($invalid);
        [$menus, $auth] = $query([$leaf]);
        menuAncestorCheck($menus === [] && $auth === [$tag . '-leaf'], 'invalid ancestor is not bypassed: ' . key($invalid));
        Db::name('system_menus')->where('id', $group)->update(['is_show' => 1, 'is_del' => 0, 'type' => 1, 'auth_type' => 1]);
    }
    Db::name('system_menus')->where('id', $group)->update(['pid' => $leaf]);
    menuAncestorCheck($query([$leaf])[0] === [], 'cyclic ancestry terminates without fabricating a root');
    Db::name('system_menus')->where('id', $group)->update(['pid' => $root]);
    Db::name('system_menus')->where('id', $group)->delete();
    menuAncestorCheck($query([$leaf])[0] === [], 'missing ancestor remains fail-closed');
    menuAncestorCheck(!empty($service->getMenusList([], 0)[0]), 'super-admin menu behavior remains unchanged');
} finally {
    Db::rollback();
    foreach ($tokens as $token) app()->make(\mohe\services\CacheService::class)->clearToken(md5($token));
}
menuAncestorCheck(!Db::name('system_role')->where('role_name', $tag)->count()
    && !Db::name('employee_internal_account')->where('account', $tag)->count(), 'synthetic fixture rolled back');
