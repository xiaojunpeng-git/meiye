<?php
declare(strict_types=1);

$backend = getenv('CASHIER_V3_BACKEND') ?: '/var/www/html';
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
    'database.database' => getenv('DB_DATABASE') ?: 'ruihao_test_recovered_20260801', 'DATABASE_DATABASE' => getenv('DB_DATABASE') ?: 'ruihao_test_recovered_20260801',
    'database.username' => getenv('DB_USERNAME') ?: 'root', 'DATABASE_USERNAME' => getenv('DB_USERNAME') ?: 'root',
    'database.password' => getenv('DB_PASSWORD') ?: 'localdev123', 'DATABASE_PASSWORD' => getenv('DB_PASSWORD') ?: 'localdev123',
] as $key => $value) $app->env->set($key, $value);
$envName = new ReflectionProperty($app, 'envName');
$envName->setAccessible(true);
$envName->setValue($app, 'member_detail_authority_test_skip_reload_dotenv');
$app->initialize();
Config::set(['default' => 'file'], 'cache');
Db::connect('mysql', true)->query('SELECT 1');

$phone = getenv('MEMBER_DETAIL_TEST_PHONE') ?: '15959380751';
$member = (array)Db::name('user')->where('phone', $phone)
    ->field('uid,now_money,ben_money,give_money,integral,belong_store_id')->find();
if (!$member) throw new RuntimeException('MEMBER_DETAIL_TEST_MEMBER_MISSING');
$memberId = (int)$member['uid'];
$storeId = (int)$member['belong_store_id'];
$organizationId = (string)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
if ($storeId <= 0 || $organizationId === '' || $organizationId === '0') {
    throw new RuntimeException('MEMBER_DETAIL_TEST_SCOPE_MISSING');
}

$operator = new CashierV3OperatorScope($storeId, 1, $organizationId, '0');
$scope = new CashierV3DataScopeContext(
    1, 1, $storeId, '0', $organizationId, [$storeId],
    CashierV3DataScopeContext::MODE_STORES, [], false, '', 'member-detail-integration', [],
    ['id' => 1, 'staff_name' => 'integration operator']
);
$detail = (new CashierV3MemberDetailQueryServices())->read($memberId, $operator, $scope);
$summary = (array)$detail['summary'];
$sales = (array)Db::name('cashier_v3_sale_fact')->where('tenant_id', '0')->where('member_id', $memberId)
    ->where('store_id', $storeId)->where('status', 'effective')
    ->field('COALESCE(SUM(sale_amount_cents),0) AS total_cents,MAX(business_date) AS latest_date')->find();
$visits = (array)Db::name('cashier_v3_entitlement_service_fact')->where('tenant_id', '0')
    ->where('store_id', $storeId)->where('member_id', $memberId)->where('service_status', 'completed')
    ->field('COUNT(DISTINCT business_date) AS visit_count,MAX(business_date) AS latest_date')->find();

$moneyFromCents = static function (int $cents): string {
    $sign = $cents < 0 ? '-' : '';
    $absolute = abs($cents);
    return $sign . intdiv($absolute, 100) . '.' . str_pad((string)($absolute % 100), 2, '0', STR_PAD_LEFT);
};
$expectedSales = $moneyFromCents((int)($sales['total_cents'] ?? 0));
$checks = [
    'member identity resolved by canonical phone' => (int)$detail['member']['memberId'] === $memberId,
    'principal balance matches authority' => (string)$summary['principalBalance'] === number_format((float)$member['ben_money'], 2, '.', ''),
    'gift balance matches authority' => (string)$summary['giftBalance'] === number_format((float)$member['give_money'], 2, '.', ''),
    'account balance matches authority' => (string)$summary['accountBalance'] === number_format((float)$member['now_money'], 2, '.', ''),
    'points match member authority' => (int)$detail['member']['currentPoints'] === (int)$member['integral'],
    'total consumption matches effective sale facts' => (string)$summary['totalConsumptionAmount'] === $expectedSales,
    'latest purchase matches effective sale facts' => (string)$summary['latestPurchaseDate'] === (string)($sales['latest_date'] ?? ''),
    'visit count is distinct completed-service business days' => (int)$summary['visitCount'] === (int)($visits['visit_count'] ?? 0),
    'latest visit matches completed-service business date' => (string)$summary['latestVisitDate'] === (string)($visits['latest_date'] ?? ''),
    'balance change numbers are readable YE documents tied to business date' => array_reduce(
        (array)($detail['balanceChanges'] ?? []),
        static function (bool $valid, array $row): bool {
            $businessDate = preg_replace('/[^0-9]/', '', (string)($row['businessDate'] ?? ''));
            return $valid && preg_match('/^YE' . substr($businessDate, 2) . '[0-9]{6}$/D', (string)($row['changeNo'] ?? '')) === 1;
        },
        true
    ),
    'card operation projection reads member-scoped immutable audit rows' => (int)Db::name('cashier_v3_card_operation')
        ->where('tenant_id', '0')->where('store_id', $storeId)
        ->where(function ($query) use ($memberId): void {
            $query->where('member_id_before', $memberId)->whereOr('member_id_after', $memberId);
        })->count() === count((array)($detail['cardOperations'] ?? [])),
];

$failed = 0;
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$passed) $failed++;
}
echo 'MEMBER_DETAIL_AUTHORITY_MYSQL passed=' . (count($checks) - $failed) . ' failed=' . $failed . PHP_EOL;
exit($failed === 0 ? 0 : 1);
