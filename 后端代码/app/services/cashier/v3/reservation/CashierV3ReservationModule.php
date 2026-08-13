<?php

namespace app\services\cashier\v3\reservation;

use app\services\cashier\v3\CashierV3ActionDispatcher;
use app\services\cashier\v3\CashierV3BusinessDocumentNumberServices;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\cashier\CashierV3SaleCatalogServices;
use app\services\cashier\v3\event\CashierV3BusinessEventExecution;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\hang\CashierV3LegacyRoomReadProvider;
use app\services\cashier\v3\projection\CashierV3RootDomainAssembler;
use app\services\cashier\v3\registry\CashierV3ContextPolicy;
use app\services\product\product\StoreProductReservationServices;
use think\facade\Db;

/** V3 reservation authority. All state changes are gateway-owned transactions. */
final class CashierV3ReservationModule
{
    private const STATUS_UNSTARTED = 'UNSTARTED';
    private const STATUS_IN_SERVICE = 'IN_SERVICE';
    private const STATUS_COMPLETED = 'COMPLETED';
    private const STATUS_CANCELLED = 'CANCELLED';

    public static function install(CashierV3ActionDispatcher $dispatcher, CashierV3RootDomainAssembler $assembler): void
    {
        $catalog = new CashierV3SaleCatalogServices();
        $rooms = new CashierV3LegacyRoomReadProvider();
        $handlers = $dispatcher->handlers();

        if (!$handlers->hasProjection('open-reservation-editor')) {
            $handlers->registerProjection('open-reservation-editor', function (array $scope) use ($catalog, $rooms): array {
                $operator = $scope['operator_scope'];
                $dataScope = $scope['data_scope'];
                $payload = (array)($scope['payload'] ?? []);
                $mode = trim((string)($payload['mode'] ?? 'create'));
                $reservationId = (int)($payload['reservationId'] ?? 0);
                if (!in_array($mode, ['create', 'edit'], true) || ($mode === 'create' && $reservationId > 0)) {
                    throw new CashierV3CommandException(CashierV3ResultCode::INVALID_COMMAND_CONTEXT, '预约编辑请求无效。');
                }

                $contexts = [];
                $draft = ['member' => null, 'memberId' => null, 'projects' => [], 'craftsmen' => [], 'appointmentTime' => '', 'room' => null, 'roomId' => null, 'remark' => ''];
                $selectedMemberId = (int)($payload['memberId'] ?? ((array)($payload['reservation'] ?? []))['memberId'] ?? 0);
                if ($mode === 'edit') {
                    $header = self::findHeaderForRead($reservationId, $operator, $dataScope);
                    if (!self::isUnstarted((string)$header['status'])) {
                        throw new CashierV3CommandException(CashierV3ResultCode::INVALID_COMMAND_CONTEXT, '服务开始后不能编辑预约。');
                    }
                    $selectedMemberId = (int)$header['member_id'];
                    $options = self::catalogOptions($catalog, $operator, $dataScope, $selectedMemberId);
                    $draft = self::editorDraft($header, $options);
                    $contexts[] = ['kind' => 'reservation', 'id' => (string)$reservationId, 'expectedVersion' => (int)$header['version']];
                } else {
                    $options = self::catalogOptions($catalog, $operator, $dataScope, $selectedMemberId);
                    $stateContextId = (string)($scope['state_context_id'] ?? '');
                    $workspaceId = \app\services\cashier\v3\CashierV3CheckoutWorkspaceIdentity::id($operator->storeId(), $stateContextId);
                    $workspaceVersion = (int)Db::name('cashier_v3_resource_version')
                        ->where('scope_type', 'store')->where('scope_id', (string)$operator->storeId())
                        ->where('resource_kind', 'cashier_workspace')->where('resource_id', $workspaceId)
                        ->value('current_version');
                    if ($workspaceVersion <= 0) {
                        throw new CashierV3CommandException(CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY, '收银工作台尚未准备完成，请刷新后再创建预约。');
                    }
                    $contexts[] = ['kind' => 'cashier_workspace', 'id' => $workspaceId, 'expectedVersion' => $workspaceVersion];
                }

                return ['data' => ['editor' => [
                    'preparationReady' => true,
                    'preparationRequestId' => (string)($payload['preparationRequestId'] ?? ''),
                    'preparationToken' => bin2hex(random_bytes(16)),
                    'commandContexts' => $contexts,
                    'draft' => $draft,
                    'catalogOptions' => $options,
                    'craftsmenOptions' => self::craftsmen($operator),
                    'rooms' => $rooms->roomCandidates($operator, $dataScope),
                ]], 'message' => '预约编辑器已准备完成。'];
            });
        }
        if (!$handlers->hasProjection('query-reservations')) {
            $handlers->registerProjection('query-reservations', function (array $scope): array {
                $payload = (array)($scope['payload'] ?? []);
                $part = (new CashierV3ReservationPartitionProvider())->readPartition((string)($scope['state_context_id'] ?? ''), '', $scope['operator_scope'], $scope['data_scope'], [
                    'calendarDate' => (string)($payload['calendarDate'] ?? ''),
                    'quickFilter' => (string)($payload['quickFilter'] ?? ''),
                ]);
                return ['data' => ['reservation' => $part['payload']], 'versions' => $part['public_versions'], 'return_root_state' => true, 'root_hints' => ['calendarDate' => (string)($part['payload']['calendar']['date'] ?? '')], 'message' => '预约列表已刷新。'];
            });
        }
        if (!$handlers->hasProjection('query-reservation-project-catalog')) {
            $handlers->registerProjection('query-reservation-project-catalog', function (array $scope) use ($catalog): array {
                $payload = (array)($scope['payload'] ?? []);
                $memberId = self::positive($payload['memberId'] ?? 0, '请选择会员。');
                return ['data' => ['reservationProjectCatalog' => [
                    'memberId' => $memberId,
                    'catalogOptions' => self::catalogOptions(
                        $catalog,
                        $scope['operator_scope'],
                        $scope['data_scope'],
                        $memberId
                    ),
                ]], 'message' => '预约项目目录已更新。'];
            });
        }
        if (!$handlers->hasProjection('open-reservation-detail')) {
            $handlers->registerProjection('open-reservation-detail', function (array $scope): array {
                $detail = (new CashierV3ReservationDetailQueryServices())->read((array)($scope['payload'] ?? []), $scope['operator_scope'], $scope['data_scope']);
                if ($detail === null) throw new CashierV3CommandException(CashierV3ResultCode::RESOURCE_NOT_FOUND, '该预约不存在或资料不完整。');
                return ['data' => ['reservation' => ['detail' => $detail]], 'versions' => [['kind' => 'reservation', 'id' => (string)$detail['reservationId'], 'version' => (int)$detail['reservationVersion']]], 'message' => '预约详情已读取。'];
            });
        }
        if (!$handlers->hasProjection('change-reservation-calendar-date')) {
            $handlers->registerProjection('change-reservation-calendar-date', function (array $scope): array {
                $payload = (array)($scope['payload'] ?? []);
                $baseDate = trim((string)($payload['calendarDate'] ?? ''));
                $timezone = new \DateTimeZone('Asia/Shanghai');
                $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $baseDate, $timezone);
                if (!$parsed || $parsed->format('Y-m-d') !== $baseDate) $parsed = new \DateTimeImmutable('today', $timezone);
                $direction = max(-1, min(1, (int)($payload['direction'] ?? 0)));
                $date = $direction === 0 ? new \DateTimeImmutable('today', $timezone) : $parsed->modify(($direction > 0 ? '+' : '-') . '1 day');
                $part = (new CashierV3ReservationPartitionProvider())->readPartition((string)($scope['state_context_id'] ?? ''), '', $scope['operator_scope'], $scope['data_scope'], [
                    'calendarDate' => $date->format('Y-m-d'),
                    'quickFilter' => (string)($payload['quickFilter'] ?? ''),
                ]);
                return ['data' => ['reservation' => $part['payload']], 'versions' => $part['public_versions'], 'return_root_state' => true, 'root_hints' => ['calendarDate' => (string)($part['payload']['calendar']['date'] ?? '')], 'message' => '预约日历已更新。'];
            });
        }
        if (!$handlers->hasProjection('recalculate-reservation-plan')) {
            $handlers->registerProjection('recalculate-reservation-plan', function (array $scope) use ($catalog): array {
                $payload = (array)($scope['payload'] ?? []);
                $reservation = (array)($payload['reservation'] ?? []);
                $requestId = trim((string)($payload['recalculationRequestId'] ?? ''));
                $when = strtotime((string)($reservation['appointmentTime'] ?? $reservation['appointmentStartAt'] ?? ''));
                if ($requestId === '') throw new CashierV3CommandException(CashierV3ResultCode::INVALID_COMMAND_CONTEXT, '预约计算请求无效。');
                $when = $when === false ? time() : $when;
                [$plans, $duration] = self::projectPlans(self::projectInput($reservation));
                $reservation['projects'] = $plans;
                $reservation['appointmentTime'] = self::formatTime($when);
                $reservation['appointmentStartAt'] = $when;
                $reservation['appointmentEndAt'] = $when + $duration * 60;
                $reservation['scheduleCalculationReady'] = true;
                $reservation['scheduleRecalculationRequestId'] = $requestId;
                return ['data' => ['reservationPlan' => ['recalculationRequestId' => $requestId, 'draft' => $reservation, 'hasConflict' => false, 'conflicts' => []]], 'message' => '预约时长已计算。'];
            });
        }

        self::registerCommand($handlers, 'create-reservation', function (array $scope) use ($dispatcher, $catalog): array {
            $result = self::create($scope, $dispatcher, $catalog);
            return ['data' => ['reservationSubmission' => $result], 'business_no' => $result['reservationNo'], 'touched' => ['cashier_workspace'], 'message' => '预约已创建。'];
        });
        self::registerCommand($handlers, 'update-reservation', function (array $scope) use ($catalog): array {
            $result = self::update($scope, $catalog);
            return ['data' => ['reservationSubmission' => $result], 'business_no' => $result['reservationNo'], 'touched' => ['reservation'], 'message' => '预约已保存。'];
        });
        self::registerCommand($handlers, 'cancel-reservation', function (array $scope): array {
            $result = self::cancel($scope);
            return ['data' => ['reservationAction' => $result], 'business_no' => $result['reservationNo'], 'touched' => ['reservation'], 'message' => '预约已删除。'];
        });
        self::registerCommand($handlers, 'start-reservation-service', function (array $scope): array {
            $result = self::start($scope);
            return ['data' => ['reservationAction' => $result], 'business_no' => $result['reservationNo'], 'touched' => ['reservation'], 'message' => '服务已开始。'];
        });
        self::registerCommand($handlers, 'end-reservation-service', function (array $scope): array {
            $result = self::end($scope);
            return ['data' => ['reservationAction' => $result], 'business_no' => $result['reservationNo'], 'touched' => ['reservation'], 'message' => '服务已结束。'];
        });

        foreach (['create-reservation', 'update-reservation'] as $action) {
            if (!$dispatcher->policies()->has($action)) {
                // 普通预约资料保存不占用收银工作台；幂等键和领域事务内的
                // header 锁定/CAS 已覆盖重复提交与并发覆盖。
                $dispatcher->policies()->register(new CashierV3ContextPolicy($action, [], [], null, [], [], [], true));
            }
        }
        foreach (['cancel-reservation', 'start-reservation-service', 'end-reservation-service'] as $action) {
            if (!$dispatcher->policies()->has($action)) {
                $dispatcher->policies()->register(new CashierV3ContextPolicy($action, ['reservation'], [], null, ['reservation']));
            }
        }
        $assembler->registerPartitionProvider(new CashierV3ReservationPartitionProvider());
    }

    private static function registerCommand($handlers, string $action, callable $handler): void
    {
        if (!$handlers->hasCommand($action)) $handlers->registerCommand($action, $handler);
    }

    private static function create(array $scope, CashierV3ActionDispatcher $dispatcher, CashierV3SaleCatalogServices $catalog): array
    {
        $operator = $scope['operator_scope'];
        $dataScope = $scope['data_scope'];
        $payload = (array)($scope['payload'] ?? []);
        $reservation = (array)($payload['reservation'] ?? []);
        $memberId = self::positive($reservation['memberId'] ?? 0, '请选择会员。');
        $when = strtotime((string)($reservation['appointmentTime'] ?? $reservation['appointmentStartAt'] ?? ''));
        $when = $when === false ? time() : $when;
        $memberInput = (array)($reservation['member'] ?? []);
        $memberName = trim((string)($reservation['memberName'] ?? $memberInput['name'] ?? $memberInput['memberName'] ?? ''));
        $memberPhone = trim((string)($reservation['memberPhone'] ?? $memberInput['phone'] ?? $memberInput['mobile'] ?? ''));
        [$plans, $duration] = self::projectPlans(self::projectInput($reservation));
        $room = self::room($reservation['roomId'] ?? 0, $reservation['roomName'] ?? ($reservation['room']['name'] ?? $reservation['room']['roomName'] ?? ''));
        [$artisanStaffIds] = self::artisans($reservation['craftsmen'] ?? []);
        $now = time();
        $tenant = $dataScope->tenantId();
        $store = Db::name('system_store')->where('id', $operator->storeId())->field('name')->find() ?: [];
        $reservationIdentity = 'RSV-' . bin2hex(random_bytes(12));
        $reservationNo = (new CashierV3BusinessDocumentNumberServices())->allocateForSourceInTx($tenant, CashierV3BusinessDocumentNumberServices::RESERVATION, 'reservation', $reservationIdentity, date('Y-m-d', $when), $now);
        $header = [
            'reservation_id' => $reservationIdentity, 'reservation_no' => $reservationNo, 'tenant_id' => $tenant,
            'organization_id' => $operator->organizationId(), 'organization_path' => '', 'organization_name_snapshot' => '',
            'store_id' => $operator->storeId(), 'store_name_snapshot' => (string)($store['name'] ?? ''),
            'member_id' => $memberId, 'member_name_snapshot' => $memberName, 'member_phone_snapshot' => $memberPhone,
            'service_order_id' => 0, 'service_order_no_snapshot' => '', 'room_id' => $room['id'], 'room_name_snapshot' => $room['name'],
            'appointment_start_at' => $when, 'appointment_end_at' => $when + $duration * 60, 'status' => self::STATUS_UNSTARTED, 'version' => 1,
            'remark_snapshot' => mb_substr(trim((string)($reservation['remark'] ?? '')), 0, 500),
            'creator_staff_id' => $operator->operatorId(), 'creator_employee_id' => $dataScope->employeeId(), 'creator_name_snapshot' => (string)($dataScope->operatorProfile()['account'] ?? ''),
            'business_date' => date('Y-m-d', $when), 'occurred_at' => $now, 'recorded_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ];
        $headerId = (int)Db::name('cashier_v3_reservation')->insertGetId($header);
        if ($headerId <= 0) throw new \RuntimeException('reservation_insert_failed');
        self::replaceLines($headerId, $tenant, $plans, $artisanStaffIds, $now);
        self::record($scope, 'reservation.created', 'reservation', (string)$headerId, 1, $header, $now, date('Y-m-d', $when), ['reservationNo' => $reservationNo, 'appointmentStartAt' => $when, 'appointmentEndAt' => $when + $duration * 60]);
        $result = ['status' => 'succeeded', 'reservationId' => $headerId, 'reservationNo' => $reservationNo, 'originalIdempotencyKey' => (string)($scope['idempotency_key'] ?? ''), 'message' => '预约已创建。'];
        self::operation($tenant, $headerId, (string)($scope['idempotency_key'] ?? ''), 'CREATE', 0, 1, $result, $now);
        return $result;
    }

    private static function update(array $scope, CashierV3SaleCatalogServices $catalog): array
    {
        $operator = $scope['operator_scope']; $dataScope = $scope['data_scope'];
        $header = self::reservationHeader($scope);
        if (!self::isUnstarted((string)$header['status'])) throw new CashierV3CommandException(CashierV3ResultCode::INVALID_COMMAND_CONTEXT, '服务开始后不能编辑预约。');
        $payload = (array)($scope['payload'] ?? []);
        $reservation = (array)($payload['reservation'] ?? []);
        $memberId = (int)$header['member_id'];
        $when = strtotime((string)($reservation['appointmentTime'] ?? $reservation['appointmentStartAt'] ?? ''));
        $when = $when === false ? time() : $when;
        [$plans, $duration] = self::projectPlans(self::projectInput($reservation));
        $room = self::room($reservation['roomId'] ?? 0, $reservation['roomName'] ?? ($reservation['room']['name'] ?? $reservation['room']['roomName'] ?? ''));
        [$artisanStaffIds] = self::artisans($reservation['craftsmen'] ?? []);
        $now = time(); $nextVersion = (int)$header['version'] + 1;
        self::advanceHeader($header, $dataScope, $nextVersion, [
            'room_id' => $room['id'], 'room_name_snapshot' => $room['name'], 'appointment_start_at' => $when, 'appointment_end_at' => $when + $duration * 60,
            'remark_snapshot' => mb_substr(trim((string)($reservation['remark'] ?? '')), 0, 500), 'business_date' => date('Y-m-d', $when), 'updated_at' => $now,
        ]);
        $beforeLines = self::lineSnapshot((int)$header['id'], $dataScope->tenantId());
        self::replaceLines((int)$header['id'], $dataScope->tenantId(), $plans, $artisanStaffIds, $now);
        $eventHeader = array_merge($header, ['appointment_start_at' => $when, 'appointment_end_at' => $when + $duration * 60]);
        self::record($scope, 'reservation.updated', 'reservation', (string)$header['id'], $nextVersion, $eventHeader, $now, date('Y-m-d', $when), ['reservationNo' => (string)$header['reservation_no'], 'appointmentStartAt' => $when, 'appointmentEndAt' => $when + $duration * 60, 'lineCount' => count($plans), 'beforeLines' => $beforeLines, 'afterLines' => self::planSnapshot($plans, $artisanStaffIds)]);
        $result = ['status' => 'succeeded', 'reservationId' => (int)$header['id'], 'reservationNo' => (string)$header['reservation_no'], 'version' => $nextVersion, 'originalIdempotencyKey' => (string)($scope['idempotency_key'] ?? '')];
        self::operation($dataScope->tenantId(), (int)$header['id'], (string)($scope['idempotency_key'] ?? ''), 'UPDATE', (int)$header['version'], $nextVersion, $result, $now);
        return $result;
    }

    private static function cancel(array $scope): array
    {
        $header = self::reservationHeader($scope); $dataScope = $scope['data_scope']; $now = time();
        if (!self::isUnstarted((string)$header['status'])) throw new CashierV3CommandException(CashierV3ResultCode::INVALID_COMMAND_CONTEXT, '开始服务后不能删除预约。');
        $nextVersion = (int)$header['version'] + 1;
        self::advanceHeader($header, $dataScope, $nextVersion, ['status' => self::STATUS_CANCELLED, 'updated_at' => $now]);
        self::record($scope, 'reservation.cancelled', 'reservation', (string)$header['id'], $nextVersion, $header, $now, date('Y-m-d', $now), ['reservationNo' => (string)$header['reservation_no'], 'logicalCancel' => true]);
        $result = ['status' => 'succeeded', 'reservationId' => (int)$header['id'], 'reservationNo' => (string)$header['reservation_no'], 'version' => $nextVersion, 'originalIdempotencyKey' => (string)($scope['idempotency_key'] ?? '')];
        self::operation($dataScope->tenantId(), (int)$header['id'], (string)($scope['idempotency_key'] ?? ''), 'CANCEL', (int)$header['version'], $nextVersion, $result, $now);
        return $result;
    }

    private static function start(array $scope): array
    {
        $header = self::reservationHeader($scope); $dataScope = $scope['data_scope']; $now = time();
        if (!self::isUnstarted((string)$header['status'])) throw new CashierV3CommandException(CashierV3ResultCode::INVALID_COMMAND_CONTEXT, '当前预约不能开始服务。');
        $nextVersion = (int)$header['version'] + 1;
        self::advanceHeader($header, $dataScope, $nextVersion, ['status' => self::STATUS_IN_SERVICE, 'updated_at' => $now]);
        self::record($scope, 'reservation.service_started', 'reservation', (string)$header['id'], $nextVersion, $header, $now, date('Y-m-d', $now), ['reservationNo' => (string)$header['reservation_no'], 'actualStartAt' => $now]);
        $result = ['status' => 'succeeded', 'reservationId' => (int)$header['id'], 'reservationNo' => (string)$header['reservation_no'], 'version' => $nextVersion, 'originalIdempotencyKey' => (string)($scope['idempotency_key'] ?? '')];
        self::operation($dataScope->tenantId(), (int)$header['id'], (string)($scope['idempotency_key'] ?? ''), 'START_SERVICE', (int)$header['version'], $nextVersion, $result, $now);
        return $result;
    }

    private static function end(array $scope): array
    {
        $header = self::reservationHeader($scope); $dataScope = $scope['data_scope']; $now = time();
        if ((string)$header['status'] !== self::STATUS_IN_SERVICE) throw new CashierV3CommandException(CashierV3ResultCode::INVALID_COMMAND_CONTEXT, '当前预约不能结束服务。');
        $nextVersion = (int)$header['version'] + 1;
        self::advanceHeader($header, $dataScope, $nextVersion, ['status' => self::STATUS_COMPLETED, 'updated_at' => $now]);
        self::record($scope, 'reservation.completed', 'reservation', (string)$header['id'], $nextVersion, $header, $now, date('Y-m-d', $now), ['reservationNo' => (string)$header['reservation_no'], 'actualEndAt' => $now, 'endedEarly' => $now < (int)$header['appointment_end_at']]);
        $result = ['status' => 'succeeded', 'reservationId' => (int)$header['id'], 'reservationNo' => (string)$header['reservation_no'], 'version' => $nextVersion, 'actualEndAt' => $now, 'originalIdempotencyKey' => (string)($scope['idempotency_key'] ?? '')];
        self::operation($dataScope->tenantId(), (int)$header['id'], (string)($scope['idempotency_key'] ?? ''), 'END_SERVICE', (int)$header['version'], $nextVersion, $result, $now);
        return $result;
    }

    private static function reservationHeader(array $scope): array
    {
        $payload = (array)($scope['payload'] ?? []);
        $reservation = (array)($payload['reservation'] ?? []);
        $reservationId = self::positive($payload['reservationId'] ?? $reservation['reservationId'] ?? $reservation['id'] ?? 0, '预约标识无效。');
        $dataScope = $scope['data_scope'];
        // 预约模块只按预约单自身读取。这里不叠加门店、工作台、资源版本或行锁。
        $row = Db::name('cashier_v3_reservation')->where('id', $reservationId)->where('tenant_id', $dataScope->tenantId())->find();
        if (!$row) throw new CashierV3CommandException(CashierV3ResultCode::RESOURCE_NOT_FOUND, '预约不存在。');
        return $row;
    }

    /** 预约资料直接覆盖保存；不使用资源版本或 CAS 锁。 */
    private static function advanceHeader(array $header, CashierV3DataScopeContext $dataScope, int $nextVersion, array $changes): void
    {
        $before = (int)($header['version'] ?? 0);
        if ($before <= 0 || $nextVersion !== $before + 1) throw new \LogicException('reservation_version_transition_invalid');
        $affected = Db::name('cashier_v3_reservation')
            ->where('id', (int)$header['id'])
            ->where('tenant_id', $dataScope->tenantId())
            ->update(array_merge($changes, ['version' => $nextVersion]));
        if ((int)$affected !== 1) throw new \RuntimeException('reservation_update_failed');
    }

    private static function findHeaderForRead(int $reservationId, CashierV3OperatorScope $operator, CashierV3DataScopeContext $dataScope): array
    {
        $row = Db::name('cashier_v3_reservation')->where('id', $reservationId)->where('tenant_id', $dataScope->tenantId())->where('store_id', $operator->storeId())->find();
        if (!$row) throw new CashierV3CommandException(CashierV3ResultCode::RESOURCE_NOT_FOUND, '预约不存在。');
        return $row;
    }

    private static function editorDraft(array $header, array $options): array
    {
        $lines = self::rows(Db::name('cashier_v3_reservation_line')->where('tenant_id', (string)$header['tenant_id'])->where('reservation_id', (int)$header['id'])->order('id asc')->select());
        $staffIds = [];
        foreach ($lines as $line) foreach (self::positiveIds(json_decode((string)($line['artisan_staff_ids_json'] ?? '[]'), true)) as $id) $staffIds[$id] = $id;
        $staffNames = $staffIds ? Db::name('system_store_staff')->whereIn('id', array_values($staffIds))->column('staff_name', 'id') : [];
        $projects = [];
        foreach ($lines as $line) {
            $projectId = (int)$line['project_id']; $skuId = (int)($line['sku_id'] ?? 0);
            $source = (string)($line['project_source'] ?? 'UNPAID') === 'ENTITLEMENT' ? 'card' : 'unpaid';
            if ($source === 'unpaid' && $skuId <= 0) $skuId = self::onlyCatalogSku($projectId, $options);
            $projects[] = ['id' => $projectId, 'projectId' => $projectId, 'skuId' => $skuId, 'name' => (string)$line['project_name_snapshot'], 'source' => $source, 'entitlementSourceDetailId' => $source === 'card' ? (int)($line['entitlement_source_detail_id'] ?? 0) : 0, 'quantity' => max(1, (int)$line['quantity']), 'appliedDurationMinutes' => (int)$line['service_duration_minutes']];
        }
        $craftsmen = [];
        foreach ($staffIds as $id) $craftsmen[] = ['id' => $id, 'staffId' => $id, 'name' => (string)($staffNames[$id] ?? '')];
        return ['id' => (int)$header['id'], 'reservationId' => (int)$header['id'], 'revision' => (int)$header['version'], 'recordVersion' => (int)$header['version'], 'reservationVersion' => (int)$header['version'], 'memberId' => (int)$header['member_id'], 'member' => ['id' => (int)$header['member_id'], 'name' => (string)$header['member_name_snapshot'], 'phone' => (string)$header['member_phone_snapshot']], 'projects' => $projects, 'craftsmen' => $craftsmen, 'appointmentTime' => self::formatTime((int)$header['appointment_start_at']), 'appointmentStartAt' => (int)$header['appointment_start_at'], 'appointmentEndAt' => (int)$header['appointment_end_at'], 'roomId' => (int)$header['room_id'], 'room' => (int)$header['room_id'] > 0 ? ['id' => (int)$header['room_id'], 'name' => (string)$header['room_name_snapshot']] : null, 'remark' => (string)$header['remark_snapshot']];
    }

    private static function catalogOptions(CashierV3SaleCatalogServices $catalog, CashierV3OperatorScope $operator, CashierV3DataScopeContext $dataScope, int $memberId = 0): array
    {
        $options = [];
        foreach ((array)($catalog->catalog($operator, $dataScope)['items'] ?? []) as $item) {
            if ((int)($item['productType'] ?? -1) !== 6 || !empty($item['disabled'])) continue;
            $categoryNames = array_values(array_filter(array_map('strval', (array)($item['categoryNames'] ?? [])), static function (string $name): bool {
                return trim($name) !== '';
            }));
            $options[] = [
                'id' => (int)$item['productId'],
                'projectId' => (int)$item['productId'],
                'skuId' => (int)$item['skuId'],
                'name' => (string)$item['name'],
                'categoryName' => trim((string)($item['categoryName'] ?? '')) ?: ($categoryNames[0] ?? '全部'),
                'categoryNames' => $categoryNames,
                'source' => 'unpaid',
                'selectable' => true,
                'productVersion' => (int)$item['productVersion'],
                'skuVersion' => (int)$item['skuVersion'],
            ];
        }
        return $memberId > 0
            ? array_merge(self::purchasedProjectOptions($memberId, $options, $operator, $dataScope), $options)
            : $options;
    }

    /**
     * A reservation only records where a future service will draw from. It
     * does not reserve or deduct a benefit, so this is a fresh eligibility
     * projection and every submit/recalculate path rebuilds it again.
     */
    private static function purchasedProjectOptions(int $memberId, array $unpaidOptions, CashierV3OperatorScope $operator, CashierV3DataScopeContext $dataScope): array
    {
        $now = time();
        $holders = self::rows(Db::name('user_card_holder')
            ->where('uid', $memberId)->where('store_id', $operator->storeId())
            ->where('is_del', 0)->where('write_surplus_times', '>', 0)
            ->field('id,oid,write_start,write_end')->select());
        if (!$holders) return [];
        $holderByOrder = [];
        foreach ($holders as $holder) {
            $holderId = (int)($holder['id'] ?? 0);
            $orderId = (int)($holder['oid'] ?? 0);
            $start = (int)($holder['write_start'] ?? 0);
            $end = (int)($holder['write_end'] ?? 0);
            if ($holderId > 0 && $orderId > 0 && ($start <= 0 || $start <= $now) && ($end <= 0 || $end >= $now)) $holderByOrder[$orderId] = $holderId;
        }
        if (!$holderByOrder) return [];
        $orders = self::rows(Db::name('store_order')
            ->whereIn('id', array_keys($holderByOrder))->where('paid', 1)
            ->where('is_del', 0)->where('is_system_del', 0)->where('is_user_del', 0)
            ->where('refund_status', 0)->where('terminal_action', 0)->where('card_upgrade_use_oid', 0)
            ->where('store_id', $operator->storeId())->field('id')->select());
        $orderIds = array_values(array_unique(array_map('intval', array_column($orders, 'id'))));
        if (!$orderIds) return [];
        $carts = self::rows(Db::name('store_order_cart_info')
            ->whereIn('oid', $orderIds)->where('cart_type', 2)->where('product_type', 6)
            ->where('is_writeoff', 0)->where('write_surplus_times', '>', 0)
            ->field('id,oid,product_id,cart_info,write_surplus_times,write_start,write_end')->order('id asc')->select());
        if (!$carts) return [];
        $disabledHolders = self::disabledCardHolders(array_values($holderByOrder), $memberId, $dataScope->tenantId());
        $catalogByProject = [];
        foreach ($unpaidOptions as $option) {
            $projectId = (int)($option['projectId'] ?? 0);
            if ($projectId > 0 && !isset($catalogByProject[$projectId])) $catalogByProject[$projectId] = $option;
        }
        $options = [];
        foreach ($carts as $cart) {
            $orderId = (int)($cart['oid'] ?? 0);
            $holderId = (int)($holderByOrder[$orderId] ?? 0);
            $detailId = (int)($cart['id'] ?? 0);
            $projectId = (int)($cart['product_id'] ?? 0);
            $start = (int)($cart['write_start'] ?? 0);
            $end = (int)($cart['write_end'] ?? 0);
            if ($holderId <= 0 || $detailId <= 0 || $projectId <= 0 || isset($disabledHolders[$holderId])
                || ($start > 0 && $start > $now) || ($end > 0 && $end < $now)) continue;
            $catalog = (array)($catalogByProject[$projectId] ?? []);
            $info = json_decode((string)($cart['cart_info'] ?? ''), true);
            $info = is_array($info) ? $info : [];
            $name = trim((string)($info['productInfo']['store_name'] ?? $catalog['name'] ?? ''));
            if ($name === '') $name = '项目';
            $options[] = [
                'id' => $projectId,
                'projectId' => $projectId,
                'skuId' => 0,
                'name' => $name,
                'categoryName' => (string)($catalog['categoryName'] ?? '全部'),
                'categoryNames' => (array)($catalog['categoryNames'] ?? []),
                'source' => 'card',
                'entitlementSourceDetailId' => $detailId,
                'remainingTimes' => (int)$cart['write_surplus_times'],
                'availableTimes' => (int)$cart['write_surplus_times'],
                'selectable' => true,
                'productVersion' => (int)($catalog['productVersion'] ?? 0),
                'skuVersion' => 0,
            ];
        }
        return $options;
    }

    /** V3 card state is optional during historical migration; disabled cards are never selectable. */
    private static function disabledCardHolders(array $holderIds, int $memberId, string $tenantId): array
    {
        if (!$holderIds) return [];
        try {
            $ids = Db::name('cashier_v3_card_state')->where('tenant_id', $tenantId)->whereIn('card_holder_id', $holderIds)
                ->where('current_member_id', $memberId)->where('card_status', '<>', 'enabled')->column('card_holder_id');
            return array_fill_keys(array_map('intval', $ids), true);
        } catch (\Throwable $exception) {
            $message = strtolower($exception->getMessage());
            if (strpos($message, 'cashier_v3_card_state') !== false && (strpos($message, "doesn't exist") !== false || strpos($message, 'not found') !== false)) return [];
            throw $exception;
        }
    }

    private static function projectInput(array $reservation): array
    {
        $projects = is_array($reservation['projects'] ?? null) ? $reservation['projects'] : (array)($reservation['projectLines'] ?? []);
        if (!$projects) throw new CashierV3CommandException(CashierV3ResultCode::INVALID_COMMAND_CONTEXT, '请至少选择一个项目。');
        return $projects;
    }

    /** @return array{0:array<int,array>,1:int} */
    private static function projectPlans(array $projects): array
    {
        $plans = []; $total = 0;
        foreach ($projects as $project) {
            $projectId = self::positive($project['projectId'] ?? $project['id'] ?? 0, '预约项目无效。');
            $source = strtolower(trim((string)($project['source'] ?? 'unpaid')));
            if ($source !== 'card') $source = 'unpaid';
            $skuId = max(0, (int)($project['skuId'] ?? 0));
            $detailId = $source === 'card'
                ? self::positive($project['entitlementSourceDetailId'] ?? $project['sourceDetailId'] ?? 0, '已购项目缺少权益明细。')
                : 0;
            $quantity = max(1, min(1000, (int)($project['quantity'] ?? 1)));
            $duration = max(1, (int)($project['appliedDurationMinutes'] ?? $project['projectServiceDuration'] ?? $project['duration'] ?? 60));
            $total += $duration * $quantity;
            $name = trim((string)($project['name'] ?? $project['projectName'] ?? ''));
            $plans[] = ['projectId' => $projectId, 'skuId' => $skuId, 'name' => $name === '' ? '项目 ' . $projectId : $name, 'source' => $source, 'entitlementSourceDetailId' => $detailId, 'quantity' => $quantity, 'duration' => $duration];
        }
        return [$plans, max(1, $total)];
    }

    private static function projectDuration(int $projectId, int $storeId): int
    {
        try {
            $service = app()->make(StoreProductReservationServices::class);
            [, $product] = $service->getProductInfo($projectId, $storeId);
            return max(1, (int)($product['project_service_duration'] ?? StoreProductReservationServices::DEFAULT_PROJECT_SERVICE_DURATION));
        } catch (\Throwable $exception) {
            throw new CashierV3CommandException(CashierV3ResultCode::RESOURCE_NOT_FOUND, '预约项目的服务时长配置无效，请重新选择。', CashierV3ResultCode::STATUS_FAILED, ['projectId' => $projectId]);
        }
    }

    private static function room($value, $name = ''): array
    {
        $roomId = max(0, (int)$value);
        if ($roomId <= 0) return ['id' => 0, 'name' => ''];
        $name = trim((string)$name);
        return ['id' => $roomId, 'name' => $name === '' ? '房间 ' . $roomId : $name];
    }

    /** @return array{0:array<int,int>,1:array<int,int>} */
    private static function artisans($values): array
    {
        $staffIds = self::positiveIds($values);
        $employeeIds = [];
        foreach (is_array($values) ? $values : [] as $value) {
            if (!is_array($value)) continue;
            $employeeId = (int)($value['employeeId'] ?? $value['employee_id'] ?? 0);
            if ($employeeId > 0) $employeeIds[$employeeId] = $employeeId;
        }
        return [$staffIds, $employeeIds];
    }

    private static function replaceLines(int $reservationId, string $tenantId, array $plans, array $artisanStaffIds, int $now): void
    {
        Db::name('cashier_v3_reservation_line')->where('tenant_id', $tenantId)->where('reservation_id', $reservationId)->delete();
        foreach ($plans as $index => $plan) {
            $isEntitlement = (string)($plan['source'] ?? '') === 'card';
            Db::name('cashier_v3_reservation_line')->insert(['tenant_id' => $tenantId, 'reservation_id' => $reservationId, 'service_order_line_id' => 0, 'line_key' => 'reservation:' . $reservationId . ':' . ($index + 1), 'project_id' => (int)$plan['projectId'], 'sku_id' => (int)$plan['skuId'], 'project_name_snapshot' => mb_substr((string)$plan['name'], 0, 128), 'project_source' => $isEntitlement ? 'ENTITLEMENT' : 'UNPAID', 'entitlement_source_detail_id' => $isEntitlement ? (int)$plan['entitlementSourceDetailId'] : 0, 'quantity' => (int)$plan['quantity'], 'role_code' => $index === 0 ? 'MAIN' : 'DETAIL', 'service_duration_minutes' => (int)$plan['duration'], 'artisan_staff_ids_json' => self::json($artisanStaffIds), 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    private static function lineSnapshot(int $reservationId, string $tenantId): array
    {
        $rows = self::rows(Db::name('cashier_v3_reservation_line')->where('tenant_id', $tenantId)->where('reservation_id', $reservationId)->order('id asc')->select());
        return array_map(static function (array $line): array {
            return [
                'projectId' => (int)($line['project_id'] ?? 0),
                'skuId' => (int)($line['sku_id'] ?? 0),
                'source' => (string)($line['project_source'] ?? 'UNPAID') === 'ENTITLEMENT' ? 'card' : 'unpaid',
                'entitlementSourceDetailId' => (int)($line['entitlement_source_detail_id'] ?? 0),
                'name' => (string)($line['project_name_snapshot'] ?? ''),
                'quantity' => (int)($line['quantity'] ?? 0),
                'duration' => (int)($line['service_duration_minutes'] ?? 0),
                'craftsmen' => self::positiveIds(json_decode((string)($line['artisan_staff_ids_json'] ?? '[]'), true)),
            ];
        }, $rows);
    }

    private static function planSnapshot(array $plans, array $artisanStaffIds): array
    {
        return array_map(static function (array $plan) use ($artisanStaffIds): array {
            return [
                'projectId' => (int)$plan['projectId'],
                'skuId' => (int)$plan['skuId'],
                'source' => (string)($plan['source'] ?? 'unpaid'),
                'entitlementSourceDetailId' => (int)($plan['entitlementSourceDetailId'] ?? 0),
                'name' => (string)$plan['name'],
                'quantity' => (int)$plan['quantity'],
                'duration' => (int)$plan['duration'],
                'craftsmen' => array_values($artisanStaffIds),
            ];
        }, $plans);
    }

    private static function record(array $scope, string $type, string $aggregateType, string $aggregateId, int $aggregateVersion, array $header, int $at, string $businessDate, array $payload): void
    {
        $recorder = $scope['event_recorder'] ?? null; $execution = $scope['event_execution'] ?? null;
        if (!$recorder instanceof CashierV3BusinessEventRecorder || !$execution instanceof CashierV3BusinessEventExecution) throw new \LogicException('reservation_event_services_missing');
        $recorder->recordInTx($execution, (array)($scope['event_contract'] ?? []), ['event_type' => $type, 'aggregate_type' => $aggregateType, 'aggregate_id' => $aggregateId, 'aggregate_version' => $aggregateVersion, 'source_type' => (string)$scope['action'], 'source_id' => $aggregateType === 'room' ? $aggregateId : (string)($header['id'] ?? $aggregateId), 'member_id' => (int)($header['member_id'] ?? 0), 'aggregate_name_snapshot' => $aggregateType === 'room' ? (string)($header['room_name_snapshot'] ?? '') : (string)($header['reservation_no'] ?? ''), 'store_name_snapshot' => (string)($header['store_name_snapshot'] ?? ''), 'occurred_at' => $at, 'settled_at' => $at, 'recorded_at' => $at, 'business_date' => $businessDate, 'payload' => $payload]);
    }

    private static function operation(string $tenantId, int $reservationId, string $key, string $type, int $before, int $after, array $result, int $now): void
    {
        Db::name('cashier_v3_reservation_operation')->insert(['tenant_id' => $tenantId, 'command_idempotency_key' => $key, 'reservation_id' => $reservationId, 'operation_type' => $type, 'version_before' => $before, 'version_after' => $after, 'result_json' => self::json($result), 'occurred_at' => $now, 'recorded_at' => $now]);
    }

    private static function craftsmen(CashierV3OperatorScope $operator): array
    {
        return array_map(static function (array $row): array { return ['id' => (int)$row['id'], 'staffId' => (int)$row['id'], 'name' => (string)$row['staff_name'], 'selectable' => true]; }, self::rows(Db::name('system_store_staff')->where('store_id', $operator->storeId())->where('is_del', 0)->where('status', 1)->field('id,staff_name')->order('id asc')->select()));
    }

    private static function onlyCatalogSku(int $projectId, array $options): int
    {
        $ids = [];
        foreach ($options as $option) if ((int)($option['projectId'] ?? 0) === $projectId) $ids[(int)$option['skuId']] = (int)$option['skuId'];
        return count($ids) === 1 ? (int)reset($ids) : 0;
    }

    // 本期只管理新生命周期写入的 UNSTARTED 预约。旧待确认／已确认数据
    // 不迁移、不映射，也不能通过新入口推进。
    private static function isUnstarted(string $status): bool { return $status === self::STATUS_UNSTARTED; }
    private static function formatTime(int $timestamp): string { return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i'); }
    private static function positiveIds($values): array { $ids = []; foreach (is_array($values) ? $values : [] as $value) { $id = is_array($value) ? (int)($value['id'] ?? $value['staffId'] ?? 0) : (int)$value; if ($id > 0) $ids[$id] = $id; } ksort($ids, SORT_NUMERIC); return array_values($ids); }
    private static function positive($value, string $message): int { $id = (int)$value; if ($id <= 0) throw new CashierV3CommandException(CashierV3ResultCode::INVALID_COMMAND_CONTEXT, $message); return $id; }
    private static function rows($rows): array { return is_object($rows) && method_exists($rows, 'toArray') ? $rows->toArray() : (array)$rows; }
    private static function json(array $value): string { $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); if ($json === false) throw new \RuntimeException('reservation_json_encode_failed'); return $json; }
}
