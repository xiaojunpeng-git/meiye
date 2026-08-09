<?php
declare(strict_types=1);

$backend = getenv('CASHIER_V3_BACKEND') ?: '/var/www/html';
require $backend . '/vendor/autoload.php';

use app\services\query\provider\MemberUnifiedQueryProvider;
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
$envName->setValue($app, 'member_visit_fact_test_skip_reload_dotenv');
$app->initialize();
Config::set(['default' => 'file'], 'cache');
Db::connect('mysql', true)->query('SELECT 1');

final class MemberVisitFactProbe extends MemberUnifiedQueryProvider
{
    public function __construct() {}
    public function readVisits(array $uids, array $stores, string $tenantId): array
    {
        return $this->visitSummaries($uids, $stores, false, $tenantId);
    }
}

$phone = getenv('MEMBER_DETAIL_TEST_PHONE') ?: '15959380751';
$member = (array)Db::name('user')->where('phone', $phone)->field('uid,belong_store_id')->find();
if (!$member) throw new RuntimeException('MEMBER_VISIT_TEST_MEMBER_MISSING');
$memberId = (int)$member['uid'];
$storeId = (int)$member['belong_store_id'];
$actual = (new MemberVisitFactProbe())->readVisits([$memberId], [$storeId], '0')[$memberId] ?? [];
$expected = (array)Db::name('cashier_v3_entitlement_service_fact')->where('tenant_id', '0')
    ->where('store_id', $storeId)->where('member_id', $memberId)->where('service_status', 'completed')
    ->field('COUNT(DISTINCT business_date) AS visit_count,MAX(business_date) AS latest_date')->find();

$checks = [
    'member list visit count matches distinct V3 service business days' =>
        (int)($actual['visit_count'] ?? -1) === (int)($expected['visit_count'] ?? 0),
    'member list latest visit matches V3 service business date' =>
        (string)($actual['latest_date'] ?? '') === (string)($expected['latest_date'] ?? ''),
    'PoPo regression has completed service visits' =>
        (int)($actual['visit_count'] ?? 0) > 0 && (string)($actual['latest_date'] ?? '') !== '',
];
$failed = 0;
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$passed) $failed++;
}
echo 'MEMBER_VISIT_FACT_MYSQL passed=' . (count($checks) - $failed) . ' failed=' . $failed . PHP_EOL;
exit($failed === 0 ? 0 : 1);
