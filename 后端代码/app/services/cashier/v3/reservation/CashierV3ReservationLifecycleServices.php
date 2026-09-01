<?php

declare(strict_types=1);

namespace app\services\cashier\v3\reservation;

use app\services\cashier\v3\CashierV3BusinessDocumentNumberServices;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\store\StoreReservationStaffServices;
use think\facade\Db;

/**
 * Transaction-only lifecycle collaborator for the new reservation generation.
 *
 * The reservation header stays authoritative.  This class owns only the
 * entitlement occupation and the service-order facts derived from it.
 */
final class CashierV3ReservationLifecycleServices
{
    public const GENERATION = 'RESERVATION_V3_20260901';
    public const SOURCE_MEMBER = 'MEMBER';
    public const SOURCE_STORE = 'STORE';

    public function occupyLinesInTx(string $tenantId, int $reservationId, int $memberId, int $storeId, array $lines, int $now): void
    {
        CashierV3TransactionGuard::assertInTransaction('reservationLifecycle.occupy');
        foreach ($lines as $line) {
            if (strtoupper((string)($line['project_source'] ?? '')) !== 'ENTITLEMENT') continue;
            $detailId = (int)($line['entitlement_source_detail_id'] ?? 0);
            $quantity = max(1, (int)($line['quantity'] ?? 1));
            if ($detailId <= 0) throw $this->failure('已购项目缺少权益明细。');

            $this->lockGuard($tenantId, $detailId, $now);
            $detail = Db::name('store_order_cart_info')->alias('c')
                ->join('store_order o', 'o.id=c.oid')
                ->where('c.id', $detailId)->where('o.uid', $memberId)
                // The service store may differ from the card's issuing store
                // when the configured cross-store rule permits it. The paid
                // order and member remain the entitlement authority.
                ->where('o.paid', 1)
                ->where('o.is_del', 0)->where('o.is_system_del', 0)->where('o.is_user_del', 0)
                ->where('o.refund_status', 0)->where('o.terminal_action', 0)
                ->field('c.id,c.oid,c.write_surplus_times,c.is_writeoff,c.write_start,c.write_end')
                ->lock(true)->find();
            if (!$detail) throw $this->failure('已购项目不存在或已不可用。');
            $start = (int)($detail['write_start'] ?? 0);
            $end = (int)($detail['write_end'] ?? 0);
            if ((int)($detail['is_writeoff'] ?? 0) === 1 || ($start > 0 && $start > $now) || ($end > 0 && $end < $now)) {
                throw $this->failure('已购项目不在有效期内。');
            }
            $active = (int)Db::name('cashier_v3_reservation_entitlement_occupation')
                ->where('tenant_id', $tenantId)->where('entitlement_source_detail_id', $detailId)
                ->where('status', 'ACTIVE')->sum('occupied_times');
            if ((int)$detail['write_surplus_times'] - $active < $quantity) {
                throw $this->failure('已购项目可用次数不足。');
            }
            $holder = Db::name('user_card_holder')->where('oid', (int)$detail['oid'])
                ->where('uid', $memberId)->where('is_del', 0)
                ->lock(true)->find();
            $holderActive = $holder ? (int)Db::name('cashier_v3_reservation_entitlement_occupation')
                ->where('tenant_id', $tenantId)->where('card_holder_id', (int)$holder['id'])
                ->where('status', 'ACTIVE')->sum('occupied_times') : 0;
            if (!$holder || (int)($holder['write_surplus_times'] ?? 0) - $holderActive < $quantity) {
                throw $this->failure('卡项可用次数不足。');
            }
            Db::name('cashier_v3_reservation_entitlement_occupation')->insert([
                'tenant_id' => $tenantId,
                'reservation_id' => $reservationId,
                'reservation_line_id' => (int)$line['id'],
                'entitlement_source_detail_id' => $detailId,
                'card_holder_id' => (int)$holder['id'],
                'occupied_times' => $quantity,
                'status' => 'ACTIVE',
                'version' => 1,
                'released_at' => 0,
                'consumed_at' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->bumpGuard($tenantId, $detailId, 'reservation_occupied', $now);
        }
    }

    /** Serialize staff schedules and recheck the authoritative V3 calendar. */
    public function assertStaffAvailabilityInTx(int $storeId, int $reservationId, array $staffIds, int $startAt, int $endAt): void
    {
        CashierV3TransactionGuard::assertInTransaction('reservationLifecycle.staffAvailability');
        $staffIds = array_values(array_unique(array_filter(array_map('intval', $staffIds))));
        sort($staffIds, SORT_NUMERIC);
        if (!$staffIds) return;
        if ($storeId <= 0 || $startAt <= 0 || $endAt <= $startAt) throw $this->failure('预约时间无效。');
        foreach ($staffIds as $staffId) {
            $staff = Db::name('system_store_staff')->where('id', $staffId)->where('status', 1)->where('is_del', 0)->lock(true)->find();
            if (!$staff || ((int)$staff['store_id'] !== $storeId && (int)($staff['can_choose'] ?? 0) !== 1)) {
                throw $this->failure('预约手艺人不存在或不属于当前门店。');
            }
        }
        /** @var StoreReservationStaffServices $availability */
        $availability = app()->make(StoreReservationStaffServices::class);
        $date = date('Y-m-d', $startAt);
        $clock = date('H:i', $startAt);
        $durationMinutes = max(1, (int)ceil(($endAt - $startAt) / 60));
        foreach ($staffIds as $staffId) {
            if (!$availability->isStaffSelectableForReservation($staffId, $storeId, $date, $clock, $durationMinutes, $reservationId)) {
                throw $this->failure('手艺人在该时段不可预约，请重新选择。');
            }
        }
    }

    /** Serialize one room's schedule and reject overlapping active V3 reservations. */
    public function assertRoomAvailabilityInTx(string $tenantId, int $storeId, int $reservationId, int $roomId, int $startAt, int $endAt): void
    {
        CashierV3TransactionGuard::assertInTransaction('reservationLifecycle.roomAvailability');
        if ($roomId <= 0) return;
        if ($storeId <= 0 || $startAt <= 0 || $endAt <= $startAt) throw $this->failure('预约时间无效。');
        $room = Db::name('table_qrcode')->where('id', $roomId)->where('store_id', $storeId)
            ->where('is_del', 0)->where('is_using', 1)->lock(true)->find();
        if (!$room) throw $this->failure('预约房间不存在或已停用。');
        $query = Db::name('cashier_v3_reservation')->where('tenant_id', $tenantId)->where('store_id', $storeId)
            ->where('lifecycle_generation', self::GENERATION)->where('room_id', $roomId)
            ->whereIn('status', ['PENDING_CONFIRMATION', 'UNSTARTED', 'IN_SERVICE'])
            ->where('appointment_start_at', '<', $endAt)->where('appointment_end_at', '>', $startAt);
        if ($reservationId > 0) $query->where('id', '<>', $reservationId);
        if ($query->field('id')->lock(true)->find()) throw $this->failure('房间在该时段已被预约，请重新选择。');
    }

    public function releaseInTx(string $tenantId, int $reservationId, int $now): void
    {
        CashierV3TransactionGuard::assertInTransaction('reservationLifecycle.release');
        $rows = $this->rows(Db::name('cashier_v3_reservation_entitlement_occupation')
            ->where('tenant_id', $tenantId)->where('reservation_id', $reservationId)
            ->where('status', 'ACTIVE')->order('entitlement_source_detail_id asc,id asc')->select());
        foreach ($rows as $row) {
            $detailId = (int)$row['entitlement_source_detail_id'];
            $this->lockGuard($tenantId, $detailId, $now);
            $affected = Db::name('cashier_v3_reservation_entitlement_occupation')
                ->where('id', (int)$row['id'])->where('tenant_id', $tenantId)
                ->where('status', 'ACTIVE')->where('version', (int)$row['version'])
                ->update(['status' => 'RELEASED', 'version' => (int)$row['version'] + 1, 'released_at' => $now, 'updated_at' => $now]);
            if ((int)$affected !== 1) throw $this->failure('预约权益占用已变更，请刷新后重试。');
            $this->bumpGuard($tenantId, $detailId, 'reservation_released', $now);
        }
    }

    public function startServiceInTx(array $header, array $lines, int $operatorStaffId, int $operatorEmployeeId, string $operatorName, int $now): array
    {
        CashierV3TransactionGuard::assertInTransaction('reservationLifecycle.startService');
        $tenantId = (string)$header['tenant_id'];
        $reservationId = (int)$header['id'];
        $existingId = (int)($header['service_order_id'] ?? 0);
        if ($existingId > 0) {
            $existing = Db::name('cashier_v3_service_order')->where('tenant_id', $tenantId)->where('id', $existingId)->lock(true)->find();
            if ($existing) return $existing;
        }

        $businessDate = date('Y-m-d', $now);
        $identity = 'reservation:' . $reservationId;
        $serviceNo = (new CashierV3BusinessDocumentNumberServices())->allocateForSourceInTx(
            $tenantId,
            CashierV3BusinessDocumentNumberServices::SERVICE,
            'reservation_service',
            $identity,
            $businessDate,
            $now
        );
        $participantIds = [];
        foreach ($lines as $line) {
            foreach ($this->positiveIds($line['artisan_staff_ids_json'] ?? '[]') as $staffId) {
                $employeeId = (int)Db::name('system_store_staff')->where('id', $staffId)->value('employee_id');
                if ($employeeId > 0) $participantIds[$employeeId] = $employeeId;
            }
        }
        $serviceId = (int)Db::name('cashier_v3_service_order')->insertGetId([
            'service_order_no' => $serviceNo,
            'tenant_id' => $tenantId,
            'organization_id' => (string)$header['organization_id'],
            'organization_path' => (string)$header['organization_path'],
            'organization_name_snapshot' => (string)$header['organization_name_snapshot'],
            'business_store_id' => (int)$header['store_id'],
            'business_store_name_snapshot' => (string)$header['store_name_snapshot'],
            'member_id' => (int)$header['member_id'],
            'member_name_snapshot' => (string)$header['member_name_snapshot'],
            'source_type' => 'RESERVATION',
            'source_id' => $reservationId,
            'source_no_snapshot' => (string)$header['reservation_no'],
            'source_version_snapshot' => (int)$header['version'],
            'room_id' => (int)$header['room_id'],
            'room_name_snapshot' => (string)$header['room_name_snapshot'],
            'participant_employee_ids_json' => $this->json(array_values($participantIds)),
            'status' => 'OPEN',
            'version' => 1,
            'business_date' => $businessDate,
            'service_started_at' => $now,
            'pending_checkout_at' => 0,
            'completed_at' => 0,
            'cancelled_at' => 0,
            'voided_at' => 0,
            'occurred_at' => $now,
            'recorded_at' => $now,
            'created_by_staff_id' => $operatorStaffId,
            'created_by_employee_id' => $operatorEmployeeId,
            'created_by_name_snapshot' => mb_substr($operatorName, 0, 64),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($serviceId <= 0) throw new \RuntimeException('reservation_service_order_insert_failed');

        foreach ($lines as $line) {
            $staffIds = $this->positiveIds($line['artisan_staff_ids_json'] ?? '[]');
            $staffId = $staffIds[0] ?? 0;
            $staff = $staffId > 0 ? Db::name('system_store_staff')->where('id', $staffId)->field('employee_id,staff_name')->find() : [];
            $isEntitlement = strtoupper((string)$line['project_source']) === 'ENTITLEMENT';
            $fingerprint = hash('sha256', $tenantId . ':' . $reservationId . ':' . (int)$line['id']);
            $serviceLineId = (int)Db::name('cashier_v3_service_order_line')->insertGetId([
                'tenant_id' => $tenantId,
                'service_order_id' => $serviceId,
                'line_key' => 'reservation:' . $reservationId . ':' . (int)$line['id'],
                'source_type' => $isEntitlement ? 'ENTITLEMENT' : 'SALE_PROJECT',
                'source_id' => (int)$line['id'],
                'source_version_snapshot' => 1,
                'hang_line_id' => 0,
                'service_quantity' => max(1, (int)$line['quantity']),
                'authority_fingerprint' => $fingerprint,
                'entitlement_source_detail_id' => $isEntitlement ? (int)$line['entitlement_source_detail_id'] : 0,
                'entitlement_instance_id' => 0,
                'project_id' => (int)$line['project_id'],
                'project_name_snapshot' => (string)$line['project_name_snapshot'],
                'occupied_times' => 0,
                'service_target' => 'SELF',
                'is_experience' => 0,
                'artisan_staff_id' => $staffId,
                'artisan_employee_id' => (int)($staff['employee_id'] ?? 0),
                'artisan_name_snapshot' => (string)($staff['staff_name'] ?? ''),
                'status' => 'ACTIVE',
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            Db::name('cashier_v3_reservation_line')->where('id', (int)$line['id'])->where('tenant_id', $tenantId)
                ->update(['service_order_line_id' => $serviceLineId, 'updated_at' => $now]);
        }
        return ['id' => $serviceId, 'service_order_no' => $serviceNo, 'service_started_at' => $now];
    }

    public function consumeInTx(array $header, int $now, array $execution): array
    {
        CashierV3TransactionGuard::assertInTransaction('reservationLifecycle.consume');
        $tenantId = (string)$header['tenant_id'];
        $reservationId = (int)$header['id'];
        $occupations = $this->rows(Db::name('cashier_v3_reservation_entitlement_occupation')
            ->where('tenant_id', $tenantId)->where('reservation_id', $reservationId)
            ->where('status', 'ACTIVE')->order('entitlement_source_detail_id asc,id asc')->select());
        $lines = $this->rows(Db::name('cashier_v3_reservation_line')->where('tenant_id', $tenantId)
            ->where('reservation_id', $reservationId)->order('id asc')->lock(true)->select());
        $facts = (new CashierV3ReservationCompletionFactServices())->persistInTx($header, $lines, $occupations, $execution, $now);
        foreach ($occupations as $occupation) {
            $detailId = (int)$occupation['entitlement_source_detail_id'];
            $holderId = (int)$occupation['card_holder_id'];
            $quantity = (int)$occupation['occupied_times'];
            $this->lockGuard($tenantId, $detailId, $now);
            $detail = Db::name('store_order_cart_info')->where('id', $detailId)->lock(true)->find();
            $holder = Db::name('user_card_holder')->where('id', $holderId)->lock(true)->find();
            if (!$detail || !$holder || (int)$detail['write_surplus_times'] < $quantity || (int)$holder['write_surplus_times'] < $quantity) {
                throw $this->failure('卡项剩余次数已变更，无法结束服务。');
            }
            $detailRemain = (int)$detail['write_surplus_times'] - $quantity;
            $holderRemain = (int)$holder['write_surplus_times'] - $quantity;
            Db::name('store_order_cart_info')->where('id', $detailId)->update([
                'write_surplus_times' => $detailRemain,
                'is_writeoff' => $detailRemain === 0 ? 1 : 0,
            ]);
            Db::name('user_card_holder')->where('id', $holderId)->update(['write_surplus_times' => $holderRemain]);
            $affected = Db::name('cashier_v3_reservation_entitlement_occupation')
                ->where('id', (int)$occupation['id'])->where('tenant_id', $tenantId)
                ->where('status', 'ACTIVE')->where('version', (int)$occupation['version'])
                ->update(['status' => 'CONSUMED', 'version' => (int)$occupation['version'] + 1, 'consumed_at' => $now, 'updated_at' => $now]);
            if ((int)$affected !== 1) throw $this->failure('预约权益占用已变更，请刷新后重试。');
            $this->bumpGuard($tenantId, $detailId, 'reservation_consumed', $now);
        }

        $serviceOrderId = (int)($header['service_order_id'] ?? 0);
        if ($serviceOrderId > 0) {
            $service = Db::name('cashier_v3_service_order')->where('tenant_id', $tenantId)->where('id', $serviceOrderId)->lock(true)->find();
            if (!$service || (string)$service['status'] !== 'OPEN') throw $this->failure('服务单状态已变更，请刷新后重试。');
            Db::name('cashier_v3_service_order')->where('tenant_id', $tenantId)->where('id', $serviceOrderId)->where('version', (int)$service['version'])
                ->update(['status' => 'COMPLETED', 'version' => (int)$service['version'] + 1, 'completed_at' => $now, 'updated_at' => $now]);
            Db::name('cashier_v3_service_order_line')->where('tenant_id', $tenantId)->where('service_order_id', $serviceOrderId)
                ->where('status', 'ACTIVE')->update(['status' => 'COMPLETED', 'updated_at' => $now]);
        }
        return $facts;
    }

    private function lockGuard(string $tenantId, int $detailId, int $now): array
    {
        Db::execute(
            'INSERT IGNORE INTO `eb_cashier_v3_service_order_entitlement_guard` (`tenant_id`,`entitlement_source_detail_id`,`current_version`,`last_action`,`created_at`,`updated_at`) VALUES (?,?,1,?,?,?)',
            [$tenantId, $detailId, 'reservation_guard_created', $now, $now]
        );
        $guard = Db::name('cashier_v3_service_order_entitlement_guard')->where('tenant_id', $tenantId)
            ->where('entitlement_source_detail_id', $detailId)->lock(true)->find();
        if (!$guard) throw new \RuntimeException('reservation_entitlement_guard_missing');
        return $guard;
    }

    private function bumpGuard(string $tenantId, int $detailId, string $action, int $now): void
    {
        $affected = Db::name('cashier_v3_service_order_entitlement_guard')->where('tenant_id', $tenantId)
            ->where('entitlement_source_detail_id', $detailId)
            ->update(['current_version' => Db::raw('current_version + 1'), 'last_action' => $action, 'updated_at' => $now]);
        if ((int)$affected !== 1) throw new \RuntimeException('reservation_entitlement_guard_bump_failed');
    }

    private function rows($rows): array
    {
        return is_object($rows) && method_exists($rows, 'toArray') ? $rows->toArray() : (array)$rows;
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

    private function json(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) throw new \RuntimeException('reservation_lifecycle_json_failed');
        return $json;
    }

    private function failure(string $message): CashierV3CommandException
    {
        return new CashierV3CommandException(CashierV3ResultCode::INVALID_COMMAND_CONTEXT, $message);
    }
}
