<?php
declare(strict_types=1);

// Real service calls on MySQL 5.7; temporary tables shadow only this connection.
// A private Redis prefix prevents test reads/invalidations touching local users.
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql') {
    throw new RuntimeException('Local MySQL container only');
}
$cache = config('cache');
$prefix = 'member-center-r8-' . bin2hex(random_bytes(8)) . ':';
$cache['stores']['redis']['prefix'] = $prefix;
$cache['stores']['redis']['tag_prefix'] = $prefix . 'tag:';
config($cache, 'cache');

use app\services\diy\DiyServices;
use think\facade\Db;

function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function document(): array {
    return json_decode((string)Db::name('diy')->where('template_name', 'member')->value('value'), true);
}
function clearDiy(): void {
    app()->make(DiyServices::class)->cacheTag()->clear();
}
$tablePrefix = config('database.connections.mysql.prefix');
check((bool)preg_match('/^[a-zA-Z0-9_]+$/', $tablePrefix), 'Unsafe table prefix');
$tables = ['diy', 'system_group', 'system_group_data'];
try {
    foreach ($tables as $name) {
        $table = $tablePrefix . $name;
        $ddl = Db::query("SHOW CREATE TABLE `{$table}`")[0]['Create Table'];
        Db::execute(str_replace('CREATE TABLE `' . $table . '`',
            'CREATE TEMPORARY TABLE `' . $table . '`', $ddl));
    }
    $menu = json_decode((string)file_get_contents(__DIR__ . '/fixtures/accepted-service-menu.json'), true);
    $original = ['member' => ['style' => 2, 'property' => [0, 1, 2]], 'menu' => $menu];
    Db::name('diy')->insert(['template_name' => 'member', 'type' => 3,
        'value' => json_encode($original), 'add_time' => time()]);
    // Sentinel gid=0 data must neither leak into GET nor be erased by SAVE.
    Db::name('system_group_data')->insert(['gid' => 0, 'value' => '{}']);
    $service = app()->make(DiyServices::class);
    clearDiy();
    $loaded = $service->getMemberData();
    check(count($loaded['routine_my_menus']) === 14, 'Missing groups lost DIY menus');
    check($loaded['routine_my_menus'][0]['pic'] === [''], 'Icon-only upload shape changed');
    check($loaded['member']['member']['style'] === 2, 'Stored member style lost');
    $payload = ['member' => $loaded['member'], 'routine_my_menus' => $loaded['routine_my_menus'],
        'routine_my_banner' => []];
    $service->memberSaveData($payload);
    check(count($service->getMemberData()['routine_my_menus']) === 14, 'Save/refresh lost menus');
    check(count($service->getCacheDiyData('member')['menu']['list']) === 14, 'Member API lost menus');
    $response = (new app\controller\api\v1\PublicController(new think\facade\App()))->menu_user($app->request);
    $api = json_decode($response->getContent(), true);
    check($api['status'] === 200 && count($api['data']['diy_data']['menu']['list']) > 0,
        'Actual public member endpoint still returns no services');
    check($api['data']['routine_my_menus'] === [] && $api['data']['routine_my_banner'] === [],
        'Missing groups exposed unrelated gid=0 rows through legacy response fields');
    $urls = array_column($api['data']['diy_data']['menu']['list'], 'url');
    check(!in_array('/pages/users/user_spread_user/index', $urls, true), 'Anonymous promoter restriction lost');
    check($api['data']['diy_data']['merMenu']['list'] === []
        && $api['data']['diy_data']['storeMenu']['list'] === []
        && $api['data']['diy_data']['agentMenu']['list'] === [], 'Old merchant menus resurfaced');
    // Generated API response is consumed by isolated H5/mini visual tests only.
    file_put_contents('/tmp/member-center-r8-api.json', json_encode($api, JSON_UNESCAPED_UNICODE));
    check(Db::name('system_group_data')->where('gid', 0)->count() === 1, 'gid=0 was modified');
    check(Db::name('system_group')->count() === 0, 'Optional groups were synthesized');

    // Existing legacy mirrors retain their read/save contract.
    $fields = [['name' => '名称', 'title' => 'name', 'type' => 'input'],
        ['name' => '图片', 'title' => 'pic', 'type' => 'upload', 'required' => false],
        ['name' => '链接', 'title' => 'url', 'type' => 'input'],
        ['name' => '图标', 'title' => 'icon', 'type' => 'input', 'required' => false],
        ['name' => '类型', 'title' => 'type', 'type' => 'input']];
    $bannerId = Db::name('system_group')->insertGetId(['config_name' => 'routine_my_banner',
        'fields' => json_encode($fields)]);
    $menuId = Db::name('system_group')->insertGetId(['config_name' => 'routine_my_menus',
        'fields' => json_encode($fields)]);
    // Use editor-normalized strings, rather than legacy upload arrays, on POST.
    $payload['routine_my_menus'] = $menu['list'];
    $service->memberSaveData($payload);
    check(Db::name('system_group_data')->where('gid', $menuId)->count() === 14, 'Legacy mirror missing');
    check(count($service->getMemberData()['routine_my_menus']) === 14, 'Legacy GET missing');
    // Auto-match stays an empty visual selection in the authoritative document and mirrors.
    $auto = $payload;
    foreach ($auto['member']['menu']['list'] as &$item) { $item['pic'] = ''; $item['icon'] = ''; }
    unset($item);
    $auto['routine_my_menus'] = $auto['member']['menu']['list'];
    $service->memberSaveData($auto);
    $autoRead = $service->getCacheDiyData('member');
    check($autoRead['menu']['list'] === $auto['member']['menu']['list'], 'Auto-match save/read changed the visual choice');
    check(document()['menu']['list'] === $auto['member']['menu']['list'], 'Auto-match choice not persisted');
    check(Db::name('system_group_data')->where('gid', $menuId)->count() === 14, 'Auto-match legacy mirror missing');
    $service->memberSaveData($payload);
    $before = document();
    $mirrorBefore = Db::name('system_group_data')->order('id')->select()->toArray();
    $bad = $payload;
    $bad['member']['member']['style'] = 3;
    $bad['routine_my_banner'] = [['name' => 'rollback sentinel', 'pic' => '',
        'url' => '/pages/index/index', 'type' => 1]];
    $bad['routine_my_menus'][0]['name'] = '';
    $threw = false;
    try { $service->memberSaveData($bad); } catch (Throwable $e) { $threw = true; }
    check($threw, 'Injected second mirror failure did not fail');
    check(document() === $before, 'Failed save partially changed DIY document');
    check(Db::name('system_group_data')->order('id')->select()->toArray() === $mirrorBefore,
        'Failed save partially changed banner/menu mirrors');
    clearDiy();
    check(count($service->getMemberData()['routine_my_menus']) === 14, 'Rollback refresh lost menus');
    $payload['member']['menu']['list'] = [];
    $payload['routine_my_menus'] = [];
    $service->memberSaveData($payload);
    check($service->getCacheDiyData('member')['menu']['list'] === [], 'Explicit empty selection ignored');
    $threw = false;
    try { $service->memberSaveData(['member' => []]); } catch (Throwable $e) { $threw = true; }
    check($threw, 'Empty uninitialized document accepted');
    echo "member-center mysql PASS: missing groups, gid=0 isolation, save/refresh, mirrors, full rollback, explicit empty\n";
} finally {
    foreach (array_reverse($tables) as $name) {
        Db::execute('DROP TEMPORARY TABLE IF EXISTS `' . $tablePrefix . $name . '`');
    }
    $redis = think\facade\Cache::store('redis')->handler();
    $keys = $redis->keys($prefix . '*');
    if ($keys) $redis->del($keys);
}
