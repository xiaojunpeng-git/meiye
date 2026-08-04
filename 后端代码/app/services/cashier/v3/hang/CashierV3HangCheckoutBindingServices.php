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
 * Binds a restored normal-product hang to its later checkout.  The source
 * reference is read-only while preparing a checkout request; final settlement
 * changes the hang in the same transaction as sales, payment and facts.
 */
final class CashierV3HangCheckoutBindingServices
{
    public const CONTRACT_VERSION = CashierV3HangResumeServices::CONTRACT_VERSION;

    /** @return array<string,mixed>|null */
    public function discoverForWorkspaceDraft(
        array $draft,
        CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $dataScope
    ) {
        $hangOrderId = trim((string)($draft['resumed_hang_order_id'] ?? ''));
        if ($hangOrderId === '') {
            return null;
        }
        $this->assertScope($operator, $dataScope);
        $workspaceId = (string)($draft['workspace_id'] ?? '');
        $stateContextId = (string)($draft['state_context_id'] ?? '');
        $header = $this->readResumedHeader(
            $hangOrderId,
            $workspaceId,
            $stateContextId,
            $operator,
            $dataScope,
            false
        );
        return $this->resource($header, 'read');
    }

    /** Recheck source binding under the final prepare-checkout locks. */
    public function assertWorkspaceBindingInTx(
        array $draft,
        array $contexts,
        CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $dataScope
    ): void {
        CashierV3TransactionGuard::assertInTransaction('hangCheckoutSourceBinding');
        $hangOrderId = trim((string)($draft['resumed_hang_order_id'] ?? ''));
        if ($hangOrderId === '') {
            return;
        }
        $header = $this->readResumedHeader(
            $hangOrderId,
            (string)$draft['workspace_id'],
            (string)$draft['state_context_id'],
            $operator,
            $dataScope,
            true
        );
        $expected = $this->contextVersion($contexts, 'hang_order', $hangOrderId);
        if ($expected <= 0 || $expected !== (int)$header['hang_version']) {
            throw CashierV3CommandException::versionConflict(
                '该挂单已经变化，请返回重新提单。',
                ['reason' => 'hang_checkout_source_version_changed']
            );
        }
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
        $source = $this->hangSource($aggregate['sources'] ?? []);
        if ($source === null) {
            return null;
        }
        $hangOrderId = (string)$source['source_id'];
        $sourceVersion = (int)$source['source_version'];
        $request = is_array($aggregate['request'] ?? null) ? $aggregate['request'] : [];
        $workspaceId = (string)($request['workspace_id'] ?? '');
        $stateContextId = (string)($request['state_context_id'] ?? '');
        $header = $this->readResumedHeader(
            $hangOrderId,
            $workspaceId,
            $stateContextId,
            $operator,
            $dataScope,
            true
        );
        if ($sourceVersion <= 0 || (int)$header['hang_version'] !== $sourceVersion
            || trim((string)($header['checkout_request_id'] ?? '')) !== '') {
            throw CashierV3CommandException::versionConflict(
                '该挂单已经进入其他结账流程，请刷新后重试。',
                ['reason' => 'hang_checkout_completion_version_or_binding_changed']
            );
        }
        $salesOrderId = trim((string)($salesOrder['orderId'] ?? ''));
        $salesOrderNo = trim((string)($salesOrder['orderNo'] ?? ''));
        if ($salesOrderId === '' || $salesOrderNo === '') {
            throw self::failure('hang_checkout_completion_sales_order_invalid');
        }
        $lines = $this->rows(Db::name(ThinkPhpCashierV3HangOrderRepository::LINE_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('hang_order_id', $hangOrderId)
            ->order('line_no asc,id asc')
            ->lock(true)
            ->select());
        if (count($lines) !== (int)$header['line_count']) {
            throw self::failure('hang_checkout_completion_line_count_invalid');
        }
        foreach ($lines as $index => $line) {
            if ((string)($line['line_status'] ?? '') !== 'resumed'
                || (int)($line['line_version'] ?? 0) !== 2
                || (string)($line['line_role'] ?? '') !== 'sale') {
                throw self::failure('hang_checkout_completion_line_state_invalid', ['index' => $index]);
            }
        }
        $now = time();
        $headerUpdated = (int)Db::name(ThinkPhpCashierV3HangOrderRepository::HEADER_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $operator->storeId())
            ->where('hang_order_id', $hangOrderId)
            ->where('hang_version', $sourceVersion)
            ->where('hang_status', 'resumed_checkout')
            ->where('resume_workspace_id', $workspaceId)
            ->where('resume_state_context_id', $stateContextId)
            ->where('checkout_request_id', '')
            ->update([
                'hang_status' => 'settled',
                'checkout_request_id' => $checkoutRequestId,
                'sales_order_id' => $salesOrderId,
                'settled_at' => $now,
                'update_time' => $now,
            ]);
        if ($headerUpdated !== 1) {
            throw CashierV3CommandException::versionConflict(
                '挂单结账状态已经变化，请刷新后重试。',
                ['reason' => 'hang_checkout_completion_header_cas_conflict']
            );
        }
        $lineUpdated = (int)Db::name(ThinkPhpCashierV3HangOrderRepository::LINE_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('hang_order_id', $hangOrderId)
            ->where('line_status', 'resumed')
            ->update([
                'line_status' => 'settled',
                'line_version' => Db::raw('line_version + 1'),
                'update_time' => $now,
            ]);
        if ($lineUpdated !== count($lines)) {
            throw self::failure('hang_checkout_completion_line_update_incomplete');
        }
        $eventRecorder->recordInTx($eventExecution, $eventContract, [
            'event_type' => 'hang_order.settled',
            'aggregate_type' => 'hang_order',
            'aggregate_id' => $hangOrderId,
            'aggregate_version' => $sourceVersion + 1,
            'event_version' => 1,
            'source_type' => 'submit-checkout',
            'source_id' => $checkoutRequestId,
            'member_id' => (int)$header['member_id'],
            'business_date' => (string)$header['business_date'],
            'occurred_at' => $now,
            'settled_at' => $now,
            'recorded_at' => $now,
            'aggregate_name_snapshot' => (string)$header['hang_order_no'],
            'store_name_snapshot' => (string)$header['store_name_snapshot'],
            'payload' => [
                'contractVersion' => self::CONTRACT_VERSION,
                'hangOrderId' => $hangOrderId,
                'checkoutRequestId' => $checkoutRequestId,
                'salesOrderId' => $salesOrderId,
                'salesOrderNo' => $salesOrderNo,
            ],
        ]);
        return [
            'hangOrderId' => $hangOrderId,
            'hangOrderNo' => (string)$header['hang_order_no'],
            'hangVersion' => $sourceVersion + 1,
            'status' => 'settled',
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
            && (string)($header['hang_mode'] ?? '') === CashierV3HangOrderPlanV1::MODE_NORMAL
            && (string)($header['hang_status'] ?? '') === 'resumed_checkout'
            && (string)($header['resume_contract_version'] ?? '') === self::CONTRACT_VERSION
            && hash_equals($workspaceId, (string)($header['resume_workspace_id'] ?? ''))
            && hash_equals($stateContextId, (string)($header['resume_state_context_id'] ?? ''))
            && trim((string)($header['resume_command_idempotency_key'] ?? '')) !== ''
            && (int)($header['resumed_at'] ?? 0) > 0
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

    /** @return array<string,mixed> */
    private function resource(array $header, string $accessMode): array
    {
        return [
            'kind' => CashierV3HangOrderVersionProvider::KIND,
            'id' => (string)$header['hang_order_id'],
            'expectedVersion' => (int)$header['hang_version'],
            'roles' => ['hang_order'],
            'accessMode' => $accessMode,
            'providerContractVersion' => CashierV3HangOrderVersionProvider::CONTRACT_VERSION,
            'authorityFingerprint' => hash('sha256', implode('|', [
                self::CONTRACT_VERSION,
                (string)$header['immutable_fingerprint'],
                (string)$header['hang_status'],
                (string)$header['hang_version'],
                (string)$header['resume_workspace_id'],
                (string)$header['resume_state_context_id'],
            ])),
        ];
    }

    /** @return array<string,mixed>|null */
    private function hangSource($sources)
    {
        $found = null;
        foreach ((array)$sources as $source) {
            if (!is_array($source) || (string)($source['source_kind'] ?? '') !== 'hang_order') {
                continue;
            }
            if ($found !== null) {
                throw self::failure('hang_checkout_source_duplicate');
            }
            $found = $source;
        }
        return $found;
    }

    private function contextVersion(array $contexts, string $kind, string $id): int
    {
        foreach ($contexts as $context) {
            if ((string)($context['kind'] ?? '') === $kind
                && (string)($context['id'] ?? '') === $id) {
                return (int)($context['expected_version'] ?? $context['expectedVersion'] ?? 0);
            }
        }
        return 0;
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
