<?php
declare(strict_types=1);

// 在本地数据库事务内验证时长命令与双端共用详情；任何检查结果均回滚。
error_reporting(E_ALL & ~E_DEPRECATED);
if (!function_exists('swoole_cpu_num')) { function swoole_cpu_num(): int { return 1; } }
if (!class_exists('Swoole\\Table')) { eval('namespace Swoole; final class Table { public const TYPE_STRING = 7; public const TYPE_INT = 1; }'); }
$backend = getenv('CASHIER_V3_BACKEND') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\reservation\CashierV3ReservationDetailQueryServices;
use app\services\cashier\v3\reservation\CashierV3ReservationModule;
use app\services\cashier\v3\reservation\CashierV3ReservationLifecycleServices;
use think\facade\Db;

$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
foreach ([
    'database.hostname' => getenv('DB_HOST') ?: '127.0.0.1', 'DATABASE_HOSTNAME' => getenv('DB_HOST') ?: '127.0.0.1',
    'database.hostport' => getenv('DB_PORT') ?: '3307', 'DATABASE_HOSTPORT' => getenv('DB_PORT') ?: '3307',
    'database.database' => getenv('DB_DATABASE') ?: 'ruihao', 'DATABASE_DATABASE' => getenv('DB_DATABASE') ?: 'ruihao',
    'database.username' => getenv('DB_USERNAME') ?: 'root', 'DATABASE_USERNAME' => getenv('DB_USERNAME') ?: 'root',
] as $key => $value) $app->env->set($key, $value);
// 密码只从本地后端环境或测试环境变量读取，测试文件不保留实例凭据。
if (getenv('DB_PASSWORD') !== false) {
    $app->env->set('database.password', getenv('DB_PASSWORD'));
    $app->env->set('DATABASE_PASSWORD', getenv('DB_PASSWORD'));
}
$envName = new ReflectionProperty($app, 'envName'); $envName->setAccessible(true);
$envName->setValue($app, 'r100-local-integration');
$app->initialize();
Db::connect('mysql', true)->query('SELECT 1');
$fixture = (array)Db::name('cashier_v3_reservation')->alias('r')
    ->join('cashier_v3_reservation_line l', 'l.tenant_id=r.tenant_id AND l.reservation_id=r.id')
    ->where('r.status', 'UNSTARTED')->where('r.lifecycle_generation', CashierV3ReservationLifecycleServices::GENERATION)
    ->field('r.*')->order('r.id desc')->find();
if (!$fixture) throw new RuntimeException('R100 reservation fixture missing');
$storeId = (int)$fixture['store_id']; $tenantId = (string)$fixture['tenant_id'];
$orgId = (string)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
$operator = new CashierV3OperatorScope($storeId, 1, $orgId, $tenantId);
$dataScope = new CashierV3DataScopeContext(1, 1, $storeId, $tenantId, $orgId, [$storeId], CashierV3DataScopeContext::MODE_STORES, [], false, '', 'r100', [], ['staff_name' => 'integration']);
$reader = new CashierV3ReservationDetailQueryServices();
$command = new ReflectionMethod(CashierV3ReservationModule::class, 'changeServiceDuration');
$command->setAccessible(true);
$scope = ['operator_scope' => $operator, 'data_scope' => $dataScope,
    'idempotency_key' => 'R100-TEST-' . bin2hex(random_bytes(8)),
    'payload' => ['reservationId' => (int)$fixture['id'], 'serviceDurationMinutes' => 37]];
$before = $reader->read(['reservationId' => (int)$fixture['id']], $operator, $dataScope);
$passed = 0;
Db::startTrans();
try {
    $scope['payload']['serviceDurationMinutes'] = 0;
    try { $command->invoke(null, $scope); throw new RuntimeException('zero minutes accepted'); }
    catch (\app\services\cashier\v3\CashierV3CommandException $expected) { $passed++; }
    $scope['payload']['serviceDurationMinutes'] = 37;
    $result = $command->invoke(null, $scope);
    $after = $reader->read(['reservationId' => (int)$fixture['id']], $operator, $dataScope);
    if ((int)$after['serviceDurationMinutes'] !== 37 || (int)$after['totalDurationSeconds'] !== 2220) throw new RuntimeException('duration readback mismatch');
    $passed++;
    if ($after['appointmentStartAt'] !== $before['appointmentStartAt'] || $after['appointmentEndAt'] !== $before['appointmentEndAt']) throw new RuntimeException('schedule changed');
    $passed++;
    if ((int)$after['reservationVersion'] !== (int)$before['reservationVersion'] + 1 || (int)$result['previousDurationMinutes'] !== (int)$before['serviceDurationMinutes']) throw new RuntimeException('version or audit mismatch');
    $passed++;
    if (!in_array('修改服务时长', array_column((array)$after['timeline'], 'title'), true)) throw new RuntimeException('timeline missing');
    $passed++;
} finally { Db::rollback(); }
$restored = $reader->read(['reservationId' => (int)$fixture['id']], $operator, $dataScope);
if ((int)$restored['serviceDurationMinutes'] !== (int)$before['serviceDurationMinutes']) throw new RuntimeException('rollback failed');
$passed++;
$serving = (array)Db::name('cashier_v3_reservation')->alias('r')
    ->join('cashier_v3_reservation_line l', 'l.tenant_id=r.tenant_id AND l.reservation_id=r.id')
    ->where('r.status', 'IN_SERVICE')->where('r.lifecycle_generation', CashierV3ReservationLifecycleServices::GENERATION)
    ->where('r.actual_service_started_at', '>', 0)->field('r.*')->order('r.id desc')->find();
if ($serving) {
    $servingStore = (int)$serving['store_id']; $servingTenant = (string)$serving['tenant_id'];
    $servingOrg = (string)Db::name('organization_store')->where('store_id', $servingStore)->value('org_id');
    $servingOperator = new CashierV3OperatorScope($servingStore, 1, $servingOrg, $servingTenant);
    $servingScope = new CashierV3DataScopeContext(1, 1, $servingStore, $servingTenant, $servingOrg, [$servingStore], CashierV3DataScopeContext::MODE_STORES, [], false, '', 'r100-serving', [], ['staff_name' => 'integration']);
    Db::startTrans();
    try {
        $command->invoke(null, ['operator_scope' => $servingOperator, 'data_scope' => $servingScope,
            'idempotency_key' => 'R100-SERVING-' . bin2hex(random_bytes(8)),
            'payload' => ['reservationId' => (int)$serving['id'], 'serviceDurationMinutes' => 41]]);
        $servingDetail = $reader->read(['reservationId' => (int)$serving['id']], $servingOperator, $servingScope);
        if ((int)$servingDetail['serviceCountdownEndsAt'] !== (int)$serving['actual_service_started_at'] + 41 * 60) throw new RuntimeException('serving clock did not use actual start');
        $passed++;
    } finally { Db::rollback(); }
}
echo "R100_DURATION_MYSQL passed={$passed}\n";
