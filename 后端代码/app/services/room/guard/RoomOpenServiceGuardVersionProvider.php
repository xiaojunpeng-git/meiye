<?php

namespace app\services\room\guard;

use app\services\cashier\v3\CashierV3DataScopedVersionProvider;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResourceScope;
use app\services\cashier\v3\CashierV3TransactionGuard;

/** Gateway boundary for room and its singleton open-service slot. */
final class RoomOpenServiceGuardVersionProvider implements CashierV3DataScopedVersionProvider
{
    public const CONTRACT_VERSION = 'room-open-service-guard-version-provider-v1';
    public const KIND_ROOM = 'room';
    public const KIND_SLOT = 'room_time_slot';
    public const KINDS = [self::KIND_ROOM, self::KIND_SLOT];

    /** @var RoomOpenServiceGuardRepository */
    private $repository;

    public function __construct(?RoomOpenServiceGuardRepository $repository = null)
    {
        $this->repository = $repository ?: new ThinkPhpRoomOpenServiceGuardRepository();
    }

    public function contractVersion(): string
    {
        return self::CONTRACT_VERSION;
    }

    /** Read-only projection; a missing slot guard deterministically starts at version 1. */
    public function discoverVersion(
        string $kind,
        string $resourceId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): int {
        $roomId = $this->roomId($kind, $resourceId);
        $this->assertBaseScope($operatorScope, $dataScope);
        $room = $this->repository->findActiveRoom($operatorScope->storeId(), $roomId, false);
        if ($room === null) {
            throw self::failure('room_guard_resource_not_found');
        }
        if ($kind === self::KIND_ROOM) {
            return RoomOpenServiceGuardAuthority::roomVersion($room);
        }
        $guard = $this->repository->findGuard(
            $dataScope->tenantId(),
            $operatorScope->storeId(),
            $roomId
        );
        return $guard === null ? 1 : $this->positiveVersion($guard['current_version'] ?? null);
    }

    public function resolveScopeWithDataScope(
        string $kind,
        string $resourceId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ) {
        try {
            $roomId = $this->roomId($kind, $resourceId);
            $this->assertBaseScope($operatorScope, $dataScope);
            if ($this->repository->findActiveRoom($operatorScope->storeId(), $roomId, false) === null) {
                return null;
            }
            return CashierV3ResourceScope::of(
                CashierV3ResourceScope::TYPE_STORE,
                (string)$operatorScope->storeId()
            );
        } catch (RoomOpenServiceGuardException $exception) {
            return null;
        }
    }

    public function lockAndReadVersionWithDataScope(
        CashierV3ResourceScope $scope,
        string $kind,
        string $resourceId,
        CashierV3DataScopeContext $dataScope
    ) {
        CashierV3TransactionGuard::assertInTransaction('roomOpenServiceVersionLock:' . $kind);
        $roomId = $this->roomId($kind, $resourceId);
        if (!$this->validLockedScope($scope, $dataScope)) {
            return null;
        }
        // Stable global order: room definition before its open-service guard.
        $room = $this->repository->findActiveRoom($dataScope->forcedStoreId(), $roomId, true);
        if ($room === null) {
            return null;
        }
        if ($kind === self::KIND_ROOM) {
            return RoomOpenServiceGuardAuthority::roomVersion($room);
        }
        $guard = $this->repository->lockOrCreateGuard(
            $dataScope->tenantId(),
            $dataScope->forcedStoreId(),
            $roomId
        );
        return $this->positiveVersion($guard['current_version'] ?? null);
    }

    /**
     * Slot CAS is owned by RoomOpenServiceGuardAuthority inside the action.
     * Gateway calls this afterwards only to verify the exact +1 advance.
     */
    public function bumpVersionWithDataScope(
        CashierV3ResourceScope $scope,
        string $kind,
        string $resourceId,
        string $action,
        CashierV3DataScopeContext $dataScope
    ): int {
        CashierV3TransactionGuard::assertInTransaction('roomOpenServiceVersionBump:' . $kind);
        if ($kind !== self::KIND_SLOT) {
            throw self::failure('room_definition_is_read_only', ['kind' => $kind, 'action' => $action]);
        }
        $current = $this->lockAndReadVersionWithDataScope(
            $scope,
            $kind,
            $resourceId,
            $dataScope
        );
        if ($current === null) {
            throw self::failure('room_guard_resource_not_found');
        }
        return $current;
    }

    private function roomId(string $kind, string $resourceId): int
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw self::failure('room_guard_kind_invalid', ['kind' => $kind]);
        }
        $raw = $resourceId;
        if ($kind === self::KIND_SLOT) {
            if (preg_match('/^open-service:([1-9][0-9]*)$/D', $resourceId, $matches) !== 1) {
                throw self::failure('room_guard_slot_identity_invalid');
            }
            $raw = $matches[1];
        }
        if (preg_match('/^[1-9][0-9]*$/D', $raw) !== 1 || (string)(int)$raw !== $raw) {
            throw self::failure('room_guard_room_identity_invalid');
        }
        return (int)$raw;
    }

    private function assertBaseScope(
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): void {
        $storeId = $operatorScope->storeId();
        if ($operatorScope->tenantId() === ''
            || !hash_equals($operatorScope->tenantId(), $dataScope->tenantId())
            || $operatorScope->operatorId() !== $dataScope->operatorId()
            || $storeId !== $dataScope->forcedStoreId()
            || !$dataScope->allowsStore($storeId)) {
            throw self::failure('room_guard_data_scope_denied');
        }
    }

    private function validLockedScope(
        CashierV3ResourceScope $scope,
        CashierV3DataScopeContext $dataScope
    ): bool {
        $storeId = $dataScope->forcedStoreId();
        return $scope->type() === CashierV3ResourceScope::TYPE_STORE
            && ctype_digit($scope->id())
            && (int)$scope->id() === $storeId
            && $dataScope->tenantId() !== ''
            && $dataScope->allowsStore($storeId);
    }

    private function positiveVersion($value): int
    {
        $raw = is_int($value) ? (string)$value : trim((string)$value);
        if (preg_match('/^[1-9][0-9]*$/D', $raw) !== 1 || (string)(int)$raw !== $raw) {
            throw self::failure('room_guard_version_invalid');
        }
        return (int)$raw;
    }

    private static function failure(string $reason, array $detail = []): RoomOpenServiceGuardException
    {
        return new RoomOpenServiceGuardException($reason, $detail);
    }
}
