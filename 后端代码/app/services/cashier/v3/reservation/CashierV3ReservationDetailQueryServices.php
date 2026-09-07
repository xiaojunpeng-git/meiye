<?php

namespace app\services\cashier\v3\reservation;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use think\facade\Db;

/** Reads one V3 reservation from its authoritative header and child facts. */
final class CashierV3ReservationDetailQueryServices
{
    private const BUSINESS_TIMEZONE = 'Asia/Shanghai';

    public function read(
        array $payload,
        CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $dataScope
    ): ?array {
        $reservationId = (int)($payload['reservationId'] ?? $payload['appointmentId'] ?? $payload['id'] ?? 0);
        if ($reservationId <= 0) return null;

        $tenantId = $dataScope->tenantId();
        $storeId = $operator->storeId();
        if ($dataScope->requiresStoreSetGate() && !$dataScope->allowsStore($storeId)) return null;
        $reservation = Db::name('cashier_v3_reservation')
            ->where('id', $reservationId)
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('lifecycle_generation', CashierV3ReservationLifecycleServices::GENERATION)
            ->find();
        if (!$reservation) return null;
        if (!in_array((string)($reservation['status'] ?? ''), ['PENDING_CONFIRMATION', 'UNSTARTED', 'IN_SERVICE', 'COMPLETED', 'CANCELLED', 'REJECTED'], true)) return null;

        $lines = $this->rows(Db::name('cashier_v3_reservation_line')
            ->where('tenant_id', $tenantId)
            ->where('reservation_id', $reservationId)
            ->order('id asc')
            ->select());
        if (!$lines) return null;

        $serviceOrderId = (int)($reservation['service_order_id'] ?? 0);
        $serviceOrder = $serviceOrderId > 0
            ? Db::name('cashier_v3_service_order')
                ->where('id', $serviceOrderId)
                ->where('tenant_id', $tenantId)
                ->where('business_store_id', $storeId)
                ->find()
            : null;
        $serviceLines = $serviceOrder
            ? $this->rows(Db::name('cashier_v3_service_order_line')
                ->where('tenant_id', $tenantId)
                ->where('service_order_id', $serviceOrderId)
                ->order('id asc')
                ->select())
            : [];

        $plannedStaffIds = [];
        foreach ($lines as $line) {
            foreach ($this->positiveIds($line['artisan_staff_ids_json'] ?? '[]') as $staffId) {
                $plannedStaffIds[$staffId] = $staffId;
            }
        }
        $plannedStaff = $plannedStaffIds
            ? Db::name('system_store_staff')
                ->where('store_id', $storeId)
                ->whereIn('id', array_values($plannedStaffIds))
                ->field('id,employee_id,staff_name,status,is_del')
                ->select()
            : [];
        $plannedStaff = $this->rows($plannedStaff);

        $operations = $this->rows(Db::name('cashier_v3_reservation_operation')
            ->where('tenant_id', $tenantId)
            ->where('reservation_id', $reservationId)
            ->order('occurred_at asc,id asc')
            ->select());
        $projectOutcomes = $this->projectOutcomes($reservation, $lines, $operations);

        $startAt = (int)($reservation['appointment_start_at'] ?? 0);
        $endAt = (int)($reservation['appointment_end_at'] ?? 0);
        $actualStartAt = (int)($reservation['actual_service_started_at'] ?? $serviceOrder['service_started_at'] ?? 0);
        $actualEndAt = (int)($reservation['actual_service_ended_at'] ?? $serviceOrder['completed_at'] ?? 0);
        $plannedDurationSeconds = max(60, $endAt - $startAt);
        $version = (int)($reservation['version'] ?? 0);

        $status = (string)($reservation['status'] ?? '');
        return [
            'detailReady' => true,
            'id' => $reservationId,
            'reservationId' => $reservationId,
            'reservationNo' => (string)($reservation['reservation_no'] ?? ''),
            'revision' => $version,
            'reservationVersion' => $version,
            'status' => $status,
            'statusLabel' => self::statusLabel($status),
            'member' => [
                'id' => (int)($reservation['member_id'] ?? 0),
                'name' => (string)($reservation['member_name_snapshot'] ?? ''),
                'phone' => (string)($reservation['member_phone_snapshot'] ?? ''),
            ],
            'projects' => array_map(function (array $line) use ($projectOutcomes): array {
                $minutes = max(0, (int)($line['service_duration_minutes'] ?? 0));
                $lineId = (int)($line['id'] ?? 0);
                return array_merge([
                    'id' => (int)($line['id'] ?? 0),
                    'projectId' => (int)($line['project_id'] ?? 0),
                    'name' => (string)($line['project_name_snapshot'] ?? ''),
                    'source' => strtoupper((string)($line['project_source'] ?? '')) === 'ENTITLEMENT' ? 'card' : 'unpaid',
                    'role' => strtoupper((string)($line['role_code'] ?? '')) === 'MAIN' ? 'main' : 'detail',
                    'isMain' => strtoupper((string)($line['role_code'] ?? '')) === 'MAIN',
                    'quantity' => max(1, (int)($line['quantity'] ?? 1)),
                    'appliedDurationMinutes' => $minutes,
                    'appliedDurationLabel' => $minutes > 0 ? ($minutes . '分钟') : '',
                    'durationDescription' => $minutes > 0 ? '预约创建时采用的服务时长。' : '',
                ], (array)($projectOutcomes[$lineId] ?? []));
            }, $lines),
            'appointmentStartAt' => self::formatTime($startAt),
            'appointmentEndAt' => self::formatTime($endAt),
            'estimatedStartAt' => self::formatTime($startAt),
            'estimatedEndAt' => self::formatTime($endAt),
            'actualStartAt' => self::formatTime($actualStartAt),
            'actualEndAt' => self::formatTime($actualEndAt),
            // Keep the existing formatted fields above for cashier V3 while
            // exposing epoch aliases consumed by the merchant/mobile timer.
            'actualStartedAt' => $actualStartAt,
            'actualServiceStartedAt' => $actualStartAt,
            'actualServiceEndedAt' => $actualEndAt,
            'actualStartTimestamp' => $actualStartAt,
            'actualEndTimestamp' => $actualEndAt,
            'serviceCountdownEndsAt' => $actualStartAt > 0 ? $actualStartAt + $plannedDurationSeconds : 0,
            'expectedEndAt' => $actualStartAt > 0 ? $actualStartAt + $plannedDurationSeconds : 0,
            'totalDurationSeconds' => $plannedDurationSeconds,
            'isOvertime' => $actualStartAt > 0 && $actualEndAt <= 0 && time() > $actualStartAt + $plannedDurationSeconds,
            'plannedCraftsmen' => array_map(static function (array $staff): array {
                return [
                    'staffId' => (int)($staff['id'] ?? 0),
                    'employeeId' => (int)($staff['employee_id'] ?? 0),
                    'name' => (string)($staff['staff_name'] ?? ''),
                    'statusLabel' => ((int)($staff['status'] ?? 0) === 1 && (int)($staff['is_del'] ?? 0) === 0) ? '在职' : '历史人员',
                ];
            }, $plannedStaff),
            'actualCraftsmen' => $this->actualCraftsmen($serviceLines),
            'room' => (int)($reservation['room_id'] ?? 0) > 0 ? [
                'id' => (int)$reservation['room_id'],
                'name' => (string)($reservation['room_name_snapshot'] ?? ''),
            ] : null,
            'roomChanges' => [],
            'remark' => (string)($reservation['remark_snapshot'] ?? ''),
            'sourceType' => (string)($reservation['source_type'] ?? ''),
            'rejectReason' => (string)($reservation['reject_reason'] ?? ''),
            'relatedRecords' => [
                'hangOrders' => [],
                'serviceOrders' => $serviceOrder ? [[
                    'id' => $serviceOrderId,
                    'serviceNo' => (string)($serviceOrder['service_order_no'] ?? $reservation['service_order_no_snapshot'] ?? ''),
                    'status' => (string)($serviceOrder['status'] ?? ''),
                    'createdAt' => self::formatTime((int)($serviceOrder['created_at'] ?? 0)),
                ]] : [],
                'writeoffs' => [],
                'salesOrders' => [],
            ],
            'timeline' => array_map(function (array $operation): array {
                return [
                    'id' => (int)($operation['id'] ?? 0),
                    'title' => self::operationLabel((string)($operation['operation_type'] ?? '')),
                    'occurredAt' => self::formatTime((int)($operation['occurred_at'] ?? 0)),
                ];
            }, $operations),
            'actions' => self::actions($status),
        ];
    }

    /**
     * Resolve each project from authoritative reservation, occupation and
     * write-off facts. The completed state is historical: later debt repayment
     * must not rewrite what happened when service ended.
     *
     * @param array<string,mixed> $reservation
     * @param array<int,array<string,mixed>> $lines
     * @param array<int,array<string,mixed>> $operations
     * @return array<int,array<string,mixed>>
     */
    private function projectOutcomes(array $reservation, array $lines, array $operations): array
    {
        $tenantId = (string)($reservation['tenant_id'] ?? '');
        $reservationId = (int)($reservation['id'] ?? 0);
        $status = (string)($reservation['status'] ?? '');
        $occupations = $this->rows(Db::name('cashier_v3_reservation_entitlement_occupation')
            ->where('tenant_id', $tenantId)->where('reservation_id', $reservationId)
            ->order('reservation_line_id asc,id asc')->select());
        $occupationByLine = [];
        $holderIds = [];
        foreach ($occupations as $occupation) {
            $lineId = (int)($occupation['reservation_line_id'] ?? 0);
            if ($lineId <= 0) continue;
            $occupationByLine[$lineId] = $occupation;
            $holderId = (int)($occupation['card_holder_id'] ?? 0);
            if ($holderId > 0) $holderIds[$holderId] = $holderId;
        }
        $holderNames = [];
        if ($holderIds) {
            foreach ($this->rows(Db::name('user_card_holder')->whereIn('id', array_values($holderIds))
                ->field('id,card_name')->select()) as $holder) {
                $holderNames[(int)$holder['id']] = trim((string)($holder['card_name'] ?? ''));
            }
        }

        $writeoffByLine = [];
        foreach ($this->rows(Db::name('cashier_v3_entitlement_writeoff_fact')
            ->where('tenant_id', $tenantId)->where('checkout_request_id', 'reservation:' . $reservationId)
            ->where('status', 'effective')->order('id asc')->select()) as $writeoff) {
            if (!preg_match('/:(\d+)$/', (string)($writeoff['source_line_id'] ?? ''), $matches)) continue;
            $writeoffByLine[(int)$matches[1]] = $writeoff;
        }

        $debtSnapshotByLine = [];
        foreach ($operations as $operation) {
            if ((string)($operation['operation_type'] ?? '') !== 'END_SERVICE') continue;
            $result = json_decode((string)($operation['result_json'] ?? ''), true);
            $blocked = is_array($result) ? (array)($result['facts']['debtBlockedEntitlements'] ?? []) : [];
            foreach ($blocked as $item) {
                $lineId = (int)($item['reservationLineId'] ?? 0);
                if ($lineId > 0) $debtSnapshotByLine[$lineId] = (array)$item;
            }
        }

        $outcomes = [];
        foreach ($lines as $line) {
            $lineId = (int)($line['id'] ?? 0);
            $isEntitlement = strtoupper((string)($line['project_source'] ?? '')) === 'ENTITLEMENT';
            $occupation = (array)($occupationByLine[$lineId] ?? []);
            $writeoff = (array)($writeoffByLine[$lineId] ?? []);
            $debtSnapshot = (array)($debtSnapshotByLine[$lineId] ?? []);
            $holderId = (int)($occupation['card_holder_id'] ?? 0);
            $cardName = trim((string)($debtSnapshot['cardName'] ?? $writeoff['source_name_snapshot'] ?? ($holderNames[$holderId] ?? '')));

            if (!$isEntitlement) {
                $outcomes[$lineId] = [
                    'processingStatus' => 'registration_only',
                    'processingStatusLabel' => '仅预约登记',
                    'processingDescription' => $status === 'COMPLETED'
                        ? '服务已结束；该项目未关联已购权益，本次不扣卡项次数。'
                        : '该项目未购买，仅登记本次预约，不占用或扣除卡项次数。',
                    'sourceCardName' => '',
                    'entitlementDeducted' => false,
                ];
                continue;
            }
            if ($status === 'COMPLETED' && ($writeoff || (string)($occupation['status'] ?? '') === 'CONSUMED')) {
                $outcomes[$lineId] = [
                    'processingStatus' => 'entitlement_deducted',
                    'processingStatusLabel' => '已扣权益',
                    'processingDescription' => '服务已结束，已从卡项「' . ($cardName ?: '对应卡项') . '」扣除 '
                        . max(1, (int)($occupation['occupied_times'] ?? $line['quantity'] ?? 1)) . ' 次。',
                    'sourceCardName' => $cardName,
                    'entitlementDeducted' => true,
                ];
                continue;
            }
            if ($status === 'COMPLETED' && ($debtSnapshot || (string)($occupation['status'] ?? '') === 'RELEASED')) {
                $outcomes[$lineId] = [
                    'processingStatus' => 'debt_blocked',
                    'processingStatusLabel' => '欠款未扣权益',
                    'processingDescription' => '服务已结束；卡项「' . ($cardName ?: '对应卡项') . '」有欠款，未扣除权益，请手动处理。',
                    'sourceCardName' => $cardName,
                    'entitlementDeducted' => false,
                ];
                continue;
            }
            if (in_array($status, ['CANCELLED', 'REJECTED'], true)) {
                $outcomes[$lineId] = [
                    'processingStatus' => 'not_processed',
                    'processingStatusLabel' => '未产生扣减',
                    'processingDescription' => '预约已取消或拒绝，未扣除已购权益。',
                    'sourceCardName' => $cardName,
                    'entitlementDeducted' => false,
                ];
                continue;
            }
            $outcomes[$lineId] = [
                'processingStatus' => 'pending_entitlement',
                'processingStatusLabel' => '待服务后扣权益',
                'processingDescription' => '已关联卡项「' . ($cardName ?: '对应卡项') . '」，服务结束时按卡项状态处理权益。',
                'sourceCardName' => $cardName,
                'entitlementDeducted' => null,
            ];
        }
        return $outcomes;
    }

    private function actualCraftsmen(array $serviceLines): array
    {
        $items = [];
        foreach ($serviceLines as $line) {
            $employeeId = (int)($line['artisan_employee_id'] ?? 0);
            $name = trim((string)($line['artisan_name_snapshot'] ?? ''));
            if ($employeeId <= 0 || $name === '') continue;
            $items[$employeeId] = ['employeeId' => $employeeId, 'name' => $name];
        }
        return array_values($items);
    }

    private function positiveIds($json): array
    {
        $values = is_array($json) ? $json : json_decode((string)$json, true);
        $ids = [];
        foreach (is_array($values) ? $values : [] as $value) {
            $id = (int)$value;
            if ($id > 0) $ids[$id] = $id;
        }
        return array_values($ids);
    }

    private function rows($rows): array
    {
        return is_object($rows) && method_exists($rows, 'toArray') ? $rows->toArray() : (array)$rows;
    }

    private static function formatTime(int $timestamp): string
    {
        if ($timestamp <= 0) return '';
        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone(self::BUSINESS_TIMEZONE))
            ->format('Y-m-d H:i');
    }

    private static function statusLabel(string $status): string
    {
        return [
            'PENDING_CONFIRMATION' => '待确认',
            'UNSTARTED' => '待服务',
            'IN_SERVICE' => '服务中',
            'COMPLETED' => '已结束',
            'CANCELLED' => '已取消',
            'REJECTED' => '已拒绝',
            'NO_SHOW' => '已取消',
        ][$status] ?? '状态未知';
    }

    private static function actions(string $status): array
    {
        if ($status === 'PENDING_CONFIRMATION') {
            return [
                ['action' => 'confirm-reservation', 'label' => '确认', 'enabled' => true],
                ['action' => 'reject-reservation', 'label' => '拒绝', 'enabled' => true],
            ];
        }
        if ($status === 'UNSTARTED') {
            return [
                ['action' => 'edit-reservation', 'label' => '编辑', 'enabled' => true],
                ['action' => 'cancel-reservation', 'label' => '删除', 'enabled' => true],
                ['action' => 'start-reservation-service', 'label' => '开始服务', 'enabled' => true],
            ];
        }
        if ($status === 'IN_SERVICE') {
            return [['action' => 'end-reservation-service', 'label' => '结束服务', 'enabled' => true]];
        }
        return [];
    }

    private static function operationLabel(string $operation): string
    {
        return [
            'CREATE' => '创建预约',
            'CONFIRM' => '确认预约',
            'START_SERVICE' => '开始服务',
            'CANCEL' => '取消预约',
            'REJECT' => '拒绝预约',
            'MARK_NO_SHOW' => '标记爽约',
            'UPDATE' => '更新预约',
            'END_SERVICE' => '结束服务',
        ][$operation] ?? '预约状态更新';
    }
}
