<?php
declare(strict_types=1);

// Exercise the real service/controllers on a connection-local MySQL 5.7 table.
// No production connection, shared DIY document or customer cache may be changed.
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql') {
    throw new RuntimeException('Local MySQL container only');
}
$cache = config('cache');
$prefix = 'theme-save-test-' . bin2hex(random_bytes(8)) . ':';
$cache['stores']['redis']['prefix'] = $prefix;
$cache['stores']['redis']['tag_prefix'] = $prefix . 'tag:';
config($cache, 'cache');

use app\dao\diy\DiyDao;
use app\services\diy\DiyServices;
use think\facade\Db;

function ensure(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function fails(callable $fn, string $message): void {
    try { $fn(); } catch (Throwable $error) { return; }
    throw new RuntimeException($message);
}
function themeValueForTest(): string {
    return (string)Db::name('diy')->where('template_name', 'color_change')->where('type', 3)->value('value');
}

// Inject a failure after the real SQL mutation: the service must roll it back,
// not return a successful save or poison the previously committed cache.
class FailingThemeDao extends DiyDao {
    public function update($id, array $data, ?string $key = null) {
        parent::update($id, $data, $key);
        throw new RuntimeException('Injected update failure');
    }
    public function save(array $data) {
        parent::save($data);
        throw new RuntimeException('Injected insert failure');
    }
}
class ThemeControllerProbe extends app\controller\admin\v1\diy\Diy {
    public function __construct(DiyServices $services) { $this->services = $services; }
}

$table = config('database.connections.mysql.prefix') . 'diy';
ensure((bool)preg_match('/^[a-zA-Z0-9_]+$/', $table), 'Unsafe table name');
$lockName = 'diy_theme_' . substr(hash('sha256', (string)Db::query('SELECT DATABASE() AS db')[0]['db']), 0, 40);
try {
    $ddl = Db::query('SHOW CREATE TABLE `' . $table . '`')[0]['Create Table'];
    Db::execute(str_replace('CREATE TABLE `' . $table . '`', 'CREATE TEMPORARY TABLE `' . $table . '`', $ddl));
    Db::name('diy')->insert(['template_name' => 'default', 'type' => 1, 'value' => '{"sentinel":"page"}']);
    Db::name('diy')->insert(['template_name' => 'category', 'type' => 3, 'value' => '2']);
    $protected = Db::name('diy')->order('id')->select()->toArray();
    $service = app()->make(DiyServices::class);
    $controller = new ThemeControllerProbe($service);
    $failing = new DiyServices(new FailingThemeDao());
    fails(static function () use ($failing) { $failing->saveThemeColor(7); }, 'Failed insert reported success');
    ensure(Db::name('diy')->count() === 2, 'Failed first save left a record');

    $response = json_decode($controller->colorChange('7', 'color_change')->getContent(), true);
    ensure($response['status'] === 200 && themeValueForTest() === '7', 'First wine save failed');
    $id = Db::name('diy')->where('template_name', 'color_change')->value('id');
    $controller->colorChange('7', 'color_change');
    ensure(Db::name('diy')->where('template_name', 'color_change')->count() === 1, 'Repeat save duplicated setting');
    ensure(Db::name('diy')->where('template_name', 'color_change')->value('id') === $id, 'Repeat save replaced identity');

    // Both administrative refresh and public member theme reads use the saved row.
    $admin = json_decode($controller->getColorChange('color_change')->getContent(), true);
    $public = new app\controller\api\v2\PublicController(new think\facade\App());
    $member = json_decode($public->colorChange($service, 'color_change')->getContent(), true);
    ensure($admin['data']['status'] === 7 && $member['data']['status'] === 7, 'Admin/member read disagree');
    foreach ([1, 2, 3, 4, 5, 6, 7] as $status) {
        $controller->colorChange((string)$status, 'color_change');
        ensure(themeValueForTest() === (string)$status, 'Existing theme update failed');
    }
    foreach ([0, 8, -1, '1.5', '7x', '', [], true, 7.0] as $status) {
        fails(static function () use ($service, $status) { $service->saveThemeColor($status); }, 'Invalid palette accepted');
        ensure(themeValueForTest() === '7', 'Invalid palette changed saved theme');
    }
    $service->cacheStrUpdate('color_change_color_change_3', '7');
    fails(static function () use ($failing) { $failing->saveThemeColor(2); }, 'Failed update reported success');
    ensure(themeValueForTest() === '7' && (int)$service->getColorChange('color_change') === 7, 'Rollback changed database/cache');
    $service->cacheStrUpdate('color_change_color_change_3', '3');
    $service->saveThemeColor(7);
    ensure((int)$service->getColorChange('color_change') === 7, 'Successful save kept stale red cache');
    ensure(Db::name('diy')->where('template_name', '<>', 'color_change')->order('id')->select()->toArray() === $protected,
        'Theme save touched another document');

    // A corrupt legacy duplicate must be rejected instead of arbitrarily choosing a row.
    $duplicate = Db::name('diy')->insertGetId(['template_name' => 'color_change', 'type' => 3, 'value' => '1']);
    fails(static function () use ($service) { $service->saveThemeColor(2); }, 'Duplicate setting silently overwritten');
    Db::name('diy')->where('id', $duplicate)->delete();
    ensure(themeValueForTest() === '7', 'Duplicate rejection changed authority');
    ensure((int)Db::query('SELECT IS_FREE_LOCK(?) AS available', [$lockName])[0]['available'] === 1,
        'A failure leaked its connection lock');
    // A second connection owns the instance lock: the command must time out
    // without writing; after release the ordinary retry must still succeed.
    $connection = config('database.connections.mysql');
    $competitor = new PDO('mysql:host=mysql;port=' . $connection['hostport'] . ';dbname=' . $connection['database'],
        $connection['username'], $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $take = $competitor->prepare('SELECT GET_LOCK(?, 0)');
    $take->execute([$lockName]);
    ensure((int)$take->fetchColumn() === 1, 'Contention setup failed');
    try {
        fails(static function () use ($service) { $service->saveThemeColor(2); }, 'Concurrent writer bypassed lock');
        ensure(themeValueForTest() === '7', 'Busy command changed theme');
    } finally {
        $competitor->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    }
    $service->saveThemeColor(7);
    $category = json_decode($controller->colorChange('1', 'category')->getContent(), true);
    ensure($category['status'] === 200 && (int)$service->getColorChange('category') === 1,
        'Existing category command changed');
    $unknown = json_decode($controller->colorChange('7', 'not-a-theme')->getContent(), true);
    ensure($unknown['status'] !== 200 && Db::name('diy')->count() === 3, 'Unknown type created a document');
    file_put_contents('/tmp/theme-save-member-response.json', json_encode($member));
    echo "PASS theme first/repeat/save-refresh; all palettes; invalid values; SQL rollback; cache refresh; document isolation; duplicate rejection; lock cleanup/contention; category compatibility\n";
} finally {
    Db::execute('DROP TEMPORARY TABLE IF EXISTS `' . $table . '`');
    $redis = think\facade\Cache::store('redis')->handler();
    $keys = $redis->keys($prefix . '*');
    if ($keys) $redis->del($keys);
}
