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
    'database.database' => getenv('DB_DATABASE') ?: 'ruihao_test_recovered_20260801', 'DATABASE_DATABASE' => getenv('DB_DATABASE') ?: 'ruihao_test_recovered_20260801',
    'database.username' => getenv('DB_USERNAME') ?: 'root', 'DATABASE_USERNAME' => getenv('DB_USERNAME') ?: 'root',
    'database.password' => getenv('DB_PASSWORD') ?: 'localdev123', 'DATABASE_PASSWORD' => getenv('DB_PASSWORD') ?: 'localdev123',
] as $key => $value) {
    $app->env->set($key, $value);
}
$envName = new ReflectionProperty($app, 'envName');
$envName->setAccessible(true);
$envName->setValue($app, 'direct_gift_member_detail_expiry_test_skip_reload_dotenv');
$app->initialize();
Config::set(['default' => 'file'], 'cache');
Db::connect('mysql', true)->query('SELECT 1');

$giftNo = trim((string)(getenv('DIRECT_GIFT_EXPIRY_GIFT_NO') ?: 'ZS26080800005'));
$fact = (array)Db::name('cashier_v3_gift_fact')->alias('gf')
    ->join('cashier_v3_direct_gift_authority ga', 'ga.gift_id=gf.source_id')
    ->leftJoin('cashier_v3_direct_gift_item gi', 'gi.gift_id=gf.source_id AND gi.item_id=gf.source_detail_id')
    ->leftJoin('user u', 'u.uid=gf.member_id')
    ->where('ga.gift_no', $giftNo)->where('gf.source_type', 'direct_gift')->where('gf.status', 'effective')
    ->field('gf.member_id,gf.tenant_id,gf.store_id,gf.content_name_snapshot,gf.content_snapshot_json,gi.content_snapshot_json AS item_content_snapshot_json,ga.validity_end')
    ->find();
if (!$fact) {
    throw new RuntimeException('DIRECT_GIFT_EXPIRY_TEST_FACT_MISSING');
}
$memberId = (int)$fact['member_id'];
$storeId = (int)$fact['store_id'];
$tenantId = (string)$fact['tenant_id'];
$organizationId = (string)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
if ($memberId <= 0 || $storeId <= 0 || $organizationId === '' || $organizationId === '0') {
    throw new RuntimeException('DIRECT_GIFT_EXPIRY_TEST_SCOPE_MISSING');
}

$snapshot = json_decode((string)($fact['item_content_snapshot_json'] ?: $fact['content_snapshot_json']), true);
$snapshot = is_array($snapshot) ? $snapshot : [];
$validityEnd = (int)($snapshot['validityEnd'] ?? $snapshot['validity_end'] ?? $fact['validity_end'] ?? 0);
if ($validityEnd <= 0) {
    throw new RuntimeException('DIRECT_GIFT_EXPIRY_TEST_VALUE_MISSING');
}
$expectedExpiresAt = date('Y-m-d H:i:s', $validityEnd);

$operator = new CashierV3OperatorScope($storeId, 1, $organizationId, $tenantId);
$scope = new CashierV3DataScopeContext(
    1, 1, $storeId, $tenantId, $organizationId, [$storeId],
    CashierV3DataScopeContext::MODE_STORES, [], false, '', 'direct-gift-expiry-integration', [],
    ['id' => 1, 'staff_name' => 'integration operator']
);
$detail = (new CashierV3MemberDetailQueryServices())->read($memberId, $operator, $scope, ['tab' => 'gift']);
$matches = [];
foreach ((array)($detail['giftRecords'] ?? []) as $record) {
    if ((string)($record['giftNo'] ?? '') !== $giftNo) {
        continue;
    }
    foreach ((array)($record['contents'] ?? []) as $content) {
        if ((string)($content['name'] ?? '') === (string)$fact['content_name_snapshot']) {
            $matches[] = $content;
        }
    }
}

$actualExpiresAt = (string)($matches[0]['expiresAt'] ?? '');
$checks = [
    'member-detail direct-gift record is returned' => count($matches) === 1,
    'member-detail direct-gift expiry equals immutable item snapshot' => $actualExpiresAt === $expectedExpiresAt,
    'member-detail direct-gift expiry is 2027-03-15 23:59:59' => $actualExpiresAt === '2027-03-15 23:59:59',
];
$failed = 0;
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$passed) {
        $failed++;
    }
}
echo 'DIRECT_GIFT_MEMBER_DETAIL_EXPIRY_MYSQL failed=' . $failed . PHP_EOL;
exit($failed === 0 ? 0 : 1);
