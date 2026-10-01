<?php
declare(strict_types=1);

/**
 * Read-only member gift/presale integration check against a local imported-data DB.
 * The caller must select a member with historical project and product rights;
 * this test never creates gifts, claims stock, or changes another customer's data.
 */
$backend = getenv('CASHIER_V3_BACKEND') ?: '/var/www/html';
$phone = trim((string)getenv('MEMBER_GIFT_TEST_PHONE'));
if ($phone === '') throw new RuntimeException('MEMBER_GIFT_TEST_PHONE_REQUIRED');
require $backend . '/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\member\CashierV3MemberDetailQueryServices;
use think\facade\Config;
use think\facade\Db;

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
$envName->setValue($app, 'member_gift_presale_integration_skip_dotenv_reload');
$app->initialize();
Config::set(['default' => 'file'], 'cache');
Db::connect('mysql', true)->query('SELECT 1');

$member = (array)Db::name('user')->where('phone', $phone)->field('uid,belong_store_id')->find();
$memberId = (int)($member['uid'] ?? 0);
$storeId = (int)($member['belong_store_id'] ?? 0);
$organizationId = (string)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
if ($memberId <= 0 || $storeId <= 0 || $organizationId === '' || $organizationId === '0') {
    throw new RuntimeException('MEMBER_GIFT_TEST_SCOPE_MISSING');
}
$operator = new CashierV3OperatorScope($storeId, 1, $organizationId, '0');
$scope = new CashierV3DataScopeContext(
    1, 1, $storeId, '0', $organizationId, [$storeId],
    CashierV3DataScopeContext::MODE_STORES, [], false, '', 'member-gift-presale-integration', [],
    ['id' => 1, 'staff_name' => 'integration operator']
);
$reader = new CashierV3MemberDetailQueryServices();
$active = $reader->read($memberId, $operator, $scope, [
    'tab' => 'gift', 'status' => 'active', 'dateFrom' => date('Y-m-d'), 'dateTo' => date('Y-m-d'),
]);
$records = (array)$active['giftRecords'];
$projectCount = count(array_filter($records, static fn(array $row): bool => ($row['sourceType'] ?? '') === 'migrated_project_gift'));
$giftCount = count(array_filter($records, static fn(array $row): bool => ($row['sourceType'] ?? '') === 'claimable_gift'));
$presaleCount = count(array_filter($records, static fn(array $row): bool => ($row['sourceType'] ?? '') === 'claimable_presale'));
$keys = array_column($records, 'id');
$today = date('Y-m-d');
$historicalVisible = count(array_filter($records, static fn(array $row): bool => (string)($row['businessDate'] ?? '') < $today)) > 0;

$allToday = $reader->read($memberId, $operator, $scope, [
    'tab' => 'gift', 'status' => '', 'dateFrom' => $today, 'dateTo' => $today,
]);
$allTodayHasHistorical = count(array_filter((array)$allToday['giftRecords'], static fn(array $row): bool => (string)($row['businessDate'] ?? '') < $today)) > 0;
$checks = [
    'historical active rights ignore today date' => $historicalVisible,
    'imported project gifts appear' => $projectCount > 0,
    'imported product gifts appear' => $giftCount > 0,
    'imported presales appear' => $presaleCount > 0,
    'no duplicate source identities' => count($keys) === count(array_unique($keys)),
    'all history date filter applies' => !$allTodayHasHistorical,
];
// 一笔正式商品赠送同时存在赠送事实与领用投影；两条承载只应显示一行。
$overlap = (array)Db::name('cashier_v3_gift_fact')->alias('gf')
    ->join('cashier_v3_presale_claimable_line cl', 'cl.tenant_id=gf.tenant_id AND cl.gift_id=gf.source_id AND cl.gift_item_id=gf.source_detail_id')
    ->where('gf.source_type', 'direct_gift')->where('gf.status', 'effective')
    ->whereIn('cl.claim_status', ['OPEN', 'AVAILABLE'])
    ->field('gf.member_id,gf.store_id,cl.claimable_line_id')->find();
if ($overlap) {
    $overlapMemberId = (int)$overlap['member_id'];
    $overlapStoreId = (int)$overlap['store_id'];
    $overlapOrganizationId = (string)Db::name('organization_store')->where('store_id', $overlapStoreId)->value('org_id');
    if ($overlapOrganizationId !== '' && $overlapOrganizationId !== '0') {
        $overlapOperator = new CashierV3OperatorScope($overlapStoreId, 1, $overlapOrganizationId, '0');
        $overlapScope = new CashierV3DataScopeContext(
            1, 1, $overlapStoreId, '0', $overlapOrganizationId, [$overlapStoreId],
            CashierV3DataScopeContext::MODE_STORES, [], false, '', 'gift-overlap-integration', [],
            ['id' => 1, 'staff_name' => 'integration operator']
        );
        $overlapDetail = $reader->read($overlapMemberId, $overlapOperator, $overlapScope, ['tab' => 'gift', 'status' => 'active']);
        $overlapIds = array_column((array)$overlapDetail['giftRecords'], 'id');
        $checks['direct gift fact and claim projection are not duplicated'] = !in_array('claimable:' . (string)$overlap['claimable_line_id'], $overlapIds, true);
    }
}
$failed = 0;
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$passed) $failed++;
}
echo 'MEMBER_GIFT_PRESALE_MYSQL project=' . $projectCount . ' gift=' . $giftCount . ' presale=' . $presaleCount . ' failed=' . $failed . PHP_EOL;
exit($failed === 0 ? 0 : 1);
