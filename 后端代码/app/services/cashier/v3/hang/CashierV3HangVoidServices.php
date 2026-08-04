<?php

namespace app\services\cashier\v3\hang;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\event\CashierV3BusinessEventExecution;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\hang\authority\CashierV3HangOrderPlanV1;
use app\services\cashier\v3\hang\authority\ThinkPhpCashierV3HangOrderRepository;
use app\services\room\guard\RoomOpenServiceGuardAuthority;
use app\services\room\guard\RoomOpenServiceGuardException;
use app\services\room\guard\RoomOpenServiceGuardVersionProvider;
use think\facade\Db;

/**
 * Voids an active hang atomically. A service-start hang releases only the
 * room guard it owns; a changed room owner aborts the whole operation.
 */
final class CashierV3HangVoidServices
{
    public const CONTRACT_VERSION = 'cashier-v3-hang-void-v1';
    private const STATUS_VOIDED = 'voided';

    /** @var RoomOpenServiceGuardVersionProvider */
    private $roomVersions;

    /** @var RoomOpenServiceGuardAuthority */
    private $roomGuard;

    public function __construct(
        ?RoomOpenServiceGuardVersionProvider $roomVersions = null,
        ?RoomOpenServiceGuardAuthority $roomGuard = null
    ) {
        $this->roomVersions = $roomVersions ?: new RoomOpenServiceGuardVersionProvider();
        $this->roomGuard = $roomGuard ?: new RoomOpenServiceGuardAuthority();
    }

    /** @return array<string,mixed> */
    public function prepare(
        array $payload,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $this->assertScope($operatorScope, $dataScope);
        $header = $this->loadActiveHeader($this->hangOrderId($payload), $operatorScope, $dataScope, false);
        $serviceInProgress = $this->isServiceInProgress($header);

        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'hangOrderId' => (string)$header['hang_order_id'],
            'hangOrderNo' => (string)$header['hang_order_no'],
            'memberName' => trim((string)$header['member_name_snapshot']) ?: '游客',
            'status' => $serviceInProgress ? '服务中' : '待结账',
            'willReleaseRoom' => $serviceInProgress,
            'roomName' => $serviceInProgress ? (string)$header['room_name_snapshot'] : '',
            'message' => $serviceInProgress
                ? '作废后将结束本次挂单服务并释放房间，已发生的销售和会员权益不会被写入。'
                : '作废后本次挂单不会产生销售、收款或会员权益。',
        ];
    }

    /** Server-only resource discovery before and after the gateway locks. */
    public function discover(array $scope): array
    {
        $operatorScope = $scope['operator_scope'] ?? null;
        $dataScope = $scope['data_scope'] ?? null;
        $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
        if (!$operatorScope instanceof CashierV3OperatorScope || !$dataScope instanceof CashierV3DataScopeContext) {
            throw CashierV3CommandException::invalidContext(
                '挂单作废上下文不完整，请刷新列表后重试。',
                ['reason' => 'hang_void_discovery_scope_invalid']
            );
        }
        $this->assertScope($operatorScope, $dataScope);
        $header = $this->loadActiveHeader($this->hangOrderId($payload), $operatorScope, $dataScope, false);
        $resources = [$this->hangResource($header, $dataScope)];

        if ($this->isServiceInProgress($header)) {
            $roomId = (int)$header['room_id'];
            $slotId = (string)$header['room_time_slot_id'];
            if ($roomId <= 0 || !hash_equals(RoomOpenServiceGuardAuthority::slotKey($roomId), $slotId)) {
                throw $this->incomplete('hang_void_room_identity_invalid');
            }
            $slotVersion = $this->roomVersions->discoverVersion(
                RoomOpenServiceGuardVersionProvider::KIND_SLOT,
                $slotId,
                $operatorScope,
                $dataScope
            );
            $resources[] = $this->roomSlotResource($roomId, $slotId, $slotVersion, $dataScope);
        }

        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'resources' => $resources,
        ];
    }

    /** @return array<string,mixed> */
    public function voidInTx(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('hangOrderVoid');
        $operatorScope = $scope['operator_scope'] ?? null;
        $dataScope = $scope['data_scope'] ?? null;
        $eventRecorder = $scope['event_recorder'] ?? null;
        $eventExecution = $scope['event_execution'] ?? null;
        $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
        $contexts = is_array($scope['contexts'] ?? null) ? $scope['contexts'] : [];
        if (!$operatorScope instanceof CashierV3OperatorScope
            || !$dataScope instanceof CashierV3DataScopeContext
            || !$eventRecorder instanceof CashierV3BusinessEventRecorder
            || !$eventExecution instanceof CashierV3BusinessEventExecution) {
            throw $this->incomplete('hang_void_scope_incomplete');
        }
        $this->assertScope($operatorScope, $dataScope);
        $hangOrderId = $this->hangOrderId($payload);
        $header = $this->loadActiveHeader($hangOrderId, $operatorScope, $dataScope, true);
        $hangVersion = $this->contextVersion($contexts, 'hang_order', $hangOrderId);
        if ((int)$header['hang_version'] !== $hangVersion) {
            throw CashierV3CommandException::versionConflict(
                '该挂单已被其他操作更新，请刷新后重试。',
                ['reason' => 'hang_void_header_version_changed']
            );
        }

        $roomRelease = null;
        if ($this->isServiceInProgress($header)) {
            $roomId = (int)$header['room_id'];
            $slotId = (string)$header['room_time_slot_id'];
            $slotVersion = $this->contextVersion($contexts, 'room_time_slot', $slotId);
            try {
                $roomRelease = $this->roomGuard->releaseInTx(
                    $roomId,
                    RoomOpenServiceGuardAuthority::OWNER_HANG_ORDER,
                    $hangOrderId,
                    $slotVersion,
                    $operatorScope,
                    $dataScope
                );
            } catch (RoomOpenServiceGuardException $exception) {
                throw $this->roomFailure($exception);
            }
        }

        $now = time();
        $headerUpdated = (int)Db::name(ThinkPhpCashierV3HangOrderRepository::HEADER_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $operatorScope->storeId())
            ->where('hang_order_id', $hangOrderId)
            ->where('hang_version', $hangVersion)
            ->whereIn('hang_status', [
                CashierV3HangOrderPlanV1::STATUS_PENDING_CHECKOUT,
                CashierV3HangOrderPlanV1::STATUS_SERVICE_IN_PROGRESS,
            ])
            ->update([
                'hang_status' => self::STATUS_VOIDED,
                'update_time' => $now,
            ]);
        if ($headerUpdated !== 1) {
            throw CashierV3CommandException::versionConflict(
                '该挂单已被其他操作更新，请刷新后重试。',
                ['reason' => 'hang_void_header_cas_conflict']
            );
        }
        $lineUpdated = (int)Db::name(ThinkPhpCashierV3HangOrderRepository::LINE_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $operatorScope->storeId())
            ->where('hang_order_id', $hangOrderId)
            ->where('line_status', 'held')
            ->update([
                'line_status' => self::STATUS_VOIDED,
                'line_version' => Db::raw('line_version + 1'),
                'update_time' => $now,
            ]);
        if ($lineUpdated !== (int)$header['line_count']) {
            throw $this->incomplete('hang_void_line_cas_conflict');
        }

        $eventContract = is_array($scope['event_contract'] ?? null) ? $scope['event_contract'] : [];
        $businessDate = (new \DateTimeImmutable('@' . $now))
            ->setTimezone(new \DateTimeZone('Asia/Shanghai'))
            ->format('Y-m-d');
        $eventRecorder->recordInTx($eventExecution, $eventContract, [
            'event_type' => 'hang_order.voided',
            'aggregate_type' => 'hang_order',
            'aggregate_id' => $hangOrderId,
            'aggregate_version' => $hangVersion + 1,
            'source_type' => 'void-hang-order',
            'source_id' => $hangOrderId,
            'member_id' => (int)$header['member_id'],
            'business_date' => $businessDate,
            'occurred_at' => $now,
            'settled_at' => $now,
            'recorded_at' => $now,
            'aggregate_name_snapshot' => (string)$header['hang_order_no'],
            'store_name_snapshot' => (string)$header['store_name_snapshot'],
            'payload' => [
                'hangOrderId' => $hangOrderId,
                'hangOrderNo' => (string)$header['hang_order_no'],
                'previousStatus' => (string)$header['hang_status'],
                'lineCount' => (int)$header['line_count'],
            ],
        ]);
        if ($roomRelease !== null) {
            $eventRecorder->recordInTx($eventExecution, $eventContract, [
                'event_type' => 'room.released',
                'aggregate_type' => 'room',
                'aggregate_id' => (string)$header['room_id'],
                'aggregate_version' => 1,
                'source_type' => 'void-hang-order',
                'source_id' => $hangOrderId,
                'member_id' => (int)$header['member_id'],
                'business_date' => $businessDate,
                'occurred_at' => $now,
                'settled_at' => $now,
                'recorded_at' => $now,
                'aggregate_name_snapshot' => (string)$header['room_name_snapshot'],
                'store_name_snapshot' => (string)$header['store_name_snapshot'],
                'payload' => [
                    'roomId' => (int)$header['room_id'],
                    'roomTimeSlotId' => (string)$header['room_time_slot_id'],
                    'roomTimeSlotVersionBefore' => $slotVersion,
                    'roomTimeSlotVersionAfter' => (int)$roomRelease['slotVersion'],
                    'ownerKind' => RoomOpenServiceGuardAuthority::OWNER_HANG_ORDER,
                    'ownerId' => $hangOrderId,
                ],
            ]);
        }

        $touched = ['hang_order'];
        if ($roomRelease !== null) {
            $touched[] = 'room_time_slot';
        }
        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'hangOrderId' => $hangOrderId,
            'hangOrderNo' => (string)$header['hang_order_no'],
            'roomReleased' => $roomRelease !== null,
            'touched' => $touched,
            'message' => $roomRelease === null ? '挂单已作废。' : '挂单已作废，房间已释放。',
        ];
    }

    private function loadActiveHeader(
        string $hangOrderId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        bool $lock
    ): array {
        $query = Db::name(ThinkPhpCashierV3HangOrderRepository::HEADER_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('organization_id', $dataScope->organizationId())
            ->where('store_id', $operatorScope->storeId())
            ->where('hang_order_id', $hangOrderId)
            ->whereIn('hang_status', [
                CashierV3HangOrderPlanV1::STATUS_PENDING_CHECKOUT,
                CashierV3HangOrderPlanV1::STATUS_SERVICE_IN_PROGRESS,
            ])
            ->field([
                'hang_order_id', 'hang_order_no', 'hang_mode', 'hang_status', 'hang_version',
                'member_id', 'member_name_snapshot', 'line_count', 'room_id', 'room_name_snapshot',
                'room_time_slot_id', 'store_name_snapshot',
            ]);
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if (is_object($row) && method_exists($row, 'toArray')) {
            $row = $row->toArray();
        }
        if (!is_array($row)) {
            throw CashierV3ScopeResolver::notFound('hang_order', $hangOrderId);
        }
        if ((int)($row['hang_version'] ?? 0) <= 0 || (int)($row['line_count'] ?? 0) <= 0) {
            throw $this->incomplete('hang_void_header_invalid');
        }
        return $row;
    }

    private function hangResource(array $header, CashierV3DataScopeContext $dataScope): array
    {
        $hangOrderId = (string)$header['hang_order_id'];
        $version = (int)$header['hang_version'];
        return [
            'kind' => 'hang_order',
            'id' => $hangOrderId,
            'expectedVersion' => $version,
            'roles' => ['hang_order'],
            'accessMode' => 'mutate',
            'providerContractVersion' => CashierV3HangOrderVersionProvider::CONTRACT_VERSION,
            'authorityFingerprint' => hash('sha256', implode('|', [
                self::CONTRACT_VERSION,
                $dataScope->tenantId(),
                (string)$dataScope->forcedStoreId(),
                $hangOrderId,
                (string)$version,
                (string)$header['hang_status'],
            ])),
        ];
    }

    private function roomSlotResource(
        int $roomId,
        string $slotId,
        int $version,
        CashierV3DataScopeContext $dataScope
    ): array {
        return [
            'kind' => RoomOpenServiceGuardVersionProvider::KIND_SLOT,
            'id' => $slotId,
            'expectedVersion' => $version,
            'roles' => ['room_time_slot'],
            'accessMode' => 'mutate',
            'providerContractVersion' => $this->roomVersions->contractVersion(),
            'authorityFingerprint' => hash('sha256', implode('|', [
                self::CONTRACT_VERSION,
                $dataScope->tenantId(),
                (string)$dataScope->forcedStoreId(),
                (string)$roomId,
                $slotId,
                (string)$version,
            ])),
        ];
    }

    private function hangOrderId(array $payload): string
    {
        $id = trim((string)($payload['hangOrderId'] ?? $payload['hang_order_id'] ?? ''));
        if (preg_match('/^HGO[0-9a-f]{40}$/D', $id) !== 1) {
            throw CashierV3CommandException::invalidContext(
                '挂单标识无效，请刷新列表后重试。',
                ['reason' => 'hang_void_identity_invalid']
            );
        }
        return $id;
    }

    private function contextVersion(array $contexts, string $kind, string $id): int
    {
        foreach ($contexts as $context) {
            if ((string)($context['kind'] ?? '') !== $kind || (string)($context['id'] ?? '') !== $id) {
                continue;
            }
            $version = (int)($context['expected_version'] ?? $context['expectedVersion'] ?? 0);
            if ($version > 0) {
                return $version;
            }
        }
        throw CashierV3CommandException::invalidContext(
            '挂单作废版本缺失，请刷新列表后重试。',
            ['reason' => 'hang_void_context_version_missing', 'kind' => $kind, 'id' => $id]
        );
    }

    private function isServiceInProgress(array $header): bool
    {
        return (string)($header['hang_mode'] ?? '') === CashierV3HangOrderPlanV1::MODE_START_SERVICE
            && (string)($header['hang_status'] ?? '') === CashierV3HangOrderPlanV1::STATUS_SERVICE_IN_PROGRESS;
    }

    private function assertScope(
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): void {
        if ($operatorScope->tenantId() === ''
            || !hash_equals($operatorScope->tenantId(), $dataScope->tenantId())
            || !hash_equals($operatorScope->organizationId(), $dataScope->organizationId())
            || $operatorScope->storeId() !== $dataScope->forcedStoreId()
            || $operatorScope->operatorId() !== $dataScope->operatorId()
            || !$dataScope->allowsStore($operatorScope->storeId())) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::PERMISSION_DENIED,
                '当前账号无权作废该挂单。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'hang_void_data_scope_denied']
            );
        }
    }

    private function roomFailure(RoomOpenServiceGuardException $exception): CashierV3CommandException
    {
        return CashierV3CommandException::versionConflict(
            '房间状态已经变化，请刷新列表后重新操作。',
            array_merge(['reason' => $exception->reason()], $exception->detail())
        );
    }

    private function incomplete(string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            '挂单资料不完整，本次作废已取消，请刷新列表后重试。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason]
        );
    }
}
