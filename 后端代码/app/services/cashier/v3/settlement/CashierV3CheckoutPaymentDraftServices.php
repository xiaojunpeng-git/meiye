<?php

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;

/** Eventless editor for the seven bookkeeping-payment draft methods. */
final class CashierV3CheckoutPaymentDraftServices
{
    public const CONTRACT_VERSION = 'cashier-v3-checkout-payment-draft-v1';

    private const ACTION_ADD = 'add-payment-method';
    private const ACTION_UPDATE = 'update-payment-line';
    private const ACTION_REMOVE = 'remove-payment-line';
    private const MAX_MONEY_CENTS = 100000000000;

    /** @var CashierV3CheckoutRequestRepository */
    private $requests;

    /** @var CashierV3CheckoutDraftAuthorityRebuilder */
    private $rebuilder;

    /** @var string */
    private $serverIdSecret;

    public function __construct(
        CashierV3CheckoutRequestRepository $requests = null,
        CashierV3CheckoutDraftAuthorityRebuilder $rebuilder = null,
        string $serverIdSecret = ''
    ) {
        $this->requests = $requests ?: new ThinkPhpCashierV3CheckoutRequestRepository();
        $this->rebuilder = $rebuilder ?: new CashierV3CheckoutDraftAuthorityRebuilder();
        $this->serverIdSecret = $serverIdSecret;
    }

    public function mutateInTx(string $action, array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('checkoutPaymentDraftMutation');
        try {
            if (!in_array($action, [self::ACTION_ADD, self::ACTION_UPDATE, self::ACTION_REMOVE], true)) {
                throw self::invalid('checkout_payment_draft_action_invalid', '本次收款明细操作无效。');
            }
            $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
            self::assertPayloadShape($action, $payload);
            $operatorScope = $scope['operator_scope'] ?? null;
            $dataScope = $scope['data_scope'] ?? null;
            if (!($operatorScope instanceof CashierV3OperatorScope)
                || !($dataScope instanceof CashierV3DataScopeContext)) {
                throw self::invalid(
                    'checkout_payment_scope_missing',
                    '当前收银账号或门店权限已失效，请重新登录后重试。'
                );
            }

            $requestId = trim((string)$payload['checkoutRequestId']);
            $requestVersion = (int)$payload['checkoutRequestVersion'];
            $stateContextId = trim((string)($scope['state_context_id'] ?? ''));
            $workspaceId = self::workspaceId($operatorScope, $stateContextId);
            $contexts = array_values((array)($scope['contexts'] ?? []));
            $workspaceVersion = self::contextVersion(
                $contexts,
                'cashier_workspace',
                $workspaceId
            );
            $requestContextVersion = self::contextVersion(
                $contexts,
                'checkout_request',
                $requestId
            );
            if ($requestVersion <= 0 || $requestContextVersion !== $requestVersion) {
                throw self::versionConflict('checkout_payment_request_context_version_mismatch');
            }

            $secret = $this->serverIdSecret();
            $kernelIdempotencyKey = self::kernelIdempotencyKey(
                (string)($scope['idempotency_key'] ?? '')
            );
            $aggregate = $this->requests->lockAggregateForEditInTx(
                $requestId,
                $requestVersion,
                $workspaceId,
                $stateContextId,
                $operatorScope,
                $dataScope
            );
            self::assertPreparationIdentity($payload, $aggregate['request']);

            $now = time();
            $snapshot = $this->rebuilder->rebuild(
                $aggregate,
                $workspaceVersion,
                $dataScope->permissionVersion(),
                $now
            );
            if ($action === self::ACTION_ADD) {
                self::addPayment(
                    $snapshot,
                    (string)$payload['paymentMethodId'],
                    $kernelIdempotencyKey,
                    $now
                );
            } elseif ($action === self::ACTION_UPDATE) {
                self::updatePayment($snapshot, $aggregate['payments'], $payload, $now);
            } else {
                self::removePayment($snapshot, $aggregate['payments'], (string)$payload['paymentLineId']);
            }
            self::assertSettlementNotOverReceivable($snapshot);
            $snapshot['authoritySnapshotFingerprint'] =
                CashierV3CheckoutSettlementKernel::authorityFingerprint($snapshot);

            $kernel = CashierV3CheckoutSettlementKernel::saveDraft([
                'contractVersion' => CashierV3CheckoutSettlementKernel::CONTRACT_VERSION,
                'operation' => CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT,
                'idempotencyKey' => $kernelIdempotencyKey,
                'workspaceId' => $workspaceId,
                'stateContextId' => $stateContextId,
                'permissionSnapshotFingerprint' => $dataScope->permissionVersion(),
                'requestId' => $requestId,
                'expectedVersion' => $requestVersion,
            ], $snapshot, $aggregate['currentRequest'], $secret);
            $persisted = $this->requests->persistKernelPlanInTx(
                $kernel,
                $aggregate['verifiedSources'],
                $operatorScope,
                $dataScope
            );

            return [
                'contractVersion' => self::CONTRACT_VERSION,
                'checkoutRequestId' => (string)$kernel['requestId'],
                'checkoutRequestVersion' => (int)$kernel['requestVersion'],
                'requestId' => (string)$kernel['requestId'],
                'version' => (int)$kernel['requestVersion'],
                'totals' => (array)$kernel['totals'],
                'replayed' => !empty($kernel['replayed']) || !empty($persisted['replayed']),
                'message' => '收款明细已更新。',
            ];
        } catch (CashierV3CheckoutSettlementContractException $exception) {
            throw self::translateContractFailure($exception);
        }
    }

    private static function addPayment(
        array &$snapshot,
        string $method,
        string $idempotencyKey,
        int $now
    ): void
    {
        if ($method === CashierV3CheckoutSettlementKernel::PAYMENT_OLD_CARD_ENTRY) {
            throw self::invalid(
                'old_card_entry_separate_flow_required',
                '旧卡录入需单独办理，不能与本次收款组合。'
            );
        }
        if (!in_array($method, CashierV3CheckoutSettlementKernel::paymentMethods(), true)) {
            throw self::invalid('payment_method_invalid', '请选择有效的记账收款方式。');
        }
        $snapshot['paymentDetails'][] = [
            // The command idempotency key makes one click one stable payment
            // draft identity, while still allowing the same method repeatedly.
            'paymentAuthorityKey' => 'payment:' . $method . ':' . $idempotencyKey,
            'method' => $method,
            // A click always creates one independent draft line. When the
            // current draft is already fully allocated, start that new line
            // at zero so the cashier can split the existing collection
            // amounts without being blocked by a premature "no remaining"
            // rule. Final checkout still requires every retained line to be
            // a positive whole-yuan amount and the overall total to balance.
            'amountCents' => self::nextPaymentInitialAmount($snapshot),
            'businessTime' => $now,
            'externalTransactionNo' => '',
            'remark' => '',
        ];
    }

    private static function updatePayment(
        array &$snapshot,
        array $storedPayments,
        array $payload,
        int $now
    ): void {
        $target = self::storedPayment($storedPayments, (string)$payload['paymentLineId']);
        $authorityKey = (string)($target['payment_authority_key'] ?? '');
        $found = false;
        foreach ($snapshot['paymentDetails'] as &$payment) {
            if (!hash_equals((string)$payment['paymentAuthorityKey'], $authorityKey)) {
                continue;
            }
            $payment['amountCents'] = self::moneyToCents($payload['amount']);
            $payment['businessTime'] = $now;
            $payment['externalTransactionNo'] = trim((string)$payload['externalTransactionNo']);
            $payment['remark'] = trim((string)$payload['remark']);
            $found = true;
            break;
        }
        unset($payment);
        if (!$found) {
            throw self::invalid('payment_line_authority_missing', '该收款明细已变化，请刷新后重试。');
        }
    }

    private static function removePayment(
        array &$snapshot,
        array $storedPayments,
        string $paymentLineId
    ): void {
        $target = self::storedPayment($storedPayments, $paymentLineId);
        $authorityKey = (string)($target['payment_authority_key'] ?? '');
        foreach ($snapshot['paymentDetails'] as $index => $payment) {
            if (hash_equals((string)$payment['paymentAuthorityKey'], $authorityKey)) {
                array_splice($snapshot['paymentDetails'], $index, 1);
                return;
            }
        }
        throw self::invalid('payment_line_authority_missing', '该收款明细已变化，请刷新后重试。');
    }

    private static function storedPayment(array $rows, string $paymentLineId): array
    {
        foreach ($rows as $row) {
            if (is_array($row)
                && hash_equals((string)($row['payment_draft_id'] ?? ''), $paymentLineId)) {
                return $row;
            }
        }
        throw self::invalid('payment_line_not_found', '该收款明细已变化，请刷新后重试。');
    }

    private static function assertSettlementNotOverReceivable(array $snapshot): void
    {
        $receivable = self::receivableAmount($snapshot);
        $settlement = self::settlementAmount($snapshot);
        if ($settlement > $receivable) {
            throw self::invalid('checkout_payment_exceeds_receivable', '收款、余额和欠款合计不能超过应收金额。');
        }
    }

    private static function nextPaymentInitialAmount(array $snapshot): int
    {
        $receivable = self::receivableAmount($snapshot);
        $settlement = self::settlementAmount($snapshot);
        return $settlement >= $receivable ? 0 : $receivable - $settlement;
    }

    private static function receivableAmount(array $snapshot): int
    {
        $total = 0;
        foreach ((array)$snapshot['saleLines'] as $line) {
            $total = self::safeAdd($total, (int)$line['saleAmountCents']);
        }
        return $total;
    }

    private static function settlementAmount(array $snapshot): int
    {
        $total = 0;
        foreach ((array)$snapshot['paymentDetails'] as $payment) {
            $total = self::safeAdd($total, (int)$payment['amountCents']);
        }
        $total = self::safeAdd($total, (int)$snapshot['balanceDeduction']['amountCents']);
        return self::safeAdd($total, (int)$snapshot['debt']['amountCents']);
    }

    private static function safeAdd(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || $left > self::MAX_MONEY_CENTS - $right) {
            throw self::invalid('checkout_payment_amount_overflow', '本次收款金额超出系统允许范围。');
        }
        return $left + $right;
    }

    private static function moneyToCents($amount): int
    {
        if (!is_string($amount)
            || preg_match('/^(?:0|[1-9][0-9]*)$/D', $amount) !== 1) {
            throw self::invalid('payment_amount_invalid', '收款金额必须为整数元。');
        }
        $raw = ltrim($amount . '00', '0');
        $raw = $raw === '' ? '0' : $raw;
        $limit = (string)self::MAX_MONEY_CENTS;
        if (strlen($raw) > strlen($limit)
            || (strlen($raw) === strlen($limit) && strcmp($raw, $limit) > 0)) {
            throw self::invalid('payment_amount_invalid', '收款金额必须为整数元且不能超出系统范围。');
        }
        return (int)$raw;
    }

    private static function assertPreparationIdentity(array $payload, array $request): void
    {
        $creationKey = (string)($request['creation_idempotency_key'] ?? '');
        if (!hash_equals($creationKey, (string)$payload['preparationRequestId'])) {
            throw self::invalid(
                'checkout_preparation_request_mismatch',
                '结账页面已变化，请关闭后重新进入。'
            );
        }
        $requestId = (string)($request['request_id'] ?? '');
        $version = (int)($request['request_version'] ?? 0);
        $aggregateFingerprint = (string)($request['aggregate_fingerprint'] ?? '');
        $operationFingerprint = (string)($request['last_operation_fingerprint'] ?? '');
        if (preg_match('/^[0-9a-f]{64}$/D', $aggregateFingerprint) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', $operationFingerprint) !== 1) {
            throw self::invalid('checkout_preparation_fingerprint_invalid', '结账页面校验失败，请重新进入。');
        }
        $expectedToken = 'CKPT-' . hash('sha256', implode('|', [
            CashierV3CheckoutProjectionServices::CONTRACT_VERSION,
            $requestId,
            (string)$version,
            $aggregateFingerprint,
            $operationFingerprint,
        ]));
        if (!hash_equals($expectedToken, (string)$payload['preparationToken'])) {
            throw self::versionConflict('checkout_preparation_token_stale');
        }
    }

    private static function assertPayloadShape(string $action, array $payload): void
    {
        $common = [
            'checkoutRequestId',
            'checkoutRequestVersion',
            'preparationRequestId',
            'preparationToken',
        ];
        $specific = [
            self::ACTION_ADD => ['paymentMethodId'],
            self::ACTION_UPDATE => [
                'paymentLineId',
                'amount',
                'externalTransactionNo',
                'remark',
            ],
            self::ACTION_REMOVE => ['paymentLineId'],
        ][$action];
        $expected = array_merge($common, $specific);
        $actual = array_keys($payload);
        sort($expected, SORT_STRING);
        sort($actual, SORT_STRING);
        if ($expected !== $actual) {
            throw self::invalid('checkout_payment_payload_shape_invalid', '本次收款明细格式无效。');
        }
    }

    private static function contextVersion(array $contexts, string $kind, string $id): int
    {
        $found = null;
        foreach ($contexts as $context) {
            if (!is_array($context)
                || (string)($context['kind'] ?? '') !== $kind
                || (string)($context['id'] ?? '') !== $id) {
                continue;
            }
            $version = (int)($context['expected_version'] ?? $context['expectedVersion'] ?? 0);
            if ($version <= 0 || $found !== null) {
                throw self::invalid('checkout_payment_context_invalid', '结账对象版本无效，请刷新后重试。');
            }
            $found = $version;
        }
        if ($found === null) {
            throw self::invalid('checkout_payment_context_missing', '结账对象版本缺失，请刷新后重试。');
        }
        return $found;
    }

    private static function workspaceId(
        CashierV3OperatorScope $operatorScope,
        string $stateContextId
    ): string {
        if ($stateContextId === '') {
            throw self::invalid('checkout_state_context_missing', '当前收银工作台已失效，请刷新后重试。');
        }
        return sprintf(
            'ws:%d:%d:%s',
            $operatorScope->storeId(),
            $operatorScope->operatorId(),
            $stateContextId
        );
    }

    private static function kernelIdempotencyKey(string $key): string
    {
        $matches = [];
        $uuid = '[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';
        if (preg_match('/^[A-Z][A-Z0-9_]{0,31}-(' . $uuid . ')$/D', trim($key), $matches) !== 1) {
            throw self::invalid('checkout_payment_idempotency_key_invalid', '本次操作请求标识无效，请重试。');
        }
        return 'CHECKOUT-' . $matches[1];
    }

    private function serverIdSecret(): string
    {
        $secret = $this->serverIdSecret;
        if ($secret === '' && function_exists('config')) {
            $secret = trim((string)config('cashier_v3.checkout_namespace_secret'));
        }
        if (strlen($secret) < 32) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '结账请求签名服务尚未配置，请联系管理员。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'checkout_namespace_secret_missing']
            );
        }
        return $secret;
    }

    private static function translateContractFailure(
        CashierV3CheckoutSettlementContractException $exception
    ): CashierV3CommandException {
        if ($exception->reason() === 'checkout_request_version_conflict') {
            return CashierV3CommandException::versionConflict(
                '结账信息已被其他操作更新，请刷新后重试。',
                ['reason' => $exception->reason(), 'contractDetail' => $exception->detail()]
            );
        }
        if ($exception->reason() === 'checkout_idempotency_key_conflict') {
            return new CashierV3CommandException(
                CashierV3ResultCode::IDEMPOTENCY_KEY_CONFLICT,
                '本次操作请求标识已用于其他内容，请重新操作。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => $exception->reason(), 'contractDetail' => $exception->detail()]
            );
        }
        return CashierV3CommandException::invalidContext(
            '结账草稿已变化或资料不完整，请刷新结账页面后重试。',
            ['reason' => $exception->reason(), 'contractDetail' => $exception->detail()]
        );
    }

    private static function versionConflict(string $reason): CashierV3CommandException
    {
        return CashierV3CommandException::versionConflict(
            '结账信息已被其他操作更新，请刷新后重试。',
            ['reason' => $reason]
        );
    }

    private static function invalid(string $reason, string $message): CashierV3CommandException
    {
        return CashierV3CommandException::invalidContext($message, ['reason' => $reason]);
    }
}
