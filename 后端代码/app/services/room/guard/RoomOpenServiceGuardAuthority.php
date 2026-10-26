<?php

namespace app\services\room\guard;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3TransactionGuard;

final class RoomOpenServiceGuardAuthority
{
    public const CONTRACT_VERSION = 'room-open-service-guard-v1';
    public const STATUS_FREE = 'FREE';
    public const STATUS_OCCUPIED = 'OCCUPIED';
    public const OWNER_HANG_ORDER = 'cashier_v3_hang_order';
    public const OWNER_SERVICE_ORDER = 'cashier_v3_service_order';

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

    public static function slotKey(int $roomId): string
    {
        if ($roomId <= 0) {
            throw self::failure('room_identity_invalid');
        }
        return 'open-service:' . $roomId;
    }

    /** Read-only availability projection. Missing guard rows are version 1 and free. */
    public function discover(
        int $roomId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $this->assertScope($operatorScope, $dataScope);
        $room = $this->requireRoom($operatorScope->storeId(), $roomId, false);
        $guard = $this->repository->findGuard(
            $dataScope->tenantId(),
            $operatorScope->storeId(),
            $roomId
        );
        return $this->state($room, $guard);
    }

    /** Locks room first, then the empty-range-safe open-service guard. */
    public function lockInTx(
        int $roomId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('roomOpenServiceAuthorityLock');
        $this->assertScope($operatorScope, $dataScope);
        $room = $this->requireRoom($operatorScope->storeId(), $roomId, true);
        $guard = $this->repository->lockOrCreateGuard(
            $dataScope->tenantId(),
            $operatorScope->storeId(),
            $roomId
        );
        return $this->state($room, $guard);
    }

    /**
     * The caller owns the outer business transaction. A thrown exception rolls
     * the occupation back together with the hang/service order.
     */
    public function occupyInTx(
        int $roomId,
        string $ownerKind,
        string $ownerId,
        int $expectedVersion,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $this->assertOwner($ownerKind, $ownerId);
        $this->assertVersion($expectedVersion);
        $locked = $this->lockInTx($roomId, $operatorScope, $dataScope);
        $this->assertExpectedVersion($locked, $expectedVersion);
        if ($locked['occupied']) {
            throw self::failure('room_open_service_conflict', [
                'roomId' => $roomId,
                'slotKey' => $locked['slotKey'],
                'currentVersion' => $locked['slotVersion'],
                'ownerKind' => $locked['ownerKind'],
                'ownerId' => $locked['ownerId'],
            ]);
        }
        $now = time();
        if (!$this->repository->occupyCas(
            $dataScope->tenantId(),
            $operatorScope->storeId(),
            $roomId,
            $expectedVersion,
            $ownerKind,
            $ownerId,
            $operatorScope->operatorId(),
            $now
        )) {
            throw self::failure('room_open_service_occupy_cas_conflict', [
                'roomId' => $roomId,
                'expectedVersion' => $expectedVersion,
            ]);
        }
        return $this->state($locked['room'], array_merge($locked['guard'], [
            'current_version' => $expectedVersion + 1,
            'occupation_status' => self::STATUS_OCCUPIED,
            'owner_kind' => $ownerKind,
            'owner_id' => $ownerId,
            'occupied_by' => $operatorScope->operatorId(),
            'occupied_at' => $now,
            'last_action' => 'occupy',
            'updated_at' => $now,
        ]));
    }

    /** V3 hang-order naming boundary; slot identity is never inferred silently. */
    public function claimInTx(
        int $roomId,
        string $slotId,
        int $expectedVersion,
        string $ownerKind,
        string $ownerId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        if (!hash_equals(self::slotKey($roomId), $slotId)) {
            throw self::failure('room_guard_slot_identity_mismatch', [
                'roomId' => $roomId,
                'slotId' => $slotId,
            ]);
        }
        return $this->occupyInTx(
            $roomId,
            $ownerKind,
            $ownerId,
            $expectedVersion,
            $operatorScope,
            $dataScope
        );
    }

    public function releaseInTx(
        int $roomId,
        string $ownerKind,
        string $ownerId,
        int $expectedVersion,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $this->assertOwner($ownerKind, $ownerId);
        $this->assertVersion($expectedVersion);
        $locked = $this->lockInTx($roomId, $operatorScope, $dataScope);
        $this->assertExpectedVersion($locked, $expectedVersion);
        if (!$locked['occupied']
            || $locked['ownerKind'] !== $ownerKind
            || $locked['ownerId'] !== $ownerId) {
            throw self::failure('room_open_service_release_owner_conflict', [
                'roomId' => $roomId,
                'currentVersion' => $locked['slotVersion'],
            ]);
        }
        $now = time();
        if (!$this->repository->releaseCas(
            $dataScope->tenantId(),
            $operatorScope->storeId(),
            $roomId,
            $expectedVersion,
            $ownerKind,
            $ownerId,
            $operatorScope->operatorId(),
            $now
        )) {
            throw self::failure('room_open_service_release_cas_conflict', [
                'roomId' => $roomId,
                'expectedVersion' => $expectedVersion,
            ]);
        }
        return $this->state($locked['room'], array_merge($locked['guard'], [
            'current_version' => $expectedVersion + 1,
            'occupation_status' => self::STATUS_FREE,
            'owner_kind' => '',
            'owner_id' => '',
            'released_by' => $operatorScope->operatorId(),
            'released_at' => $now,
            'last_action' => 'release',
            'updated_at' => $now,
        ]));
    }

    /** @return array{conflict:bool,state:array} */
    public function conflict(
        int $roomId,
        string $ownerKind,
        string $ownerId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $this->assertOwner($ownerKind, $ownerId);
        $state = $this->discover($roomId, $operatorScope, $dataScope);
        return [
            'conflict' => $state['occupied']
                && ($state['ownerKind'] !== $ownerKind || $state['ownerId'] !== $ownerId),
            'state' => $state,
        ];
    }

    /** Active V3 occupations for room-list projections; keyed by room id. */
    public function activeOccupancies(
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $this->assertScope($operatorScope, $dataScope);
        $result = [];
        foreach ($this->repository->activeGuards(
            $dataScope->tenantId(),
            $operatorScope->storeId()
        ) as $row) {
            $roomId = (int)($row['room_id'] ?? 0);
            if ($roomId <= 0) {
                throw self::failure('room_guard_active_row_invalid');
            }
            $result[$roomId] = [
                'roomId' => $roomId,
                'slotKey' => (string)($row['slot_key'] ?? ''),
                'slotVersion' => (int)($row['current_version'] ?? 0),
                'ownerKind' => (string)($row['owner_kind'] ?? ''),
                'ownerId' => (string)($row['owner_id'] ?? ''),
                'occupiedAt' => (int)($row['occupied_at'] ?? 0),
                'contractVersion' => self::CONTRACT_VERSION,
            ];
        }
        return $result;
    }

    public static function roomVersion(array $room): int
    {
        $payload = [
            'authority' => 'legacy-room-reservation-authority-v1',
            'room' => [
                'id' => (int)($room['id'] ?? 0),
                'storeId' => (int)($room['store_id'] ?? 0),
                'categoryId' => (int)($room['cate_id'] ?? 0),
                'name' => self::roomName($room),
                'tableNumber' => (string)($room['table_number'] ?? ''),
                'seatCount' => (int)($room['seat_num'] ?? 0),
                'enabled' => (int)($room['is_using'] ?? 0),
                'deleted' => (int)($room['is_del'] ?? 0),
                'createdAt' => (int)($room['add_time'] ?? 0),
            ],
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw self::failure('room_version_payload_invalid');
        }
        return (int)hexdec(substr(hash('sha256', $json), 0, 13)) + 1;
    }

    private function state(array $room, ?array $guard): array
    {
        $guard = $guard ?: [
            'current_version' => 1,
            'occupation_status' => self::STATUS_FREE,
            'owner_kind' => '',
            'owner_id' => '',
            'last_action' => '',
        ];
        $version = (int)($guard['current_version'] ?? 0);
        $this->assertVersion($version);
        $occupied = ($guard['occupation_status'] ?? '') === self::STATUS_OCCUPIED;
        return [
            'roomId' => (int)$room['id'],
            'roomName' => self::roomName($room),
            'roomVersion' => self::roomVersion($room),
            'slotKey' => self::slotKey((int)$room['id']),
            'slotVersion' => $version,
            'occupied' => $occupied,
            'ownerKind' => $occupied ? (string)($guard['owner_kind'] ?? '') : '',
            'ownerId' => $occupied ? (string)($guard['owner_id'] ?? '') : '',
            'lastAction' => (string)($guard['last_action'] ?? ''),
            'room' => $room,
            'guard' => $guard,
            'contractVersion' => self::CONTRACT_VERSION,
        ];
    }

    private function requireRoom(int $storeId, int $roomId, bool $lock): array
    {
        if ($roomId <= 0) {
            throw self::failure('room_identity_invalid');
        }
        $room = $this->repository->findActiveRoom($storeId, $roomId, $lock);
        if ($room === null || (int)($room['store_id'] ?? 0) !== $storeId) {
            throw self::failure('room_not_found_in_forced_store', ['roomId' => $roomId]);
        }
        return $room;
    }

    private function assertScope(
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

    private function assertOwner(string $ownerKind, string $ownerId): void
    {
        if (!in_array($ownerKind, [self::OWNER_HANG_ORDER, self::OWNER_SERVICE_ORDER], true)
            || preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $ownerId) !== 1) {
            throw self::failure('room_guard_owner_invalid');
        }
    }

    private function assertVersion(int $version): void
    {
        if ($version <= 0 || $version >= PHP_INT_MAX) {
            throw self::failure('room_guard_version_invalid', ['version' => $version]);
        }
    }

    private function assertExpectedVersion(array $state, int $expectedVersion): void
    {
        if ((int)$state['slotVersion'] !== $expectedVersion) {
            throw self::failure('room_guard_version_conflict', [
                'slotKey' => $state['slotKey'],
                'expectedVersion' => $expectedVersion,
                'currentVersion' => (int)$state['slotVersion'],
            ]);
        }
    }

    private static function roomName(array $room): string
    {
        $name = trim((string)($room['remarks'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $number = trim((string)($room['table_number'] ?? ''));
        return $number === '' ? '房间 ' . (int)($room['id'] ?? 0) : '房间 ' . $number;
    }

    private static function failure(string $reason, array $detail = []): RoomOpenServiceGuardException
    {
        return new RoomOpenServiceGuardException($reason, $detail);
    }
}
