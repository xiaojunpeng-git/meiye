<?php

namespace app\services\cashier\v3 {
    /** Test double: the writer must hit this gate before any ThinkPHP access. */
    final class CashierV3TransactionGuard
    {
        public static $inTransaction = false;

        public static function assertInTransaction(string $operation): void
        {
            if (!self::$inTransaction) {
                throw new \RuntimeException('test_transaction_required:' . $operation);
            }
        }
    }
}

namespace {
    $backendRoot = getenv('C2_COMPLETION_BACKEND_ROOT');
    $backendRoot = is_string($backendRoot) && $backendRoot !== ''
        ? rtrim($backendRoot, '/')
        : __DIR__ . '/../../../后端代码';

    require_once $backendRoot . '/app/services/cashier/v3/checkout/CashierV3EntitlementCompletionContractException.php';
    require_once $backendRoot . '/app/services/cashier/v3/checkout/CashierV3EntitlementCompletionKernel.php';
    require_once $backendRoot . '/app/services/cashier/v3/checkout/persistence/CashierV3EntitlementCompletionPersistenceException.php';
    require_once $backendRoot . '/app/services/cashier/v3/checkout/persistence/CashierV3EntitlementCompletionPlanV1.php';
    require_once $backendRoot . '/app/services/cashier/v3/checkout/persistence/CashierV3EntitlementCompletionOccupationWriter.php';
    require_once $backendRoot . '/app/services/cashier/v3/checkout/persistence/ThinkPhpCashierV3EntitlementCompletionWriter.php';

    use app\services\cashier\v3\checkout\CashierV3EntitlementCompletionKernel;
    use app\services\cashier\v3\checkout\persistence\CashierV3EntitlementCompletionPersistenceException;
    use app\services\cashier\v3\checkout\persistence\CashierV3EntitlementCompletionPlanV1;
    use app\services\cashier\v3\checkout\persistence\ThinkPhpCashierV3EntitlementCompletionWriter;

    $passed = 0;
    $failed = 0;

    function ecpAssert(string $name, bool $condition, string $detail = ''): void
    {
        global $passed, $failed;
        if ($condition) {
            $passed++;
            echo "PASS {$name}\n";
            return;
        }
        $failed++;
        echo "FAIL {$name}" . ($detail === '' ? '' : ': ' . $detail) . "\n";
    }

    function ecpReason(callable $callable): string
    {
        try {
            $callable();
        } catch (CashierV3EntitlementCompletionPersistenceException $exception) {
            return $exception->reason();
        } catch (\Throwable $exception) {
            return 'UNEXPECTED:' . get_class($exception) . ':' . $exception->getMessage();
        }
        return '';
    }

    function ecpStaffSnapshots(): array
    {
        return [[
            'staffId' => 11,
            'employeeId' => 1011,
            'staffName' => '手艺人甲',
            'staffVersion' => 111,
            'storeId' => 7,
            'employeeTypeCodeSnapshot' => 'internal',
            'employeeTypeAuthorityVersion' => 21,
        ], [
            'staffId' => 12,
            'employeeId' => 1012,
            'staffName' => '外包手艺人乙',
            'staffVersion' => 112,
            'storeId' => 7,
            'employeeTypeCodeSnapshot' => 'outsourced',
            'employeeTypeAuthorityVersion' => 22,
        ]];
    }

    function ecpAllocation(
        int $staffId,
        int $sequence,
        int $amountCents,
        int $weight
    ): array {
        return [
            'staffId' => $staffId,
            'isPrimary' => $sequence === 1,
            'sequence' => $sequence,
            'amountCents' => $amountCents,
            'staffVersion' => 100 + $staffId,
            'staffName' => $staffId === 11 ? '手艺人甲' : '外包手艺人乙',
            'storeId' => 7,
            'laborWeight' => $weight,
        ];
    }

    function ecpLine(
        string $lineId,
        int $quantity,
        int $actualAmount,
        int $laborAmount,
        string $serviceObject,
        bool $experience,
        array $allocations
    ): array {
        return [
            'lineId' => $lineId,
            'sortNo' => $lineId === 'entitlement-line-001' ? 10 : 20,
            'source' => [
                'entitlementInstanceType' => 'card_holder',
                'entitlementInstanceId' => 2001,
                'sourceKind' => 'count_card',
                'isGift' => false,
                'giftSourceType' => 'none',
                'giftId' => 0,
                'giftVersion' => 0,
                'holderId' => 2001,
                'originOrderId' => 5001,
                'sourceNameSnapshot' => '护理次卡',
                'sourceCodeSnapshot' => 'CARD-2001',
                'sourceDetailId' => 3001,
                'projectId' => 4001,
                'projectNameSnapshot' => '深层护理',
                'projectCategoryIdSnapshot' => 51,
                'projectCategoryNameSnapshot' => '面部护理',
                'sourceVersion' => 81,
                'detailVersion' => 131,
                'purchaseAmountCents' => 10000,
                'totalPurchaseTimes' => 5,
                'consumedTimesAtLock' => 2,
                'amountCalculationVersion' => 'cumulative-half-up-cent-v2',
            ],
            'quantity' => $quantity,
            'actualEntitlementAmountCents' => $actualAmount,
            'performanceRuleSnapshot' => [
                'ruleVersion' => 301,
                'consumptionMode' => 'actual_entitlement_amount',
                'consumptionConfiguredUnitAmountCents' => 0,
                'laborMode' => 'project_configured_amount',
                'laborConfiguredUnitAmountCents' => 1000,
            ],
            'consumptionPerformance' => [
                'mode' => 'actual_entitlement_amount',
                'amountCents' => $actualAmount,
            ],
            'laborPerformance' => [
                'mode' => 'project_configured_amount',
                'amountCents' => $laborAmount,
                'allocations' => $allocations,
            ],
            'serviceSnapshot' => [
                'serviceObject' => $serviceObject,
                'isExperience' => $experience,
                'craftsmen' => $allocations,
                'primaryCraftsmanId' => $allocations[0]['staffId'],
                'businessDate' => '2026-07-29',
                'businessTimezone' => 'Asia/Shanghai',
                'occurredAt' => 1785283200,
                'settledAt' => 1785283260,
                'recordedAt' => 1785283261,
                'sourceType' => 'direct',
                'serviceOrderId' => 0,
                'reservationId' => 0,
                'occupationContributors' => [],
            ],
        ];
    }

    function ecpKernelPlan(): array
    {
        $lineOne = ecpLine(
            'entitlement-line-001',
            1,
            3333,
            1000,
            'self',
            false,
            [ecpAllocation(11, 1, 334, 1), ecpAllocation(12, 2, 666, 2)]
        );
        $lineTwo = ecpLine(
            'entitlement-line-002',
            2,
            6667,
            2000,
            'friend',
            true,
            [ecpAllocation(12, 1, 2000, 2)]
        );
        return [
            'contractVersion' => CashierV3EntitlementCompletionKernel::CONTRACT_VERSION,
            'action' => CashierV3EntitlementCompletionKernel::ACTION,
            'composition' => CashierV3EntitlementCompletionKernel::COMPOSITION_ENTITLEMENT_ONLY,
            'persistenceStatus' => 'not_persisted',
            'requiresGatewayTransaction' => true,
            'workspaceId' => 'workspace-001',
            'stateContextId' => 'state-context-001',
            'memberId' => 1001,
            'storeId' => 7,
            'operatorId' => 21,
            'businessDate' => '2026-07-29',
            'businessTimezone' => 'Asia/Shanghai',
            'occurredAt' => 1785283200,
            'settledAt' => 1785283260,
            'recordedAt' => 1785283261,
            'dimensionSnapshot' => [
                'tenantId' => 'tenant-1',
                'organizationId' => 3,
                'organizationName' => '华东区域',
                'organizationPath' => '/1/3/',
                'storeId' => 7,
                'storeName' => '旗舰店',
                'memberId' => 1001,
                'memberName' => '会员甲',
                'operatorId' => 21,
                'operatorName' => '收银员甲',
            ],
            'source' => [
                'type' => 'direct',
                'serviceOrderId' => 0,
                'reservationId' => 0,
            ],
            'entitlementDeductions' => [[
                'sourceKey' => 'card_holder:2001:3001',
                'entitlementInstanceType' => 'card_holder',
                'entitlementInstanceId' => 2001,
                'sourceKind' => 'count_card',
                'isGift' => false,
                'giftSourceType' => 'none',
                'giftId' => 0,
                'giftVersion' => 0,
                'holderId' => 2001,
                'originOrderId' => 5001,
                'sourceDetailId' => 3001,
                'projectId' => 4001,
                'sourceVersion' => 81,
                'detailVersion' => 131,
                'expectedPhysicalRemainingTimes' => 5,
                'deductPhysicalTimes' => 3,
                'convertCurrentSourceOccupiedTimes' => 0,
                'lineIds' => ['entitlement-line-001', 'entitlement-line-002'],
            ]],
            'linePlans' => [$lineOne, $lineTwo],
            'totals' => [
                'lineCount' => 2,
                'serviceQuantity' => 3,
                'actualEntitlementAmountCents' => 10000,
                'consumptionPerformanceCents' => 10000,
                'laborPerformanceCents' => 3000,
            ],
        ];
    }

    function ecpPersistenceContext(): array
    {
        return [
            'contractVersion' => CashierV3EntitlementCompletionPlanV1::CONTRACT_VERSION,
            'checkoutRequestId' => 'checkout-request-001',
            'commandIdempotencyKey' => 'idem-entitlement-completion-001',
            'businessEventNo' => 'EVT-ENTITLEMENT-001',
            'documentId' => 'ECR-DOCUMENT-001',
            'documentNo' => 'ECR-DOCUMENT-001',
            'tenantNameSnapshot' => '测试商户',
            'staffSnapshots' => ecpStaffSnapshots(),
        ];
    }

    $kernel = ecpKernelPlan();
    $context = ecpPersistenceContext();
    $plan = CashierV3EntitlementCompletionPlanV1::fromKernelPlan($kernel, $context);

    ecpAssert('ECP-01 actual cart quantity is preserved in separate writeoff rows',
        count($plan->writeoffRows()) === 2
        && array_column($plan->writeoffRows(), 'quantity') === [1, 2]
        && array_column($plan->writeoffRows(), 'actual_entitlement_amount_cents') === [3333, 6667]);
    ecpAssert('ECP-02 same entitlement source deducts aggregate quantity once',
        count($plan->deductions()) === 1
        && $plan->deductions()[0]['deduct_physical_times'] === 3
        && $plan->deductions()[0]['expected_physical_remaining_times'] === 5);
    ecpAssert('ECP-03 service facts remain one per cart line',
        count($plan->serviceRows()) === 2
        && array_column($plan->serviceRows(), 'source_line_id')
            === ['entitlement-line-001', 'entitlement-line-002']);
    ecpAssert('ECP-04 self friend and experience snapshots remain authoritative',
        $plan->writeoffRows()[0]['service_object'] === 'self'
        && $plan->writeoffRows()[0]['is_experience'] === 0
        && $plan->writeoffRows()[1]['service_object'] === 'friend'
        && $plan->writeoffRows()[1]['is_experience'] === 1);

    $performance = $plan->performanceRows();
    $consumptionRows = array_values(array_filter($performance, static function (array $row): bool {
        return $row['performance_type'] === 'consumption_performance_recorded';
    }));
    $laborRows = array_values(array_filter($performance, static function (array $row): bool {
        return $row['performance_type'] === 'labor_performance_allocated';
    }));
    ecpAssert('ECP-05 consumption performance remains line-grained',
        count($consumptionRows) === 2
        && array_sum(array_column($consumptionRows, 'amount_cents')) === 10000);
    ecpAssert('ECP-06 labor performance is line plus employee grained and exact',
        count($laborRows) === 3
        && array_sum(array_column($laborRows, 'amount_cents')) === 3000
        && array_column($laborRows, 'employee_id') === [1011, 1012, 1012]);
    $independentPrimary = ecpAllocation(11, 1, 1000, 100);
    $independentPrimary['allocationGroupKey'] = 'independent:67';
    $independentPrimary['performanceIndependent'] = true;
    $normalCraftsman = ecpAllocation(12, 2, 1000, 100);
    $groupedKernel = $kernel;
    $groupedKernel['linePlans'][0] = ecpLine(
        'entitlement-line-001', 1, 3333, 1000, 'self', false,
        [$independentPrimary, $normalCraftsman]
    );
    $groupedKernel['totals']['laborPerformanceCents'] = 3000;
    $groupedPlan = CashierV3EntitlementCompletionPlanV1::fromKernelPlan($groupedKernel, $context);
    $groupedLaborRows = array_values(array_filter($groupedPlan->performanceRows(), static function (array $row): bool {
        return $row['performance_type'] === 'labor_performance_allocated'
            && $row['source_line_id'] === 'entitlement-line-001';
    }));
    ecpAssert('ECP-06A independent and normal groups each persist a 100% denominator',
        count($groupedLaborRows) === 2
        && array_column($groupedLaborRows, 'allocation_weight_denominator') === [100, 100]
        && strpos($groupedPlan->serviceRows()[0]['craftsmen_snapshot_json'], 'allocation_group_key') !== false);
    ecpAssert('ECP-07 craftsmen name type and authority versions are frozen',
        $laborRows[0]['employee_name_snapshot'] === '手艺人甲'
        && $laborRows[0]['employee_type_snapshot'] === 'internal'
        && $laborRows[0]['employee_type_authority_version'] === 21
        && $laborRows[1]['employee_type_snapshot'] === 'outsourced'
        && $laborRows[1]['employee_type_authority_version'] === 22);
    ecpAssert('ECP-08 four business times and dimensions are frozen on facts',
        $plan->serviceRows()[0]['business_date'] === '2026-07-29'
        && $plan->serviceRows()[0]['business_timezone'] === 'Asia/Shanghai'
        && $plan->serviceRows()[0]['occurred_at'] === 1785283200
        && $plan->serviceRows()[0]['settled_at'] === 1785283260
        && $plan->serviceRows()[0]['recorded_at'] === 1785283261
        && $plan->serviceRows()[0]['store_id'] === 7
        && $plan->serviceRows()[0]['member_id'] === 1001);

    $receiptId = CashierV3EntitlementCompletionPlanV1::receiptId(
        'tenant-1',
        'checkout-request-001',
        'idem-entitlement-completion-001'
    );
    ecpAssert('ECP-09 stable receipt id has the frozen public format',
        preg_match('/^ECR-[0-9a-f]{40}$/D', $receiptId) === 1
        && $receiptId === CashierV3EntitlementCompletionPlanV1::receiptId(
            'tenant-1',
            'checkout-request-001',
            'idem-entitlement-completion-001'
        ));

    $permutedContext = $context;
    $permutedContext['staffSnapshots'] = array_reverse($permutedContext['staffSnapshots']);
    $permuted = CashierV3EntitlementCompletionPlanV1::fromKernelPlan($kernel, $permutedContext);
    ecpAssert('ECP-10 authority set order does not change persistence fingerprint',
        $permuted->fingerprint() === $plan->fingerprint());

    $differentKernel = $kernel;
    $differentKernel['linePlans'][1]['serviceSnapshot']['isExperience'] = false;
    $different = CashierV3EntitlementCompletionPlanV1::fromKernelPlan($differentKernel, $context);
    ecpAssert('ECP-11 same idempotency identity with changed business payload changes fingerprint',
        $different->fingerprint() !== $plan->fingerprint());

    $badCoverage = $kernel;
    $badCoverage['entitlementDeductions'][0]['deductPhysicalTimes'] = 2;
    ecpAssert('ECP-12 deduction must equal actual cart quantity sum',
        ecpReason(static function () use ($badCoverage, $context): void {
            CashierV3EntitlementCompletionPlanV1::fromKernelPlan($badCoverage, $context);
        }) === 'deduction_line_coverage_invalid');

    $missingStaff = $context;
    $missingStaff['staffSnapshots'] = [$missingStaff['staffSnapshots'][0]];
    ecpAssert('ECP-13 every craftsman requires a locked employee snapshot',
        ecpReason(static function () use ($kernel, $missingStaff): void {
            CashierV3EntitlementCompletionPlanV1::fromKernelPlan($kernel, $missingStaff);
        }) === 'labor_staff_snapshot_mismatch');

    $duplicateLine = $kernel;
    $duplicateLine['linePlans'][1]['lineId'] = 'entitlement-line-001';
    $duplicateLine['linePlans'][1]['serviceSnapshot']['craftsmen'] = [$duplicateLine['linePlans'][1]['laborPerformance']['allocations'][0]];
    ecpAssert('ECP-14 duplicate line identity is rejected before persistence',
        ecpReason(static function () use ($duplicateLine, $context): void {
            CashierV3EntitlementCompletionPlanV1::fromKernelPlan($duplicateLine, $context);
        }) === 'completion_line_duplicate');

    $transactionFailure = '';
    try {
        (new ThinkPhpCashierV3EntitlementCompletionWriter())->persistInTx($plan);
    } catch (\RuntimeException $exception) {
        $transactionFailure = $exception->getMessage();
    }
    ecpAssert('ECP-15 writer rejects calls without a caller-owned transaction',
        $transactionFailure === 'test_transaction_required:entitlementCompletion.persistInTx');

    $writerSource = (string)file_get_contents(
        $backendRoot . '/app/services/cashier/v3/checkout/persistence/ThinkPhpCashierV3EntitlementCompletionWriter.php'
    );
    ecpAssert('ECP-16 writer never opens commits or rolls back the business transaction',
        strpos($writerSource, 'Db::transaction(') === false
        && strpos($writerSource, 'startTrans(') === false
        && strpos($writerSource, '->commit(') === false
        && strpos($writerSource, '->rollback(') === false);
    ecpAssert('ECP-17 writer uses legacy rights as authorities and unified performance facts',
        strpos($writerSource, "HOLDER_TABLE = 'user_card_holder'") !== false
        && strpos($writerSource, "DETAIL_TABLE = 'store_order_cart_info'") !== false
        && strpos($writerSource, "PERFORMANCE_TABLE = 'cashier_v3_performance_fact'") !== false);
    ecpAssert('ECP-18 non-direct occupation conversion fails closed without an authority adapter',
        strpos($writerSource, 'completion_occupation_writer_required') !== false
        && strpos($writerSource, 'convert_current_source_occupied_times') !== false);

    $migration = (string)file_get_contents(
        $backendRoot . '/database/upgrades/2026-07-29-收银V3权益完成权威写入/02-正式升级.sql'
    );
    ecpAssert('ECP-19 migration adds receipt writeoff service and reuses unified performance',
        substr_count($migration, 'CREATE TABLE IF NOT EXISTS') === 3
        && strpos($migration, '`eb_cashier_v3_entitlement_completion_receipt`') !== false
        && strpos($migration, '`eb_cashier_v3_entitlement_writeoff_fact`') !== false
        && strpos($migration, '`eb_cashier_v3_entitlement_service_fact`') !== false
        && strpos($migration, 'CREATE TABLE IF NOT EXISTS `eb_cashier_v3_performance_fact`') === false);

    echo "RESULT {$passed} passed, {$failed} failed\n";
    exit($failed === 0 ? 0 : 1);
}
