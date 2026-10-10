<?php
declare(strict_types=1);

$backend = getenv('CASHIER_V3_BACKEND') ?: '/var/www/html';
require $backend . '/vendor/autoload.php';

use app\services\cashier\v3\cashier\CashierV3CashierWorkspaceServices;
use think\facade\Config;
use think\facade\Db;

date_default_timezone_set('Asia/Shanghai');
$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
foreach ([
    'cache.driver' => 'file', 'CACHE_DRIVER' => 'file',
    'database.hostname' => getenv('DB_HOST') ?: 'mysql', 'DATABASE_HOSTNAME' => getenv('DB_HOST') ?: 'mysql',
    'database.hostport' => getenv('DB_PORT') ?: '3306', 'DATABASE_HOSTPORT' => getenv('DB_PORT') ?: '3306',
    'database.database' => getenv('DB_DATABASE') ?: 'ruihao', 'DATABASE_DATABASE' => getenv('DB_DATABASE') ?: 'ruihao',
    'database.username' => getenv('DB_USERNAME') ?: 'root', 'DATABASE_USERNAME' => getenv('DB_USERNAME') ?: 'root',
    'database.password' => getenv('DB_PASSWORD') ?: 'localdev123', 'DATABASE_PASSWORD' => getenv('DB_PASSWORD') ?: 'localdev123',
] as $key => $value) $app->env->set($key, $value);
$envName = new ReflectionProperty($app, 'envName');
$envName->setAccessible(true);
$envName->setValue($app, 'r40_coupon_threshold_test_skip_reload_dotenv');
$app->initialize();
Config::set(['default' => 'file'], 'cache');
Db::connect('mysql', true)->query('SELECT 1');

// Read-only regression against the gift shown in the cashier screenshot.
// The gift IDs identify the real coupon instances; no coupon is issued or used here.
$giftNo = getenv('R40_COUPON_TEST_GIFT_NO') ?: 'ZS26101000031';
$gift = (array)Db::name('cashier_v3_direct_gift_authority')->where('gift_no', $giftNo)
    ->field('gift_id,member_id,store_id')->find();
$item = $gift ? (array)Db::name('cashier_v3_direct_gift_item')
    ->where('gift_id', (string)$gift['gift_id'])->where('gift_kind', 'coupon')
    ->field('coupon_user_ids_json')->find() : [];
$ids = json_decode((string)($item['coupon_user_ids_json'] ?? ''), true);
$ids = is_array($ids) ? array_map('intval', $ids) : [];
if (!$gift || count($ids) !== 2) throw new RuntimeException('R40_COUPON_TEST_FIXTURE_MISSING');

$service = (new ReflectionClass(CashierV3CashierWorkspaceServices::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod(CashierV3CashierWorkspaceServices::class, 'couponOptions');
$method->setAccessible(true);
$read = static function (int $threshold, array $reserved = [], int $onlyId = 0) use ($method, $service, $gift): array {
    return $method->invoke($service, (int)$gift['member_id'], (int)$gift['store_id'],
        ['threshold' => $threshold, 'base' => $threshold, 'cap' => $threshold], false,
        'r40-coupon-threshold-read-only', 'line-1', $onlyId, $reserved);
};
$below = $read(396000);
$atMinimum = $read(1000000);
$fourItems = $read(1584000);
$threeItems = $read(1188000);
$only = $read(396000, [], $ids[0]);
$reserved = $read(396000, [$ids[0]]);
$belowIds = array_map('intval', array_column($below['belowMinimum'], 'couponId'));
$availableIds = array_map('intval', array_column($atMinimum['available'], 'couponId'));
sort($ids); sort($belowIds); sort($availableIds);
$checks = [
    'below-minimum coupons are visible but not usable' => $below['available'] === [] && $belowIds === $ids,
    'shortfall is computed against this sale line' => array_reduce($below['belowMinimum'],
        static fn(bool $ok, array $coupon): bool => $ok && (int)$coupon['useMinAmountCents'] === 1000000
            && (int)$coupon['shortfallCents'] === 604000, true),
    'meeting the line threshold makes both coupons usable' => $availableIds === $ids && $atMinimum['belowMinimum'] === [],
    'four items at 3960 each meet the 10000 threshold' => count($fourItems['available']) === 2
        && $fourItems['belowMinimum'] === []
        && array_reduce($fourItems['available'], static fn(bool $ok, array $coupon): bool => $ok
            && (int)$coupon['discountAmountCents'] === 100000, true),
    'three items at 3960 each still meet the threshold' => count($threeItems['available']) === 2
        && $threeItems['belowMinimum'] === [],
    'write guard sees no usable coupon below the threshold' => $only['available'] === []
        && count($only['belowMinimum']) === 1,
    'coupon reserved by another line is not offered again' => count($reserved['belowMinimum']) === 1
        && (int)$reserved['belowMinimum'][0]['couponId'] !== $ids[0],
];
$failed = 0;
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$passed) $failed++;
}
echo 'R40_COUPON_THRESHOLD_SELECTOR_MYSQL failed=' . $failed . PHP_EOL;
exit($failed === 0 ? 0 : 1);
