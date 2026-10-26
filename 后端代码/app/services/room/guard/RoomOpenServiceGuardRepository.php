<?php

namespace app\services\room\guard;

interface RoomOpenServiceGuardRepository
{
    public function findActiveRoom(int $storeId, int $roomId, bool $lock): ?array;

    public function findGuard(string $tenantId, int $storeId, int $roomId): ?array;

    /** @return array<int,array> */
    public function activeGuards(string $tenantId, int $storeId): array;

    /** Caller must already be inside the owning business transaction. */
    public function lockOrCreateGuard(string $tenantId, int $storeId, int $roomId): array;

    public function occupyCas(
        string $tenantId,
        int $storeId,
        int $roomId,
        int $expectedVersion,
        string $ownerKind,
        string $ownerId,
        int $operatorId,
        int $now
    ): bool;

    public function releaseCas(
        string $tenantId,
        int $storeId,
        int $roomId,
        int $expectedVersion,
        string $ownerKind,
        string $ownerId,
        int $operatorId,
        int $now
    ): bool;
}
