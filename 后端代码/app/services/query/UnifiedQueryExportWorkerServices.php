<?php

namespace app\services\query;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use think\facade\Db;

/**
 * 统一查询 XLSX 后台执行器。运行时文件只允许写入 runtime/unified-query-exports。
 */
class UnifiedQueryExportWorkerServices
{
    public const RETENTION_SECONDS = 86400;
    public const CLEANUP_GRACE_SECONDS = 60;
    public const LEASE_RENEW_INTERVAL_SECONDS = 30;
    public const MAX_TASK_RUNTIME_SECONDS = 240;
    public const HEARTBEAT_ROW_INTERVAL = 50;

    /** @var UnifiedQueryExportTaskServices */
    protected $tasks;

    /** @var UnifiedQueryProviderRegistry */
    protected $providers;

    /** @var UnifiedQueryWorkerContextResolverRegistry */
    protected $contextResolvers;

    /** @var UnifiedQueryExportStorage */
    protected $storage;

    public function __construct(
        UnifiedQueryExportTaskServices $tasks,
        UnifiedQueryProviderRegistry $providers,
        UnifiedQueryWorkerContextResolverRegistry $contextResolvers
    ) {
        $this->tasks = $tasks;
        $this->providers = $providers;
        $this->contextResolvers = $contextResolvers;
        $this->storage = new UnifiedQueryExportStorage();
    }

    public function processPending(int $limit = 20, string $onlyTaskNo = ''): array
    {
        $limit = max(1, min(100, $limit));
        $cleanup = $this->cleanupExpired(max(20, min(200, $limit * 2)));
        if ($onlyTaskNo !== '') {
            if (!preg_match('/^uqe_[a-f0-9]{32}$/D', $onlyTaskNo)) {
                throw new \InvalidArgumentException('导出任务编号不合法');
            }
        }

        // 分拆 pending / 过期 running，避免 OR 让 MySQL 放弃
        // idx_status_lease(status,lease_expires_at,id) 做全表扫描。pending 的历史坏数据
        // 即使遗留 lease，也由 claim() 的 status CAS 接管并自愈，不能永久卡住任务。
        $pending = Db::name(UnifiedQueryExportTaskServices::TABLE)
            ->where('status', 'pending');
        $expired = Db::name(UnifiedQueryExportTaskServices::TABLE)
            ->where('status', 'running')
            ->whereBetween('lease_expires_at', [1, max(1, time() - 1)]);
        if ($onlyTaskNo !== '') {
            $pending->where('task_no', $onlyTaskNo);
            $expired->where('task_no', $onlyTaskNo);
        }
        $candidates = array_merge(
            $pending->field('id,task_no')
                ->order('id', 'asc')
                ->limit($limit)
                ->select()
                ->toArray(),
            $expired->field('id,task_no')
                ->order('id', 'asc')
                ->limit($limit)
                ->select()
                ->toArray()
        );
        usort($candidates, function (array $left, array $right): int {
            return (int)$left['id'] <=> (int)$right['id'];
        });
        $taskNos = [];
        foreach ($candidates as $candidate) {
            $candidateTaskNo = (string)($candidate['task_no'] ?? '');
            if ($candidateTaskNo !== '' && !isset($taskNos[$candidateTaskNo])) {
                $taskNos[$candidateTaskNo] = true;
            }
            if (count($taskNos) >= $limit) {
                break;
            }
        }
        $taskNos = array_keys($taskNos);
        $result = [
            'scanned' => count($taskNos),
            'succeeded' => 0,
            'failed' => 0,
            'tasks' => [],
            'cleanup' => $cleanup,
        ];
        foreach ($taskNos as $taskNo) {
            $item = $this->processOne($taskNo);
            $result['tasks'][] = $item;
            $result[$item['status'] === 'succeeded' ? 'succeeded' : 'failed']++;
        }
        return $result;
    }

    /**
     * 有界、可重入的过期清理：
     * 1. CAS 将到期成功任务改为 expired，立即阻止新下载；
     * 2. expired 行保留 storage_key，删除失败或进程崩溃可在下轮重试；
     * 3. 仅删除受控 runtime 路径，成功（含文件已不存在）后再 CAS 清对象键。
     */
    public function cleanupExpired(int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $now = time();
        $marked = 0;
        $deleted = 0;
        $retry = 0;
        $candidates = Db::name(UnifiedQueryExportTaskServices::TABLE)
            ->where('status', 'succeeded')
            ->whereBetween('expires_at', [1, $now])
            ->field('id')
            ->order('expires_at', 'asc')
            ->order('id', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();
        foreach ($candidates as $candidate) {
            $marked += (int)Db::name(UnifiedQueryExportTaskServices::TABLE)
                ->where('id', (int)$candidate['id'])
                ->where('status', 'succeeded')
                ->whereBetween('expires_at', [1, $now])
                ->update([
                    'status' => 'expired',
                    'updated_at' => $now,
                ]);
        }

        $expired = Db::name(UnifiedQueryExportTaskServices::TABLE)
            ->where('status', 'expired')
            ->where('storage_key', '<>', '')
            // 给过期前已经通过鉴权、正在流式下载的响应留出完成窗口。
            ->where('updated_at', '<=', $now - self::CLEANUP_GRACE_SECONDS)
            ->field('id,storage_key')
            ->order('expires_at', 'asc')
            ->order('id', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();
        foreach ($expired as $task) {
            $storageKey = (string)$task['storage_key'];
            try {
                $path = $this->absolutePath($storageKey);
                if (is_file($path) && !@unlink($path)) {
                    $retry++;
                    continue;
                }
                $cleared = Db::name(UnifiedQueryExportTaskServices::TABLE)
                    ->where('id', (int)$task['id'])
                    ->where('status', 'expired')
                    ->where('storage_key', $storageKey)
                    ->update([
                        'storage_key' => '',
                        'updated_at' => time(),
                    ]);
                if ((int)$cleared === 1) {
                    $deleted++;
                }
            } catch (\Throwable $ignored) {
                $retry++;
            }
        }
        return ['markedExpired' => $marked, 'deletedFiles' => $deleted, 'retry' => $retry];
    }

    public function processOne(string $taskNo): array
    {
        $seed = Db::name(UnifiedQueryExportTaskServices::TABLE)
            ->where('task_no', $taskNo)
            ->find();
        if (!$seed) {
            throw new \RuntimeException('导出任务不存在');
        }
        $claim = null;
        $storageKey = '';
        $completionAttempted = false;
        $execution = [];
        $heartbeat = null;
        try {
            $context = $this->currentContext($seed);
            $claim = $this->tasks->claim($context, $taskNo);
            $this->applyProcessTimeout();
            $heartbeat = $this->leaseHeartbeat($claim, $taskNo);
            // claim 后立即 CAS 续期；后续任何昂贵阶段开始前都已确认本 worker
            // 仍是当前租约持有者，旧 worker 不会继续生成可提交结果。
            $heartbeat(true);
            $effectiveDataScope = $this->normalizeEffectiveDataScope(
                $claim['effectiveDataScope'] ?? null,
                (string)($claim['page_code'] ?? '')
            );
            $context['visible_store_ids'] = $effectiveDataScope['visible_store_ids'];
            $context['all_stores'] = $context['visible_store_ids'] === null;
            $context['ancestor_organization_ids'] = $effectiveDataScope['ancestor_organization_ids'];
            $context['scope_dimensions'] = $effectiveDataScope['scope_dimensions'];
            $context['effective_data_scope'] = [
                'all_stores' => $context['all_stores'],
                'visible_store_ids' => $context['visible_store_ids'],
                'ancestor_organization_ids' => $context['ancestor_organization_ids'],
                'scope_dimensions' => $context['scope_dimensions'],
            ];
            $claimCutoffDate = trim((string)($claim['query_cutoff_date'] ?? ''));
            $claimDataAsOf = (int)($claim['data_as_of'] ?? 0);
            if (!$this->validDate($claimCutoffDate) || $claimDataAsOf <= 0) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_EXPORT_PLAN_INVALID',
                    '导出任务的冻结统计时点不合法。',
                    []
                );
            }
            $context['query_cutoff_date'] = $claimCutoffDate;
            $context['data_as_of'] = $claimDataAsOf;

            $plan = UnifiedQueryJson::decode((string)$claim['query_payload']);
            $taskPageCode = trim((string)($claim['page_code'] ?? ''));
            $planPageCode = trim((string)($plan['page_code'] ?? ''));
            if ($taskPageCode === '' || $planPageCode !== $taskPageCode) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_EXPORT_PLAN_INVALID',
                    '导出查询计划与页面不匹配。',
                    [
                        'task_page_code' => $taskPageCode,
                        'plan_page_code' => $planPageCode,
                    ]
                );
            }
            $provider = $this->providers->resolve($taskPageCode);
            if (!hash_equals($taskPageCode, $provider->pageCode())) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_EXPORT_PLAN_INVALID',
                    '导出查询服务与页面不匹配。',
                    ['page_code' => $taskPageCode]
                );
            }
            $fieldSnapshot = UnifiedQueryJson::decode((string)$claim['field_snapshot']);
            $this->tasks->preflightFrozenExecution(
                $context,
                $claim,
                $plan,
                $fieldSnapshot
            );
            $heartbeat(true);
            $fieldKeys = array_values(array_filter(array_map(function (array $field): string {
                return (string)($field['key'] ?? '');
            }, $fieldSnapshot)));
            $execution = $provider->executeFrozenPlan(
                $context,
                $plan,
                (string)$claim['export_scope'],
                $fieldKeys
            );
            $heartbeat(true);
            $includeSummary = !empty($claim['include_summary']);
            UnifiedQueryExportTaskServices::assertCellBudget(
                count($fieldSnapshot),
                count((array)$execution['exportRows']),
                $includeSummary
            );
            $storageKey = $this->writeXlsx(
                $taskNo,
                (string)$claim['workerToken'],
                $fieldSnapshot,
                (array)$execution['exportRows'],
                $includeSummary ? (array)$execution['summaries'] : [],
                $includeSummary,
                $heartbeat
            );
            $heartbeat(true);
            // 从此刻起 complete() 可能已在数据库提交、但调用方只收到连接异常。
            // catch 必须先核对权威任务行，不能直接删除可能已被成功任务引用的文件。
            $completionAttempted = true;
            $this->tasks->complete(
                (string)$claim['tenant_id'],
                $taskNo,
                count((array)$execution['exportRows']),
                $storageKey,
                time() + self::RETENTION_SECONDS,
                (string)$claim['workerToken']
            );
            return [
                'taskId' => $taskNo,
                'status' => 'succeeded',
                'rowCount' => count((array)$execution['exportRows']),
                'storageKey' => $storageKey,
            ];
        } catch (\Throwable $exception) {
            $completion = [
                'state' => 'not_applicable',
                'rowCount' => count((array)($execution['exportRows'] ?? [])),
            ];
            if ($completionAttempted && is_array($claim) && $storageKey !== '') {
                $completion = $this->completionState(
                    (string)$claim['tenant_id'],
                    $taskNo,
                    $storageKey,
                    (string)$claim['workerToken']
                );
                if ($completion['state'] === 'committed') {
                    return [
                        'taskId' => $taskNo,
                        'status' => 'succeeded',
                        'rowCount' => (int)$completion['rowCount'],
                        'storageKey' => $storageKey,
                        'recoveredAfterAmbiguousCommit' => true,
                    ];
                }
            }
            // 每个租约使用独立对象键；失租或完成 CAS 失败时只清理自己的文件。
            // complete 已发出但权威状态无法确认时保守保留，避免误删已提交结果。
            $mayDeleteOwnFile = !$completionAttempted
                || $completion['state'] === 'superseded'
                || $completion['state'] === 'stale_own_lease';
            if ($storageKey !== '' && $mayDeleteOwnFile) {
                try {
                    $orphan = $this->absolutePath($storageKey);
                    if (is_file($orphan)) {
                        @unlink($orphan);
                    }
                } catch (\Throwable $ignored) {
                    // 非法或尚未落盘的对象键无需再处理。
                }
            }
            $reason = $exception instanceof UnifiedQueryException
                ? $exception->getMessage()
                : '导出任务处理失败，请联系管理员。';
            try {
                if (is_array($claim) && !empty($claim['workerToken'])) {
                    $this->tasks->fail(
                        (string)$claim['tenant_id'],
                        $taskNo,
                        $reason,
                        (string)$claim['workerToken']
                    );
                } elseif ((string)($seed['status'] ?? '') === 'pending') {
                    $this->tasks->failPending(
                        (string)($seed['tenant_id'] ?? ''),
                        $taskNo,
                        $reason
                    );
                } elseif ((string)($seed['status'] ?? '') === 'running'
                    && (int)($seed['lease_expires_at'] ?? 0) > 0
                    && (int)($seed['lease_expires_at'] ?? 0) < time()) {
                    // currentContext() 在 claim 前因账号/员工停用而失败时，只有已失效
                    // 租约可被收口。严格 CAS 防止覆盖已被另一 worker 接管的任务。
                    $this->tasks->failExpiredRunning(
                        (string)($seed['tenant_id'] ?? ''),
                        $taskNo,
                        $reason
                    );
                }
            } catch (\Throwable $ignored) {
                // 租约已被新 worker 接管时禁止覆盖其状态。
            }
            return [
                'taskId' => $taskNo,
                'status' => 'failed',
                'reason' => $reason,
                // 供 CLI/监控按稳定业务码归类；页面只使用 reason，不暴露堆栈。
                'errorCode' => $exception instanceof UnifiedQueryException
                    ? $exception->getErrorCode()
                    : 'UNIFIED_QUERY_EXPORT_WORKER_FAILED',
                'diagnostic' => get_class($exception) . ': ' . $exception->getMessage(),
            ];
        }
    }

    /**
     * complete() 的提交结果可能因连接中断而对调用方不确定。
     *
     * committed：当前任务已成功且引用本租约对象；
     * superseded：另一租约已成功或已明确接管，可清理本租约孤儿文件；
     * stale_own_lease：同 token 的租约已到期，结果没有被任务行引用，可清理；
     * ambiguous：数据库不可读或仍可能是提交后的陈旧视图，禁止删除。
     */
    protected function completionState(
        string $tenantId,
        string $taskNo,
        string $storageKey,
        string $workerToken
    ): array {
        try {
            $task = Db::name(UnifiedQueryExportTaskServices::TABLE)
                ->where('tenant_id', $tenantId)
                ->where('task_no', $taskNo)
                ->field('status,storage_key,lease_token,lease_expires_at,result_count')
                ->find();
        } catch (\Throwable $ignored) {
            return ['state' => 'ambiguous', 'rowCount' => 0];
        }
        if (!$task) {
            return ['state' => 'ambiguous', 'rowCount' => 0];
        }
        $status = (string)($task['status'] ?? '');
        $persistedKey = (string)($task['storage_key'] ?? '');
        if ($status === 'succeeded' && hash_equals($persistedKey, $storageKey)) {
            return [
                'state' => 'committed',
                'rowCount' => max(0, (int)($task['result_count'] ?? 0)),
            ];
        }
        if ($status === 'succeeded' && $persistedKey !== $storageKey) {
            return ['state' => 'superseded', 'rowCount' => 0];
        }
        if ($status === 'running') {
            $persistedToken = (string)($task['lease_token'] ?? '');
            if ($persistedToken !== '' && !hash_equals($persistedToken, $workerToken)) {
                return ['state' => 'superseded', 'rowCount' => 0];
            }
            // 对象键包含 worker token；同一 token 的过期 running 行不可能引用此
            // 文件为成功结果，且新 worker 必会得到另一个 token，因此可安全删孤儿。
            if ($persistedToken !== ''
                && hash_equals($persistedToken, $workerToken)
                && (int)($task['lease_expires_at'] ?? 0) > 0
                && (int)$task['lease_expires_at'] < time()) {
                return ['state' => 'stale_own_lease', 'rowCount' => 0];
            }
        }
        if (in_array($status, ['pending', 'failed', 'blocked', 'expired'], true)) {
            return ['state' => 'superseded', 'rowCount' => 0];
        }
        return ['state' => 'ambiguous', 'rowCount' => 0];
    }

    public function absolutePath(string $storageKey): string
    {
        return $this->storage->absolutePath($storageKey);
    }

    protected function currentContext(array $task): array
    {
        $pageCode = trim((string)($task['page_code'] ?? ''));
        if ($pageCode === '') {
            throw $this->invalidWorkerContext('', '任务缺少 page_code');
        }
        $resolver = $this->contextResolvers->resolve($pageCode);
        if (!hash_equals($pageCode, $resolver->pageCode())) {
            throw $this->invalidWorkerContext($pageCode, 'resolver 页面不匹配');
        }
        return $this->normalizeWorkerContext(
            $task,
            $resolver->resolve($task),
            $pageCode
        );
    }

    protected function normalizeWorkerContext(
        array $task,
        array $rawContext,
        string $pageCode
    ): array {
        $taskTenantId = trim((string)($task['tenant_id'] ?? ''));
        $taskAccountId = (int)($task['account_id'] ?? 0);
        $taskOperatorId = (int)($task['operator_id'] ?? 0);
        if (!is_string($rawContext['tenant_id'] ?? null)
            || !is_int($rawContext['account_id'] ?? null)
            || !is_int($rawContext['operator_id'] ?? null)
            || !is_string($rawContext['page_code'] ?? null)) {
            throw $this->invalidWorkerContext($pageCode, '身份上下文字段类型不合法');
        }
        $contextTenantId = trim($rawContext['tenant_id']);
        $contextAccountId = $rawContext['account_id'];
        $operatorId = $rawContext['operator_id'];
        $contextPageCode = trim($rawContext['page_code']);
        if ($taskTenantId === '' || $taskAccountId <= 0 || $taskOperatorId <= 0
            || $contextTenantId === '' || $contextAccountId <= 0 || $operatorId <= 0
            || !hash_equals($taskTenantId, $contextTenantId)
            || $taskAccountId !== $contextAccountId
            || $taskOperatorId !== $operatorId
            || !hash_equals($pageCode, $contextPageCode)) {
            throw $this->invalidWorkerContext($pageCode, '任务与当前账号上下文不匹配');
        }

        $permissions = $this->normalizeStringList(
            $rawContext['permissions'] ?? null,
            $pageCode,
            'permissions',
            '/^(?:\*|[a-z][a-z0-9._:-]{1,127})$/D',
            512
        );
        if (in_array('*', $permissions, true)) {
            throw $this->invalidWorkerContext($pageCode, 'permissions 禁止通配符');
        }
        if (!in_array(UnifiedQueryAccessPolicy::PAGE_POLICY, $permissions, true)
            || !in_array(UnifiedQueryAccessPolicy::EXPORT, $permissions, true)) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_PERMISSION_REVOKED',
                '当前账号已无页面查询或导出权限，任务已停止。',
                ['page_code' => $pageCode]
            );
        }

        if (!array_key_exists('visible_store_ids', $rawContext)) {
            throw $this->invalidWorkerContext($pageCode, '缺少 visible_store_ids');
        }
        $visibleStoreIds = null;
        if ($rawContext['visible_store_ids'] !== null) {
            if (!is_array($rawContext['visible_store_ids'])
                || !$this->isList($rawContext['visible_store_ids'])
                || count($rawContext['visible_store_ids'])
                    > UnifiedQueryContextFactory::MAX_SCOPE_IDS) {
                throw $this->invalidWorkerContext($pageCode, 'visible_store_ids 不合法');
            }
            $visibleStoreIds = [];
            foreach ($rawContext['visible_store_ids'] as $storeId) {
                if (!is_int($storeId) || $storeId <= 0
                    || isset($visibleStoreIds[$storeId])) {
                    throw $this->invalidWorkerContext($pageCode, 'visible_store_ids 不合法');
                }
                $visibleStoreIds[$storeId] = $storeId;
            }
            $visibleStoreIds = array_values($visibleStoreIds);
            sort($visibleStoreIds, SORT_NUMERIC);
        }

        $ancestorOrganizationIds = $this->normalizeStringList(
            $rawContext['ancestor_organization_ids'] ?? null,
            $pageCode,
            'ancestor_organization_ids',
            '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D'
        );
        if (!is_string($rawContext['permission_version'] ?? null)) {
            throw $this->invalidWorkerContext($pageCode, 'permission_version 不合法');
        }
        $permissionVersion = trim($rawContext['permission_version']);
        if ($permissionVersion === '' || strlen($permissionVersion) > 128) {
            throw $this->invalidWorkerContext($pageCode, 'permission_version 不合法');
        }
        if (!is_array($rawContext['scope_dimensions'] ?? null)) {
            throw $this->invalidWorkerContext($pageCode, 'scope_dimensions 不合法');
        }
        try {
            $scopeDimensions = $this->contextResolvers->pageRegistry()
                ->normalizeScopeDimensions($pageCode, $rawContext['scope_dimensions']);
            $this->contextResolvers->pageRegistry()
                ->assertScopeDimensionsNotEmpty($pageCode, $scopeDimensions);
        } catch (UnifiedQueryException $exception) {
            throw $this->invalidWorkerContext(
                $pageCode,
                'scope_dimensions 不合法：' . $exception->getErrorCode()
            );
        }

        if (!is_string($rawContext['query_cutoff_date'] ?? null)
            || !is_int($rawContext['data_as_of'] ?? null)) {
            throw $this->invalidWorkerContext($pageCode, '统计时点类型不合法');
        }
        $cutoffDate = trim($rawContext['query_cutoff_date']);
        $dataAsOf = $rawContext['data_as_of'];
        if (!$this->validDate($cutoffDate) || $dataAsOf <= 0) {
            throw $this->invalidWorkerContext($pageCode, '统计时点不合法');
        }

        if (!is_int($rawContext['store_id'] ?? null)
            || (int)$rawContext['store_id'] < 0) {
            throw $this->invalidWorkerContext($pageCode, 'store_id 不合法');
        }
        $storeId = (int)$rawContext['store_id'];
        if (!is_string($rawContext['organization_id'] ?? null)) {
            throw $this->invalidWorkerContext($pageCode, 'organization_id 不合法');
        }
        $organizationId = trim($rawContext['organization_id']);
        if ($organizationId !== ''
            && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $organizationId)) {
            throw $this->invalidWorkerContext($pageCode, 'organization_id 不合法');
        }

        return [
            'tenant_id' => $contextTenantId,
            'account_id' => $contextAccountId,
            'operator_id' => $operatorId,
            'store_id' => $storeId,
            'organization_id' => $organizationId,
            'page_code' => $pageCode,
            'permissions' => $permissions,
            'visible_store_ids' => $visibleStoreIds,
            'all_stores' => $visibleStoreIds === null,
            'ancestor_organization_ids' => $ancestorOrganizationIds,
            'permission_version' => $permissionVersion,
            'scope_dimensions' => $scopeDimensions,
            'query_cutoff_date' => $cutoffDate,
            'data_as_of' => $dataAsOf,
        ];
    }

    protected function normalizeStringList(
        $value,
        string $pageCode,
        string $key,
        string $pattern,
        int $maxItems = UnifiedQueryContextFactory::MAX_SCOPE_IDS
    ): array {
        if (!is_array($value) || !$this->isList($value) || count($value) > $maxItems) {
            throw $this->invalidWorkerContext($pageCode, $key . ' 不合法');
        }
        $normalized = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw $this->invalidWorkerContext($pageCode, $key . ' 不合法');
            }
            $item = trim($item);
            $identity = 'value:' . $item;
            if ($item === '' || !preg_match($pattern, $item)
                || isset($normalized[$identity])) {
                throw $this->invalidWorkerContext($pageCode, $key . ' 不合法');
            }
            $normalized[$identity] = $item;
        }
        return array_values($normalized);
    }

    protected function normalizeEffectiveDataScope($rawScope, string $pageCode): array
    {
        if (!is_array($rawScope)
            || !array_key_exists('visible_store_ids', $rawScope)
            || !array_key_exists('ancestor_organization_ids', $rawScope)
            || !array_key_exists('scope_dimensions', $rawScope)) {
            throw $this->invalidWorkerContext($pageCode, '有效数据范围结构不完整');
        }
        $visibleStoreIds = null;
        if ($rawScope['visible_store_ids'] !== null) {
            if (!is_array($rawScope['visible_store_ids'])
                || !$this->isList($rawScope['visible_store_ids'])
                || count($rawScope['visible_store_ids'])
                    > UnifiedQueryContextFactory::MAX_SCOPE_IDS) {
                throw $this->invalidWorkerContext($pageCode, '有效门店范围不合法');
            }
            $visibleStoreIds = [];
            foreach ($rawScope['visible_store_ids'] as $storeId) {
                if (!is_int($storeId) || $storeId <= 0
                    || isset($visibleStoreIds[$storeId])) {
                    throw $this->invalidWorkerContext($pageCode, '有效门店范围不合法');
                }
                $visibleStoreIds[$storeId] = $storeId;
            }
            $visibleStoreIds = array_values($visibleStoreIds);
            sort($visibleStoreIds, SORT_NUMERIC);
        }
        $ancestorOrganizationIds = $this->normalizeStringList(
            $rawScope['ancestor_organization_ids'],
            $pageCode,
            '有效组织范围',
            '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D'
        );
        try {
            $scopeDimensions = $this->contextResolvers->pageRegistry()
                ->normalizeScopeDimensions($pageCode, $rawScope['scope_dimensions']);
            $this->contextResolvers->pageRegistry()
                ->assertScopeDimensionsNotEmpty($pageCode, $scopeDimensions);
        } catch (UnifiedQueryException $exception) {
            throw $this->invalidWorkerContext(
                $pageCode,
                '有效维度范围不合法：' . $exception->getErrorCode()
            );
        }
        return [
            'visible_store_ids' => $visibleStoreIds,
            'ancestor_organization_ids' => $ancestorOrganizationIds,
            'scope_dimensions' => $scopeDimensions,
        ];
    }

    protected function invalidWorkerContext(
        string $pageCode,
        string $reason
    ): UnifiedQueryException {
        return new UnifiedQueryException(
            'UNIFIED_QUERY_WORKER_CONTEXT_INVALID',
            '导出任务的当前权限上下文无效，任务已停止。',
            ['page_code' => $pageCode, 'reason' => $reason]
        );
    }

    protected function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        return $date !== false
            && ($errors === false
                || ((int)$errors['warning_count'] === 0
                    && (int)$errors['error_count'] === 0))
            && $date->format('Y-m-d') === $value;
    }

    protected function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * Worker 运行期的双保险：PHP 可执行时间上限阻止单任务无限占用进程，所有
     * 阶段边界和写表批次仍用墙钟检查及租约 CAS 续期。即使底层 I/O 卡住超过
     * 租约，complete()/fail() 的 token 条件也会拒绝旧 worker 回写。
     */
    protected function applyProcessTimeout(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(self::MAX_TASK_RUNTIME_SECONDS + 15);
        }
    }

    /**
     * @return callable(bool):void
     */
    protected function leaseHeartbeat(array $claim, string $taskNo): callable
    {
        $tenantId = (string)($claim['tenant_id'] ?? '');
        $workerToken = (string)($claim['workerToken'] ?? '');
        if ($tenantId === '' || !preg_match('/^[a-f0-9]{64}$/D', $workerToken)) {
            throw new \RuntimeException('导出 worker 租约信息不完整');
        }
        $startedAt = microtime(true);
        $lastRenewedAt = 0.0;
        return function (bool $force = false) use (
            $tenantId,
            $taskNo,
            $workerToken,
            $startedAt,
            &$lastRenewedAt
        ): void {
            $now = microtime(true);
            if ($now - $startedAt > self::MAX_TASK_RUNTIME_SECONDS) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_EXPORT_TIMEOUT',
                    '导出任务处理超时，请缩小查询范围后重试。',
                    ['max_seconds' => self::MAX_TASK_RUNTIME_SECONDS]
                );
            }
            if ($force || $lastRenewedAt <= 0.0
                || $now - $lastRenewedAt >= self::LEASE_RENEW_INTERVAL_SECONDS) {
                $this->tasks->renewLease(
                    $tenantId,
                    $taskNo,
                    $workerToken,
                    UnifiedQueryExportTaskServices::LEASE_SECONDS
                );
                $lastRenewedAt = $now;
            }
        };
    }

    protected function heartbeat(?callable $heartbeat, bool $force = false): void
    {
        if ($heartbeat !== null) {
            $heartbeat($force);
        }
    }

    protected function writeXlsx(
        string $taskNo,
        string $workerToken,
        array $fieldSnapshot,
        array $rows,
        array $summaries,
        $includeSummary = null,
        ?callable $heartbeat = null
    ): string {
        if (!preg_match('/^[a-f0-9]{64}$/D', $workerToken)) {
            throw new \RuntimeException('导出 worker 租约标识不合法');
        }
        $includeSummary = $includeSummary === null ? !empty($summaries) : (bool)$includeSummary;
        UnifiedQueryExportTaskServices::assertCellBudget(
            count($fieldSnapshot),
            count($rows),
            $includeSummary
        );
        $this->heartbeat($heartbeat, true);
        $datePath = date('Y/m');
        // 禁止不同租约写同一路径；只有 complete CAS 成功的对象键才会进入任务行。
        $objectName = $taskNo . '-' . $workerToken . '.xlsx';
        $storageKey = 'unified-query-exports/' . $datePath . '/' . $objectName;
        $root = rtrim((string)app()->getRuntimePath(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'unified-query-exports';
        $directory = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $datePath);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('无法创建导出运行时目录');
        }
        $path = $directory . DIRECTORY_SEPARATOR . $objectName;
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        $spreadsheet = new Spreadsheet();
        try {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('查询结果');
            foreach ($fieldSnapshot as $index => $field) {
                $column = Coordinate::stringFromColumnIndex($index + 1);
                $label = $this->text((string)($field['label'] ?? ($field['key'] ?? '')));
                $sheet->setCellValueExplicit($column . '1', $label, DataType::TYPE_STRING);
                $sheet->getColumnDimension($column)->setWidth($this->columnWidth($label));
            }
            foreach ($rows as $rowIndex => $row) {
                if ($rowIndex > 0 && $rowIndex % self::HEARTBEAT_ROW_INTERVAL === 0) {
                    $this->heartbeat($heartbeat);
                }
                $excelRow = $rowIndex + 2;
                foreach ($fieldSnapshot as $fieldIndex => $field) {
                    $column = Coordinate::stringFromColumnIndex($fieldIndex + 1);
                    $key = (string)($field['key'] ?? '');
                    $type = (string)($field['type'] ?? 'text');
                    $this->writeCell($sheet, $column . $excelRow, $row[$key] ?? null, $type);
                }
            }
            if ($summaries) {
                $summaryRow = count($rows) + 3;
                $sheet->setCellValueExplicit('A' . $summaryRow, '合计', DataType::TYPE_STRING);
                foreach ($fieldSnapshot as $fieldIndex => $field) {
                    $key = (string)($field['key'] ?? '');
                    $values = [];
                    foreach ($summaries as $summaryKey => $value) {
                        if (strpos((string)$summaryKey, $key . ':') === 0) {
                            $values[] = substr((string)$summaryKey, strlen($key) + 1)
                                . ': ' . (string)$value;
                        }
                    }
                    if ($values) {
                        $column = Coordinate::stringFromColumnIndex($fieldIndex + 1);
                        $sheet->setCellValueExplicit(
                            $column . $summaryRow,
                            $this->text(implode('; ', $values)),
                            DataType::TYPE_STRING
                        );
                    }
                }
            }
            $lastColumn = Coordinate::stringFromColumnIndex(count($fieldSnapshot));
            $sheet->setAutoFilter('A1:' . $lastColumn . '1');
            $sheet->freezePane('A2');
            $sheet->getStyle('A1:' . $lastColumn . '1')->getFont()->setBold(true);
            $this->heartbeat($heartbeat, true);
            (new Xlsx($spreadsheet))->save($temporary);
            $this->heartbeat($heartbeat, true);
            if (!is_file($temporary) || filesize($temporary) <= 0 || !rename($temporary, $path)) {
                throw new \RuntimeException('导出文件写入失败');
            }
            @chmod($path, 0600);
            return $storageKey;
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
            $spreadsheet->disconnectWorksheets();
        }
    }

    protected function writeCell($sheet, string $coordinate, $value, string $type): void
    {
        if ($value === null) {
            $sheet->setCellValueExplicit($coordinate, '', DataType::TYPE_STRING);
            return;
        }
        if ($type === 'boolean') {
            $sheet->setCellValueExplicit($coordinate, $value ? '是' : '否', DataType::TYPE_STRING);
            return;
        }
        if ($type === 'integer' && preg_match('/^-?\d+$/D', (string)$value)) {
            $numeric = (string)$value;
            if (!$this->canWriteExcelNumeric($numeric)) {
                $sheet->setCellValueExplicit($coordinate, $numeric, DataType::TYPE_STRING);
                return;
            }
            $sheet->setCellValueExplicit($coordinate, (float)$numeric, DataType::TYPE_NUMERIC);
            $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode('0');
            return;
        }
        if (in_array($type, ['amount', 'decimal'], true)
            && preg_match('/^-?\d+(?:\.\d+)?$/D', (string)$value)) {
            $numeric = (string)$value;
            // Excel 理论上最多保证 15 位有效数字，但 PhpSpreadsheet 会先经 PHP
            // float 再落入 XLSX。带小数的 15 位临界金额可能在这个中转步骤丢分，
            // 因此金额/小数只在更保守且可往返的范围写成数值，其余精确文本导出。
            if (!$this->canWriteExcelNumeric($numeric)) {
                $sheet->setCellValueExplicit($coordinate, $numeric, DataType::TYPE_STRING);
                return;
            }
            $sheet->setCellValueExplicit($coordinate, (float)$numeric, DataType::TYPE_NUMERIC);
            $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode(
                $type === 'amount' ? '#,##0.00' : '0.######'
            );
            return;
        }
        if (is_array($value)) {
            $value = implode('、', array_map('strval', $value));
        }
        $sheet->setCellValueExplicit(
            $coordinate,
            $this->text((string)$value),
            DataType::TYPE_STRING
        );
    }

    protected function canWriteExcelNumeric(string $value): bool
    {
        if (!preg_match('/^-?(\d+)(?:\.(\d+))?$/D', $value, $matches)) {
            return false;
        }
        $integer = ltrim((string)$matches[1], '0');
        $fraction = rtrim((string)($matches[2] ?? ''), '0');
        $significant = strlen($integer) + strlen($fraction);
        // 整数在 IEEE-754 的 15 位展示范围内仍能精确表示；有小数时为避免 PHP
        // float 的二进制中转在边界处把分位改写，保留一位安全余量。
        $limit = $fraction === '' ? 15 : 14;
        return max(1, $significant) <= $limit;
    }

    protected function text(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, 32767, 'UTF-8');
        }
        return substr($value, 0, 32767);
    }

    protected function columnWidth(string $label): int
    {
        $length = function_exists('mb_strlen') ? mb_strlen($label, 'UTF-8') : strlen($label);
        return max(12, min(32, $length * 2 + 4));
    }
}
