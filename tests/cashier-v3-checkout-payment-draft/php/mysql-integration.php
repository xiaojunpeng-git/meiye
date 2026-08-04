<?php
declare(strict_types=1);

require '/tests/cashier-v3/lib/boot-env.php';
require '/var/www/html/vendor/autoload.php';
require '/tests/cashier-v3/lib/_lib.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\settlement\CashierV3CheckoutPaymentDraftServices;
use app\services\cashier\v3\settlement\CashierV3CheckoutProjectionServices;
use app\services\cashier\v3\settlement\CashierV3CheckoutRequestRepository;
use app\services\cashier\v3\settlement\CashierV3CheckoutSettlementKernel;
use app\services\cashier\v3\settlement\CashierV3CheckoutVerifiedSourceSet;
use app\services\cashier\v3\settlement\ThinkPhpCashierV3CheckoutRequestRepository;
use think\facade\Db;

c1aBootThinkApp('/var/www/html/');

final class PaymentDraftCapturingRepository implements CashierV3CheckoutRequestRepository
{
    /** @var CashierV3CheckoutRequestRepository */
    private $delegate;

    /** @var array|null */
    public $lastKernel;

    /** @var CashierV3CheckoutVerifiedSourceSet|null */
    public $lastSources;

    public function __construct(CashierV3CheckoutRequestRepository $delegate)
    {
        $this->delegate = $delegate;
    }

    public function lockAggregateForEditInTx(
        string $requestId,
        int $expectedVersion,
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        return $this->delegate->lockAggregateForEditInTx(
            $requestId,
            $expectedVersion,
            $workspaceId,
            $stateContextId,
            $operatorScope,
            $dataScope
        );
    }

    public function lockAggregateForSubmitInTx(
        string $requestId,
        int $expectedVersion,
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        return $this->delegate->lockAggregateForSubmitInTx(
            $requestId,
            $expectedVersion,
            $workspaceId,
            $stateContextId,
            $operatorScope,
            $dataScope
        );
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
        return $this->delegate->markSucceededInTx(
            $requestId,
            $expectedVersion,
            $commandIdempotencyKey,
            $salesOrderId,
            $settledAt,
            $operatorScope,
            $dataScope
        );
    }

    public function readLatestEditingProjection(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ) {
        return $this->delegate->readLatestEditingProjection(
            $workspaceId,
            $stateContextId,
            $operatorScope,
            $dataScope
        );
    }

    public function lockCurrentForKernelInTx(
        string $requestId,
        string $creationIdempotencyKey,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ) {
        return $this->delegate->lockCurrentForKernelInTx(
            $requestId,
            $creationIdempotencyKey,
            $operatorScope,
            $dataScope
        );
    }

    public function persistKernelPlanInTx(
        array $kernelResult,
        CashierV3CheckoutVerifiedSourceSet $verifiedSources,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $this->lastKernel = $kernelResult;
        $this->lastSources = $verifiedSources;
        return $this->delegate->persistKernelPlanInTx(
            $kernelResult,
            $verifiedSources,
            $operatorScope,
            $dataScope
        );
    }
}

$passed = 0;
$failed = 0;

function paymentMysqlOk(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}" . ($detail === '' ? '' : ": {$detail}") . "\n";
}

function paymentMysqlUuid(int $number, string $prefix = 'CMD'): string
{
    return sprintf('%s-00000000-0000-4000-8000-%012d', $prefix, $number);
}

function paymentMysqlOperator(): CashierV3OperatorScope
{
    return new CashierV3OperatorScope(7, 21, '3', '0');
}

function paymentMysqlDataScope(): CashierV3DataScopeContext
{
    return new CashierV3DataScopeContext(
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
        'payment-draft-scope-v1',
        ['cashier.v3.cashier'],
        ['id' => 21, 'employee_id' => 121, 'staff_name' => '收款测试员']
    );
}

function paymentMysqlSnapshot(): array
{
    $snapshot = [
        'contractVersion' => CashierV3CheckoutSettlementKernel::AUTHORITY_CONTRACT_VERSION,
        'authorityOrigin' => 'server_final_lock_snapshot',
        'authoritySnapshotVersion' => 1,
        'authoritySnapshotFingerprint' => '',
        'tenantId' => '0',
        'organizationId' => '3',
        'organizationPath' => '/1/3/',
        'organizationName' => '收款测试组织',
        'storeId' => 7,
        'storeName' => '收款测试门店',
        'workspaceId' => 'ws:7:21:payment-mysql-state',
        'stateContextId' => 'payment-mysql-state',
        'permissionSnapshotFingerprint' => 'payment-draft-scope-v1',
        'memberId' => 1001,
        'memberName' => '收款测试会员',
        'operatorId' => 21,
        'operatorName' => '收款测试员',
        'businessDate' => '2026-07-20',
        'businessTimezone' => 'Asia/Shanghai',
        'occurredAt' => 1785258000,
        'recordedAt' => 1785258000,
        'sourceDocument' => [
            'type' => 'service_order',
            'id' => 'SO-PAY-1',
            'no' => 'FW202607200001',
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
        'paymentDetails' => [],
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

function paymentMysqlSources(): CashierV3CheckoutVerifiedSourceSet
{
    return CashierV3CheckoutVerifiedSourceSet::fromServerVerifiedAuthorityRows('0', 7, [[
        'tenantId' => '0',
        'storeId' => 7,
        'kind' => 'service_order',
        'id' => 'SO-PAY-1',
        'sourceVersion' => 31,
        'role' => 'service_origin',
    ]]);
}

function paymentMysqlProjection(
    CashierV3CheckoutProjectionServices $projections,
    CashierV3OperatorScope $operator,
    CashierV3DataScopeContext $scope
): array {
    $projection = $projections->readCurrent(
        'ws:7:21:payment-mysql-state',
        'payment-mysql-state',
        $operator,
        $scope
    );
    if (!is_array($projection)) {
        throw new RuntimeException('PAYMENT_CURRENT_PROJECTION_MISSING');
    }
    return $projection;
}

function paymentMysqlActionScope(
    array $projection,
    string $action,
    array $specific,
    int $idempotencyNumber,
    CashierV3OperatorScope $operator,
    CashierV3DataScopeContext $scope,
    ?int $requestVersionOverride = null
): array {
    $requestVersion = $requestVersionOverride === null
        ? (int)$projection['checkoutRequestVersion']
        : $requestVersionOverride;
    $contexts = array_values((array)$projection['commandContexts']);
    foreach ($contexts as &$context) {
        if ((string)($context['kind'] ?? '') === 'checkout_request') {
            $context['expectedVersion'] = $requestVersion;
        }
    }
    unset($context);
    return [
        'action' => $action,
        'payload' => array_merge([
            'checkoutRequestId' => (string)$projection['checkoutRequestId'],
            'checkoutRequestVersion' => $requestVersion,
            'preparationRequestId' => (string)$projection['preparationRequestId'],
            'preparationToken' => (string)$projection['preparationToken'],
        ], $specific),
        'contexts' => $contexts,
        'idempotency_key' => paymentMysqlUuid($idempotencyNumber),
        'operator_scope' => $operator,
        'data_scope' => $scope,
        'state_context_id' => 'payment-mysql-state',
    ];
}

function paymentMysqlAdvanceWorkspace(int $expectedVersion, string $action): void
{
    $affected = (int)Db::name('cashier_v3_resource_version')
        ->where('scope_type', 'store')
        ->where('scope_id', '7')
        ->where('resource_kind', 'cashier_workspace')
        ->where('resource_id', 'ws:7:21:payment-mysql-state')
        ->where('current_version', $expectedVersion)
        ->update([
            'current_version' => $expectedVersion + 1,
            'last_action' => $action,
            'update_time' => time(),
        ]);
    if ($affected !== 1) {
        throw new RuntimeException('PAYMENT_WORKSPACE_CAS_FAILED:' . $expectedVersion);
    }
}

function paymentMysqlFailure(callable $callback): array
{
    try {
        $callback();
    } catch (CashierV3CommandException $exception) {
        return [
            'code' => $exception->getResultCode(),
            'reason' => (string)($exception->getDetail()['reason'] ?? ''),
        ];
    } catch (Throwable $throwable) {
        return [
            'code' => get_class($throwable),
            'reason' => $throwable->getMessage(),
        ];
    }
    return ['code' => '', 'reason' => ''];
}

Db::execute('DELETE FROM `eb_cashier_v3_checkout_source_reference`');
Db::execute('DELETE FROM `eb_cashier_v3_checkout_payment_draft`');
Db::execute('DELETE FROM `eb_cashier_v3_checkout_line_draft`');
Db::execute('DELETE FROM `eb_cashier_v3_checkout_request`');
Db::execute('DELETE FROM `eb_cashier_v3_resource_version`');

$operator = paymentMysqlOperator();
$scope = paymentMysqlDataScope();
$repository = new ThinkPhpCashierV3CheckoutRequestRepository();
$capturingRepository = new PaymentDraftCapturingRepository($repository);
$projections = new CashierV3CheckoutProjectionServices($repository);
$paymentDrafts = new CashierV3CheckoutPaymentDraftServices(
    $capturingRepository,
    null,
    'payment-draft-mysql-secret-at-least-32-bytes'
);
$sources = paymentMysqlSources();

Db::name('cashier_v3_resource_version')->insert([
    'scope_type' => 'store',
    'scope_id' => '7',
    'resource_kind' => 'cashier_workspace',
    'resource_id' => 'ws:7:21:payment-mysql-state',
    'current_version' => 1,
    'last_action' => '',
    'add_time' => time(),
    'update_time' => time(),
]);

$prepareCommand = [
    'contractVersion' => CashierV3CheckoutSettlementKernel::CONTRACT_VERSION,
    'operation' => CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT,
    'idempotencyKey' => paymentMysqlUuid(1, 'CHECKOUT_PREPARE'),
    'workspaceId' => 'ws:7:21:payment-mysql-state',
    'stateContextId' => 'payment-mysql-state',
    'permissionSnapshotFingerprint' => 'payment-draft-scope-v1',
];
$prepared = Db::transaction(static function () use (
    $repository,
    $prepareCommand,
    $sources,
    $operator,
    $scope
): array {
    $kernel = CashierV3CheckoutSettlementKernel::saveDraft(
        $prepareCommand,
        paymentMysqlSnapshot(),
        null,
        'payment-draft-mysql-secret-at-least-32-bytes'
    );
    return $repository->persistKernelPlanInTx($kernel, $sources, $operator, $scope);
});
paymentMysqlAdvanceWorkspace(1, 'prepare-checkout');
paymentMysqlOk('prepare creates one editing request at N with no payment rows',
    $prepared['requestVersion'] === 1
    && (int)Db::name('cashier_v3_checkout_request')->count() === 1
    && (int)Db::name('cashier_v3_checkout_payment_draft')->count() === 0
    && (int)Db::name('cashier_v3_checkout_source_reference')->count() === 1);

$projectionN = paymentMysqlProjection($projections, $operator, $scope);
$added = Db::transaction(static function () use (
    $paymentDrafts,
    $projectionN,
    $operator,
    $scope
): array {
    return $paymentDrafts->mutateInTx(
        'add-payment-method',
        paymentMysqlActionScope(
            $projectionN,
            'add-payment-method',
            ['paymentMethodId' => 'wechat'],
            2,
            $operator,
            $scope
        )
    );
});
paymentMysqlAdvanceWorkspace(2, 'add-payment-method');
$paymentId = (string)Db::name('cashier_v3_checkout_payment_draft')->value('payment_draft_id');
paymentMysqlOk('add at N defaults to remaining receivable and advances request to N+1',
    $added['version'] === 2
    && $added['totals']['selectedPaymentAmountCents'] === 7000
    && (int)Db::name('cashier_v3_checkout_payment_draft')->value('amount_cents') === 7000
    && (int)Db::name('cashier_v3_checkout_payment_draft')->value('draft_version') === 2
    && $paymentId !== '');

$capturedAddKernel = $capturingRepository->lastKernel;
$capturedAddSources = $capturingRepository->lastSources;
$capturedAddIdempotencyKey = (string)$capturedAddKernel['persistencePlan']['request']['lastIdempotencyKey'];
$beforeReplayCounts = [
    (int)Db::name('cashier_v3_checkout_line_draft')->count(),
    (int)Db::name('cashier_v3_checkout_payment_draft')->count(),
    (int)Db::name('cashier_v3_checkout_source_reference')->count(),
];
$replayed = Db::transaction(static function () use (
    $repository,
    $capturedAddKernel,
    $capturedAddSources,
    $operator,
    $scope
): array {
    return $repository->persistKernelPlanInTx(
        $capturedAddKernel,
        $capturedAddSources,
        $operator,
        $scope
    );
});
paymentMysqlOk('same idempotency persistence replay adds no version or child rows',
    $replayed['replayed'] === true
    && (int)Db::name('cashier_v3_checkout_request')->value('request_version') === 2
    && (string)Db::name('cashier_v3_checkout_request')->value('last_idempotency_key')
        === $capturedAddIdempotencyKey
    && $beforeReplayCounts === [
        (int)Db::name('cashier_v3_checkout_line_draft')->count(),
        (int)Db::name('cashier_v3_checkout_payment_draft')->count(),
        (int)Db::name('cashier_v3_checkout_source_reference')->count(),
    ]
    && (int)Db::name('cashier_v3_checkout_payment_draft')->value('amount_cents') === 7000);

$projectionN1 = paymentMysqlProjection($projections, $operator, $scope);
$fractionalBefore = [
    (int)Db::name('cashier_v3_checkout_request')->value('request_version'),
    (int)Db::name('cashier_v3_checkout_payment_draft')->value('amount_cents'),
];
$fractionalFailure = paymentMysqlFailure(static function () use (
    $paymentDrafts,
    $projectionN1,
    $paymentId,
    $operator,
    $scope
): void {
    Db::transaction(static function () use (
        $paymentDrafts,
        $projectionN1,
        $paymentId,
        $operator,
        $scope
    ): void {
        $paymentDrafts->mutateInTx(
            'update-payment-line',
            paymentMysqlActionScope($projectionN1, 'update-payment-line', [
                'paymentLineId' => $paymentId,
                'amount' => '60.25',
                'externalTransactionNo' => '',
                'remark' => '',
            ], 3, $operator, $scope)
        );
    });
});
paymentMysqlOk('fractional payment update is rejected without advancing the draft',
    $fractionalFailure['code'] === CashierV3ResultCode::INVALID_COMMAND_CONTEXT
    && $fractionalFailure['reason'] === 'payment_amount_invalid'
    && $fractionalBefore === [
        (int)Db::name('cashier_v3_checkout_request')->value('request_version'),
        (int)Db::name('cashier_v3_checkout_payment_draft')->value('amount_cents'),
    ]);

$updated = Db::transaction(static function () use (
    $paymentDrafts,
    $projectionN1,
    $paymentId,
    $operator,
    $scope
): array {
    return $paymentDrafts->mutateInTx(
            'update-payment-line',
            paymentMysqlActionScope($projectionN1, 'update-payment-line', [
                'paymentLineId' => $paymentId,
                'amount' => '60',
                'externalTransactionNo' => 'WECHAT-TRACE-1',
                'remark' => '组合收款测试',
            ], 4, $operator, $scope)
    );
});
paymentMysqlAdvanceWorkspace(3, 'update-payment-line');
paymentMysqlOk('continuous update uses N+1 and advances request to N+2',
    $projectionN1['checkoutRequestVersion'] === 2
    && $updated['version'] === 3
    && $updated['totals']['selectedPaymentAmountCents'] === 6000
    && (int)Db::name('cashier_v3_checkout_payment_draft')->value('amount_cents') === 6000
    && (int)Db::name('cashier_v3_checkout_payment_draft')->value('draft_version') === 3);
paymentMysqlOk('N+2 request payment and source rows share one aggregate version',
    (int)Db::name('cashier_v3_checkout_request')->value('request_version') === 3
    && (int)Db::name('cashier_v3_checkout_payment_draft')->value('draft_version') === 3
    && (int)Db::name('cashier_v3_checkout_source_reference')->value('bound_request_version') === 3
    && (int)Db::name('cashier_v3_checkout_source_reference')->value('source_version') === 31);

$projectionN2 = paymentMysqlProjection($projections, $operator, $scope);
$staleBefore = [
    (int)Db::name('cashier_v3_checkout_request')->value('request_version'),
    (int)Db::name('cashier_v3_checkout_payment_draft')->value('amount_cents'),
];
$staleFailure = paymentMysqlFailure(static function () use (
    $paymentDrafts,
    $projectionN2,
    $paymentId,
    $operator,
    $scope
): void {
    Db::transaction(static function () use (
        $paymentDrafts,
        $projectionN2,
        $paymentId,
        $operator,
        $scope
    ): void {
        $paymentDrafts->mutateInTx(
            'update-payment-line',
            paymentMysqlActionScope($projectionN2, 'update-payment-line', [
                'paymentLineId' => $paymentId,
                'amount' => '50',
                'externalTransactionNo' => '',
                'remark' => '竞争写入',
            ], 5, $operator, $scope, 2)
        );
    });
});
paymentMysqlOk('minimal competing stale N+1 writer conflicts and rolls back',
    $staleFailure['code'] === CashierV3ResultCode::RESOURCE_VERSION_CONFLICT
    && $staleFailure['reason'] === 'checkout_request_version_conflict'
    && $staleBefore === [
        (int)Db::name('cashier_v3_checkout_request')->value('request_version'),
        (int)Db::name('cashier_v3_checkout_payment_draft')->value('amount_cents'),
    ]);

$overBefore = [
    (int)Db::name('cashier_v3_checkout_request')->value('request_version'),
    (int)Db::name('cashier_v3_checkout_payment_draft')->value('amount_cents'),
];
$overFailure = paymentMysqlFailure(static function () use (
    $paymentDrafts,
    $projectionN2,
    $paymentId,
    $operator,
    $scope
): void {
    Db::transaction(static function () use (
        $paymentDrafts,
        $projectionN2,
        $paymentId,
        $operator,
        $scope
    ): void {
        $paymentDrafts->mutateInTx(
            'update-payment-line',
            paymentMysqlActionScope($projectionN2, 'update-payment-line', [
                'paymentLineId' => $paymentId,
                'amount' => '80',
                'externalTransactionNo' => '',
                'remark' => '',
            ], 6, $operator, $scope)
        );
    });
});
paymentMysqlOk('over-receivable update rolls back request and payment row',
    $overFailure['code'] === CashierV3ResultCode::INVALID_COMMAND_CONTEXT
    && $overFailure['reason'] === 'checkout_payment_exceeds_receivable'
    && $overBefore === [
        (int)Db::name('cashier_v3_checkout_request')->value('request_version'),
        (int)Db::name('cashier_v3_checkout_payment_draft')->value('amount_cents'),
    ]);

$removed = Db::transaction(static function () use (
    $paymentDrafts,
    $projectionN2,
    $paymentId,
    $operator,
    $scope
): array {
    return $paymentDrafts->mutateInTx(
        'remove-payment-line',
        paymentMysqlActionScope($projectionN2, 'remove-payment-line', [
            'paymentLineId' => $paymentId,
        ], 6, $operator, $scope)
    );
});
paymentMysqlAdvanceWorkspace(4, 'remove-payment-line');
paymentMysqlOk('continuous remove uses N+2 and advances request to N+3',
    $projectionN2['checkoutRequestVersion'] === 3
    && $removed['version'] === 4
    && $removed['totals']['selectedPaymentAmountCents'] === 0
    && (int)Db::name('cashier_v3_checkout_payment_draft')->count() === 0);

$projectionN3 = paymentMysqlProjection($projections, $operator, $scope);
$oldCardBefore = [
    (int)Db::name('cashier_v3_checkout_request')->value('request_version'),
    (int)Db::name('cashier_v3_checkout_line_draft')->count(),
    (int)Db::name('cashier_v3_checkout_source_reference')->count(),
];
$oldCardFailure = paymentMysqlFailure(static function () use (
    $paymentDrafts,
    $projectionN3,
    $operator,
    $scope
): void {
    Db::transaction(static function () use (
        $paymentDrafts,
        $projectionN3,
        $operator,
        $scope
    ): void {
        $paymentDrafts->mutateInTx(
            'add-payment-method',
            paymentMysqlActionScope($projectionN3, 'add-payment-method', [
                'paymentMethodId' => 'old_card_entry',
            ], 7, $operator, $scope)
        );
    });
});
paymentMysqlOk('old card entry is rejected with no checkout rows written',
    $oldCardFailure['code'] === CashierV3ResultCode::INVALID_COMMAND_CONTEXT
    && $oldCardFailure['reason'] === 'old_card_entry_separate_flow_required'
    && $oldCardBefore === [
        (int)Db::name('cashier_v3_checkout_request')->value('request_version'),
        (int)Db::name('cashier_v3_checkout_line_draft')->count(),
        (int)Db::name('cashier_v3_checkout_source_reference')->count(),
    ]
    && (int)Db::name('cashier_v3_checkout_payment_draft')->count() === 0);

$finalRequest = Db::name('cashier_v3_checkout_request')
    ->where('request_id', $prepared['requestId'])
    ->find();
$finalLine = Db::name('cashier_v3_checkout_line_draft')
    ->where('request_id', $prepared['requestId'])
    ->find();
$finalSource = Db::name('cashier_v3_checkout_source_reference')
    ->where('request_id', $prepared['requestId'])
    ->find();
paymentMysqlOk('final request line and source versions are one consistent aggregate',
    (int)$finalRequest['request_version'] === 4
    && (int)$finalRequest['authority_snapshot_version'] === 4
    && (int)$finalLine['draft_version'] === 4
    && (int)$finalSource['bound_request_version'] === 4
    && (int)$finalSource['source_version'] === 31
    && (string)$finalRequest['business_date'] === '2026-07-20'
    && (string)$finalLine['source_name_snapshot'] === '历史项目名称');
paymentMysqlOk('workspace reaches request authority version plus one',
    (int)Db::name('cashier_v3_resource_version')->value('current_version') === 5
    && (int)$finalRequest['authority_snapshot_version'] + 1 === 5);

echo "PAYMENT_DRAFT_MYSQL passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
