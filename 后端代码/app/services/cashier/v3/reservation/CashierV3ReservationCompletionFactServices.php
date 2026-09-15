<?php

declare(strict_types=1);

namespace app\services\cashier\v3\reservation;

use app\services\cashier\v3\CashierV3BusinessDocumentNumberServices;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\cashier\CashierV3EntitlementActualAmountAllocator;
use app\services\cashier\v3\checkout\CashierV3EntitlementCompletionKernel;
use app\services\report\CustomerLifecycleFactServices;
use app\services\report\StoreReportServiceCategorySnapshotServices;
use think\facade\Db;

/** Immutable service/writeoff/performance facts produced by reservation end. */
final class CashierV3ReservationCompletionFactServices
{
    public function persistInTx(array $header, array $lines, array $occupations, array $execution, int $now): array
    {
        CashierV3TransactionGuard::assertInTransaction('reservationCompletionFacts.persist');
        $eventNo = trim((string)($execution['eventNo'] ?? ''));
        $commandKey = trim((string)($execution['commandIdempotencyKey'] ?? ''));
        $operatorId = (int)($execution['operatorId'] ?? 0);
        $operatorName = trim((string)($execution['operatorName'] ?? ''));
        if ($eventNo === '' || $commandKey === '' || $operatorId <= 0) {
            throw new \LogicException('reservation_completion_execution_invalid');
        }
        $occupationByLine = [];
        foreach ($occupations as $occupation) {
            $occupationByLine[(int)$occupation['reservation_line_id']] = $occupation;
        }
        $serviceRows = [];
        $writeoffCount = 0;
        $performanceCount = 0;
        foreach ($lines as $line) {
            $occupation = $occupationByLine[(int)$line['id']] ?? null;
            $facts = $this->persistLineInTx($header, $line, is_array($occupation) ? $occupation : null, $eventNo, $commandKey, $operatorId, $operatorName, $now);
            $serviceRows[] = $facts['service'];
            $writeoffCount += $facts['writeoff'];
            $performanceCount += $facts['performance'];
        }
        if (!$serviceRows) throw new \LogicException('reservation_completion_lines_empty');
        $context = $this->lifecycleContext($header, $operatorId, $operatorName, $now);
        (new CustomerLifecycleFactServices())->recordServiceCompletionInTx($context, $serviceRows);
        return ['service' => count($serviceRows), 'writeoff' => $writeoffCount, 'performance' => $performanceCount];
    }

    private function persistLineInTx(array $header, array $line, ?array $occupation, string $eventNo, string $commandKey, int $operatorId, string $operatorName, int $now): array
    {
        $tenantId = (string)$header['tenant_id'];
        $reservationId = (int)$header['id'];
        $lineId = (int)$line['id'];
        $quantity = max(1, (int)$line['quantity']);
        $projectId = (int)$line['project_id'];
        $businessDate = date('Y-m-d', $now);
        $requestId = 'reservation:' . $reservationId;
        $sourceLineId = 'reservation:' . $reservationId . ':' . $lineId;
        $documentId = (string)(int)$header['service_order_id'];
        $documentNo = (string)$header['service_order_no_snapshot'];
        $category = $this->category($projectId);
        $craftsmen = $this->craftsmen(
            (string)($line['artisan_staff_ids_json'] ?? '[]'),
            (array)($line['point_customer_staff_ids'] ?? [])
        );
        $amount = $occupation ? $this->entitlementAmount($occupation, $quantity) : [
            'actualCents' => 0, 'purchaseCents' => 0, 'totalTimes' => 0,
            'consumedBefore' => 0, 'calculationVersion' => CashierV3EntitlementActualAmountAllocator::CALCULATION_VERSION,
            'detail' => [], 'holder' => [],
        ];
        $rule = $this->performanceRule($tenantId, $projectId, $now);
        $consumptionCents = $occupation
            ? ($rule['consumptionMode'] === CashierV3EntitlementCompletionKernel::PERFORMANCE_CONFIGURED
                ? $rule['consumptionConfiguredUnitCents'] * $quantity : $amount['actualCents'])
            : 0;
        $laborCents = $craftsmen
            ? ($rule['laborMode'] === CashierV3EntitlementCompletionKernel::PERFORMANCE_CONFIGURED
                ? $rule['laborConfiguredUnitCents'] * $quantity : $amount['actualCents'])
            : 0;
        $common = [
            'business_event_no' => $eventNo,
            'command_idempotency_key' => $commandKey,
            'tenant_id' => $tenantId,
            'tenant_name_snapshot' => '',
            'organization_id' => (string)$header['organization_id'],
            'organization_name_snapshot' => (string)$header['organization_name_snapshot'],
            'organization_path_snapshot' => (string)$header['organization_path'],
            'store_id' => (int)$header['store_id'],
            'store_name_snapshot' => (string)$header['store_name_snapshot'],
            'member_id' => (int)$header['member_id'],
            'member_name_snapshot' => (string)$header['member_name_snapshot'],
            'operator_id' => $operatorId,
            'operator_name_snapshot' => mb_substr($operatorName, 0, 128),
            'business_date' => $businessDate,
            'business_timezone' => date_default_timezone_get() ?: 'Asia/Shanghai',
            'occurred_at' => $now,
            'settled_at' => $now,
            'recorded_at' => $now,
            'checkout_request_id' => $requestId,
            'document_id' => $documentId,
            'document_no_snapshot' => $documentNo,
            'source_document_type' => 'reservation',
            'source_document_id' => $reservationId,
            'source_line_id' => $sourceLineId,
        ];
        $serviceNatural = 'reservation-service:' . $reservationId . ':' . $lineId;
        $serviceNo = (new CashierV3BusinessDocumentNumberServices())->allocateForSourceInTx(
            $tenantId,
            CashierV3BusinessDocumentNumberServices::SERVICE,
            'reservation_service_fact',
            (string)$reservationId . ':' . $lineId,
            $businessDate,
            $now
        );
        $service = array_merge($common, [
            'service_fact_id' => 'ESF-' . substr(hash('sha256', $tenantId . "\0" . $serviceNatural), 0, 40),
            'service_record_no' => $serviceNo,
            'natural_key' => $serviceNatural,
            'project_id' => $projectId,
            'project_name_snapshot' => (string)$line['project_name_snapshot'],
            'project_category_id_snapshot' => $category['id'],
            'project_category_name_snapshot' => $category['name'],
            'quantity' => $quantity,
            'project_count' => $quantity,
            'service_object' => 'SELF',
            'is_experience' => 0,
            'labor_amount_cents' => $laborCents,
            'labor_fee_amount_cents' => 0,
            'labor_mode' => $rule['laborMode'],
            'primary_craftsman_staff_id' => (int)($craftsmen[0]['staffId'] ?? 0),
            'craftsmen_snapshot_json' => $this->json($craftsmen),
            'service_status' => 'completed',
        ]);
        $service['immutable_fingerprint'] = $this->fingerprint($service);
        $this->insertImmutable('cashier_v3_entitlement_service_fact', 'natural_key', $serviceNatural, $service);

        $writeoffCount = 0;
        if ($occupation) {
            $detail = $amount['detail'];
            $holder = $amount['holder'];
            $writeoffNatural = 'reservation-writeoff:' . $reservationId . ':' . $lineId;
            $writeoff = array_merge($common, [
                'writeoff_id' => 'EWO-' . substr(hash('sha256', $tenantId . "\0" . $writeoffNatural), 0, 40),
                'natural_key' => $writeoffNatural,
                'entitlement_instance_type' => 'card_holder',
                'entitlement_instance_id' => (int)$occupation['card_holder_id'],
                'source_kind' => 'card_holder',
                'is_gift' => (int)($detail['is_gift'] ?? 0),
                'gift_source_type' => '', 'gift_id' => 0, 'gift_version' => 0,
                'holder_id' => (int)$occupation['card_holder_id'],
                'origin_order_id' => (int)$detail['oid'],
                'source_name_snapshot' => (string)($holder['card_name'] ?? ''),
                'source_code_snapshot' => (string)($holder['card_no'] ?? ''),
                'source_detail_id' => (int)$occupation['entitlement_source_detail_id'],
                'project_id' => $projectId,
                'project_name_snapshot' => (string)$line['project_name_snapshot'],
                'project_category_id_snapshot' => $category['id'],
                'project_category_name_snapshot' => $category['name'],
                'source_version_snapshot' => (int)$occupation['version'],
                'detail_version_snapshot' => (int)$occupation['version'],
                'quantity' => $quantity,
                'actual_entitlement_amount_cents' => $amount['actualCents'],
                'purchase_amount_cents_snapshot' => $amount['purchaseCents'],
                'total_purchase_times_snapshot' => $amount['totalTimes'],
                'consumed_times_before_snapshot' => $amount['consumedBefore'],
                'amount_calculation_version_snapshot' => $amount['calculationVersion'],
                'service_object' => 'SELF', 'is_experience' => 0,
                'primary_craftsman_staff_id' => (int)($craftsmen[0]['staffId'] ?? 0),
                'craftsmen_snapshot_json' => $this->json($craftsmen),
                'occupation_snapshot_json' => $this->json([
                    'reservationOccupationId' => (int)$occupation['id'],
                    'status' => (string)$occupation['status'],
                    'occupiedTimes' => (int)$occupation['occupied_times'],
                ]),
                'status' => 'effective',
            ]);
            $writeoff['immutable_fingerprint'] = $this->fingerprint($writeoff);
            $this->insertImmutable('cashier_v3_entitlement_writeoff_fact', 'natural_key', $writeoffNatural, $writeoff);
            $writeoffCount = 1;
        }

        $performanceCount = 0;
        if ($occupation) {
            $this->performanceFact($common, $header, $line, $category, $eventNo, $commandKey, $sourceLineId,
                'consumption_performance_recorded', 0, '', '', 0, $consumptionCents, 0, 1, 0,
                'reservation_consumption_' . $rule['consumptionMode'], '项目消耗业绩', 'reservation-rule:' . $rule['version']);
            $performanceCount++;
        }
        if ($craftsmen && $laborCents > 0) {
            $staffIds = array_map(static function (array $row): int { return (int)$row['staffId']; }, $craftsmen);
            $weights = array_fill_keys($staffIds, 1);
            $allocations = CashierV3EntitlementCompletionKernel::allocateLaborAmount($laborCents, $staffIds, $weights);
            $amountByStaff = [];
            foreach ($allocations as $allocation) $amountByStaff[(int)$allocation['staffId']] = (int)$allocation['amountCents'];
            $denominator = count($craftsmen);
            $halfUnits = $quantity * 2;
            foreach ($craftsmen as $index => $craftsman) {
                $projectCount = intdiv($halfUnits, $denominator) + ($index >= $denominator - ($halfUnits % $denominator) ? 1 : 0);
                $this->performanceFact($common, $header, $line, $category, $eventNo, $commandKey, $sourceLineId,
                    'labor_performance_allocated', (int)$craftsman['employeeId'], (string)$craftsman['name'],
                    (string)$craftsman['employeeType'], (int)$craftsman['employeeTypeVersion'],
                    (int)($amountByStaff[(int)$craftsman['staffId']] ?? 0), 1, $denominator, $projectCount,
                    'reservation_labor_' . $rule['laborMode'], '项目劳动业绩', 'reservation-rule:' . $rule['version'],
                    !empty($craftsman['isPointCustomer']) ? 'craftsman:point' : 'craftsman:round');
                $performanceCount++;
            }
        }
        return ['service' => $service, 'writeoff' => $writeoffCount, 'performance' => $performanceCount];
    }

    private function performanceFact(array $common, array $header, array $line, array $category, string $eventNo, string $commandKey, string $sourceLineId, string $type, int $employeeId, string $employeeName, string $employeeType, int $employeeTypeVersion, int $amountCents, int $weight, int $denominator, int $projectCountHalfUnits, string $ruleCode, string $ruleName, string $ruleVersion, string $roleSnapshot = ''): void
    {
        $natural = 'reservation-performance:' . (int)$header['id'] . ':' . (int)$line['id'] . ':' . $type . ':' . $employeeId;
        $row = [
            'fact_id' => 'RPF-' . substr(hash('sha256', (string)$header['tenant_id'] . "\0" . $natural), 0, 40),
            'business_event_no' => $eventNo, 'fact_type' => $type, 'fact_direction' => 'forward',
            'natural_key' => $natural, 'command_idempotency_key' => $commandKey, 'fact_version' => 1,
            'reversal_of' => '', 'status' => 'effective',
            'tenant_id' => (string)$header['tenant_id'], 'tenant_name_snapshot' => '',
            'organization_id' => (string)$header['organization_id'], 'organization_name_snapshot' => (string)$header['organization_name_snapshot'],
            'organization_path_snapshot' => (string)$header['organization_path'], 'store_id' => (int)$header['store_id'],
            'store_name_snapshot' => (string)$header['store_name_snapshot'], 'member_id' => (int)$header['member_id'],
            'member_name_snapshot' => (string)$header['member_name_snapshot'], 'operator_id' => (int)$common['operator_id'],
            'operator_name_snapshot' => (string)$common['operator_name_snapshot'], 'business_date' => (string)$common['business_date'],
            'business_timezone' => (string)$common['business_timezone'], 'occurred_at' => (int)$common['occurred_at'],
            'settled_at' => (int)$common['settled_at'], 'recorded_at' => (int)$common['recorded_at'],
            'checkout_request_id' => (string)$common['checkout_request_id'], 'order_id' => (string)$common['document_id'],
            'order_no_snapshot' => (string)$common['document_no_snapshot'], 'source_document_type' => 'entitlement_completion',
            'business_source_primary_id' => 0, 'business_source_primary_name_snapshot' => '',
            'business_source_secondary_id' => 0, 'business_source_secondary_name_snapshot' => '',
            'business_source_label_snapshot' => '', 'source_attribution_type_snapshot' => 'other',
            'source_line_id' => $sourceLineId, 'performance_type' => $type,
            'employee_id' => $employeeId, 'employee_name_snapshot' => $employeeName,
            'employee_type_snapshot' => $employeeType, 'employee_type_authority_version' => $employeeTypeVersion,
            // The point/round decision is part of the service-time snapshot.
            // Keep it on the immutable labor fact as well, so later staff
            // changes cannot alter historical service-record presentation.
            'role_snapshot' => $roleSnapshot !== '' ? $roleSnapshot : ($employeeId > 0 ? 'craftsman' : 'service_line'),
            'allocation_weight_numerator' => $weight, 'allocation_weight_denominator' => max(1, $denominator),
            'allocation_base_amount_cents' => $amountCents, 'amount_cents' => $amountCents,
            'labor_fee_amount_cents' => 0, 'project_count_half_units' => $projectCountHalfUnits,
            'rule_code_snapshot' => $ruleCode, 'rule_name_snapshot' => $ruleName, 'rule_version_snapshot' => $ruleVersion,
        ];
        $row['immutable_fingerprint'] = $this->fingerprint($row);
        $this->insertImmutable('cashier_v3_performance_fact', 'natural_key', $natural, $row);
    }

    private function entitlementAmount(array $occupation, int $quantity): array
    {
        // Reservation completion supplies the exact, already locked live
        // snapshot from its own authority check.  This preserves the amount
        // sequence for two lines using the same card in one completion.
        $detail = (array)($occupation['detailSnapshot'] ?? []);
        $holder = (array)($occupation['holderSnapshot'] ?? []);
        if (!$detail) $detail = Db::name('store_order_cart_info')->where('id', (int)$occupation['entitlement_source_detail_id'])->lock(true)->find();
        if (!$holder) $holder = Db::name('user_card_holder')->where('id', (int)$occupation['card_holder_id'])->lock(true)->find();
        if (!$detail || !$holder) throw new \LogicException('reservation_completion_entitlement_missing');
        $snapshot = json_decode((string)($detail['cart_info'] ?? ''), true);
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $legacy = is_array($snapshot['rh_source'] ?? null) ? $snapshot['rh_source'] : [];
        $purchase = array_key_exists('source_line_paid_amount', $legacy) ? $legacy['source_line_paid_amount'] : ($detail['pay_price'] ?? '0');
        $purchase = preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', trim((string)$purchase)) ? bcadd((string)$purchase, '0', 2) : '0.00';
        $totalTimes = max(1, (int)($detail['write_times'] ?? $holder['write_times'] ?? 1));
        $remainingBefore = (int)$detail['write_surplus_times'];
        $consumedBefore = max(0, $totalTimes - $remainingBefore);
        $actual = CashierV3EntitlementActualAmountAllocator::allocateForSnapshot($purchase, $totalTimes, $consumedBefore, $quantity, $snapshot);
        $centCapable = CashierV3EntitlementActualAmountAllocator::isCentCapableSnapshot($snapshot);
        return [
            'actualCents' => $this->moneyToCents($actual), 'purchaseCents' => $this->moneyToCents($purchase),
            'totalTimes' => $totalTimes, 'consumedBefore' => $consumedBefore,
            'calculationVersion' => ($centCapable ? 'operation-cent-' : 'legacy-cart-line-payment-') . CashierV3EntitlementActualAmountAllocator::CALCULATION_VERSION,
            'detail' => $detail, 'holder' => $holder,
        ];
    }

    private function performanceRule(string $tenantId, int $projectId, int $now): array
    {
        Db::execute('INSERT IGNORE INTO `eb_cashier_v3_project_performance_rule` (`tenant_id`,`project_id`,`consumption_mode`,`consumption_configured_unit_amount_cents`,`labor_mode`,`labor_configured_unit_amount_cents`,`current_version`,`created_at`,`updated_at`) VALUES (?,?,?,0,?,0,1,?,?)', [
            $tenantId, $projectId, CashierV3EntitlementCompletionKernel::PERFORMANCE_ACTUAL,
            CashierV3EntitlementCompletionKernel::PERFORMANCE_ACTUAL, $now, $now,
        ]);
        $row = Db::name('cashier_v3_project_performance_rule')->where('tenant_id', $tenantId)->where('project_id', $projectId)->lock(true)->find();
        if (!$row) throw new \LogicException('reservation_completion_performance_rule_missing');
        return [
            'consumptionMode' => (string)$row['consumption_mode'],
            'consumptionConfiguredUnitCents' => max(0, (int)$row['consumption_configured_unit_amount_cents']),
            'laborMode' => (string)$row['labor_mode'],
            'laborConfiguredUnitCents' => max(0, (int)$row['labor_configured_unit_amount_cents']),
            'version' => max(1, (int)$row['current_version']),
        ];
    }

    private function craftsmen(string $json, array $pointCustomerStaffIds = []): array
    {
        $ids = json_decode($json, true);
        $ids = array_values(array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : []))));
        $pointCustomerStaffIds = array_fill_keys(array_filter(array_map('intval', $pointCustomerStaffIds)), true);
        sort($ids, SORT_NUMERIC);
        $rows = [];
        foreach ($ids as $staffId) {
            $staff = Db::name('system_store_staff')->where('id', $staffId)->field('id,employee_id,staff_name')->find();
            if (!$staff) continue;
            $employee = (int)$staff['employee_id'] > 0 ? Db::name('employee')->where('id', (int)$staff['employee_id'])->field('employment_type_code,employment_type_version')->find() : [];
            $type = (string)($employee['employment_type_code'] ?? 'internal');
            if (!in_array($type, ['internal', 'partner', 'outsourced'], true)) $type = 'internal';
            $rows[] = [
                'staffId' => $staffId, 'employeeId' => max(0, (int)$staff['employee_id']),
                'name' => (string)$staff['staff_name'], 'employeeType' => $type,
                'employeeTypeVersion' => max(0, (int)($employee['employment_type_version'] ?? 0)),
                'isPrimary' => count($rows) === 0,
                'isPointCustomer' => isset($pointCustomerStaffIds[$staffId]),
            ];
        }
        return $rows;
    }

    private function category(int $projectId): array
    {
        $product = Db::name('store_product')->where('id', $projectId)->field('cate_id')->find() ?: [];
        $raw = (string)($product['cate_id'] ?? '');
        preg_match('/\d+/', $raw, $match);
        $id = (int)($match[0] ?? 0);
        $name = $id > 0 ? (string)Db::name('store_product_category')->where('id', $id)->value('cate_name') : '';
        $path = (new StoreReportServiceCategorySnapshotServices())->resolveInTx($id, $name);
        return ['id' => $id, 'name' => $name, 'path' => (string)$path['project_category_path_snapshot']];
    }

    private function lifecycleContext(array $header, int $operatorId, string $operatorName, int $now): array
    {
        return [
            'tenant_id' => (string)$header['tenant_id'], 'organization_id' => (string)$header['organization_id'],
            'store_id' => (int)$header['store_id'], 'member_id' => (int)$header['member_id'],
            'order_id' => (string)$header['service_order_id'], 'order_no_snapshot' => (string)$header['service_order_no_snapshot'],
            'checkout_request_id' => 'reservation:' . (int)$header['id'], 'business_date' => date('Y-m-d', $now),
            'occurred_at' => $now, 'recorded_at' => $now, 'operator_id' => $operatorId, 'operator_name_snapshot' => $operatorName,
            'business_source_primary_id' => 0, 'business_source_primary_name_snapshot' => '',
            'business_source_secondary_id' => 0, 'business_source_secondary_name_snapshot' => '',
            'business_source_label_snapshot' => '', 'source_attribution_type_snapshot' => 'other',
        ];
    }

    private function insertImmutable(string $table, string $keyColumn, string $key, array $row): void
    {
        $existing = Db::name($table)->where('tenant_id', (string)$row['tenant_id'])->where($keyColumn, $key)->lock(true)->find();
        if ($existing) {
            if (!hash_equals((string)$existing['immutable_fingerprint'], (string)$row['immutable_fingerprint'])) {
                throw new \LogicException('reservation_completion_fact_replay_conflict');
            }
            return;
        }
        Db::name($table)->insert($row);
    }

    private function fingerprint(array $row): string
    {
        unset($row['immutable_fingerprint']);
        ksort($row, SORT_STRING);
        return hash('sha256', $this->json($row));
    }

    private function moneyToCents(string $amount): int
    {
        return (int)bcmul(bcadd($amount, '0', 2), '100', 0);
    }

    private function json(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) throw new \LogicException('reservation_completion_json_failed');
        return $json;
    }
}
