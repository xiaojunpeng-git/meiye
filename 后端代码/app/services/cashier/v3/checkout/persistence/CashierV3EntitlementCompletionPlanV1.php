<?php

namespace app\services\cashier\v3\checkout\persistence;

use app\services\cashier\v3\checkout\CashierV3EntitlementCompletionKernel;

/**
 * Immutable hand-off from the pure completion kernel to the transaction-only
 * authority writer. The persistence context is assembled from already-locked
 * server authorities; no field in this object may come directly from a client.
 */
final class CashierV3EntitlementCompletionPlanV1
{
    public const CONTRACT_VERSION = 'cashier-v3-entitlement-completion-persistence-v1';

    private const EMPLOYEE_TYPES = ['internal', 'partner', 'outsourced'];

    /** @var array */
    private $context;

    /** @var array */
    private $deductions;

    /** @var array */
    private $writeoffRows;

    /** @var array */
    private $serviceRows;

    /** @var array */
    private $performanceRows;

    /** @var array */
    private $totals;

    /** @var string */
    private $fingerprint;

    private function __construct(
        array $context,
        array $deductions,
        array $writeoffRows,
        array $serviceRows,
        array $performanceRows,
        array $totals,
        string $fingerprint
    ) {
        $this->context = $context;
        $this->deductions = $deductions;
        $this->writeoffRows = $writeoffRows;
        $this->serviceRows = $serviceRows;
        $this->performanceRows = $performanceRows;
        $this->totals = $totals;
        $this->fingerprint = $fingerprint;
    }

    /**
     * Persistence context keys:
     * contractVersion, checkoutRequestId, commandIdempotencyKey,
     * businessEventNo, documentId, documentNo, tenantNameSnapshot,
     * staffSnapshots.
     */
    public static function fromKernelPlan(array $kernelPlan, array $persistenceContext): self
    {
        self::assertKernelEnvelope($kernelPlan);
        $context = self::normalizeContext($kernelPlan, $persistenceContext);
        $staff = self::normalizeStaffSnapshots($persistenceContext['staffSnapshots'], $context['store_id']);
        $deductions = self::normalizeDeductions($kernelPlan['entitlementDeductions']);

        $writeoffs = [];
        $services = [];
        $performance = [];
        $seenLines = [];
        $actualTotal = 0;
        $consumptionTotal = 0;
        $laborTotal = 0;
        $serviceQuantity = 0;
        foreach (array_values($kernelPlan['linePlans']) as $index => $line) {
            $normalized = self::normalizeLine($line, $context, $staff, $index);
            $lineId = $normalized['line_id'];
            if (isset($seenLines[$lineId])) {
                throw self::failure('completion_line_duplicate', ['lineId' => $lineId]);
            }
            $seenLines[$lineId] = true;

            $writeoff = self::writeoffRow($context, $normalized);
            $service = self::serviceRow($context, $normalized);
            $writeoffs[] = $writeoff;
            $services[] = $service;
            $performance[] = self::consumptionPerformanceRow($context, $normalized);
            foreach ($normalized['labor_allocations'] as $allocation) {
                // Labor-only craftsmen remain in the immutable service
                // snapshot. They do not create labor-performance amount, but
                // a separately entered labor fee is still an auditable fact.
                if ((int)$allocation['amount_cents'] === 0
                    && (int)$allocation['labor_fee_cents'] === 0) {
                    continue;
                }
                $performance[] = self::laborPerformanceRow($context, $normalized, $allocation);
            }

            $actualTotal += $normalized['actual_entitlement_amount_cents'];
            $consumptionTotal += $normalized['consumption_amount_cents'];
            $laborTotal += $normalized['labor_amount_cents'];
            $serviceQuantity += $normalized['quantity'];
        }
        if (!$writeoffs) {
            throw self::failure('completion_lines_empty');
        }

        $totals = [
            'line_count' => count($writeoffs),
            'service_quantity' => $serviceQuantity,
            'actual_entitlement_amount_cents' => $actualTotal,
            'consumption_performance_cents' => $consumptionTotal,
            'labor_performance_cents' => $laborTotal,
        ];
        self::assertKernelTotals($kernelPlan['totals'], $totals);
        self::assertDeductionCoverage($deductions, $writeoffs);

        $fingerprint = self::fingerprintValue([
            'contractVersion' => self::CONTRACT_VERSION,
            'context' => $context,
            'deductions' => $deductions,
            'writeoffs' => $writeoffs,
            'services' => $services,
            'performance' => $performance,
            'totals' => $totals,
        ]);
        return new self($context, $deductions, $writeoffs, $services, $performance, $totals, $fingerprint);
    }

    public function context(): array
    {
        return $this->context;
    }

    public function deductions(): array
    {
        return $this->deductions;
    }

    public function writeoffRows(): array
    {
        return $this->writeoffRows;
    }

    public function serviceRows(): array
    {
        return $this->serviceRows;
    }

    public function performanceRows(): array
    {
        return $this->performanceRows;
    }

    public function totals(): array
    {
        return $this->totals;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    public static function receiptId(
        string $tenantId,
        string $checkoutRequestId,
        string $commandIdempotencyKey
    ): string {
        $tenantId = self::token($tenantId, 32, 'tenant_id_invalid');
        $checkoutRequestId = self::token($checkoutRequestId, 64, 'checkout_request_id_invalid');
        $commandIdempotencyKey = self::token(
            $commandIdempotencyKey,
            128,
            'command_idempotency_key_invalid'
        );
        return 'ECR-' . substr(hash(
            'sha256',
            $tenantId . "\0" . $checkoutRequestId . "\0" . $commandIdempotencyKey
        ), 0, 40);
    }

    private static function assertKernelEnvelope(array $plan): void
    {
        foreach ([
            'contractVersion', 'action', 'composition', 'persistenceStatus',
            'requiresGatewayTransaction', 'workspaceId', 'stateContextId',
            'memberId', 'storeId', 'operatorId', 'businessDate',
            'businessTimezone', 'occurredAt', 'settledAt', 'recordedAt',
            'dimensionSnapshot', 'source', 'entitlementDeductions', 'linePlans',
            'totals',
        ] as $key) {
            if (!array_key_exists($key, $plan)) {
                throw self::failure('kernel_plan_shape_invalid', ['missing' => $key]);
            }
        }
        if ($plan['contractVersion'] !== CashierV3EntitlementCompletionKernel::CONTRACT_VERSION
            || $plan['action'] !== CashierV3EntitlementCompletionKernel::ACTION
            || $plan['composition'] !== CashierV3EntitlementCompletionKernel::COMPOSITION_ENTITLEMENT_ONLY
            || $plan['persistenceStatus'] !== 'not_persisted'
            || $plan['requiresGatewayTransaction'] !== true
            || !is_array($plan['dimensionSnapshot'])
            || !is_array($plan['source'])
            || !is_array($plan['entitlementDeductions'])
            || !is_array($plan['linePlans'])
            || !is_array($plan['totals'])) {
            throw self::failure('kernel_plan_not_persistable');
        }
    }

    private static function normalizeContext(array $plan, array $input): array
    {
        self::assertExactKeys($input, [
            'contractVersion', 'checkoutRequestId', 'commandIdempotencyKey',
            'businessEventNo', 'documentId', 'documentNo',
            'tenantNameSnapshot', 'staffSnapshots',
        ], 'persistenceContext');
        if ($input['contractVersion'] !== self::CONTRACT_VERSION) {
            throw self::failure('persistence_contract_version_invalid');
        }
        $dimension = $plan['dimensionSnapshot'];
        foreach ([
            'tenantId', 'organizationId', 'organizationName', 'organizationPath',
            'storeId', 'storeName', 'memberId', 'memberName', 'operatorId',
            'operatorName',
        ] as $key) {
            if (!array_key_exists($key, $dimension)) {
                throw self::failure('dimension_snapshot_incomplete', ['missing' => $key]);
            }
        }
        $context = [
            'tenant_id' => self::token($dimension['tenantId'], 32, 'tenant_id_invalid'),
            'tenant_name_snapshot' => self::text($input['tenantNameSnapshot'], 128, 'tenant_name_invalid'),
            'organization_id' => (string)self::nonNegativeInt(
                $dimension['organizationId'],
                'organization_id_invalid'
            ),
            'organization_name_snapshot' => self::text($dimension['organizationName'], 128, 'organization_name_invalid'),
            'organization_path_snapshot' => self::text($dimension['organizationPath'], 512, 'organization_path_invalid'),
            'store_id' => self::positiveInt($plan['storeId'], 'store_id_invalid'),
            'store_name_snapshot' => self::text($dimension['storeName'], 128, 'store_name_invalid'),
            'member_id' => self::positiveInt($plan['memberId'], 'member_id_invalid'),
            'member_name_snapshot' => self::text($dimension['memberName'], 128, 'member_name_invalid'),
            'operator_id' => self::positiveInt($plan['operatorId'], 'operator_id_invalid'),
            'operator_name_snapshot' => self::text($dimension['operatorName'], 128, 'operator_name_invalid'),
            'business_date' => self::businessDate($plan['businessDate']),
            'business_timezone' => self::timezone($plan['businessTimezone']),
            'occurred_at' => self::positiveInt($plan['occurredAt'], 'occurred_at_invalid'),
            'settled_at' => self::positiveInt($plan['settledAt'], 'settled_at_invalid'),
            'recorded_at' => self::positiveInt($plan['recordedAt'], 'recorded_at_invalid'),
            'checkout_request_id' => self::token($input['checkoutRequestId'], 64, 'checkout_request_id_invalid'),
            'command_idempotency_key' => self::token($input['commandIdempotencyKey'], 128, 'command_idempotency_key_invalid'),
            'business_event_no' => self::token($input['businessEventNo'], 64, 'business_event_no_invalid'),
            'document_id' => self::token($input['documentId'], 64, 'document_id_invalid'),
            'document_no_snapshot' => self::text($input['documentNo'], 64, 'document_no_invalid'),
            'workspace_id' => self::token($plan['workspaceId'], 64, 'workspace_id_invalid'),
            'state_context_id' => self::token($plan['stateContextId'], 64, 'state_context_id_invalid'),
            'source_type' => self::token($plan['source']['type'] ?? null, 32, 'source_type_invalid'),
            'service_order_id' => self::nonNegativeInt($plan['source']['serviceOrderId'] ?? null, 'service_order_id_invalid'),
            'reservation_id' => self::nonNegativeInt($plan['source']['reservationId'] ?? null, 'reservation_id_invalid'),
        ];
        foreach ([
            'tenantId' => 'tenant_id', 'organizationId' => 'organization_id',
            'storeId' => 'store_id', 'memberId' => 'member_id',
            'operatorId' => 'operator_id',
        ] as $dimensionKey => $contextKey) {
            if ((string)$dimension[$dimensionKey] !== (string)$context[$contextKey]) {
                throw self::failure('kernel_dimension_mismatch', ['field' => $dimensionKey]);
            }
        }
        if ($context['settled_at'] < $context['occurred_at']
            || $context['recorded_at'] < $context['occurred_at']) {
            throw self::failure('completion_time_order_invalid');
        }
        if (($context['source_type'] === 'direct'
                && ($context['service_order_id'] !== 0 || $context['reservation_id'] !== 0))
            || ($context['source_type'] === 'service_order'
                && ($context['service_order_id'] <= 0 || $context['reservation_id'] !== 0))
            || ($context['source_type'] === 'reservation'
                && ($context['reservation_id'] <= 0 || $context['service_order_id'] !== 0))) {
            throw self::failure('completion_source_identity_invalid');
        }
        return $context;
    }

    private static function normalizeStaffSnapshots($input, int $storeId): array
    {
        if (!is_array($input) || !self::isList($input) || count($input) > 100) {
            throw self::failure('staff_snapshots_invalid');
        }
        $result = [];
        foreach ($input as $row) {
            if (!is_array($row)) {
                throw self::failure('staff_snapshot_shape_invalid');
            }
            self::assertExactKeys($row, [
                'staffId', 'employeeId', 'staffName', 'staffVersion', 'storeId',
                'employeeTypeCodeSnapshot', 'employeeTypeAuthorityVersion',
            ], 'staffSnapshot');
            $staffId = self::positiveInt($row['staffId'], 'staff_id_invalid');
            $type = self::token($row['employeeTypeCodeSnapshot'], 16, 'employee_type_invalid');
            $normalized = [
                'staff_id' => $staffId,
                'employee_id' => self::positiveInt($row['employeeId'], 'employee_id_invalid'),
                'staff_name' => self::text($row['staffName'], 128, 'staff_name_invalid'),
                'staff_version' => self::positiveInt($row['staffVersion'], 'staff_version_invalid'),
                'store_id' => self::positiveInt($row['storeId'], 'staff_store_id_invalid'),
                'employee_type' => $type,
                'employee_type_version' => self::positiveInt(
                    $row['employeeTypeAuthorityVersion'],
                    'employee_type_version_invalid'
                ),
            ];
            if ($normalized['store_id'] !== $storeId || !in_array($type, self::EMPLOYEE_TYPES, true)) {
                throw self::failure('staff_snapshot_scope_invalid', ['staffId' => $staffId]);
            }
            if (isset($result[$staffId])) {
                throw self::failure('staff_snapshot_duplicate', ['staffId' => $staffId]);
            }
            $result[$staffId] = $normalized;
        }
        ksort($result, SORT_NUMERIC);
        return $result;
    }

    private static function normalizeDeductions($input): array
    {
        if (!is_array($input) || !self::isList($input) || !$input) {
            throw self::failure('entitlement_deductions_invalid');
        }
        $result = [];
        $seen = [];
        foreach ($input as $row) {
            if (!is_array($row)) {
                throw self::failure('entitlement_deduction_shape_invalid');
            }
            foreach ([
                'sourceKey', 'entitlementInstanceType', 'entitlementInstanceId',
                'sourceKind', 'isGift', 'giftSourceType', 'giftId', 'giftVersion',
                'holderId', 'originOrderId', 'sourceDetailId', 'projectId',
                'sourceVersion', 'detailVersion', 'expectedPhysicalRemainingTimes',
                'deductPhysicalTimes', 'convertCurrentSourceOccupiedTimes', 'lineIds',
            ] as $key) {
                if (!array_key_exists($key, $row)) {
                    throw self::failure('entitlement_deduction_shape_invalid', ['missing' => $key]);
                }
            }
            if ($row['entitlementInstanceType'] !== CashierV3EntitlementCompletionKernel::ENTITLEMENT_CARD_HOLDER) {
                throw self::failure('entitlement_instance_writer_not_ready');
            }
            $holderId = self::positiveInt($row['holderId'], 'deduction_holder_id_invalid');
            $detailId = self::positiveInt($row['sourceDetailId'], 'deduction_detail_id_invalid');
            $key = $holderId . ':' . $detailId;
            if (isset($seen[$key])) {
                throw self::failure('entitlement_deduction_duplicate', ['source' => $key]);
            }
            $seen[$key] = true;
            $expected = self::positiveInt($row['expectedPhysicalRemainingTimes'], 'deduction_expected_invalid');
            $deduct = self::positiveInt($row['deductPhysicalTimes'], 'deduction_quantity_invalid');
            if ($deduct > $expected || !is_array($row['lineIds']) || !self::isList($row['lineIds']) || !$row['lineIds']) {
                throw self::failure('entitlement_deduction_quantity_invalid', ['source' => $key]);
            }
            $lineIds = [];
            foreach ($row['lineIds'] as $lineId) {
                $lineIds[] = self::token($lineId, 64, 'deduction_line_id_invalid');
            }
            $result[] = [
                'source_key' => self::token($row['sourceKey'], 128, 'deduction_source_key_invalid'),
                'entitlement_instance_type' => 'card_holder',
                'entitlement_instance_id' => self::positiveInt($row['entitlementInstanceId'], 'deduction_instance_id_invalid'),
                'source_kind' => self::token($row['sourceKind'], 32, 'deduction_source_kind_invalid'),
                'is_gift' => self::booleanInt($row['isGift'], 'deduction_gift_flag_invalid'),
                'gift_source_type' => self::token($row['giftSourceType'], 32, 'deduction_gift_source_invalid'),
                'gift_id' => self::nonNegativeInt($row['giftId'], 'deduction_gift_id_invalid'),
                'gift_version' => self::nonNegativeInt($row['giftVersion'], 'deduction_gift_version_invalid'),
                'holder_id' => $holderId,
                'origin_order_id' => self::positiveInt($row['originOrderId'], 'deduction_order_id_invalid'),
                'source_detail_id' => $detailId,
                'project_id' => self::positiveInt($row['projectId'], 'deduction_project_id_invalid'),
                'source_version' => self::positiveInt($row['sourceVersion'], 'deduction_source_version_invalid'),
                'detail_version' => self::positiveInt($row['detailVersion'], 'deduction_detail_version_invalid'),
                'expected_physical_remaining_times' => $expected,
                'deduct_physical_times' => $deduct,
                'convert_current_source_occupied_times' => self::nonNegativeInt(
                    $row['convertCurrentSourceOccupiedTimes'],
                    'deduction_convert_occupied_invalid'
                ),
                'line_ids' => $lineIds,
            ];
        }
        usort($result, static function (array $left, array $right): int {
            $holder = $left['holder_id'] <=> $right['holder_id'];
            return $holder !== 0 ? $holder : ($left['source_detail_id'] <=> $right['source_detail_id']);
        });
        return $result;
    }

    private static function normalizeLine(array $line, array $context, array $staff, int $index): array
    {
        foreach ([
            'lineId', 'source', 'quantity', 'actualEntitlementAmountCents',
            'performanceRuleSnapshot', 'consumptionPerformance',
            'laborPerformance', 'serviceSnapshot',
        ] as $key) {
            if (!array_key_exists($key, $line)) {
                throw self::failure('completion_line_shape_invalid', ['index' => $index, 'missing' => $key]);
            }
        }
        if (!is_array($line['source']) || !is_array($line['performanceRuleSnapshot'])
            || !is_array($line['consumptionPerformance']) || !is_array($line['laborPerformance'])
            || !is_array($line['serviceSnapshot'])) {
            throw self::failure('completion_line_shape_invalid', ['index' => $index]);
        }
        $source = $line['source'];
        foreach ([
            'entitlementInstanceType', 'entitlementInstanceId', 'sourceKind',
            'isGift', 'giftSourceType', 'giftId', 'giftVersion', 'holderId',
            'originOrderId', 'sourceNameSnapshot', 'sourceCodeSnapshot',
            'sourceDetailId', 'projectId', 'projectNameSnapshot',
            'projectCategoryIdSnapshot', 'projectCategoryNameSnapshot',
            'sourceVersion', 'detailVersion', 'purchaseAmountCents',
            'totalPurchaseTimes', 'consumedTimesAtLock', 'amountCalculationVersion',
        ] as $key) {
            if (!array_key_exists($key, $source)) {
                throw self::failure('completion_line_source_incomplete', ['index' => $index, 'missing' => $key]);
            }
        }
        $service = $line['serviceSnapshot'];
        foreach ([
            'serviceObject', 'friendCountsAsCustomer', 'isExperience', 'craftsmen', 'primaryCraftsmanId',
            'businessDate', 'businessTimezone', 'occurredAt', 'settledAt',
            'recordedAt', 'sourceType', 'serviceOrderId', 'reservationId',
            'occupationContributors',
        ] as $key) {
            if (!array_key_exists($key, $service)) {
                throw self::failure('service_snapshot_incomplete', ['index' => $index, 'missing' => $key]);
            }
        }
        $lineId = self::token($line['lineId'], 64, 'line_id_invalid');
        $quantity = self::positiveInt($line['quantity'], 'line_quantity_invalid');
        $actual = self::nonNegativeInt($line['actualEntitlementAmountCents'], 'line_actual_amount_invalid');
        $consumption = self::nonNegativeInt(
            $line['consumptionPerformance']['amountCents'] ?? null,
            'consumption_amount_invalid'
        );
        $labor = self::nonNegativeInt($line['laborPerformance']['amountCents'] ?? null, 'labor_amount_invalid');
        $allocations = self::normalizeAllocations(
            $line['laborPerformance']['allocations'] ?? null,
            $labor,
            $service['craftsmen'],
            $staff,
            $context['store_id'],
            $lineId
        );
        $primaryId = self::positiveInt($service['primaryCraftsmanId'], 'primary_craftsman_invalid');
        if ($allocations[0]['staff_id'] !== $primaryId || empty($allocations[0]['is_primary'])) {
            throw self::failure('primary_craftsman_mismatch', ['lineId' => $lineId]);
        }
        $serviceObject = self::token($service['serviceObject'], 16, 'service_object_invalid');
        if (!in_array($serviceObject, ['self', 'friend'], true)) {
            throw self::failure('service_object_invalid', ['lineId' => $lineId]);
        }
        if ((string)$service['businessDate'] !== $context['business_date']
            || (string)$service['businessTimezone'] !== $context['business_timezone']
            || (int)$service['occurredAt'] !== $context['occurred_at']
            || (int)$service['settledAt'] !== $context['settled_at']
            || (int)$service['recordedAt'] !== $context['recorded_at']
            || (string)$service['sourceType'] !== $context['source_type']
            || (int)$service['serviceOrderId'] !== $context['service_order_id']
            || (int)$service['reservationId'] !== $context['reservation_id']) {
            throw self::failure('line_service_context_mismatch', ['lineId' => $lineId]);
        }
        $rule = $line['performanceRuleSnapshot'];
        $ruleVersion = self::positiveInt($rule['ruleVersion'] ?? null, 'performance_rule_version_invalid');
        $consumptionMode = self::token($rule['consumptionMode'] ?? null, 48, 'consumption_mode_invalid');
        $laborMode = self::token($rule['laborMode'] ?? null, 48, 'labor_mode_invalid');
        if (($line['consumptionPerformance']['mode'] ?? null) !== $consumptionMode
            || ($line['laborPerformance']['mode'] ?? null) !== $laborMode) {
            throw self::failure('performance_mode_snapshot_mismatch', ['lineId' => $lineId]);
        }
        return [
            'line_id' => $lineId,
            'quantity' => $quantity,
            'actual_entitlement_amount_cents' => $actual,
            'entitlement_instance_type' => self::token($source['entitlementInstanceType'], 32, 'line_instance_type_invalid'),
            'entitlement_instance_id' => self::positiveInt($source['entitlementInstanceId'], 'line_instance_id_invalid'),
            'source_kind' => self::token($source['sourceKind'], 32, 'line_source_kind_invalid'),
            'is_gift' => self::booleanInt($source['isGift'], 'line_gift_flag_invalid'),
            'gift_source_type' => self::token($source['giftSourceType'], 32, 'line_gift_source_invalid'),
            'gift_id' => self::nonNegativeInt($source['giftId'], 'line_gift_id_invalid'),
            'gift_version' => self::nonNegativeInt($source['giftVersion'], 'line_gift_version_invalid'),
            'holder_id' => self::positiveInt($source['holderId'], 'line_holder_id_invalid'),
            'origin_order_id' => self::positiveInt($source['originOrderId'], 'line_order_id_invalid'),
            'source_name_snapshot' => self::text($source['sourceNameSnapshot'], 128, 'line_source_name_invalid'),
            'source_code_snapshot' => self::text($source['sourceCodeSnapshot'], 128, 'line_source_code_invalid'),
            'source_detail_id' => self::positiveInt($source['sourceDetailId'], 'line_detail_id_invalid'),
            'project_id' => self::positiveInt($source['projectId'], 'line_project_id_invalid'),
            'project_name_snapshot' => self::text($source['projectNameSnapshot'], 128, 'line_project_name_invalid'),
            'project_category_id_snapshot' => self::nonNegativeInt($source['projectCategoryIdSnapshot'], 'line_category_id_invalid'),
            'project_category_name_snapshot' => self::text($source['projectCategoryNameSnapshot'], 128, 'line_category_name_invalid'),
            'source_version' => self::positiveInt($source['sourceVersion'], 'line_source_version_invalid'),
            'detail_version' => self::positiveInt($source['detailVersion'], 'line_detail_version_invalid'),
            'purchase_amount_cents' => self::nonNegativeInt($source['purchaseAmountCents'], 'line_purchase_amount_invalid'),
            'total_purchase_times' => self::positiveInt($source['totalPurchaseTimes'], 'line_total_times_invalid'),
            'consumed_times_at_lock' => self::nonNegativeInt($source['consumedTimesAtLock'], 'line_consumed_times_invalid'),
            'amount_calculation_version' => self::token($source['amountCalculationVersion'], 128, 'line_amount_version_invalid'),
            'service_object' => $serviceObject,
            'friend_counts_as_customer' => self::booleanInt(
                $service['friendCountsAsCustomer'],
                'friend_counts_as_customer_invalid'
            ),
            'is_experience' => self::booleanInt($service['isExperience'], 'experience_flag_invalid'),
            // 明细备注是后加的可空快照；旧服务单没有该键时等价于空备注。
            'detail_remark_snapshot' => self::text($service['detailRemark'] ?? '', 65535, 'detail_remark_invalid'),
            'primary_craftsman_staff_id' => $primaryId,
            'craftsmen_snapshot' => $allocations,
            'occupation_snapshot' => is_array($service['occupationContributors'])
                ? array_values($service['occupationContributors'])
                : self::invalidArray('occupation_snapshot_invalid'),
            'performance_rule_version' => $ruleVersion,
            'consumption_mode' => $consumptionMode,
            'consumption_amount_cents' => $consumption,
            'labor_mode' => $laborMode,
            'labor_amount_cents' => $labor,
            'labor_allocations' => $allocations,
        ];
    }

    private static function normalizeAllocations(
        $input,
        int $expectedTotal,
        $craftsmen,
        array $staff,
        int $storeId,
        string $lineId
    ): array {
        if (!is_array($input) || !self::isList($input) || !$input
            || !is_array($craftsmen) || !self::isList($craftsmen)) {
            throw self::failure('labor_allocations_invalid', ['lineId' => $lineId]);
        }
        $craftsmenById = [];
        foreach ($craftsmen as $craftsman) {
            if (!is_array($craftsman) || !isset($craftsman['staffId'])) {
                throw self::failure('craftsman_snapshot_invalid', ['lineId' => $lineId]);
            }
            $craftsmenById[(int)$craftsman['staffId']] = $craftsman;
        }
        $result = [];
        foreach ($input as $index => $row) {
            if (!is_array($row)) {
                throw self::failure('labor_allocation_shape_invalid', ['lineId' => $lineId]);
            }
            foreach (['staffId', 'isPrimary', 'sequence', 'amountCents', 'staffVersion', 'staffName', 'storeId', 'laborWeight'] as $key) {
                if (!array_key_exists($key, $row)) {
                    throw self::failure('labor_allocation_shape_invalid', ['lineId' => $lineId, 'missing' => $key]);
                }
            }
            $staffId = self::positiveInt($row['staffId'], 'labor_staff_id_invalid');
            $authority = $staff[$staffId] ?? null;
            $craftsman = $craftsmenById[$staffId] ?? null;
            if (!$authority || !$craftsman
                || (int)$row['staffVersion'] !== $authority['staff_version']
                || (string)$row['staffName'] !== $authority['staff_name']
                || (int)$row['storeId'] !== $storeId
                || (int)$craftsman['staffVersion'] !== $authority['staff_version']
                || (string)$craftsman['staffName'] !== $authority['staff_name']) {
                throw self::failure('labor_staff_snapshot_mismatch', ['lineId' => $lineId, 'staffId' => $staffId]);
            }
            $amount = self::nonNegativeInt($row['amountCents'], 'labor_allocation_amount_invalid');
            $performanceType = (string)($row['craftsmanPerformanceType']
                ?? $craftsman['craftsmanPerformanceType'] ?? 'commission_labor');
            if (!in_array($performanceType, ['commission', 'labor', 'commission_labor'], true)) {
                throw self::failure('labor_performance_type_invalid', ['lineId' => $lineId, 'staffId' => $staffId]);
            }
            $laborWeight = self::nonNegativeInt($row['laborWeight'], 'labor_weight_invalid');
            if ($laborWeight > 100) {
                throw self::failure('labor_weight_invalid', ['lineId' => $lineId, 'staffId' => $staffId]);
            }
            if ($performanceType === 'labor' && $amount !== 0) {
                throw self::failure('labor_allocation_amount_invalid', ['lineId' => $lineId, 'staffId' => $staffId]);
            }
            $allocationGroupKey = trim((string)($row['allocationGroupKey']
                ?? $craftsman['allocationGroupKey']
                ?? 'normal'));
            if ($allocationGroupKey === '') $allocationGroupKey = 'normal';
            $performanceIndependent = !empty($row['performanceIndependent'])
                || !empty($craftsman['performanceIndependent']);
            $normalized = [
                'staff_id' => $staffId,
                'employee_id' => $authority['employee_id'],
                'staff_name_snapshot' => $authority['staff_name'],
                'staff_version' => $authority['staff_version'],
                'employee_type_snapshot' => $authority['employee_type'],
                'employee_type_authority_version' => $authority['employee_type_version'],
                'store_id' => $storeId,
                'sequence' => self::positiveInt($row['sequence'], 'labor_sequence_invalid'),
                'is_primary' => self::booleanInt($row['isPrimary'], 'labor_primary_flag_invalid'),
                'labor_weight' => $laborWeight,
                'craftsman_performance_type' => $performanceType,
                'labor_fee_cents' => self::nonNegativeInt(
                    $row['laborFeeCents'] ?? $craftsman['laborFeeCents'] ?? 0,
                    'labor_fee_invalid'
                ),
                'amount_cents' => $amount,
                // Freeze the group identity alongside the labor allocation.
                // The normal group and each independent position have their
                // own 100% denominator; they must never share one global sum.
                'allocation_group_key' => $allocationGroupKey,
                'performance_independent' => $performanceIndependent ? 1 : 0,
            ];
            if ($normalized['sequence'] !== $index + 1 || $normalized['is_primary'] !== ($index === 0 ? 1 : 0)) {
                throw self::failure('labor_allocation_order_invalid', ['lineId' => $lineId]);
            }
            $result[] = $normalized;
        }
        if (count($result) !== count($craftsmenById)) {
            throw self::failure('labor_allocation_total_invalid', ['lineId' => $lineId]);
        }
        return $result;
    }

    private static function writeoffRow(array $context, array $line): array
    {
        $naturalKey = self::naturalKey('writeoff', $context['checkout_request_id'], $line['line_id']);
        $row = array_merge(self::commonFactContext($context, $line), [
            'writeoff_id' => self::factId('EWO', $naturalKey),
            'natural_key' => $naturalKey,
            'entitlement_instance_type' => $line['entitlement_instance_type'],
            'entitlement_instance_id' => $line['entitlement_instance_id'],
            'source_kind' => $line['source_kind'],
            'is_gift' => $line['is_gift'],
            'gift_source_type' => $line['gift_source_type'],
            'gift_id' => $line['gift_id'],
            'gift_version' => $line['gift_version'],
            'holder_id' => $line['holder_id'],
            'origin_order_id' => $line['origin_order_id'],
            'source_name_snapshot' => $line['source_name_snapshot'],
            'source_code_snapshot' => $line['source_code_snapshot'],
            'source_detail_id' => $line['source_detail_id'],
            'project_id' => $line['project_id'],
            'project_name_snapshot' => $line['project_name_snapshot'],
            'project_category_id_snapshot' => $line['project_category_id_snapshot'],
            'project_category_name_snapshot' => $line['project_category_name_snapshot'],
            'source_version_snapshot' => $line['source_version'],
            'detail_version_snapshot' => $line['detail_version'],
            'quantity' => $line['quantity'],
            'actual_entitlement_amount_cents' => $line['actual_entitlement_amount_cents'],
            'purchase_amount_cents_snapshot' => $line['purchase_amount_cents'],
            'total_purchase_times_snapshot' => $line['total_purchase_times'],
            'consumed_times_before_snapshot' => $line['consumed_times_at_lock'],
            'amount_calculation_version_snapshot' => $line['amount_calculation_version'],
            'service_object' => $line['service_object'],
            'is_experience' => $line['is_experience'],
            'primary_craftsman_staff_id' => $line['primary_craftsman_staff_id'],
            'craftsmen_snapshot_json' => self::encode($line['craftsmen_snapshot']),
            'occupation_snapshot_json' => self::encode($line['occupation_snapshot']),
            'status' => 'effective',
        ]);
        $row['immutable_fingerprint'] = self::fingerprintValue($row);
        return $row;
    }

    private static function serviceRow(array $context, array $line): array
    {
        $naturalKey = self::naturalKey('service', $context['checkout_request_id'], $line['line_id']);
        $row = array_merge(self::commonFactContext($context, $line), [
            'service_fact_id' => self::factId('ESF', $naturalKey),
            'natural_key' => $naturalKey,
            'project_id' => $line['project_id'],
            'project_name_snapshot' => $line['project_name_snapshot'],
            'project_category_id_snapshot' => $line['project_category_id_snapshot'],
            'project_category_name_snapshot' => $line['project_category_name_snapshot'],
            'quantity' => $line['quantity'],
            'service_object' => $line['service_object'],
            'friend_counts_as_customer' => $line['friend_counts_as_customer'],
            'is_experience' => $line['is_experience'],
            'labor_amount_cents' => $line['labor_amount_cents'],
            'labor_fee_amount_cents' => array_sum(array_map(
                static function (array $allocation): int {
                    return (int)$allocation['labor_fee_cents'];
                },
                $line['labor_allocations']
            )) * $line['quantity'],
            'primary_craftsman_staff_id' => $line['primary_craftsman_staff_id'],
            'craftsmen_snapshot_json' => self::encode($line['craftsmen_snapshot']),
            'detail_remark_snapshot' => $line['detail_remark_snapshot'],
            'service_status' => 'completed',
        ]);
        $row['immutable_fingerprint'] = self::fingerprintValue($row);
        return $row;
    }

    private static function consumptionPerformanceRow(array $context, array $line): array
    {
        $naturalKey = self::naturalKey('consumption-performance', $context['checkout_request_id'], $line['line_id']);
        $row = array_merge(self::performanceCommon($context, $line, $naturalKey), [
            'fact_id' => self::factId('ECP', $naturalKey),
            'fact_type' => 'consumption_performance_recorded',
            'performance_type' => 'consumption_performance_recorded',
            'employee_id' => 0,
            'employee_name_snapshot' => '',
            'employee_type_snapshot' => '',
            'employee_type_authority_version' => 0,
            'role_snapshot' => 'service_line',
            'allocation_weight_numerator' => 0,
            'allocation_weight_denominator' => 1,
            'allocation_base_amount_cents' => $line['consumption_amount_cents'],
            'amount_cents' => $line['consumption_amount_cents'],
            'rule_code_snapshot' => 'entitlement_consumption_' . $line['consumption_mode'],
            'rule_name_snapshot' => '项目消耗业绩',
            'rule_version_snapshot' => 'entitlement-rule:' . $line['performance_rule_version'],
        ]);
        $row['immutable_fingerprint'] = self::fingerprintValue($row);
        return $row;
    }

    private static function laborPerformanceRow(array $context, array $line, array $allocation): array
    {
        $naturalKey = self::naturalKey(
            'labor-performance',
            $context['checkout_request_id'],
            $line['line_id'] . ':' . $allocation['staff_id']
        );
        $allocationGroupKey = (string)($allocation['allocation_group_key'] ?? 'normal');
        $weightTotal = 0;
        foreach ($line['labor_allocations'] as $candidate) {
            if ((string)($candidate['allocation_group_key'] ?? 'normal') === $allocationGroupKey) {
                $weightTotal += (int)($candidate['labor_weight'] ?? 0);
            }
        }
        $allocationWeightDenominator = $weightTotal > 0 ? $weightTotal : 1;
        $row = array_merge(self::performanceCommon($context, $line, $naturalKey), [
            'fact_id' => self::factId('ELP', $naturalKey),
            'fact_type' => 'labor_performance_allocated',
            'performance_type' => 'labor_performance_allocated',
            'employee_id' => $allocation['employee_id'],
            'employee_name_snapshot' => $allocation['staff_name_snapshot'],
            'employee_type_snapshot' => $allocation['employee_type_snapshot'],
            'employee_type_authority_version' => $allocation['employee_type_authority_version'],
            'role_snapshot' => $allocation['is_primary'] ? 'primary_craftsman' : 'craftsman',
            'allocation_weight_numerator' => $allocation['labor_weight'],
            'allocation_weight_denominator' => $allocationWeightDenominator,
            'allocation_base_amount_cents' => $line['labor_amount_cents'],
            'amount_cents' => $allocation['amount_cents'],
            'labor_fee_amount_cents' => $allocation['labor_fee_cents'] * $line['quantity'],
            'rule_code_snapshot' => 'entitlement_labor_' . $line['labor_mode'],
            'rule_name_snapshot' => '项目劳动业绩',
            'rule_version_snapshot' => 'entitlement-rule:' . $line['performance_rule_version'],
        ]);
        $row['immutable_fingerprint'] = self::fingerprintValue($row);
        return $row;
    }

    private static function commonFactContext(array $context, array $line): array
    {
        return [
            'business_event_no' => $context['business_event_no'],
            'command_idempotency_key' => $context['command_idempotency_key'],
            'tenant_id' => $context['tenant_id'],
            'tenant_name_snapshot' => $context['tenant_name_snapshot'],
            'organization_id' => $context['organization_id'],
            'organization_name_snapshot' => $context['organization_name_snapshot'],
            'organization_path_snapshot' => $context['organization_path_snapshot'],
            'store_id' => $context['store_id'],
            'store_name_snapshot' => $context['store_name_snapshot'],
            'member_id' => $context['member_id'],
            'member_name_snapshot' => $context['member_name_snapshot'],
            'operator_id' => $context['operator_id'],
            'operator_name_snapshot' => $context['operator_name_snapshot'],
            'business_date' => $context['business_date'],
            'business_timezone' => $context['business_timezone'],
            'occurred_at' => $context['occurred_at'],
            'settled_at' => $context['settled_at'],
            'recorded_at' => $context['recorded_at'],
            'checkout_request_id' => $context['checkout_request_id'],
            'document_id' => $context['document_id'],
            'document_no_snapshot' => $context['document_no_snapshot'],
            'source_document_type' => $context['source_type'],
            'source_document_id' => $context['source_type'] === 'service_order'
                ? $context['service_order_id']
                : ($context['source_type'] === 'reservation' ? $context['reservation_id'] : 0),
            'source_line_id' => $line['line_id'],
        ];
    }

    private static function performanceCommon(array $context, array $line, string $naturalKey): array
    {
        $common = self::commonFactContext($context, $line);
        return [
            'business_event_no' => $common['business_event_no'],
            'fact_direction' => 'forward',
            'natural_key' => $naturalKey,
            'command_idempotency_key' => $common['command_idempotency_key'],
            'fact_version' => 1,
            'reversal_of' => '',
            'status' => 'effective',
            'tenant_id' => $common['tenant_id'],
            'tenant_name_snapshot' => $common['tenant_name_snapshot'],
            'organization_id' => $common['organization_id'],
            'organization_name_snapshot' => $common['organization_name_snapshot'],
            'organization_path_snapshot' => $common['organization_path_snapshot'],
            'store_id' => $common['store_id'],
            'store_name_snapshot' => $common['store_name_snapshot'],
            'member_id' => $common['member_id'],
            'member_name_snapshot' => $common['member_name_snapshot'],
            'operator_id' => $common['operator_id'],
            'operator_name_snapshot' => $common['operator_name_snapshot'],
            'business_date' => $common['business_date'],
            'business_timezone' => $common['business_timezone'],
            'occurred_at' => $common['occurred_at'],
            'settled_at' => $common['settled_at'],
            'recorded_at' => $common['recorded_at'],
            'checkout_request_id' => $common['checkout_request_id'],
            'order_id' => $context['document_id'],
            'order_no_snapshot' => $context['document_no_snapshot'],
            'source_document_type' => 'entitlement_completion',
            'source_line_id' => $common['source_line_id'],
        ];
    }

    private static function assertKernelTotals(array $kernelTotals, array $totals): void
    {
        $map = [
            'lineCount' => 'line_count',
            'serviceQuantity' => 'service_quantity',
            'actualEntitlementAmountCents' => 'actual_entitlement_amount_cents',
            'consumptionPerformanceCents' => 'consumption_performance_cents',
            'laborPerformanceCents' => 'labor_performance_cents',
        ];
        foreach ($map as $kernelKey => $normalizedKey) {
            if (!array_key_exists($kernelKey, $kernelTotals)
                || !is_int($kernelTotals[$kernelKey])
                || $kernelTotals[$kernelKey] !== $totals[$normalizedKey]) {
                throw self::failure('kernel_totals_mismatch', ['field' => $kernelKey]);
            }
        }
    }

    private static function assertDeductionCoverage(array $deductions, array $writeoffs): void
    {
        $quantityBySource = [];
        $linesBySource = [];
        foreach ($writeoffs as $row) {
            $key = $row['holder_id'] . ':' . $row['source_detail_id'];
            $quantityBySource[$key] = (int)($quantityBySource[$key] ?? 0) + $row['quantity'];
            $linesBySource[$key][] = $row['source_line_id'];
        }
        foreach ($deductions as $row) {
            $key = $row['holder_id'] . ':' . $row['source_detail_id'];
            $expectedLines = $linesBySource[$key] ?? [];
            sort($expectedLines, SORT_STRING);
            $actualLines = $row['line_ids'];
            sort($actualLines, SORT_STRING);
            if (($quantityBySource[$key] ?? 0) !== $row['deduct_physical_times']
                || $expectedLines !== $actualLines) {
                throw self::failure('deduction_line_coverage_invalid', ['source' => $key]);
            }
            unset($quantityBySource[$key], $linesBySource[$key]);
        }
        if ($quantityBySource || $linesBySource) {
            throw self::failure('deduction_line_coverage_incomplete');
        }
    }

    private static function naturalKey(string $type, string $requestId, string $grain): string
    {
        return 'entitlement_completion:' . $type . ':v1:' . hash('sha256', $requestId . "\0" . $grain);
    }

    private static function factId(string $prefix, string $naturalKey): string
    {
        return $prefix . '-' . substr(hash('sha256', $naturalKey), 0, 40);
    }

    private static function fingerprintValue(array $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function encode($value): string
    {
        $encoded = json_encode(self::canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw self::failure('completion_json_encoding_failed');
        }
        return $encoded;
    }

    private static function canonicalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!self::isList($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }

    private static function token($value, int $maxLength, string $reason): string
    {
        if (!is_string($value)) {
            throw self::failure($reason);
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > $maxLength
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:\/-]*$/D', $value) !== 1) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function text($value, int $maxLength, string $reason): string
    {
        if (!is_string($value) || mb_strlen($value, 'UTF-8') > $maxLength) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function positiveInt($value, string $reason): int
    {
        if (!is_int($value) || $value <= 0 || $value > 1000000000000000) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function nonNegativeInt($value, string $reason): int
    {
        if (!is_int($value) || $value < 0 || $value > 1000000000000000) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function booleanInt($value, string $reason): int
    {
        if (!is_bool($value)) {
            throw self::failure($reason);
        }
        return $value ? 1 : 0;
    }

    private static function businessDate($value): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            throw self::failure('business_date_invalid');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw self::failure('business_date_invalid');
        }
        return $value;
    }

    private static function timezone($value): string
    {
        if (!is_string($value) || strlen($value) > 64) {
            throw self::failure('business_timezone_invalid');
        }
        try {
            new \DateTimeZone($value);
        } catch (\Throwable $exception) {
            throw self::failure('business_timezone_invalid');
        }
        return $value;
    }

    private static function assertExactKeys(array $value, array $expected, string $path): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw self::failure('completion_shape_invalid', [
                'path' => $path,
                'actualKeys' => $actual,
                'expectedKeys' => $expected,
            ]);
        }
    }

    private static function isList(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }

    private static function invalidArray(string $reason): array
    {
        throw self::failure($reason);
    }

    private static function failure(
        string $reason,
        array $detail = []
    ): CashierV3EntitlementCompletionPersistenceException {
        return new CashierV3EntitlementCompletionPersistenceException($reason, $detail);
    }
}
