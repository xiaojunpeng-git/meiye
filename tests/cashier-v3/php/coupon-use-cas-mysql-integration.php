<?php
declare(strict_types=1);

$backend = getenv('CASHIER_V3_BACKEND') ?: '/var/www/html';
require $backend . '/vendor/autoload.php';

use app\dao\activity\coupon\StoreCouponUserDao;
use app\services\activity\coupon\StoreCouponIssueUserServices;
use think\facade\Config;
use think\facade\Db;

$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
foreach ([
    'cache.driver' => 'file', 'CACHE_DRIVER' => 'file',
    'database.hostname' => getenv('DB_HOST') ?: 'mysql', 'DATABASE_HOSTNAME' => getenv('DB_HOST') ?: 'mysql',
    'database.hostport' => getenv('DB_PORT') ?: '3306', 'DATABASE_HOSTPORT' => getenv('DB_PORT') ?: '3306',
    'database.database' => getenv('DB_DATABASE') ?: 'ruihao_test_recovered_20260801', 'DATABASE_DATABASE' => getenv('DB_DATABASE') ?: 'ruihao_test_recovered_20260801',
    'database.username' => getenv('DB_USERNAME') ?: 'root', 'DATABASE_USERNAME' => getenv('DB_USERNAME') ?: 'root',
    'database.password' => getenv('DB_PASSWORD') ?: 'localdev123', 'DATABASE_PASSWORD' => getenv('DB_PASSWORD') ?: 'localdev123',
] as $key => $value) $app->env->set($key, $value);
$envName = new ReflectionProperty($app, 'envName');
$envName->setAccessible(true);
$envName->setValue($app, 'coupon_use_cas_test_skip_reload_dotenv');
$app->initialize();
Config::set(['default' => 'file'], 'cache');
Db::connect('mysql', true)->query('SELECT 1');

$database = getenv('DB_DATABASE') ?: 'ruihao_test_recovered_20260801';
$pdoB = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST') ?: 'mysql', getenv('DB_PORT') ?: '3306', $database),
    getenv('DB_USERNAME') ?: 'root', getenv('DB_PASSWORD') ?: 'localdev123',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$prefix = 'CAS' . substr(hash('sha256', microtime(true) . '|' . getmypid()), 0, 12);
$now = time();
$uid = (int)(getenv('COUPON_CAS_TEST_UID') ?: 900000001);
$issueId = 0; $couponA = 0; $couponB = 0; $claimA = 0; $claimB = 0;
$passed = 0; $failed = 0;
$ok = static function (string $name, bool $condition) use (&$passed, &$failed): void {
    if ($condition) { $passed++; echo "PASS {$name}\n"; return; }
    $failed++; echo "FAIL {$name}\n";
};
try {
    $issueId = (int)Db::name('store_coupon_issue')->insertGetId([
        'cid' => 0, 'type' => 0, 'title' => $prefix, 'coupon_title' => $prefix, 'coupon_price' => '1.00',
        'use_min_price' => '0.00', 'coupon_time' => 1, 'start_time' => 0, 'end_time' => 0, 'start_use_time' => $now,
        'end_use_time' => $now + 86400, 'total_count' => 2, 'remain_count' => 0, 'is_permanent' => 0,
        'status' => 1, 'is_del' => 0, 'add_time' => $now,
    ]);
    foreach (['A', 'B'] as $suffix) {
        $couponId = (int)Db::name('store_coupon_user')->insertGetId([
            'cid' => $issueId, 'uid' => $uid, 'coupon_title' => $prefix . $suffix, 'coupon_price' => '1.00',
            'use_min_price' => '0.00', 'add_time' => $now, 'start_time' => $now, 'end_time' => $now + 86400,
            'use_time' => 0, 'type' => 'cashier_v3_recharge_gift', 'status' => 0, 'is_fail' => 0, 'oid' => 0,
        ]);
        $claimId = (int)Db::name('store_coupon_issue_user')->insertGetId(['uid' => $uid, 'issue_coupon_id' => $issueId, 'add_time' => $now]);
        Db::name('cashier_v3_recharge_gift_coupon_issue_mapping')->insert([
            'mapping_id' => $prefix . $suffix, 'tenant_id' => '0', 'store_id' => 1, 'member_id' => $uid,
            'recharge_id' => 900000000 + $couponId, 'gift_id' => $prefix, 'gift_item_id' => $prefix . '-ITEM-' . $suffix,
            'item_sequence' => 1, 'coupon_issue_id' => $issueId, 'coupon_user_id' => $couponId,
            'coupon_issue_user_id' => $claimId, 'status' => 'issued', 'void_operation_id' => '', 'voided_at' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        if ($suffix === 'A') { $couponA = $couponId; $claimA = $claimId; } else { $couponB = $couponId; $claimB = $claimId; }
    }

    $stale = $pdoB->prepare('SELECT status,is_fail,use_time FROM eb_store_coupon_user WHERE id=?');
    $stale->execute([$couponA]);
    $staleRow = (array)$stale->fetch(PDO::FETCH_ASSOC);
    $ok('connection B observes coupon A usable before void', (int)($staleRow['status'] ?? -1) === 0
        && (int)($staleRow['is_fail'] ?? -1) === 0 && (int)($staleRow['use_time'] ?? -1) === 0);
    Db::name('store_coupon_user')->where('id', $couponA)->where('status', 0)->where('is_fail', 0)->where('use_time', 0)->update(['status' => 2, 'is_fail' => 1]);
    Db::name('cashier_v3_recharge_gift_coupon_issue_mapping')->where('coupon_user_id', $couponA)->update(['status' => 'voided', 'void_operation_id' => $prefix . '-VOID', 'voided_at' => $now, 'updated_at' => $now]);
    $cas = $pdoB->prepare('UPDATE eb_store_coupon_user SET status=1,use_time=? WHERE id=? AND uid=? AND status=0 AND is_fail=0 AND use_time=0');
    $cas->execute([$now, $couponA, $uid]);
    $ok('stale connection cannot resurrect voided coupon', $cas->rowCount() === 0);

    /** @var StoreCouponUserDao $dao */
    $dao = app()->make(StoreCouponUserDao::class);
    $ok('coupon use CAS wins exactly once', $dao->useCoupon($couponB, $uid) === 1 && $dao->useCoupon($couponB, $uid) === 0);
    $voidAfterUse = (int)Db::name('store_coupon_user')->where('id', $couponB)->where('status', 0)->where('is_fail', 0)->where('use_time', 0)->update(['status' => 2, 'is_fail' => 1]);
    $ok('void cannot restore inventory after use wins', $voidAfterUse === 0);

    /** @var StoreCouponIssueUserServices $claims */
    $claims = app()->make(StoreCouponIssueUserServices::class);
    $ok('voided exact mapping is excluded while issued claim remains', $claims->effectiveClaimCount($uid, $issueId) === 1);
} finally {
    if ($couponA > 0 || $couponB > 0) Db::name('cashier_v3_recharge_gift_coupon_issue_mapping')->whereIn('coupon_user_id', array_filter([$couponA, $couponB]))->delete();
    if ($couponA > 0 || $couponB > 0) Db::name('store_coupon_user')->whereIn('id', array_filter([$couponA, $couponB]))->delete();
    if ($claimA > 0 || $claimB > 0) Db::name('store_coupon_issue_user')->whereIn('id', array_filter([$claimA, $claimB]))->delete();
    if ($issueId > 0) Db::name('store_coupon_issue')->where('id', $issueId)->delete();
}
echo "COUPON_USE_CAS_MYSQL passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
