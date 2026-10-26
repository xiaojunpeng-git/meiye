<?php
/** Real local DB/Redis regression: role ID remains stable while grants change. */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();

use think\facade\Db;
use app\services\system\SystemRoleServices;
use mohe\services\CacheService;

if (!getenv('MOHE_LOCAL_DB_HOSTNAME') || config('database.connections.mysql.hostname') !== 'mysql'
    || Db::query('SELECT @@hostname AS host')[0]['host'] !== getenv('MOHE_LOCAL_DB_HOSTNAME')) {
    throw new RuntimeException('Local Docker database guard failed');
}
function roleCacheCheck(bool $ok, string $name): void {
    if (!$ok) throw new RuntimeException($name);
    echo 'PASS ' . $name . PHP_EOL;
}
$tag = 'role-cache-' . bin2hex(random_bytes(6));
$cacheKeys = [];
$service = app()->make(SystemRoleServices::class);
Db::startTrans();
try {
    $insert = static function (string $name, int $authType = 1) use ($tag): int {
        return (int)Db::name('system_menus')->insertGetId([
            'menu_name' => $tag . $name, 'unique_auth' => $tag . $name,
            'type' => 1, 'auth_type' => $authType, 'is_show' => 1, 'is_del' => 0,
        ]);
    };
    $first = $insert('-first');
    $second = $insert('-second');
    $api = $insert('-api', 2);
    $role = (int)Db::name('system_role')->insertGetId([
        'role_name' => $tag, 'status' => 1, 'rules' => (string)$first,
    ]);
    // Unique cache prefix isolates fixtures; record both old/new key formats for cleanup.
    $read = static function (int $authType = 1) use ($service, $role, $tag, &$cacheKeys): array {
        $ids = array_map('intval', $service->getRoleIds([$role])); sort($ids);
        $cacheKeys[] = md5($tag . '_' . $authType . '_1_' . $role);
        $cacheKeys[] = md5($tag . '_rules_v2_' . $authType . '_1_' . implode('_', $ids));
        $auth = array_column($service->getRolesByAuth([$role], $authType, 1, $tag), 'unique_auth');
        sort($auth);
        return $auth;
    };
    roleCacheCheck($read() === [$tag . '-first'], 'warm initial report permission cache');
    Db::name('system_role')->where('id', $role)->update(['rules' => "$first,$second,$api"]);
    roleCacheCheck($read() === [$tag . '-first', $tag . '-second'], 'new report grant is effective with unchanged role ID');
    roleCacheCheck($read(2) === [$tag . '-api'], 'API permission cache remains separate from menu permissions');
    Db::name('system_role')->where('id', $role)->update(['rules' => (string)$second]);
    roleCacheCheck($read() === [$tag . '-second'] && $read(2) === [], 'revoked report and API grants disappear immediately');
    Db::name('system_role')->where('id', $role)->update(['status' => 0]);
    roleCacheCheck($read() === [], 'disabled role cannot reuse a warm permission cache');
    Db::name('system_role')->where('id', $role)->update(['status' => 1, 'rules' => '']);
    roleCacheCheck($read() === [] && $service->getRolesByAuth([]) === [], 'empty grants and empty roles remain denied');
} finally {
    Db::rollback();
    foreach (array_unique($cacheKeys) as $key) CacheService::redisHandler()->delete($key);
}
echo 'PASS all database fixtures rolled back and test cache keys removed' . PHP_EOL;
