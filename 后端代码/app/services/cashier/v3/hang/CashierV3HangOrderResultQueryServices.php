<?php

namespace app\services\cashier\v3\hang;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3IdempotencyKeyServices;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\hang\authority\CashierV3HangOrderPlanV1;
use app\services\room\guard\RoomOpenServiceGuardAuthority;

/** Read-only recovery projection for a possibly unknown submit-hang-order result. */
final class CashierV3HangOrderResultQueryServices
{
    public const CONTRACT_VERSION = 'cashier-v3-hang-order-result-v1';
    private const ORIGINAL_ACTION = 'submit-hang-order';
    private const RECEIPT_PENDING = 0;
    private const RECEIPT_SUCCEEDED = 1;

    /** @var CashierV3HangOrderResultReadRepository */
    private $repository;

    /** @var CashierV3IdempotencyKeyServices */
    private $idempotencyKeys;

    public function __construct(
        ?CashierV3HangOrderResultReadRepository $repository = null,
        ?CashierV3IdempotencyKeyServices $idempotencyKeys = null
    ) {
        $this->repository = $repository ?: new ThinkPhpCashierV3HangOrderResultReadRepository();
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
        if (strpos($idempotencyKey, 'HANG-') !== 0) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::INVALID_IDEMPOTENCY_KEY,
                '原请求标识不是挂单提交请求，请返回收银台重新确认。',
                CashierV3ResultCode::STATUS_FAILED
            );
        }

        $receipt = $this->repository->findReceiptForActor(
            $idempotencyKey,
            $operatorScope->storeId(),
            $operatorScope->operatorId()
        );
        if ($receipt === null) {
            return $this->unknown(
                $idempotencyKey,
                'not_found',
                '暂未查到该挂单请求的确定结果，请稍后继续查询。'
            );
        }
        $this->assertOwnedReceipt($receipt, $idempotencyKey, $operatorScope);

        $receiptStatus = (int)($receipt['status'] ?? -1);
        if ($receiptStatus === self::RECEIPT_PENDING) {
            return $this->unknown(
                $idempotencyKey,
                'pending',
                '挂单仍在处理中，请勿更换请求标识或重复挂单。'
            );
        }
        if ($receiptStatus !== self::RECEIPT_SUCCEEDED) {
            return $this->failed($receipt, $idempotencyKey);
        }
        if (trim((string)($receipt['result_code'] ?? '')) !== '') {
            return $this->unknown(
                $idempotencyKey,
                'reconciliation_required',
                '挂单回执状态不一致，暂不能确认结果，请联系管理员核对。'
            );
        }

        $committed = $this->repository->findCommittedHangOrder($receipt, $operatorScope, $dataScope);
        if (!$this->validCommittedResult($committed, $idempotencyKey)) {
            return $this->unknown(
                $idempotencyKey,
                'reconciliation_required',
                '挂单回执已完成，但正式挂单或房间状态暂时无法核验，请联系管理员核对。'
            );
        }

        $header = $committed['header'];
        $isStartService = (string)$header['hang_mode'] === CashierV3HangOrderPlanV1::MODE_START_SERVICE;
        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'originalIdempotencyKey' => $idempotencyKey,
            'status' => CashierV3ResultCode::STATUS_SUCCESS,
            'phase' => 'succeeded',
            'code' => '',
            'message' => $isStartService ? '挂单已完成，房间已自动占用。' : '挂单已完成。',
            'hangOrder' => [
                'hangOrderId' => (string)$header['hang_order_id'],
                'hangOrderNo' => (string)$header['hang_order_no'],
                'hangMode' => (string)$header['hang_mode'],
                'hangStatus' => (string)$header['hang_status'],
                'hangVersion' => (int)$header['hang_version'],
                'lineCount' => (int)$header['line_count'],
                'totalQuantity' => (int)$header['total_quantity'],
                'occurredAt' => (int)$header['occurred_at'],
                'room' => [
                    'roomId' => (int)$header['room_id'],
                    'roomName' => (string)$header['room_name_snapshot'],
                    'roomTimeSlotId' => (string)$header['room_time_slot_id'],
                    'occupied' => $isStartService,
                ],
            ],
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
            || $operatorScope->operatorId() !== $dataScope->operatorId()
            || $operatorScope->tenantId() === ''
            || !hash_equals($operatorScope->tenantId(), $dataScope->tenantId())
            || !hash_equals($operatorScope->organizationId(), $dataScope->organizationId())) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::PERMISSION_DENIED,
                '当前账号无权查看该挂单结果。',
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
            || (int)($receipt['store_id'] ?? 0) !== $operatorScope->storeId()
            || (int)($receipt['operator_id'] ?? 0) !== $operatorScope->operatorId()) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::PERMISSION_DENIED,
                '当前账号无权查看该挂单结果。',
                CashierV3ResultCode::STATUS_FAILED
            );
        }
        if (!hash_equals(self::ORIGINAL_ACTION, (string)($receipt['action'] ?? ''))) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::IDEMPOTENCY_KEY_CONFLICT,
                '原请求标识不属于挂单提交，不能用于查询挂单结果。',
                CashierV3ResultCode::STATUS_FAILED
            );
        }
    }

    private function validCommittedResult($committed, string $idempotencyKey): bool
    {
        if (!is_array($committed) || !is_array($committed['header'] ?? null)) {
            return false;
        }
        $header = $committed['header'];
        $mode = (string)($header['hang_mode'] ?? '');
        $status = (string)($header['hang_status'] ?? '');
        $startService = $mode === CashierV3HangOrderPlanV1::MODE_START_SERVICE;
        $normal = $mode === CashierV3HangOrderPlanV1::MODE_NORMAL;
        if ((!$startService && !$normal)
            || !hash_equals($idempotencyKey, (string)($header['command_idempotency_key'] ?? ''))
            || preg_match('/^HGO[0-9a-f]{40}$/D', (string)($header['hang_order_id'] ?? '')) !== 1
            || preg_match('/^HG[0-9]{8}[0-9A-F]{16}$/D', (string)($header['hang_order_no'] ?? '')) !== 1
            || (int)($header['hang_version'] ?? 0) <= 0
            || (int)($header['line_count'] ?? 0) <= 0
            || (int)($header['total_quantity'] ?? 0) <= 0
            || (int)($committed['heldLineCount'] ?? 0) !== (int)$header['line_count']
            || ($startService && $status !== CashierV3HangOrderPlanV1::STATUS_SERVICE_IN_PROGRESS)
            || ($normal && $status !== CashierV3HangOrderPlanV1::STATUS_PENDING_CHECKOUT)) {
            return false;
        }
        if (!$startService) {
            return true;
        }

        $guard = is_array($committed['roomGuard'] ?? null) ? $committed['roomGuard'] : [];
        return (int)($header['room_id'] ?? 0) > 0
            && trim((string)($header['room_time_slot_id'] ?? '')) !== ''
            && (string)($guard['occupation_status'] ?? '') === RoomOpenServiceGuardAuthority::STATUS_OCCUPIED
            && (string)($guard['owner_kind'] ?? '') === RoomOpenServiceGuardAuthority::OWNER_HANG_ORDER
            && hash_equals((string)$header['hang_order_id'], (string)($guard['owner_id'] ?? ''))
            && hash_equals((string)$header['room_time_slot_id'], (string)($guard['slot_key'] ?? ''));
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
            'hangOrder' => null,
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
            'phase' => 'failed',
            'code' => $code !== '' ? $code : CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            'message' => $message !== '' ? $message : '挂单未完成，请返回收银台核对后重试。',
            'hangOrder' => null,
        ];
    }
}
