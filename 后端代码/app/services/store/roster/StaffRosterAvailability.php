<?php
declare(strict_types=1);
namespace app\services\store\roster;
use think\facade\Db;

/** 排班与预约共用员工身份及锁序；不建立客户端各自的可预约算法。 */
final class StaffRosterAvailability
{
    public static function aliases(array $staffIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $staffIds))));
        if (!$ids) return [];
        $employees = Db::name('system_store_staff')->whereIn('id', $ids)->where('employee_id', '>', 0)->column('employee_id');
        if ($employees) $ids = array_merge($ids, Db::name('system_store_staff')->whereIn('employee_id', $employees)->column('id'));
        $ids = array_values(array_unique(array_map('intval', $ids))); sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** 调用方必须已开启事务；预约和排班均先锁同一员工所有任职，防止同时预约/改休息。 */
    public static function lock(array $staffIds): array
    {
        \app\services\cashier\v3\CashierV3TransactionGuard::assertInTransaction('staffRoster.employeeLock');
        $ids = self::aliases($staffIds);
        return $ids ? Db::name('system_store_staff')->whereIn('id', $ids)->order('id asc')->lock(true)->select()->toArray() : [];
    }

    /** 旧记录缺少快照时只读解析原模板；新记录永远使用保存时的时间快照。 */
    public function rows(int $staffId, string $date): array
    {
        $ids = self::aliases([$staffId]);
        if (!$ids) return [];
        $current = \app\services\cashier\v3\CashierV3TransactionGuard::isInTransaction();
        $query = Db::name('store_staff_schedule')->whereIn('staff_id', $ids)
            ->whereBetween('schedule_date', [date('Y-m-d', strtotime($date . ' -1 day')), $date])->order('id asc');
        // 等待员工锁后必须当前读；不能让事务早先的RR快照漏掉刚提交的休息。
        if ($current) $query->lock(true);
        $rows = $query->select()->toArray();
        $shiftIds = array_values(array_unique(array_filter(array_column($rows, 'shift_id'))));
        $shifts = $shiftIds ? Db::name('store_staff_shift')->whereIn('id', $shiftIds)->select()->toArray() : [];
        $map = array_column($shifts, null, 'id');
        foreach ($rows as &$row) {
            if ((int)$row['schedule_type'] !== 1) continue;
            $shift = $map[$row['shift_id']] ?? [];
            if (empty($row['shift_name_snapshot'])) {
                $row['start_time'] = $row['start_time'] ?: ($shift['start_time'] ?? '');
                $row['end_time'] = $row['end_time'] ?: ($shift['end_time'] ?? '');
                $row['breaks_snapshot'] = $shift['break_periods'] ?? '';
            }
        }
        unset($row);
        return $rows;
    }

    /** 仅查询所选员工的有效预约。事务内用当前读，避免等待锁后仍读到旧快照。
     * 关联表为新预约权威；只有尚无人员关系的旧预约才回读项目人员JSON。
     */
    public static function reservations(array $staffIds, int $from, int $to, int $excludeId = 0): array
    {
        $ids = self::aliases($staffIds);
        if (!$ids) return [];
        $query = Db::name('cashier_v3_reservation')->alias('r')
            ->leftJoin('cashier_v3_reservation_staff_schedule s', 's.tenant_id=r.tenant_id AND s.reservation_id=r.id')
            ->leftJoin('cashier_v3_reservation_line l', 'l.tenant_id=r.tenant_id AND l.reservation_id=r.id')
            ->where('r.tenant_id', \app\services\cashier\v3\CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('r.lifecycle_generation', \app\services\cashier\v3\reservation\CashierV3ReservationLifecycleServices::GENERATION)
            ->whereIn('r.status', ['PENDING_CONFIRMATION', 'UNSTARTED', 'IN_SERVICE'])
            ->where('r.appointment_start_at', '<', $to)->where('r.appointment_end_at', '>', $from)
            ->where(function ($query) use ($ids): void {
                $query->whereIn('s.staff_id', $ids)->whereOr(function ($legacy) use ($ids): void {
                    $legacy->whereNull('s.id')->where(function ($people) use ($ids): void {
                        foreach ($ids as $id) {
                            // ID已归整为整数，JSON参数仍使用绑定，不插入用户文本。
                            $people->whereOrRaw("JSON_CONTAINS(IF(JSON_VALID(l.artisan_staff_ids_json),l.artisan_staff_ids_json,'[]'),?)", [json_encode($id)])
                                ->whereOrRaw("JSON_CONTAINS(IF(JSON_VALID(l.artisan_staff_ids_json),l.artisan_staff_ids_json,'[]'),?)", [json_encode((string)$id)]);
                        }
                    });
                });
            });
        if ($excludeId > 0) $query->where('r.id', '<>', $excludeId);
        if (\app\services\cashier\v3\CashierV3TransactionGuard::isInTransaction()) $query->lock(true);
        return $query->field('r.id,r.store_id,r.reservation_no,r.appointment_start_at,r.appointment_end_at,r.status')->distinct(true)->order('r.id asc')->select()->toArray();
    }

    public function allows(int $staffId, int $storeId, int $start, int $end): bool
    {
        $date = date('Y-m-d', $start);
        $rows = $this->rows($staffId, $date);
        if (date('Y-m-d', $end - 1) !== $date) $rows = array_merge($rows, $this->rows($staffId, date('Y-m-d', $end - 1)));
        return StaffRosterRules::allows($rows, $storeId, $start, $end);
    }

    public function disabled(int $staffId, int $storeId, string $date): array
    {
        return StaffRosterRules::disabled($this->rows($staffId, $date), $storeId, $date);
    }
}
