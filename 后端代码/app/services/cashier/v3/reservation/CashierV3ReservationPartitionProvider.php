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
        $rows = Db::name('cashier_v3_reservation')
            ->where('tenant_id', $tenantId)->where('store_id', $storeId)
            ->order('appointment_start_at desc,id desc')->limit(100)->select();
        $rows = is_object($rows) && method_exists($rows, 'toArray') ? $rows->toArray() : (array)$rows;
        $ids = [];
        foreach ($rows as $row) $ids[] = (int)($row['id'] ?? 0);
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
        $counts = ['today' => 0, 'pending' => 0, 'serving' => 0];
        $today = date('Y-m-d');
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
            $status = self::statusLabel((string)($row['status'] ?? ''));
            $dateTime = (int)($row['appointment_start_at'] ?? 0);
            if ($dateTime > 0 && date('Y-m-d', $dateTime) === $today) $counts['today']++;
            if ($status === '待确认') $counts['pending']++;
            if ($status === '服务中') $counts['serving']++;
            // reservation is a domain-owned source version. It is not a
            // central synthetic row: the V3 reservation header is the only
            // authority, so list/detail actions receive the header revision.
            $version = (int)($row['version'] ?? 0);
            if ($version > 0) $versions[] = ['kind' => 'reservation', 'id' => (string)$id, 'version' => $version];
            $records[] = [
                'id' => $id,
                'reservationId' => $id,
                'reservationNo' => (string)($row['reservation_no'] ?? ''),
                'appointmentTime' => $dateTime > 0 ? date('Y-m-d H:i', $dateTime) : '',
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
                'serviceOrderId' => (int)($row['service_order_id'] ?? 0),
                'primaryAction' => ['action' => 'open-reservation-detail', 'label' => '查看详情', 'enabled' => true],
            ];
        }
        return ['ready' => true, 'payload' => [
            'availability' => ['contractVersion' => 'cashier-v3-reservation-v1', 'status' => 'active', 'reasonCode' => '', 'dataLoaded' => true, 'businessFactsIncluded' => true],
            'quickCounts' => $counts, 'records' => $records, 'total' => count($records), 'page' => 1, 'pageSize' => 20,
            'detail' => null, 'editor' => ['draft' => new \stdClass(), 'catalogOptions' => [], 'craftsmenOptions' => [], 'rooms' => []],
            'calendar' => ['date' => date('Y-m-d'), 'resources' => [], 'blocks' => [], 'timeSlots' => []],
        ], 'public_versions' => $versions];
    }

    private static function statusLabel(string $status): string
    {
        return ['PENDING_CONFIRMATION' => '待确认', 'CONFIRMED' => '已确认', 'IN_SERVICE' => '服务中', 'PENDING_CHECKOUT' => '待结账', 'CANCELLED' => '已取消', 'NO_SHOW' => '爽约'][$status] ?? '待确认';
    }
}
