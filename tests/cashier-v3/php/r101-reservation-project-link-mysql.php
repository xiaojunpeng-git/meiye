<?php
declare(strict_types=1);

/** 本地事务内核对服务中项目行、服务单行与计时字段；测试末尾一律回滚。 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!function_exists('swoole_cpu_num')) { function swoole_cpu_num(): int { return 1; } }
if (!class_exists('Swoole\\Table')) { eval('namespace Swoole; final class Table { public const TYPE_STRING = 7; public const TYPE_INT = 1; }'); }
$backend = getenv('CASHIER_V3_BACKEND') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';

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
$envName->setValue($app, 'r101-local-integration');
$app->initialize();
Db::connect('mysql', true)->query('SELECT 1');

$fixture = (array)Db::name('cashier_v3_reservation')->alias('r')
    ->join('cashier_v3_reservation_line l', 'l.tenant_id=r.tenant_id AND l.reservation_id=r.id')
    ->join('cashier_v3_service_order s', 's.tenant_id=r.tenant_id AND s.id=r.service_order_id')
    ->where('r.status', 'IN_SERVICE')->where('s.status', 'OPEN')->where('l.project_source', 'ENTITLEMENT')
    ->where('r.lifecycle_generation', CashierV3ReservationLifecycleServices::GENERATION)
    ->field('r.*')->order('r.id desc')->find();
if (!$fixture) throw new RuntimeException('R101 serving fixture missing');
$tenantId = (string)$fixture['tenant_id'];
$reservationId = (int)$fixture['id'];
$serviceId = (int)$fixture['service_order_id'];
$oldLines = Db::name('cashier_v3_reservation_line')->where('tenant_id', $tenantId)
    ->where('reservation_id', $reservationId)->order('id asc')->select()->toArray();
$oldServiceLineIds = Db::name('cashier_v3_service_order_line')->where('tenant_id', $tenantId)
    ->where('service_order_id', $serviceId)->where('status', 'ACTIVE')->column('id');
if (!$oldLines || !$oldServiceLineIds) throw new RuntimeException('R101 active line fixture missing');
$beforeTimer = [(int)$fixture['service_countdown_duration_minutes'], (int)$fixture['actual_service_started_at']];
$plan = [[
    'projectId' => (int)$oldLines[0]['project_id'], 'skuId' => (int)$oldLines[0]['sku_id'],
    'name' => (string)$oldLines[0]['project_name_snapshot'],
    'source' => $oldLines[0]['project_source'] === 'ENTITLEMENT' ? 'card' : 'unpaid',
    'entitlementSourceDetailId' => (int)$oldLines[0]['entitlement_source_detail_id'],
    'quantity' => (int)$oldLines[0]['quantity'], 'duration' => (int)$oldLines[0]['service_duration_minutes'],
]];
$staffIds = json_decode((string)$oldLines[0]['artisan_staff_ids_json'], true) ?: [];
$replace = new ReflectionMethod(CashierV3ReservationModule::class, 'replaceLines');
$replace->setAccessible(true);
Db::startTrans();
try {
    $now = time();
    $newLines = $replace->invoke(null, $reservationId, $tenantId, $plan, $staffIds, $now);
    (new CashierV3ReservationLifecycleServices())->replaceStartedServiceLinesInTx($fixture, $newLines, $now);
    $newLine = Db::name('cashier_v3_reservation_line')->where('tenant_id', $tenantId)
        ->where('reservation_id', $reservationId)->find();
    if ((int)$newLine['id'] === (int)$oldLines[0]['id'] || (int)$newLine['service_order_line_id'] <= 0) {
        throw new RuntimeException('final reservation line not replaced or linked');
    }
    $newServiceLine = Db::name('cashier_v3_service_order_line')->where('tenant_id', $tenantId)
        ->where('id', (int)$newLine['service_order_line_id'])->find();
    if ((string)$newServiceLine['status'] !== 'ACTIVE'
        || (int)$newServiceLine['source_id'] !== (int)$newLine['id']
        || (int)$newServiceLine['entitlement_source_detail_id'] !== (int)$newLine['entitlement_source_detail_id']) {
        throw new RuntimeException('service line does not match final selected project');
    }
    $released = Db::name('cashier_v3_service_order_line')->where('tenant_id', $tenantId)
        ->whereIn('id', $oldServiceLineIds)->where('status', 'RELEASED')->count();
    if ($released !== count($oldServiceLineIds)) throw new RuntimeException('old service lines not released');
    // 完成链路必须读取新预约行，并以新行 ID 生成已购权益核销事实；全部写入随后回滚。
    $eventNo = 'r101-test-' . bin2hex(random_bytes(8));
    $facts = (new CashierV3ReservationLifecycleServices())->consumeInTx($fixture, $now, [
        'eventNo' => $eventNo, 'commandIdempotencyKey' => $eventNo,
        'operatorId' => 1, 'operatorName' => 'local-test',
    ]);
    $writeoff = Db::name('cashier_v3_entitlement_writeoff_fact')->where('tenant_id', $tenantId)
        ->where('business_event_no', $eventNo)->find();
    if ((int)($facts['writeoff'] ?? 0) !== 1
        || (string)($writeoff['source_line_id'] ?? '') !== 'reservation:' . $reservationId . ':' . (int)$newLine['id']) {
        throw new RuntimeException('completion did not write off the final selected reservation line');
    }
    $after = Db::name('cashier_v3_reservation')->where('tenant_id', $tenantId)->where('id', $reservationId)->find();
    if ([(int)$after['service_countdown_duration_minutes'], (int)$after['actual_service_started_at']] !== $beforeTimer) {
        throw new RuntimeException('service timer changed with project');
    }
} finally {
    Db::rollback();
}
$restored = Db::name('cashier_v3_reservation_line')->where('tenant_id', $tenantId)
    ->where('reservation_id', $reservationId)->order('id asc')->column('id');
if (array_map('intval', $restored) !== array_map(static function ($line) { return (int)$line['id']; }, $oldLines)) {
    throw new RuntimeException('test rollback failed');
}
echo "R101_PROJECT_LINK_MYSQL PASS\n";
