<?php

namespace app\services\store;

use think\facade\Db;

/**
 * 门店房间基础资料的唯一写入口。
 *
 * table_qrcode 是既有房间配置权威源；本服务不创建平行房间表，也不修改
 * 运行中的占用事实。停用前在同一事务内锁定房间定义，并检查预约、服务、
 * 挂单和开放服务守卫，避免停用仍被业务引用的房间。
 */
final class RoomSettingsServices
{
    private const ROOM_TABLE = 'table_qrcode';
    private const GUARD_TABLE = 'cashier_v3_room_open_service_guard';
    private const RESERVATION_TABLE = 'cashier_v3_reservation';
    private const SERVICE_ORDER_TABLE = 'cashier_v3_service_order';
    private const HANG_ORDER_TABLE = 'cashier_v3_hang_order';

    /** @return array{records:array,total:int,page:int,limit:int} */
    public function page(int $storeId, array $filters): array
    {
        $page = max(1, min(100000, (int)($filters['page'] ?? 1)));
        $limit = max(1, min(100, (int)($filters['limit'] ?? 20)));
        $keyword = trim((string)($filters['keyword'] ?? ''));
        $status = $filters['status'] ?? '';

        $query = Db::name(self::ROOM_TABLE)
            ->where('store_id', $storeId)
            ->where('is_del', 0);
        if ($keyword !== '') {
            $query->whereLike('remarks|table_number', '%' . $keyword . '%');
        }
        if ($status !== '' && in_array((string)$status, ['0', '1'], true)) {
            $query->where('is_using', (int)$status);
        }

        $total = (int)(clone $query)->count();
        $rows = $query
            ->field('id,table_number,remarks,seat_num,is_using,add_time')
            ->order('table_number asc,id asc')
            ->page($page, $limit)
            ->select();
        $records = $this->records($this->rows($rows), $storeId);

        return compact('records', 'total', 'page', 'limit');
    }

    public function create(int $storeId, string $name): array
    {
        $name = $this->roomName($name);
        return Db::transaction(function () use ($storeId, $name): array {
            $last = Db::name(self::ROOM_TABLE)
                ->where('store_id', $storeId)
                ->where('is_del', 0)
                ->order('table_number desc,id desc')
                ->lock(true)
                ->find();
            $nextSort = max(0, (int)($last['table_number'] ?? 0)) + 1;
            $now = time();
            $id = (int)Db::name(self::ROOM_TABLE)->insertGetId([
                'store_id' => $storeId,
                'cate_id' => 0,
                'seat_num' => 0,
                'table_number' => $nextSort,
                'remarks' => $name,
                'is_using' => 1,
                'is_del' => 0,
                'add_time' => $now,
            ]);
            if ($id <= 0) {
                throw new \RuntimeException('房间新增失败，请稍后重试。');
            }
            return $this->record($this->lockedRoom($storeId, $id), $storeId);
        });
    }

    public function update(int $storeId, int $roomId, string $name): array
    {
        $name = $this->roomName($name);
        return Db::transaction(function () use ($storeId, $roomId, $name): array {
            $this->lockedRoom($storeId, $roomId);
            $affected = (int)Db::name(self::ROOM_TABLE)
                ->where('id', $roomId)
                ->where('store_id', $storeId)
                ->where('is_del', 0)
                ->update(['remarks' => $name]);
            if ($affected !== 1) {
                throw new \RuntimeException('房间资料未能保存，请刷新后重试。');
            }
            return $this->record($this->lockedRoom($storeId, $roomId), $storeId);
        });
    }

    public function setEnabled(int $storeId, int $roomId, bool $enabled): array
    {
        return Db::transaction(function () use ($storeId, $roomId, $enabled): array {
            $room = $this->lockedRoom($storeId, $roomId);
            if (!$enabled) {
                $occupancies = $this->activeOccupancies($storeId, [$roomId]);
                if (!empty($occupancies[$roomId])) {
                    throw new \RuntimeException($this->disableReason($occupancies[$roomId]));
                }
            }
            if ((int)$room['is_using'] !== ($enabled ? 1 : 0)) {
                $affected = (int)Db::name(self::ROOM_TABLE)
                    ->where('id', $roomId)
                    ->where('store_id', $storeId)
                    ->where('is_del', 0)
                    ->update(['is_using' => $enabled ? 1 : 0]);
                if ($affected !== 1) {
                    throw new \RuntimeException('房间状态未能更新，请刷新后重试。');
                }
            }
            return $this->record($this->lockedRoom($storeId, $roomId), $storeId);
        });
    }

    /** @param int[] $orderedIds */
    public function reorder(int $storeId, array $orderedIds): void
    {
        $orderedIds = $this->positiveUniqueIds($orderedIds);
        Db::transaction(function () use ($storeId, $orderedIds): void {
            $rows = $this->rows(Db::name(self::ROOM_TABLE)
                ->where('store_id', $storeId)
                ->where('is_del', 0)
                ->field('id')
                ->order('table_number asc,id asc')
                ->lock(true)
                ->select());
            $actualIds = array_map(static function (array $row): int {
                return (int)$row['id'];
            }, $rows);
            sort($actualIds, SORT_NUMERIC);
            $submittedIds = $orderedIds;
            sort($submittedIds, SORT_NUMERIC);
            if ($actualIds !== $submittedIds) {
                throw new \RuntimeException('房间列表已变化，请刷新后重新排序。');
            }
            foreach ($orderedIds as $index => $roomId) {
                Db::name(self::ROOM_TABLE)
                    ->where('id', $roomId)
                    ->where('store_id', $storeId)
                    ->where('is_del', 0)
                    ->update(['table_number' => $index + 1]);
            }
        });
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function records(array $rows, int $storeId): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $occupancies = $this->activeOccupancies($storeId, $ids);
        return array_map(function (array $row) use ($storeId, $occupancies): array {
            return $this->record($row, $storeId, $occupancies[(int)$row['id']] ?? []);
        }, $rows);
    }

    private function record(array $row, int $storeId, ?array $occupancy = null): array
    {
        $id = (int)($row['id'] ?? 0);
        if ($occupancy === null && $id > 0) {
            $occupancy = $this->activeOccupancies($storeId, [$id])[$id] ?? [];
        }
        $occupancy = $occupancy ?? [];
        $enabled = (int)($row['is_using'] ?? 0) === 1;
        return [
            'id' => $id,
            'sort' => (int)($row['table_number'] ?? 0),
            'name' => trim((string)($row['remarks'] ?? '')) ?: ('房间 ' . $id),
            'enabled' => $enabled,
            'createdAt' => (int)($row['add_time'] ?? 0) > 0 ? date('Y-m-d H:i', (int)$row['add_time']) : '',
            'canDisable' => $enabled && !$occupancy,
            'disableReason' => $enabled && $occupancy ? $this->disableReason($occupancy) : '',
        ];
    }

    /** @param int[] $roomIds @return array<int,string[]> */
    private function activeOccupancies(int $storeId, array $roomIds): array
    {
        $roomIds = $this->positiveUniqueIds($roomIds, false);
        if (!$roomIds) {
            return [];
        }
        $found = [];
        $add = static function (array &$bucket, int $roomId, string $type): void {
            if ($roomId > 0) {
                $bucket[$roomId][$type] = $type;
            }
        };

        foreach ($this->rows(Db::name(self::RESERVATION_TABLE)
            ->where('store_id', $storeId)
            ->whereIn('room_id', $roomIds)
            ->whereIn('status', ['PENDING_CONFIRMATION', 'CONFIRMED', 'IN_SERVICE', 'PENDING_CHECKOUT'])
            ->field('room_id')
            ->select()) as $row) {
            $add($found, (int)$row['room_id'], '有效预约');
        }
        foreach ($this->rows(Db::name(self::SERVICE_ORDER_TABLE)
            ->where('business_store_id', $storeId)
            ->whereIn('room_id', $roomIds)
            ->whereIn('status', ['OPEN', 'IN_SERVICE', 'PENDING_CHECKOUT'])
            ->field('room_id')
            ->select()) as $row) {
            $add($found, (int)$row['room_id'], '服务中或待结账服务');
        }
        foreach ($this->rows(Db::name(self::HANG_ORDER_TABLE)
            ->where('store_id', $storeId)
            ->whereIn('room_id', $roomIds)
            ->whereIn('hang_status', ['service_in_progress', 'pending_checkout', 'resumed_checkout'])
            ->field('room_id')
            ->select()) as $row) {
            $add($found, (int)$row['room_id'], '待结账挂单');
        }
        foreach ($this->rows(Db::name(self::GUARD_TABLE)
            ->where('store_id', $storeId)
            ->whereIn('room_id', $roomIds)
            ->where('occupation_status', 'OCCUPIED')
            ->field('room_id')
            ->select()) as $row) {
            $add($found, (int)$row['room_id'], '开放服务占用');
        }
        foreach ($found as $roomId => $types) {
            $found[$roomId] = array_values($types);
        }
        return $found;
    }

    private function disableReason(array $occupancy): string
    {
        return '该房间仍有关联的' . implode('、', $occupancy) . '，不能停用。';
    }

    private function lockedRoom(int $storeId, int $roomId): array
    {
        if ($roomId <= 0) {
            throw new \RuntimeException('房间标识无效。');
        }
        $row = Db::name(self::ROOM_TABLE)
            ->where('id', $roomId)
            ->where('store_id', $storeId)
            ->where('is_del', 0)
            ->lock(true)
            ->find();
        if (!is_array($row) || !$row) {
            throw new \RuntimeException('房间不存在或不属于当前门店。');
        }
        return $row;
    }

    private function roomName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new \RuntimeException('请输入房间名称。');
        }
        if (!mb_check_encoding($name, 'UTF-8')) {
            throw new \RuntimeException('房间名称编码无效，请重新输入。');
        }
        if (mb_strlen($name) > 64) {
            throw new \RuntimeException('房间名称不能超过64个字符。');
        }
        return $name;
    }

    /** @return int[] */
    private function positiveUniqueIds(array $values, bool $required = true): array
    {
        $ids = [];
        foreach ($values as $value) {
            if (!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value))) {
                throw new \RuntimeException('房间排序数据无效，请刷新后重试。');
            }
            $id = (int)$value;
            if ($id <= 0 || isset($ids[$id])) {
                throw new \RuntimeException('房间排序数据无效，请刷新后重试。');
            }
            $ids[$id] = $id;
        }
        if ($required && !$ids) {
            throw new \RuntimeException('请保留至少一个房间后再排序。');
        }
        return array_values($ids);
    }

    private function rows($rows): array
    {
        if (is_object($rows) && method_exists($rows, 'toArray')) {
            $rows = $rows->toArray();
        }
        return is_array($rows) ? array_values($rows) : [];
    }
}
