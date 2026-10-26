<?php

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3BusinessDocumentNumberServices;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3IdempotencyKeyServices;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;

/** Read-only recovery projection for a possibly unknown submit-checkout result. */
final class CashierV3CheckoutResultQueryServices
{
    public const CONTRACT_VERSION = 'cashier-v3-checkout-result-v1';
    private const ORIGINAL_ACTION = 'submit-checkout';
    private const RECEIPT_PENDING = 0;
    private const RECEIPT_SUCCEEDED = 1;

    /** @var CashierV3CheckoutResultReadRepository */
    private $repository;

    /** @var CashierV3IdempotencyKeyServices */
    private $idempotencyKeys;

    public function __construct(
        CashierV3CheckoutResultReadRepository $repository,
        CashierV3IdempotencyKeyServices $idempotencyKeys = null
    ) {
        $this->repository = $repository;
        $this->idempotencyKeys = $idempotencyKeys ?: new CashierV3IdempotencyKeyServices();
    }

    public function query(
        array $payload,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $this->assertCurrentScope($operatorScope, $dataScope);
        $idempotencyKey = $this->idempotencyKeys->normalizeIdempotencyKey(
            (string)($payload['originalIdempotencyKey'] ?? '')
        );
        if (strpos($idempotencyKey, 'CHECKOUT-') !== 0) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::INVALID_IDEMPOTENCY_KEY,
                '原请求标识不是结账提交请求，请返回收银台重新确认。',
                CashierV3ResultCode::STATUS_FAILED
            );
        }

        $receipt = $this->repository->findReceiptForActor(
            $idempotencyKey,
            $operatorScope->storeId()
        );
        if ($receipt === null) {
            return $this->unknown(
                $idempotencyKey,
                'not_found',
                '暂未查到该结账请求的确定结果，请稍后继续查询。'
            );
        }
        $this->assertOwnedReceipt($receipt, $idempotencyKey, $operatorScope);

        $receiptStatus = (int)($receipt['status'] ?? -1);
        if ($receiptStatus === self::RECEIPT_PENDING) {
            return $this->unknown(
                $idempotencyKey,
                'pending',
                '结账仍在处理中，请勿更换请求标识或重复收款。'
            );
        }
        if ($receiptStatus !== self::RECEIPT_SUCCEEDED) {
            return $this->failed($receipt, $idempotencyKey);
        }
        if (trim((string)($receipt['result_code'] ?? '')) !== '') {
            return $this->unknown(
                $idempotencyKey,
                'reconciliation_required',
                '结账回执状态不一致，暂不能确认成交结果，请联系管理员核对。'
            );
        }

        $committed = $this->repository->findCommittedCheckoutResult(
            $receipt,
            $operatorScope,
            $dataScope
        );
        if (!$this->validCommittedResult($committed)) {
            return $this->unknown(
                $idempotencyKey,
                'reconciliation_required',
                '结账回执已完成，但正式业务结果暂时无法核验，请联系管理员核对。'
            );
        }

        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'originalIdempotencyKey' => $idempotencyKey,
            'status' => CashierV3ResultCode::STATUS_SUCCESS,
            'phase' => CashierV3CheckoutSettlementStateMachine::SUCCEEDED,
            'code' => '',
            'message' => '结账已完成。',
            'composition' => (string)$committed['composition'],
            'checkoutRequest' => [
                'requestId' => (string)$committed['checkoutRequest']['requestId'],
                'requestVersion' => (int)$committed['checkoutRequest']['requestVersion'],
                'requestStatus' => (string)$committed['checkoutRequest']['requestStatus'],
            ],
            'salesOrder' => is_array($committed['salesOrder'] ?? null) ? [
                'orderId' => (string)$committed['salesOrder']['orderId'],
                'orderNo' => (string)$committed['salesOrder']['orderNo'],
                'orderStatus' => (string)$committed['salesOrder']['orderStatus'],
                'orderVersion' => (int)$committed['salesOrder']['orderVersion'],
            ] : null,
            'entitlementCompletion' => is_array($committed['entitlementCompletion'] ?? null) ? [
                'receiptId' => (string)$committed['entitlementCompletion']['receiptId'],
                'receiptStatus' => (string)$committed['entitlementCompletion']['receiptStatus'],
                'planFingerprint' => (string)$committed['entitlementCompletion']['planFingerprint'],
            ] : null,
        ];
    }

    private function assertCurrentScope(
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): void {
        $mode = $dataScope->authorizationMode();
        $knownMode = in_array($mode, [
            CashierV3DataScopeContext::MODE_ALL,
            CashierV3DataScopeContext::MODE_STORES,
            CashierV3DataScopeContext::MODE_SELF_PARTICIPANT,
            CashierV3DataScopeContext::MODE_NONE,
        ], true);
        $storeAllowed = $mode === CashierV3DataScopeContext::MODE_SELF_PARTICIPANT
            || $dataScope->allowsStore($operatorScope->storeId());
        if (!$knownMode
            || $mode === CashierV3DataScopeContext::MODE_NONE
            || !$storeAllowed
            || $operatorScope->storeId() !== $dataScope->forcedStoreId()
            || $operatorScope->tenantId() === ''
            || !hash_equals($operatorScope->tenantId(), $dataScope->tenantId())
            || !hash_equals($operatorScope->organizationId(), $dataScope->organizationId())) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::PERMISSION_DENIED,
                '当前账号无权查看该结账结果。',
                CashierV3ResultCode::STATUS_FAILED
            );
        }
    }

    private function assertOwnedReceipt(
        array $receipt,
        string $idempotencyKey,
        CashierV3OperatorScope $operatorScope
    ): void {
        if (!hash_equals($idempotencyKey, (string)($receipt['idempotency_key'] ?? ''))
            || (int)($receipt['store_id'] ?? 0) !== $operatorScope->storeId()) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::PERMISSION_DENIED,
                '当前账号无权查看该结账结果。',
                CashierV3ResultCode::STATUS_FAILED
            );
        }
        if (!hash_equals(self::ORIGINAL_ACTION, (string)($receipt['action'] ?? ''))) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::IDEMPOTENCY_KEY_CONFLICT,
                '原请求标识不属于结账提交，不能用于查询结账结果。',
                CashierV3ResultCode::STATUS_FAILED
            );
        }
    }

    private function validCommittedResult($committed): bool
    {
        if (!is_array($committed)
            || !is_array($committed['checkoutRequest'] ?? null)) {
            return false;
        }
        $request = $committed['checkoutRequest'];
        $composition = (string)($committed['composition'] ?? '');
        $requestValid = preg_match('/^CKR-[0-9a-f]{40}$/D', (string)($request['requestId'] ?? '')) === 1
            && (int)($request['requestVersion'] ?? 0) > 0
            && (string)($request['requestStatus'] ?? '') === CashierV3CheckoutSettlementStateMachine::SUCCEEDED;
        if (!$requestValid) {
            return false;
        }
        if (in_array($composition, ['sale_only', 'mixed'], true)) {
            $order = is_array($committed['salesOrder'] ?? null) ? $committed['salesOrder'] : [];
            $orderValid = preg_match('/^CSO-[0-9a-f]{40}$/D', (string)($order['orderId'] ?? '')) === 1
                && CashierV3BusinessDocumentNumberServices::isSalesOrderNo((string)($order['orderNo'] ?? ''))
                && (string)($order['orderStatus'] ?? '') === 'settled'
                && (int)($order['orderVersion'] ?? 0) > 0;
            if (!$orderValid) {
                return false;
            }
            return $composition === 'sale_only'
                ? ($committed['entitlementCompletion'] ?? null) === null
                : $this->validEntitlementCompletion($committed['entitlementCompletion'] ?? null);
        }
        if ($composition !== 'entitlement_only' || ($committed['salesOrder'] ?? null) !== null) {
            return false;
        }
        return $this->validEntitlementCompletion($committed['entitlementCompletion'] ?? null);
    }

    private function validEntitlementCompletion($value): bool
    {
        $entitlement = is_array($value) ? $value : [];
        return preg_match('/^ECR-[0-9a-f]{40}$/D', (string)($entitlement['receiptId'] ?? '')) === 1
            && (string)($entitlement['receiptStatus'] ?? '') === 'completed'
            && preg_match('/^[0-9a-f]{64}$/D', (string)($entitlement['planFingerprint'] ?? '')) === 1;
    }

    private function unknown(string $idempotencyKey, string $phase, string $message): array
    {
        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'originalIdempotencyKey' => $idempotencyKey,
            'status' => CashierV3ResultCode::STATUS_RESULT_UNKNOWN,
            'phase' => $phase,
            'code' => CashierV3ResultCode::COMMAND_RESULT_UNKNOWN,
            'message' => $message,
            'composition' => null,
            'checkoutRequest' => null,
            'salesOrder' => null,
            'entitlementCompletion' => null,
        ];
    }

    private function failed(array $receipt, string $idempotencyKey): array
    {
        $code = trim((string)($receipt['result_code'] ?? ''));
        $message = trim((string)($receipt['result_message'] ?? ''));
        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'originalIdempotencyKey' => $idempotencyKey,
            'status' => CashierV3ResultCode::STATUS_FAILED,
            'phase' => CashierV3CheckoutSettlementStateMachine::FAILED,
            'code' => $code !== '' ? $code : CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            'message' => $message !== '' ? $message : '结账未完成，请返回收银台核对后重试。',
            'composition' => null,
            'checkoutRequest' => null,
            'salesOrder' => null,
            'entitlementCompletion' => null,
        ];
    }
}
