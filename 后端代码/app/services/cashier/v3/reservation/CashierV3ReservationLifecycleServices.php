<?php

declare(strict_types=1);

namespace app\services\cashier\v3\reservation;

use app\services\cashier\v3\CashierV3BusinessDocumentNumberServices;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\cashier\CashierV3CardOriginDebtResolver;
use app\services\cashier\v3\service\ThinkPhpCashierV3ServiceOrderRepository;
use app\services\order\store\WriteOffOrderServices;
use app\services\store\StoreReservationStaffServices;
use think\facade\Db;

/**
 * Transaction-only lifecycle collaborator for the new reservation generation.
 *
 * The reservation header stays authoritative.  This class owns only the
 * live entitlement settlement at service completion and the service-order
 * facts derived from that outcome. Historical occupation rows are released
 * for compatibility only; new reservations never create them.
 */
final class CashierV3ReservationLifecycleServices
{
    public const GENERATION = 'RESERVATION_V3_20260901';
    public const SOURCE_MEMBER = 'MEMBER';
    public const SOURCE_STORE = 'STORE';

    /** @deprecated New V3 reservations must not reserve card quantities. */
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

    /**
     * Creating a time-only appointment is allowed. Creating a service from it
     * is not: at least one real project and one selectable artisan are needed
     * before any service-order row is inserted.
     */
    public function assertServiceStartPrerequisitesInTx(array $header, array $lines, array $staffIds): void
    {
        CashierV3TransactionGuard::assertInTransaction('reservationLifecycle.startPrerequisites');
        if (!$lines) throw $this->failure('开始服务前请至少选择一个项目。');
        foreach ($lines as $line) {
            if ((int)($line['project_id'] ?? 0) <= 0 || (int)($line['quantity'] ?? 0) <= 0) {
                throw $this->failure('预约项目无效，请重新选择后开始服务。');
            }
        }
        $staffIds = array_values(array_unique(array_filter(array_map('intval', $staffIds))));
        if (!$staffIds) throw $this->failure('开始服务前请至少选择一名手艺人。');
        $storeId = (int)($header['store_id'] ?? 0);
        foreach ($staffIds as $staffId) {
            $staff = Db::name('system_store_staff')->where('id', $staffId)->where('status', 1)->where('is_del', 0)->lock(true)->find();
            if (!$staff || ((int)$staff['store_id'] !== $storeId && (int)($staff['can_choose'] ?? 0) !== 1)) {
                throw $this->failure('预约手艺人不存在或不属于当前门店。');
            }
        }
    }

    public function startServiceInTx(array $header, array $lines, int $operatorStaffId, int $operatorEmployeeId, string $operatorName, int $now): array
    {
        CashierV3TransactionGuard::assertInTransaction('reservationLifecycle.startService');
        if (!$lines) throw $this->failure('开始服务前请至少选择一个项目。');
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
        // Persist the appointment's personnel arrangement onto the generated
        // service record.  It is a service snapshot, rather than a later read
        // from the editable appointment relation.
        $staffAssignments = $this->serviceStaffAssignmentsInTx($tenantId, $reservationId, $lines);
        $participantIds = [];
        foreach ($staffAssignments as $assignment) {
            $employeeId = (int)($assignment['employee_id'] ?? 0);
            if ($employeeId > 0) $participantIds[$employeeId] = $employeeId;
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

        foreach ($staffAssignments as $assignment) {
            Db::name('cashier_v3_service_order_staff_assignment')->insert([
                'tenant_id' => $tenantId,
                'service_order_id' => $serviceId,
                'staff_id' => (int)$assignment['staff_id'],
                'employee_id' => (int)$assignment['employee_id'],
                'staff_name_snapshot' => (string)$assignment['staff_name_snapshot'],
                'is_point_customer' => !empty($assignment['is_point_customer']) ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

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
                // An appointment records the selected card project only. It
                // does not become an active checkout occupation.
                'source_type' => $isEntitlement
                    ? ThinkPhpCashierV3ServiceOrderRepository::LINE_SOURCE_RESERVATION_INTENT
                    : ThinkPhpCashierV3ServiceOrderRepository::LINE_SOURCE_SALE_PROJECT,
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

    /** @return array<int,array{staff_id:int,employee_id:int,staff_name_snapshot:string,is_point_customer:bool}> */
    private function serviceStaffAssignmentsInTx(string $tenantId, int $reservationId, array $lines): array
    {
        $scheduled = $this->rows(Db::name('cashier_v3_reservation_staff_schedule')
            ->where('tenant_id', $tenantId)->where('reservation_id', $reservationId)
            ->order('staff_id asc')->lock(true)->select());
        $pointCustomerByStaffId = [];
        $staffIds = [];
        foreach ($scheduled as $row) {
            $staffId = (int)($row['staff_id'] ?? 0);
            if ($staffId <= 0) continue;
            $staffIds[$staffId] = $staffId;
            $pointCustomerByStaffId[$staffId] = !empty($row['is_point_customer']);
        }
        if (!$staffIds) {
            foreach ($lines as $line) {
                foreach ($this->positiveIds($line['artisan_staff_ids_json'] ?? '[]') as $staffId) $staffIds[$staffId] = $staffId;
            }
        }
        if (!$staffIds) return [];
        $staffRows = $this->rows(Db::name('system_store_staff')->whereIn('id', array_values($staffIds))
            ->field('id,employee_id,staff_name')->lock(true)->select());
        $staffById = [];
        foreach ($staffRows as $staff) $staffById[(int)$staff['id']] = $staff;
        $assignments = [];
        foreach ($staffIds as $staffId) {
            $staff = $staffById[$staffId] ?? [];
            $assignments[] = [
                'staff_id' => $staffId,
                'employee_id' => (int)($staff['employee_id'] ?? 0),
                'staff_name_snapshot' => mb_substr((string)($staff['staff_name'] ?? ''), 0, 64),
                'is_point_customer' => !empty($pointCustomerByStaffId[$staffId]),
            ];
        }
        return $assignments;
    }

    public function consumeInTx(array $header, int $now, array $execution): array
    {
        CashierV3TransactionGuard::assertInTransaction('reservationLifecycle.consume');
        $tenantId = (string)$header['tenant_id'];
        $reservationId = (int)$header['id'];
        $lines = $this->rows(Db::name('cashier_v3_reservation_line')->where('tenant_id', $tenantId)
            ->where('reservation_id', $reservationId)->order('id asc')->lock(true)->select());
        $serviceOrderId = (int)($header['service_order_id'] ?? 0);
        $pointCustomerStaffIds = $serviceOrderId > 0
            ? Db::name('cashier_v3_service_order_staff_assignment')
                ->where('tenant_id', $tenantId)->where('service_order_id', $serviceOrderId)
                ->where('is_point_customer', 1)->lock(true)->column('staff_id')
            : Db::name('cashier_v3_reservation_staff_schedule')
                ->where('tenant_id', $tenantId)->where('reservation_id', $reservationId)
                ->where('is_point_customer', 1)->lock(true)->column('staff_id');
        $pointCustomerStaffIds = array_fill_keys($this->positiveIds($pointCustomerStaffIds), true);
        foreach ($lines as &$line) $line['point_customer_staff_ids'] = array_keys($pointCustomerStaffIds);
        unset($line);

        // Legacy reservations may still have occupation rows. They are
        // historical only: release them so they cannot influence a cashier
        // checkout, then make this completion decision solely from the live
        // card balance.
        $this->releaseInTx($tenantId, $reservationId, $now);
        $candidateResult = $this->liveEntitlementCandidatesInTx($header, $lines);
        $candidates = $candidateResult['candidates'];
        // 预约结束服务必须与收银“使用权益”共用欠款折算口径：有欠款不等于
        // 整卡禁用，只有欠款限制后的实时可用次数小于本次数量才阻止扣次。
        $debtLimitedAvailability = $this->debtLimitedAvailabilityInTx($candidates, (int)$header['member_id']);

        $detailRemain = $candidateResult['detailRemain'];
        $holderRemain = $candidateResult['holderRemain'];
        $settledOccupations = [];
        $debtBlockedOccupationIds = [];
        $insufficientEntitlements = $candidateResult['unavailable'];
        foreach ($candidates as $candidate) {
            $candidateId = (int)$candidate['id'];
            $detailId = (int)$candidate['entitlement_source_detail_id'];
            $holderId = (int)$candidate['card_holder_id'];
            $quantity = max(1, (int)$candidate['occupied_times']);
            $available = min((int)($detailRemain[$detailId] ?? 0), (int)($holderRemain[$holderId] ?? 0));
            if ($available < $quantity) {
                $insufficientEntitlements[] = $this->insufficientSnapshot($candidate, $available);
                continue;
            }
            $debtLimitedAvailable = min($available, (int)($debtLimitedAvailability[$candidateId] ?? $available));
            if ($debtLimitedAvailable < $quantity) {
                $debtBlockedOccupationIds[$candidateId] = true;
                continue;
            }
            $candidate['detailSnapshot']['write_surplus_times'] = (int)$detailRemain[$detailId];
            $candidate['holderSnapshot']['write_surplus_times'] = (int)$holderRemain[$holderId];
            $settledOccupations[] = $candidate;
            $detailRemain[$detailId] -= $quantity;
            $holderRemain[$holderId] -= $quantity;
        }
        $facts = (new CashierV3ReservationCompletionFactServices())->persistInTx($header, $lines, $settledOccupations, $execution, $now);
        foreach ($candidateResult['details'] as $detailId => $detail) {
            if (!array_key_exists($detailId, $detailRemain)) continue;
            Db::name('store_order_cart_info')->where('id', $detailId)->update([
                'write_surplus_times' => max(0, (int)$detailRemain[$detailId]),
                'is_writeoff' => (int)$detailRemain[$detailId] === 0 ? 1 : 0,
            ]);
        }
        foreach ($candidateResult['holders'] as $holderId => $holder) {
            if (!array_key_exists($holderId, $holderRemain)) continue;
            Db::name('user_card_holder')->where('id', $holderId)->update([
                'write_surplus_times' => max(0, (int)$holderRemain[$holderId]),
            ]);
        }
        $debtBlockedEntitlements = $this->debtBlockedEntitlementSnapshots(
            $candidates,
            $lines,
            $debtBlockedOccupationIds
        );

        $serviceOrderId = (int)($header['service_order_id'] ?? 0);
        if ($serviceOrderId > 0) {
            $service = Db::name('cashier_v3_service_order')->where('tenant_id', $tenantId)->where('id', $serviceOrderId)->lock(true)->find();
            if (!$service || (string)$service['status'] !== 'OPEN') throw $this->failure('服务单状态已变更，请刷新后重试。');
            Db::name('cashier_v3_service_order')->where('tenant_id', $tenantId)->where('id', $serviceOrderId)->where('version', (int)$service['version'])
                ->update(['status' => 'COMPLETED', 'version' => (int)$service['version'] + 1, 'completed_at' => $now, 'updated_at' => $now]);
            Db::name('cashier_v3_service_order_line')->where('tenant_id', $tenantId)->where('service_order_id', $serviceOrderId)
                ->where('status', 'ACTIVE')->update(['status' => 'COMPLETED', 'updated_at' => $now]);
        }
        $facts['manualWriteoffRequired'] = !empty($debtBlockedOccupationIds);
        $facts['debtBlockedEntitlementCount'] = count($debtBlockedOccupationIds);
        $facts['debtBlockedEntitlements'] = $debtBlockedEntitlements;
        $facts['insufficientEntitlementCount'] = count($insufficientEntitlements);
        $facts['insufficientEntitlements'] = $insufficientEntitlements;
        return $facts;
    }

    /** Build end-service candidates from live authorities, never from a reservation occupation. */
    private function liveEntitlementCandidatesInTx(array $header, array $lines): array
    {
        $memberId = (int)$header['member_id'];
        $entitlementLines = array_values(array_filter($lines, static function (array $line): bool {
            return strtoupper((string)($line['project_source'] ?? '')) === 'ENTITLEMENT';
        }));
        $detailIds = array_values(array_unique(array_filter(array_map(static function (array $line): int {
            return (int)($line['entitlement_source_detail_id'] ?? 0);
        }, $entitlementLines))));
        if (!$detailIds) return ['candidates' => [], 'details' => [], 'holders' => [], 'detailRemain' => [], 'holderRemain' => [], 'unavailable' => []];

        $discovered = $this->rows(Db::name('store_order_cart_info')->whereIn('id', $detailIds)->field('id,oid')->select());
        $orderIds = array_values(array_unique(array_filter(array_map(static function (array $row): int { return (int)($row['oid'] ?? 0); }, $discovered))));
        $holders = $orderIds ? $this->rows(Db::name('user_card_holder')->where('uid', $memberId)->whereIn('oid', $orderIds)
            ->where('is_del', 0)->order('id asc')->lock(true)->select()) : [];
        $holderByOrder = [];
        foreach ($holders as $holder) {
            $oid = (int)($holder['oid'] ?? 0);
            if ($oid > 0 && !isset($holderByOrder[$oid])) $holderByOrder[$oid] = $holder;
        }
        $details = $this->rows(Db::name('store_order_cart_info')->whereIn('id', $detailIds)->order('id asc')->lock(true)->select());
        $detailById = [];
        foreach ($details as $detail) $detailById[(int)$detail['id']] = $detail;

        $candidates = []; $unavailable = []; $detailRemain = []; $holderRemain = []; $detailMap = []; $holderMap = [];
        foreach ($entitlementLines as $line) {
            $lineId = (int)($line['id'] ?? 0); $detailId = (int)($line['entitlement_source_detail_id'] ?? 0);
            $detail = (array)($detailById[$detailId] ?? []); $holder = (array)($holderByOrder[(int)($detail['oid'] ?? 0)] ?? []);
            $quantity = max(1, (int)($line['quantity'] ?? 1));
            if ($lineId <= 0 || !$detail || !$holder) {
                $unavailable[] = ['reservationLineId' => $lineId, 'entitlementSourceDetailId' => $detailId, 'cardHolderId' => 0, 'cardName' => '对应卡项', 'projectName' => trim((string)($line['project_name_snapshot'] ?? '')) ?: '预约项目', 'quantity' => $quantity, 'availableTimes' => 0];
                continue;
            }
            $holderId = (int)$holder['id'];
            $detailRemain[$detailId] = (int)$detail['write_surplus_times'];
            $holderRemain[$holderId] = (int)$holder['write_surplus_times'];
            $detailMap[$detailId] = $detail; $holderMap[$holderId] = $holder;
            $candidates[] = ['id' => $lineId, 'reservation_line_id' => $lineId, 'entitlement_source_detail_id' => $detailId,
                'card_holder_id' => $holderId, 'occupied_times' => $quantity, 'status' => 'DIRECT_CHECK', 'version' => 1,
                'projectName' => trim((string)($line['project_name_snapshot'] ?? '')) ?: '预约项目',
                'detailSnapshot' => $detail, 'holderSnapshot' => $holder];
        }
        return ['candidates' => $candidates, 'details' => $detailMap, 'holders' => $holderMap,
            'detailRemain' => $detailRemain, 'holderRemain' => $holderRemain, 'unavailable' => $unavailable];
    }

    private function insufficientSnapshot(array $candidate, int $available): array
    {
        return ['reservationLineId' => (int)$candidate['reservation_line_id'], 'entitlementSourceDetailId' => (int)$candidate['entitlement_source_detail_id'],
            'cardHolderId' => (int)$candidate['card_holder_id'], 'cardName' => trim((string)($candidate['holderSnapshot']['card_name'] ?? '')) ?: '对应卡项',
            'projectName' => trim((string)($candidate['projectName'] ?? '')) ?: '预约项目', 'quantity' => max(1, (int)$candidate['occupied_times']), 'availableTimes' => max(0, $available)];
    }

    /**
     * Persist a human-readable snapshot in the end-service receipt. The detail
     * view can then explain the original decision even after a debt is repaid or
     * a card is renamed.
     *
     * @param array<int,array<string,mixed>> $occupations
     * @param array<int,array<string,mixed>> $lines
     * @param array<int,true> $blockedOccupationIds
     * @return array<int,array<string,mixed>>
     */
    private function debtBlockedEntitlementSnapshots(
        array $occupations,
        array $lines,
        array $blockedOccupationIds
    ): array {
        if (!$blockedOccupationIds) return [];

        $lineById = [];
        foreach ($lines as $line) {
            $lineById[(int)($line['id'] ?? 0)] = $line;
        }
        $holderIds = [];
        foreach ($occupations as $occupation) {
            if (!isset($blockedOccupationIds[(int)($occupation['id'] ?? 0)])) continue;
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

        $snapshots = [];
        foreach ($occupations as $occupation) {
            $occupationId = (int)($occupation['id'] ?? 0);
            if (!isset($blockedOccupationIds[$occupationId])) continue;
            $lineId = (int)($occupation['reservation_line_id'] ?? 0);
            $holderId = (int)($occupation['card_holder_id'] ?? 0);
            $line = (array)($lineById[$lineId] ?? []);
            $snapshots[] = [
                'reservationLineId' => $lineId,
                'entitlementSourceDetailId' => (int)($occupation['entitlement_source_detail_id'] ?? 0),
                'cardHolderId' => $holderId,
                'cardName' => $holderNames[$holderId] ?? '对应卡项',
                'projectName' => trim((string)($line['project_name_snapshot'] ?? '')) ?: '预约项目',
                'quantity' => max(1, (int)($occupation['occupied_times'] ?? 1)),
            ];
        }
        usort($snapshots, static function (array $left, array $right): int {
            return (int)$left['reservationLineId'] <=> (int)$right['reservationLineId'];
        });
        return $snapshots;
    }

    /**
     * Return the debt-limited available quantity for every candidate line.
     *
     * The shared write-off service owns the monetary-to-times conversion. This
     * method only supplies locked reservation authorities and the authoritative
     * pending debt, so reservation completion cannot drift from cashier use.
     *
     * @param array<int,array<string,mixed>> $occupations
     * @return array<int,int> reservation line id => debt-limited available times
     */
    private function debtLimitedAvailabilityInTx(array $occupations, int $memberId): array
    {
        if (!$occupations) return [];
        $orderIds = [];
        foreach ($occupations as $occupation) {
            $orderId = (int)($occupation['detailSnapshot']['oid'] ?? 0);
            if ($orderId > 0) $orderIds[$orderId] = $orderId;
        }
        $orderIds = array_values($orderIds);
        sort($orderIds, SORT_NUMERIC);
        if (!$orderIds) return [];

        $debtRows = $this->rows(Db::name('store_debt')->whereIn('order_id', $orderIds)
            ->field('id,order_id,status,total_debt,repaid_debt')->order('order_id asc,id asc')->lock(true)->select());
        $debtByOrder = [];
        foreach ($debtRows as $debt) {
            $debtByOrder[(int)$debt['order_id']] = $debt;
        }

        $orders = $this->rows(Db::name('store_order')->whereIn('id', $orderIds)
            ->order('id asc')->lock(true)->select());
        $ordersById = [];
        foreach ($orders as $order) $ordersById[(int)$order['id']] = $order;

        $cartRows = $this->rows(Db::name('store_order_cart_info')->whereIn('oid', $orderIds)
            ->where('cart_type', 2)->where('product_type', 6)
            ->field('id,oid,cart_info,cart_type,product_type,write_times,write_surplus_times,pay_price,debt_amount,repaid_debt_amount,is_gift')
            ->order('oid asc,id asc')->lock(true)->select());
        $cartRowsByOrder = [];
        foreach ($cartRows as $cartRow) $cartRowsByOrder[(int)$cartRow['oid']][] = $cartRow;

        $v3CardDebtResolver = new CashierV3CardOriginDebtResolver();
        $pendingByOrder = [];
        foreach ($orders as $order) {
            $orderId = (int)$order['id'];
            $v3CardDebt = $v3CardDebtResolver->pendingV3CardDebt($order, $memberId, true);
            if ($v3CardDebt !== null) {
                $pendingByOrder[$orderId] = $v3CardDebt;
                continue;
            }
            $debt = (array)($debtByOrder[$orderId] ?? []);
            if ($debt) {
                $pendingByOrder[$orderId] = (int)$debt['status'] === 0
                    ? max('0.00', bcsub((string)$debt['total_debt'], (string)$debt['repaid_debt'], 2))
                    : '0.00';
                continue;
            }
            $pendingByOrder[$orderId] = max('0.00', bcsub(
                (string)($order['debt_amount'] ?? '0'),
                (string)($order['repaid_debt_amount'] ?? '0'),
                2
            ));
        }

        $writeoff = app()->make(WriteOffOrderServices::class);
        if (!$writeoff instanceof WriteOffOrderServices) {
            throw new \LogicException('reservation_writeoff_service_invalid');
        }
        $availability = [];
        foreach ($occupations as $occupation) {
            $occupationId = (int)$occupation['id'];
            $detail = (array)($occupation['detailSnapshot'] ?? []);
            $orderId = (int)($detail['oid'] ?? 0);
            $order = (array)($ordersById[$orderId] ?? []);
            if ($occupationId <= 0 || !$detail || !$order) continue;
            $availability[$occupationId] = $writeoff->calcEffectiveWriteSurplusTimes(
                $detail,
                (float)($pendingByOrder[$orderId] ?? 0),
                $order,
                null,
                $cartRowsByOrder[$orderId] ?? []
            );
        }
        return $availability;
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
