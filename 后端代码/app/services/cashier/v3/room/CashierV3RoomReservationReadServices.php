<?php

namespace app\services\cashier\v3\room;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use think\facade\Db;

/** Read-only room projection for new V3 reservations that are already in service. */
final class CashierV3RoomReservationReadServices
{
    private const BUSINESS_TIMEZONE = 'Asia/Shanghai';

    /** @return array<int,array<int,array<string,mixed>>> keyed by room id */
    public function activeByRoom(CashierV3OperatorScope $operator, CashierV3DataScopeContext $dataScope): array
    {
        $rows = $this->rows(Db::name('cashier_v3_reservation')
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $operator->storeId())
            ->where('status', 'IN_SERVICE')
            ->where('room_id', '>', 0)
            ->field('id,reservation_no,member_name_snapshot,member_phone_snapshot,room_id,room_name_snapshot,version,appointment_start_at,appointment_end_at')
            ->order('room_id asc,id asc')
            ->select());
        if (!$rows) return [];

        $reservationIds = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) $reservationIds[$id] = $id;
        }
        if (!$reservationIds) return [];

        $projects = $this->projectSummaries($dataScope->tenantId(), array_values($reservationIds));
        $startedAt = $this->startedAt($dataScope->tenantId(), array_values($reservationIds));
        $result = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            $roomId = (int)($row['room_id'] ?? 0);
            if ($id <= 0 || $roomId <= 0) continue;
            $result[$roomId][] = [
                'sourceType' => 'reservation',
                'reservationId' => $id,
                'reservationNo' => (string)($row['reservation_no'] ?? ''),
                'reservationVersion' => (int)($row['version'] ?? 0),
                'memberName' => (string)($row['member_name_snapshot'] ?? ''),
                'memberPhone' => (string)($row['member_phone_snapshot'] ?? ''),
                'projectSummary' => $projects[$id] ?? '',
                'roomId' => $roomId,
                'roomName' => (string)($row['room_name_snapshot'] ?? ''),
                'serviceStartedAt' => self::formatTime((int)($startedAt[$id] ?? 0)),
                'appointmentStartAt' => self::formatTime((int)($row['appointment_start_at'] ?? 0)),
                'appointmentEndAt' => self::formatTime((int)($row['appointment_end_at'] ?? 0)),
            ];
        }
        return $result;
    }

    /** @param int[] $reservationIds @return array<int,string> */
    private function projectSummaries(string $tenantId, array $reservationIds): array
    {
        $lines = $this->rows(Db::name('cashier_v3_reservation_line')
            ->where('tenant_id', $tenantId)
            ->whereIn('reservation_id', $reservationIds)
            ->field('reservation_id,project_name_snapshot,id')
            ->order('reservation_id asc,id asc')
            ->select());
        $names = [];
        foreach ($lines as $line) {
            $reservationId = (int)($line['reservation_id'] ?? 0);
            $name = trim((string)($line['project_name_snapshot'] ?? ''));
            if ($reservationId > 0 && $name !== '') $names[$reservationId][$name] = $name;
        }
        $result = [];
        foreach ($names as $reservationId => $items) $result[$reservationId] = implode('、', array_values($items));
        return $result;
    }

    /** @param int[] $reservationIds @return array<int,int> */
    private function startedAt(string $tenantId, array $reservationIds): array
    {
        $operations = $this->rows(Db::name('cashier_v3_reservation_operation')
            ->where('tenant_id', $tenantId)
            ->whereIn('reservation_id', $reservationIds)
            ->where('operation_type', 'START_SERVICE')
            ->field('reservation_id,occurred_at,id')
            ->order('reservation_id asc,occurred_at desc,id desc')
            ->select());
        $result = [];
        foreach ($operations as $operation) {
            $reservationId = (int)($operation['reservation_id'] ?? 0);
            if ($reservationId > 0 && !isset($result[$reservationId])) $result[$reservationId] = (int)($operation['occurred_at'] ?? 0);
        }
        return $result;
    }

    private static function formatTime(int $timestamp): string
    {
        if ($timestamp <= 0) return '';
        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone(self::BUSINESS_TIMEZONE))
            ->format('Y-m-d H:i');
    }

    private function rows($rows): array
    {
        return is_object($rows) && method_exists($rows, 'toArray') ? $rows->toArray() : (array)$rows;
    }
}
