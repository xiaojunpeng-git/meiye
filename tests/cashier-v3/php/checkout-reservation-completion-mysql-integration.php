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
use app\services\cashier\v3\reservation\CashierV3ReservationDetailQueryServices;
use app\services\cashier\v3\reservation\CashierV3ReservationLifecycleServices;
use app\services\cashier\v3\reservation\CashierV3ReservationPartitionProvider;
use think\facade\Config;
use think\facade\Db;

$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
$app->initialize();
Config::set(['default' => 'file'], 'cache');
Db::connect('mysql', true)->query('SELECT 1');

$fixtureService = (array)Db::name('cashier_v3_entitlement_service_fact')
    ->where('source_document_type', 'sales_order')->where('service_status', 'completed')
    ->where('member_id', '>', 0)->order('id desc')->find();
$order = (array)Db::name('cashier_v3_sales_order')
    ->where('order_id', (string)($fixtureService['document_id'] ?? ''))
    ->where('order_status', 'settled')->where('order_direction', 'forward')->where('member_id', '>', 0)
    ->find();
$reservations = Db::name('cashier_v3_reservation')
    ->where('tenant_id', (string)($order['tenant_id'] ?? ''))
    ->where('lifecycle_generation', CashierV3ReservationLifecycleServices::GENERATION)
    ->order('id desc')->limit(2)->select()->toArray();
if (!$fixtureService || !$order || count($reservations) < 2) {
    throw new RuntimeException('CHECKOUT_RESERVATION_FIXTURE_MISSING');
}
$entitlementOnlyRequest = (array)Db::name('cashier_v3_checkout_request')->alias('request')
    ->join(
        'cashier_v3_entitlement_completion_receipt receipt',
        "receipt.tenant_id=request.tenant_id AND receipt.checkout_request_id=request.request_id AND receipt.status='completed'"
    )
    ->join(
        'cashier_v3_entitlement_service_fact service',
        "service.tenant_id=request.tenant_id AND service.checkout_request_id=request.request_id AND service.service_status='completed'"
    )
    ->leftJoin(
        'cashier_v3_sales_order sales_order',
        'sales_order.tenant_id=request.tenant_id AND sales_order.store_id=request.store_id AND sales_order.checkout_request_id=request.request_id'
    )
    ->where('request.request_status', 'succeeded')->where('request.composition', 'entitlement_only')
    ->where('request.member_id', '>', 0)->whereNull('sales_order.id')
    ->field('request.request_id,request.tenant_id,request.organization_id,request.store_id,request.member_id,request.business_date,request.operator_id,receipt.receipt_id')
    ->order('request.id desc')->find();
if (!$entitlementOnlyRequest) throw new RuntimeException('ENTITLEMENT_ONLY_CHECKOUT_FIXTURE_MISSING');
$entitlementOnlyServices = Db::name('cashier_v3_entitlement_service_fact')
    ->where('tenant_id', (string)$entitlementOnlyRequest['tenant_id'])
    ->where('checkout_request_id', (string)$entitlementOnlyRequest['request_id'])
    ->where('service_status', 'completed')->order('id asc')->select()->toArray();
if (!$entitlementOnlyServices) throw new RuntimeException('ENTITLEMENT_ONLY_SERVICE_FIXTURE_MISSING');
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
    // The local database may already contain checkout links from a previous
    // real UI acceptance run. Clear only the two transaction-scoped fixture
    // reservations so this rollback test always verifies one fresh sync batch.
    Db::name('cashier_v3_reservation_checkout_service_link')
        ->where('tenant_id', (string)$order['tenant_id'])
        ->whereIn('reservation_id', [(int)$reservation['id'], (int)$secondReservation['id']])
        ->delete();
    $appointmentStart = (new DateTimeImmutable(
        (string)$order['business_date'] . ' 12:00:00',
        new DateTimeZone('Asia/Shanghai')
    ))->getTimestamp();
    Db::name('cashier_v3_reservation')->where('id', (int)$reservation['id'])->update([
        'tenant_id' => (string)$order['tenant_id'],
        'store_id' => (int)$order['store_id'],
        'member_id' => (int)$order['member_id'],
        'business_date' => (string)$order['business_date'],
        'appointment_start_at' => $appointmentStart,
        'appointment_end_at' => $appointmentStart + 3600,
        'status' => 'UNSTARTED',
        'actual_service_ended_at' => 0,
        'version' => $originalVersion,
    ]);
    Db::name('cashier_v3_reservation')->where('id', (int)$secondReservation['id'])->update([
        'store_id' => (int)$order['store_id'],
        'member_id' => (int)$order['member_id'],
        'business_date' => (string)$order['business_date'],
        'appointment_start_at' => $appointmentStart + 7200,
        'appointment_end_at' => $appointmentStart + 10800,
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
    $checkoutServices = Db::name('cashier_v3_entitlement_service_fact')
        ->where('tenant_id', (string)$order['tenant_id'])
        ->where('document_id', (string)$order['order_id'])
        ->where('source_document_type', 'sales_order')->where('service_status', 'completed')
        ->order('id asc')->select()->toArray();
    if (!$checkoutServices) throw new RuntimeException('CHECKOUT_PROJECT_SERVICE_FIXTURE_MISSING');

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
    foreach ([(int)$reservation['id'], (int)$secondReservation['id']] as $reservationId) {
        $links = Db::name('cashier_v3_reservation_checkout_service_link')
            ->where('tenant_id', (string)$order['tenant_id'])
            ->where('reservation_id', $reservationId)->order('id asc')->select()->toArray();
        if (count($links) !== count($checkoutServices)) {
            throw new RuntimeException('ALL_RESERVATIONS_DID_NOT_RECEIVE_ALL_CHECKOUT_SERVICES');
        }
        foreach ($links as $index => $link) {
            $source = (array)$checkoutServices[$index];
            if ((string)$link['service_fact_id'] !== (string)$source['service_fact_id']
                || (int)$link['project_id'] !== (int)$source['project_id']
                || (string)$link['craftsmen_snapshot_json'] !== (string)$source['craftsmen_snapshot_json']) {
                throw new RuntimeException('CHECKOUT_SERVICE_OR_CRAFTSMAN_SNAPSHOT_MISMATCH');
            }
        }
    }
    if ((int)$completed['syncedServiceLinkCount'] !== count($checkoutServices) * 2) {
        throw new RuntimeException('SYNCED_SERVICE_LINK_COUNT_MISMATCH');
    }
    $detail = (new CashierV3ReservationDetailQueryServices())->read(
        ['reservationId' => (int)$reservation['id']],
        $operator,
        $dataScope
    );
    $actualProjectIds = array_map('intval', array_column((array)($detail['projects'] ?? []), 'projectId'));
    $sourceProjectIds = array_map('intval', array_column($checkoutServices, 'project_id'));
    $detailProjects = array_values((array)($detail['projects'] ?? []));
    if (!$detailProjects || ($detailProjects[0]['isMain'] ?? false) !== true
        || (string)($detailProjects[0]['role'] ?? '') !== 'main') {
        throw new RuntimeException('CHECKOUT_PROJECT_DETAIL_HAS_NO_STABLE_MAIN_PROJECT');
    }
    foreach (array_slice($detailProjects, 1) as $detailProject) {
        if (($detailProject['isMain'] ?? false) === true || (string)($detailProject['role'] ?? '') !== 'detail') {
            throw new RuntimeException('CHECKOUT_PROJECT_DETAIL_HAS_MULTIPLE_MAIN_PROJECTS');
        }
    }
    $actualStaffIds = array_map('intval', array_column((array)($detail['actualCraftsmen'] ?? []), 'staffId'));
    $sourceStaffIds = [];
    foreach ($checkoutServices as $checkoutService) {
        foreach ((array)json_decode((string)$checkoutService['craftsmen_snapshot_json'], true) as $craftsman) {
            $staffId = (int)($craftsman['staff_id'] ?? $craftsman['staffId'] ?? $craftsman['id'] ?? 0);
            if ($staffId > 0) $sourceStaffIds[$staffId] = $staffId;
        }
    }
    sort($actualProjectIds);
    sort($sourceProjectIds);
    sort($actualStaffIds);
    $sourceStaffIds = array_values($sourceStaffIds);
    sort($sourceStaffIds);
    if ($detail === null || $actualProjectIds !== $sourceProjectIds || $actualStaffIds !== $sourceStaffIds) {
        throw new RuntimeException('RESERVATION_DETAIL_DID_NOT_SHOW_CHECKOUT_PROJECTS_AND_CRAFTSMEN');
    }
    $partition = (new CashierV3ReservationPartitionProvider())->readPartition(
        'checkout-reservation-integration',
        '1',
        $operator,
        $dataScope,
        ['calendarDate' => (string)$order['business_date'], 'page' => 1, 'pageSize' => 100]
    );
    $recordsById = [];
    foreach ((array)($partition['payload']['records'] ?? []) as $record) {
        $recordsById[(int)($record['reservationId'] ?? 0)] = $record;
    }
    $projectNames = array_values(array_filter(array_map(static function (array $service): string {
        return trim((string)($service['project_name_snapshot'] ?? ''));
    }, $checkoutServices)));
    $staffNames = [];
    foreach ($checkoutServices as $checkoutService) {
        foreach ((array)json_decode((string)$checkoutService['craftsmen_snapshot_json'], true) as $craftsman) {
            $name = trim((string)($craftsman['staff_name_snapshot'] ?? $craftsman['name'] ?? ''));
            if ($name !== '') $staffNames[$name] = $name;
        }
    }
    foreach ([(int)$reservation['id'], (int)$secondReservation['id']] as $reservationId) {
        $record = (array)($recordsById[$reservationId] ?? []);
        if (!$record || (string)($record['projectSource'] ?? '') !== '本次结账') {
            throw new RuntimeException('RESERVATION_LIST_DID_NOT_USE_CHECKOUT_SERVICE_SOURCE');
        }
        foreach ($projectNames as $name) {
            if (!str_contains((string)$record['projectSummary'], $name)) {
                throw new RuntimeException('RESERVATION_LIST_MISSING_CHECKOUT_PROJECT');
            }
        }
        foreach ($staffNames as $name) {
            if (!str_contains((string)$record['craftsmanSummary'], $name)) {
                throw new RuntimeException('RESERVATION_LIST_MISSING_CHECKOUT_CRAFTSMAN');
            }
        }
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
    $linkCountAfterReplay = (int)Db::name('cashier_v3_reservation_checkout_service_link')
        ->where('tenant_id', (string)$order['tenant_id'])
        ->whereIn('reservation_id', [(int)$reservation['id'], (int)$secondReservation['id']])->count();
    if ($linkCountAfterReplay !== count($checkoutServices) * 2) {
        throw new RuntimeException('REPLAY_DUPLICATED_CHECKOUT_SERVICE_LINKS');
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
    $linkCountAfterVoid = (int)Db::name('cashier_v3_reservation_checkout_service_link')
        ->where('tenant_id', (string)$order['tenant_id'])
        ->whereIn('reservation_id', [(int)$reservation['id'], (int)$secondReservation['id']])->count();
    if ((int)$voidedOrderResult['completedCount'] !== 0 || (string)$afterVoided['status'] !== 'IN_SERVICE'
        || $linkCountAfterVoid !== $linkCountAfterReplay) {
        throw new RuntimeException('VOIDED_ORDER_CHANGED_RESERVATION');
    }

    // 纯权益完成没有销售订单。只提交成功回执中的 checkout_request_id，
    // 服务端仍应从结账请求、完成回执和服务事实反查并结束预约。
    $entitlementVersion = (int)$afterVoided['version'];
    Db::name('cashier_v3_reservation')->where('id', (int)$reservation['id'])->update([
        'tenant_id' => (string)$entitlementOnlyRequest['tenant_id'],
        'store_id' => (int)$entitlementOnlyRequest['store_id'],
        'member_id' => (int)$entitlementOnlyRequest['member_id'],
        'business_date' => (string)$entitlementOnlyRequest['business_date'],
        'status' => 'IN_SERVICE', 'actual_service_ended_at' => 0,
        'version' => $entitlementVersion,
    ]);
    $entitlementOperatorId = max(1, (int)$entitlementOnlyRequest['operator_id']);
    $entitlementOperator = new CashierV3OperatorScope(
        (int)$entitlementOnlyRequest['store_id'], $entitlementOperatorId,
        (string)$entitlementOnlyRequest['organization_id'], (string)$entitlementOnlyRequest['tenant_id']
    );
    $entitlementDataScope = new CashierV3DataScopeContext(
        $entitlementOperatorId, 1, (int)$entitlementOnlyRequest['store_id'],
        (string)$entitlementOnlyRequest['tenant_id'], (string)$entitlementOnlyRequest['organization_id'],
        null, CashierV3DataScopeContext::MODE_ALL, [], true, 'integration', '1', ['cashier.v3.cashier'], [
            'id' => $entitlementOperatorId, 'employee_id' => 1,
            'staff_name' => '纯权益测试操作员', 'account' => '纯权益测试操作员',
        ]
    );
    $entitlementKey = 'CHECKOUT-RESERVATION-ENTITLEMENT-' . bin2hex(random_bytes(12));
    $entitlementScope = [
        'action' => 'complete-checkout-reservations',
        'payload' => ['checkoutRequestId' => (string)$entitlementOnlyRequest['request_id']],
        'idempotency_key' => $entitlementKey,
        'operator_scope' => $entitlementOperator, 'data_scope' => $entitlementDataScope,
        'state_context_id' => 'checkout-reservation-entitlement-integration',
        'event_recorder' => $recorder,
        'event_execution' => $recorder->newExecution(
            'complete-checkout-reservations', $entitlementKey, $entitlementOperator,
            $entitlementDataScope, 'checkout-reservation-entitlement-integration'
        ),
        'event_contract' => CashierV3ActionManifest::eventContractFor('complete-checkout-reservations'),
    ];
    $entitlementPreview = $service->preview($entitlementScope);
    if (empty($entitlementPreview['required'])
        || (string)$entitlementPreview['checkoutRequestId'] !== (string)$entitlementOnlyRequest['request_id']) {
        throw new RuntimeException('ENTITLEMENT_ONLY_PREVIEW_DID_NOT_FIND_RESERVATION');
    }
    $entitlementCompleted = $service->complete($entitlementScope);
    $entitlementReservation = (array)Db::name('cashier_v3_reservation')->where('id', (int)$reservation['id'])->find();
    $entitlementServiceIds = array_column($entitlementOnlyServices, 'service_fact_id');
    $entitlementLinks = Db::name('cashier_v3_reservation_checkout_service_link')
        ->where('tenant_id', (string)$entitlementOnlyRequest['tenant_id'])
        ->where('reservation_id', (int)$reservation['id'])
        ->whereIn('service_fact_id', $entitlementServiceIds)->select()->toArray();
    if ((int)$entitlementCompleted['completedCount'] !== 1
        || (string)$entitlementCompleted['checkoutRequestId'] !== (string)$entitlementOnlyRequest['request_id']
        || (string)$entitlementReservation['status'] !== 'COMPLETED'
        || (int)$entitlementReservation['version'] !== $entitlementVersion + 1
        || count($entitlementLinks) !== count($entitlementOnlyServices)) {
        throw new RuntimeException('ENTITLEMENT_ONLY_CHECKOUT_DID_NOT_COMPLETE_RESERVATION');
    }
    foreach ($entitlementLinks as $link) {
        if ((string)$link['sales_order_id'] !== '') {
            throw new RuntimeException('ENTITLEMENT_ONLY_LINK_FABRICATED_SALES_ORDER');
        }
    }

    echo "PASS checkout reservation completion mysql integration\n";
    echo "PASS entitlement-only checkout completes reservation through checkout request authority\n";
    Db::rollback();
} catch (Throwable $exception) {
    Db::rollback();
    throw $exception;
}
