<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);
if (!function_exists('swoole_cpu_num')) { function swoole_cpu_num(): int { return 1; } }
if (!class_exists('Swoole\\Table')) {
    eval('namespace Swoole; final class Table { public const TYPE_STRING = 7; public const TYPE_INT = 1; }');
}

$backend = getenv('CASHIER_V3_BACKEND') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\reservation\CashierV3CheckoutReservationClosureServices;
use app\services\cashier\v3\reservation\CashierV3ReservationLifecycleServices;
use think\facade\Config;
use think\facade\Db;

$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
$app->initialize();
Config::set(['default' => 'file'], 'cache');
Db::connect('mysql', true)->query('SELECT 1');

$order = (array)Db::name('cashier_v3_sales_order')
    ->where('order_status', 'settled')->where('order_direction', 'forward')->where('member_id', '>', 0)
    ->order('id desc')->find();
$reservations = Db::name('cashier_v3_reservation')
    ->where('tenant_id', (string)($order['tenant_id'] ?? ''))
    ->where('lifecycle_generation', CashierV3ReservationLifecycleServices::GENERATION)
    ->order('id desc')->limit(2)->select()->toArray();
if (!$order || count($reservations) < 2) {
    throw new RuntimeException('CHECKOUT_RESERVATION_FIXTURE_MISSING');
}
$reservation = (array)$reservations[0];
$secondReservation = (array)$reservations[1];

$operatorId = max(1, (int)($order['operator_id'] ?? 0));
$operatorName = trim((string)($order['operator_name_snapshot'] ?? '')) ?: '集成测试操作员';
$operator = new CashierV3OperatorScope((int)$order['store_id'], $operatorId, (string)($order['organization_id'] ?? ''), (string)$order['tenant_id']);
$dataScope = new CashierV3DataScopeContext(
    $operatorId, 1, (int)$order['store_id'], (string)$order['tenant_id'], (string)($order['organization_id'] ?? ''),
    null, CashierV3DataScopeContext::MODE_ALL, [], true, 'integration', '1', ['cashier.v3.cashier'], [
        'id' => $operatorId,
        'employee_id' => 1,
        'staff_name' => $operatorName,
        'account' => $operatorName,
    ]
);
$recorder = new CashierV3BusinessEventRecorder();
$idempotencyKey = 'CHECKOUT-RESERVATION-' . bin2hex(random_bytes(16));
$scope = [
    'action' => 'complete-checkout-reservations',
    'payload' => ['salesOrderId' => (string)$order['order_id']],
    'idempotency_key' => $idempotencyKey,
    'operator_scope' => $operator,
    'data_scope' => $dataScope,
    'state_context_id' => 'checkout-reservation-integration',
    'event_recorder' => $recorder,
    'event_execution' => $recorder->newExecution('complete-checkout-reservations', $idempotencyKey, $operator, $dataScope, 'checkout-reservation-integration'),
    'event_contract' => CashierV3ActionManifest::eventContractFor('complete-checkout-reservations'),
];

Db::startTrans();
try {
    $originalVersion = (int)$reservation['version'];
    $secondOriginalVersion = (int)$secondReservation['version'];
    Db::name('cashier_v3_reservation')->where('id', (int)$reservation['id'])->update([
        'tenant_id' => (string)$order['tenant_id'],
        'store_id' => (int)$order['store_id'],
        'member_id' => (int)$order['member_id'],
        'business_date' => (string)$order['business_date'],
        'status' => 'UNSTARTED',
        'actual_service_ended_at' => 0,
        'version' => $originalVersion,
    ]);
    Db::name('cashier_v3_reservation')->where('id', (int)$secondReservation['id'])->update([
        'store_id' => (int)$order['store_id'],
        'member_id' => (int)$order['member_id'],
        'business_date' => (string)$order['business_date'],
        'status' => 'PENDING_CONFIRMATION',
        'actual_service_ended_at' => 0,
        'version' => $secondOriginalVersion,
    ]);

    $service = new CashierV3CheckoutReservationClosureServices();
    $preview = $service->preview($scope);
    if (empty($preview['required']) || (int)$preview['reservationCount'] < 2) {
        throw new RuntimeException('PREVIEW_DID_NOT_FIND_MATCHING_UNFINISHED_RESERVATION');
    }

    $factCountsBefore = [
        'service' => Db::name('cashier_v3_entitlement_service_fact')->count(),
        'writeoff' => Db::name('cashier_v3_entitlement_writeoff_fact')->count(),
        'performance' => Db::name('cashier_v3_performance_fact')->count(),
    ];
    $occupationBefore = Db::name('cashier_v3_reservation_entitlement_occupation')
        ->where('reservation_id', (int)$reservation['id'])->column('status', 'id');

    $completed = $service->complete($scope);
    $row = (array)Db::name('cashier_v3_reservation')->where('id', (int)$reservation['id'])->find();
    $secondRow = (array)Db::name('cashier_v3_reservation')->where('id', (int)$secondReservation['id'])->find();
    if ((string)$row['status'] !== 'COMPLETED'
        || (int)$row['version'] !== $originalVersion + 1
        || (int)$row['actual_service_ended_at'] <= 0
        || (string)$secondRow['status'] !== 'COMPLETED'
        || (int)$secondRow['version'] !== $secondOriginalVersion + 1
        || (int)$secondRow['actual_service_ended_at'] <= 0
        || (int)$completed['completedCount'] < 2) {
        throw new RuntimeException('MATCHING_RESERVATION_WAS_NOT_COMPLETED');
    }

    $factCountsAfter = [
        'service' => Db::name('cashier_v3_entitlement_service_fact')->count(),
        'writeoff' => Db::name('cashier_v3_entitlement_writeoff_fact')->count(),
        'performance' => Db::name('cashier_v3_performance_fact')->count(),
    ];
    $occupationAfter = Db::name('cashier_v3_reservation_entitlement_occupation')
        ->where('reservation_id', (int)$reservation['id'])->column('status', 'id');
    if ($factCountsBefore !== $factCountsAfter || $occupationBefore !== $occupationAfter) {
        throw new RuntimeException('CHECKOUT_PROMPT_APPLIED_FORBIDDEN_SERVICE_SIDE_EFFECTS');
    }

    $replayedDomain = $service->complete($scope);
    if ((int)$replayedDomain['completedCount'] !== 0) {
        throw new RuntimeException('SECOND_DOMAIN_EXECUTION_WAS_NOT_IDEMPOTENT');
    }

    // 作废链路不接入预约：即使预约又是未结束，非 settled 订单也不得修改它。
    Db::name('cashier_v3_reservation')->where('id', (int)$reservation['id'])->update([
        'status' => 'IN_SERVICE',
        'actual_service_ended_at' => 0,
        'version' => $originalVersion + 2,
    ]);
    Db::name('cashier_v3_sales_order')->where('id', (int)$order['id'])->update(['order_status' => 'voided']);
    $voidedOrderResult = $service->complete($scope);
    $afterVoided = (array)Db::name('cashier_v3_reservation')->where('id', (int)$reservation['id'])->find();
    if ((int)$voidedOrderResult['completedCount'] !== 0 || (string)$afterVoided['status'] !== 'IN_SERVICE') {
        throw new RuntimeException('VOIDED_ORDER_CHANGED_RESERVATION');
    }

    echo "PASS checkout reservation completion mysql integration\n";
    Db::rollback();
} catch (Throwable $exception) {
    Db::rollback();
    throw $exception;
}
