<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);
if (!function_exists('swoole_cpu_num')) { function swoole_cpu_num(): int { return 1; } }
if (!class_exists('Swoole\\Table')) {
    eval('namespace Swoole; final class Table { public const TYPE_STRING = 7; public const TYPE_INT = 1; }');
}

$backend = getenv('CASHIER_V3_BACKEND') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\cashier\v3\reservation\CashierV3ReservationLifecycleServices;
use app\services\cashier\v3\reservation\MemberV3ReservationServices;
use app\services\mobile\reservation\MobileReservationServices;
use think\exception\ValidateException;
use think\facade\Config;
use think\facade\Db;

$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
$app->initialize();
Config::set(['default' => 'file'], 'cache');
Db::connect('mysql', true)->query('SELECT 1');

$fixture = (array)Db::name('store_order')->alias('o')
    ->join('store_order_cart_info c', 'c.oid=o.id')
    ->join('organization_store os', 'os.store_id=o.store_id')
    ->where('o.paid', 1)->where('o.is_del', 0)->where('o.is_system_del', 0)->where('o.is_user_del', 0)
    ->where('o.refund_status', 0)->where('o.terminal_action', 0)
    ->where('c.cart_type', 2)->where('c.product_type', 6)->where('c.write_surplus_times', '>=', 2)
    ->field('o.uid,o.id AS order_id,o.store_id,c.id AS detail_id,c.write_surplus_times')
    ->order('o.id desc')->find();
if (!$fixture) throw new RuntimeException('RESERVATION_V3_ENTITLEMENT_FIXTURE_MISSING');

$operators = Db::name('system_store_staff')->where('store_id', (int)$fixture['store_id'])
    ->where('status', 1)->where('is_del', 0)->where('employee_id', '>', 0)
    ->order('id asc')->limit(2)->select()->toArray();
if (count($operators) < 2) throw new RuntimeException('RESERVATION_V3_TWO_OPERATOR_FIXTURE_MISSING');
$merchantA = [
    'staffId' => (int)$operators[0]['id'], 'employeeId' => (int)$operators[0]['employee_id'],
    'storeId' => (int)$fixture['store_id'],
    'session' => ['session_id' => '11111111-1111-4111-8111-111111111111'],
    'requestMetadata' => ['requestId' => 'reservation-integration-merchant-a'],
];
$merchantB = [
    'staffId' => (int)$operators[1]['id'], 'employeeId' => (int)$operators[1]['employee_id'],
    'storeId' => (int)$fixture['store_id'],
    'session' => ['session_id' => '22222222-2222-4222-8222-222222222222'],
    'requestMetadata' => ['requestId' => 'reservation-integration-store-b'],
];

$beforeDetail = (int)$fixture['write_surplus_times'];
$beforeHolder = (int)Db::name('user_card_holder')->where('oid', (int)$fixture['order_id'])
    ->where('uid', (int)$fixture['uid'])->where('store_id', (int)$fixture['store_id'])->where('is_del', 0)->value('write_surplus_times');

Db::startTrans();
try {
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $created = (new MemberV3ReservationServices())->create((int)$fixture['uid'], (int)$fixture['order_id'], [
        'cart_num' => 1,
        'reservation_time' => $tomorrow,
        'reservation_start' => '10:00',
        'reservation_end' => '11:00',
        'custom_form' => [[]],
        'cart_info_id' => (int)$fixture['detail_id'],
        'service_staff_id' => 0,
        'service_duration_minutes' => 60,
        'addon_items' => [],
        'sync_all' => [],
        'store_id' => (int)$fixture['store_id'],
        'mark' => 'V3 integration rollback fixture',
    ]);
    $reservationId = (int)($created['reservationId'] ?? 0);
    $header = (array)Db::name('cashier_v3_reservation')->where('id', $reservationId)->lock(true)->find();
    $lines = Db::name('cashier_v3_reservation_line')->where('reservation_id', $reservationId)->order('id asc')->select()->toArray();
    $occupation = (array)Db::name('cashier_v3_reservation_entitlement_occupation')->where('reservation_id', $reservationId)->find();

    $checks = [];
    $checks['member creates pending confirmation in new generation'] = (string)($header['status'] ?? '') === 'PENDING_CONFIRMATION'
        && (string)($header['lifecycle_generation'] ?? '') === CashierV3ReservationLifecycleServices::GENERATION
        && (string)($header['source_type'] ?? '') === 'MEMBER';
    $checks['creation occupies without deducting entitlement'] = (string)($occupation['status'] ?? '') === 'ACTIVE'
        && (int)Db::name('store_order_cart_info')->where('id', (int)$fixture['detail_id'])->value('write_surplus_times') === $beforeDetail
        && (int)Db::name('user_card_holder')->where('id', (int)$occupation['card_holder_id'])->value('write_surplus_times') === $beforeHolder;

    $memberService = new MemberV3ReservationServices();
    $detailProjection = $memberService->detail((int)$fixture['uid'], $reservationId);
    $searchName = (string)($detailProjection['project_list'][0]['product_name'] ?? '');
    $searched = $memberService->listing((int)$fixture['uid'], ['search' => $searchName, 'oid' => (int)$fixture['order_id'], 'page' => 1, 'limit' => 100]);
    $searchedIds = array_map(static function (array $row): int { return (int)($row['id'] ?? 0); }, (array)($searched['list'] ?? []));
    $checks['member DTO keeps legacy list/detail display contract'] = (int)($detailProjection['id'] ?? 0) === $reservationId
        && (string)($detailProjection['reservation_time'] ?? '') === $tomorrow
        && (string)($detailProjection['reservation_start'] ?? '') === '10:00'
        && !empty($detailProjection['project_list'])
        && isset($detailProjection['cart_info']['productInfo'])
        && array_key_exists('master_phone', $detailProjection)
        && array_key_exists('service_images', $detailProjection)
        && $searchName !== ''
        && in_array($reservationId, $searchedIds, true);

    $mobile = new MobileReservationServices();
    $reservationProjection = static function (array $response): array {
        $projection = (array)($response['data']['reservation'] ?? []);
        return isset($projection['payload']) && is_array($projection['payload']) ? $projection['payload'] : $projection;
    };
    $confirmationPage = $mobile->list($merchantA, ['workflow' => 'confirmation', 'page' => 1, 'pageSize' => 1]);
    $confirmationPayload = $reservationProjection($confirmationPage);
    $checks['future member booking is visible in confirmation workflow'] = (int)($confirmationPayload['total'] ?? 0) >= 1
        && (int)($confirmationPayload['pageSize'] ?? 0) === 1
        && (string)($confirmationPayload['records'][0]['statusCode'] ?? '') === 'PENDING_CONFIRMATION';

    $command = static function (string $action, int $id, int $version, string $key): array {
        return ['command' => [
            'action' => $action,
            'idempotencyKey' => $key,
            'contexts' => [['kind' => 'reservation', 'id' => (string)$id, 'expectedVersion' => $version]],
        ]];
    };
    $editorResponse = $mobile->openEditor($merchantA, ['mode' => 'edit', 'reservationId' => $reservationId, 'preparationRequestId' => 'integration-confirm-editor']);
    $editor = (array)($editorResponse['data']['editor'] ?? []);
    $room = (array)(Db::name('table_qrcode')->where('store_id', (int)$fixture['store_id'])->where('is_del', 0)->where('is_using', 1)->field('id,remarks,table_number')->order('id asc')->find() ?: []);
    $roomId = (int)($room['id'] ?? 0);
    $roomName = trim((string)($room['remarks'] ?? ''));
    if ($roomName === '' && $roomId > 0) $roomName = trim((string)($room['table_number'] ?? '')) ?: '房间 ' . $roomId;
    $confirmAt = $tomorrow . ' 10:15';
    $confirmPayload = $command('confirm-reservation', $reservationId, 1, 'RESERVATION-aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
    $confirmPayload['reservation'] = [
        'memberId' => (int)$fixture['uid'],
        'member' => ['id' => (int)$fixture['uid'], 'name' => (string)($header['member_name_snapshot'] ?? '')],
        'appointmentTime' => $confirmAt,
        'projects' => [[
            'projectId' => (int)$lines[0]['project_id'],
            'skuId' => (int)($lines[0]['sku_id'] ?? 0),
            'name' => (string)$lines[0]['project_name_snapshot'],
            'source' => 'card',
            'entitlementSourceDetailId' => (int)$lines[0]['entitlement_source_detail_id'],
            'quantity' => (int)$lines[0]['quantity'],
            'appliedDurationMinutes' => (int)$lines[0]['service_duration_minutes'],
        ]],
        'craftsmen' => [],
        'roomId' => $roomId,
        'roomName' => $roomName,
        'remark' => 'V3 confirmation edited atomically',
    ];
    $wrongMemberPayload = $confirmPayload;
    $wrongMemberPayload['command']['idempotencyKey'] = 'RESERVATION-eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
    $wrongMemberPayload['reservation']['memberId'] = (int)$fixture['uid'] + 1;
    $wrongMemberPayload['reservation']['member']['id'] = (int)$fixture['uid'] + 1;
    $wrongMember = $mobile->confirm($merchantA, $reservationId, $wrongMemberPayload);
    $afterWrongMember = (array)Db::name('cashier_v3_reservation')->where('id', $reservationId)->find();
    $checks['confirmation rejects customer replacement without partial writes'] = !in_array((string)($wrongMember['result']['status'] ?? ''), ['success', 'succeeded'], true)
        && (string)($afterWrongMember['status'] ?? '') === 'PENDING_CONFIRMATION'
        && (int)($afterWrongMember['version'] ?? 0) === 1
        && Db::name('cashier_v3_reservation_entitlement_occupation')->where('reservation_id', $reservationId)->where('status', 'ACTIVE')->count() === 1;
    $confirmed = $mobile->confirm($merchantA, $reservationId, $confirmPayload);
    $confirmedReplay = $mobile->confirm($merchantA, $reservationId, $confirmPayload);
    if (!in_array((string)($confirmed['result']['status'] ?? ''), ['success', 'succeeded'], true)) {
        throw new RuntimeException('CONFIRM_COMMAND_FAILED ' . json_encode($confirmed, JSON_UNESCAPED_UNICODE));
    }
    $header = (array)Db::name('cashier_v3_reservation')->where('id', $reservationId)->find();
    $unstartedProjection = $reservationProjection($mobile->list($merchantB, ['workflow' => 'service', 'quickFilter' => 'unstarted', 'page' => 1, 'pageSize' => 100]));
    $unstartedRecords = (array)($unstartedProjection['records'] ?? []);
    $activeOccupationsAfterConfirm = Db::name('cashier_v3_reservation_entitlement_occupation')->where('reservation_id', $reservationId)->where('status', 'ACTIVE')->count();
    $releasedOccupationsAfterConfirm = Db::name('cashier_v3_reservation_entitlement_occupation')->where('reservation_id', $reservationId)->where('status', 'RELEASED')->count();
    $checks['mobile editor opens the same pending reservation with customer fixed'] = !empty($editor['preparationReady'])
        && (int)($editor['draft']['reservationId'] ?? 0) === $reservationId
        && (int)($editor['draft']['memberId'] ?? 0) === (int)$fixture['uid'];
    $checks['merchant edits and confirms member booking atomically through V3 command'] = (string)($header['status'] ?? '') === 'UNSTARTED'
        && (int)($header['version'] ?? 0) === 2
        && (int)($header['member_id'] ?? 0) === (int)$fixture['uid']
        && (int)($header['appointment_start_at'] ?? 0) === strtotime($confirmAt)
        && (int)($header['room_id'] ?? 0) === $roomId
        && (string)($header['remark_snapshot'] ?? '') === 'V3 confirmation edited atomically'
        && $activeOccupationsAfterConfirm === 1
        && $releasedOccupationsAfterConfirm === 1
        && !empty($confirmedReplay['replay']);
    $checks['service unstarted filter returns only waiting service'] = in_array($reservationId, array_map(static function (array $row): int { return (int)($row['reservationId'] ?? 0); }, $unstartedRecords), true)
        && !array_filter($unstartedRecords, static function (array $row): bool { return (string)($row['statusCode'] ?? '') !== 'UNSTARTED'; });

    $startPayload = $command('start-reservation-service', $reservationId, 2, 'RESERVATION-bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
    $started = $mobile->startService($merchantB, $reservationId, $startPayload);
    $startedReplay = $mobile->startService($merchantB, $reservationId, $startPayload);
    if (!in_array((string)($started['result']['status'] ?? ''), ['success', 'succeeded'], true)) {
        throw new RuntimeException('START_COMMAND_FAILED ' . json_encode($started, JSON_UNESCAPED_UNICODE));
    }
    $header = (array)Db::name('cashier_v3_reservation')->where('id', $reservationId)->find();
    $service = (array)Db::name('cashier_v3_service_order')->where('id', (int)($header['service_order_id'] ?? 0))->find();
    $servingProjection = $reservationProjection($mobile->list($merchantA, ['workflow' => 'service', 'quickFilter' => 'serving', 'page' => 1, 'pageSize' => 100]));
    $servingRecords = (array)($servingProjection['records'] ?? []);

    $endPayload = $command('end-reservation-service', $reservationId, 3, 'RESERVATION-cccccccc-cccc-4ccc-8ccc-cccccccccccc');
    $ended = $mobile->endService($merchantA, $reservationId, $endPayload);
    $endedReplay = $mobile->endService($merchantA, $reservationId, $endPayload);
    if (!in_array((string)($ended['result']['status'] ?? ''), ['success', 'succeeded'], true)) {
        throw new RuntimeException('END_COMMAND_FAILED ' . json_encode($ended, JSON_UNESCAPED_UNICODE));
    }
    $header = (array)Db::name('cashier_v3_reservation')->where('id', $reservationId)->find();
    $endedProjection = $reservationProjection($mobile->list($merchantB, ['workflow' => 'service', 'quickFilter' => 'ended', 'page' => 1, 'pageSize' => 100]));
    $endedRecords = (array)($endedProjection['records'] ?? []);
    $endedDetailResponse = $mobile->detail($merchantA, ['reservationId' => $reservationId]);
    $endedDetail = (array)($endedDetailResponse['data']['reservation']['detail'] ?? []);
    $facts = [
        'service' => Db::name('cashier_v3_entitlement_service_fact')->where('checkout_request_id', 'reservation:' . $reservationId)->count(),
        'writeoff' => Db::name('cashier_v3_entitlement_writeoff_fact')->where('checkout_request_id', 'reservation:' . $reservationId)->count(),
        'performance' => Db::name('cashier_v3_performance_fact')->where('checkout_request_id', 'reservation:' . $reservationId)->count(),
    ];

    $checks['start writes service order with actual start'] = (int)($service['id'] ?? 0) > 0
        && (int)($service['service_started_at'] ?? 0) > 0
        && !empty($startedReplay['replay']);
    $checks['service serving filter returns only in-service records'] = in_array($reservationId, array_map(static function (array $row): int { return (int)($row['reservationId'] ?? 0); }, $servingRecords), true)
        && !array_filter($servingRecords, static function (array $row): bool { return (string)($row['statusCode'] ?? '') !== 'IN_SERVICE'; });
    $checks['end converts occupation into one entitlement consumption'] = (string)Db::name('cashier_v3_reservation_entitlement_occupation')->where('reservation_id', $reservationId)->value('status') === 'CONSUMED'
        && (int)Db::name('store_order_cart_info')->where('id', (int)$fixture['detail_id'])->value('write_surplus_times') === $beforeDetail - 1
        && (int)Db::name('user_card_holder')->where('id', (int)$occupation['card_holder_id'])->value('write_surplus_times') === $beforeHolder - 1;
    $checks['service end is explicit, timer never auto-completes'] = (string)Db::name('cashier_v3_service_order')->where('id', (int)($service['id'] ?? 0))->value('status') === 'COMPLETED'
        && (string)($header['status'] ?? '') === 'COMPLETED'
        && !empty($endedReplay['replay']);
    $checks['service ended filter returns only completed records'] = in_array($reservationId, array_map(static function (array $row): int { return (int)($row['reservationId'] ?? 0); }, $endedRecords), true)
        && !array_filter($endedRecords, static function (array $row): bool { return (string)($row['statusCode'] ?? '') !== 'COMPLETED'; });
    $checks['service end writes immutable service writeoff and performance facts'] = (int)($facts['service'] ?? 0) === 1
        && (int)($facts['writeoff'] ?? 0) === 1
        && (int)($facts['performance'] ?? 0) >= 1
        && Db::name('cashier_v3_entitlement_service_fact')->where('checkout_request_id', 'reservation:' . $reservationId)->count() === 1
        && Db::name('cashier_v3_entitlement_writeoff_fact')->where('checkout_request_id', 'reservation:' . $reservationId)->count() === 1;
    $checks['completed reservation detail identifies deducted entitlement per project'] =
        (string)($endedDetail['projects'][0]['processingStatus'] ?? '') === 'entitlement_deducted'
        && (string)($endedDetail['projects'][0]['processingStatusLabel'] ?? '') === '已扣权益'
        && !empty($endedDetail['projects'][0]['sourceCardName'])
        && ($endedDetail['projects'][0]['entitlementDeducted'] ?? null) === true;

    // A debt can appear after the entitlement was reserved. Ending the real
    // service must still succeed, while the reserved entitlement is released
    // for explicit manual handling and no writeoff/performance is fabricated.
    $debtCandidate = $memberService->create((int)$fixture['uid'], (int)$fixture['order_id'], [
        'cart_num' => 1,
        'reservation_time' => $tomorrow,
        'reservation_start' => '16:00',
        'reservation_end' => '17:00',
        'custom_form' => [[]],
        'cart_info_id' => (int)$fixture['detail_id'],
        'service_staff_id' => 0,
        'service_duration_minutes' => 60,
        'addon_items' => [],
        'sync_all' => [],
        'store_id' => (int)$fixture['store_id'],
        'mark' => 'V3 debt discovered at service end fixture',
    ]);
    $debtReservationId = (int)$debtCandidate['reservationId'];
    $debtHeader = (array)Db::name('cashier_v3_reservation')->where('id', $debtReservationId)->find();
    $debtLines = Db::name('cashier_v3_reservation_line')->where('reservation_id', $debtReservationId)->order('id asc')->select()->toArray();
    $debtConfirm = $command('confirm-reservation', $debtReservationId, 1, 'RESERVATION-11111111-1111-4111-8111-111111111111');
    $debtConfirm['reservation'] = [
        'memberId' => (int)$fixture['uid'],
        'member' => ['id' => (int)$fixture['uid'], 'name' => (string)($debtHeader['member_name_snapshot'] ?? '')],
        'appointmentTime' => $tomorrow . ' 16:00',
        'projects' => [[
            'projectId' => (int)$debtLines[0]['project_id'],
            'skuId' => (int)($debtLines[0]['sku_id'] ?? 0),
            'name' => (string)$debtLines[0]['project_name_snapshot'],
            'source' => 'card',
            'entitlementSourceDetailId' => (int)$debtLines[0]['entitlement_source_detail_id'],
            'quantity' => (int)$debtLines[0]['quantity'],
            'appliedDurationMinutes' => (int)$debtLines[0]['service_duration_minutes'],
        ]],
        'craftsmen' => [],
        'roomId' => 0,
        'roomName' => '',
        'remark' => 'V3 debt discovered at service end fixture',
    ];
    $debtConfirmed = $mobile->confirm($merchantA, $debtReservationId, $debtConfirm);
    if (!in_array((string)($debtConfirmed['result']['status'] ?? ''), ['success', 'succeeded'], true)) {
        throw new RuntimeException('DEBT_CONFIRM_COMMAND_FAILED ' . json_encode($debtConfirmed, JSON_UNESCAPED_UNICODE));
    }
    $debtStarted = $mobile->startService($merchantA, $debtReservationId,
        $command('start-reservation-service', $debtReservationId, 2, 'RESERVATION-22222222-2222-4222-8222-222222222222'));
    if (!in_array((string)($debtStarted['result']['status'] ?? ''), ['success', 'succeeded'], true)) {
        throw new RuntimeException('DEBT_START_COMMAND_FAILED ' . json_encode($debtStarted, JSON_UNESCAPED_UNICODE));
    }
    $beforeDebtEndDetail = (int)Db::name('store_order_cart_info')->where('id', (int)$fixture['detail_id'])->value('write_surplus_times');
    $debtOccupation = (array)Db::name('cashier_v3_reservation_entitlement_occupation')->where('reservation_id', $debtReservationId)->where('status', 'ACTIVE')->find();
    $beforeDebtEndHolder = (int)Db::name('user_card_holder')->where('id', (int)$debtOccupation['card_holder_id'])->value('write_surplus_times');
    $testDebtNo = 'TEST-END-' . $debtReservationId;
    $testDebtId = (int)Db::name('store_debt')->insertGetId([
        'debt_no' => $testDebtNo,
        'order_id' => (int)$fixture['order_id'],
        'order_sn' => 'TEST-' . (int)$fixture['order_id'],
        'uid' => (int)$fixture['uid'],
        'store_id' => (int)$fixture['store_id'],
        'staff_id' => (int)$merchantA['staffId'],
        'total_debt' => '1.00',
        'repaid_debt' => '0.00',
        'status' => 0,
        'remark' => 'transaction rollback fixture',
        'add_time' => time(),
        'update_time' => time(),
    ]);
    Db::name('store_order')->where('id', (int)$fixture['order_id'])->update(['debt_amount' => '1.00', 'repaid_debt_amount' => '0.00']);
    $debtEndPayload = $command('end-reservation-service', $debtReservationId, 3, 'RESERVATION-33333333-3333-4333-8333-333333333333');
    $debtEnded = $mobile->endService($merchantA, $debtReservationId, $debtEndPayload);
    $debtEndedReplay = $mobile->endService($merchantA, $debtReservationId, $debtEndPayload);
    if (!in_array((string)($debtEnded['result']['status'] ?? ''), ['success', 'succeeded'], true)) {
        throw new RuntimeException('DEBT_END_COMMAND_FAILED ' . json_encode($debtEnded, JSON_UNESCAPED_UNICODE));
    }
    $debtEndFacts = (array)($debtEnded['data']['reservationAction']['facts'] ?? []);
    $debtBlockedSnapshots = (array)($debtEndFacts['debtBlockedEntitlements'] ?? []);
    $debtCardName = (string)Db::name('user_card_holder')->where('id', (int)$debtOccupation['card_holder_id'])->value('card_name');
    $debtProjectName = (string)($debtLines[0]['project_name_snapshot'] ?? '');
    $debtWarningMessage = (string)($debtEnded['feedback']['message'] ?? '');
    $debtDetailResponse = $mobile->detail($merchantA, ['reservationId' => $debtReservationId]);
    $debtDetail = (array)($debtDetailResponse['data']['reservation']['detail'] ?? []);
    if (strpos($debtWarningMessage, '卡项「' . $debtCardName . '」有欠款') === false
        || strpos($debtWarningMessage, '项目「' . $debtProjectName . '」未扣权益') === false
        || empty($debtEnded['feedback']['persistent'])
        || empty($debtEndFacts['manualWriteoffRequired'])
        || (string)($debtBlockedSnapshots[0]['cardName'] ?? '') !== $debtCardName
        || (string)($debtBlockedSnapshots[0]['projectName'] ?? '') !== $debtProjectName) {
        throw new RuntimeException('DEBT_END_RESPONSE_INVALID ' . json_encode($debtEnded, JSON_UNESCAPED_UNICODE));
    }
    $checks['debt discovered at end still completes service and returns manual writeoff warning'] =
        (string)Db::name('cashier_v3_reservation')->where('id', $debtReservationId)->value('status') === 'COMPLETED'
        && (string)Db::name('cashier_v3_service_order')->where('id', (int)Db::name('cashier_v3_reservation')->where('id', $debtReservationId)->value('service_order_id'))->value('status') === 'COMPLETED'
        && !empty($debtEndFacts['manualWriteoffRequired'])
        && strpos($debtWarningMessage, '卡项「' . $debtCardName . '」有欠款') !== false
        && strpos($debtWarningMessage, '项目「' . $debtProjectName . '」未扣权益') !== false
        && !empty($debtEnded['feedback']['persistent'])
        && !empty($debtEndedReplay['replay']);
    $checks['completed reservation detail identifies debt-blocked entitlement per project'] =
        (string)($debtDetail['projects'][0]['processingStatus'] ?? '') === 'debt_blocked'
        && (string)($debtDetail['projects'][0]['processingStatusLabel'] ?? '') === '欠款未扣权益'
        && (string)($debtDetail['projects'][0]['sourceCardName'] ?? '') === $debtCardName
        && ($debtDetail['projects'][0]['entitlementDeducted'] ?? null) === false;
    $checks['debt discovered at end releases occupation without deducting entitlement'] =
        (string)Db::name('cashier_v3_reservation_entitlement_occupation')->where('reservation_id', $debtReservationId)->value('status') === 'RELEASED'
        && (int)Db::name('store_order_cart_info')->where('id', (int)$fixture['detail_id'])->value('write_surplus_times') === $beforeDebtEndDetail
        && (int)Db::name('user_card_holder')->where('id', (int)$debtOccupation['card_holder_id'])->value('write_surplus_times') === $beforeDebtEndHolder;
    $checks['debt discovered at end records service only and never writeoff or performance'] =
        Db::name('cashier_v3_entitlement_service_fact')->where('checkout_request_id', 'reservation:' . $debtReservationId)->count() === 1
        && Db::name('cashier_v3_entitlement_writeoff_fact')->where('checkout_request_id', 'reservation:' . $debtReservationId)->count() === 0
        && Db::name('cashier_v3_performance_fact')->where('checkout_request_id', 'reservation:' . $debtReservationId)->count() === 0
        && Db::name('cashier_v3_reservation_operation')->where('reservation_id', $debtReservationId)->where('operation_type', 'END_SERVICE')->count() === 1;
    Db::name('store_debt')->where('id', $testDebtId)->delete();
    Db::name('store_order')->where('id', (int)$fixture['order_id'])->update(['debt_amount' => '0.00', 'repaid_debt_amount' => '0.00']);

    $cancelCandidate = (new MemberV3ReservationServices())->create((int)$fixture['uid'], (int)$fixture['order_id'], [
        'cart_num' => 1,
        'reservation_time' => $tomorrow,
        'reservation_start' => '12:00',
        'reservation_end' => '13:00',
        'custom_form' => [[]],
        'cart_info_id' => (int)$fixture['detail_id'],
        'service_staff_id' => 0,
        'service_duration_minutes' => 60,
        'addon_items' => [],
        'sync_all' => [],
        'store_id' => (int)$fixture['store_id'],
        'mark' => 'V3 cancellation rollback fixture',
    ]);
    $cancelReservationId = (int)$cancelCandidate['reservationId'];
    $cancelled = (new MemberV3ReservationServices())->cancel((int)$fixture['uid'], $cancelReservationId);
    $checks['member cancellation releases occupation without consuming'] = (string)($cancelled['status'] ?? '') === 'CANCELLED'
        && (string)Db::name('cashier_v3_reservation_entitlement_occupation')->where('reservation_id', $cancelReservationId)->value('status') === 'RELEASED'
        && (int)Db::name('store_order_cart_info')->where('id', (int)$fixture['detail_id'])->value('write_surplus_times') === $beforeDetail - 1;
    $checks['member cancellation records event and idempotent operation'] = Db::name('cashier_v3_business_event')->where('aggregate_type', 'reservation')
            ->where('aggregate_id', (string)$cancelReservationId)->where('event_type', 'reservation.cancelled')->count() === 1
        && Db::name('cashier_v3_reservation_operation')->where('reservation_id', $cancelReservationId)->where('operation_type', 'CANCEL')->count() === 1;

    $rejectCandidate = $memberService->create((int)$fixture['uid'], (int)$fixture['order_id'], [
        'cart_num' => 1, 'reservation_time' => $tomorrow,
        'reservation_start' => '14:00', 'reservation_end' => '15:00',
        'custom_form' => [[]], 'cart_info_id' => (int)$fixture['detail_id'],
        'service_staff_id' => 0, 'service_duration_minutes' => 60,
        'addon_items' => [], 'sync_all' => [], 'store_id' => (int)$fixture['store_id'],
        'mark' => 'V3 rejection rollback fixture',
    ]);
    $rejectReservationId = (int)$rejectCandidate['reservationId'];
    $rejectPayload = $command('reject-reservation', $rejectReservationId, 1, 'RESERVATION-dddddddd-dddd-4ddd-8ddd-dddddddddddd');
    $rejectPayload['reason'] = '本时段门店资源不可用';
    $rejected = $mobile->reject($merchantB, $rejectReservationId, $rejectPayload);
    $rejectedReplay = $mobile->reject($merchantB, $rejectReservationId, $rejectPayload);
    $rejectHeader = (array)Db::name('cashier_v3_reservation')->where('id', $rejectReservationId)->find();
    $checks['merchant rejection releases occupation and is idempotent'] = in_array((string)($rejected['result']['status'] ?? ''), ['success', 'succeeded'], true)
        && !empty($rejectedReplay['replay'])
        && (string)($rejectHeader['status'] ?? '') === 'REJECTED'
        && (string)($rejectHeader['reject_reason'] ?? '') === '本时段门店资源不可用'
        && (string)Db::name('cashier_v3_reservation_entitlement_occupation')->where('reservation_id', $rejectReservationId)->value('status') === 'RELEASED'
        && Db::name('cashier_v3_reservation_operation')->where('reservation_id', $rejectReservationId)->where('operation_type', 'REJECT')->count() === 1
        && Db::name('cashier_v3_business_event')->where('aggregate_type', 'reservation')->where('aggregate_id', (string)$rejectReservationId)->where('event_type', 'reservation.rejected')->count() === 1;
    $deleted = $memberService->delete((int)$fixture['uid'], $cancelReservationId);
    $deletedReplay = $memberService->delete((int)$fixture['uid'], $cancelReservationId);
    $afterDeleteList = $memberService->listing((int)$fixture['uid'], ['page' => 1, 'limit' => 100]);
    $visibleIds = array_map(static function (array $row): int { return (int)($row['id'] ?? 0); }, (array)($afterDeleteList['list'] ?? []));
    $detailHidden = false;
    try {
        $memberService->detail((int)$fixture['uid'], $cancelReservationId);
    } catch (ValidateException $exception) {
        $detailHidden = true;
    }
    $checks['member delete hides only cancelled authority row'] = !empty($deleted['deleted'])
        && !empty($deletedReplay['deleted'])
        && !in_array($cancelReservationId, $visibleIds, true)
        && $detailHidden
        && (int)Db::name('cashier_v3_reservation')->where('id', $cancelReservationId)->value('member_deleted_at') > 0
        && Db::name('cashier_v3_reservation_operation')->where('reservation_id', $cancelReservationId)->where('operation_type', 'MEMBER_HIDE')->count() === 1;

    $checks['cross-client repeated commands never double consume'] = Db::name('cashier_v3_reservation_operation')->where('reservation_id', $reservationId)->where('operation_type', 'CONFIRM')->count() === 1
        && Db::name('cashier_v3_reservation_operation')->where('reservation_id', $reservationId)->where('operation_type', 'START_SERVICE')->count() === 1
        && Db::name('cashier_v3_reservation_operation')->where('reservation_id', $reservationId)->where('operation_type', 'END_SERVICE')->count() === 1
        && Db::name('cashier_v3_entitlement_writeoff_fact')->where('checkout_request_id', 'reservation:' . $reservationId)->count() === 1;

    foreach ($checks as $label => $passed) {
        if (!$passed) throw new RuntimeException('FAIL: ' . $label);
        echo 'PASS: ' . $label . PHP_EOL;
    }
    echo "RESERVATION_CROSS_CLIENT_MYSQL_INTEGRATION=PASS\n";
} finally {
    Db::rollback();
}
