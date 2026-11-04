<?php
namespace app\services\store;

use app\dao\order\StoreReservationOrderDao;
use app\services\BaseServices;
use app\services\cashier\v3\reservation\CashierV3ReservationLifecycleServices;
use think\exception\ValidateException;
use think\facade\Db;
use app\services\store\roster\StaffRosterAvailability;

/**
 * 预约选人 / 可用时段（按门店员工，无距离筛选）
 */
class StoreReservationStaffServices extends BaseServices
{
    /** @var int 默认服务时长（分钟） */
    protected int $defaultDurationMinutes = 120;

    public function __construct(StoreReservationOrderDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 门店营业时间
     */
    public function getStoreBusinessHours(int $storeId): array
    {
        /** @var SystemStoreServices $storeServices */
        $storeServices = app()->make(SystemStoreServices::class);
        $store = $storeServices->get($storeId, ['id', 'day_start', 'day_end']);
        $begin = '09:00:00';
        $end = '22:00:00';
        if ($store) {
            if (!empty($store['day_start'])) {
                $begin = date('H:i:s', strtotime($store['day_start']));
            }
            if (!empty($store['day_end'])) {
                $end = date('H:i:s', strtotime($store['day_end']));
            }
        }
        return ['begin_time' => $begin, 'end_time' => $end];
    }

    /**
     * 解析预约时间戳
     * @param mixed $serviceTime
     */
    public function parseServiceTimestamp($serviceTime): int
    {
        if (!$serviceTime) {
            return 0;
        }
        if (is_numeric($serviceTime)) {
            return (int)$serviceTime;
        }
        $ts = strtotime((string)$serviceTime);
        return $ts ?: 0;
    }

    /**
     * 指定时刻忙碌的员工 ID 列表
     */
    public function getBusyStaffIds(int $storeId, int $appointmentTs, int $durationMinutes = 0, int $excludeReservationId = 0): array
    {
        if (!$storeId || !$appointmentTs) {
            return [];
        }
        if ($durationMinutes <= 0) {
            $durationMinutes = $this->defaultDurationMinutes;
        }
        $rangeStart = $appointmentTs;
        $rangeEnd = $appointmentTs + $durationMinutes * 60;
        $fromDate = date('Y-m-d', $appointmentTs - 86400);
        $orders = $this->getActiveStaffReservations($storeId, 0, $fromDate, $excludeReservationId);
        $busy = [];
        foreach ($orders as $order) {
            [$start, $end] = $this->getReservationTimeRange($order);
            if ($this->isTimeOverlap($rangeStart, $rangeEnd, $start, $end)) {
                foreach ($this->extractReservationStaffIds($order) as $staffId) {
                    $busy[$staffId] = $staffId;
                }
            }
        }
        return array_values($busy);
    }

    /**
     * 员工在指定时间是否可预约
     */
    public function isStaffAvailable(int $staffId, int $storeId, int $appointmentTs, int $durationMinutes = 0, int $excludeReservationId = 0): bool
    {
        if (!$staffId || !$storeId || !$appointmentTs) {
            return true;
        }
        if (!$this->isStaffOnDuty($staffId)) {
            return false;
        }
        if ($durationMinutes <= 0) {
            $durationMinutes = $this->defaultDurationMinutes;
        }
        if ($this->isStaffBlockedByOffWorkTime($staffId, $appointmentTs, $durationMinutes)) {
            return false;
        }
        if (\app\services\store\roster\StaffRosterSwitch::enabled()) {
            // 开启后，员工跨店占用与完整上班/休息时段使用唯一权威规则。
            foreach ($this->getActiveStaffReservations($storeId, $staffId, date('Y-m-d', $appointmentTs - 86400), $excludeReservationId) as $order) {
                [$busyStart, $busyEnd] = $this->getReservationTimeRange($order);
                if ($this->isTimeOverlap($appointmentTs, $appointmentTs + $durationMinutes * 60, $busyStart, $busyEnd)) return false;
            }
            if (!(new StaffRosterAvailability())->allows($staffId, $storeId, $appointmentTs, $appointmentTs + $durationMinutes * 60)) return false;
        } else {
            // 关闭时保持原预约行为；旧老师休息照旧生效，新排班记录不介入。
            if (in_array($staffId, $this->getBusyStaffIds($storeId, $appointmentTs, $durationMinutes, $excludeReservationId), true)) return false;
            foreach ($this->getTeacherPersonalRestSlots($staffId, $storeId, date('Y-m-d', $appointmentTs)) as $slot) {
                if ($this->isTimeOverlap($appointmentTs, $appointmentTs + $durationMinutes * 60, strtotime($slot[0]), strtotime($slot[1]))) return false;
            }
        }
        return true;
    }

    /**
     * 老师是否在岗（可被预约）
     */
    public function isStaffOnDuty(int $staffId): bool
    {
        $staff = $this->getStaffAvailabilityProfile($staffId);
        if (!$staff || (int)($staff['status'] ?? 0) !== 1) {
            return false;
        }
        return (int)($staff['is_reservable'] ?? 1) === 1;
    }

    /**
     * 是否超过老师下班时间（对齐旧系统 down_time）
     */
    public function isStaffBlockedByOffWorkTime(int $staffId, int $appointmentTs, int $durationMinutes = 0): bool
    {
        $staff = $this->getStaffAvailabilityProfile($staffId);
        $offWork = trim((string)($staff['off_work_time'] ?? ''));
        if (!$offWork || !$appointmentTs) {
            return false;
        }
        if (preg_match('/^\d{1,2}:\d{2}$/', $offWork)) {
            $offWork .= ':00';
        }
        $offTs = strtotime(date('Y-m-d', $appointmentTs) . ' ' . $offWork);
        if (!$offTs) {
            return false;
        }
        if ($durationMinutes <= 0) {
            $durationMinutes = $this->defaultDurationMinutes;
        }
        if ($appointmentTs >= $offTs) {
            return true;
        }
        return ($appointmentTs + $durationMinutes * 60) > $offTs;
    }

    protected function getStaffAvailabilityProfile(int $staffId): array
    {
        if (!$staffId) {
            return [];
        }
        /** @var SystemStoreStaffServices $staffServices */
        $staffServices = app()->make(SystemStoreStaffServices::class);
        $staff = $staffServices->getOne(['id' => $staffId, 'is_del' => 0], 'id,staff_name,status,is_reservable,off_work_time');
        if (!$staff) {
            return [];
        }
        return is_object($staff) ? $staff->toArray() : (array)$staff;
    }

    protected function getStaffOffWorkDisabledSlot(int $staffId, string $serviceDate): ?array
    {
        $staff = $this->getStaffAvailabilityProfile($staffId);
        $offWork = trim((string)($staff['off_work_time'] ?? ''));
        if (!$offWork || !$serviceDate) {
            return null;
        }
        if (preg_match('/^\d{1,2}:\d{2}$/', $offWork)) {
            $offWork .= ':00';
        }
        return [$serviceDate . ' ' . $offWork, $serviceDate . ' 23:59:59'];
    }

    /**
     * 员工已被占用 + 排班休息时段（供 chooseTime 组件 disableTimeSlot）
     */
    public function getStaffDisabledTimeSlots(int $staffId, int $storeId, string $serviceDate = '', int $serviceDuration = 0, int $excludeReservationId = 0): array
    {
        if (!$staffId || !$storeId) {
            return [];
        }
        if (!$serviceDate) {
            $serviceDate = date('Y-m-d');
        }
        $slots = [];
        $fromDate = date('Y-m-d', strtotime($serviceDate) - 86400);
        $orders = $this->getActiveStaffReservations($storeId, $staffId, $fromDate, $excludeReservationId);
        foreach ($orders as $order) {
            [$start, $end] = $this->getReservationTimeRange($order);
            $slots[] = [date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $end)];
        }
        $offWorkSlot = $this->getStaffOffWorkDisabledSlot($staffId, $serviceDate);
        if ($offWorkSlot) {
            $slots[] = $offWorkSlot;
        }
        $restSlots = $this->getStaffScheduleRestSlots($staffId, $storeId, $serviceDate);
        return array_merge($slots, $restSlots);
    }

    /**
     * 可用时间接口返回结构
     */
    public function getStaffAvailableTimeData(int $staffId, int $storeId, string $serviceDate = '', int $serviceDuration = 0, int $excludeReservationId = 0, array $staffIds = []): array
    {
        if (!$storeId) {
            throw new ValidateException('请选择门店');
        }
        if (!$staffIds && $staffId) {
            $staffIds = [$staffId];
        }
        $staffIds = array_values(array_unique(array_filter(array_map('intval', $staffIds))));
        $hours = $this->getStoreBusinessHours($storeId);
        $disable = [];
        foreach ($staffIds as $sid) {
            $slots = $this->getStaffDisabledTimeSlots($sid, $storeId, $serviceDate, $serviceDuration, $excludeReservationId);
            if ($slots) {
                $disable = array_merge($disable, $slots);
            }
        }
        return [
            'begin_time' => $hours['begin_time'],
            'end_time' => $hours['end_time'],
            'disable_time_slot' => $disable,
        ];
    }

    /**
     * 门店可预约时段（至少一名老师可用，规则与收银台一致）
     */
    public function getStoreServiceTimeSlots(int $storeId, string $serviceDate = '', int $serviceDuration = 0, int $excludeReservationId = 0): array
    {
        if (!$storeId) {
            throw new ValidateException('请选择门店');
        }
        if (!$serviceDate) {
            $serviceDate = date('Y-m-d');
        }
        if ($serviceDuration <= 0) {
            $serviceDuration = $this->defaultDurationMinutes;
        }
        /** @var SystemStoreStaffServices $staffServices */
        $staffServices = app()->make(SystemStoreStaffServices::class);
        $staffList = $staffServices->getReservationStaffList($storeId, [
            'service_date' => $serviceDate,
            'service_duration' => $serviceDuration,
        ]);
        $staffIds = array_values(array_unique(array_filter(array_map('intval', array_column($staffList, 'id')))));
        $hours = $this->getStoreBusinessHours($storeId);
        $beginTs = strtotime($serviceDate . ' ' . $hours['begin_time']);
        $endTs = strtotime($serviceDate . ' ' . $hours['end_time']);
        if (!$beginTs || !$endTs || $endTs <= $beginTs) {
            return [
                'begin_time' => $hours['begin_time'],
                'end_time' => $hours['end_time'],
                'service_duration' => $serviceDuration,
                'time_slots' => [],
            ];
        }
        /** @var StoreStaffScheduleServices $scheduleServices */
        $scheduleServices = app()->make(StoreStaffScheduleServices::class);
        $intervalSec = 15 * 60;
        $durationSec = $serviceDuration * 60;
        $now = time();
        $isToday = $serviceDate === date('Y-m-d');
        $slots = [];
        for ($slotStart = $beginTs; $slotStart + $durationSec <= $endTs; $slotStart += $intervalSec) {
            $timeStr = date('H:i:s', $slotStart);
            $isPast = $isToday && $slotStart <= $now;
            $available = false;
            if (!$isPast && $staffIds) {
                $rangeEnd = $slotStart + $durationSec;
                foreach ($staffIds as $staffId) {
                    if (!$scheduleServices->isStaffScheduledForSlot($staffId, $storeId, $serviceDate, $slotStart, $rangeEnd)) {
                        continue;
                    }
                    if ($this->isStaffAvailable($staffId, $storeId, $slotStart, $serviceDuration, $excludeReservationId)) {
                        $available = true;
                        break;
                    }
                }
            }
            $slots[] = [
                'time' => $timeStr,
                'available' => $available,
                'is_past' => $isPast,
            ];
        }
        return [
            'begin_time' => $hours['begin_time'],
            'end_time' => $hours['end_time'],
            'service_duration' => $serviceDuration,
            'time_slots' => $slots,
        ];
    }

    /**
     * 指定预约时段内已被占用的手艺人 ID 列表
     */
    public function getBusyStaffIdsAtReservationTime(
        int $storeId,
        string $serviceDate,
        string $reservationStart,
        string $reservationEnd = '',
        int $durationMinutes = 0,
        int $excludeReservationId = 0
    ): array {
        $range = $this->resolveReservationTimeRange($serviceDate, $reservationStart, $reservationEnd, $durationMinutes);
        if (!$range || !$storeId) {
            return [];
        }
        [$rangeStart, $rangeEnd] = $range;
        $fromDate = date('Y-m-d', $rangeStart - 86400);
        $busyIds = [];
        $orders = $this->getActiveStaffReservations($storeId, 0, $fromDate, $excludeReservationId);
        foreach ($orders as $order) {
            [$start, $end] = $this->getReservationTimeRange($order);
            if (!$this->isTimeOverlap($rangeStart, $rangeEnd, $start, $end)) {
                continue;
            }
            foreach ($this->extractReservationStaffIds($order) as $staffId) {
                $busyIds[$staffId] = $staffId;
            }
        }
        return array_values($busyIds);
    }

    /**
     * 解析预约时段起止时间戳
     */
    protected function resolveReservationTimeRange(
        string $serviceDate,
        string $reservationStart,
        string $reservationEnd = '',
        int $durationMinutes = 0
    ): ?array {
        if (!$serviceDate) {
            return null;
        }
        $startStr = trim($reservationStart);
        if (!$startStr) {
            return null;
        }
        $rangeStart = strtotime($serviceDate . ' ' . (strlen($startStr) <= 5 ? $startStr . ':00' : $startStr));
        if (!$rangeStart) {
            return null;
        }
        $endStr = trim($reservationEnd);
        if ($durationMinutes > 0) {
            $rangeEnd = $rangeStart + $durationMinutes * 60;
        } elseif ($endStr) {
            $rangeEnd = strtotime($serviceDate . ' ' . (strlen($endStr) <= 5 ? $endStr . ':00' : $endStr));
            if ($rangeEnd <= $rangeStart) {
                $rangeEnd = $rangeStart + $this->defaultDurationMinutes * 60;
            }
        } else {
            $rangeEnd = $rangeStart + $this->defaultDurationMinutes * 60;
        }
        return [$rangeStart, $rangeEnd];
    }

    /**
     * 手艺人是否与当前预约时段冲突
     */
    public function getStaffReservationConflictList(
        int $storeId,
        array $staffIds,
        string $serviceDate,
        string $reservationStart,
        string $reservationEnd,
        int $durationMinutes = 0,
        int $excludeReservationId = 0
    ): array {
        if (!$storeId || !$staffIds || !$serviceDate) {
            return [];
        }
        $staffIds = array_values(array_unique(array_filter(array_map('intval', $staffIds))));
        if (!$staffIds) {
            return [];
        }
        $startStr = trim($reservationStart);
        if (!$startStr) {
            return [];
        }
        $range = $this->resolveReservationTimeRange($serviceDate, $reservationStart, $reservationEnd, $durationMinutes);
        if (!$range) {
            return [];
        }
        [$rangeStart, $rangeEnd] = $range;
        /** @var SystemStoreStaffServices $staffServices */
        $staffServices = app()->make(SystemStoreStaffServices::class);
        $staffNameMap = [];
        foreach ($staffIds as $staffId) {
            $staffNameMap[$staffId] = (string)$staffServices->value(['id' => $staffId], 'staff_name');
        }
        $conflicts = [];
        $seen = [];
        $fromDate = date('Y-m-d', $rangeStart - 86400);
        $staffIdSet = array_flip($staffIds);
        $orders = $this->getActiveStaffReservations($storeId, 0, $fromDate, $excludeReservationId);
        foreach ($orders as $order) {
            [$start, $end] = $this->getReservationTimeRange($order);
            if (!$this->isTimeOverlap($rangeStart, $rangeEnd, $start, $end)) {
                continue;
            }
            foreach ($this->extractReservationStaffIds($order) as $staffId) {
                if (!isset($staffIdSet[$staffId])) {
                    continue;
                }
                $key = $staffId . '-' . (int)$order['id'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $conflicts[] = [
                    'staff_id' => $staffId,
                    'staff_name' => $staffNameMap[$staffId] ?? '',
                    'reservation_date' => date('Y-m-d', (int)($order['reservation_time'] ?? $start)),
                    'reservation_start' => date('H:i', $start),
                    'reservation_end' => date('H:i', $end),
                ];
            }
        }
        return $conflicts;
    }

    /** 选时与提交使用同一排班：未排班沿用原规则，明确休息及上班范围始终生效。 */
    public function getStaffScheduleRestSlots(int $staffId, int $storeId, string $serviceDate): array
    {
        return \app\services\store\roster\StaffRosterSwitch::enabled()
            ? (new StaffRosterAvailability())->disabled($staffId, $storeId, $serviceDate)
            : $this->getTeacherPersonalRestSlots($staffId, $storeId, $serviceDate);
    }

    /** 关闭新排班时保留既有个人休息的原始时段语义，不读取新排班记录。 */
    protected function getTeacherPersonalRestSlots(int $staffId, int $storeId, string $serviceDate): array
    {
        if (!$staffId || !$storeId || !$serviceDate) {
            return [];
        }
        /** @var \app\dao\store\StoreStaffScheduleDao $scheduleDao */
        $scheduleDao = app()->make(\app\dao\store\StoreStaffScheduleDao::class);
        $rows = $scheduleDao->getByStoreDate($storeId, $serviceDate, $staffId);
        $rows = array_values(array_filter($rows, static fn($row) => empty($row['roster_managed'])));
        if (!$rows) {
            return [];
        }
        $slots = [];
        $fullDayRest = false;
        foreach ($rows as $row) {
            if ((int)($row['schedule_type'] ?? -1) !== 0) {
                continue;
            }
            if (!$row['start_time'] && !$row['end_time']) {
                $fullDayRest = true;
                continue;
            }
            $start = $row['start_time'] ?: '00:00';
            $end = $row['end_time'] ?: '23:59';
            $slots[] = [
                $this->buildScheduleSlotDatetime($serviceDate, (string)$start, false),
                $this->buildScheduleSlotDatetime($serviceDate, (string)$end, true),
            ];
        }
        if ($fullDayRest) {
            return [[$serviceDate . ' 00:00:00', $serviceDate . ' 23:59:59']];
        }
        return $slots;
    }

    /** 关闭新排班时保留既有个人休息的原始时段语义，不读取新排班记录。 */
    protected function buildScheduleSlotDatetime(string $serviceDate, string $time, bool $isEnd = false): string
    {
        $time = trim($time);
        if ($time === '') {
            return $isEnd ? $serviceDate . ' 23:59:59' : $serviceDate . ' 00:00:00';
        }
        if (strpos($time, ' ') !== false) {
            return $time;
        }
        if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $time)) {
            return $serviceDate . ' ' . $time;
        }
        if (preg_match('/^\d{1,2}:\d{2}$/', $time)) {
            return $serviceDate . ' ' . $time . ($isEnd ? ':59' : ':00');
        }
        return $serviceDate . ' ' . $time;
    }

    /** New-generation V3 is the only availability authority after cutover. */
    protected function getActiveStaffReservations(int $storeId, int $staffId = 0, string $fromDate = '', int $excludeReservationId = 0): array
    {
        $query = Db::name('cashier_v3_reservation')
            ->where('tenant_id', \app\services\cashier\v3\CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('lifecycle_generation', CashierV3ReservationLifecycleServices::GENERATION)
            ->whereIn('status', ['PENDING_CONFIRMATION', 'UNSTARTED', 'IN_SERVICE']);
        $rosterEnabled = \app\services\store\roster\StaffRosterSwitch::enabled();
        $aliases = $staffId > 0 ? ($rosterEnabled ? StaffRosterAvailability::aliases([$staffId]) : [$staffId]) : [];
        if ($staffId <= 0 || !$rosterEnabled) $query->where('store_id', $storeId);
        if ($excludeReservationId > 0) $query->where('id', '<>', $excludeReservationId);
        $fromTs = $fromDate ? strtotime($fromDate) : 0;
        if ($fromTs > 0) {
            $query->where('appointment_end_at', '>=', $fromTs);
            if ($staffId > 0) $query->where('appointment_start_at', '<', $fromTs + 3 * 86400);
        }
        $headers = $staffId > 0 && $rosterEnabled
            ? StaffRosterAvailability::reservations([$staffId], $fromTs ?: 1, $fromTs > 0 ? $fromTs + 3 * 86400 : PHP_INT_MAX, $excludeReservationId)
            : $query->field('id,appointment_start_at,appointment_end_at,status')->order('appointment_start_at asc,id asc')->select();
        $headers = is_object($headers) && method_exists($headers, 'toArray') ? $headers->toArray() : (array)$headers;
        if (!$headers) return [];
        if ($staffId > 0 && $rosterEnabled) {
            // 权威关联查询已限定统一员工；直接投影到当前任职，不能再从RR旧快照
            // 二次读取人员关系，否则会把刚刚提交的预约误判为无人预约。
            return array_map(static function (array $header) use ($staffId): array {
                $start = (int)$header['appointment_start_at']; $end = (int)$header['appointment_end_at'];
                return ['id' => (int)$header['id'], 'service_staff_id' => $staffId, 'staff_choose' => [['staff_id' => $staffId]],
                    'reservation_time' => $start, 'reservation_start' => date('H:i:s', $start), 'reservation_end' => date('H:i:s', $end),
                    'service_duration_minutes' => max(1, (int)ceil(($end - $start) / 60)), 'status' => $header['status']];
            }, $headers);
        }
        $reservationIds = array_values(array_unique(array_map('intval', array_column($headers, 'id'))));
        $staffByReservation = [];
        // New time-only appointments have no project line. Their booking
        // staff lives in the reservation-level schedule relation; project
        // JSON remains a fallback for pre-upgrade reservations.
        $scheduled = Db::name('cashier_v3_reservation_staff_schedule')
            ->where('tenant_id', \app\services\cashier\v3\CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('reservation_id', $reservationIds)
            ->field('reservation_id,staff_id')->order('reservation_id asc,staff_id asc')->select();
        $scheduled = is_object($scheduled) && method_exists($scheduled, 'toArray') ? $scheduled->toArray() : (array)$scheduled;
        foreach ($scheduled as $row) {
            $reservationId = (int)($row['reservation_id'] ?? 0);
            $id = (int)($row['staff_id'] ?? 0);
            if ($reservationId > 0 && $id > 0) $staffByReservation[$reservationId][$id] = $id;
        }
        $lines = Db::name('cashier_v3_reservation_line')->whereIn('reservation_id', $reservationIds)
            ->field('reservation_id,artisan_staff_ids_json')->order('reservation_id asc,id asc')->select();
        $lines = is_object($lines) && method_exists($lines, 'toArray') ? $lines->toArray() : (array)$lines;
        foreach ($lines as $line) {
            $reservationId = (int)$line['reservation_id'];
            // A relation row is the authority after the scheduling upgrade.
            if (!empty($staffByReservation[$reservationId])) continue;
            $ids = json_decode((string)($line['artisan_staff_ids_json'] ?? '[]'), true);
            foreach (is_array($ids) ? $ids : [] as $id) {
                $id = (int)$id;
                if ($id > 0) $staffByReservation[$reservationId][$id] = $id;
            }
        }
        $list = [];
        foreach ($headers as $header) {
            $ids = array_values($staffByReservation[(int)$header['id']] ?? []);
            if (!$ids || ($staffId > 0 && !array_intersect($aliases, $ids))) continue;
            $start = (int)$header['appointment_start_at'];
            $end = max($start + 60, (int)$header['appointment_end_at']);
            $list[] = [
                'id' => (int)$header['id'],
                'service_staff_id' => (int)($ids[0] ?? 0),
                'staff_choose' => array_map(static function (int $id): array { return ['staff_id' => $id]; }, $ids),
                'reservation_time' => $start,
                'reservation_start' => date('H:i', $start),
                'reservation_end' => date('H:i', $end),
                'service_duration_minutes' => max(1, (int)ceil(($end - $start) / 60)),
                'status' => (string)$header['status'],
            ];
        }
        if ($staffId) {
            $list = array_values(array_filter($list, function ($item) use ($aliases) {
                return (bool)array_intersect($aliases, $this->extractReservationStaffIds($item));
            }));
        } else {
            $list = array_values(array_filter($list, function ($item) {
                return !empty($this->extractReservationStaffIds($item));
            }));
        }
        $result = [];
        foreach ($list as $item) {
            if ($excludeReservationId && (int)$item['id'] === $excludeReservationId) {
                continue;
            }
            [$start] = $this->getReservationTimeRange($item);
            if ($fromTs && $start < $fromTs) {
                continue;
            }
            $result[] = $item;
        }
        return $result;
    }

    /**
     * 预约单起止时间戳
     */
    protected function getReservationTimeRange(array $order): array
    {
        $date = date('Y-m-d', (int)($order['reservation_time'] ?? time()));
        $startStr = trim((string)($order['reservation_start'] ?? ''));
        if ($startStr) {
            $start = strtotime($date . ' ' . (strlen($startStr) <= 5 ? $startStr . ':00' : $startStr));
        } else {
            $start = (int)($order['reservation_time'] ?? 0);
        }
        $duration = (int)($order['service_duration_minutes'] ?? 0);
        $endStr = trim((string)($order['reservation_end'] ?? ''));
        if ($duration > 0) {
            $end = $start + $duration * 60;
        } elseif ($endStr) {
            $end = strtotime($date . ' ' . (strlen($endStr) <= 5 ? $endStr . ':00' : $endStr));
            if ($end <= $start) {
                $end = $start + $this->defaultDurationMinutes * 60;
            }
        } else {
            $end = $start + $this->defaultDurationMinutes * 60;
        }
        return [$start, $end];
    }

    protected function isTimeOverlap(int $start1, int $end1, int $start2, int $end2): bool
    {
        return $start1 < $end2 && $end1 > $start2;
    }

    /**
     * 排班/休息时段拼接为完整 datetime（兼容 HH:mm 与 HH:mm:ss）
     */
    /**
     * 预约选人：指定日期时段是否可选
     */
    public function isStaffSelectableForReservation(
        int $staffId,
        int $storeId,
        string $serviceDate,
        string $serviceTime,
        int $serviceDuration = 0,
        int $excludeReservationId = 0
    ): bool {
        if (!$staffId || !$storeId) {
            return false;
        }
        if (!$this->isStaffOnDuty($staffId)) {
            return false;
        }
        $serviceTime = trim($serviceTime);
        if ($serviceTime === '') {
            // 尚未选择具体时间时仍须属于当天上班名单；开关关闭不增加新的预约限制。
            return !\app\services\store\roster\StaffRosterSwitch::enabled()
                || in_array($staffId, (new StaffRosterAvailability())->workingStaffIds([$staffId], $storeId, $serviceDate ?: date('Y-m-d')), true);
        }
        if (!$serviceDate) {
            $serviceDate = date('Y-m-d');
        }
        $timeStr = strlen($serviceTime) <= 5 ? $serviceTime . ':00' : $serviceTime;
        $appointmentTs = strtotime($serviceDate . ' ' . $timeStr);
        if (!$appointmentTs) {
            return false;
        }
        if ($serviceDuration <= 0) {
            $serviceDuration = $this->defaultDurationMinutes;
        }
        $rangeEnd = $appointmentTs + $serviceDuration * 60;
        /** @var StoreStaffScheduleServices $scheduleServices */
        $scheduleServices = app()->make(StoreStaffScheduleServices::class);
        if (!$scheduleServices->isStaffScheduledForSlot($staffId, $storeId, $serviceDate, $appointmentTs, $rangeEnd)) {
            return false;
        }
        return $this->isStaffAvailable($staffId, $storeId, $appointmentTs, $serviceDuration, $excludeReservationId);
    }

    /**
     * 预约单关联的全部手艺人（含 staff_choose 多选）
     */
    protected function extractReservationStaffIds(array $order): array
    {
        $ids = [];
        $primaryId = (int)($order['service_staff_id'] ?? 0);
        if ($primaryId) {
            $ids[$primaryId] = $primaryId;
        }
        $staffChoose = $order['staff_choose'] ?? [];
        if (is_string($staffChoose) && $staffChoose !== '') {
            $decoded = json_decode($staffChoose, true);
            $staffChoose = is_array($decoded) ? $decoded : [];
        }
        if (is_array($staffChoose)) {
            foreach ($staffChoose as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $staffId = (int)($item['staff_id'] ?? 0);
                if ($staffId) {
                    $ids[$staffId] = $staffId;
                }
            }
        }
        return array_values($ids);
    }
}
