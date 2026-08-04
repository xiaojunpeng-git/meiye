<?php
declare(strict_types=1);

namespace app\services\cashier\v3 {
    // This suite is intentionally DB-free. The real repository independently
    // enforces the PDO transaction guard before taking FOR UPDATE locks.
    class CashierV3TransactionGuard
    {
        public static function assertInTransaction(string $operation): void
        {
        }
    }
}

namespace {
    $backendRoot = getenv('PAYMENT_DRAFT_BACKEND_ROOT')
        ?: dirname(__DIR__, 3) . '/后端代码';
    $backend = $backendRoot . '/app/services/cashier/v3';
    require $backend . '/CashierV3ResultCode.php';
    require $backend . '/CashierV3CommandException.php';
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
    require $backend . '/settlement/CashierV3CheckoutPaymentDraftServices.php';

    use app\services\cashier\v3\CashierV3CommandException;
    use app\services\cashier\v3\CashierV3DataScopeContext;
    use app\services\cashier\v3\CashierV3OperatorScope;
    use app\services\cashier\v3\settlement\CashierV3CheckoutDraftAuthorityRebuilder;
    use app\services\cashier\v3\settlement\CashierV3CheckoutPaymentDraftServices;
    use app\services\cashier\v3\settlement\CashierV3CheckoutProjectionServices;
    use app\services\cashier\v3\settlement\CashierV3CheckoutRequestRepository;
    use app\services\cashier\v3\settlement\CashierV3CheckoutSettlementKernel;
    use app\services\cashier\v3\settlement\CashierV3CheckoutVerifiedSourceSet;

    final class PaymentDraftFakeRepository implements CashierV3CheckoutRequestRepository
    {
        public $aggregate;
        public $lastKernel;
        public $persistCalls = 0;

        public function __construct(array $aggregate)
        {
            $this->aggregate = $aggregate;
        }

        public function lockAggregateForEditInTx(
            string $requestId,
            int $expectedVersion,
            string $workspaceId,
            string $stateContextId,
            CashierV3OperatorScope $operatorScope,
            CashierV3DataScopeContext $dataScope
        ): array {
            paymentDraftAssert('repository lock receives exact request',
                $requestId === $this->aggregate['request']['request_id']
                && $expectedVersion === (int)$this->aggregate['request']['request_version']);
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
            throw new \LogicException('not used by payment draft contract');
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
            throw new \LogicException('not used by payment draft contract');
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
            return ['replayed' => false];
        }
    }

    $passed = 0;
    $failed = 0;

    function paymentDraftAssert(string $name, bool $condition): void
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

    function paymentDraftSnapshot(array $payments = []): array
    {
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
            'workspaceId' => 'ws:7:21:payment-draft-state',
            'stateContextId' => 'payment-draft-state',
            'permissionSnapshotFingerprint' => 'roles:' . str_repeat('a', 32),
            'memberId' => 1001,
            'memberName' => '历史会员名称',
            'operatorId' => 21,
            'operatorName' => '历史收银员名称',
            'businessDate' => '2026-07-20',
            'businessTimezone' => 'Asia/Shanghai',
            'occurredAt' => 1785258000,
            'recordedAt' => 1785258000,
            'sourceDocument' => [
                'type' => 'cashier_workspace',
                'id' => 'ws:7:21:payment-draft-state',
                'no' => 'CHECKOUT-DRAFT-001',
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
                'serviceObject' => 'self',
                'isExperience' => 0,
            ]],
            'entitlementLines' => [],
            'paymentDetails' => $payments,
            'balanceDeduction' => [
                'authorityKey' => 'balance:member:1001',
                'accountId' => 'member-balance-1001',
                'accountVersion' => 5,
                'amountCents' => 2000,
            ],
            'debt' => [
                'authorityKey' => 'debt-policy:store:7',
                'policyVersion' => 3,
                'amountCents' => 1000,
            ],
        ];
        $snapshot['authoritySnapshotFingerprint'] =
            CashierV3CheckoutSettlementKernel::authorityFingerprint($snapshot);
        return $snapshot;
    }

    function paymentDraftAggregate(array $payments = []): array
    {
        $secret = str_repeat('payment-draft-test-secret-', 2);
        $kernel = CashierV3CheckoutSettlementKernel::saveDraft([
            'contractVersion' => CashierV3CheckoutSettlementKernel::CONTRACT_VERSION,
            'operation' => CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT,
            'idempotencyKey' => 'CHECKOUT_PREPARE-00000000-0000-4000-8000-000000000001',
            'workspaceId' => 'ws:7:21:payment-draft-state',
            'stateContextId' => 'payment-draft-state',
            'permissionSnapshotFingerprint' => 'roles:' . str_repeat('a', 32),
        ], paymentDraftSnapshot($payments), null, $secret);
        $plan = $kernel['persistencePlan'];
        $request = $plan['request'];
        $requestMap = [
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
        ];
        $requestRow = [];
        foreach ($requestMap as $source => $target) {
            $requestRow[$target] = $request[$source];
        }

        $lineRows = [];
        foreach ($plan['lineDrafts'] as $index => $line) {
            $lineRows[] = [
                'id' => $index + 1,
                'line_id' => $line['lineId'],
                'request_id' => $line['requestId'],
                'draft_version' => $line['draftVersion'],
                'draft_status' => $line['draftStatus'],
                'tenant_id' => $line['tenantId'],
                'store_id' => $line['storeId'],
                'member_id' => $line['memberId'],
                'line_role' => $line['lineRole'],
                'authority_key' => $line['authorityKey'],
                'source_kind' => $line['sourceKind'],
                'source_type' => $line['sourceType'],
                'source_id' => $line['sourceId'],
                'entitlement_source_detail_id' => $line['entitlementSourceDetailId'],
                'source_version' => $line['sourceVersion'],
                'project_id' => $line['projectId'],
                'project_version' => $line['projectVersion'],
                'quantity' => $line['quantity'],
                'original_amount_cents' => $line['originalAmountCents'],
                'discount_amount_cents' => $line['discountAmountCents'],
                'sale_amount_cents' => $line['saleAmountCents'],
                'entitlement_actual_amount_cents' => $line['entitlementActualAmountCents'],
                'source_name_snapshot' => $line['sourceNameSnapshot'],
                'source_code_snapshot' => $line['sourceCodeSnapshot'],
                'project_name_snapshot' => $line['projectNameSnapshot'],
                'category_id_snapshot' => $line['categoryIdSnapshot'],
                'category_name_snapshot' => $line['categoryNameSnapshot'],
                'service_object' => $line['serviceObject'],
                'is_experience' => $line['isExperience'],
                'line_fingerprint' => $line['lineFingerprint'],
                'sort_no' => $line['sortNo'],
            ];
        }
        $paymentRows = [];
        foreach ($plan['paymentDrafts'] as $index => $payment) {
            $paymentRows[] = [
                'id' => $index + 1,
                'payment_draft_id' => $payment['paymentDraftId'],
                'request_id' => $payment['requestId'],
                'draft_version' => $payment['draftVersion'],
                'draft_status' => $payment['draftStatus'],
                'tenant_id' => $payment['tenantId'],
                'store_id' => $payment['storeId'],
                'member_id' => $payment['memberId'],
                'operator_id' => $payment['operatorId'],
                'payment_authority_key' => $payment['paymentAuthorityKey'],
                'payment_method' => $payment['paymentMethod'],
                'amount_cents' => $payment['amountCents'],
                'external_transaction_no' => $payment['externalTransactionNo'],
                'remark' => $payment['remark'],
                'business_date' => $payment['businessDate'],
                'business_timezone' => $payment['businessTimezone'],
                'operation_occurred_at' => $payment['operationOccurredAt'],
                'recorded_at' => $payment['recordedAt'],
                'operator_name_snapshot' => $payment['operatorNameSnapshot'],
                'source_document_type' => $payment['sourceDocumentType'],
                'source_document_id' => $payment['sourceDocumentId'],
                'source_document_no' => $payment['sourceDocumentNo'],
                'payment_fingerprint' => $payment['paymentFingerprint'],
                'sort_no' => $payment['sortNo'],
            ];
        }
        return [
            'request' => $requestRow,
            'lines' => $lineRows,
            'payments' => $paymentRows,
            'sources' => [],
            'currentRequest' => [
                'requestId' => $kernel['requestId'],
                'tenantId' => $request['tenantId'],
                'workspaceId' => $request['workspaceId'],
                'version' => $kernel['requestVersion'],
                'status' => $kernel['requestStatus'],
                'creationIdempotencyKey' => $request['creationIdempotencyKey'],
                'lastIdempotencyKey' => $request['lastIdempotencyKey'],
                'lastOperationFingerprint' => $kernel['operationFingerprint'],
            ],
            'verifiedSources' => CashierV3CheckoutVerifiedSourceSet::fromServerVerifiedAuthorityRows(
                '0',
                7,
                []
            ),
        ];
    }

    function paymentDraftScope(array $aggregate, string $action, array $specific, int $number): array
    {
        $projection = CashierV3CheckoutProjectionServices::projectPersistedAggregate([
            'request' => $aggregate['request'],
            'lines' => $aggregate['lines'],
            'payments' => $aggregate['payments'],
            'sources' => $aggregate['sources'],
            'workspaceCurrentVersion' => 8,
        ]);
        return [
            'action' => $action,
            'payload' => array_merge([
                'checkoutRequestId' => $projection['checkoutRequestId'],
                'checkoutRequestVersion' => $projection['checkoutRequestVersion'],
                'preparationRequestId' => $projection['preparationRequestId'],
                'preparationToken' => $projection['preparationToken'],
            ], $specific),
            'contexts' => [
                [
                    'kind' => 'cashier_workspace',
                    'id' => 'ws:7:21:payment-draft-state',
                    'expected_version' => 8,
                ],
                [
                    'kind' => 'checkout_request',
                    'id' => $projection['checkoutRequestId'],
                    'expected_version' => $projection['checkoutRequestVersion'],
                ],
            ],
            'idempotency_key' => sprintf(
                'CMD-00000000-0000-4000-8000-%012d',
                $number
            ),
            'operator_scope' => new CashierV3OperatorScope(7, 21, '3', '0'),
            'data_scope' => new CashierV3DataScopeContext(
                21,
                121,
                7,
                '0',
                '3',
                [7],
                CashierV3DataScopeContext::MODE_STORES,
                [],
                false,
                '',
                'roles:' . str_repeat('b', 32),
                ['cashier.v3.cashier'],
                ['id' => 21, 'employee_id' => 121, 'staff_name' => '当前收银员']
            ),
            'state_context_id' => 'payment-draft-state',
        ];
    }

    function paymentDraftFailure(callable $operation): array
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

    $emptyAggregate = paymentDraftAggregate();
    $rebuiltAt = 1785348000;
    $rebuilt = (new CashierV3CheckoutDraftAuthorityRebuilder())->rebuild(
        $emptyAggregate,
        8,
        'roles:' . str_repeat('b', 32),
        $rebuiltAt
    );
    paymentDraftAssert('rebuilder uses current workspace and permission authorities',
        $rebuilt['authoritySnapshotVersion'] === 8
        && $rebuilt['permissionSnapshotFingerprint'] === 'roles:' . str_repeat('b', 32));
    paymentDraftAssert('rebuilder uses server operation time',
        $rebuilt['occurredAt'] === $rebuiltAt && $rebuilt['recordedAt'] === $rebuiltAt);
    paymentDraftAssert('rebuilder preserves business date and historical names',
        $rebuilt['businessDate'] === '2026-07-20'
        && $rebuilt['memberName'] === '历史会员名称'
        && $rebuilt['saleLines'][0]['sourceNameSnapshot'] === '历史项目名称');

    $balanceProjection = CashierV3CheckoutProjectionServices::projectPersistedAggregate([
        'request' => $emptyAggregate['request'],
        'lines' => $emptyAggregate['lines'],
        'payments' => $emptyAggregate['payments'],
        'sources' => $emptyAggregate['sources'],
        'workspaceCurrentVersion' => 8,
    ]);
    paymentDraftAssert('projection renders balance deduction as a cancellable receipt line',
        count($balanceProjection['payment']['selectedLines']) === 1
        && $balanceProjection['payment']['selectedLines'][0]['kind'] === 'balance_deduction'
        && $balanceProjection['payment']['selectedLines'][0]['amount'] === '20.00'
        && $balanceProjection['payment']['selectedLines'][0]['canEdit'] === true
        && $balanceProjection['payment']['selectedLines'][0]['editAction'] === 'update-balance-payment'
        && $balanceProjection['payment']['selectedLines'][0]['removalAction'] === 'remove-balance-payment'
        // 欠款属于仍待收账款，不是已经选择的收款方式。右侧已选收款只
        // 汇总余额扣款和七类记账收款，剩余金额仍包含欠款的最终平衡约束。
        && $balanceProjection['payment']['summary']['selectedAmount'] === '20.00'
        && $balanceProjection['payment']['summary']['remainingAmount'] === '70.00');

    $secret = str_repeat('payment-draft-service-secret-', 2);
    $addRepo = new PaymentDraftFakeRepository($emptyAggregate);
    $addResult = (new CashierV3CheckoutPaymentDraftServices($addRepo, null, $secret))->mutateInTx(
        'add-payment-method',
        paymentDraftScope($emptyAggregate, 'add-payment-method', ['paymentMethodId' => 'wechat'], 11)
    );
    paymentDraftAssert('add defaults to the current remaining receivable',
        $addResult['version'] === 2
        && $addResult['checkoutRequestId'] === $addResult['requestId']
        && $addResult['checkoutRequestVersion'] === $addResult['version']
        && $addResult['totals']['selectedPaymentAmountCents'] === 7000
        && $addResult['totals']['settlementAmountCents'] === 10000);
    paymentDraftAssert('add remains eventless and writes only one draft CAS plan',
        $addRepo->persistCalls === 1
        && $addRepo->lastKernel['businessEffects'] === [
            'checkoutSucceeded' => false,
            'saleFacts' => 0,
            'paymentCollectedFacts' => 0,
            'balanceMutations' => 0,
            'debtMutations' => 0,
            'entitlementMutations' => 0,
            'performanceFacts' => 0,
            'businessEvents' => 0,
            'outboxRows' => 0,
        ]
        && $addRepo->lastKernel['persistencePlan']['mode'] === 'cas_replace_children');

    $existingPayment = [[
        'paymentAuthorityKey' => 'payment:unionpay',
        'method' => 'unionpay',
        'amountCents' => 3000,
        'businessTime' => 1785258000,
        'externalTransactionNo' => 'TRACE-OLD',
        'remark' => '原备注',
    ]];
    $updateAggregate = paymentDraftAggregate($existingPayment);
    $paymentLineId = $updateAggregate['payments'][0]['payment_draft_id'];
    $updateRepo = new PaymentDraftFakeRepository($updateAggregate);
    $updateResult = (new CashierV3CheckoutPaymentDraftServices($updateRepo, null, $secret))->mutateInTx(
        'update-payment-line',
        paymentDraftScope($updateAggregate, 'update-payment-line', [
            'paymentLineId' => $paymentLineId,
            'amount' => '50',
            'externalTransactionNo' => 'TRACE-NEW',
            'remark' => '新备注',
        ], 12)
    );
    paymentDraftAssert('update accepts an integer yuan amount as exact integer cents',
        $updateResult['totals']['selectedPaymentAmountCents'] === 5000
        && $updateRepo->lastKernel['paymentDrafts'][0]['amountCents'] === 5000
        && $updateRepo->lastKernel['paymentDrafts'][0]['externalTransactionNo'] === 'TRACE-NEW');

    $overRepo = new PaymentDraftFakeRepository($updateAggregate);
    $overFailure = paymentDraftFailure(static function () use (
        $overRepo,
        $updateAggregate,
        $paymentLineId,
        $secret
    ): void {
        (new CashierV3CheckoutPaymentDraftServices($overRepo, null, $secret))->mutateInTx(
            'update-payment-line',
            paymentDraftScope($updateAggregate, 'update-payment-line', [
                'paymentLineId' => $paymentLineId,
                'amount' => '81',
                'externalTransactionNo' => '',
                'remark' => '',
            ], 13)
        );
    });
    paymentDraftAssert('update blocks payment balance debt total above receivable',
        $overFailure['reason'] === 'checkout_payment_exceeds_receivable'
        && $overRepo->persistCalls === 0);

    $removeRepo = new PaymentDraftFakeRepository($updateAggregate);
    $removeResult = (new CashierV3CheckoutPaymentDraftServices($removeRepo, null, $secret))->mutateInTx(
        'remove-payment-line',
        paymentDraftScope($updateAggregate, 'remove-payment-line', [
            'paymentLineId' => $paymentLineId,
        ], 14)
    );
    paymentDraftAssert('remove allows an underpaid editable draft',
        $removeResult['totals']['selectedPaymentAmountCents'] === 0
        && $removeResult['totals']['settlementAmountCents'] === 3000
        && $removeResult['totals']['balanced'] === false);

    $duplicateRepo = new PaymentDraftFakeRepository($updateAggregate);
    $duplicateResult = (new CashierV3CheckoutPaymentDraftServices($duplicateRepo, null, $secret))->mutateInTx(
        'add-payment-method',
        paymentDraftScope($updateAggregate, 'add-payment-method', [
            'paymentMethodId' => 'unionpay',
        ], 15)
    );
    paymentDraftAssert('one bookkeeping method can have multiple independent draft rows',
        $duplicateResult['totals']['selectedPaymentAmountCents'] === 7000
        && count($duplicateRepo->lastKernel['paymentDrafts']) === 2
        && $duplicateRepo->lastKernel['paymentDrafts'][0]['paymentMethod'] === 'unionpay'
        && $duplicateRepo->lastKernel['paymentDrafts'][1]['paymentMethod'] === 'unionpay'
        // Existing payment, balance and debt have already covered 6,000 cents,
        // so the added payment must receive only the remaining 4,000 cents.
        && $duplicateRepo->lastKernel['paymentDrafts'][1]['amountCents'] === 4000
        && $duplicateRepo->lastKernel['paymentDrafts'][0]['paymentAuthorityKey']
            !== $duplicateRepo->lastKernel['paymentDrafts'][1]['paymentAuthorityKey']);

    $oldCardRepo = new PaymentDraftFakeRepository($emptyAggregate);
    $oldCardFailure = paymentDraftFailure(static function () use (
        $oldCardRepo,
        $emptyAggregate,
        $secret
    ): void {
        (new CashierV3CheckoutPaymentDraftServices($oldCardRepo, null, $secret))->mutateInTx(
            'add-payment-method',
            paymentDraftScope($emptyAggregate, 'add-payment-method', [
                'paymentMethodId' => 'old_card_entry',
            ], 16)
        );
    });
    paymentDraftAssert('old card entry is rejected as a separate zero-cash flow',
        $oldCardFailure['reason'] === 'old_card_entry_separate_flow_required'
        && $oldCardRepo->persistCalls === 0);

    echo "RESULT: {$passed} passed, {$failed} failed\n";
    exit($failed === 0 ? 0 : 1);
}
