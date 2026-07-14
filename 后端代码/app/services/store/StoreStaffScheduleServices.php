<?php
namespace app\services\store;

use app\dao\store\StoreStaffScheduleDao;
use app\model\position\Position;
use app\services\BaseServices;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * 门店员工排班/休息
 * @mixin StoreStaffScheduleDao
 */
class StoreStaffScheduleServices extends BaseServices
{
    public function __construct(StoreStaffScheduleDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 是否启用排班管理
     */
    public function isScheduleManageEnabled(): bool
    {
        return (int)sys_config('reservation_schedule_manage', 0) === 1;
    }

    /**
     * 预约看板/选人：获取当日应展示的员工
     */
    public function getReservationBoardStaff(int $storeId, string $date, int $filterStaffId = 0): array
    {
        /** @var SystemStoreStaffServices $staffServices */
        $staffServices = app()->make(SystemStoreStaffServices::class);
        $where = ['store_id' => $storeId, 'status' => 1, 'is_del' => 0];
        if ($filterStaffId) {
            $where['id'] = $filterStaffId;
        }
        if (!$this->isScheduleManageEnabled()) {
            $where['is_reservable'] = 1;
            return $staffServices->getColumn($where, 'id,staff_name,phone');
        }
        $allStaff = $staffServices->getColumn($where, 'id,staff_name,phone');
        if (!$allStaff) {
            return [];
        }
        $scheduleList = $this->dao->getByStoreDate($storeId, $date);
        $scheduleMap = [];
        foreach ($scheduleList as $row) {
            $scheduleMap[(int)$row['staff_id']][] = $row;
        }
        $result = [];
        foreach ($allStaff as $staff) {
            $staffId = (int)$staff['id'];
            $rows = $scheduleMap[$staffId] ?? [];
            if (!$rows) {
                continue;
            }
            $hasWork = false;
            $fullDayRest = false;
            foreach ($rows as $row) {
                if ((int)$row['schedule_type'] === 1) {
                    $hasWork = true;
                } elseif ((int)$row['schedule_type'] === 0 && !$row['start_time'] && !$row['end_time']) {
                    $fullDayRest = true;
                }
            }
            if ($fullDayRest && !$hasWork) {
                continue;
            }
            $result[] = $staff;
        }
        return $result;
    }

    /**
     * 预约选人列表（非排班模式过滤可被预约）
     */
    public function filterReservationStaffList(array $staffList): array
    {
        if ($this->isScheduleManageEnabled() || !$staffList) {
            return $staffList;
        }
        return array_values(array_filter($staffList, function ($item) {
            if (isset($item['value'])) {
                return true;
            }
            return (int)($item['is_reservable'] ?? 1) === 1;
        }));
    }

    /**
     * 员工在指定日期时段是否有上班排班（含班次模板时间与休息段）
     */
    public function isStaffScheduledForSlot(int $staffId, int $storeId, string $date, int $rangeStart, int $rangeEnd): bool
    {
        if (!$this->isScheduleManageEnabled() || !$staffId || !$storeId || !$date || !$rangeStart || !$rangeEnd || $rangeEnd <= $rangeStart) {
            return true;
        }
        $rows = $this->dao->getByStoreDate($storeId, $date, $staffId);
        if (!$rows) {
            return false;
        }
        /** @var StoreStaffShiftServices $shiftServices */
        $shiftServices = app()->make(StoreStaffShiftServices::class);
        $shiftMap = $shiftServices->getShiftMap($storeId);
        $hasWork = false;
        $fullDayRest = false;
        foreach ($rows as $row) {
            if ((int)$row['schedule_type'] === 0 && !$row['start_time'] && !$row['end_time']) {
                $fullDayRest = true;
                continue;
            }
            if ((int)$row['schedule_type'] !== 1) {
                continue;
            }
            $hasWork = true;
            $shiftId = (int)($row['shift_id'] ?? 0);
            $workStart = $row['start_time'] ?? '';
            $workEnd = $row['end_time'] ?? '';
            if ($shiftId && isset($shiftMap[$shiftId])) {
                $workStart = $shiftMap[$shiftId]['start_time'] ?? $workStart;
                $workEnd = $shiftMap[$shiftId]['end_time'] ?? $workEnd;
            }
            if (!$workStart || !$workEnd) {
                return !$this->slotOverlapsScheduleRest($rows, $shiftMap, $storeId, $date, $rangeStart, $rangeEnd);
            }
            $workStartTs = strtotime($date . ' ' . $workStart . ':00');
            $workEndTs = strtotime($date . ' ' . $workEnd . ':00');
            if ($workStartTs && $workEndTs && $rangeStart >= $workStartTs && $rangeEnd <= $workEndTs) {
                if (!$this->slotOverlapsScheduleRest($rows, $shiftMap, $storeId, $date, $rangeStart, $rangeEnd)) {
                    return true;
                }
            }
        }
        if ($fullDayRest && !$hasWork) {
            return false;
        }
        return false;
    }

    /**
     * 预约时段是否与排班休息/班次休息段重叠
     */
    protected function slotOverlapsScheduleRest(array $rows, array $shiftMap, int $storeId, string $date, int $rangeStart, int $rangeEnd): bool
    {
        /** @var StoreReservationStaffServices $reservationStaffServices */
        $reservationStaffServices = app()->make(StoreReservationStaffServices::class);
        $staffId = (int)($rows[0]['staff_id'] ?? 0);
        $restSlots = $reservationStaffServices->getStaffScheduleRestSlots($staffId, $storeId, $date);
        foreach ($restSlots as $slot) {
            $restStart = strtotime($slot[0]);
            $restEnd = strtotime($slot[1]);
            if ($restStart && $restEnd && $this->isTimeRangeOverlap($rangeStart, $rangeEnd, $restStart, $restEnd)) {
                return true;
            }
        }
        foreach ($rows as $row) {
            if ((int)$row['schedule_type'] !== 1) {
                continue;
            }
            $shiftId = (int)($row['shift_id'] ?? 0);
            if (!$shiftId || !isset($shiftMap[$shiftId])) {
                continue;
            }
            /** @var StoreStaffShiftServices $shiftServices */
            $shiftServices = app()->make(StoreStaffShiftServices::class);
            $breaks = $shiftServices->decodeBreakPeriods($shiftMap[$shiftId]['break_periods'] ?? '');
            foreach ($breaks as $break) {
                $bStart = trim((string)($break['start'] ?? $break['start_time'] ?? ''));
                $bEnd = trim((string)($break['end'] ?? $break['end_time'] ?? ''));
                if (!$bStart || !$bEnd) {
                    continue;
                }
                $breakStartTs = strtotime($date . ' ' . $bStart . (strlen($bStart) <= 5 ? ':00' : ''));
                $breakEndTs = strtotime($date . ' ' . $bEnd . (strlen($bEnd) <= 5 ? ':00' : ''));
                if ($breakStartTs && $breakEndTs && $this->isTimeRangeOverlap($rangeStart, $rangeEnd, $breakStartTs, $breakEndTs)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * 看板休息背景块
     */
    public function getBoardRestEvents(int $storeId, string $date, array $staffRows): array
    {
        if (!$this->isScheduleManageEnabled() || !$staffRows) {
            return [];
        }
        $staffIds = array_column($staffRows, 'id');
        $scheduleList = $this->dao->getByStoreDate($storeId, $date);
        /** @var StoreStaffShiftServices $shiftServices */
        $shiftServices = app()->make(StoreStaffShiftServices::class);
        $shiftMap = $shiftServices->getShiftMap($storeId);
        $workStaffIds = [];
        foreach ($scheduleList as $row) {
            if ((int)$row['schedule_type'] === 1) {
                $workStaffIds[(int)$row['staff_id']] = true;
            }
        }
        $events = [];
        foreach ($scheduleList as $row) {
            if ((int)$row['schedule_type'] !== 0) {
                continue;
            }
            if (!in_array((int)$row['staff_id'], $staffIds)) {
                continue;
            }
            $start = $row['start_time'] ?: '00:00';
            $end = $row['end_time'] ?: '23:59';
            // 当日已有上班排班时，忽略无时段的全天休息记录，避免盖住预约块
            if (!$row['start_time'] && !$row['end_time'] && isset($workStaffIds[(int)$row['staff_id']])) {
                continue;
            }
            $events[] = [
                'staff_id' => (int)$row['staff_id'],
                'resourceId' => (int)$row['staff_id'],
                'title' => '休息',
                'start' => $date . ' ' . $start,
                'end' => $date . ' ' . $end,
                'display' => 'background',
                'color' => '#E8E8E8',
                'schedule_type' => 0,
            ];
        }
        // 上班时段外的休息背景（有明确上下班时间时）
        foreach ($scheduleList as $row) {
            if ((int)$row['schedule_type'] !== 1) {
                continue;
            }
            if (!in_array((int)$row['staff_id'], $staffIds)) {
                continue;
            }
            $startTime = (string)($row['start_time'] ?? '');
            $endTime = (string)($row['end_time'] ?? '');
            $shiftId = (int)($row['shift_id'] ?? 0);
            if ($shiftId && isset($shiftMap[$shiftId])) {
                if (!$startTime) {
                    $startTime = (string)($shiftMap[$shiftId]['start_time'] ?? '');
                }
                if (!$endTime) {
                    $endTime = (string)($shiftMap[$shiftId]['end_time'] ?? '');
                }
            }
            if (!$startTime || !$endTime) {
                continue;
            }
            $staffId = (int)$row['staff_id'];
            if ($startTime > '00:00') {
                $events[] = [
                    'staff_id' => $staffId,
                    'resourceId' => $staffId,
                    'title' => '休息',
                    'start' => $date . ' 00:00',
                    'end' => $date . ' ' . $startTime,
                    'display' => 'background',
                    'color' => '#E8E8E8',
                    'schedule_type' => 0,
                ];
            }
            if ($endTime < '23:59') {
                $events[] = [
                    'staff_id' => $staffId,
                    'resourceId' => $staffId,
                    'title' => '休息',
                    'start' => $date . ' ' . $endTime,
                    'end' => $date . ' 23:59',
                    'display' => 'background',
                    'color' => '#E8E8E8',
                    'schedule_type' => 0,
                ];
            }
        }
        return $events;
    }

    public function getMonthList(int $storeId, string $month, int $staffId = 0): array
    {
        return $this->dao->getByStoreMonth($storeId, $month, $staffId);
    }

    public function saveSchedule(int $storeId, array $data): bool
    {
        $staffId = (int)($data['staff_id'] ?? 0);
        $date = (string)($data['schedule_date'] ?? '');
        $type = (int)($data['schedule_type'] ?? 1);
        if (!$staffId || !$date) {
            throw new ValidateException('请选择员工和日期');
        }
        if (!in_array($type, [0, 1], true)) {
            throw new ValidateException('排班类型错误');
        }
        /** @var SystemStoreStaffServices $staffServices */
        $staffServices = app()->make(SystemStoreStaffServices::class);
        $staff = $staffServices->getOne(['id' => $staffId, 'store_id' => $storeId, 'is_del' => 0]);
        if (!$staff) {
            throw new ValidateException('员工不存在');
        }
        $save = [
            'store_id' => $storeId,
            'staff_id' => $staffId,
            'shift_id' => (int)($data['shift_id'] ?? 0),
            'schedule_date' => $date,
            'schedule_type' => $type,
            'start_time' => (string)($data['start_time'] ?? ''),
            'end_time' => (string)($data['end_time'] ?? ''),
            'mark' => (string)($data['mark'] ?? ''),
            'update_time' => time(),
        ];
        $exist = $this->dao->getOne([
            'store_id' => $storeId,
            'staff_id' => $staffId,
            'schedule_date' => $date,
            'schedule_type' => $type,
            'start_time' => $save['start_time'],
            'end_time' => $save['end_time'],
        ]);
        if ($exist) {
            return (bool)$this->dao->update($exist['id'], $save);
        }
        $save['add_time'] = time();
        return (bool)$this->dao->save($save);
    }

    public function deleteSchedule(int $storeId, int $id): bool
    {
        $row = $this->dao->get($id);
        if (!$row || (int)$row['store_id'] !== $storeId) {
            throw new ValidateException('记录不存在');
        }
        return (bool)$this->dao->delete($id);
    }

    /**
     * 批量设置（同一天多员工）
     */
    public function batchSave(int $storeId, array $list): bool
    {
        foreach ($list as $item) {
            $this->saveSchedule($storeId, $item);
        }
        return true;
    }

    /**
     * 月历排班看板
     */
    public function getCalendarBoard(int $storeId, string $month, string $keyword = '', int $positionId = 0): array
    {
        $month = $month ?: date('Y-m');
        $rows = $this->dao->getByStoreMonth($storeId, $month);
        /** @var StoreStaffShiftServices $shiftServices */
        $shiftServices = app()->make(StoreStaffShiftServices::class);
        $shiftMap = $shiftServices->getShiftMap($storeId);
        /** @var SystemStoreStaffServices $staffServices */
        $staffServices = app()->make(SystemStoreStaffServices::class);
        $staffWhere = ['store_id' => $storeId, 'status' => 1, 'is_del' => 0];
        $staffRows = $staffServices->geAllList($staffWhere);
        $staffRows = $this->filterStaffRows($staffRows, $keyword, $positionId);
        $staffMap = [];
        foreach ($staffRows as $s) {
            $staffMap[(int)$s['id']] = $s;
        }
        $allowedStaffIds = array_keys($staffMap);
        $days = [];
        foreach ($rows as $row) {
            $staffId = (int)$row['staff_id'];
            if ($allowedStaffIds && !in_array($staffId, $allowedStaffIds, true)) {
                continue;
            }
            if ((int)$row['schedule_type'] !== 1) {
                continue;
            }
            $date = (string)$row['schedule_date'];
            if (!isset($days[$date])) {
                $days[$date] = [
                    'date' => $date,
                    'staff_ids' => [],
                    'staff_names' => [],
                    'shifts' => [],
                ];
            }
            $shiftId = (int)($row['shift_id'] ?? 0);
            if ($shiftId <= 0) {
                $shiftId = 0;
            }
            if (!isset($days[$date]['shifts'][$shiftId])) {
                $shift = $shiftId && isset($shiftMap[$shiftId]) ? $shiftMap[$shiftId] : null;
                $days[$date]['shifts'][$shiftId] = [
                    'shift_id' => $shiftId,
                    'shift_name' => $shift ? ($shift['name'] ?? '') : '自定义',
                    'start_time' => $shift ? ($shift['start_time'] ?? $row['start_time']) : $row['start_time'],
                    'end_time' => $shift ? ($shift['end_time'] ?? $row['end_time']) : $row['end_time'],
                    'staff' => [],
                ];
            }
            $staffName = $staffMap[$staffId]['staff_name'] ?? (string)$staffId;
            if (!in_array($staffId, $days[$date]['staff_ids'], true)) {
                $days[$date]['staff_ids'][] = $staffId;
                $days[$date]['staff_names'][] = $staffName;
            }
            $exists = false;
            foreach ($days[$date]['shifts'][$shiftId]['staff'] as $st) {
                if ((int)$st['staff_id'] === $staffId) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $days[$date]['shifts'][$shiftId]['staff'][] = [
                    'staff_id' => $staffId,
                    'staff_name' => $staffName,
                ];
            }
        }
        foreach ($days as &$day) {
            $day['count'] = count($day['staff_ids']);
            $day['staff_names_text'] = implode('、', $day['staff_names']);
            $day['shifts'] = array_values($day['shifts']);
        }
        unset($day);
        return [
            'month' => $month,
            'days' => $days,
            'schedule_manage' => $this->isScheduleManageEnabled() ? 1 : 0,
            'shifts' => array_values($shiftMap),
        ];
    }

    /**
     * 排班导出列表（与月历搜索条件一致，按行展开）
     */
    public function getScheduleExportList(int $storeId, string $month, string $keyword = '', int $positionId = 0): array
    {
        $board = $this->getCalendarBoard($storeId, $month, $keyword, $positionId);
        $days = $board['days'] ?? [];
        if (!$days) {
            return [];
        }
        /** @var SystemStoreStaffServices $staffServices */
        $staffServices = app()->make(SystemStoreStaffServices::class);
        $staffRows = $this->filterStaffRows(
            $staffServices->geAllList(['store_id' => $storeId, 'status' => 1, 'is_del' => 0]),
            $keyword,
            $positionId
        );
        $positionIds = array_values(array_unique(array_filter(array_map(function ($row) {
            return (int)($row['position'] ?? 0);
        }, $staffRows))));
        $positionMap = $positionIds ? Position::whereIn('id', $positionIds)->column('name', 'id') : [];
        $staffDetailMap = [];
        foreach ($staffRows as $row) {
            $pid = (int)($row['position'] ?? 0);
            $staffDetailMap[(int)$row['id']] = [
                'staff_name' => (string)($row['staff_name'] ?? ''),
                'phone' => (string)($row['phone'] ?? ''),
                'account' => (string)($row['account'] ?? ''),
                'position_label' => $pid ? (string)($positionMap[$pid] ?? '') : '',
            ];
        }
        $weekMap = ['日', '一', '二', '三', '四', '五', '六'];
        $list = [];
        ksort($days);
        foreach ($days as $date => $day) {
            $weekIndex = (int)date('w', strtotime($date));
            $weekDay = '星期' . ($weekMap[$weekIndex] ?? '');
            foreach ($day['shifts'] ?? [] as $shift) {
                $shiftName = (string)($shift['shift_name'] ?? '');
                $startTime = (string)($shift['start_time'] ?? '');
                $endTime = (string)($shift['end_time'] ?? '');
                $shiftTime = $startTime && $endTime ? $startTime . '-' . $endTime : ($startTime ?: $endTime);
                foreach ($shift['staff'] ?? [] as $staff) {
                    $staffId = (int)($staff['staff_id'] ?? 0);
                    $detail = $staffDetailMap[$staffId] ?? [];
                    $list[] = [
                        'schedule_date' => $date,
                        'week_day' => $weekDay,
                        'staff_name' => (string)($staff['staff_name'] ?? ($detail['staff_name'] ?? '')),
                        'phone' => $detail['phone'] ?? '',
                        'account' => $detail['account'] ?? '',
                        'position_label' => $detail['position_label'] ?? '',
                        'shift_name' => $shiftName,
                        'start_time' => $startTime,
                        'end_time' => $endTime,
                        'shift_time' => $shiftTime,
                    ];
                }
            }
        }
        return $list;
    }

    /**
     * 获取某日各班次已排员工
     */
    public function getDayDetail(int $storeId, string $date): array
    {
        /** @var StoreStaffShiftServices $shiftServices */
        $shiftServices = app()->make(StoreStaffShiftServices::class);
        $shifts = $shiftServices->getList($storeId);
        $rows = $this->dao->getByStoreDate($storeId, $date);
        $scheduledStaffIds = [];
        foreach ($rows as $row) {
            if ((int)$row['schedule_type'] === 1) {
                $scheduledStaffIds[] = (int)$row['staff_id'];
            }
        }
        $staffMap = $this->getStaffNameMap($storeId, $scheduledStaffIds);
        $assigned = [];
        foreach ($rows as $row) {
            if ((int)$row['schedule_type'] !== 1) {
                continue;
            }
            $shiftId = (int)($row['shift_id'] ?? 0);
            if (!isset($assigned[$shiftId])) {
                $assigned[$shiftId] = [];
            }
            $assigned[$shiftId][] = [
                'staff_id' => (int)$row['staff_id'],
                'staff_name' => $staffMap[(int)$row['staff_id']] ?? '',
            ];
        }
        $result = [];
        foreach ($shifts as $shift) {
            $sid = (int)$shift['id'];
            $result[] = [
                'shift_id' => $sid,
                'shift_name' => $shift['name'],
                'start_time' => $shift['start_time'],
                'end_time' => $shift['end_time'],
                'staff' => $assigned[$sid] ?? [],
            ];
        }
        return [
            'schedule_date' => $date,
            'shifts' => $result,
        ];
    }

    /**
     * 按班次保存某日排班
     */
    public function saveDayByShifts(int $storeId, string $date, array $shifts): bool
    {
        if (!$date) {
            throw new ValidateException('请选择排班日期');
        }
        /** @var StoreStaffShiftServices $shiftServices */
        $shiftServices = app()->make(StoreStaffShiftServices::class);
        $shiftMap = $shiftServices->getShiftMap($storeId);
        Db::name('store_staff_schedule')
            ->where('store_id', $storeId)
            ->where('schedule_date', $date)
            ->where('shift_id', '>', 0)
            ->delete();
        $now = time();
        foreach ($shifts as $block) {
            $shiftId = (int)($block['shift_id'] ?? 0);
            if (!$shiftId || !isset($shiftMap[$shiftId])) {
                continue;
            }
            $shift = $shiftMap[$shiftId];
            $staffIds = $block['staff_ids'] ?? [];
            if (!is_array($staffIds)) {
                $staffIds = [];
            }
            foreach ($staffIds as $staffId) {
                $staffId = (int)$staffId;
                if (!$staffId) {
                    continue;
                }
                $this->dao->save([
                    'store_id' => $storeId,
                    'staff_id' => $staffId,
                    'shift_id' => $shiftId,
                    'schedule_date' => $date,
                    'schedule_type' => 1,
                    'start_time' => (string)($shift['start_time'] ?? ''),
                    'end_time' => (string)($shift['end_time'] ?? ''),
                    'mark' => '',
                    'add_time' => $now,
                    'update_time' => $now,
                ]);
            }
        }
        return true;
    }

    /**
     * 可选排班员工
     */
    public function getSelectableStaff(int $storeId, string $keyword = '', int $positionId = 0): array
    {
        /** @var SystemStoreStaffServices $staffServices */
        $staffServices = app()->make(SystemStoreStaffServices::class);
        $where = ['store_id' => $storeId, 'status' => 1, 'is_del' => 0];
        $list = $staffServices->geAllList($where);
        return $this->filterStaffRows($list, $keyword, $positionId);
    }

    /**
     * 排班展示用员工姓名映射（本店员工 + 可被其他门店选中的员工）
     */
    protected function getStaffNameMap(int $storeId, array $extraStaffIds = []): array
    {
        /** @var SystemStoreStaffServices $staffServices */
        $staffServices = app()->make(SystemStoreStaffServices::class);
        $staffMap = [];
        foreach ($staffServices->geAllList(['store_id' => $storeId, 'status' => 1, 'is_del' => 0]) as $s) {
            $staffMap[(int)$s['id']] = (string)($s['staff_name'] ?? '');
        }
        $extraStaffIds = array_values(array_unique(array_filter(array_map('intval', $extraStaffIds))));
        $missingIds = array_diff($extraStaffIds, array_keys($staffMap));
        if ($missingIds) {
            $missingIdSet = array_flip($missingIds);
            foreach ($staffServices->getSelectList(['is_del' => 0], 'id,staff_name') as $s) {
                $staffId = (int)($s['id'] ?? 0);
                if (!$staffId || !isset($missingIdSet[$staffId])) {
                    continue;
                }
                $staffMap[$staffId] = (string)($s['staff_name'] ?? '');
            }
        }
        return $staffMap;
    }

    protected function filterStaffRows(array $list, string $keyword, int $positionId): array
    {
        if ($positionId) {
            $list = array_values(array_filter($list, function ($item) use ($positionId) {
                return (int)($item['position'] ?? 0) === $positionId;
            }));
        }
        $keyword = trim($keyword);
        if ($keyword !== '') {
            $k = mb_strtolower($keyword);
            $list = array_values(array_filter($list, function ($item) use ($k) {
                foreach (['staff_name', 'account', 'phone'] as $field) {
                    if (mb_strpos(mb_strtolower((string)($item[$field] ?? '')), $k) !== false) {
                        return true;
                    }
                }
                return false;
            }));
        }
        return $list;
    }

    protected function isTimeRangeOverlap(int $start1, int $end1, int $start2, int $end2): bool
    {
        return $start1 < $end2 && $end1 > $start2;
    }
}
