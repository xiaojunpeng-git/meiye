<?php

namespace app\services\cashier\v3 {
    // DB-free contract suite: the real guard is covered by repository integration tests.
    class CashierV3TransactionGuard
    {
        public static function assertInTransaction(string $operation): void
        {
        }
    }
}

namespace {
    $backendRoot = getenv('SUBMISSION_PREPARATION_BACKEND_ROOT')
        ?: dirname(__DIR__, 3) . '/后端代码';
    $backend = $backendRoot . '/app/services/cashier/v3';
    require $backend . '/CashierV3ResultCode.php';
    require $backend . '/CashierV3CommandException.php';
    require $backend . '/CashierV3ResourceScope.php';
    require $backend . '/CashierV3ResourceKindCatalog.php';
    require $backend . '/CashierV3OperatorScope.php';
    require $backend . '/CashierV3DataScopeContext.php';
    require $backend . '/settlement/CashierV3CheckoutSettlementContractException.php';
    require $backend . '/settlement/CashierV3CheckoutSettlementCanonicalizer.php';
    require $backend . '/settlement/CashierV3CheckoutSettlementStateMachine.php';
    require $backend . '/settlement/CashierV3CheckoutSettlementIdFactory.php';
    require $backend . '/settlement/CashierV3CheckoutSettlementKernel.php';
    require $backend . '/settlement/CashierV3CheckoutVerifiedSourceSet.php';
    require $backend . '/settlement/CashierV3CheckoutRequestRepository.php';
    require $backend . '/settlement/CashierV3CheckoutProjectionServices.php';
    require $backend . '/settlement/CashierV3CheckoutDraftAuthorityRebuilder.php';
    require $backend . '/settlement/CashierV3CheckoutVerifiedResourcePlan.php';
    require $backend . '/settlement/CashierV3CheckoutResourcePlanRepository.php';
    require $backend . '/settlement/CashierV3CheckoutSubmissionPreparationServices.php';

    use app\services\cashier\v3\CashierV3CommandException;
    use app\services\cashier\v3\CashierV3DataScopeContext;
    use app\services\cashier\v3\CashierV3OperatorScope;
    use app\services\cashier\v3\CashierV3ResourceScope;
    use app\services\cashier\v3\settlement\CashierV3CheckoutRequestRepository;
    use app\services\cashier\v3\settlement\CashierV3CheckoutResourcePlanRepository;
    use app\services\cashier\v3\settlement\CashierV3CheckoutSettlementKernel;
    use app\services\cashier\v3\settlement\CashierV3CheckoutSubmissionPreparationServices;
    use app\services\cashier\v3\settlement\CashierV3CheckoutVerifiedResourcePlan;
    use app\services\cashier\v3\settlement\CashierV3CheckoutVerifiedSourceSet;

    final class SubmissionPreparationTrace
    {
        public $events = [];
    }

    final class SubmissionPreparationRequestRepository implements CashierV3CheckoutRequestRepository
    {
        public $aggregate;
        public $lockCalls = 0;
        public $persistCalls = 0;
        public $lastKernel = [];
        private $trace;

        public function __construct(array $aggregate, SubmissionPreparationTrace $trace)
        {
            $this->aggregate = $aggregate;
            $this->trace = $trace;
        }

        public function lockAggregateForEditInTx(
            string $requestId,
            int $expectedVersion,
            string $workspaceId,
            string $stateContextId,
            CashierV3OperatorScope $operatorScope,
            CashierV3DataScopeContext $dataScope
        ): array {
            $this->lockCalls++;
            submissionPreparationAssert(
                'request repository receives the exact N identity',
                $requestId === (string)$this->aggregate['request']['request_id']
                    && $expectedVersion === (int)$this->aggregate['request']['request_version']
                    && $workspaceId === (string)$this->aggregate['request']['workspace_id']
                    && $stateContextId === (string)$this->aggregate['request']['state_context_id']
            );
            return $this->aggregate;
        }

        public function lockAggregateForSubmitInTx(
            string $requestId,
            int $expectedVersion,
            string $workspaceId,
            string $stateContextId,
            CashierV3OperatorScope $operatorScope,
            CashierV3DataScopeContext $dataScope
        ): array {
            throw new \LogicException('not used by submission preparation contract');
        }

        public function markSucceededInTx(
            string $requestId,
            int $expectedVersion,
            string $commandIdempotencyKey,
            string $salesOrderId,
            int $settledAt,
            CashierV3OperatorScope $operatorScope,
            CashierV3DataScopeContext $dataScope
        ): array {
            throw new \LogicException('not used by submission preparation contract');
        }

        public function readLatestEditingProjection(
            string $workspaceId,
            string $stateContextId,
            CashierV3OperatorScope $operatorScope,
            CashierV3DataScopeContext $dataScope
        ) {
            return null;
        }

        public function lockCurrentForKernelInTx(
            string $requestId,
            string $creationIdempotencyKey,
            CashierV3OperatorScope $operatorScope,
            CashierV3DataScopeContext $dataScope
        ) {
            return $this->aggregate['currentRequest'];
        }

        public function persistKernelPlanInTx(
            array $kernelResult,
            CashierV3CheckoutVerifiedSourceSet $verifiedSources,
            CashierV3OperatorScope $operatorScope,
            CashierV3DataScopeContext $dataScope
        ): array {
            $this->persistCalls++;
            $this->lastKernel = $kernelResult;
            $this->trace->events[] = 'request:' . (int)$kernelResult['requestVersion'];
            return [
                'requestId' => (string)$kernelResult['requestId'],
                'requestVersion' => (int)$kernelResult['requestVersion'],
                'requestStatus' => (string)$kernelResult['requestStatus'],
                'replayed' => false,
                'eventless' => true,
            ];
        }
    }

    final class SubmissionPreparationPlanRepository implements CashierV3CheckoutResourcePlanRepository
    {
        public $persistCalls = 0;
        public $lastPlan;
        private $trace;

        public function __construct(SubmissionPreparationTrace $trace)
        {
            $this->trace = $trace;
        }

        public function persistInTx(
            string $requestId,
            CashierV3CheckoutVerifiedResourcePlan $plan,
            int $preparedAt
        ): array {
            $this->persistCalls++;
            $this->lastPlan = $plan;
            $this->trace->events[] = 'plan:' . $plan->boundRequestVersion();
            return [
                'planId' => 1,
                'requestId' => $requestId,
                'boundRequestVersion' => $plan->boundRequestVersion(),
                'resourcePlanFingerprint' => $plan->fingerprint(),
                'resourceCount' => $plan->resourceCount(),
                'roleCount' => $plan->roleCount(),
                'replayed' => false,
            ];
        }

        public function invalidateInTx(
            string $requestId,
            string $tenantId,
            int $storeId,
            int $boundRequestVersion,
            string $reason
        ): array {
            return [];
        }

        public function markConsumedInTx(
            string $requestId,
            string $tenantId,
            int $storeId,
            int $boundRequestVersion,
            string $expectedFingerprint
        ): array {
            return [];
        }

        public function supersedeActiveBeforeVersionInTx(
            string $requestId,
            string $tenantId,
            int $storeId,
            int $nextVersion,
            string $reason
        ): array {
            return [];
        }
    }

    $passed = 0;
    $failed = 0;

    function submissionPreparationAssert(string $name, bool $condition): void
    {
        global $passed, $failed;
        if ($condition) {
            $passed++;
            echo "PASS: {$name}\n";
            return;
        }
        $failed++;
        echo "FAIL: {$name}\n";
    }

    function submissionPreparationSnapshot(int $balanceAmount = 0): array
    {
        $paymentAmount = 10000 - $balanceAmount;
        $snapshot = [
            'contractVersion' => CashierV3CheckoutSettlementKernel::AUTHORITY_CONTRACT_VERSION,
            'authorityOrigin' => 'server_final_lock_snapshot',
            'authoritySnapshotVersion' => 7,
            'authoritySnapshotFingerprint' => '',
            'tenantId' => '0',
            'organizationId' => '3',
            'organizationPath' => '/1/3/',
            'organizationName' => '华东区域',
            'storeId' => 7,
            'storeName' => '测试门店',
            'workspaceId' => 'ws:7:21:submission-state',
            'stateContextId' => 'submission-state',
            'permissionSnapshotFingerprint' => 'roles:' . str_repeat('a', 32),
            'memberId' => 1001,
            'memberName' => '历史会员名称',
            'operatorId' => 21,
            'operatorName' => '历史收银员名称',
            'businessDate' => '2026-07-29',
            'businessTimezone' => 'Asia/Shanghai',
            'occurredAt' => 1785297600,
            'recordedAt' => 1785297600,
            'sourceDocument' => [
                'type' => 'cashier_workspace',
                'id' => 'ws:7:21:submission-state',
                'no' => 'CHECKOUT-SUBMISSION-001',
            ],
            'saleLines' => [[
                'authorityKey' => 'sale:project:501',
                'saleClassification' => 'formal_sale',
                'sourceType' => 'project',
                'sourceId' => 501,
                'sourceVersion' => 9,
                'quantity' => 1,
                'originalAmountCents' => 11000,
                'discountAmountCents' => 1000,
                'saleAmountCents' => 10000,
                'sourceNameSnapshot' => '历史项目名称',
                'sourceCodeSnapshot' => 'PROJECT-501',
                'categoryIdSnapshot' => 51,
                'categoryNameSnapshot' => '历史分类名称',
            ]],
            'entitlementLines' => [],
            'paymentDetails' => $paymentAmount > 0 ? [[
                'paymentAuthorityKey' => 'payment:unionpay',
                'method' => CashierV3CheckoutSettlementKernel::PAYMENT_UNIONPAY,
                'amountCents' => $paymentAmount,
                'businessTime' => 1785297600,
                'externalTransactionNo' => '',
                'remark' => '',
            ]] : [],
            'balanceDeduction' => $balanceAmount === 0 ? [
                'authorityKey' => '',
                'accountId' => '',
                'accountVersion' => 0,
                'amountCents' => 0,
            ] : [
                'authorityKey' => 'balance:member:1001',
                'accountId' => 'member-balance-1001',
                'accountVersion' => 5,
                'amountCents' => $balanceAmount,
            ],
            'debt' => [
                'authorityKey' => '',
                'policyVersion' => 0,
                'amountCents' => 0,
            ],
        ];
        $snapshot['authoritySnapshotFingerprint'] =
            CashierV3CheckoutSettlementKernel::authorityFingerprint($snapshot);
        return $snapshot;
    }

    function submissionPreparationMap(array $row, array $map): array
    {
        $mapped = [];
        foreach ($map as $source => $target) {
            $mapped[$target] = $row[$source];
        }
        return $mapped;
    }

    function submissionPreparationAggregate(int $balanceAmount = 0): array
    {
        $kernel = CashierV3CheckoutSettlementKernel::saveDraft([
            'contractVersion' => CashierV3CheckoutSettlementKernel::CONTRACT_VERSION,
            'operation' => CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT,
            'idempotencyKey' => 'CHECKOUT_PREPARE-00000000-0000-4000-8000-000000000001',
            'workspaceId' => 'ws:7:21:submission-state',
            'stateContextId' => 'submission-state',
            'permissionSnapshotFingerprint' => 'roles:' . str_repeat('a', 32),
        ], submissionPreparationSnapshot($balanceAmount), null, str_repeat('fixture-secret-', 3));
        $plan = $kernel['persistencePlan'];
        $request = submissionPreparationMap($plan['request'], [
            'requestId' => 'request_id',
            'tenantId' => 'tenant_id',
            'organizationId' => 'organization_id',
            'organizationPath' => 'organization_path',
            'organizationNameSnapshot' => 'organization_name_snapshot',
            'workspaceId' => 'workspace_id',
            'stateContextId' => 'state_context_id',
            'storeId' => 'store_id',
            'storeNameSnapshot' => 'store_name_snapshot',
            'memberId' => 'member_id',
            'memberNameSnapshot' => 'member_name_snapshot',
            'operatorId' => 'operator_id',
            'operatorNameSnapshot' => 'operator_name_snapshot',
            'requestVersion' => 'request_version',
            'requestStatus' => 'request_status',
            'composition' => 'composition',
            'businessDate' => 'business_date',
            'businessTimezone' => 'business_timezone',
            'operationOccurredAt' => 'operation_occurred_at',
            'recordedAt' => 'recorded_at',
            'sourceDocumentType' => 'source_document_type',
            'sourceDocumentId' => 'source_document_id',
            'sourceDocumentNo' => 'source_document_no',
            'salesAmountCents' => 'sales_amount_cents',
            'receivableAmountCents' => 'receivable_amount_cents',
            'selectedPaymentAmountCents' => 'selected_payment_amount_cents',
            'balanceDeductionAmountCents' => 'balance_deduction_amount_cents',
            'balanceAuthorityKey' => 'balance_authority_key',
            'balanceAccountId' => 'balance_account_id',
            'balanceAccountVersion' => 'balance_account_version',
            'debtAmountCents' => 'debt_amount_cents',
            'debtAuthorityKey' => 'debt_authority_key',
            'debtPolicyVersion' => 'debt_policy_version',
            'cashPerformanceAmountCents' => 'cash_performance_amount_cents',
            'entitlementActualAmountCents' => 'entitlement_actual_amount_cents',
            'authoritySnapshotVersion' => 'authority_snapshot_version',
            'authorityFingerprint' => 'authority_fingerprint',
            'aggregateFingerprint' => 'aggregate_fingerprint',
            'creationIdempotencyKey' => 'creation_idempotency_key',
            'lastIdempotencyKey' => 'last_idempotency_key',
            'lastOperationFingerprint' => 'last_operation_fingerprint',
            'lastOperation' => 'last_operation',
        ]);
        $lines = [];
        foreach ($plan['lineDrafts'] as $index => $line) {
            $lines[] = ['id' => $index + 1] + submissionPreparationMap($line, [
                'lineId' => 'line_id',
                'requestId' => 'request_id',
                'draftVersion' => 'draft_version',
                'draftStatus' => 'draft_status',
                'tenantId' => 'tenant_id',
                'storeId' => 'store_id',
                'memberId' => 'member_id',
                'lineRole' => 'line_role',
                'authorityKey' => 'authority_key',
                'sourceKind' => 'source_kind',
                'sourceType' => 'source_type',
                'sourceId' => 'source_id',
                'entitlementSourceDetailId' => 'entitlement_source_detail_id',
                'sourceVersion' => 'source_version',
                'projectId' => 'project_id',
                'projectVersion' => 'project_version',
                'quantity' => 'quantity',
                'originalAmountCents' => 'original_amount_cents',
                'discountAmountCents' => 'discount_amount_cents',
                'saleAmountCents' => 'sale_amount_cents',
                'entitlementActualAmountCents' => 'entitlement_actual_amount_cents',
                'sourceNameSnapshot' => 'source_name_snapshot',
                'sourceCodeSnapshot' => 'source_code_snapshot',
                'projectNameSnapshot' => 'project_name_snapshot',
                'categoryIdSnapshot' => 'category_id_snapshot',
                'categoryNameSnapshot' => 'category_name_snapshot',
                'lineFingerprint' => 'line_fingerprint',
                'sortNo' => 'sort_no',
            ]);
        }
        $payments = [];
        foreach ($plan['paymentDrafts'] as $index => $payment) {
            $payments[] = ['id' => $index + 1] + submissionPreparationMap($payment, [
                'paymentDraftId' => 'payment_draft_id',
                'requestId' => 'request_id',
                'draftVersion' => 'draft_version',
                'tenantId' => 'tenant_id',
                'storeId' => 'store_id',
                'memberId' => 'member_id',
                'operatorId' => 'operator_id',
                'paymentAuthorityKey' => 'payment_authority_key',
                'paymentMethod' => 'payment_method',
                'amountCents' => 'amount_cents',
                'externalTransactionNo' => 'external_transaction_no',
                'remark' => 'remark',
                'businessDate' => 'business_date',
                'businessTimezone' => 'business_timezone',
                'operationOccurredAt' => 'operation_occurred_at',
                'recordedAt' => 'recorded_at',
                'operatorNameSnapshot' => 'operator_name_snapshot',
                'sourceDocumentType' => 'source_document_type',
                'sourceDocumentId' => 'source_document_id',
                'sourceDocumentNo' => 'source_document_no',
                'paymentFingerprint' => 'payment_fingerprint',
                'draftStatus' => 'draft_status',
                'sortNo' => 'sort_no',
            ]);
        }
        return [
            'request' => $request,
            'lines' => $lines,
            'payments' => $payments,
            'sources' => [],
            'currentRequest' => [
                'requestId' => $kernel['requestId'],
                'tenantId' => $plan['request']['tenantId'],
                'workspaceId' => $plan['request']['workspaceId'],
                'version' => $kernel['requestVersion'],
                'status' => $kernel['requestStatus'],
                'creationIdempotencyKey' => $plan['request']['creationIdempotencyKey'],
                'lastIdempotencyKey' => $plan['request']['lastIdempotencyKey'],
                'lastOperationFingerprint' => $kernel['operationFingerprint'],
            ],
            'verifiedSources' => CashierV3CheckoutVerifiedSourceSet::fromServerVerifiedAuthorityRows(
                '0',
                7,
                []
            ),
        ];
    }

    function submissionPreparationDataScope(string $tenantId = '0'): CashierV3DataScopeContext
    {
        return new CashierV3DataScopeContext(
            21,
            121,
            7,
            $tenantId,
            '3',
            [7],
            CashierV3DataScopeContext::MODE_STORES,
            [],
            false,
            '',
            'roles:' . str_repeat('b', 32),
            ['cashier.v3.cashier'],
            ['id' => 21, 'employee_id' => 121, 'staff_name' => '当前收银员']
        );
    }

    function submissionPreparationDiscovery(array $aggregate): array
    {
        $requestId = (string)$aggregate['request']['request_id'];
        $requestVersion = (int)$aggregate['request']['request_version'];
        $resources = [
            [
                'kind' => 'member',
                'id' => '1001',
                'expectedVersion' => 4,
                'roles' => ['checkout_member'],
                'accessMode' => 'read',
                'providerContractVersion' => 'member-authority-v1',
                'authorityFingerprint' => hash('sha256', 'member|1001|4'),
            ],
            [
                'kind' => 'catalog_product',
                'id' => '501',
                'expectedVersion' => 9,
                'roles' => ['sale_catalog_product'],
                'accessMode' => 'read',
                'providerContractVersion' => 'sale-catalog-v1',
                'authorityFingerprint' => hash('sha256', 'catalog_product|501|9'),
            ],
            [
                'kind' => 'checkout_request',
                'id' => $requestId,
                'expectedVersion' => $requestVersion,
                'roles' => ['visible_checkout_request'],
                'accessMode' => 'read',
                'providerContractVersion' => 'checkout-request-v1',
                'authorityFingerprint' => hash('sha256', 'checkout_request|' . $requestId . '|' . $requestVersion),
            ],
            [
                'kind' => 'cashier_workspace',
                'id' => 'ws:7:21:submission-state',
                'expectedVersion' => 8,
                'roles' => ['visible_cashier_workspace'],
                'accessMode' => 'read',
                'providerContractVersion' => 'cashier-workspace-v1',
                'authorityFingerprint' => hash('sha256', 'cashier_workspace|8'),
            ],
        ];
        return [
            'contractVersion' => 'cashier-v3-server-resource-discovery-v1',
            'resources' => $resources,
            'fingerprint' => hash('sha256', json_encode($resources, JSON_UNESCAPED_UNICODE)),
        ];
    }

    function submissionPreparationScope(array $aggregate): array
    {
        $operatorScope = new CashierV3OperatorScope(7, 21, '3', '0');
        $dataScope = submissionPreparationDataScope();
        $requestId = (string)$aggregate['request']['request_id'];
        $requestVersion = (int)$aggregate['request']['request_version'];
        $workspaceId = 'ws:7:21:submission-state';
        $discovery = submissionPreparationDiscovery($aggregate);
        $resources = [];
        foreach ($discovery['resources'] as $row) {
            $resources[$row['kind'] . ':' . $row['id']] = $row;
        }
        $contexts = [
            [
                'kind' => 'member',
                'id' => '1001',
                'expected_version' => 4,
                'roles' => $resources['member:1001']['roles'],
                'server_resource_access_mode' => $resources['member:1001']['accessMode'],
                'server_resource_provider_contract_version' => $resources['member:1001']['providerContractVersion'],
                'server_resource_authority_fingerprint' => $resources['member:1001']['authorityFingerprint'],
                'scope' => CashierV3ResourceScope::of('tenant', '0'),
                'data_scope' => $dataScope,
            ],
            [
                'kind' => 'catalog_product',
                'id' => '501',
                'expected_version' => 9,
                'roles' => $resources['catalog_product:501']['roles'],
                'role' => 'checkout_navigation_product',
                'server_resource_access_mode' => $resources['catalog_product:501']['accessMode'],
                'resource_plan_access_mode' => 'mutate',
                'server_resource_provider_contract_version' => $resources['catalog_product:501']['providerContractVersion'],
                'server_resource_authority_fingerprint' => $resources['catalog_product:501']['authorityFingerprint'],
                'scope' => CashierV3ResourceScope::of('store', '7'),
                'data_scope' => $dataScope,
            ],
            [
                'kind' => 'service_order',
                'id' => 'SO-9001',
                'expected_version' => 6,
                'role' => 'checkout_source_service_order',
                'scope' => CashierV3ResourceScope::of('store', '7'),
                'data_scope' => $dataScope,
            ],
            [
                'kind' => 'checkout_request',
                'id' => $requestId,
                'expected_version' => $requestVersion,
                'roles' => $resources['checkout_request:' . $requestId]['roles'],
                'scope' => CashierV3ResourceScope::of('store', '7'),
                'data_scope' => $dataScope,
            ],
            [
                'kind' => 'cashier_workspace',
                'id' => $workspaceId,
                'expected_version' => 8,
                'roles' => $resources['cashier_workspace:' . $workspaceId]['roles'],
                'scope' => CashierV3ResourceScope::of('store', '7'),
                'data_scope' => $dataScope,
            ],
        ];
        $locked = [];
        foreach ($contexts as $context) {
            $locked[$context['scope']->signature() . ':' . $context['kind'] . ':' . $context['id']]
                = $context['expected_version'];
        }
        $projection = \app\services\cashier\v3\settlement\CashierV3CheckoutProjectionServices::projectPersistedAggregate([
            'request' => $aggregate['request'],
            'lines' => $aggregate['lines'],
            'payments' => $aggregate['payments'],
            'sources' => $aggregate['sources'],
            'workspaceCurrentVersion' => 8,
        ]);
        return [
            'action' => 'prepare-checkout-submission',
            'payload' => [
                'checkoutRequestId' => $requestId,
                'checkoutRequestVersion' => $requestVersion,
                'preparationRequestId' => (string)$projection['preparationRequestId'],
                'preparationToken' => (string)$projection['preparationToken'],
            ],
            'contexts' => $contexts,
            'locked_versions' => $locked,
            'idempotency_key' => 'CHECKOUT_PREPARE-00000000-0000-4000-8000-000000000099',
            'operator_scope' => $operatorScope,
            'data_scope' => $dataScope,
            'state_context_id' => 'submission-state',
            'server_resource_discovery' => $discovery,
        ];
    }

    function submissionPreparationHarness(array $aggregate, array $scope = null, string $secret = ''): array
    {
        $trace = new SubmissionPreparationTrace();
        $requests = new SubmissionPreparationRequestRepository($aggregate, $trace);
        $plans = new SubmissionPreparationPlanRepository($trace);
        $services = new CashierV3CheckoutSubmissionPreparationServices(
            $requests,
            null,
            $plans,
            $secret
        );
        return [
            'services' => $services,
            'scope' => $scope ?: submissionPreparationScope($aggregate),
            'requests' => $requests,
            'plans' => $plans,
            'trace' => $trace,
        ];
    }

    function submissionPreparationFailure(callable $operation): array
    {
        try {
            $operation();
        } catch (CashierV3CommandException $exception) {
            return [
                'code' => $exception->getResultCode(),
                'reason' => (string)($exception->getDetail()['reason'] ?? ''),
            ];
        }
        return ['code' => '', 'reason' => ''];
    }

    $aggregate = submissionPreparationAggregate();
    $harness = submissionPreparationHarness(
        $aggregate,
        null,
        str_repeat('submission-preparation-secret-', 2)
    );
    $prepared = $harness['services']->prepareInTx($harness['scope']);
    submissionPreparationAssert(
        'sale-only request advances exactly N to N+1 ready_for_submit',
        $prepared['checkoutRequestVersion'] === 2
            && $prepared['requestStatus'] === 'ready_for_submit'
            && $prepared['composition'] === 'sale_only'
            && $prepared['eventless'] === true
            && empty($prepared['businessEffects']['checkoutSucceeded'])
    );
    submissionPreparationAssert(
        'request CAS is persisted before the same-version resource plan',
        $harness['trace']->events === ['request:2', 'plan:2']
            && $harness['requests']->persistCalls === 1
            && $harness['plans']->persistCalls === 1
    );
    submissionPreparationAssert(
        'resource plan combines hidden resources with locked navigation sources',
        array_column($harness['plans']->lastPlan->resources(), 'kind') === [
            'member',
            'catalog_product',
            'service_order',
        ]
            && $prepared['resourceCount'] === 3
            && $harness['plans']->lastPlan->boundRequestVersion() === 2
    );
    submissionPreparationAssert(
        'scope and lock order are derived from the server catalog',
        array_map(static function (array $row): array {
            return [$row['scopeType'], $row['scopeId'], $row['lockOrder']];
        }, $harness['plans']->lastPlan->resources()) === [
            ['tenant', '0', 10],
            ['store', '7', 42],
            ['store', '7', 70],
        ]
    );
    $discoveredResource = $harness['plans']->lastPlan->resources()[1];
    submissionPreparationAssert(
        'same physical discovery and context merge roles with explicit mutate winning',
        $discoveredResource['roles'] === [
            'checkout_navigation_product',
            'sale_catalog_product',
        ]
            && $discoveredResource['accessMode'] === 'mutate'
            && $discoveredResource['providerContractVersion'] === 'sale-catalog-v1'
    );
    $navigationResource = $harness['plans']->lastPlan->resources()[2];
    submissionPreparationAssert(
        'ordinary Gateway context uses a fixed provider contract and canonical fingerprint',
        $navigationResource['providerContractVersion'] === 'gateway-locked-context-v1'
            && $navigationResource['accessMode'] === 'read'
            && $navigationResource['roles'] === ['checkout_source_service_order']
            && $navigationResource['authorityFingerprint']
                === \app\services\cashier\v3\settlement\CashierV3CheckoutSettlementCanonicalizer::fingerprint([
                    'contractVersion' => 'gateway-locked-context-v1',
                    'kind' => 'service_order',
                    'id' => 'SO-9001',
                    'expectedVersion' => 6,
                    'scopeType' => 'store',
                    'scopeId' => '7',
                ])
    );
    submissionPreparationAssert(
        'kernel retains the dedicated preparation idempotency key',
        $harness['requests']->lastKernel['persistencePlan']['request']['lastIdempotencyKey']
            === 'CHECKOUT_PREPARE-00000000-0000-4000-8000-000000000099'
            && !array_key_exists('touched', $prepared)
    );

    $missingSecret = submissionPreparationHarness($aggregate);
    $missingSecretFailure = submissionPreparationFailure(static function () use ($missingSecret): void {
        $missingSecret['services']->prepareInTx($missingSecret['scope']);
    });
    submissionPreparationAssert(
        'namespace secret fails closed before aggregate mutation',
        $missingSecretFailure['reason'] === 'checkout_namespace_secret_missing'
            && $missingSecret['requests']->lockCalls === 0
            && $missingSecret['requests']->persistCalls === 0
    );

    $invalidKeyScope = submissionPreparationScope($aggregate);
    $invalidKeyScope['idempotency_key'] = 'CHECKOUT-00000000-0000-4000-8000-000000000099';
    $invalidKey = submissionPreparationHarness(
        $aggregate,
        $invalidKeyScope,
        str_repeat('submission-preparation-secret-', 2)
    );
    submissionPreparationAssert(
        'submission preparation accepts only its registered idempotency namespace',
        submissionPreparationFailure(static function () use ($invalidKey): void {
            $invalidKey['services']->prepareInTx($invalidKey['scope']);
        })['reason'] === 'checkout_submission_idempotency_key_invalid'
            && $invalidKey['requests']->persistCalls === 0
    );

    $missingLockScope = submissionPreparationScope($aggregate);
    unset($missingLockScope['locked_versions']['tenant/0:member:1001']);
    $missingLock = submissionPreparationHarness(
        $aggregate,
        $missingLockScope,
        str_repeat('submission-preparation-secret-', 2)
    );
    submissionPreparationAssert(
        'hidden resources require Gateway lock evidence',
        submissionPreparationFailure(static function () use ($missingLock): void {
            $missingLock['services']->prepareInTx($missingLock['scope']);
        })['reason'] === 'checkout_submission_lock_evidence_missing'
            && $missingLock['requests']->persistCalls === 0
    );

    $driftScope = submissionPreparationScope($aggregate);
    $driftScope['server_resource_discovery']['fingerprint'] = str_repeat('f', 64);
    $drift = submissionPreparationHarness(
        $aggregate,
        $driftScope,
        str_repeat('submission-preparation-secret-', 2)
    );
    submissionPreparationAssert(
        'tampered discovery fingerprint is rejected before request CAS',
        submissionPreparationFailure(static function () use ($drift): void {
            $drift['services']->prepareInTx($drift['scope']);
        })['reason'] === 'checkout_submission_discovery_fingerprint_mismatch'
            && $drift['requests']->persistCalls === 0
    );

    $wrongTenantScope = submissionPreparationScope($aggregate);
    $wrongTenantScope['operator_scope'] = new CashierV3OperatorScope(7, 21, '3', 'other-tenant');
    $wrongTenant = submissionPreparationHarness(
        $aggregate,
        $wrongTenantScope,
        str_repeat('submission-preparation-secret-', 2)
    );
    submissionPreparationAssert(
        'operator and DataScope tenant mismatch fails closed',
        submissionPreparationFailure(static function () use ($wrongTenant): void {
            $wrongTenant['services']->prepareInTx($wrongTenant['scope']);
        })['reason'] === 'checkout_submission_data_scope_denied'
            && $wrongTenant['requests']->lockCalls === 0
    );

    $staleVersionScope = submissionPreparationScope($aggregate);
    $requestId = (string)$aggregate['request']['request_id'];
    foreach ($staleVersionScope['contexts'] as &$context) {
        if ($context['kind'] === 'checkout_request') {
            $context['expected_version'] = 2;
        }
    }
    unset($context);
    $staleVersionScope['locked_versions']['store/7:checkout_request:' . $requestId] = 2;
    $staleVersion = submissionPreparationHarness(
        $aggregate,
        $staleVersionScope,
        str_repeat('submission-preparation-secret-', 2)
    );
    submissionPreparationAssert(
        'payload request version must equal the locked checkout context version',
        submissionPreparationFailure(static function () use ($staleVersion): void {
            $staleVersion['services']->prepareInTx($staleVersion['scope']);
        })['reason'] === 'checkout_submission_request_context_version_mismatch'
            && $staleVersion['requests']->lockCalls === 0
    );

    $balanceAggregate = submissionPreparationAggregate(2000);
    $balance = submissionPreparationHarness(
        $balanceAggregate,
        null,
        str_repeat('submission-preparation-secret-', 2)
    );
    submissionPreparationAssert(
        'balance settlement remains fail-closed in the first sale-only slice',
        submissionPreparationFailure(static function () use ($balance): void {
            $balance['services']->prepareInTx($balance['scope']);
        })['reason'] === 'checkout_submission_sale_only_required'
            && $balance['requests']->persistCalls === 0
            && $balance['plans']->persistCalls === 0
    );

    echo "CHECKOUT_SUBMISSION_PREPARATION_CONTRACT passed={$passed} failed={$failed}\n";
    exit($failed === 0 ? 0 : 1);
}
