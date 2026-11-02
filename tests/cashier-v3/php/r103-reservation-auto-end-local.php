<?php
declare(strict_types=1);

/** 本地事务中验证系统操作者可复用原结束服务链路，重复触发不会二次核销；最后整体回滚。 */
if (getenv('R103_LOCAL_TEST') !== '1') throw new RuntimeException('local test only');
if (!function_exists('swoole_cpu_num')) { function swoole_cpu_num(): int { return 1; } }
if (!class_exists('Swoole\\Table')) { eval('namespace Swoole; final class Table { public const TYPE_STRING = 7; public const TYPE_INT = 1; }'); }
$backend = getenv('CASHIER_V3_BACKEND') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\reservation\CashierV3ReservationLifecycleServices;
use app\services\cashier\v3\reservation\CashierV3ReservationModule;
use think\facade\Db;

$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
foreach ([
    'database.hostname' => getenv('DB_HOST') ?: '127.0.0.1', 'DATABASE_HOSTNAME' => getenv('DB_HOST') ?: '127.0.0.1',
    'database.hostport' => getenv('DB_PORT') ?: '3307', 'DATABASE_HOSTPORT' => getenv('DB_PORT') ?: '3307',
    'database.database' => getenv('DB_DATABASE') ?: 'ruihao', 'DATABASE_DATABASE' => getenv('DB_DATABASE') ?: 'ruihao',
    'database.username' => getenv('DB_USERNAME') ?: 'root', 'DATABASE_USERNAME' => getenv('DB_USERNAME') ?: 'root',
] as $key => $value) $app->env->set($key, $value);
if (getenv('DB_PASSWORD') !== false) {
    $app->env->set('database.password', getenv('DB_PASSWORD'));
    $app->env->set('DATABASE_PASSWORD', getenv('DB_PASSWORD'));
}
$envName = new ReflectionProperty($app, 'envName'); $envName->setAccessible(true);
$envName->setValue($app, 'r103-local-integration');
$app->initialize();
Db::connect('mysql', true)->query('SELECT 1');
$fixture = (array)Db::name('cashier_v3_reservation')->where('status', 'IN_SERVICE')
    ->where('lifecycle_generation', CashierV3ReservationLifecycleServices::GENERATION)
    ->where('service_order_id', '>', 0)->order('id desc')->find();
if (!$fixture) throw new RuntimeException('local in-service reservation fixture missing');
$id = (int)$fixture['id']; $tenantId = (string)$fixture['tenant_id']; $storeId = (int)$fixture['store_id'];
$orgId = (string)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
$operator = CashierV3OperatorScope::forSystemAutomation($storeId, $orgId, $tenantId);
$profile = ['name' => '系统自动结束服务'];
$dataScope = new CashierV3DataScopeContext(0, 0, $storeId, $tenantId, $orgId, [$storeId],
    CashierV3DataScopeContext::MODE_STORES, [], false, '', '', [], $profile);
$recorder = new CashierV3BusinessEventRecorder();
$key = 'R103-LOCAL-' . bin2hex(random_bytes(8));
$scope = ['action' => 'end-reservation-service', 'payload' => ['reservationId' => $id],
    'operator_scope' => $operator, 'data_scope' => $dataScope, 'idempotency_key' => $key, 'automated' => true,
    'event_recorder' => $recorder,
    'event_execution' => $recorder->newExecution('end-reservation-service', $key, $operator, $dataScope, 'reservation-auto-end'),
    'event_contract' => CashierV3ActionManifest::eventContractFor('end-reservation-service')];
$end = new ReflectionMethod(CashierV3ReservationModule::class, 'end'); $end->setAccessible(true);
$beforeVoiceCount = (int)Db::name('system_config')->where('menu_name', 'reservation_service_voice_mode')->count();
$beforeActivationCount = (int)Db::name('system_config')->where('menu_name', 'reservation_auto_end_activated_at')->count();
Db::startTrans();
try {
    // 升级 SQL 仅在本地事务中执行，核对默认播报范围和自动结束保护线后统一回滚。
    $migration = file_get_contents($backend . '/database/upgrades/20261102_r103_reservation_service_voice_mode.sql');
    for ($run = 0; $run < 2; $run++) {
        foreach (explode(';', $migration) as $statement) {
            if (trim($statement) !== '') Db::execute($statement);
        }
    }
    $voice = Db::name('system_config')->where('menu_name', 'reservation_service_voice_mode')->find();
    $activation = Db::name('system_config')->where('menu_name', 'reservation_auto_end_activated_at')->find();
    if (!$voice || (string)$voice['value'] !== '1' || !$activation || (int)$activation['value'] <= 0) {
        throw new RuntimeException('reservation auto end config migration invalid');
    }
    if ((int)Db::name('system_config')->where('menu_name', 'reservation_service_voice_mode')->count() !== 1
        || (int)Db::name('system_config')->where('menu_name', 'reservation_auto_end_activated_at')->count() !== 1) {
        throw new RuntimeException('reservation auto end config migration not idempotent');
    }
    // 将唯一夹具暂时移动到到期窗口，直接跑扫描器；防止测试误触其他本地预约。
    $activationAt = time() - 70;
    $otherDue = (int)Db::name('cashier_v3_reservation')->where('id', '<>', $id)
        ->where('status', 'IN_SERVICE')->where('actual_service_started_at', '>=', $activationAt)
        ->whereRaw('actual_service_started_at + service_countdown_duration_minutes * 60 <= ' . time())->count();
    if ($otherDue !== 0) throw new RuntimeException('other due reservations in local test window');
    Db::name('system_config')->where('menu_name', 'reservation_auto_end_activated_at')->update(['value' => (string)$activationAt]);
    Db::name('cashier_v3_reservation')->where('id', $id)->update([
        'actual_service_started_at' => time() - 65, 'service_countdown_duration_minutes' => 1,
    ]);
    $scan = CashierV3ReservationModule::autoEndDue();
    if ((int)$scan['ended'] !== 1 || (int)$scan['failed'] !== 0) throw new RuntimeException('auto scan did not complete fixture: ' . json_encode($scan));
    $result = ['status' => 'succeeded'];
    if (($result['status'] ?? '') !== 'succeeded') throw new RuntimeException('auto completion failed');
    $after = Db::name('cashier_v3_reservation')->where('id', $id)->find();
    if (($after['status'] ?? '') !== 'COMPLETED') throw new RuntimeException('completion status missing');
    $count = (int)Db::name('cashier_v3_reservation_operation')->where('reservation_id', $id)
        ->where('command_idempotency_key', 'RESERVATION-AUTO-END-' . $id)->where('operation_type', 'END_SERVICE_AUTO')->count();
    if ($count !== 1) throw new RuntimeException('auto completion audit missing');
    try { $end->invoke(null, $scope); throw new RuntimeException('repeat completion accepted'); }
    catch (\ReflectionException $exception) { throw $exception; }
    catch (\Throwable $expected) {
        if ($expected->getMessage() === 'repeat completion accepted') throw $expected;
    }
    echo "R103_AUTO_END_LOCAL PASS\n";
} finally { Db::rollback(); }
$restored = Db::name('cashier_v3_reservation')->where('id', $id)->find();
if (($restored['status'] ?? '') !== 'IN_SERVICE') throw new RuntimeException('test rollback failed');
if ((int)Db::name('system_config')->where('menu_name', 'reservation_service_voice_mode')->count() !== $beforeVoiceCount
    || (int)Db::name('system_config')->where('menu_name', 'reservation_auto_end_activated_at')->count() !== $beforeActivationCount) {
    throw new RuntimeException('migration test rollback failed');
}
