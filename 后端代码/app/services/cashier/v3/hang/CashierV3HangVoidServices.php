<?php

namespace app\services\cashier\v3\hang;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\hang\authority\CashierV3HangOrderPlanV1;
use app\services\cashier\v3\hang\authority\ThinkPhpCashierV3HangOrderRepository;
use app\services\room\guard\RoomOpenServiceGuardAuthority;
use app\services\room\guard\RoomOpenServiceGuardException;
use app\services\room\guard\RoomOpenServiceGuardVersionProvider;
use think\facade\Db;

/**
 * Deletes a hang draft atomically. A service-start hang releases the room
 * guard it owns when that guard information is still complete.
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

        if ($this->hasReleasableRoomGuard($header)) {
            $roomId = (int)$header['room_id'];
            $slotId = (string)$header['room_time_slot_id'];
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
        $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
        $contexts = is_array($scope['contexts'] ?? null) ? $scope['contexts'] : [];
        if (!$operatorScope instanceof CashierV3OperatorScope
            || !$dataScope instanceof CashierV3DataScopeContext
        ) {
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
        if ($this->hasReleasableRoomGuard($header)) {
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

        Db::name(ThinkPhpCashierV3HangOrderRepository::LINE_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $operatorScope->storeId())
            ->where('hang_order_id', $hangOrderId)
            ->delete();
        $headerDeleted = (int)Db::name(ThinkPhpCashierV3HangOrderRepository::HEADER_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $operatorScope->storeId())
            ->where('hang_order_id', $hangOrderId)
            ->where('hang_version', $hangVersion)
            ->delete();
        if ($headerDeleted !== 1) {
            throw CashierV3CommandException::versionConflict(
                '该挂单已被其他操作删除，请刷新后重试。',
                ['reason' => 'hang_delete_header_cas_conflict']
            );
        }

        // The draft header is physically gone, so it cannot be version-bumped
        // by the command gateway. Advance the caller workspace revision only.
        $touched = ['cashier_workspace'];
        if ($roomRelease !== null) {
            $touched[] = 'room_time_slot';
        }
        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'hangOrderId' => $hangOrderId,
            'hangOrderNo' => (string)$header['hang_order_no'],
            'roomReleased' => $roomRelease !== null,
            'touched' => $touched,
            'message' => $roomRelease === null ? '挂单已删除。' : '挂单已删除，房间已释放。',
        ];
    }

    /**
     * Draft-only deletion endpoint. This intentionally bypasses the command
     * gateway because a draft has no business fact or version to advance.
     * The controller still supplies the authenticated tenant/store scope.
     */
    public function deleteDirectInTx(
        string $hangOrderId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('hangDraftDirectDelete');
        $this->assertScope($operatorScope, $dataScope);
        if (preg_match('/^HGO[0-9a-f]{40}$/D', $hangOrderId) !== 1) {
            throw CashierV3CommandException::invalidContext('挂单标识无效。');
        }
        $header = $this->loadActiveHeader($hangOrderId, $operatorScope, $dataScope, true);
        $roomReleased = false;
        if ($this->hasReleasableRoomGuard($header)) {
            $roomId = (int)$header['room_id'];
            $slotId = (string)$header['room_time_slot_id'];
            $slotVersion = $this->roomVersions->discoverVersion(
                RoomOpenServiceGuardVersionProvider::KIND_SLOT,
                $slotId,
                $operatorScope,
                $dataScope
            );
            $this->roomGuard->releaseInTx(
                $roomId,
                RoomOpenServiceGuardAuthority::OWNER_HANG_ORDER,
                $hangOrderId,
                $slotVersion,
                $operatorScope,
                $dataScope
            );
            $roomReleased = true;
        }
        Db::name(ThinkPhpCashierV3HangOrderRepository::LINE_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $operatorScope->storeId())
            ->where('hang_order_id', $hangOrderId)
            ->delete();
        Db::name(ThinkPhpCashierV3HangOrderRepository::HEADER_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('organization_id', $dataScope->organizationId())
            ->where('store_id', $operatorScope->storeId())
            ->where('hang_order_id', $hangOrderId)
            ->where('hang_version', (int)$header['hang_version'])
            ->delete();
        return [
            'hangOrderId' => $hangOrderId,
            'hangOrderNo' => (string)$header['hang_order_no'],
            'roomReleased' => $roomReleased,
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
        if ((int)($row['hang_version'] ?? 0) <= 0) {
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
            // The header is deleted, so it cannot be version-bumped after the
            // command. It remains a locked read dependency; the delete itself
            // is guarded by the scoped header/version CAS below.
            'accessMode' => 'read',
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

    /**
     * A draft may be old or partially populated, but deleting it must not be
     * blocked by that. Only attempt room cleanup when the guard identity is
     * complete enough to release safely.
     */
    private function hasReleasableRoomGuard(array $header): bool
    {
        if (!$this->isServiceInProgress($header)) {
            return false;
        }
        $roomId = (int)($header['room_id'] ?? 0);
        $slotId = (string)($header['room_time_slot_id'] ?? '');
        return $roomId > 0 && hash_equals(RoomOpenServiceGuardAuthority::slotKey($roomId), $slotId);
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
