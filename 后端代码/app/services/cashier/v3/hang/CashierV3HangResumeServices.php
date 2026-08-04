<?php

namespace app\services\cashier\v3\hang;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\cashier\CashierV3CashierWorkspaceServices;
use app\services\cashier\v3\cashier\CashierV3SaleCatalogServices;
use app\services\cashier\v3\event\CashierV3BusinessEventExecution;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\hang\authority\CashierV3HangOrderPlanV1;
use app\services\cashier\v3\hang\authority\ThinkPhpCashierV3HangOrderRepository;
use think\facade\Db;

/**
 * Restores only new-contract ordinary-product hangs.  A public hang-line
 * snapshot is never used here: every restored cart row originates from the
 * immutable workspace snapshot captured while the source workspace was locked.
 */
final class CashierV3HangResumeServices
{
    public const CONTRACT_VERSION = 'cashier-v3-hang-resume-sale-only-v1';

    /** @var CashierV3CashierWorkspaceServices */
    private $workspace;

    /** @var CashierV3SaleCatalogServices */
    private $saleCatalog;

    public function __construct(
        CashierV3CashierWorkspaceServices $workspace,
        CashierV3SaleCatalogServices $saleCatalog
    ) {
        $this->workspace = $workspace;
        $this->saleCatalog = $saleCatalog;
    }

    /** Callable server discovery for resume-hang-order. */
    public function discover(array $scope): array
    {
        $operator = $scope['operator_scope'] ?? null;
        $dataScope = $scope['data_scope'] ?? null;
        $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
        $contexts = is_array($scope['contexts'] ?? null) ? $scope['contexts'] : [];
        if (!$operator instanceof CashierV3OperatorScope || !$dataScope instanceof CashierV3DataScopeContext) {
            throw self::invalid('hang_resume_discovery_scope_invalid');
        }
        $this->assertScope($operator, $dataScope);
        $hangOrderId = self::hangOrderId($payload['hangOrderId'] ?? $payload['hang_order_id'] ?? null);
        $header = $this->loadEligibleHeader($hangOrderId, $operator, $dataScope, false);
        $lines = $this->loadEligibleLines($header, $operator, $dataScope, false);

        $resources = [$this->hangResource($header, $lines, 'mutate')];
        foreach ($lines as $line) {
            foreach ($this->saleCatalog->discoverStoredLineResources(
                $line['workspaceSnapshot'],
                (int)$line['workspaceSnapshot']['quantity'],
                $operator,
                $dataScope
            ) as $resource) {
                $resources[] = $resource;
            }
        }
        $resources[] = $this->workspaceResource($contexts);

        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'resources' => $resources,
        ];
    }

    /** Execute after Gateway has locked hang_order, workspace and catalog resources. */
    public function resumeInTx(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('cashierHangResume');
        $operator = $scope['operator_scope'] ?? null;
        $dataScope = $scope['data_scope'] ?? null;
        $eventRecorder = $scope['event_recorder'] ?? null;
        $eventExecution = $scope['event_execution'] ?? null;
        $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
        $contexts = is_array($scope['contexts'] ?? null) ? $scope['contexts'] : [];
        $stateContextId = trim((string)($scope['state_context_id'] ?? ''));
        $commandKey = trim((string)($scope['idempotency_key'] ?? ''));
        if (!$operator instanceof CashierV3OperatorScope
            || !$dataScope instanceof CashierV3DataScopeContext
            || !$eventRecorder instanceof CashierV3BusinessEventRecorder
            || !$eventExecution instanceof CashierV3BusinessEventExecution) {
            throw self::invalid('hang_resume_scope_incomplete');
        }
        $this->assertScope($operator, $dataScope);
        $hangOrderId = self::hangOrderId($payload['hangOrderId'] ?? $payload['hang_order_id'] ?? null);
        $workspaceId = self::workspaceId($operator, $stateContextId);
        $this->assertLockedContexts($contexts, $hangOrderId, $workspaceId);
        if (preg_match('/^[A-Za-z0-9:_-]{16,128}$/D', $commandKey) !== 1) {
            throw self::invalid('hang_resume_command_key_invalid');
        }

        $header = $this->loadEligibleHeader($hangOrderId, $operator, $dataScope, true);
        $lines = $this->loadEligibleLines($header, $operator, $dataScope, true);
        $contextVersion = $this->contextVersion($contexts, 'hang_order', $hangOrderId);
        if ((int)$header['hang_version'] !== $contextVersion) {
            throw CashierV3CommandException::versionConflict(
                '该挂单已被其他操作更新，请刷新后重新提单。',
                ['reason' => 'hang_resume_locked_version_changed']
            );
        }
        foreach ($lines as $line) {
            $this->saleCatalog->assertStoredSaleQuantityAfterGatewayLocksInTx(
                $line['workspaceSnapshot'],
                (int)$line['workspaceSnapshot']['quantity'],
                $contexts,
                $operator,
                $dataScope
            );
        }

        $cashierDraft = $this->workspace->restoreSaleOnlyHangInTx(
            $workspaceId,
            $stateContextId,
            $operator,
            $hangOrderId,
            (int)$header['member_id'],
            array_column($lines, 'workspaceSnapshot')
        );
        $now = time();
        $updated = (int)Db::name(ThinkPhpCashierV3HangOrderRepository::HEADER_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $operator->storeId())
            ->where('hang_order_id', $hangOrderId)
            ->where('hang_version', $contextVersion)
            ->where('hang_status', CashierV3HangOrderPlanV1::STATUS_PENDING_CHECKOUT)
            ->where('resume_contract_version', self::CONTRACT_VERSION)
            ->where('resume_workspace_id', '')
            ->where('checkout_request_id', '')
            ->update([
                'hang_status' => 'resumed_checkout',
                'resume_workspace_id' => $workspaceId,
                'resume_state_context_id' => $stateContextId,
                'resume_command_idempotency_key' => $commandKey,
                'resumed_at' => $now,
                'update_time' => $now,
            ]);
        if ($updated !== 1) {
            throw CashierV3CommandException::versionConflict(
                '该挂单已被其他操作提取，请刷新后重试。',
                ['reason' => 'hang_resume_header_cas_conflict']
            );
        }
        $lineUpdated = (int)Db::name(ThinkPhpCashierV3HangOrderRepository::LINE_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('hang_order_id', $hangOrderId)
            ->where('line_status', 'held')
            ->update([
                'line_status' => 'resumed',
                'line_version' => Db::raw('line_version + 1'),
                'update_time' => $now,
            ]);
        if ($lineUpdated !== count($lines)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '挂单明细状态更新失败，本次提单已回滚，请重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'hang_resume_line_update_incomplete']
            );
        }

        $eventRecorder->recordInTx($eventExecution, (array)($scope['event_contract'] ?? []), [
            'event_type' => 'hang_order.resumed',
            'aggregate_type' => 'hang_order',
            'aggregate_id' => $hangOrderId,
            'aggregate_version' => $contextVersion + 1,
            'event_version' => 1,
            'source_type' => 'resume-hang-order',
            'source_id' => $hangOrderId,
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
                'workspaceId' => $workspaceId,
                'lineCount' => count($lines),
            ],
        ]);

        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'hangOrderId' => $hangOrderId,
            'hangOrderNo' => (string)$header['hang_order_no'],
            'hangVersion' => $contextVersion + 1,
            'status' => 'restored',
            'cashierDraft' => $cashierDraft,
        ];
    }

    /** @return array<string,mixed> */
    private function loadEligibleHeader(
        string $hangOrderId,
        CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $dataScope,
        bool $lock
    ): array {
        $query = Db::name(ThinkPhpCashierV3HangOrderRepository::HEADER_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $operator->storeId())
            ->where('hang_order_id', $hangOrderId);
        if ($lock) {
            $query->lock(true);
        }
        $header = $query->find();
        if (!is_array($header)) {
            throw CashierV3CommandException::versionConflict(
                '该挂单不存在或已不在当前门店范围，请刷新后重试。',
                ['reason' => 'hang_resume_header_not_found']
            );
        }
        $valid = (string)($header['hang_mode'] ?? '') === CashierV3HangOrderPlanV1::MODE_NORMAL
            && (string)($header['hang_status'] ?? '') === CashierV3HangOrderPlanV1::STATUS_PENDING_CHECKOUT
            && (string)($header['resume_contract_version'] ?? '') === self::CONTRACT_VERSION
            && trim((string)($header['resume_workspace_id'] ?? '')) === ''
            && trim((string)($header['checkout_request_id'] ?? '')) === ''
            && trim((string)($header['sales_order_id'] ?? '')) === ''
            && (int)($header['resumed_at'] ?? 0) === 0
            && (int)($header['settled_at'] ?? 0) === 0
            && (int)($header['hang_version'] ?? 0) > 0
            && (int)($header['line_count'] ?? 0) > 0
            && (int)($header['entitlement_actual_amount_cents'] ?? -1) === 0;
        if (!$valid) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_NOT_IMPLEMENTED,
                '该挂单不是可恢复的新版普通商品挂单，请按原流程处理。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'hang_resume_contract_not_supported']
            );
        }
        return $header;
    }

    /** @return array<int,array{workspaceSnapshot:array,line:array}> */
    private function loadEligibleLines(
        array $header,
        CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $dataScope,
        bool $lock
    ): array {
        $query = Db::name(ThinkPhpCashierV3HangOrderRepository::LINE_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('hang_order_id', (string)$header['hang_order_id'])
            ->order('line_no asc,id asc');
        if ($lock) {
            $query->lock(true);
        }
        $rows = $query->select();
        if (is_object($rows) && method_exists($rows, 'toArray')) {
            $rows = $rows->toArray();
        }
        $rows = is_array($rows) ? array_values($rows) : [];
        if (count($rows) !== (int)$header['line_count']) {
            throw self::invalid('hang_resume_line_count_invalid');
        }
        $result = [];
        foreach ($rows as $index => $row) {
            $snapshot = json_decode((string)($row['workspace_snapshot_json'] ?? ''), true);
            $valid = (string)($row['line_role'] ?? '') === 'sale'
                && (string)($row['line_status'] ?? '') === 'held'
                && (int)($row['line_version'] ?? 0) === 1
                && (int)($row['member_id'] ?? -1) === (int)$header['member_id']
                && (int)($row['quantity'] ?? 0) > 0
                && (int)($row['source_id'] ?? 0) > 0
                && (int)($row['detail_id'] ?? 0) > 0
                && (int)($row['source_version'] ?? 0) > 0
                && (int)($row['detail_version'] ?? 0) > 0
                && preg_match('/^[a-f0-9]{64}$/D', (string)($row['immutable_fingerprint'] ?? '')) === 1
                && is_array($snapshot)
                && (string)($snapshot['line_key'] ?? '') === (string)($row['workspace_line_id'] ?? '')
                && (string)($snapshot['line_role'] ?? '') === 'sale'
                && (int)($snapshot['member_id'] ?? -1) === (int)$header['member_id']
                && (int)($snapshot['catalog_product_id'] ?? 0) === (int)$row['source_id']
                && (int)($snapshot['catalog_sku_id'] ?? 0) === (int)$row['detail_id']
                && (int)($snapshot['catalog_product_type'] ?? -1) === 0
                && (int)($snapshot['project_id'] ?? -1) === 0
                && (int)($snapshot['quantity'] ?? 0) === (int)$row['quantity']
                && (int)($snapshot['source_version'] ?? 0) === (int)$row['source_version']
                && (int)($snapshot['detail_version'] ?? 0) === (int)$row['detail_version'];
            if (!$valid) {
                throw self::invalid('hang_resume_line_contract_invalid', ['index' => $index]);
            }
            $result[] = ['workspaceSnapshot' => $snapshot, 'line' => $row];
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function hangResource(array $header, array $lines, string $accessMode): array
    {
        $lineFingerprintRows = [];
        foreach ($lines as $line) {
            $row = (array)($line['line'] ?? []);
            $lineFingerprintRows[] = [
                'lineId' => (string)($row['hang_order_line_id'] ?? ''),
                'fingerprint' => (string)($row['immutable_fingerprint'] ?? ''),
                'workspaceSnapshot' => hash('sha256', (string)($row['workspace_snapshot_json'] ?? '')),
            ];
        }
        return [
            'kind' => CashierV3HangOrderVersionProvider::KIND,
            'id' => (string)$header['hang_order_id'],
            'expectedVersion' => (int)$header['hang_version'],
            'roles' => ['hang_order'],
            'accessMode' => $accessMode,
            'providerContractVersion' => CashierV3HangOrderVersionProvider::CONTRACT_VERSION,
            'authorityFingerprint' => hash('sha256', self::canonicalJson([
                'contractVersion' => self::CONTRACT_VERSION,
                'headerFingerprint' => (string)$header['immutable_fingerprint'],
                'status' => (string)$header['hang_status'],
                'version' => (int)$header['hang_version'],
                'lines' => $lineFingerprintRows,
            ])),
        ];
    }

    /** @return array<string,mixed> */
    private function workspaceResource(array $contexts): array
    {
        foreach ($contexts as $context) {
            if ((string)($context['kind'] ?? '') !== 'cashier_workspace') {
                continue;
            }
            $id = trim((string)($context['id'] ?? ''));
            $version = (int)($context['expected_version'] ?? $context['expectedVersion'] ?? 0);
            if ($id !== '' && $version > 0) {
                return [
                    'kind' => 'cashier_workspace',
                    'id' => $id,
                    'expectedVersion' => $version,
                    'roles' => ['cashier_workspace'],
                    'accessMode' => 'mutate',
                    'providerContractVersion' => self::CONTRACT_VERSION,
                    'authorityFingerprint' => hash('sha256', 'cashier_workspace|' . $id . '|' . $version),
                ];
            }
        }
        throw self::invalid('hang_resume_workspace_context_missing');
    }

    private function assertLockedContexts(array $contexts, string $hangOrderId, string $workspaceId): void
    {
        if ($this->contextVersion($contexts, 'hang_order', $hangOrderId) <= 0
            || $this->contextVersion($contexts, 'cashier_workspace', $workspaceId) <= 0) {
            throw self::invalid('hang_resume_locked_context_missing');
        }
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
                '当前账号无权提取该挂单。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'hang_resume_data_scope_denied']
            );
        }
    }

    private static function hangOrderId($value): string
    {
        $id = trim((string)$value);
        if (preg_match('/^HGO[0-9a-f]{40}$/D', $id) !== 1) {
            throw self::invalid('hang_resume_id_invalid');
        }
        return $id;
    }

    private static function workspaceId(CashierV3OperatorScope $operator, string $stateContextId): string
    {
        if ($stateContextId === '' || strlen($stateContextId) > 64) {
            throw self::invalid('hang_resume_state_context_invalid');
        }
        return sprintf('ws:%d:%d:%s', $operator->storeId(), $operator->operatorId(), $stateContextId);
    }

    private static function canonicalJson(array $value): string
    {
        $normalize = static function ($input) use (&$normalize) {
            if (!is_array($input)) {
                return $input;
            }
            if (array_keys($input) === range(0, count($input) - 1)) {
                foreach ($input as $index => $item) {
                    $input[$index] = $normalize($item);
                }
                return $input;
            }
            ksort($input, SORT_STRING);
            foreach ($input as $key => $item) {
                $input[$key] = $normalize($item);
            }
            return $input;
        };
        $json = json_encode($normalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw self::invalid('hang_resume_canonical_json_invalid');
        }
        return $json;
    }

    private static function invalid(string $reason, array $detail = []): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::INVALID_COMMAND_CONTEXT,
            '挂单恢复资料无效，请刷新后重新提单。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason] + $detail
        );
    }
}
