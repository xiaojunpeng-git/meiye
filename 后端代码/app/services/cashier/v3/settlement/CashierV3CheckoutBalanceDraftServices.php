<?php

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceContractException;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceProvider;

/**
 * Edits a checkout's balance-payment intention without moving money.
 *
 * The actual debit is deliberately deferred to submit-checkout, after its
 * immutable resource plan has locked the same member balance in the final
 * transaction. This makes apply/remove safe to retry and prevents a draft
 * from becoming an unpaired ledger mutation.
 */
final class CashierV3CheckoutBalanceDraftServices
{
    public const CONTRACT_VERSION = 'cashier-v3-checkout-balance-draft-v1';

    private const ACTION_APPLY = 'apply-balance-payment';
    private const ACTION_REMOVE = 'remove-balance-payment';
    private const ACTION_UPDATE = 'update-balance-payment';
    private const ACTION_RETURN_TO_PAYMENT_EDIT = 'return-to-payment-edit';

    /** @var CashierV3CheckoutRequestRepository */
    private $requests;

    /** @var CashierV3CheckoutDraftAuthorityRebuilder */
    private $rebuilder;

    /** @var CashierV3MemberBalanceProvider */
    private $balances;

    /** @var string */
    private $serverIdSecret;

    public function __construct(
        ?CashierV3CheckoutRequestRepository $requests = null,
        ?CashierV3CheckoutDraftAuthorityRebuilder $rebuilder = null,
        ?CashierV3MemberBalanceProvider $balances = null,
        string $serverIdSecret = ''
    ) {
        $this->requests = $requests ?: new ThinkPhpCashierV3CheckoutRequestRepository();
        $this->rebuilder = $rebuilder ?: new CashierV3CheckoutDraftAuthorityRebuilder();
        $this->balances = $balances ?: new CashierV3MemberBalanceProvider();
        $this->serverIdSecret = $serverIdSecret;
    }

    public function mutateInTx(string $action, array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('checkoutBalanceDraftMutation');
        try {
            if (!in_array($action, [self::ACTION_APPLY, self::ACTION_REMOVE, self::ACTION_UPDATE], true)) {
                throw self::invalid('checkout_balance_draft_action_invalid', '余额支付操作无效。');
            }
            $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
            self::assertPayload($action, $payload);
            $operator = $scope['operator_scope'] ?? null;
            $dataScope = $scope['data_scope'] ?? null;
            if (!($operator instanceof CashierV3OperatorScope)
                || !($dataScope instanceof CashierV3DataScopeContext)) {
                throw self::invalid('checkout_balance_draft_scope_missing', '当前收银账号或门店权限已失效。');
            }

            $requestId = (string)$payload['checkoutRequestId'];
            $requestVersion = (int)$payload['checkoutRequestVersion'];
            $stateContextId = self::stateContextId($scope['state_context_id'] ?? null);
            $workspaceId = self::workspaceId($operator, $stateContextId);
            $contexts = array_values((array)($scope['contexts'] ?? []));
            $workspaceVersion = self::contextVersion($contexts, 'cashier_workspace', $workspaceId);
            $requestContextVersion = self::contextVersion($contexts, 'checkout_request', $requestId);
            if ($requestContextVersion !== $requestVersion) {
                throw self::versionConflict('checkout_balance_draft_request_context_version_mismatch');
            }

            $aggregate = $this->requests->lockAggregateForEditInTx(
                $requestId,
                $requestVersion,
                $workspaceId,
                $stateContextId,
                $operator,
                $dataScope
            );
            self::assertPreparationIdentity($payload, (array)$aggregate['request']);
            $now = time();
            $snapshot = $this->rebuilder->rebuild(
                $aggregate,
                $workspaceVersion,
                $dataScope->permissionVersion(),
                $now
            );
            if ($action === self::ACTION_REMOVE) {
                $snapshot['balanceDeduction'] = self::emptyBalance();
            } else {
                self::applyBalance(
                    $snapshot,
                    $operator,
                    $dataScope,
                    $this->balances,
                    $action === self::ACTION_UPDATE ? (string)$payload['amount'] : null
                );
            }
            self::assertSettlementNotOverReceivable($snapshot);
            $snapshot['authoritySnapshotFingerprint'] =
                CashierV3CheckoutSettlementKernel::authorityFingerprint($snapshot);
            $kernel = CashierV3CheckoutSettlementKernel::saveDraft([
                'contractVersion' => CashierV3CheckoutSettlementKernel::CONTRACT_VERSION,
                'operation' => CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT,
                'idempotencyKey' => self::kernelIdempotencyKey($scope['idempotency_key'] ?? null),
                'workspaceId' => $workspaceId,
                'stateContextId' => $stateContextId,
                'permissionSnapshotFingerprint' => $dataScope->permissionVersion(),
                'requestId' => $requestId,
                'expectedVersion' => $requestVersion,
            ], $snapshot, (array)$aggregate['currentRequest'], $this->serverIdSecret());
            $persisted = $this->requests->persistKernelPlanInTx(
                $kernel,
                $aggregate['verifiedSources'],
                $operator,
                $dataScope
            );
            $balance = (array)$snapshot['balanceDeduction'];
            return [
                'contractVersion' => self::CONTRACT_VERSION,
                'checkoutRequestId' => (string)$kernel['requestId'],
                'checkoutRequestVersion' => (int)$kernel['requestVersion'],
                'requestId' => (string)$kernel['requestId'],
                'version' => (int)$kernel['requestVersion'],
                'balanceDeduction' => [
                    'amountCents' => (int)$balance['amountCents'],
                    'accountId' => (string)$balance['accountId'],
                    'accountVersion' => (int)$balance['accountVersion'],
                ],
                'totals' => (array)$kernel['totals'],
                'replayed' => !empty($kernel['replayed']) || !empty($persisted['replayed']),
                'message' => $action === self::ACTION_APPLY
                    ? '已使用会员余额支付本单待收金额。'
                    : ($action === self::ACTION_UPDATE ? '已更新本单余额支付金额。' : '已取消本单余额支付。'),
            ];
        } catch (CashierV3CheckoutSettlementContractException $exception) {
            throw self::translateContractFailure($exception);
        } catch (CashierV3MemberBalanceContractException $exception) {
            throw self::invalid($exception->reason(), '会员余额暂不可用，请刷新后重试。');
        }
    }

    /**
     * Reopens a prepared checkout after its final balance-version check lost
     * the race. This intentionally restores only the editable draft: it
     * never retries final checkout and never writes any business facts.
     */
    public function returnToPaymentEditInTx(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('checkoutBalanceConflictRecovery');
        try {
            $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
            self::assertPayload(self::ACTION_RETURN_TO_PAYMENT_EDIT, $payload);
            $operator = $scope['operator_scope'] ?? null;
            $dataScope = $scope['data_scope'] ?? null;
            if (!($operator instanceof CashierV3OperatorScope)
                || !($dataScope instanceof CashierV3DataScopeContext)) {
                throw self::invalid('checkout_balance_recovery_scope_missing', '当前收银账号或门店权限已失效。');
            }

            $requestId = (string)$payload['checkoutRequestId'];
            $requestVersion = (int)$payload['checkoutRequestVersion'];
            $stateContextId = self::stateContextId($scope['state_context_id'] ?? null);
            $workspaceId = self::workspaceId($operator, $stateContextId);
            $contexts = array_values((array)($scope['contexts'] ?? []));
            $workspaceVersion = self::contextVersion($contexts, 'cashier_workspace', $workspaceId);
            $requestContextVersion = self::contextVersion($contexts, 'checkout_request', $requestId);
            if ($requestContextVersion !== $requestVersion) {
                throw self::versionConflict('checkout_balance_recovery_request_context_version_mismatch');
            }

            $aggregate = $this->requests->lockAggregateForSubmitInTx(
                $requestId,
                $requestVersion,
                $workspaceId,
                $stateContextId,
                $operator,
                $dataScope
            );
            self::assertPreparationIdentity($payload, (array)$aggregate['request']);
            $snapshot = $this->rebuilder->rebuild(
                $aggregate,
                $workspaceVersion,
                $dataScope->permissionVersion(),
                time()
            );
            $recovery = self::refreshPlannedBalanceAfterConflict(
                $snapshot,
                $operator,
                $dataScope,
                $this->balances
            );
            $snapshot['authoritySnapshotFingerprint'] =
                CashierV3CheckoutSettlementKernel::authorityFingerprint($snapshot);
            $kernel = CashierV3CheckoutSettlementKernel::saveDraft([
                'contractVersion' => CashierV3CheckoutSettlementKernel::CONTRACT_VERSION,
                'operation' => CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT,
                'idempotencyKey' => self::kernelIdempotencyKey($scope['idempotency_key'] ?? null),
                'workspaceId' => $workspaceId,
                'stateContextId' => $stateContextId,
                'permissionSnapshotFingerprint' => $dataScope->permissionVersion(),
                'requestId' => $requestId,
                'expectedVersion' => $requestVersion,
            ], $snapshot, (array)$aggregate['currentRequest'], $this->serverIdSecret());
            $persisted = $this->requests->persistKernelPlanInTx(
                $kernel,
                $aggregate['verifiedSources'],
                $operator,
                $dataScope
            );

            return [
                'contractVersion' => self::CONTRACT_VERSION,
                'checkoutRequestId' => (string)$kernel['requestId'],
                'checkoutRequestVersion' => (int)$kernel['requestVersion'],
                'requestId' => (string)$kernel['requestId'],
                'version' => (int)$kernel['requestVersion'],
                'requestStatus' => (string)$kernel['requestStatus'],
                'balanceDeduction' => [
                    'amountCents' => (int)$snapshot['balanceDeduction']['amountCents'],
                    'accountId' => (string)$snapshot['balanceDeduction']['accountId'],
                    'accountVersion' => (int)$snapshot['balanceDeduction']['accountVersion'],
                ],
                'balanceRecovered' => $recovery['balanceRecovered'],
                'balanceAdjusted' => $recovery['balanceAdjusted'],
                'paymentDetailsPreserved' => true,
                'eventless' => true,
                'replayed' => !empty($kernel['replayed']) || !empty($persisted['replayed']),
                'message' => $recovery['balanceAdjusted']
                    ? '会员余额已按最新可用金额重新读取，请补齐剩余收款后再确认结账。'
                    : '会员余额已重新读取，请确认收款后再结账。',
            ];
        } catch (CashierV3CheckoutSettlementContractException $exception) {
            throw self::translateContractFailure($exception);
        } catch (CashierV3MemberBalanceContractException $exception) {
            throw self::invalid($exception->reason(), '会员余额暂不可用，请刷新后重试。');
        }
    }

    private static function applyBalance(
        array &$snapshot,
        CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $dataScope,
        CashierV3MemberBalanceProvider $balances,
        ?string $requestedAmountYuan = null
    ): void {
        self::assertBalanceEligible($snapshot);
        $memberId = (int)($snapshot['memberId'] ?? 0);
        if ($memberId <= 0) {
            throw self::invalid('checkout_balance_member_required', '请选择会员后才能使用余额支付。');
        }
        $remaining = self::receivableAmount($snapshot)
            - self::paymentAndDebtAmount($snapshot);
        if ($remaining <= 0) {
            throw self::invalid('checkout_balance_no_remaining_receivable', '本单已没有待收金额。');
        }
        $account = $balances->lockSnapshotInTx($memberId, $operator, $dataScope);
        $availableWholeCents = intdiv(max(0, (int)$account['totalCents']), 100) * 100;
        if ($availableWholeCents <= 0) {
            throw self::invalid('checkout_balance_insufficient', '会员可用余额不足。');
        }
        $requestedCents = $requestedAmountYuan === null
            ? min($remaining, $availableWholeCents)
            : self::wholeYuanToCents($requestedAmountYuan);
        if ($requestedCents > $availableWholeCents) {
            throw self::invalid('checkout_balance_exceeds_available', '余额支付金额不能超过会员可用余额。');
        }
        if ($requestedCents > $remaining) {
            throw self::invalid('checkout_balance_exceeds_remaining_receivable', '余额支付金额不能超过本单剩余待收金额。');
        }
        $snapshot['balanceDeduction'] = [
            'authorityKey' => (string)$account['authorityKey'],
            'accountId' => (string)$account['accountId'],
            'accountVersion' => (int)$account['accountVersion'],
            // Historic balances may contain cents. New deductions must remain
            // whole RMB and must never alter or round the historic remainder.
            'amountCents' => $requestedCents,
        ];
    }

    /**
     * @return array{balanceRecovered:bool,balanceAdjusted:bool}
     */
    private static function refreshPlannedBalanceAfterConflict(
        array &$snapshot,
        CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $dataScope,
        CashierV3MemberBalanceProvider $balances
    ): array {
        $plannedAmount = (int)($snapshot['balanceDeduction']['amountCents'] ?? 0);
        if ($plannedAmount <= 0) {
            return ['balanceRecovered' => false, 'balanceAdjusted' => false];
        }
        self::assertBalanceEligible($snapshot);
        $memberId = (int)($snapshot['memberId'] ?? 0);
        if ($memberId <= 0) {
            throw self::invalid('checkout_balance_member_required', '请选择会员后才能使用余额支付。');
        }
        $account = $balances->lockSnapshotInTx($memberId, $operator, $dataScope);
        $availableWholeCents = intdiv(max(0, (int)$account['totalCents']), 100) * 100;
        $remainingAfterExternal = max(
            0,
            self::receivableAmount($snapshot) - self::paymentAndDebtAmount($snapshot)
        );
        $restoredAmount = min($plannedAmount, $availableWholeCents, $remainingAfterExternal);
        $snapshot['balanceDeduction'] = $restoredAmount > 0 ? [
            'authorityKey' => (string)$account['authorityKey'],
            'accountId' => (string)$account['accountId'],
            'accountVersion' => (int)$account['accountVersion'],
            'amountCents' => $restoredAmount,
        ] : self::emptyBalance();

        return [
            'balanceRecovered' => true,
            'balanceAdjusted' => $restoredAmount !== $plannedAmount,
        ];
    }

    private static function assertBalanceEligible(array $snapshot): void
    {
        if ((array)($snapshot['saleLines'] ?? []) === []) {
            throw self::invalid(
                'checkout_balance_composition_not_supported',
                '当前结账内容暂不支持余额支付。'
            );
        }
        foreach ((array)$snapshot['saleLines'] as $line) {
            // 余额只是本单销售应收的结算方式。权益服务行不产生新的应收，
            // 因而不会进入余额金额计算；项目销售行与商品、卡项使用同一来源快照。
            if (!in_array((string)($line['sourceType'] ?? ''), ['product', 'card', 'project'], true)) {
                throw self::invalid(
                    'checkout_balance_sale_source_not_supported',
                    '当前结账内容暂不支持余额支付。'
                );
            }
        }
    }

    private static function emptyBalance(): array
    {
        return [
            'authorityKey' => '',
            'accountId' => '',
            'accountVersion' => 0,
            'amountCents' => 0,
        ];
    }

    private static function receivableAmount(array $snapshot): int
    {
        $total = 0;
        foreach ((array)($snapshot['saleLines'] ?? []) as $line) {
            $total = self::safeAdd($total, (int)($line['saleAmountCents'] ?? 0));
        }
        return $total;
    }

    private static function paymentAndDebtAmount(array $snapshot): int
    {
        $total = (int)(($snapshot['debt']['amountCents'] ?? 0));
        foreach ((array)($snapshot['paymentDetails'] ?? []) as $payment) {
            $total = self::safeAdd($total, (int)($payment['amountCents'] ?? 0));
        }
        return $total;
    }

    private static function assertSettlementNotOverReceivable(array $snapshot): void
    {
        $settlement = self::paymentAndDebtAmount($snapshot);
        $settlement = self::safeAdd($settlement, (int)($snapshot['balanceDeduction']['amountCents'] ?? 0));
        if ($settlement > self::receivableAmount($snapshot)) {
            throw self::invalid('checkout_balance_exceeds_receivable', '收款、余额和欠款合计不能超过应收金额。');
        }
    }

    private function serverIdSecret(): string
    {
        if (strlen($this->serverIdSecret) < 32) {
            $secret = function_exists('config')
                ? trim((string)config('cashier_v3.checkout_namespace_secret'))
                : '';
            if (strlen($secret) >= 32) {
                return $secret;
            }
            throw self::invalid('checkout_balance_server_secret_missing', '结账服务尚未完成配置。');
        }
        return $this->serverIdSecret;
    }

    private static function assertPayload(string $action, array $payload): void
    {
        $expected = [
            'checkoutRequestId', 'checkoutRequestVersion',
            'preparationRequestId', 'preparationToken',
        ];
        if ($action === self::ACTION_UPDATE) $expected[] = 'amount';
        $actual = array_keys($payload);
        sort($expected, SORT_STRING);
        sort($actual, SORT_STRING);
        if ($expected !== $actual
            || !is_string($payload['checkoutRequestId'])
            || preg_match('/^CKR-[0-9a-f]{40}$/D', $payload['checkoutRequestId']) !== 1
            || !is_int($payload['checkoutRequestVersion'])
            || $payload['checkoutRequestVersion'] <= 0
            || !is_string($payload['preparationRequestId'])
            || !is_string($payload['preparationToken'])
            || ($action === self::ACTION_UPDATE
                && (!is_string($payload['amount']) || preg_match('/^[1-9][0-9]*$/D', $payload['amount']) !== 1))) {
            throw self::invalid('checkout_balance_payload_invalid', '本次余额支付资料无效。');
        }
    }

    private static function wholeYuanToCents(string $amount): int
    {
        $raw = ltrim($amount . '00', '0');
        $raw = $raw === '' ? '0' : $raw;
        if (strlen($raw) > strlen('100000000000')
            || (strlen($raw) === strlen('100000000000') && strcmp($raw, '100000000000') > 0)) {
            throw self::invalid('checkout_balance_amount_invalid', '余额支付金额超出系统允许范围。');
        }
        return (int)$raw;
    }

    private static function assertPreparationIdentity(array $payload, array $request): void
    {
        $requestId = (string)($request['request_id'] ?? '');
        $version = (int)($request['request_version'] ?? 0);
        $aggregate = (string)($request['aggregate_fingerprint'] ?? '');
        $operation = (string)($request['last_operation_fingerprint'] ?? '');
        if ($requestId === '' || $version <= 0
            || !hash_equals((string)($request['creation_idempotency_key'] ?? ''), (string)$payload['preparationRequestId'])
            || preg_match('/^[0-9a-f]{64}$/D', $aggregate) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', $operation) !== 1) {
            throw self::invalid('checkout_balance_preparation_identity_invalid', '结账页面已变化，请关闭后重新进入。');
        }
        $token = 'CKPT-' . hash('sha256', implode('|', [
            CashierV3CheckoutProjectionServices::CONTRACT_VERSION,
            $requestId,
            (string)$version,
            $aggregate,
            $operation,
        ]));
        if (!hash_equals($token, (string)$payload['preparationToken'])) {
            throw self::versionConflict('checkout_balance_preparation_token_stale');
        }
    }

    private static function contextVersion(array $contexts, string $kind, string $id): int
    {
        foreach ($contexts as $context) {
            $expectedVersion = is_array($context)
                ? ($context['expected_version'] ?? ($context['expectedVersion'] ?? null))
                : null;
            if (is_array($context)
                && (string)($context['kind'] ?? '') === $kind
                && hash_equals((string)($context['id'] ?? ''), $id)
                && is_int($expectedVersion)
                && $expectedVersion > 0) {
                return $expectedVersion;
            }
        }
        throw self::versionConflict('checkout_balance_required_context_missing');
    }

    private static function workspaceId(CashierV3OperatorScope $operator, string $stateContextId): string
    {
        return sprintf('ws:%d:%d:%s', $operator->storeId(), $operator->operatorId(), $stateContextId);
    }

    private static function stateContextId($value): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $value) !== 1) {
            throw self::invalid('checkout_balance_state_context_invalid', '当前工作台已变化，请刷新后重试。');
        }
        return $value;
    }

    private static function kernelIdempotencyKey($value): string
    {
        $uuid = '[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';
        if (!is_string($value)
            || preg_match('/^[A-Z][A-Z0-9_]{0,31}-(' . $uuid . ')$/D', trim($value), $matches) !== 1) {
            throw self::invalid('checkout_balance_idempotency_key_invalid', '本次余额支付请求无效。');
        }
        return 'CHECKOUT-' . $matches[1];
    }

    private static function safeAdd(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || $left > 100000000000 - $right) {
            throw self::invalid('checkout_balance_amount_invalid', '本次结账金额无效。');
        }
        return $left + $right;
    }

    private static function versionConflict(string $reason): CashierV3CommandException
    {
        return CashierV3CommandException::versionConflict(
            '结账资料已变化，请刷新后重试。',
            ['reason' => $reason]
        );
    }

    private static function invalid(string $reason, string $message): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::INVALID_COMMAND_CONTEXT,
            $message,
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason]
        );
    }

    private static function translateContractFailure(
        CashierV3CheckoutSettlementContractException $exception
    ): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            '结账资料保存失败，请刷新后重试。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $exception->reason()] + $exception->detail()
        );
    }
}
