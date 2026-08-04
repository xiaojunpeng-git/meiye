<?php

namespace app\services\cashier\v3\reservation;

use app\services\cashier\v3\CashierV3ActionDispatcher;
use app\services\cashier\v3\CashierV3BusinessDocumentNumberServices;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResourceScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\cashier\CashierV3SaleCatalogServices;
use app\services\cashier\v3\event\CashierV3BusinessEventExecution;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\hang\CashierV3LegacyRoomReadProvider;
use app\services\cashier\v3\projection\CashierV3RootDomainAssembler;
use app\services\cashier\v3\registry\CashierV3ContextPolicy;
use think\facade\Db;

/** New C3 appointment creation. It never writes or interprets legacy reservations. */
final class CashierV3ReservationModule
{
    public static function install(CashierV3ActionDispatcher $dispatcher, CashierV3RootDomainAssembler $assembler): void
    {
        $catalog = new CashierV3SaleCatalogServices();
        $rooms = new CashierV3LegacyRoomReadProvider();
        $handlers = $dispatcher->handlers();
        if (!$handlers->hasProjection('open-reservation-editor')) {
            $handlers->registerProjection('open-reservation-editor', function (array $scope) use ($catalog, $rooms, $dispatcher): array {
                $operator = $scope['operator_scope']; $dataScope = $scope['data_scope'];
                $payload = (array)($scope['payload'] ?? []);
                if (($payload['mode'] ?? 'create') !== 'create' || !empty($payload['reservationId'])) {
                    throw new CashierV3CommandException(CashierV3ResultCode::ACTION_NOT_IMPLEMENTED, '当前仅开放新建预约，旧预约不进入本次改造。');
                }
                $stateContextId = (string)($scope['state_context_id'] ?? '');
                $workspaceId = sprintf('ws:%d:%d:%s', $operator->storeId(), $operator->operatorId(), $stateContextId);
                $workspaceVersion = (int)Db::name('cashier_v3_resource_version')->where('scope_type', 'store')->where('scope_id', (string)$operator->storeId())->where('resource_kind', 'cashier_workspace')->where('resource_id', $workspaceId)->value('current_version');
                if ($workspaceVersion <= 0) throw new CashierV3CommandException(CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY, '收银工作台尚未准备完成，请刷新后再创建预约。');
                $options = [];
                foreach ((array)($catalog->catalog($operator, $dataScope)['items'] ?? []) as $item) {
                    if ((int)($item['productType'] ?? -1) !== 6 || !empty($item['disabled'])) continue;
                    $options[] = ['id' => (int)$item['productId'], 'projectId' => (int)$item['productId'], 'skuId' => (int)$item['skuId'], 'name' => (string)$item['name'], 'source' => 'unpaid', 'selectable' => true, 'productVersion' => (int)$item['productVersion'], 'skuVersion' => (int)$item['skuVersion']];
                }
                $token = bin2hex(random_bytes(16));
                return ['data' => ['editor' => [
                    'preparationReady' => true, 'preparationRequestId' => (string)($payload['preparationRequestId'] ?? ''), 'preparationToken' => $token,
                    'commandContexts' => [['kind' => 'cashier_workspace', 'id' => $workspaceId, 'expectedVersion' => $workspaceVersion]],
                    'draft' => ['member' => null, 'memberId' => null, 'projects' => [], 'craftsmen' => [], 'appointmentTime' => '', 'room' => null, 'roomId' => null, 'remark' => ''],
                    'catalogOptions' => $options, 'craftsmenOptions' => self::craftsmen($operator), 'rooms' => $rooms->roomCandidates($operator, $dataScope),
                ]], 'message' => '预约编辑器已准备完成。'];
            });
        }
        if (!$handlers->hasProjection('query-reservations')) {
            $handlers->registerProjection('query-reservations', function (array $scope) use ($assembler): array {
                $part = (new CashierV3ReservationPartitionProvider())->readPartition((string)($scope['state_context_id'] ?? ''), '', $scope['operator_scope'], $scope['data_scope']);
                return ['data' => ['reservation' => $part['payload']], 'versions' => $part['public_versions'], 'return_root_state' => true, 'message' => '预约列表已刷新。'];
            });
        }
        if (!$handlers->hasProjection('recalculate-reservation-plan')) {
            $handlers->registerProjection('recalculate-reservation-plan', function (array $scope): array {
                $payload = (array)($scope['payload'] ?? []);
                $reservation = (array)($payload['reservation'] ?? []);
                $requestId = trim((string)($payload['recalculationRequestId'] ?? ''));
                $when = strtotime((string)($reservation['appointmentTime'] ?? $reservation['appointmentStartAt'] ?? ''));
                if ($requestId === '' || $when === false || $when <= time()) {
                    throw new CashierV3CommandException(CashierV3ResultCode::INVALID_COMMAND_CONTEXT, '请选择晚于当前时间的预约时间后再保存。');
                }
                $reservation['appointmentTime'] = date('Y-m-d H:i', $when);
                $reservation['appointmentStartAt'] = $when;
                $reservation['appointmentEndAt'] = $when + 3600;
                $reservation['scheduleCalculationReady'] = true;
                $reservation['scheduleRecalculationRequestId'] = $requestId;
                // New reservations do not occupy a room until service starts.
                // Existing V3 open-service guards are still shown by the editor
                // and an occupied room cannot be selected at creation.
                return ['data' => ['reservationPlan' => [
                    'recalculationRequestId' => $requestId,
                    'draft' => $reservation,
                    'hasConflict' => false,
                    'conflicts' => [],
                ]], 'message' => '预约时长已计算。'];
            });
        }
        if (!$handlers->hasCommand('create-reservation')) {
            $handlers->registerCommand('create-reservation', function (array $scope) use ($dispatcher, $catalog): array {
                $result = self::create($scope, $dispatcher, $catalog);
                return ['data' => ['reservationSubmission' => $result], 'business_no' => $result['reservationNo'], 'touched' => ['cashier_workspace'], 'message' => '预约已创建。'];
            });
        }
        if (!$dispatcher->policies()->has('create-reservation')) {
            $dispatcher->policies()->register(new CashierV3ContextPolicy('create-reservation', ['cashier_workspace'], [], null, ['cashier_workspace']));
        }
        $assembler->registerPartitionProvider(new CashierV3ReservationPartitionProvider());
    }

    private static function create(array $scope, CashierV3ActionDispatcher $dispatcher, CashierV3SaleCatalogServices $catalog): array
    {
        $operator = $scope['operator_scope']; $dataScope = $scope['data_scope']; $payload = (array)($scope['payload'] ?? []); $reservation = (array)($payload['reservation'] ?? []);
        $memberId = self::positive($reservation['memberId'] ?? 0, '请选择会员。');
        $when = strtotime((string)($reservation['appointmentTime'] ?? $reservation['appointmentStartAt'] ?? ''));
        if ($when === false || $when <= time()) throw new CashierV3CommandException(CashierV3ResultCode::INVALID_COMMAND_CONTEXT, '预约时间必须晚于当前时间。');
        if ($dataScope->employeeId() <= 0) {
            throw new CashierV3CommandException(CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY, '当前收银员未关联有效员工档案，不能创建正式预约。');
        }
        $member = Db::name('user')->where('uid', $memberId)->where('is_del', 0)->field('uid,real_name,nickname,phone')->lock(true)->find();
        if (!$member) throw new CashierV3CommandException(CashierV3ResultCode::RESOURCE_NOT_FOUND, '会员不存在或已停用。');
        $memberStore = Db::name('store_user')->where('store_id', $operator->storeId())->where('uid', $memberId)->where('status', 1)->lock(true)->find();
        if (!$memberStore) throw new CashierV3CommandException(CashierV3ResultCode::PERMISSION_DENIED, '该会员不属于当前门店，不能创建预约。');
        $projects = is_array($reservation['projects'] ?? null) ? $reservation['projects'] : (array)($reservation['projectLines'] ?? []);
        if (!$projects) throw new CashierV3CommandException(CashierV3ResultCode::INVALID_COMMAND_CONTEXT, '请至少选择一个项目。');
        $authoritativeProjects = [];
        foreach ((array)($catalog->catalog($operator, $dataScope)['items'] ?? []) as $item) {
            if ((int)($item['productType'] ?? 0) !== 6 || !empty($item['disabled'])) continue;
            $authoritativeProjects[(int)$item['productId'] . ':' . (int)$item['skuId']] = $item;
        }
        $now = time(); $tenant = $dataScope->tenantId(); $store = Db::name('system_store')->where('id', $operator->storeId())->field('id,name')->find();
        if (!$store) throw new CashierV3CommandException(CashierV3ResultCode::RESOURCE_NOT_FOUND, '当前门店不存在。');
        $reservationNo = 'YY' . date('YmdHis', $now) . substr((string)mt_rand(1000, 9999), 0, 4); $reservationId = 'RSV-' . bin2hex(random_bytes(12));
        $roomId = max(0, (int)($reservation['roomId'] ?? 0)); $roomName = '';
        if ($roomId > 0) {
            $room = Db::name('table_qrcode')->where('id', $roomId)->where('store_id', $operator->storeId())->where('is_del', 0)->where('is_using', 1)->lock(true)->find();
            if (!$room) throw new CashierV3CommandException(CashierV3ResultCode::RESOURCE_NOT_FOUND, '选择的房间不存在或不可用。');
            $available = false;
            foreach ((new CashierV3LegacyRoomReadProvider())->roomCandidates($operator, $dataScope) as $candidate) {
                if ((int)($candidate['id'] ?? 0) === $roomId && !empty($candidate['selectable']) && !empty($candidate['canSelect'])) { $available = true; break; }
            }
            if (!$available) throw new CashierV3CommandException(CashierV3ResultCode::RESOURCE_NOT_FOUND, '选择的房间当前已被占用或不可用，请重新选择。');
            $roomName = trim((string)($room['remarks'] ?? $room['table_number'] ?? ''));
            if ($roomName === '') $roomName = '房间 ' . $roomId;
        }
        $artisanStaffIds = self::positiveIds($reservation['craftsmen'] ?? []);
        $artisanRows = $artisanStaffIds ? Db::name('system_store_staff')->whereIn('id', $artisanStaffIds)
            ->where('store_id', $operator->storeId())->where('status', 1)->where('is_del', 0)
            ->where('cashier_craftsman_enabled', 1)
            ->field('id,employee_id,staff_name')->lock(true)->select()->toArray() : [];
        if (count($artisanRows) !== count($artisanStaffIds)) {
            throw new CashierV3CommandException(CashierV3ResultCode::RESOURCE_NOT_FOUND, '选择的手艺人不存在、已停用、不属于当前门店或已关闭手艺人资格，请重新选择。');
        }
        $artisanEmployeeIds = [];
        foreach ($artisanRows as $artisan) {
            $employeeId = (int)($artisan['employee_id'] ?? 0);
            if ($employeeId > 0) $artisanEmployeeIds[$employeeId] = $employeeId;
        }
        $header = ['reservation_id' => $reservationId, 'reservation_no' => $reservationNo, 'tenant_id' => $tenant, 'organization_id' => $operator->organizationId(), 'organization_path' => '', 'organization_name_snapshot' => '', 'store_id' => $operator->storeId(), 'store_name_snapshot' => (string)$store['name'], 'member_id' => $memberId, 'member_name_snapshot' => (string)($member['real_name'] ?: $member['nickname']), 'member_phone_snapshot' => (string)($member['phone'] ?? ''), 'service_order_id' => 0, 'service_order_no_snapshot' => '', 'room_id' => $roomId, 'room_name_snapshot' => $roomName, 'appointment_start_at' => $when, 'appointment_end_at' => $when + 3600, 'status' => 'PENDING_CONFIRMATION', 'version' => 1, 'remark_snapshot' => mb_substr(trim((string)($reservation['remark'] ?? '')), 0, 500), 'creator_staff_id' => $operator->operatorId(), 'creator_employee_id' => $dataScope->employeeId(), 'creator_name_snapshot' => (string)($dataScope->operatorProfile()['account'] ?? ''), 'business_date' => date('Y-m-d', $when), 'occurred_at' => $now, 'recorded_at' => $now, 'created_at' => $now, 'updated_at' => $now];
        $headerId = (int)Db::name('cashier_v3_reservation')->insertGetId($header); if ($headerId <= 0) throw new \RuntimeException('reservation_insert_failed');
        $serviceNo = (new CashierV3BusinessDocumentNumberServices())->allocateForSourceInTx(
            $tenant,
            CashierV3BusinessDocumentNumberServices::SERVICE,
            'reservation_service_order',
            (string)$headerId,
            date('Y-m-d', $when),
            $now
        );
        $serviceOrderId = (int)Db::name('cashier_v3_service_order')->insertGetId(['service_order_no' => $serviceNo, 'tenant_id' => $tenant, 'organization_id' => $operator->organizationId(), 'organization_path' => '', 'organization_name_snapshot' => '', 'business_store_id' => $operator->storeId(), 'business_store_name_snapshot' => (string)$store['name'], 'member_id' => $memberId, 'member_name_snapshot' => (string)($member['real_name'] ?: $member['nickname']), 'source_type' => 'reservation', 'source_id' => $headerId, 'source_no_snapshot' => $reservationNo, 'source_version_snapshot' => 1, 'room_id' => $roomId, 'room_name_snapshot' => $roomName, 'participant_employee_ids_json' => json_encode(array_values($artisanEmployeeIds)), 'status' => 'OPEN', 'version' => 1, 'business_date' => date('Y-m-d', $when), 'occurred_at' => $now, 'recorded_at' => $now, 'created_by_staff_id' => $operator->operatorId(), 'created_by_employee_id' => $dataScope->employeeId(), 'created_by_name_snapshot' => (string)($dataScope->operatorProfile()['account'] ?? ''), 'created_at' => $now, 'updated_at' => $now]);
        if ($serviceOrderId <= 0) throw new \RuntimeException('service_order_insert_failed');
        Db::name('cashier_v3_reservation')->where('id', $headerId)->update(['service_order_id' => $serviceOrderId, 'service_order_no_snapshot' => $serviceNo, 'updated_at' => $now]);
        // Reservation project lines are authoritative in their own table. Do
        // not forge a SALE_PROJECT or entitlement line: those C3 line types
        // have different occupation and checkout semantics and would corrupt
        // later completion accounting.
        $lineNo = 0;
        foreach ($projects as $project) {
            $projectId = self::positive($project['projectId'] ?? $project['id'] ?? 0, '预约项目无效。');
            $skuId = self::positive($project['skuId'] ?? 0, '预约项目规格无效，请重新选择项目。');
            $catalogItem = $authoritativeProjects[$projectId . ':' . $skuId] ?? null;
            if (!is_array($catalogItem)) throw new CashierV3CommandException(CashierV3ResultCode::RESOURCE_NOT_FOUND, '预约项目已下架、不可售或不属于当前门店，请重新选择。');
            $name = trim((string)($catalogItem['name'] ?? ''));
            $lineNo++;
            $quantity = max(1, (int)($project['quantity'] ?? 1));
            $key = 'reservation:' . $headerId . ':' . $lineNo;
            Db::name('cashier_v3_reservation_line')->insert([
                'tenant_id' => $tenant, 'reservation_id' => $headerId, 'service_order_line_id' => 0,
                'line_key' => $key, 'project_id' => $projectId, 'project_name_snapshot' => mb_substr($name, 0, 128),
                'project_source' => 'UNPAID', 'quantity' => $quantity, 'role_code' => $lineNo === 1 ? 'MAIN' : 'DETAIL',
                'service_duration_minutes' => 60, 'artisan_staff_ids_json' => json_encode($artisanStaffIds), 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $dispatcher->versionServices()->ensureRegistered(CashierV3ResourceScope::of(CashierV3ResourceScope::TYPE_STORE, (string)$operator->storeId()), 'reservation', (string)$headerId, $dataScope);
        $recorder = $scope['event_recorder'] ?? null; $execution = $scope['event_execution'] ?? null; if (!$recorder instanceof CashierV3BusinessEventRecorder || !$execution instanceof CashierV3BusinessEventExecution) throw new \LogicException('reservation_event_services_missing');
        $recorder->recordInTx($execution, (array)($scope['event_contract'] ?? []), ['event_type' => 'reservation.created', 'aggregate_type' => 'reservation', 'aggregate_id' => (string)$headerId, 'aggregate_version' => 1, 'source_type' => 'create-reservation', 'source_id' => (string)$headerId, 'member_id' => $memberId, 'aggregate_name_snapshot' => $reservationNo, 'store_name_snapshot' => (string)$store['name'], 'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now, 'business_date' => date('Y-m-d', $when), 'payload' => ['reservationNo' => $reservationNo, 'serviceOrderId' => $serviceOrderId, 'appointmentStartAt' => $when]]);
        $result = ['status' => 'succeeded', 'reservationId' => $headerId, 'reservationNo' => $reservationNo, 'serviceOrderId' => $serviceOrderId, 'serviceOrderNo' => $serviceNo, 'originalIdempotencyKey' => (string)($scope['idempotency_key'] ?? ''), 'message' => '预约已创建。']; Db::name('cashier_v3_reservation_operation')->insert(['tenant_id' => $tenant, 'command_idempotency_key' => (string)($scope['idempotency_key'] ?? ''), 'reservation_id' => $headerId, 'operation_type' => 'CREATE', 'version_before' => 0, 'version_after' => 1, 'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE), 'occurred_at' => $now, 'recorded_at' => $now]); return $result;
    }

    private static function craftsmen(CashierV3OperatorScope $operator): array { $rows = Db::name('system_store_staff')->where('store_id', $operator->storeId())->where('is_del', 0)->where('status', 1)->where('cashier_craftsman_enabled', 1)->field('id,staff_name')->order('id asc')->select(); $rows = is_object($rows) && method_exists($rows, 'toArray') ? $rows->toArray() : (array)$rows; return array_map(static function ($row) { return ['id' => (int)$row['id'], 'staffId' => (int)$row['id'], 'name' => (string)$row['staff_name'], 'selectable' => true]; }, $rows); }
    private static function positiveIds($values): array { $ids = []; foreach (is_array($values) ? $values : [] as $value) { $id = (int)$value; if ($id > 0) $ids[$id] = $id; } ksort($ids, SORT_NUMERIC); return array_values($ids); }
    private static function positive($value, string $message): int { $id = (int)$value; if ($id <= 0) throw new CashierV3CommandException(CashierV3ResultCode::INVALID_COMMAND_CONTEXT, $message); return $id; }
}
