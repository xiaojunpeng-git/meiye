<?php
declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);
if (!function_exists('swoole_cpu_num')) {
    function swoole_cpu_num(): int { return 1; }
}
if (!class_exists('Swoole\\Table')) {
    eval('namespace Swoole; final class Table { public const TYPE_STRING = 7; public const TYPE_INT = 1; }');
}

$backend = getenv('CASHIER_V3_BACKEND') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\reservation\CashierV3ReservationDetailQueryServices;
use think\facade\Config;
use think\facade\Db;

$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
foreach ([
    'cache.driver' => 'file', 'CACHE_DRIVER' => 'file',
    'database.hostname' => getenv('DB_HOST') ?: '127.0.0.1', 'DATABASE_HOSTNAME' => getenv('DB_HOST') ?: '127.0.0.1',
    'database.hostport' => getenv('DB_PORT') ?: '3307', 'DATABASE_HOSTPORT' => getenv('DB_PORT') ?: '3307',
    'database.database' => getenv('DB_DATABASE') ?: 'ruihao_test_recovered_20260801', 'DATABASE_DATABASE' => getenv('DB_DATABASE') ?: 'ruihao_test_recovered_20260801',
    'database.username' => getenv('DB_USERNAME') ?: 'root', 'DATABASE_USERNAME' => getenv('DB_USERNAME') ?: 'root',
    'database.password' => getenv('DB_PASSWORD') ?: 'localdev123', 'DATABASE_PASSWORD' => getenv('DB_PASSWORD') ?: 'localdev123',
] as $key => $value) $app->env->set($key, $value);
$envName = new ReflectionProperty($app, 'envName');
$envName->setAccessible(true);
$envName->setValue($app, 'reservation_detail_integration_skip_reload_dotenv');
$app->initialize();
Config::set(['default' => 'file'], 'cache');
Db::connect('mysql', true)->query('SELECT 1');

$fixture = (array)Db::name('cashier_v3_reservation')->alias('r')
    ->join('cashier_v3_reservation_line l', 'l.tenant_id=r.tenant_id AND l.reservation_id=r.id')
    ->field('r.id,r.reservation_no,r.tenant_id,r.store_id,r.version,COUNT(l.id) AS line_count')
    ->group('r.id,r.reservation_no,r.tenant_id,r.store_id,r.version')
    ->having('COUNT(l.id) > 0')
    ->order('r.id desc')
    ->find();
if (!$fixture) throw new RuntimeException('RESERVATION_DETAIL_FIXTURE_MISSING');

$storeId = (int)$fixture['store_id'];
$tenantId = (string)$fixture['tenant_id'];
$organizationId = (string)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
$operator = new CashierV3OperatorScope($storeId, 1, $organizationId, $tenantId);
$dataScope = new CashierV3DataScopeContext(
    1, 1, $storeId, $tenantId, $organizationId, [$storeId],
    CashierV3DataScopeContext::MODE_STORES, [], false, '', 'reservation-detail-integration', [],
    ['staff_name' => 'integration operator']
);
$reader = new CashierV3ReservationDetailQueryServices();
$detail = $reader->read(['reservationId' => (int)$fixture['id']], $operator, $dataScope);

$otherStoreId = (int)Db::name('system_store')->where('id', '<>', $storeId)->where('is_del', 0)->order('id asc')->value('id');
$crossStoreDetail = null;
if ($otherStoreId > 0) {
    $otherOrganizationId = (string)Db::name('organization_store')->where('store_id', $otherStoreId)->value('org_id');
    $crossStoreDetail = $reader->read(
        ['reservationId' => (int)$fixture['id']],
        new CashierV3OperatorScope($otherStoreId, 1, $otherOrganizationId, $tenantId),
        new CashierV3DataScopeContext(
            1, 1, $otherStoreId, $tenantId, $otherOrganizationId, [$otherStoreId],
            CashierV3DataScopeContext::MODE_STORES, [], false, '', 'reservation-detail-cross-store', [],
            ['staff_name' => 'integration operator']
        )
    );
}

$checks = [
    'detail reads authoritative reservation identity' => is_array($detail)
        && (int)$detail['reservationId'] === (int)$fixture['id']
        && (string)$detail['reservationNo'] === (string)$fixture['reservation_no'],
    'detail exposes authoritative reservation version' => (int)($detail['reservationVersion'] ?? 0) === (int)$fixture['version'],
    'detail returns every authoritative project line' => count((array)($detail['projects'] ?? [])) === (int)$fixture['line_count'],
    'detail satisfies frontend completeness contract' => !empty($detail['detailReady'])
        && is_array($detail['member'] ?? null)
        && is_array($detail['plannedCraftsmen'] ?? null)
        && is_array($detail['actualCraftsmen'] ?? null)
        && is_array($detail['timeline'] ?? null)
        && is_array($detail['actions'] ?? null)
        && is_array($detail['relatedRecords'] ?? null),
    'same reservation is hidden outside current store' => $otherStoreId <= 0 || $crossStoreDetail === null,
];

$failed = 0;
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$passed) $failed++;
}
echo 'RESERVATION_DETAIL_MYSQL passed=' . (count($checks) - $failed) . ' failed=' . $failed . PHP_EOL;
exit($failed === 0 ? 0 : 1);
