<?php

namespace app\services\cashier\v3\reservation;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResourceVersionServices;
use app\services\cashier\v3\projection\CashierV3RootPartitionProvider;
use think\facade\Db;

/** Read model for new C3 reservations only. Old reservation rows are intentionally excluded. */
final class CashierV3ReservationPartitionProvider implements CashierV3RootPartitionProvider
{
    private const BUSINESS_TIMEZONE = 'Asia/Shanghai';

    public function partitionKey(): string { return 'reservation'; }

    public function readPartition(
        string $stateContextId,
        string $stateRevision,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        array $hints = []
    ): array {
        $tenantId = $dataScope->tenantId();
        $storeId = $operatorScope->storeId();
        $now = time();
        $workflow = strtolower(trim((string)($hints['workflow'] ?? '')));
        $quickFilter = self::quickFilter($hints['quickFilter'] ?? $hints['quick_filter'] ?? '', $workflow);
        $page = max(1, (int)($hints['page'] ?? 1));
        $pageSize = max(1, min(100, (int)($hints['pageSize'] ?? $hints['page_size'] ?? 20)));
        $dueNotArrivedCount = (int)Db::name('cashier_v3_reservation')->alias('r')
            ->where('r.tenant_id', $tenantId)->where('r.store_id', $storeId)
            ->where('r.lifecycle_generation', CashierV3ReservationLifecycleServices::GENERATION)
            ->where('r.status', 'UNSTARTED')
            ->where('r.appointment_start_at', '>', 0)->where('r.appointment_start_at', '<=', $now)
            ->where('r.actual_service_started_at', 0)
            ->count();
        $recordsQuery = Db::name('cashier_v3_reservation')
            ->where('tenant_id', $tenantId)->where('store_id', $storeId)
            ->where('lifecycle_generation', CashierV3ReservationLifecycleServices::GENERATION)
            ->whereIn('status', ['PENDING_CONFIRMATION', 'UNSTARTED', 'IN_SERVICE', 'COMPLETED']);
        if ($quickFilter === 'pending_confirmation') {
            $recordsQuery->where('status', 'PENDING_CONFIRMATION');
        } elseif ($quickFilter === 'service') {
            $recordsQuery->whereIn('status', ['UNSTARTED', 'IN_SERVICE', 'COMPLETED']);
        } elseif ($quickFilter === 'unstarted') {
            $recordsQuery->whereIn('status', ['PENDING_CONFIRMATION', 'UNSTARTED']);
        } elseif ($quickFilter === 'serving') {
            $recordsQuery->where('status', 'IN_SERVICE');
        } elseif ($quickFilter === 'ended') {
            $recordsQuery->where('status', 'COMPLETED');
        } elseif ($quickFilter === 'today') {
            $todayStart = self::businessNow()->setTime(0, 0)->getTimestamp();
            $recordsQuery->where('appointment_start_at', '>=', $todayStart)
                ->where('appointment_start_at', '<', $todayStart + 86400);
        }
        $total = (int)(clone $recordsQuery)->count();
        $rows = $recordsQuery->order('appointment_start_at desc,id desc')->page($page, $pageSize)->select();
        $rows = is_object($rows) && method_exists($rows, 'toArray') ? $rows->toArray() : (array)$rows;
        $ids = [];
        foreach ($rows as $row) $ids[] = (int)($row['id'] ?? 0);
        $serviceOrderIds = [];
        foreach ($rows as $row) {
            $serviceOrderId = (int)($row['service_order_id'] ?? 0);
            if ($serviceOrderId > 0) $serviceOrderIds[$serviceOrderId] = $serviceOrderId;
        }
        $serviceOrders = $serviceOrderIds
            ? Db::name('cashier_v3_service_order')
                ->where('tenant_id', $tenantId)->where('business_store_id', $storeId)
                ->whereIn('id', array_values($serviceOrderIds))
                ->field('id,status,service_started_at')->select()
            : [];
        $serviceOrders = is_object($serviceOrders) && method_exists($serviceOrders, 'toArray')
            ? $serviceOrders->toArray()
            : (array)$serviceOrders;
        $serviceOrdersById = [];
        foreach ($serviceOrders as $serviceOrder) {
            $serviceOrdersById[(int)($serviceOrder['id'] ?? 0)] = $serviceOrder;
        }
        $lineRows = $ids ? Db::name('cashier_v3_reservation_line')->where('tenant_id', $tenantId)->whereIn('reservation_id', $ids)->order('id asc')->select() : [];
        $lineRows = is_object($lineRows) && method_exists($lineRows, 'toArray') ? $lineRows->toArray() : (array)$lineRows;
        $lines = [];
        foreach ($lineRows as $line) $lines[(int)($line['reservation_id'] ?? 0)][] = $line;
        $artisanIds = [];
        foreach ($lineRows as $line) {
            $ids = json_decode((string)($line['artisan_staff_ids_json'] ?? '[]'), true);
            foreach (is_array($ids) ? $ids : [] as $staffId) { $staffId = (int)$staffId; if ($staffId > 0) $artisanIds[$staffId] = $staffId; }
        }
        $artisanNames = $artisanIds ? Db::name('system_store_staff')->whereIn('id', array_values($artisanIds))->column('staff_name', 'id') : [];
        $versions = [];
        $records = [];
        $countBase = Db::name('cashier_v3_reservation')->where('tenant_id', $tenantId)->where('store_id', $storeId)
            ->where('lifecycle_generation', CashierV3ReservationLifecycleServices::GENERATION);
        $today = self::businessNow()->format('Y-m-d');
        $todayStart = self::businessNow()->setTime(0, 0)->getTimestamp();
        $counts = [
            'today' => (int)(clone $countBase)->where('appointment_start_at', '>=', $todayStart)->where('appointment_start_at', '<', $todayStart + 86400)->count(),
            'pendingConfirmation' => (int)(clone $countBase)->where('status', 'PENDING_CONFIRMATION')->count(),
            'unstarted' => (int)(clone $countBase)->where('status', 'UNSTARTED')->count(),
            'serving' => (int)(clone $countBase)->where('status', 'IN_SERVICE')->count(),
            'ended' => (int)(clone $countBase)->where('status', 'COMPLETED')->count(),
            'dueNotArrived' => $dueNotArrivedCount,
        ];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) continue;
            $projectNames = [];
            $reservationArtisans = [];
            foreach ((array)($lines[$id] ?? []) as $line) {
                $projectNames[] = (string)($line['project_name_snapshot'] ?? '');
                $ids = json_decode((string)($line['artisan_staff_ids_json'] ?? '[]'), true);
                foreach (is_array($ids) ? $ids : [] as $staffId) { $name = trim((string)($artisanNames[(int)$staffId] ?? '')); if ($name !== '') $reservationArtisans[$name] = $name; }
            }
            $statusCode = (string)($row['status'] ?? '');
            $status = self::statusLabel($statusCode);
            $dateTime = (int)($row['appointment_start_at'] ?? 0);
            $serviceOrderId = (int)($row['service_order_id'] ?? 0);
            $serviceOrder = (array)($serviceOrdersById[$serviceOrderId] ?? []);
            $dueNotArrived = $dateTime > 0
                && $dateTime <= $now
                && $statusCode === 'UNSTARTED'
                && (int)($row['actual_service_started_at'] ?? 0) <= 0;
            // reservation is a domain-owned source version. It is not a
            // central synthetic row: the V3 reservation header is the only
            // authority, so list/detail actions receive the header revision.
            $version = (int)($row['version'] ?? 0);
            if ($version > 0) $versions[] = ['kind' => 'reservation', 'id' => (string)$id, 'version' => $version];
            $actualStartedAt = (int)($row['actual_service_started_at'] ?? 0);
            $totalDurationSeconds = max(60, (int)$row['appointment_end_at'] - (int)$row['appointment_start_at']);
            $expectedEndAt = $actualStartedAt > 0 ? $actualStartedAt + $totalDurationSeconds : 0;
            $records[] = [
                'id' => $id,
                'reservationId' => $id,
                'reservationNo' => (string)($row['reservation_no'] ?? ''),
                'appointmentTime' => $dateTime > 0 ? self::businessTimeFromTimestamp($dateTime) : '',
                'memberName' => (string)($row['member_name_snapshot'] ?? ''),
                'phone' => (string)($row['member_phone_snapshot'] ?? ''),
                'projectSummary' => implode('、', array_filter($projectNames)),
                'projectSource' => '本次预约',
                'craftsmanSummary' => $reservationArtisans ? implode('、', array_values($reservationArtisans)) : '待分配手艺人',
                'roomName' => trim((string)($row['room_name_snapshot'] ?? '')) ?: ((int)($row['room_id'] ?? 0) > 0 ? '房间 ' . (int)$row['room_id'] : ''),
                'remark' => (string)($row['remark_snapshot'] ?? ''),
                'source' => '收银 V3',
                'creator' => (string)($row['creator_name_snapshot'] ?? ''),
                'status' => $status,
                'statusCode' => $statusCode,
                'sourceType' => (string)($row['source_type'] ?? ''),
                'serviceOrderId' => $serviceOrderId,
                'actualServiceStartedAt' => $actualStartedAt,
                'actualStartAt' => $actualStartedAt,
                'actualServiceEndedAt' => (int)($row['actual_service_ended_at'] ?? 0),
                'serviceCountdownEndsAt' => $expectedEndAt,
                'expectedEndAt' => $expectedEndAt,
                'totalDurationSeconds' => $totalDurationSeconds,
                'isOvertime' => $actualStartedAt > 0
                    && (int)($row['actual_service_ended_at'] ?? 0) <= 0
                    && $now > $expectedEndAt,
                'dueNotArrived' => $dueNotArrived,
                'primaryAction' => ['action' => 'open-reservation-detail', 'label' => '查看详情', 'enabled' => true],
            ];
        }
        $calendarDate = self::calendarDate($hints['calendarDate'] ?? $hints['calendar_date'] ?? '');
        $calendar = self::calendar($tenantId, $storeId, $calendarDate, $rows, $lines, $artisanNames);
        return ['ready' => true, 'payload' => [
            'availability' => ['contractVersion' => 'cashier-v3-reservation-v1', 'status' => 'active', 'reasonCode' => '', 'dataLoaded' => true, 'businessFactsIncluded' => true],
            'quickCounts' => $counts, 'records' => $records, 'total' => $total, 'page' => $page, 'pageSize' => $pageSize,
            'detail' => null, 'editor' => ['draft' => new \stdClass(), 'catalogOptions' => [], 'craftsmenOptions' => [], 'rooms' => []],
            'calendar' => $calendar,
        ], 'public_versions' => $versions];
    }

    /**
     * Calendar is a read model over V3 reservations only.  It deliberately
     * does not try to infer availability from legacy reservation tables.
     */
    private static function calendar(string $tenantId, int $storeId, string $date, array $rows, array $lines, array $artisanNames): array
    {
        $store = Db::name('system_store')->where('id', $storeId)->field('day_start,day_end')->find();
        $start = self::timeOfDay((string)($store['day_start'] ?? ''));
        $end = self::timeOfDay((string)($store['day_end'] ?? ''));
        $timeSlots = [];
        $businessHoursReady = $start !== '' && $end !== '' && $start < $end;
        if ($businessHoursReady) {
            $zone = self::businessZone();
            $cursor = new \DateTimeImmutable($date . ' ' . $start, $zone);
            $closeAt = new \DateTimeImmutable($date . ' ' . $end, $zone);
            for (; $cursor < $closeAt; $cursor = $cursor->modify('+30 minutes')) {
                $timeSlots[$cursor->format('H:i')] = $cursor->format('H:i');
            }
        }

        // 营业时间只限制新预约的可选时段。历史或已创建预约必须始终
        // 出现在对应日期的时间轴上，不能因门店后来修改营业时间而消失。
        foreach ($rows as $row) {
            $at = (int)($row['appointment_start_at'] ?? 0);
            if ($at <= 0 || self::businessDateFromTimestamp($at) !== $date) {
                continue;
            }
            $endAt = max($at + 60, (int)($row['appointment_end_at'] ?? 0));
            $cursor = self::businessTime($at)->setTime(
                (int)self::businessTime($at)->format('H'),
                ((int)self::businessTime($at)->format('i') >= 30) ? 30 : 0
            );
            $closeAt = self::businessTime($endAt)->modify('+30 minutes')->setTime(
                (int)self::businessTime($endAt)->modify('+30 minutes')->format('H'),
                ((int)self::businessTime($endAt)->modify('+30 minutes')->format('i') >= 30) ? 30 : 0
            );
            for (; $cursor < $closeAt; $cursor = $cursor->modify('+30 minutes')) {
                $timeSlots[$cursor->format('H:i')] = $cursor->format('H:i');
            }
        }
        ksort($timeSlots, SORT_STRING);

        $resources = [['id' => 'staff:unassigned', 'type' => 'unassigned_staff', 'name' => '未分配']];
        $staffRows = Db::name('system_store_staff')
            ->where('store_id', $storeId)->where('status', 1)->where('is_del', 0)
            ->where('cashier_craftsman_enabled', 1)->field('id,staff_name')->order('id asc')->select();
        $staffRows = is_object($staffRows) && method_exists($staffRows, 'toArray') ? $staffRows->toArray() : (array)$staffRows;
        foreach ($staffRows as $staff) {
            $staffId = (int)($staff['id'] ?? 0);
            if ($staffId > 0) $resources[] = ['id' => 'staff:' . $staffId, 'type' => 'staff', 'name' => (string)($staff['staff_name'] ?? ('手艺人 ' . $staffId))];
        }

        $roomResources = [['id' => 'room:unassigned', 'type' => 'unassigned_room', 'name' => '未分配']];
        $roomRows = Db::name('table_qrcode')->where('store_id', $storeId)->where('is_del', 0)->where('is_using', 1)
            ->field('id,remarks,table_number')->order('id asc')->select();
        $roomRows = is_object($roomRows) && method_exists($roomRows, 'toArray') ? $roomRows->toArray() : (array)$roomRows;
        foreach ($roomRows as $room) {
            $roomId = (int)($room['id'] ?? 0);
            if ($roomId > 0) {
                $name = trim((string)($room['remarks'] ?? $room['table_number'] ?? '')) ?: ('房间 ' . $roomId);
                $roomResources[] = ['id' => 'room:' . $roomId, 'type' => 'room', 'name' => $name];
            }
        }
        $resources = array_merge($resources, $roomResources);

        $blocks = [];
        foreach ($rows as $row) {
            $reservationId = (int)($row['id'] ?? 0);
            $at = (int)($row['appointment_start_at'] ?? 0);
            if ($reservationId <= 0 || $at <= 0 || self::businessDateFromTimestamp($at) !== $date) continue;
            $lineRows = (array)($lines[$reservationId] ?? []);
            $staffIds = [];
            foreach ($lineRows as $line) {
                $ids = json_decode((string)($line['artisan_staff_ids_json'] ?? '[]'), true);
                foreach (is_array($ids) ? $ids : [] as $staffId) {
                    $staffId = (int)$staffId;
                    if ($staffId > 0) $staffIds[$staffId] = $staffId;
                }
            }
            $resourceIds = $staffIds ? array_map(static function (int $id): string { return 'staff:' . $id; }, array_values($staffIds)) : ['staff:unassigned'];
            $roomId = (int)($row['room_id'] ?? 0);
            $roomResourceId = $roomId > 0 ? 'room:' . $roomId : 'room:unassigned';
            $projectCount = count($lineRows);
            $base = [
                'id' => 'reservation:' . $reservationId,
                'reservationId' => $reservationId,
                'memberName' => (string)($row['member_name_snapshot'] ?? ''),
                'start' => self::businessTimeFromTimestamp($at, 'H:i'),
                'end' => self::businessTimeFromTimestamp(max($at + 60, (int)($row['appointment_end_at'] ?? 0)), 'H:i'),
                'projectCount' => $projectCount,
                'status' => self::statusLabel((string)($row['status'] ?? '')),
                'roomName' => trim((string)($row['room_name_snapshot'] ?? '')) ?: ($roomId > 0 ? ('房间 ' . $roomId) : '待分配房间'),
            ];
            foreach (array_unique(array_merge($resourceIds, [$roomResourceId])) as $resourceId) {
                $blocks[] = array_merge($base, ['id' => $base['id'] . ':' . $resourceId, 'resourceId' => $resourceId]);
            }
        }
        return ['date' => $date, 'resources' => $resources, 'blocks' => $blocks, 'timeSlots' => array_values($timeSlots), 'businessHoursReady' => $businessHoursReady];
    }

    private static function calendarDate($value): string
    {
        $value = trim((string)$value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, self::businessZone());
        return $date && $date->format('Y-m-d') === $value ? $value : self::businessNow()->format('Y-m-d');
    }

    private static function quickFilter($value, string $workflow = ''): string
    {
        $value = trim((string)$value);
        if ($workflow === 'confirmation') return 'pending_confirmation';
        if ($workflow === 'service' && $value === '') return 'service';
        return in_array($value, ['today', 'unstarted', 'serving', 'ended', 'all', 'pending_confirmation', 'service'], true) ? $value : 'today';
    }

    private static function timeOfDay(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^([01]?\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $value)) return '';
        $parts = explode(':', $value);
        return str_pad((string)(int)$parts[0], 2, '0', STR_PAD_LEFT) . ':' . $parts[1];
    }

    private static function businessZone(): \DateTimeZone
    {
        return new \DateTimeZone(self::BUSINESS_TIMEZONE);
    }

    private static function businessNow(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', self::businessZone());
    }

    private static function businessDateFromTimestamp(int $timestamp): string
    {
        return self::businessTime($timestamp)->format('Y-m-d');
    }

    private static function businessTimeFromTimestamp(int $timestamp, string $format = 'Y-m-d H:i'): string
    {
        return self::businessTime($timestamp)->format($format);
    }

    private static function businessTime(int $timestamp): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(self::businessZone());
    }

    private static function statusLabel(string $status): string
    {
        return ['PENDING_CONFIRMATION' => '待确认', 'UNSTARTED' => '待服务', 'IN_SERVICE' => '服务中', 'COMPLETED' => '已结束', 'CANCELLED' => '已取消', 'REJECTED' => '已拒绝'][$status] ?? '状态未知';
    }
}
