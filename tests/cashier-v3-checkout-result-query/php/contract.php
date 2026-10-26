<?php

$root = dirname(__DIR__, 3);
$base = $root . '/后端代码/app/services/cashier/v3';
require $base . '/CashierV3BusinessDocumentNumberServices.php';
require $base . '/CashierV3ResultCode.php';
require $base . '/CashierV3CommandException.php';
require $base . '/CashierV3ResourceScope.php';
require $base . '/CashierV3ScopeResolver.php';
require $base . '/CashierV3OperatorScope.php';
require $base . '/CashierV3DataScopeContext.php';
require $base . '/CashierV3IdempotencyKeyServices.php';
require $base . '/settlement/CashierV3CheckoutSettlementContractException.php';
require $base . '/settlement/CashierV3CheckoutSettlementStateMachine.php';
require $base . '/settlement/CashierV3CheckoutRequestRepository.php';
require $base . '/settlement/CashierV3CheckoutResultReadRepository.php';
require $base . '/settlement/CashierV3CheckoutResultQueryServices.php';
require $base . '/settlement/CashierV3CheckoutProjectionServices.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\settlement\CashierV3CheckoutResultQueryServices;
use app\services\cashier\v3\settlement\CashierV3CheckoutResultReadRepository;

final class CheckoutResultFakeRepository implements CashierV3CheckoutResultReadRepository
{
    public $receipt;
    public $committed;
    public $receiptCalls = [];
    public $committedCalls = 0;
    public $latest;

    public function findLatestCommittedCheckoutForWorkspace(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ) {
        return $this->latest;
    }

    public function findLatestCommittedSaleOnlyForWorkspace(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ) {
        return $this->latest;
    }

    public function findReceiptForActor(
        string $idempotencyKey,
        int $storeId
    ) {
        $this->receiptCalls[] = [$idempotencyKey, $storeId];
        return $this->receipt;
    }

    public function findCommittedSaleOnlyResult(
        array $receipt,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ) {
        $this->committedCalls++;
        return $this->committed;
    }

    public function findCommittedCheckoutResult(
        array $receipt,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ) {
        $this->committedCalls++;
        return $this->committed;
    }
}

$passed = 0;
$failed = 0;
function checkoutResultOk(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}" . ($detail !== '' ? ": {$detail}" : '') . "\n";
}

function checkoutResultScope(
    string $mode = CashierV3DataScopeContext::MODE_STORES,
    int $storeId = 7,
    int $operatorId = 21,
    array $visibleStores = [7]
): CashierV3DataScopeContext {
    return new CashierV3DataScopeContext(
        $operatorId,
        121,
        $storeId,
        '0',
        '3',
        $mode === CashierV3DataScopeContext::MODE_ALL ? null : $visibleStores,
        $mode,
        [],
        $mode === CashierV3DataScopeContext::MODE_ALL,
        $mode === CashierV3DataScopeContext::MODE_ALL ? 'system_super_admin' : '',
        'permission-v1',
        ['cashier.v3.cashier'],
        []
    );
}

function checkoutResultOperator(int $storeId = 7, int $operatorId = 21): CashierV3OperatorScope
{
    return new CashierV3OperatorScope($storeId, $operatorId, '3', '0');
}

function checkoutResultReceipt(int $status = 1, string $action = 'submit-checkout'): array
{
    return [
        'idempotency_key' => 'CHECKOUT-123e4567-e89b-42d3-a456-426614174000',
        'action' => $action,
        'store_id' => 7,
        'operator_id' => 21,
        'state_context_id' => 'state-result-query',
        'status' => $status,
        'result_code' => '',
        'result_message' => '',
        'result_json' => '{}',
        'business_no' => '',
    ];
}

function checkoutResultCommitted(string $composition = 'sale_only'): array
{
    $entitlement = $composition === 'mixed' ? [
        'receiptId' => 'ECR-' . str_repeat('e', 40),
        'receiptStatus' => 'completed',
        'planFingerprint' => str_repeat('f', 64),
    ] : null;
    return [
        'composition' => $composition,
        'checkoutRequest' => [
            'requestId' => 'CKR-' . str_repeat('b', 40),
            'requestVersion' => 12,
            'requestStatus' => 'succeeded',
            'memberId' => 1001,
            'receivableAmountCents' => 10000,
        ],
        'salesOrder' => [
            'orderId' => 'CSO-' . str_repeat('a', 40),
            'orderNo' => 'XS26072900001',
            'orderStatus' => 'settled',
            'orderVersion' => 1,
            'memberName' => '不得泄露',
            'saleAmountCents' => 10000,
        ],
        'entitlementCompletion' => $entitlement,
    ];
}

function checkoutEntitlementResultCommitted(): array
{
    return [
        'composition' => 'entitlement_only',
        'checkoutRequest' => [
            'requestId' => 'CKR-' . str_repeat('d', 40),
            'requestVersion' => 8,
            'requestStatus' => 'succeeded',
        ],
        'salesOrder' => null,
        'entitlementCompletion' => [
            'receiptId' => 'ECR-' . str_repeat('e', 40),
            'receiptStatus' => 'completed',
            'planFingerprint' => str_repeat('f', 64),
        ],
    ];
}

function checkoutSucceededProjectionAuthority(string $composition = 'sale_only'): array
{
    $entitlement = $composition === 'mixed' ? [
        'receiptId' => 'ECR-' . str_repeat('e', 40),
        'receiptStatus' => 'completed',
        'planFingerprint' => str_repeat('f', 64),
    ] : null;
    return [
        'composition' => $composition,
        'originalIdempotencyKey' => 'CHECKOUT-123e4567-e89b-42d3-a456-426614174000',
        'settledAt' => 1785283200,
        'checkoutRequest' => [
            'requestId' => 'CKR-' . str_repeat('b', 40),
            'requestVersion' => 12,
            'requestStatus' => 'succeeded',
        ],
        'salesOrder' => [
            'orderId' => 'CSO-' . str_repeat('a', 40),
            'orderNo' => 'XS26072900001',
            'orderStatus' => 'settled',
            'orderVersion' => 1,
            'checkoutRequestVersion' => 11,
        ],
        'entitlementCompletion' => $entitlement,
    ];
}

function checkoutEntitlementProjectionAuthority(): array
{
    return [
        'composition' => 'entitlement_only',
        'originalIdempotencyKey' => 'CHECKOUT-123e4567-e89b-42d3-a456-426614174000',
        'settledAt' => 1785283200,
        'checkoutRequest' => [
            'requestId' => 'CKR-' . str_repeat('d', 40),
            'requestVersion' => 8,
            'requestStatus' => 'succeeded',
        ],
        'salesOrder' => null,
        'entitlementCompletion' => [
            'receiptId' => 'ECR-' . str_repeat('e', 40),
            'receiptStatus' => 'completed',
            'planFingerprint' => str_repeat('f', 64),
        ],
    ];
}

function checkoutResultExceptionCode(callable $callback): string
{
    try {
        $callback();
    } catch (CashierV3CommandException $exception) {
        return $exception->getResultCode();
    }
    return '';
}

$key = 'CHECKOUT-123e4567-e89b-42d3-a456-426614174000';
$payload = ['originalIdempotencyKey' => $key];
$operator = checkoutResultOperator();
$scope = checkoutResultScope();

$rootSuccess = \app\services\cashier\v3\settlement\CashierV3CheckoutProjectionServices::projectSucceededResult(
    checkoutSucceededProjectionAuthority()
);
checkoutResultOk('root success projection is authoritative but never auto-resumes an old checkout',
    $rootSuccess['status'] === 'succeeded'
        && $rootSuccess['checkoutRequestVersion'] === 12
        && $rootSuccess['salesOrderNo'] === 'XS26072900001'
        && $rootSuccess['resumeOnLoad'] === false
        && $rootSuccess['commandContexts'] === []);
checkoutResultOk('root success projection exposes no member money or mutable checkout commands',
    !isset($rootSuccess['member'])
        && !isset($rootSuccess['receivableAmount'])
        && $rootSuccess['canRetry'] === false
        && $rootSuccess['preparationReady'] === false);

$mixedRoot = \app\services\cashier\v3\settlement\CashierV3CheckoutProjectionServices::projectSucceededResult(
    checkoutSucceededProjectionAuthority('mixed')
);
checkoutResultOk('mixed root result keeps its real CSO while preserving the entitlement composition',
    $mixedRoot['compositionCode'] === 'mixed'
        && $mixedRoot['salesOrderId'] === 'CSO-' . str_repeat('a', 40)
        && $mixedRoot['entitlementCompletionReceiptId'] === 'ECR-' . str_repeat('e', 40)
        && $mixedRoot['completionKind'] === 'sale_and_entitlement_service_completed'
        && $mixedRoot['composition']['hasSale'] === true
        && $mixedRoot['composition']['hasEntitlement'] === true
        && $mixedRoot['composition']['lineRoles'] === ['sale', 'entitlement_service']);

$entitlementRoot = \app\services\cashier\v3\settlement\CashierV3CheckoutProjectionServices::projectSucceededResult(
    checkoutEntitlementProjectionAuthority()
);
checkoutResultOk('entitlement-only root result points to a real ECR and never fabricates a sales order',
    $entitlementRoot['compositionCode'] === 'entitlement_only'
        && $entitlementRoot['salesOrderId'] === ''
        && $entitlementRoot['salesOrderNo'] === ''
        && $entitlementRoot['entitlementCompletionReceiptId'] === 'ECR-' . str_repeat('e', 40)
        && $entitlementRoot['composition']['hasSale'] === false
        && $entitlementRoot['composition']['hasEntitlement'] === true);

$repository = new CheckoutResultFakeRepository();
$query = new CashierV3CheckoutResultQueryServices($repository);
$notFound = $query->query($payload, $operator, $scope);
checkoutResultOk('missing receipt remains result_unknown rather than authorizing a retry with a new key',
    $notFound['status'] === CashierV3ResultCode::STATUS_RESULT_UNKNOWN
        && $notFound['phase'] === 'not_found'
        && $notFound['checkoutRequest'] === null
        && $notFound['salesOrder'] === null);
checkoutResultOk('receipt lookup is bound to the normalized key and current store',
    $repository->receiptCalls === [[$key, 7]]);

$repository = new CheckoutResultFakeRepository();
$repository->receipt = checkoutResultReceipt(0);
$pending = (new CashierV3CheckoutResultQueryServices($repository))->query($payload, $operator, $scope);
checkoutResultOk('processing receipt uses result_unknown plus pending phase',
    $pending['status'] === CashierV3ResultCode::STATUS_RESULT_UNKNOWN
        && $pending['phase'] === 'pending'
        && $repository->committedCalls === 0);

$repository = new CheckoutResultFakeRepository();
$repository->receipt = checkoutResultReceipt(2);
$repository->receipt['result_code'] = 'CHECKOUT_DECLINED';
$repository->receipt['result_message'] = '结账条件未满足。';
$terminalFailure = (new CashierV3CheckoutResultQueryServices($repository))->query(
    $payload,
    $operator,
    $scope
);
checkoutResultOk('terminal failure preserves the existing receipt code and message without business data',
    $terminalFailure['status'] === CashierV3ResultCode::STATUS_FAILED
        && $terminalFailure['phase'] === 'failed'
        && $terminalFailure['code'] === 'CHECKOUT_DECLINED'
        && $terminalFailure['message'] === '结账条件未满足。'
        && $terminalFailure['checkoutRequest'] === null
        && $terminalFailure['salesOrder'] === null);

$repository = new CheckoutResultFakeRepository();
$repository->receipt = checkoutResultReceipt();
$repository->committed = checkoutResultCommitted();
$success = (new CashierV3CheckoutResultQueryServices($repository))->query($payload, $operator, $scope);
checkoutResultOk('success requires both authoritative checkout request and sales order',
    $success['status'] === CashierV3ResultCode::STATUS_SUCCESS
        && $success['phase'] === 'succeeded'
        && $repository->committedCalls === 1);
checkoutResultOk('success projection exposes only stable ids statuses and versions',
    array_keys($success['checkoutRequest']) === ['requestId', 'requestVersion', 'requestStatus']
        && array_keys($success['salesOrder']) === ['orderId', 'orderNo', 'orderStatus', 'orderVersion']
        && !isset($success['checkoutRequest']['memberId'])
        && !isset($success['salesOrder']['saleAmountCents']));

$repository = new CheckoutResultFakeRepository();
$repository->receipt = checkoutResultReceipt();
$repository->committed = checkoutResultCommitted('mixed');
$mixedSuccess = (new CashierV3CheckoutResultQueryServices($repository))->query(
    $payload,
    $operator,
    $scope
);
checkoutResultOk('mixed query requires both its real CSO and completed ECR authorities',
    $mixedSuccess['status'] === CashierV3ResultCode::STATUS_SUCCESS
        && $mixedSuccess['composition'] === 'mixed'
        && $mixedSuccess['salesOrder']['orderId'] === 'CSO-' . str_repeat('a', 40)
        && $mixedSuccess['entitlementCompletion']['receiptId']
            === 'ECR-' . str_repeat('e', 40));

$repository = new CheckoutResultFakeRepository();
$repository->receipt = checkoutResultReceipt();
$repository->committed = checkoutEntitlementResultCommitted();
$entitlementSuccess = (new CashierV3CheckoutResultQueryServices($repository))->query(
    $payload,
    $operator,
    $scope
);
checkoutResultOk('entitlement-only query succeeds only with a completed ECR and no sales order',
    $entitlementSuccess['status'] === CashierV3ResultCode::STATUS_SUCCESS
        && $entitlementSuccess['composition'] === 'entitlement_only'
        && $entitlementSuccess['salesOrder'] === null
        && $entitlementSuccess['entitlementCompletion']['receiptId']
            === 'ECR-' . str_repeat('e', 40)
        && $entitlementSuccess['entitlementCompletion']['receiptStatus'] === 'completed');

$repository = new CheckoutResultFakeRepository();
$repository->receipt = checkoutResultReceipt();
$repository->committed = checkoutEntitlementResultCommitted();
$repository->committed['entitlementCompletion']['receiptStatus'] = 'processing';
$incompleteEntitlement = (new CashierV3CheckoutResultQueryServices($repository))->query(
    $payload,
    $operator,
    $scope
);
checkoutResultOk('processing entitlement receipt can never prove checkout success',
    $incompleteEntitlement['status'] === CashierV3ResultCode::STATUS_RESULT_UNKNOWN
        && $incompleteEntitlement['phase'] === 'reconciliation_required');

$repository = new CheckoutResultFakeRepository();
$repository->receipt = checkoutResultReceipt();
$repository->committed = null;
$incomplete = (new CashierV3CheckoutResultQueryServices($repository))->query($payload, $operator, $scope);
checkoutResultOk('a succeeded receipt without matching authorities stays unknown for reconciliation',
    $incomplete['status'] === CashierV3ResultCode::STATUS_RESULT_UNKNOWN
        && $incomplete['phase'] === 'reconciliation_required');

$repository = new CheckoutResultFakeRepository();
$repository->receipt = checkoutResultReceipt(1, 'prepare-checkout-submission');
checkoutResultOk('only an original submit-checkout receipt is accepted',
    checkoutResultExceptionCode(static function () use ($repository, $payload, $operator, $scope): void {
        (new CashierV3CheckoutResultQueryServices($repository))->query($payload, $operator, $scope);
    }) === CashierV3ResultCode::IDEMPOTENCY_KEY_CONFLICT);

$repository = new CheckoutResultFakeRepository();
$repository->receipt = checkoutResultReceipt();
$repository->receipt['operator_id'] = 22;
checkoutResultOk('same-store result recovery ignores the original operator identity',
    (new CashierV3CheckoutResultQueryServices($repository))->query($payload, $operator, $scope)['status']
        === CashierV3ResultCode::STATUS_RESULT_UNKNOWN);

$repository = new CheckoutResultFakeRepository();
checkoutResultOk('non-checkout original keys are rejected before any receipt read',
    checkoutResultExceptionCode(static function () use ($repository, $operator, $scope): void {
        (new CashierV3CheckoutResultQueryServices($repository))->query([
            'originalIdempotencyKey' => 'SERVICE-123e4567-e89b-42d3-a456-426614174000',
        ], $operator, $scope);
    }) === CashierV3ResultCode::INVALID_IDEMPOTENCY_KEY
        && $repository->receiptCalls === []);

$repository = new CheckoutResultFakeRepository();
checkoutResultOk('MODE_NONE is denied before touching the repository',
    checkoutResultExceptionCode(static function () use ($repository, $payload, $operator): void {
        (new CashierV3CheckoutResultQueryServices($repository))->query(
            $payload,
            $operator,
            checkoutResultScope(CashierV3DataScopeContext::MODE_NONE, 7, 21, [])
        );
    }) === CashierV3ResultCode::PERMISSION_DENIED
        && $repository->receiptCalls === []);

$repository = new CheckoutResultFakeRepository();
$selfParticipant = (new CashierV3CheckoutResultQueryServices($repository))->query(
    $payload,
    $operator,
    checkoutResultScope(CashierV3DataScopeContext::MODE_SELF_PARTICIPANT, 7, 21, [])
);
checkoutResultOk('self-participant scope still cannot broaden store permission',
    $selfParticipant['status'] === CashierV3ResultCode::STATUS_RESULT_UNKNOWN
        && $repository->receiptCalls === [[$key, 7]]);

echo "CHECKOUT_RESULT_QUERY_CONTRACT passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
