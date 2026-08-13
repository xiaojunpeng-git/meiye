<?php

namespace app\services\cashier\v3\hang;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\event\CashierV3BusinessEventExecution;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\hang\authority\CashierV3HangOrderPlanV1;
use app\services\cashier\v3\hang\authority\ThinkPhpCashierV3HangOrderRepository;
use think\facade\Db;

/**
 * Performs post-settlement cleanup for a restored hang draft. A resume only
 * restores editable cart data; the internal request reference is never a
 * checkout source or a submission resource.
 */
final class CashierV3HangCheckoutBindingServices
{
    public const CONTRACT_VERSION = CashierV3HangResumeServices::CONTRACT_VERSION;

    /**
     * Pins the restored draft as an internal cleanup dependency. It is not a
     * checkout source document: submission preparation reads it here and the
     * immutable final resource plan upgrades it to a mutate lock only when the
     * successful settlement physically deletes the draft.
     *
     * @return array<string,mixed>
     */
    public function discoverSettlementCleanupResource(
        string $hangOrderId,
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $dataScope
    ): array {
        $header = $this->readResumedHeader(
            $hangOrderId,
            $workspaceId,
            $stateContextId,
            $operator,
            $dataScope,
            false
        );
        if (trim((string)($header['checkout_request_id'] ?? '')) !== '') {
            throw CashierV3CommandException::versionConflict(
                '该挂单已经进入其他结账流程，请刷新后重试。',
                ['reason' => 'hang_checkout_cleanup_already_bound']
            );
        }

        $version = (int)$header['hang_version'];
        return [
            'kind' => CashierV3HangOrderVersionProvider::KIND,
            'id' => $hangOrderId,
            'expectedVersion' => $version,
            'roles' => ['hang_order'],
            // Preparing the final plan must not mutate the draft itself.
            'accessMode' => 'read',
            'providerContractVersion' => CashierV3HangOrderVersionProvider::CONTRACT_VERSION,
            'authorityFingerprint' => hash('sha256', json_encode([
                'contractVersion' => self::CONTRACT_VERSION,
                'hangOrderId' => $hangOrderId,
                'hangOrderNo' => (string)$header['hang_order_no'],
                'status' => (string)$header['hang_status'],
                'version' => $version,
                'immutableFingerprint' => (string)$header['immutable_fingerprint'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];
    }

    /**
     * Terminal transition after a sale-only checkout has all formal sales,
     * collections, inventory records and facts written successfully.
     *
     * @return array<string,mixed>|null
     */
    public function completeFromCheckoutInTx(
        array $aggregate,
        string $checkoutRequestId,
        array $salesOrder,
        CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $dataScope,
        CashierV3BusinessEventRecorder $eventRecorder,
        CashierV3BusinessEventExecution $eventExecution,
        array $eventContract
    ) {
        CashierV3TransactionGuard::assertInTransaction('hangCheckoutCompletion');
        $request = is_array($aggregate['request'] ?? null) ? $aggregate['request'] : [];
        $hangOrderId = trim((string)($request['resumed_hang_order_id'] ?? ''));
        if ($hangOrderId === '') {
            return null;
        }
        // A normal resumed hang is only a saved cart.  It never participates
        // in checkout validation, resource locks or payment facts.  Once the
        // sales/payment transaction succeeds, remove that draft directly.
        $deletedLines = (int)Db::name(ThinkPhpCashierV3HangOrderRepository::LINE_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $operator->storeId())
            ->where('hang_order_id', $hangOrderId)
            ->delete();
        $deletedHeader = (int)Db::name(ThinkPhpCashierV3HangOrderRepository::HEADER_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $operator->storeId())
            ->where('hang_order_id', $hangOrderId)
            ->delete();
        return [
            'hangOrderId' => $hangOrderId,
            'deleted' => $deletedHeader === 1,
            'deletedLineCount' => $deletedLines,
        ];
    }

    /** @return array<string,mixed> */
    private function readResumedHeader(
        string $hangOrderId,
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $dataScope,
        bool $lock
    ): array {
        if (preg_match('/^HGO[0-9a-f]{40}$/D', $hangOrderId) !== 1
            || $workspaceId === '' || $stateContextId === '') {
            throw self::failure('hang_checkout_source_identity_invalid');
        }
        $this->assertScope($operator, $dataScope);
        $query = Db::name(ThinkPhpCashierV3HangOrderRepository::HEADER_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $operator->storeId())
            ->where('hang_order_id', $hangOrderId);
        if ($lock) {
            $query->lock(true);
        }
        $header = $query->find();
        $valid = is_array($header)
            && in_array((string)($header['hang_mode'] ?? ''), [
                CashierV3HangOrderPlanV1::MODE_NORMAL,
                CashierV3HangOrderPlanV1::MODE_START_SERVICE,
            ], true)
            && in_array((string)($header['hang_status'] ?? ''), [
                CashierV3HangOrderPlanV1::STATUS_PENDING_CHECKOUT,
                CashierV3HangOrderPlanV1::STATUS_SERVICE_IN_PROGRESS,
                'resumed_checkout',
            ], true)
            && in_array((string)($header['resume_contract_version'] ?? ''), [
                CashierV3HangOrderPlanV1::RESUME_SALE_ONLY_CONTRACT_VERSION,
                CashierV3HangOrderPlanV1::RESUME_SALE_PROJECT_CONTRACT_VERSION,
                CashierV3HangOrderPlanV1::RESUME_DRAFT_CONTRACT_VERSION,
            ], true)
            && (int)($header['settled_at'] ?? 0) === 0
            && (int)($header['hang_version'] ?? 0) > 0;
        if (!$valid) {
            throw CashierV3CommandException::versionConflict(
                '已提取挂单的状态已经变化，请返回挂单列表重新操作。',
                ['reason' => 'hang_checkout_source_not_active']
            );
        }
        return $header;
    }

    private function assertScope(CashierV3OperatorScope $operator, CashierV3DataScopeContext $dataScope): void
    {
        if ($operator->tenantId() === ''
            || !hash_equals($operator->tenantId(), $dataScope->tenantId())
            || $operator->storeId() !== $dataScope->forcedStoreId()
            || $operator->operatorId() !== $dataScope->operatorId()
            || !hash_equals($operator->organizationId(), $dataScope->organizationId())
            || !$dataScope->allowsStore($operator->storeId())) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::PERMISSION_DENIED,
                '当前账号无权结算该挂单。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'hang_checkout_data_scope_denied']
            );
        }
    }

    private function rows($rows): array
    {
        if (is_object($rows) && method_exists($rows, 'toArray')) {
            $rows = $rows->toArray();
        }
        return is_array($rows) ? array_values($rows) : [];
    }

    private static function failure(string $reason, array $detail = []): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            '挂单与结账的关联校验失败，本次结账已回滚。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason] + $detail
        );
    }
}
