<?php

error_reporting(E_ALL & ~E_DEPRECATED);

$root = dirname(__DIR__, 3);
require $root . '/后端代码/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\settlement\CashierV3CheckoutSubmissionExecutionContext;
use app\services\cashier\v3\settlement\ThinkPhpCashierV3CheckoutSubmissionExecutionPort;

$passed = 0;
$failed = 0;

function submissionExecutionAssert(string $name, bool $condition, string $detail = ''): void
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

function submissionExecutionInvoke($object, string $method, array $arguments)
{
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);
    return $reflection->invokeArgs($object, $arguments);
}

function submissionExecutionBlock(string $source, string $start, string $end): string
{
    $offset = strpos($source, $start);
    if ($offset === false) {
        return '';
    }
    $limit = strpos($source, $end, $offset + strlen($start));
    return $limit === false ? substr($source, $offset) : substr($source, $offset, $limit - $offset);
}

$authority = [
    'checkoutRequestId' => 'CKR-' . str_repeat('a', 40),
    'checkoutRequestVersion' => 7,
    'commandIdempotencyKey' => 'CHECKOUT-00000000-0000-4000-8000-000000000001',
    'composition' => 'entitlement_only',
    'workspaceId' => 'ws:7:21:state-1',
    'stateContextId' => 'state-1',
    'tenantId' => 'tenant-1',
    'storeId' => 7,
    'memberId' => 1001,
];
$kernelPlan = [
    'businessDate' => '2026-07-29',
    'occurredAt' => 1785283260,
    'settledAt' => 1785283260,
    'recordedAt' => 1785283260,
    'dimensionSnapshot' => [
        'organizationName' => '第一组织',
        'storeName' => '七号门店',
    ],
    'linePlans' => [[
        'lineId' => 'entitlement-line-001',
        'quantity' => 3,
        'actualEntitlementAmountCents' => 9000,
        'source' => [
            'entitlementInstanceType' => 'card_holder',
            'entitlementInstanceId' => 101,
            'sourceDetailId' => 201,
            'sourceVersion' => 4,
            'detailVersion' => 5,
            'sourceNameSnapshot' => '护理次卡',
            'projectId' => 301,
            'projectNameSnapshot' => '深层护理',
            'isGift' => true,
            'giftSourceType' => 'holder_backed',
            'giftId' => 0,
            'giftVersion' => 0,
        ],
        'serviceSnapshot' => [
            'serviceObject' => 'self',
            'isExperience' => true,
            'primaryCraftsmanId' => 11,
        ],
        'consumptionPerformance' => [
            'mode' => 'actual_entitlement_amount',
            'amountCents' => 9000,
        ],
        'laborPerformance' => [
            'allocations' => [[
                'staffId' => 11,
                'staffName' => '员工甲',
                'amountCents' => 5000,
            ], [
                'staffId' => 12,
                'staffName' => '员工乙',
                'amountCents' => 4000,
            ]],
        ],
        'inventory' => [
            'consumables' => [[
                'stockId' => '501',
                'batchAllocations' => [[
                    'batchId' => 801,
                    'quantityUnits' => 20,
                    'actualCostCents' => 300,
                ]],
                'shortageQuantityUnits' => 5,
                'estimatedShortageCostCents' => 80,
            ]],
        ],
    ]],
    'totals' => ['lineCount' => 1],
    'requiredEventContract' => [
        'entitlement.writeoff.completed' => ['minCount' => 1, 'maxCount' => 1],
        'service.completed' => ['minCount' => 1, 'maxCount' => 1],
        'performance.consumption.recorded' => ['minCount' => 1, 'maxCount' => 1],
        'performance.labor.allocated' => ['minCount' => 2, 'maxCount' => 2],
        'gift.consumed' => ['minCount' => 1, 'maxCount' => 1],
        'inventory.batch.consumed' => ['minCount' => 1, 'maxCount' => 1],
        'inventory.shortage.recorded' => ['minCount' => 1, 'maxCount' => 1],
        'inventory.service_consumption.resolved' => ['minCount' => 1, 'maxCount' => 1],
    ],
];
$inventory = [
    'receiptKey' => 'ICR-' . str_repeat('b', 40),
    'actualCostCents' => 300,
    'estimatedShortageCostCents' => 80,
    'costComplete' => false,
];

$reflection = new ReflectionClass(ThinkPhpCashierV3CheckoutSubmissionExecutionPort::class);
$port = $reflection->newInstanceWithoutConstructor();
$events = submissionExecutionInvoke($port, 'completionEventInputs', [
    $authority,
    $kernelPlan,
    [],
    [],
    [],
    $inventory,
]);
submissionExecutionInvoke($port, 'assertKernelEventCardinality', [$kernelPlan, $events]);
$eventTypes = array_column($events, 'event_type');

submissionExecutionAssert(
    'C2-EXEC-01 entitlement-only emits one checkout terminal event without sales authority',
    $events[0]['event_type'] === 'checkout.completed'
        && $events[0]['aggregate_type'] === 'entitlement_completion'
        && $events[0]['payload']['salesOrderId'] === null
        && $events[0]['payload']['paymentBatchId'] === null
);
submissionExecutionAssert(
    'C2-EXEC-02 every event is sourced from submit-checkout',
    count(array_filter($events, static function (array $event): bool {
        return ($event['source_type'] ?? '') === 'submit-checkout';
    })) === count($events)
);
submissionExecutionAssert(
    'C2-EXEC-03 quantity greater than one remains one service grain with authoritative quantity',
    count(array_filter($events, static function (array $event): bool {
        return $event['event_type'] === 'entitlement.writeoff.completed';
    })) === 1
        && $events[1]['payload']['quantity'] === 3
);
submissionExecutionAssert(
    'C2-EXEC-04 labor events retain one fact grain per craftsman',
    count(array_filter($eventTypes, static function (string $type): bool {
        return $type === 'performance.labor.allocated';
    })) === 2
);
submissionExecutionAssert(
    'C2-EXEC-05 gift, real batch and shortage event cardinalities come from the kernel plan',
    count(array_filter($eventTypes, static function (string $type): bool {
        return in_array($type, [
            'gift.consumed',
            'inventory.batch.consumed',
            'inventory.shortage.recorded',
        ], true);
    })) === 3
);

$mixedAuthority = array_merge($authority, ['composition' => 'mixed']);
$salesOrder = ['orderId' => 'CSO-' . str_repeat('c', 40), 'orderNo' => 'SO-20260801-MIXED-DEBT'];
$payment = ['batchId' => 'CPB-' . str_repeat('d', 40)];
$debt = [
    'debtNo' => 'D3ABCDEF0123456789ABCDEF01234567',
    'amountCents' => 49600,
    'policyVersion' => 1,
    'orderLineAllocations' => ['sale-line-001' => 49600],
];
$mixedEvents = submissionExecutionInvoke($port, 'completionEventInputs', [
    $mixedAuthority,
    $kernelPlan,
    $salesOrder,
    $payment,
    $debt,
    $inventory,
]);
$debtEvents = array_values(array_filter($mixedEvents, static function (array $event): bool {
    return ($event['event_type'] ?? '') === 'debt.recorded';
}));
submissionExecutionAssert(
    'C2-EXEC-06 mixed debt emits exactly one authoritative debt.recorded event',
    count($debtEvents) === 1
        && $debtEvents[0]['aggregate_id'] === $debt['debtNo']
        && $debtEvents[0]['payload']['checkoutRequestId'] === $authority['checkoutRequestId']
        && $debtEvents[0]['payload']['salesOrderId'] === $salesOrder['orderId']
        && $debtEvents[0]['payload']['debtAmountCents'] === 49600
);

$operator = new CashierV3OperatorScope(7, 21, 'org-1', 'tenant-1');
$dataScope = new CashierV3DataScopeContext(
    21,
    31,
    7,
    'tenant-1',
    'org-1',
    [7],
    CashierV3DataScopeContext::MODE_STORES,
    ['mode' => 'stores', 'store_ids' => [7]],
    false,
    '',
    'roles:' . str_repeat('1', 32),
    ['cashier.v3.cashier', 'cashier.v3.writeoff'],
    ['account' => 'tester']
);
$recorder = new CashierV3BusinessEventRecorder();
$execution = $recorder->newExecution(
    'submit-checkout',
    $authority['commandIdempotencyKey'],
    $operator,
    $dataScope,
    $authority['stateContextId']
);
$aggregate = [
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
];
$context = new CashierV3CheckoutSubmissionExecutionContext(
    $aggregate,
    $operator,
    $dataScope,
    $recorder,
    $execution,
    ['required_event_types' => ['checkout.completed']],
    ['kernelPlan' => $kernelPlan]
);
$context->assertMatchesAuthority($authority);
submissionExecutionAssert(
    'C2-EXEC-07 immutable execution context is request-scoped and identity-bound',
    $context->aggregate()['request']['request_id'] === $authority['checkoutRequestId']
        && $context->eventExecution()->idempotencyKey() === $authority['commandIdempotencyKey']
        && $context->hasEntitlementBundle()
);

$portSource = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/settlement/ThinkPhpCashierV3CheckoutSubmissionExecutionPort.php'
);
$contextSource = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutSubmissionExecutionContext.php'
);
submissionExecutionAssert(
    'C2-EXEC-07 execution port never owns a transaction boundary',
    strpos($portSource, 'startTrans') === false
        && strpos($portSource, 'Db::transaction') === false
        && strpos($portSource, '->commit') === false
        && strpos($portSource, '->rollback') === false
);
submissionExecutionAssert(
    'C2-EXEC-08 ECR persistence is built only from a real recorded checkout event',
    strpos($portSource, 'buildPersistencePlanAfterEvents(') !== false
        && strpos($portSource, "'event_no' => \$event['event_no']") !== false
        && strpos($portSource, 'PENDING-EVENT') === false
);
submissionExecutionAssert(
    'C2-EXEC-09 sale-only delegates the previously proven submission service',
    strpos($portSource, 'return $this->saleOnly->submitInTx($scope);') !== false
);
submissionExecutionAssert(
    'C2-EXEC-10 request state is carried by the opaque context, not shared port properties',
    strpos($contextSource, 'Per-command server authority') !== false
        && strpos($portSource, 'private $current') === false
        && strpos($portSource, 'private $aggregate') === false
        && strpos($portSource, 'private $executionContext') === false
);
submissionExecutionAssert(
    'C2-EXEC-11 tenant display snapshot comes from the locked server dimension',
    strpos(
        $portSource,
        '$request[\'organization_name_snapshot\']'
    ) !== false
        && strpos($portSource, "'tenantNameSnapshot' => ''") === false
        && strpos($portSource, 'checkout_tenant_name_snapshot_mismatch') !== false
);

$repositorySource = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/settlement/ThinkPhpCashierV3CheckoutRequestRepository.php'
);
$markSucceededBlock = submissionExecutionBlock(
    $portSource,
    'public function markSucceededInTx(',
    'public function completeWorkspaceInTx('
);
submissionExecutionAssert(
    'C2-EXEC-12 repository completion authority is verified before the top-level reference is projected',
    strpos($repositorySource, "'completionReference' => \$completionReference") !== false
        && strpos($repositorySource, "'salesOrderId' =>") !== false
        && strpos($repositorySource, "'entitlementCompletionReceiptId' =>") !== false
        && strpos($markSucceededBlock, "\$result['completionReference']") !== false
        && strpos($markSucceededBlock, "'mixed_checkout'") !== false
        && strpos($markSucceededBlock, "'entitlement_completion_receipt'") !== false
        && strpos($markSucceededBlock, "\$result['completionReferenceId'] = \$id") !== false
        && strpos($markSucceededBlock, 'checkout_success_reference_authority_mismatch') !== false
);
submissionExecutionAssert(
    'C2-EXEC-13 provider contract failures are translated to stable command errors',
    strpos(
        $portSource,
        'use app\\services\\cashier\\v3\\checkout\\provider\\CashierV3EntitlementProviderContractException;'
    ) !== false
        && strpos($portSource, '| CashierV3EntitlementProviderContractException') !== false
);
submissionExecutionAssert(
    'C2-EXEC-14 zero balance deduction remains a valid optional checkout slice',
    submissionExecutionInvoke($port, 'domainCall', [static function (): ?array {
        return null;
    }]) === null
        && (new ReflectionMethod($port, 'domainCall'))->getReturnType()->allowsNull()
);

echo "C2 checkout submission execution: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
