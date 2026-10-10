<?php
declare(strict_types=1);

$backend = getenv('CASHIER_V3_BACKEND') ?: '/var/www/html';
require $backend . '/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\member\CashierV3MemberDetailQueryServices;
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
$envName->setValue($app, 'member_coupon_detail_test_skip_reload_dotenv');
$app->initialize();
Config::set(['default' => 'file'], 'cache');
Db::connect('mysql', true)->query('SELECT 1');

// This regression fixture is a real direct gift with two live coupon instances.
// Its gift fact proves issuance, while the member-owned coupon rows prove current rights.
$giftNo = getenv('MEMBER_COUPON_TEST_GIFT_NO') ?: 'ZS26101000031';
$gift = (array)Db::name('cashier_v3_direct_gift_authority')->where('gift_no', $giftNo)
    ->field('gift_id,member_id,tenant_id,store_id')->find();
if (!$gift) throw new RuntimeException('MEMBER_COUPON_TEST_GIFT_MISSING');
$item = (array)Db::name('cashier_v3_direct_gift_item')->where('gift_id', (string)$gift['gift_id'])
    ->where('gift_kind', 'coupon')->field('coupon_user_ids_json')->find();
$issuedIds = json_decode((string)($item['coupon_user_ids_json'] ?? ''), true);
$issuedIds = is_array($issuedIds) ? array_map('strval', $issuedIds) : [];
if ($issuedIds === []) throw new RuntimeException('MEMBER_COUPON_TEST_ISSUANCE_MISSING');
$storeId = (int)$gift['store_id'];
$tenantId = (string)$gift['tenant_id'];
$orgId = (string)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
$operator = new CashierV3OperatorScope($storeId, 1, $orgId, $tenantId);
$scope = new CashierV3DataScopeContext(1, 1, $storeId, $tenantId, $orgId, [$storeId],
    CashierV3DataScopeContext::MODE_STORES, [], false, '', 'member-coupon-detail-test', [],
    ['id' => 1, 'staff_name' => 'integration operator']);
$reader = new CashierV3MemberDetailQueryServices();
$active = $reader->read((int)$gift['member_id'], $operator, $scope,
    ['tab' => 'coupons', 'status' => 'active', 'dateFrom' => '2000-01-01', 'dateTo' => '2000-01-01']);
$activeRows = (array)$active['coupons'];
$activeIds = array_column($activeRows, 'couponNo');
$all = $reader->read((int)$gift['member_id'], $operator, $scope, ['tab' => 'coupons', 'status' => '']);
$allRows = (array)$all['coupons'];
$checks = [
    'issued coupon instances appear under member rights' => count(array_intersect($issuedIds, $activeIds)) === count($issuedIds),
    'effective coupons ignore stale date filters' => count($activeRows) >= count($issuedIds),
    'each coupon keeps its physical instance identity' => count($activeIds) === count(array_unique($activeIds)),
    'active status is read from current coupon instances' => array_reduce($activeRows,
        static fn(bool $ok, array $row): bool => $ok && $row['statusLabel'] === '有效', true),
    'all status retains voided history without treating it as active' => count($allRows) > count($activeRows)
        && count(array_filter($allRows, static fn(array $row): bool => $row['statusLabel'] === '已作废')) > 0,
];
$failed = 0;
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$passed) $failed++;
}
echo 'MEMBER_COUPON_DETAIL_MYSQL failed=' . $failed . PHP_EOL;
exit($failed === 0 ? 0 : 1);
