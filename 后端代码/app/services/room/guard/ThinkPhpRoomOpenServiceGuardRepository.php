<?php

namespace app\services\room\guard;

use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

final class ThinkPhpRoomOpenServiceGuardRepository implements RoomOpenServiceGuardRepository
{
    public const TABLE = 'cashier_v3_room_open_service_guard';

    public function findActiveRoom(int $storeId, int $roomId, bool $lock): ?array
    {
        $query = Db::name('table_qrcode')
            ->where('id', $roomId)
            ->where('store_id', $storeId)
            ->where('is_del', 0)
            ->where('is_using', 1)
            ->field('id,store_id,cate_id,remarks,table_number,seat_num,is_using,is_del,add_time');
        if ($lock) {
            CashierV3TransactionGuard::assertInTransaction('roomOpenServiceRoomLock');
            $query->lock(true);
        }
        return $this->row($query->find());
    }

    public function findGuard(string $tenantId, int $storeId, int $roomId): ?array
    {
        return $this->row(Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('room_id', $roomId)
            ->find());
    }

    public function activeGuards(string $tenantId, int $storeId): array
    {
        $rows = Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('occupation_status', RoomOpenServiceGuardAuthority::STATUS_OCCUPIED)
            ->field('room_id,slot_key,current_version,owner_kind,owner_id,occupied_by,occupied_at,updated_at')
            ->order('room_id asc')
            ->select();
        if (is_object($rows) && method_exists($rows, 'toArray')) {
            $rows = $rows->toArray();
        }
        return is_array($rows) ? array_values($rows) : [];
    }

    public function lockOrCreateGuard(string $tenantId, int $storeId, int $roomId): array
    {
        CashierV3TransactionGuard::assertInTransaction('roomOpenServiceGuardLock');
        $now = time();
        Db::execute(
            'INSERT IGNORE INTO `eb_cashier_v3_room_open_service_guard`'
            . ' (`tenant_id`,`store_id`,`room_id`,`slot_key`,`current_version`,`occupation_status`,'
            . '`owner_kind`,`owner_id`,`occupied_by`,`released_by`,`occupied_at`,`released_at`,'
            . '`last_action`,`created_at`,`updated_at`)'
            . ' VALUES (?,?,?,?,1,?,?,?,?,?,?,?,?,?,?)',
            [
                $tenantId,
                $storeId,
                $roomId,
                RoomOpenServiceGuardAuthority::slotKey($roomId),
                RoomOpenServiceGuardAuthority::STATUS_FREE,
                '',
                '',
                0,
                0,
                0,
                0,
                'guard_created',
                $now,
                $now,
            ]
        );
        $row = $this->row(Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('room_id', $roomId)
            ->lock(true)
            ->find());
        if ($row === null || (int)($row['current_version'] ?? 0) <= 0) {
            throw self::failure('room_guard_row_invalid');
        }
        return $row;
    }

    public function occupyCas(
        string $tenantId,
        int $storeId,
        int $roomId,
        int $expectedVersion,
        string $ownerKind,
        string $ownerId,
        int $operatorId,
        int $now
    ): bool {
        CashierV3TransactionGuard::assertInTransaction('roomOpenServiceGuardOccupy');
        return (int)Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('room_id', $roomId)
            ->where('current_version', $expectedVersion)
            ->where('occupation_status', RoomOpenServiceGuardAuthority::STATUS_FREE)
            ->update([
                'current_version' => Db::raw('current_version + 1'),
                'occupation_status' => RoomOpenServiceGuardAuthority::STATUS_OCCUPIED,
                'owner_kind' => $ownerKind,
                'owner_id' => $ownerId,
                'occupied_by' => $operatorId,
                'released_by' => 0,
                'occupied_at' => $now,
                'released_at' => 0,
                'last_action' => 'occupy',
                'updated_at' => $now,
            ]) === 1;
    }

    public function releaseCas(
        string $tenantId,
        int $storeId,
        int $roomId,
        int $expectedVersion,
        string $ownerKind,
        string $ownerId,
        int $operatorId,
        int $now
    ): bool {
        CashierV3TransactionGuard::assertInTransaction('roomOpenServiceGuardRelease');
        return (int)Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('room_id', $roomId)
            ->where('current_version', $expectedVersion)
            ->where('occupation_status', RoomOpenServiceGuardAuthority::STATUS_OCCUPIED)
            ->where('owner_kind', $ownerKind)
            ->where('owner_id', $ownerId)
            ->update([
                'current_version' => Db::raw('current_version + 1'),
                'occupation_status' => RoomOpenServiceGuardAuthority::STATUS_FREE,
                'owner_kind' => '',
                'owner_id' => '',
                'released_by' => $operatorId,
                'released_at' => $now,
                'last_action' => 'release',
                'updated_at' => $now,
            ]) === 1;
    }

    private function row($value): ?array
    {
        if (is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        }
        return is_array($value) && $value ? $value : null;
    }

    private static function failure(string $reason, array $detail = []): RoomOpenServiceGuardException
    {
        return new RoomOpenServiceGuardException($reason, $detail);
    }
}
