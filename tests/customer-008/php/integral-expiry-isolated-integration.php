<?php
declare(strict_types=1);

/**
 * 积分到期隔离库回归：实际调用业务服务验证历史发生时间、余额/流水同事务、重试幂等。
 * 只接受显式的 integral_expiry_test 数据库；测试自行建立/清理其夹具表。
 */

use app\services\user\UserIntegralServices;
use think\facade\Db;

$backend = getenv('INTEGRAL_TEST_BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';
date_default_timezone_set('Asia/Shanghai');

$database = (string)getenv('DB_DATABASE');
if ($database !== 'integral_expiry_test') {
    throw new RuntimeException('只允许显式指定隔离测试库 integral_expiry_test');
}
$app = new think\App($backend . '/');
foreach ([
    'cache.driver' => 'file', 'CACHE_DRIVER' => 'file',
    'database.type' => 'mysql', 'DATABASE_TYPE' => 'mysql',
    'database.hostname' => getenv('DB_HOST') ?: '127.0.0.1',
    'DATABASE_HOSTNAME' => getenv('DB_HOST') ?: '127.0.0.1',
    'database.hostport' => getenv('DB_PORT') ?: '3306',
    'DATABASE_HOSTPORT' => getenv('DB_PORT') ?: '3306',
    'database.database' => $database, 'DATABASE_DATABASE' => $database,
    'database.username' => getenv('DB_USERNAME') ?: 'root',
    'DATABASE_USERNAME' => getenv('DB_USERNAME') ?: 'root',
    'database.password' => getenv('DB_PASSWORD') ?: '',
    'DATABASE_PASSWORD' => getenv('DB_PASSWORD') ?: '',
    'database.prefix' => 'eb_', 'DATABASE_PREFIX' => 'eb_',
] as $key => $value) {
    $app->env->set($key, $value);
}
$envName = new ReflectionProperty($app, 'envName');
$envName->setAccessible(true);
$envName->setValue($app, 'integral_expiry_test_skip_dotenv_reload');
$app->initialize();
$app->instance('sysConfig', new class {
    public function get(string $key)
    {
        return $key === 'integral_effective_status' ? 1 : '';
    }
});
if ((string)Db::query('SELECT DATABASE() AS db')[0]['db'] !== $database) {
    throw new RuntimeException('实际数据库与隔离库名称不符');
}

// 独立进程入口用于真实并发争用同一会员行，而不是在同一 PHP 进程重复调用。
$workerUid = (int)(getenv('INTEGRAL_TEST_WORKER_UID') ?: 0);
if ($workerUid > 0) {
    usleep(500000);
    app()->make(UserIntegralServices::class)->doClearExpireIntegral([$workerUid], [
        strtotime('2026-12-31 00:00:00'),
        strtotime('2025-01-01 00:00:00'),
        strtotime('2025-12-31 23:59:59'),
    ]);
    echo "PASS 并发工作进程 {$workerUid}\n";
    exit(0);
}
if ((string)getenv('INTEGRAL_TEST_CHECK_CONCURRENCY') === '1') {
    $uid = 6;
    if ((int)Db::name('user')->where('uid', $uid)->value('integral') !== 0
        || (int)Db::name('user_integral_expiry')->where('uid', $uid)->count() !== 1
        || (int)Db::name('user_bill')->where('uid', $uid)->where('type', 'system_clear')->count() !== 1) {
        throw new RuntimeException('并发清算未保持单次余额和流水');
    }
    echo "PASS 并发双进程只扣一次、只写一笔清零流水\n";
    exit(0);
}

// 最小结构保留生产代码实际读写的字段；历史账用原始 SQL 插入以保护 add_time。
Db::execute('DROP TABLE IF EXISTS eb_user_integral_expiry');
Db::execute('DROP TABLE IF EXISTS eb_user_bill');
Db::execute('DROP TABLE IF EXISTS eb_user');
Db::execute('CREATE TABLE eb_user (uid int unsigned NOT NULL PRIMARY KEY, integral int NOT NULL) ENGINE=InnoDB');
Db::execute("CREATE TABLE eb_user_bill (
  id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uid int unsigned NOT NULL, category varchar(32) NOT NULL, type varchar(48) NOT NULL,
  title varchar(100) NOT NULL DEFAULT '', mark varchar(255) NOT NULL DEFAULT '',
  pm tinyint NOT NULL, status tinyint NOT NULL, number int NOT NULL, balance int NOT NULL DEFAULT 0,
  link_id bigint NOT NULL DEFAULT 0, add_time int unsigned NOT NULL,
  obtain_time int unsigned NOT NULL DEFAULT 0, frozen_time int unsigned NOT NULL DEFAULT 0,
  KEY idx_uid_time (uid,add_time)) ENGINE=InnoDB");
Db::execute("CREATE TABLE eb_user_integral_expiry (
  id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uid int unsigned NOT NULL, period_start int unsigned NOT NULL, period_end int unsigned NOT NULL,
  clear_time int unsigned NOT NULL, number int unsigned NOT NULL DEFAULT 0,
  bill_id bigint unsigned NOT NULL DEFAULT 0, recorded_at int unsigned NOT NULL,
  UNIQUE KEY uniq_uid_period_start (uid,period_start)) ENGINE=InnoDB");

[$clearTime, $startTime, $endTime] = [
    strtotime('2026-12-31 00:00:00'),
    strtotime('2025-01-01 00:00:00'),
    strtotime('2025-12-31 23:59:59'),
];
$period = [$clearTime, $startTime, $endTime];
foreach ([1 => 140, 2 => 15, 3 => 25, 4 => 40, 5 => 10, 6 => 100] as $uid => $integral) {
    Db::name('user')->insert(['uid' => $uid, 'integral' => $integral]);
}
$bill = static function (int $uid, int $number, int $pm, string $time, string $type = 'gain'): void {
    Db::name('user_bill')->insert([
        'uid' => $uid, 'category' => 'integral', 'type' => $type, 'pm' => $pm,
        'status' => 1, 'number' => $number, 'add_time' => strtotime($time),
    ]);
};
$bill(1, 100, 1, '2025-01-10 12:00:00');
$bill(1, 50, 1, '2025-07-10 12:00:00');
$bill(1, 30, 0, '2025-10-10 12:00:00', 'deduction');
$bill(1, 20, 1, '2026-03-10 12:00:00');
$bill(2, 50, 1, '2025-08-10 12:00:00');
$bill(4, 40, 1, '2025-08-10 12:00:00');
$bill(4, 40, 0, '2026-12-31 08:00:00', 'system_clear');
$bill(5, 10, 1, '2025-08-10 12:00:00');
$bill(6, 100, 1, '2025-08-10 12:00:00');
if ((int)Db::name('user_bill')->where('uid', 1)->where('pm', 1)->min('add_time')
    !== strtotime('2025-01-10 12:00:00')) {
    throw new RuntimeException('历史积分取得时间未被保留');
}

$service = app()->make(UserIntegralServices::class);
if ($service->getUserClearIntegral(1, ['integral' => 140])[0] !== 120) {
    throw new RuntimeException('会员端到期预览与实际清算期间净额不一致');
}
$service->doClearExpireIntegral([1, 2, 3, 4], $period);
$balance = static function (int $uid): int {
    return (int)Db::name('user')->where('uid', $uid)->value('integral');
};
if ([$balance(1), $balance(2), $balance(3), $balance(4)] !== [20, 0, 25, 40]) {
    throw new RuntimeException('按 2025 年账清算或当前积分上限错误');
}
if ((int)Db::name('user_integral_expiry')->count() !== 4
    || (int)Db::name('user_bill')->where('type', 'system_clear')->count() !== 3) {
    throw new RuntimeException('清算守卫、零扣减或旧流水复用错误');
}
$service->doClearExpireIntegral([4, 3, 2, 1, 1], $period);
if ([$balance(1), $balance(2), $balance(3), $balance(4)] !== [20, 0, 25, 40]
    || (int)Db::name('user_bill')->where('type', 'system_clear')->count() !== 3) {
    throw new RuntimeException('重复任务再次扣分或写流水');
}

// 首次执行时余额已为零仍要写守卫；迟到的旧任务不能扣后来新取得的积分。
Db::name('user')->insert(['uid' => 8, 'integral' => 0]);
$bill(8, 50, 1, '2025-08-10 12:00:00');
$service->doClearExpireIntegral([8], $period);
Db::name('user')->where('uid', 8)->update(['integral' => 30]);
$service->doClearExpireIntegral([8], $period);
if ($balance(8) !== 30
    || (int)Db::name('user_integral_expiry')->where('uid', 8)->value('number') !== 0
    || (int)Db::name('user_bill')->where('uid', 8)->where('type', 'system_clear')->count() !== 0) {
    throw new RuntimeException('零余额会员的旧任务错误扣减了新积分');
}

// 在写流水时注入数据库错误，证明扣减与守卫均随事务回滚；解除故障后同一批次可重试。
Db::execute("CREATE TRIGGER reject_clear_for_uid5 BEFORE INSERT ON eb_user_bill
FOR EACH ROW BEGIN
  IF NEW.uid = 5 AND NEW.type = 'system_clear' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected ledger failure';
  END IF;
END");
$failed = false;
try {
    $service->doClearExpireIntegral([5], $period);
} catch (Throwable $e) {
    $failed = true;
}
if (!$failed || $balance(5) !== 10
    || (int)Db::name('user_integral_expiry')->where('uid', 5)->count() !== 0) {
    throw new RuntimeException('流水写入故障未整体回滚');
}
Db::execute('DROP TRIGGER reject_clear_for_uid5');
$service->doClearExpireIntegral([5], $period);
if ($balance(5) !== 0
    || (int)Db::name('user_integral_expiry')->where('uid', 5)->count() !== 1) {
    throw new RuntimeException('故障恢复后的重试未完成');
}

// 上一年清零流水发生在本年，却不能抵销本年新得积分的下一次到期数。
Db::name('user')->insert(['uid' => 7, 'integral' => 100]);
$bill(7, 100, 1, '2026-06-10 12:00:00');
$bill(7, 40, 0, '2026-12-31 08:00:00', 'system_clear');
$service->doClearExpireIntegral([7], [
    strtotime('2027-12-31 00:00:00'),
    strtotime('2026-01-01 00:00:00'),
    strtotime('2026-12-31 23:59:59'),
]);
if ($balance(7) !== 0
    || (int)Db::name('user_integral_expiry')->where('uid', 7)->value('number') !== 100) {
    throw new RuntimeException('上期清零流水错误冲减本期积分');
}

echo "PASS 历史时间/预览一致/2025窗口/余额上限/重复幂等/零余额迟到任务/旧清零复用/失败回滚及重试/跨年清零隔离\n";
