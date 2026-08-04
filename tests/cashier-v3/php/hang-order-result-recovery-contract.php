<?php
/** Focused recovery contract: deterministic failures stay bound; committed hangs are re-verified. */

$backendRoot = __DIR__ . '/../../../后端代码';
foreach ([
    '/app/services/cashier/v3/CashierV3ResultCode.php',
    '/app/services/cashier/v3/CashierV3CommandException.php',
    '/app/services/cashier/v3/CashierV3IdempotencyKeyServices.php',
    '/app/services/cashier/v3/CashierV3OperatorScope.php',
    '/app/services/cashier/v3/CashierV3DataScopeContext.php',
    '/app/services/cashier/v3/manifest/CashierV3ActionModule.php',
    '/app/services/cashier/v3/manifest/CashierV3C1WorkbenchModule.php',
    '/app/services/cashier/v3/manifest/CashierV3C2CashierModule.php',
    '/app/services/cashier/v3/manifest/CashierV3C3ServiceModule.php',
    '/app/services/cashier/v3/manifest/CashierV3C4DashboardModule.php',
    '/app/services/cashier/v3/manifest/CashierV3C5MemberOrderModule.php',
    '/app/services/cashier/v3/manifest/CashierV3ActionManifest.php',
    '/app/services/cashier/v3/CashierV3CommandFailureEnvelopeServices.php',
    '/app/services/cashier/v3/hang/CashierV3HangOrderResultReadRepository.php',
    '/app/services/cashier/v3/hang/authority/CashierV3HangOrderPlanV1.php',
    '/app/services/room/guard/RoomOpenServiceGuardAuthority.php',
    '/app/services/cashier/v3/hang/CashierV3HangOrderResultQueryServices.php',
] as $file) {
    require_once $backendRoot . $file;
}

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3CommandFailureEnvelopeServices;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\hang\CashierV3HangOrderResultQueryServices;
use app\services\cashier\v3\hang\CashierV3HangOrderResultReadRepository;

$passed = 0;
$failed = 0;

function hangRecoveryOk(string $name, bool $condition, string $detail = ''): void
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

final class HangRecoveryFakeRepository implements CashierV3HangOrderResultReadRepository
{
    public $receipt = null;
    public $committed = null;

    public function findReceiptForActor(string $idempotencyKey, int $storeId, int $operatorId)
    {
        return $this->receipt;
    }

    public function findCommittedHangOrder(
        array $receipt,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ) {
        return $this->committed;
    }
}

function hangRecoveryKey(): string
{
    return 'HANG-123e4567-e89b-42d3-a456-426614174000';
}

function hangRecoveryScopes(): array
{
    $operator = new CashierV3OperatorScope(8, 1, '3', '0');
    $scope = new CashierV3DataScopeContext(
        1,
        1,
        8,
        '0',
        '3',
        [8],
        CashierV3DataScopeContext::MODE_STORES,
        [],
        false,
        '',
        'hang-recovery-test-v1',
        ['cashier.v3.hang'],
        ['id' => 1]
    );
    return [$operator, $scope];
}

function hangRecoveryReceipt(int $status = 1, string $action = 'submit-hang-order'): array
{
    return [
        'idempotency_key' => hangRecoveryKey(),
        'action' => $action,
        'store_id' => 8,
        'operator_id' => 1,
        'state_context_id' => 'ctx-hang-recovery-1',
        'status' => $status,
        'result_code' => '',
        'result_message' => '',
    ];
}

function hangRecoveryCommitted(bool $guardMatches = true): array
{
    $hangId = 'HGO0123456789abcdef0123456789abcdef01234567';
    return [
        'header' => [
            'hang_order_id' => $hangId,
            'hang_order_no' => 'HG20260730ABCDEF0123456789',
            'command_idempotency_key' => hangRecoveryKey(),
            'hang_mode' => 'start_service',
            'hang_status' => 'service_in_progress',
            'hang_version' => 1,
            'line_count' => 1,
            'total_quantity' => 1,
            'room_id' => 11,
            'room_name_snapshot' => '普通房 01',
            'room_time_slot_id' => 'open-service:11',
            'occurred_at' => 1785400000,
        ],
        'heldLineCount' => 1,
        'roomGuard' => [
            'occupation_status' => 'OCCUPIED',
            'owner_kind' => 'cashier_v3_hang_order',
            'owner_id' => $guardMatches ? $hangId : 'HGOdifferent',
            'slot_key' => 'open-service:11',
        ],
    ];
}

echo "== deterministic command failure envelope ==\n";
$failureEnvelopes = new CashierV3CommandFailureEnvelopeServices();
$bound = $failureEnvelopes->fromException([
    'action' => 'submit-hang-order',
    'correlationId' => 'CORR-hang-failure-1',
    'stateContextId' => 'SC-hang-failure-1',
    'command' => [
        'action' => 'submit-hang-order',
        'idempotencyKey' => hangRecoveryKey(),
    ],
], CashierV3CommandException::invalidContext('挂单准备数据失效。'));
hangRecoveryOk('pre-receipt hang failure remains an action-bound failed response',
    ($bound['result']['status'] ?? '') === CashierV3ResultCode::STATUS_FAILED
    && ($bound['boundAction'] ?? '') === 'submit-hang-order'
    && ($bound['boundCanonical'] ?? '') === 'submit-hang-order'
    && ($bound['boundIdempotencyKey'] ?? '') === hangRecoveryKey()
    && ($bound['boundCorrelationId'] ?? '') === 'CORR-hang-failure-1'
    && ($bound['stateContextId'] ?? '') === 'SC-hang-failure-1'
);
$mixedAction = $failureEnvelopes->fromException([
    'action' => 'submit-hang-order',
    'correlationId' => 'CORR-hang-failure-2',
    'command' => [
        'action' => 'submit-checkout',
        'idempotencyKey' => hangRecoveryKey(),
    ],
], CashierV3CommandException::invalidContext('请求不一致。'));
hangRecoveryOk('mixed action request is never bound', !isset($mixedAction['boundAction']));
$unknownFailure = $failureEnvelopes->fromException([
    'action' => 'submit-hang-order',
    'correlationId' => 'CORR-hang-failure-3',
    'command' => [
        'action' => 'submit-hang-order',
        'idempotencyKey' => hangRecoveryKey(),
    ],
], new CashierV3CommandException(
    CashierV3ResultCode::COMMAND_RESULT_UNKNOWN,
    '结果未知。',
    CashierV3ResultCode::STATUS_RESULT_UNKNOWN
));
hangRecoveryOk('unknown command outcome is never converted to a deterministic failure', !isset($unknownFailure['boundAction']));
$projectionFailure = $failureEnvelopes->fromException([
    'action' => 'open-add-card-service-project',
    'correlationId' => 'CORR-entitlement-failure-1',
    'stateContextId' => 'SC-entitlement-failure-1',
], CashierV3CommandException::invalidContext('会员权益暂时无法加载。'));
hangRecoveryOk('projection failure remains action-bound without fabricating an idempotency key',
    ($projectionFailure['result']['status'] ?? '') === CashierV3ResultCode::STATUS_FAILED
    && ($projectionFailure['boundAction'] ?? '') === 'open-add-card-service-project'
    && ($projectionFailure['boundCanonical'] ?? '') === 'open-add-card-service-project'
    && !isset($projectionFailure['boundIdempotencyKey'])
    && ($projectionFailure['boundCorrelationId'] ?? '') === 'CORR-entitlement-failure-1'
    && ($projectionFailure['stateContextId'] ?? '') === 'SC-entitlement-failure-1'
);

echo "== receipt-backed hang recovery ==\n";
list($operator, $dataScope) = hangRecoveryScopes();
$repository = new HangRecoveryFakeRepository();
$queries = new CashierV3HangOrderResultQueryServices($repository);
$missing = $queries->query(['originalIdempotencyKey' => hangRecoveryKey()], $operator, $dataScope);
hangRecoveryOk('missing receipt stays result unknown',
    ($missing['status'] ?? '') === CashierV3ResultCode::STATUS_RESULT_UNKNOWN
    && ($missing['phase'] ?? '') === 'not_found'
);
$repository->receipt = hangRecoveryReceipt(0);
$pending = $queries->query(['originalIdempotencyKey' => hangRecoveryKey()], $operator, $dataScope);
hangRecoveryOk('pending receipt stays result unknown',
    ($pending['status'] ?? '') === CashierV3ResultCode::STATUS_RESULT_UNKNOWN
    && ($pending['phase'] ?? '') === 'pending'
);
$repository->receipt = hangRecoveryReceipt(1);
$repository->committed = hangRecoveryCommitted();
$success = $queries->query(['originalIdempotencyKey' => hangRecoveryKey()], $operator, $dataScope);
hangRecoveryOk('successful receipt requires matching hang header, frozen line and occupied room guard',
    ($success['status'] ?? '') === CashierV3ResultCode::STATUS_SUCCESS
    && ($success['phase'] ?? '') === 'succeeded'
    && ($success['hangOrder']['room']['occupied'] ?? false) === true
    && ($success['hangOrder']['hangStatus'] ?? '') === 'service_in_progress'
);
$repository->committed = hangRecoveryCommitted(false);
$mismatch = $queries->query(['originalIdempotencyKey' => hangRecoveryKey()], $operator, $dataScope);
hangRecoveryOk('wrong room guard owner never confirms a completed hang',
    ($mismatch['status'] ?? '') === CashierV3ResultCode::STATUS_RESULT_UNKNOWN
    && ($mismatch['phase'] ?? '') === 'reconciliation_required'
);

echo "\nhang-order-result-recovery-contract: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
