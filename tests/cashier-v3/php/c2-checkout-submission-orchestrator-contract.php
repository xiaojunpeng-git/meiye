<?php

namespace app\services\cashier\v3 {
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

    final class CashierV3OperatorScope
    {
        private $storeId;
        private $operatorId;
        private $organizationId;
        private $tenantId;

        public function __construct(int $storeId, int $operatorId, string $organizationId, string $tenantId)
        {
            $this->storeId = $storeId;
            $this->operatorId = $operatorId;
            $this->organizationId = $organizationId;
            $this->tenantId = $tenantId;
        }

        public function storeId(): int { return $this->storeId; }
        public function operatorId(): int { return $this->operatorId; }
        public function organizationId(): string { return $this->organizationId; }
        public function tenantId(): string { return $this->tenantId; }
    }

    final class CashierV3DataScopeContext
    {
        private $operatorId;
        private $storeId;
        private $organizationId;
        private $tenantId;

        public function __construct(int $operatorId, int $storeId, string $organizationId, string $tenantId)
        {
            $this->operatorId = $operatorId;
            $this->storeId = $storeId;
            $this->organizationId = $organizationId;
            $this->tenantId = $tenantId;
        }

        public function operatorId(): int { return $this->operatorId; }
        public function forcedStoreId(): int { return $this->storeId; }
        public function organizationId(): string { return $this->organizationId; }
        public function tenantId(): string { return $this->tenantId; }
    }
}

namespace app\services\cashier\v3\event {
    final class CashierV3BusinessEventRecorder
    {
    }

    final class CashierV3BusinessEventExecution
    {
        private $action;
        private $idempotencyKey;
        private $operatorScope;
        private $dataScope;
        private $stateContextId;

        public function __construct(
            string $action,
            string $idempotencyKey,
            \app\services\cashier\v3\CashierV3OperatorScope $operatorScope,
            \app\services\cashier\v3\CashierV3DataScopeContext $dataScope,
            string $stateContextId
        ) {
            $this->action = $action;
            $this->idempotencyKey = $idempotencyKey;
            $this->operatorScope = $operatorScope;
            $this->dataScope = $dataScope;
            $this->stateContextId = $stateContextId;
        }

        public function action(): string { return $this->action; }
        public function idempotencyKey(): string { return $this->idempotencyKey; }
        public function operatorScope(): \app\services\cashier\v3\CashierV3OperatorScope { return $this->operatorScope; }
        public function dataScope(): \app\services\cashier\v3\CashierV3DataScopeContext { return $this->dataScope; }
        public function stateContextId(): string { return $this->stateContextId; }
    }
}

namespace {
    $backendRoot = getenv('C2_SUBMISSION_BACKEND_ROOT');
    $backendRoot = is_string($backendRoot) && $backendRoot !== ''
        ? rtrim($backendRoot, '/')
        : __DIR__ . '/../../../后端代码';

    require_once $backendRoot . '/app/services/cashier/v3/settlement/CashierV3CheckoutSubmissionOrchestrationException.php';
    require_once $backendRoot . '/app/services/cashier/v3/settlement/CashierV3CheckoutSubmissionExecutionPort.php';
    require_once $backendRoot . '/app/services/cashier/v3/settlement/CashierV3CheckoutSubmissionExecutionContext.php';
    require_once $backendRoot . '/app/services/cashier/v3/settlement/CashierV3CheckoutSubmissionOrchestrator.php';

    use app\services\cashier\v3\CashierV3TransactionGuard;
    use app\services\cashier\v3\CashierV3DataScopeContext;
    use app\services\cashier\v3\CashierV3OperatorScope;
    use app\services\cashier\v3\event\CashierV3BusinessEventExecution;
    use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
    use app\services\cashier\v3\settlement\CashierV3CheckoutSubmissionExecutionPort;
    use app\services\cashier\v3\settlement\CashierV3CheckoutSubmissionExecutionContext;
    use app\services\cashier\v3\settlement\CashierV3CheckoutSubmissionOrchestrationException;
    use app\services\cashier\v3\settlement\CashierV3CheckoutSubmissionOrchestrator;

    final class C2SubmissionTestPort implements CashierV3CheckoutSubmissionExecutionPort
    {
        /** @var array */
        public $authority;

        /** @var array<int,string> */
        public $calls = [];

        /** @var string */
        public $failAt = '';

        /** @var bool */
        public $invalidEntitlementReceipt = false;

        public function __construct(array $authority)
        {
            $this->authority = $authority;
        }

        public function lockSubmissionAuthorityInTx(array $scope): array
        {
            $this->step('lock');
            return $this->authority;
        }

        public function submitSaleOnlyInTx(array $scope, array $authority): array
        {
            $this->step('sale-only');
            return [
                'checkoutRequestId' => $authority['checkoutRequestId'],
                'checkoutRequestVersion' => $authority['checkoutRequestVersion'] + 1,
                'requestStatus' => 'succeeded',
                'salesOrder' => [
                    'orderId' => c2SubmissionId('CSO', 'b'),
                    'orderNo' => 'SO-20260729-ABCDEF01234567890123',
                    'replayed' => false,
                ],
                'paymentCollection' => [
                    'batchId' => c2SubmissionId('CPB', 'c'),
                    'collectionCount' => 1,
                    'replayed' => false,
                ],
                'businessEventNo' => 'EV-ABCDEF0123456789',
                'factFingerprint' => str_repeat('d', 64),
                'settledAt' => $authority['settledAt'],
                'cashierDraft' => ['workspaceId' => $authority['workspaceId'], 'lines' => []],
                'replayed' => false,
            ];
        }

        public function persistSalesOrderInTx(array $authority): array
        {
            $this->step('sales');
            return [
                'orderId' => c2SubmissionId('CSO', 'b'),
                'orderNo' => 'SO-20260729-ABCDEF01234567890123',
                'checkoutRequestId' => $authority['checkoutRequestId'],
                'replayed' => false,
            ];
        }

        public function persistPaymentCollectionInTx(
            array $authority,
            array $salesOrder
        ): array {
            $this->step('payments');
            return [
                'batchId' => c2SubmissionId('CPB', 'c'),
                'salesOrderId' => $salesOrder['orderId'],
                'checkoutRequestId' => $authority['checkoutRequestId'],
                'collectionCount' => 1,
                'replayed' => false,
            ];
        }

        public function persistDebtInTx(array $authority, array $salesOrder): array
        {
            $this->step('debt');
            return [
                'debtId' => 31,
                'debtNo' => 'D3ABCDEF0123456789ABCDEF01234567',
                'amountCents' => 49600,
                'policyVersion' => 1,
                'orderLineAllocations' => ['sale-line-001' => 49600],
                'checkoutRequestId' => $authority['checkoutRequestId'],
                'salesOrderId' => $salesOrder['orderId'],
                'replayed' => false,
            ];
        }

        public function persistBalancePaymentInTx(
            array $authority,
            array $salesOrder,
            array $paymentCollection
        ): ?array {
            $this->step('balance');
            return [
                'memberId' => $authority['memberId'],
                'deductedTotalCents' => 60000,
                'ledgerId' => 91,
            ];
        }

        public function planEntitlementCompletionInTx(array $authority): array
        {
            $this->step('entitlement-plan');
            return [
                'contractVersion' => 'c2-entitlement-completion-v3',
                'checkoutRequestId' => $authority['checkoutRequestId'],
                'receiptId' => c2SubmissionId('ECR', 'e'),
                'kernelPlanFingerprint' => str_repeat('f', 64),
                'persistenceStatus' => 'not_persisted',
            ];
        }

        public function persistInventoryCompletionInTx(
            array $authority,
            array $entitlementPlan
        ): array {
            $this->step('inventory');
            return [
                'contractVersion' => 'inventory-entitlement-completion-provider-v1',
                'checkoutRequestId' => $authority['checkoutRequestId'],
                'receiptKey' => 'inventory-receipt-001',
                'replayed' => false,
            ];
        }

        public function recordCompletionEventsInTx(
            array $authority,
            array $salesOrder,
            array $paymentCollection,
            array $debt,
            array $entitlementPlan,
            array $inventoryCompletion
        ): array {
            $this->step('events');
            return [
                'businessEventNos' => [
                    'EV-ENTITLEMENT-WRITEOFF-001',
                    'EV-SERVICE-COMPLETED-001',
                ],
                'eventSetFingerprint' => str_repeat('1', 64),
            ];
        }

        public function persistEntitlementCompletionInTx(
            array $authority,
            array $entitlementPlan,
            array $inventoryCompletion,
            array $businessEvents
        ): array {
            $this->step('entitlement-persist');
            return [
                'contractVersion' => 'cashier-v3-entitlement-completion-persistence-v1',
                'checkoutRequestId' => $authority['checkoutRequestId'],
                'receiptId' => $this->invalidEntitlementReceipt
                    ? c2SubmissionId('ECR', '9')
                    : $entitlementPlan['receiptId'],
                'planFingerprint' => str_repeat('8', 64),
                'persistenceStatus' => 'persisted',
                'replayed' => false,
            ];
        }

        public function persistSaleFactsInTx(
            array $authority,
            array $salesOrder,
            array $paymentCollection,
            array $businessEvents,
            ?array $balancePayment
        ): array {
            $this->step('sale-facts');
            return ['planFingerprint' => str_repeat('2', 64), 'replayed' => false];
        }

        public function markSucceededInTx(
            array $authority,
            string $completionReferenceId,
            array $resultSlices
        ): array {
            $this->step('mark-succeeded');
            return [
                'checkoutRequestId' => $authority['checkoutRequestId'],
                'checkoutRequestVersion' => $authority['checkoutRequestVersion'] + 1,
                'requestStatus' => 'succeeded',
                'completionReferenceId' => $completionReferenceId,
            ];
        }

        public function completeWorkspaceInTx(array $authority): array
        {
            $this->step('clear-workspace');
            return [
                'workspaceId' => $authority['workspaceId'],
                'cleared' => true,
                'cashierDraft' => ['workspaceId' => $authority['workspaceId'], 'lines' => []],
            ];
        }

        private function step(string $name): void
        {
            $this->calls[] = $name;
            if ($this->failAt === $name) {
                throw new \RuntimeException('forced_failure:' . $name);
            }
        }
    }

    $passed = 0;
    $failed = 0;

    function c2SubmissionAssert(string $name, bool $condition, string $detail = ''): void
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

    function c2SubmissionId(string $prefix, string $character): string
    {
        return $prefix . '-' . str_repeat($character, 40);
    }

    function c2SubmissionEntitlementAuthority(array $authority): array
    {
        $lines = [];
        foreach ($authority['entitlementLineIds'] as $lineId) {
            $lines[] = ['lineId' => $lineId];
        }
        $snapshotLines = [];
        foreach ($authority['entitlementLineIds'] as $lineId) {
            $snapshotLines[] = ['lineId' => $lineId, 'lineRole' => 'entitlement_service'];
        }
        return [
            'command' => [
                'workspaceId' => $authority['workspaceId'],
                'stateContextId' => $authority['stateContextId'],
                'memberId' => $authority['memberId'],
                'lines' => $lines,
            ],
            'lockedSnapshot' => [
                'workspaceId' => $authority['workspaceId'],
                'stateContextId' => $authority['stateContextId'],
                'tenantId' => $authority['tenantId'],
                'storeId' => $authority['storeId'],
                'memberId' => $authority['memberId'],
                'lines' => $snapshotLines,
            ],
            'lockedResourcePlan' => [['kind' => 'member', 'id' => '1001']],
            'staffSnapshots' => [['staffId' => 11, 'staffVersion' => 2]],
            'inventoryProviderSnapshot' => [
                'contractVersion' => 'inventory-entitlement-completion-provider-v1',
                'tenantId' => $authority['tenantId'],
                'storeId' => $authority['storeId'],
            ],
            'inventoryDataScope' => (object)['trusted' => true],
        ];
    }

    function c2SubmissionAuthority(string $composition): array
    {
        $saleLineIds = $composition === 'entitlement_only' ? [] : ['sale-line-001'];
        $entitlementLineIds = $composition === 'sale_only'
            ? []
            : ['entitlement-line-001'];
        $authority = [
            'contractVersion' => CashierV3CheckoutSubmissionOrchestrator::AUTHORITY_CONTRACT_VERSION,
            'checkoutRequestId' => c2SubmissionId('CKR', 'a'),
            'checkoutRequestVersion' => 7,
            'commandIdempotencyKey' => 'CHECKOUT-00000000-0000-4000-8000-000000000001',
            'requestStatus' => 'ready_for_submit',
            'composition' => $composition,
            'workspaceId' => 'ws:7:21:state-context-001',
            'stateContextId' => 'state-context-001',
            'tenantId' => 'tenant-1',
            'storeId' => 7,
            'memberId' => $composition === 'sale_only' ? 0 : 1001,
            'settledAt' => 1785283260,
            'lockedAggregateFingerprint' => str_repeat('3', 64),
            'lockedResourcePlanFingerprint' => str_repeat('4', 64),
            'saleLineIds' => $saleLineIds,
            'entitlementLineIds' => $entitlementLineIds,
            'entitlementAuthority' => null,
            'replayResult' => null,
            'executionContext' => null,
        ];
        if ($composition !== 'sale_only') {
            $authority['entitlementAuthority'] = c2SubmissionEntitlementAuthority($authority);
        }
        $operator = new CashierV3OperatorScope(7, 21, 'org-1', 'tenant-1');
        $dataScope = new CashierV3DataScopeContext(21, 7, 'org-1', 'tenant-1');
        $recorder = new CashierV3BusinessEventRecorder();
        $execution = new CashierV3BusinessEventExecution(
            'submit-checkout',
            $authority['commandIdempotencyKey'],
            $operator,
            $dataScope,
            $authority['stateContextId']
        );
        $authority['executionContext'] = new CashierV3CheckoutSubmissionExecutionContext(
            [
                'request' => [
                    'request_id' => $authority['checkoutRequestId'],
                    'request_version' => $authority['checkoutRequestVersion'],
                    'workspace_id' => $authority['workspaceId'],
                    'state_context_id' => $authority['stateContextId'],
                    'tenant_id' => $authority['tenantId'],
                    'store_id' => $authority['storeId'],
                    'member_id' => $authority['memberId'],
                    'composition' => $authority['composition'],
                ],
                'lines' => [],
                'payments' => [],
                'sources' => [],
                'currentRequest' => [],
                'verifiedSources' => (object)[],
            ],
            $operator,
            $dataScope,
            $recorder,
            $execution,
            ['required_event_types' => ['checkout.completed']],
            $composition === 'sale_only' ? null : ['kernelPlan' => []]
        );
        return $authority;
    }

    function c2SubmissionReason(callable $callback): string
    {
        try {
            $callback();
        } catch (CashierV3CheckoutSubmissionOrchestrationException $exception) {
            return $exception->reason();
        } catch (\Throwable $exception) {
            return 'UNEXPECTED:' . get_class($exception) . ':' . $exception->getMessage();
        }
        return '';
    }

    function c2SubmissionRuntimeMessage(callable $callback): string
    {
        try {
            $callback();
        } catch (\RuntimeException $exception) {
            return $exception->getMessage();
        }
        return '';
    }

    $scope = ['serverLocked' => true];

    $port = new C2SubmissionTestPort(c2SubmissionAuthority('sale_only'));
    $orchestrator = new CashierV3CheckoutSubmissionOrchestrator($port);
    CashierV3TransactionGuard::$inTransaction = false;
    c2SubmissionAssert(
        'C2-SUBMIT-01 transaction gate rejects execution before authority locking',
        c2SubmissionRuntimeMessage(static function () use ($orchestrator, $scope): void {
            $orchestrator->submitInTx($scope);
        }) === 'test_transaction_required:checkoutSubmissionOrchestrator'
            && $port->calls === []
    );

    CashierV3TransactionGuard::$inTransaction = true;
    $port = new C2SubmissionTestPort(c2SubmissionAuthority('sale_only'));
    $result = (new CashierV3CheckoutSubmissionOrchestrator($port))->submitInTx($scope);
    c2SubmissionAssert(
        'C2-SUBMIT-02 sale-only delegates proven slice and never enters entitlement steps',
        $port->calls === ['lock', 'sale-only']
            && $result['composition'] === 'sale_only'
            && $result['completionReferenceId'] === c2SubmissionId('CSO', 'b')
            && $result['entitlementCompletion'] === null
    );

    $port = new C2SubmissionTestPort(c2SubmissionAuthority('entitlement_only'));
    $result = (new CashierV3CheckoutSubmissionOrchestrator($port))->submitInTx($scope);
    c2SubmissionAssert(
        'C2-SUBMIT-03 entitlement-only creates no zero-value sales or collection record',
        $port->calls === [
            'lock', 'entitlement-plan', 'inventory', 'events',
            'entitlement-persist', 'mark-succeeded', 'clear-workspace',
        ]
            && $result['salesOrder'] === null
            && $result['paymentCollection'] === null
            && $result['completionReferenceId'] === c2SubmissionId('ECR', 'e')
    );

    $port = new C2SubmissionTestPort(c2SubmissionAuthority('mixed'));
    $result = (new CashierV3CheckoutSubmissionOrchestrator($port))->submitInTx($scope);
    c2SubmissionAssert(
        'C2-SUBMIT-04 mixed writes every slice before request success and cart cleanup',
        $port->calls === [
            'lock', 'entitlement-plan', 'sales', 'payments', 'debt', 'balance', 'inventory', 'events',
            'entitlement-persist', 'sale-facts', 'mark-succeeded', 'clear-workspace',
        ]
            && $result['salesOrder']['orderId'] === c2SubmissionId('CSO', 'b')
            && $result['debt']['amountCents'] === 49600
            && $result['entitlementCompletion']['receiptId'] === c2SubmissionId('ECR', 'e')
            && $result['completionReferenceId'] === c2SubmissionId('CSO', 'b')
    );

    $authority = c2SubmissionAuthority('entitlement_only');
    $authority['entitlementAuthority']['lockedSnapshot'] = [];
    $port = new C2SubmissionTestPort($authority);
    $reason = c2SubmissionReason(static function () use ($port, $scope): void {
        (new CashierV3CheckoutSubmissionOrchestrator($port))->submitInTx($scope);
    });
    c2SubmissionAssert(
        'C2-SUBMIT-05 missing locked entitlement snapshot fails closed before planning',
        $reason === 'entitlement_locked_input_missing' && $port->calls === ['lock'],
        $reason
    );

    $port = new C2SubmissionTestPort(c2SubmissionAuthority('mixed'));
    $port->failAt = 'entitlement-persist';
    $message = c2SubmissionRuntimeMessage(static function () use ($port, $scope): void {
        (new CashierV3CheckoutSubmissionOrchestrator($port))->submitInTx($scope);
    });
    c2SubmissionAssert(
        'C2-SUBMIT-06 a mixed slice failure escapes to the outer transaction without finalizing',
        $message === 'forced_failure:entitlement-persist'
            && !in_array('sale-facts', $port->calls, true)
            && !in_array('mark-succeeded', $port->calls, true)
            && !in_array('clear-workspace', $port->calls, true)
    );

    $authority = c2SubmissionAuthority('entitlement_only');
    $authority['requestStatus'] = 'succeeded';
    $authority['entitlementAuthority'] = null;
    $authority['replayResult'] = [
        'contractVersion' => CashierV3CheckoutSubmissionOrchestrator::CONTRACT_VERSION,
        'checkoutRequestId' => $authority['checkoutRequestId'],
        'checkoutRequestVersion' => 8,
        'requestStatus' => 'succeeded',
        'composition' => 'entitlement_only',
        'completionReferenceId' => c2SubmissionId('ECR', 'e'),
        'salesOrder' => null,
        'paymentCollection' => null,
        'entitlementCompletion' => ['receiptId' => c2SubmissionId('ECR', 'e')],
        'inventoryCompletion' => ['receiptKey' => 'inventory-receipt-001'],
        'businessEventNos' => ['EV-SERVICE-COMPLETED-001'],
        'factFingerprints' => ['sale' => null, 'entitlement' => str_repeat('f', 64)],
        'settledAt' => $authority['settledAt'],
        'cashierDraft' => ['workspaceId' => $authority['workspaceId'], 'lines' => []],
        'replayed' => false,
    ];
    $port = new C2SubmissionTestPort($authority);
    $result = (new CashierV3CheckoutSubmissionOrchestrator($port))->submitInTx($scope);
    c2SubmissionAssert(
        'C2-SUBMIT-07 authoritative replay performs no duplicate deduction, fact or cleanup write',
        $port->calls === ['lock'] && $result['replayed'] === true
    );

    $port = new C2SubmissionTestPort(c2SubmissionAuthority('entitlement_only'));
    $port->invalidEntitlementReceipt = true;
    $reason = c2SubmissionReason(static function () use ($port, $scope): void {
        (new CashierV3CheckoutSubmissionOrchestrator($port))->submitInTx($scope);
    });
    c2SubmissionAssert(
        'C2-SUBMIT-08 mismatched entitlement receipt cannot advance the request or clear the cart',
        $reason === 'entitlement_completion_result_invalid'
            && !in_array('mark-succeeded', $port->calls, true)
            && !in_array('clear-workspace', $port->calls, true),
        $reason
    );

    $source = file_get_contents(
        $backendRoot . '/app/services/cashier/v3/settlement/CashierV3CheckoutSubmissionOrchestrator.php'
    );
    c2SubmissionAssert(
        'C2-SUBMIT-09 orchestrator never opens, commits or rolls back its own transaction',
        is_string($source)
            && strpos($source, 'startTrans') === false
            && strpos($source, 'Db::transaction') === false
            && strpos($source, '->commit') === false
            && strpos($source, '->rollback') === false
    );

    echo "C2 checkout submission orchestrator: {$passed} passed, {$failed} failed\n";
    exit($failed === 0 ? 0 : 1);
}
