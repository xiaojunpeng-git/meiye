<?php

namespace app\services\cashier\v3;

use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use think\facade\Log;

/**
 * Builds a fail-closed response for a command rejected before a normal gateway
 * envelope is available. A coherent registered request stays bound so the
 * browser can distinguish a deterministic business rejection from a response
 * belonging to another request. Commands additionally require the matching
 * idempotency key; projections do not have one.
 */
final class CashierV3CommandFailureEnvelopeServices
{
    public function fromException(array $body, CashierV3CommandException $exception): array
    {
        $envelope = [
            'result' => [
                'status' => $exception->getResultStatus(),
                'code' => $exception->getResultCode(),
                'message' => $exception->getMessage(),
            ],
            'conflict' => $exception->getResultStatus() === CashierV3ResultCode::STATUS_CONFLICT
                ? $exception->getDetail()
                : null,
        ];

        $binding = $this->safeBinding($body, $exception);
        if ($binding === null) {
            return $envelope;
        }

        $envelope['boundAction'] = $binding['canonical'];
        $envelope['boundCanonical'] = $binding['canonical'];
        if ($binding['idempotencyKey'] !== '') {
            $envelope['boundIdempotencyKey'] = $binding['idempotencyKey'];
        }
        $envelope['correlationId'] = $binding['correlationId'];
        $envelope['boundCorrelationId'] = $binding['correlationId'];
        if ($binding['stateContextId'] !== '') {
            $envelope['stateContextId'] = $binding['stateContextId'];
        }
        return $envelope;
    }

    /**
     * Convert an unexpected server exception into a deterministic V3 failure.
     *
     * The business transaction has already been rolled back by the gateway
     * boundary.  Returning a bound failure keeps the browser from treating a
     * deterministic database/dependency error as an unknown payment result.
     * The exception text is logged only; SQL/schema details never reach the
     * operator.
     */
    public function fromThrowable(array $body, \Throwable $exception): array
    {
        $command = isset($body['command']) && is_array($body['command'])
            ? $body['command']
            : [];
        $action = trim((string)($body['action'] ?? $command['action'] ?? ''));
        $isCheckout = in_array($action, [
            'submit-checkout',
            'submit-debt-repayment',
            'submit-recharge-checkout',
            'submit-recharge-debt-repayment',
        ], true);
        $message = $isCheckout
            ? '结账提交失败，业务数据已回滚，请稍后重试。'
            : '操作失败，请稍后重试。';
        $code = CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE;
        $raw = $exception->getMessage();
        if (preg_match('/42S02|42S22|Base table or view not found|Unknown column|doesn\'t exist/i', $raw)) {
            $message = $isCheckout
                ? '收银服务依赖尚未完成升级，当前结账未提交，业务数据已回滚，请联系管理员完成数据库升级。'
                : '当前功能依赖尚未完成升级，请联系管理员完成数据库升级后重试。';
            $code = CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY;
        }
        try {
            Log::error('[cashier_v3_unexpected_command_failure] ' . json_encode([
                'action' => $action,
                'correlationId' => (string)($body['correlationId'] ?? $body['correlation_id'] ?? ''),
                'exceptionClass' => get_class($exception),
                'exceptionMessage' => $raw,
                'exceptionFile' => basename($exception->getFile()),
                'exceptionLine' => $exception->getLine(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $ignored) {
            // Logging failure must never turn a deterministic checkout failure
            // back into an empty/unknown HTTP response.
        }

        $envelope = [
            'result' => [
                'status' => CashierV3ResultCode::STATUS_FAILED,
                'code' => $code,
                'message' => $message,
            ],
            'conflict' => null,
        ];
        $bindingBody = $body;
        if (trim((string)($bindingBody['action'] ?? '')) === '' && $action !== '') {
            $bindingBody['action'] = $action;
        }
        $binding = $this->safeBinding($bindingBody, new CashierV3CommandException(
            $code,
            $message,
            CashierV3ResultCode::STATUS_FAILED,
            ['action' => $action, 'reason' => 'unexpected_server_exception']
        ));
        if ($binding === null) {
            return $envelope;
        }
        $envelope['boundAction'] = $binding['canonical'];
        $envelope['boundCanonical'] = $binding['canonical'];
        if ($binding['idempotencyKey'] !== '') {
            $envelope['boundIdempotencyKey'] = $binding['idempotencyKey'];
        }
        $envelope['correlationId'] = $binding['correlationId'];
        $envelope['boundCorrelationId'] = $binding['correlationId'];
        if ($binding['stateContextId'] !== '') {
            $envelope['stateContextId'] = $binding['stateContextId'];
        }
        return $envelope;
    }

    /**
     * A binding turns a response into a deterministic answer for the browser
     * request that caused it. Reject mixed command aliases, unknown actions and
     * unknown outcomes rather than guessing which request it belongs to.
     *
     * @return array{canonical:string,idempotencyKey:string,correlationId:string,stateContextId:string}|null
     */
    private function safeBinding(array $body, CashierV3CommandException $exception): ?array
    {
        if (!in_array($exception->getResultStatus(), [
            CashierV3ResultCode::STATUS_FAILED,
            CashierV3ResultCode::STATUS_CONFLICT,
        ], true)) {
            return null;
        }

        $action = $this->singleValue($body, ['action']);
        if ($action === null || $action === '') {
            return null;
        }

        try {
            $definition = CashierV3ActionManifest::requireAction($action);
        } catch (CashierV3CommandException $ignored) {
            return null;
        }
        $canonical = trim((string)($definition['canonical'] ?? ''));
        if ($canonical === '') {
            return null;
        }

        $detailAction = trim((string)($exception->getDetail()['action'] ?? ''));
        if ($detailAction !== '' && CashierV3ActionManifest::canonicalOf($detailAction) !== $canonical) {
            return null;
        }

        $correlationId = $this->singleValue($body, ['correlationId', 'correlation_id']);
        $stateContextId = $this->singleValue($body, ['stateContextId', 'state_context_id']);
        if ($correlationId === null || $correlationId === '' || $stateContextId === null) {
            return null;
        }

        $type = (string)($definition['type'] ?? '');
        $idempotencyKey = '';
        if ($type === CashierV3ActionManifest::TYPE_COMMAND) {
            $command = isset($body['command']) && is_array($body['command']) ? $body['command'] : [];
            $commandAction = $this->singleValue($command, ['action']);
            $idempotencyKey = $this->singleValue($command, ['idempotencyKey', 'idempotency_key']);
            if ($commandAction === null || $commandAction === '' || !hash_equals($action, $commandAction)
                || $idempotencyKey === null || $idempotencyKey === '') {
                return null;
            }
        } elseif ($type !== CashierV3ActionManifest::TYPE_PROJECTION) {
            return null;
        }

        return [
            'canonical' => $canonical,
            'idempotencyKey' => $idempotencyKey,
            'correlationId' => $correlationId,
            'stateContextId' => $stateContextId,
        ];
    }

    /** Returns null when aliases disagree or when the value is not scalar. */
    private function singleValue(array $source, array $keys): ?string
    {
        $seen = false;
        $value = '';
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            if (!is_string($source[$key]) && !is_int($source[$key])) {
                return null;
            }
            $candidate = trim((string)$source[$key]);
            if ($seen && !hash_equals($value, $candidate)) {
                return null;
            }
            $seen = true;
            $value = $candidate;
        }
        return $seen ? $value : '';
    }
}
