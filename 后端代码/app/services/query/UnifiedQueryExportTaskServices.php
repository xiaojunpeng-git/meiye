<?php

namespace app\services\query;

use think\facade\Db;

/**
 * 后台导出任务只保存受控查询合同、别名和字段版本快照，不保存 SQL。
 */
class UnifiedQueryExportTaskServices
{
    public const TABLE = 'unified_query_export_task';
    /**
     * PhpSpreadsheet 会把每个单元格保留在 PHP 进程内。250,000 是当前 10,000
     * 行执行窗口的四分之一，保留会员页约 20 列的常规全量导出，同时禁止原先
     * 1,000,000+ 单元格的无界内存放大。
     */
    public const MAX_EXPORT_CELLS = 250000;
    public const MAX_EXPORT_FIELDS = 100;
    public const MAX_EXPORT_ROWS = UnifiedQueryExecutionServices::MAX_SOURCE_ROWS;
    public const LEASE_SECONDS = 300;

    /** @var UnifiedQueryPageRegistry */
    protected $registry;

    /** @var UnifiedQueryCustomFieldServices */
    protected $customFields;

    /** @var UnifiedQueryFieldAliasServices */
    protected $aliases;

    /** @var UnifiedQueryFieldReferenceServices */
    protected $references;

    /** @var UnifiedQueryExecutionServices */
    protected $execution;

    /** @var UnifiedQueryPreferenceServices */
    protected $preferences;

    /** @var UnifiedQueryAccessPolicy */
    protected $access;

    protected $aiFence;
    private $sourceContractAvailable;

    /** Trusted runtime wiring only. Fence must verify instance/owner/Run/generation and
     * execute the callback under the same cancellation/publication exclusion lock.
     */
    public function setAiFence(callable $fence): void { $this->aiFence = $fence; }

    public function hasSourceContract(): bool
    {
        if ($this->sourceContractAvailable === null) {
            $fields = Db::name(self::TABLE)->getTableFields();
            $hasSource = in_array('source_type', $fields, true); $hasBinding = in_array('ai_binding', $fields, true);
            if ($hasSource !== $hasBinding) throw new \RuntimeException('EXPORT_SOURCE_SCHEMA_INCOMPLETE');
            $this->sourceContractAvailable = $hasSource && $hasBinding;
        }
        return $this->sourceContractAvailable;
    }

    public function assertExecutionPartition(array $task, string $partition): void
    {
        // Only the entire pre-migration schema is legacy; never infer a row's origin from null.
        $source = $this->sourceType($task);
        if (UnifiedQueryExportSourcePolicy::partitionForSource($source) !== $partition) {
            throw new UnifiedQueryException('UNIFIED_QUERY_EXPORT_PARTITION_MISMATCH', '导出任务不属于当前执行分区。', []);
        }
        if ($source === 'REPORT' && !empty($task['ai_binding'])) throw new \InvalidArgumentException('EXPORT_REPORT_AI_BINDING_FORBIDDEN');
    }

    public function withAiFence(array $task, string $phase, callable $action)
    {
        $source = $this->sourceType($task);
        if ($source !== 'AI') return $action();
        if (!is_callable($this->aiFence)) throw new UnifiedQueryException('UNIFIED_QUERY_AI_EXPORT_NOT_READY', 'AI 导出尚未就绪。', []);
        $binding = UnifiedQueryJson::decode((string)$task['ai_binding']);
        $called = false;
        $result = call_user_func($this->aiFence, $binding, $phase, function () use (&$called, $action) {
            if ($called) throw new \LogicException('AI_EXPORT_FENCE_REENTRY');
            $called = true;
            return $action();
        });
        if (!$called) throw new \LogicException('AI_EXPORT_FENCE_DID_NOT_EXECUTE');
        return $result;
    }

    private function sourceType(array $task): string
    {
        if (!$this->hasSourceContract()) {
            if (!empty($task['ai_binding']) || (isset($task['source_type']) && $task['source_type'] !== 'REPORT')) throw new \InvalidArgumentException('EXPORT_SOURCE_SCHEMA_REQUIRED');
            return 'REPORT';
        }
        $source = $task['source_type'] ?? null;
        UnifiedQueryExportSourcePolicy::partitionForSource($source);
        if ($source === 'REPORT' && !empty($task['ai_binding'])) throw new \InvalidArgumentException('EXPORT_REPORT_AI_BINDING_FORBIDDEN');
        return $source;
    }

    /** Return the original shared compiler plan; never repair either AI scope. */
    public function executablePlan(array $task): array
    {
        $plan = UnifiedQueryJson::decode((string)($task['query_payload'] ?? ''));
        if ($this->sourceType($task) === 'AI') {
            if (($task['export_scope'] ?? null) !== 'query' || ($plan['query']['export']['scope'] ?? null) !== 'query'
                || !is_array($plan['plan'] ?? null) || count($plan) !== 3
                || count($plan['query']) !== 1 || count($plan['query']['export']) !== 1
                || ($plan['page_code'] ?? null) !== ($plan['plan']['page_code'] ?? null)) throw new \InvalidArgumentException('EXPORT_AI_QUERY_SCOPE_REQUIRED');
            $plan = $plan['plan'];
        }
        return $plan;
    }

    private function lifecycleTask(string $tenantId, string $taskNo): array
    {
        $task = Db::name(self::TABLE)->where('tenant_id', $tenantId)->where('task_no', $taskNo)->find();
        if (!$task) throw new \RuntimeException('导出任务不存在');
        $this->sourceType($task);
        return $task;
    }

    private function assertAiExecution(array $task): void
    {
        if ($this->sourceType($task) !== 'AI') return;
        $binding = UnifiedQueryJson::decode((string)$task['ai_binding']);
        $normalized = $task; $normalized['ai_binding'] = $binding;
        UnifiedQueryExportSourcePolicy::assertClaimable($normalized, UnifiedQueryJson::decode((string)$task['query_payload']), 'AI_EXPORT', $binding, time());
    }

    public function __construct(
        UnifiedQueryPageRegistry $registry,
        UnifiedQueryCustomFieldServices $customFields,
        UnifiedQueryFieldAliasServices $aliases,
        UnifiedQueryFieldReferenceServices $references,
        UnifiedQueryExecutionServices $execution,
        UnifiedQueryPreferenceServices $preferences,
        UnifiedQueryAccessPolicy $access
    ) {
        $this->registry = $registry;
        $this->customFields = $customFields;
        $this->aliases = $aliases;
        $this->references = $references;
        $this->execution = $execution;
        $this->preferences = $preferences;
        $this->access = $access;
    }

    /**
     * 对齐 create-unified-query-export。
     */
    public function create(array $rawContext, array $payload): array
    {
        if (array_key_exists('source_type', $payload) || array_key_exists('ai_binding', $payload)) throw new \InvalidArgumentException('EXPORT_SOURCE_CLIENT_FORBIDDEN');
        return $this->createWithSource($rawContext, $payload, 'REPORT', []);
    }

    public function createAi(array $rawContext, array $payload, array $binding): array
    {
        if (!$this->hasSourceContract() || !is_callable($this->aiFence)) throw new UnifiedQueryException('UNIFIED_QUERY_AI_EXPORT_NOT_READY', 'AI 导出尚未就绪。', []);
        if (($payload['scope'] ?? null) !== 'query' || ($payload['query']['export']['scope'] ?? null) !== 'query'
            || array_key_exists('source_type', $payload) || array_key_exists('ai_binding', $payload)) throw new \InvalidArgumentException('EXPORT_AI_QUERY_SCOPE_REQUIRED');
        if (($payload['pageCode'] ?? ($payload['page_code'] ?? null)) !== metric\MetricReadViewExportProvider::PAGE_CODE || !empty($payload['includeSummary'])) throw new \InvalidArgumentException('EXPORT_AI_PAGE_INVALID');
        if (($rawContext['scope_dimensions']['metric_read_ref'] ?? null) !== [$binding['read_consistency_ref'] ?? null]) throw new \InvalidArgumentException('EXPORT_AI_READ_SCOPE_MISMATCH');
        $task = ['source_type' => 'AI', 'export_scope' => 'query', 'ai_binding' => $binding];
        UnifiedQueryExportSourcePolicy::assertClaimable($task, ['query' => $payload['query']], 'AI_EXPORT', $binding, time());
        if ((int)($rawContext['account_id'] ?? 0) !== $binding['account_id']) throw new \InvalidArgumentException('EXPORT_AI_OWNER_MISMATCH');
        $task['ai_binding'] = UnifiedQueryJson::encode($binding);
        return $this->withAiFence($task, 'create', function () use ($rawContext, $payload, $binding) { return $this->createWithSource($rawContext, $payload, 'AI', $binding); });
    }

    private function createWithSource(array $rawContext, array $payload, string $sourceType, array $aiBinding): array
    {
        $context = $this->access->normalizeContext($rawContext);
        $permissions = $this->access->capabilityPermissions($context);
        if (!$permissions['export']) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_FORBIDDEN',
                '你没有导出当前数据的权限。',
                []
            );
        }
        $pageCode = (string)($payload['pageCode'] ?? ($payload['page_code'] ?? ''));
        $this->registry->page($pageCode);
        $contextPageCode = $this->contextPageCode($context);
        if (!hash_equals($contextPageCode, $pageCode)) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_PAGE_MISMATCH',
                '导出任务页面与当前页面不一致，请返回原页面重试。',
                [
                    'context_page_code' => $contextPageCode,
                    'payload_page_code' => $pageCode,
                ]
            );
        }
        $scopeDimensions = $this->registry->normalizeScopeDimensions(
            $pageCode,
            $context['scope_dimensions'] ?? []
        );
        $this->registry->assertScopeDimensionsNotEmpty($pageCode, $scopeDimensions);
        if (isset($payload['schemaVersion'])
            && (string)$payload['schemaVersion'] !== $this->registry->schemaVersion()) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_SCHEMA_VERSION_CONFLICT',
                '查询结构版本已更新，请刷新页面后重试。',
                [
                    'expected' => $this->registry->schemaVersion(),
                    'received' => (string)$payload['schemaVersion'],
                ]
            );
        }
        $scope = (string)($payload['scope'] ?? 'query');
        if (!in_array($scope, ['query', 'page'], true)) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_SCOPE_INVALID',
                '请选择当前查询结果或当前页。',
                ['scope' => $scope]
            );
        }
        $cutoffDate = (string)($context['query_cutoff_date'] ?? '');
        if (!$this->validDate($cutoffDate)) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_CUTOFF_DATE_REQUIRED',
                '导出必须使用本次查询的截止日期，请刷新后重试。',
                []
            );
        }
        $receivedCutoffDate = (string)($payload['queryCutoffDate']
            ?? ($payload['query_cutoff_date'] ?? ''));
        if ($receivedCutoffDate !== '' && $receivedCutoffDate !== $cutoffDate) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_CUTOFF_DATE_CONFLICT',
                '查询截止日期已变化，请刷新后重试。',
                ['expected' => $cutoffDate, 'received' => $receivedCutoffDate]
            );
        }
        $query = $payload['query'] ?? [];
        if (!is_array($query)) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_QUERY_INVALID',
                '导出查询条件格式不正确。',
                []
            );
        }
        $this->assertNoExecutableText($query);
        $rawQueryJson = UnifiedQueryJson::encode($query);
        if (strlen($rawQueryJson) > 65535) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_QUERY_TOO_LARGE',
                '导出查询条件过多，请减少条件后重试。',
                []
            );
        }

        $systemFields = $this->registry->fieldsForCapability($pageCode, $context['permissions']);
        $customFields = $this->customFields->listVisible($context, $pageCode, false);
        $available = [];
        foreach (array_merge($systemFields, $customFields) as $field) {
            if (($field['status'] ?? 'active') === 'active'
                && in_array('export', (array)($field['allowedOperations'] ?? []), true)) {
                $available[(string)$field['key']] = $field;
            }
        }
        $selected = $payload['fields'] ?? [];
        if (!is_array($selected) || !$selected || count($selected) > self::MAX_EXPORT_FIELDS) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_FIELDS_INVALID',
                '请至少选择一个、最多选择 ' . self::MAX_EXPORT_FIELDS . ' 个导出字段。',
                []
            );
        }
        $selected = array_values(array_unique(array_map('strval', $selected)));
        $fieldKeyCollector = new UnifiedQueryCustomFieldKeyCollector();
        $referencedCustomKeys = array_values(array_unique(array_merge(
            $fieldKeyCollector->collectFromQuery($query),
            $fieldKeyCollector->collectFromFieldList($selected)
        )));
        $savedPreference = $this->preferences->load($context, $pageCode);
        $pinnedVersions = (array)($savedPreference['customFieldVersions'] ?? []);
        $resolvedVersions = [];
        $planDefinitions = [];
        foreach ($referencedCustomKeys as $customKey) {
            if (!isset($available[$customKey])) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_CUSTOM_FIELD_UNAVAILABLE',
                    '查询使用的自定义字段已不可用，请重新设置。',
                    ['field_key' => $customKey]
                );
            }
            // 忽略客户端 query.fieldVersions / customFieldVersions 自报值。
            $version = (int)($pinnedVersions[$customKey] ?? $available[$customKey]['version']);
            $snapshot = $this->customFields->versionDefinition(
                $context,
                $pageCode,
                $customKey,
                $version
            );
            $snapshot['page_code'] = $pageCode;
            $planDefinitions[] = $snapshot;
            $resolvedVersions[$customKey] = $version;
        }
        $planContext = $context;
        $planContext['query_cutoff_date'] = $cutoffDate;
        $queryForPlan = $query;
        unset($queryForPlan['fieldVersions']);
        $queryPlan = $this->execution->validatedPlan(
            $pageCode,
            $planDefinitions,
            $queryForPlan,
            $planContext
        );
        $queryJson = UnifiedQueryJson::encode($sourceType === 'AI'
            ? ['page_code' => $pageCode, 'query' => ['export' => ['scope' => 'query']], 'plan' => $queryPlan]
            : $queryPlan);
        $fieldSnapshot = [];
        // 过滤、排序、分组或合计里的自定义字段同样决定任务是否可执行。
        // 不能只冻结导出列，否则停用字段时会漏掉仍在 pending 的任务。
        $customVersions = $resolvedVersions;
        $aliasMap = $sourceType === 'AI' ? [] : $this->aliases->aliases($context, $pageCode);
        foreach ($selected as $fieldKey) {
            if (!isset($available[$fieldKey])) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_EXPORT_FIELD_FORBIDDEN',
                    '导出字段已不可用或超出权限，请重新选择。',
                    ['field_key' => $fieldKey]
                );
            }
            $field = $available[$fieldKey];
            $fieldVersion = isset($resolvedVersions[$fieldKey])
                ? (int)$resolvedVersions[$fieldKey]
                : (int)($field['version'] ?? 1);
            $fieldSnapshot[] = [
                'key' => $fieldKey,
                'label' => (string)($aliasMap[$fieldKey] ?? $field['label']),
                'type' => (string)$field['type'],
                'customFieldId' => $field['customFieldId'] ?? null,
                'version' => $fieldVersion,
            ];
        }
        $fileName = $this->fileName((string)($payload['fileName'] ?? ($payload['file_name'] ?? '')));
        $includeSummary = !empty($payload['includeSummary']);
        // 创建期不读取或计算业务数据：按已冻结 scope 的理论最大行数预留，避免排队
        // 后才发现 Xlsx 需要建立不可控工作簿。页面导出只预留已验证的分页上限。
        $maximumRows = $scope === 'page'
            ? (int)($queryPlan['pagination']['limit'] ?? 0)
            : self::MAX_EXPORT_ROWS;
        $reservedCellCount = self::assertCellBudget(
            count($fieldSnapshot),
            $maximumRows,
            $includeSummary
        );
        $taskNo = 'uqe_' . bin2hex(random_bytes(16));
        $now = time();
        $dataAsOf = (int)($context['data_as_of'] ?? $now);
        $permissionFingerprint = hash('sha256', UnifiedQueryJson::encode([
            'permissions' => $context['permissions'],
            'visible_store_ids' => $context['visible_store_ids'],
            'ancestor_organization_ids' => $context['ancestor_organization_ids'],
            'scope_dimensions' => $scopeDimensions,
        ]));
        $frozenScope = UnifiedQueryJson::encode([
            'all_stores' => !empty($context['all_stores']),
            'visible_store_ids' => $context['visible_store_ids'],
            'ancestor_organization_ids' => $context['ancestor_organization_ids'],
            'scope_dimensions' => $scopeDimensions,
        ]);

        return Db::transaction(function () use (
            $context,
            $pageCode,
            $scope,
            $cutoffDate,
            $queryJson,
            $fieldSnapshot,
            $aliasMap,
            $customVersions,
            $fileName,
            $taskNo,
            $now,
            $dataAsOf,
            $permissionFingerprint,
            $frozenScope,
            $includeSummary,
            $reservedCellCount,
            $sourceType,
            $aiBinding
        ) {
            $insert = [
                'task_no' => $taskNo,
                'tenant_id' => $context['tenant_id'],
                'account_id' => $context['account_id'],
                'operator_id' => $context['operator_id'],
                'origin_store_id' => $context['store_id'],
                'origin_organization_id' => $context['organization_id'],
                'page_code' => $pageCode,
                'export_scope' => $scope,
                'status' => 'pending',
                'query_payload' => $queryJson,
                'field_snapshot' => UnifiedQueryJson::encode($fieldSnapshot),
                'alias_snapshot' => UnifiedQueryJson::encode($aliasMap),
                'permission_fingerprint' => $permissionFingerprint,
                'permission_version' => substr(
                    (string)($context['permission_version'] ?? ''),
                    0,
                    128
                ),
                'frozen_scope' => $frozenScope,
                'query_cutoff_date' => $cutoffDate,
                'data_as_of' => $dataAsOf,
                'result_count' => 0,
                'include_summary' => $includeSummary ? 1 : 0,
                'file_name' => $fileName,
                'storage_key' => '',
                'error_reason' => '',
                'lease_token' => '',
                'lease_expires_at' => 0,
                'attempt_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
                'started_at' => 0,
                'completed_at' => 0,
                'expires_at' => 0,
            ];
            if ($this->hasSourceContract()) {
                $insert['source_type'] = $sourceType;
                $insert['ai_binding'] = $sourceType === 'AI' ? UnifiedQueryJson::encode($aiBinding) : '';
                if ($sourceType === 'AI') $insert['expires_at'] = $aiBinding['expires_at'];
            }
            Db::name(self::TABLE)->insert($insert);
            $this->references->register(
                $context['tenant_id'],
                'export_task',
                $taskNo,
                $customVersions,
                $cutoffDate
            );
            return [
                'taskId' => $taskNo,
                'status' => 'pending',
                'fileName' => $fileName,
                'fieldCount' => count($fieldSnapshot),
                // 任务结果页必须继续显示创建时的表头，不能跟随随后字段改名或停用而漂移。
                'frozenFields' => $this->presentFrozenFields($fieldSnapshot),
                'reservedCellCount' => $reservedCellCount,
                'queryCutoffDate' => $cutoffDate,
                'dataAsOf' => date('Y-m-d H:i:s', $dataAsOf),
            ];
        });
    }

    /**
     * Worker 领取前必须重新注入当前账号权限；权限减少时由实际查询再次取交集。
     */
    public function claim(array $rawContext, string $taskNo, string $partition = 'REPORT'): array
    {
        $task = Db::name(self::TABLE)->where('task_no', $taskNo)->find();
        if (!$task) throw new \RuntimeException('导出任务不存在');
        $this->assertExecutionPartition($task, $partition);
        return $this->withAiFence($task, 'claim', function () use ($rawContext, $taskNo, $partition) { return $this->claimInternal($rawContext, $taskNo, $partition); });
    }

    private function claimInternal(array $rawContext, string $taskNo, string $partition): array
    {
        $context = $this->access->normalizeContext($rawContext);
        if (!$this->access->capabilityPermissions($context)['export']) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_FORBIDDEN',
                '当前账号已无导出权限，任务已停止。',
                []
            );
        }
        $contextPageCode = $this->contextPageCode($context);
        return Db::transaction(function () use ($context, $contextPageCode, $taskNo, $partition) {
            $task = Db::name(self::TABLE)
                ->where('tenant_id', $context['tenant_id'])
                ->where('task_no', $taskNo)
                ->lock(true)
                ->find();
            if (!$task || (int)$task['account_id'] !== (int)$context['account_id']) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_EXPORT_NOT_FOUND',
                    '导出任务不存在或已不可见。',
                    []
                );
            }
            $this->assertTaskPage($contextPageCode, $task);
            $this->assertExecutionPartition($task, $partition);
            if (($task['source_type'] ?? '') === 'AI') {
                $binding = UnifiedQueryJson::decode((string)$task['ai_binding']);
                $normalizedTask = $task; $normalizedTask['ai_binding'] = $binding;
                UnifiedQueryExportSourcePolicy::assertClaimable($normalizedTask, UnifiedQueryJson::decode((string)$task['query_payload']), 'AI_EXPORT', $binding, time());
            }
            $now = time();
            $canReclaim = (string)$task['status'] === 'running'
                && (int)$task['lease_expires_at'] > 0
                && (int)$task['lease_expires_at'] < $now;
            if (($task['source_type'] ?? '') === 'AI') $canReclaim = false; // Lease expiry is not physical stop evidence.
            if ((string)$task['status'] !== 'pending' && !$canReclaim) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_EXPORT_ALREADY_CLAIMED',
                    '该导出任务已被其他执行器处理。',
                    ['status' => $task['status']]
                );
            }
            $effectiveScope = $this->effectiveScope($context, $task);
            $effectiveFrozenScope = UnifiedQueryJson::encode(
                $this->canonicalScope($effectiveScope)
            );
            if (($task['source_type'] ?? '') === 'AI' && !hash_equals(
                hash('sha256', UnifiedQueryJson::encode($this->normalizeFrozenScope($contextPageCode, $task['frozen_scope']))),
                hash('sha256', $effectiveFrozenScope)
            )) throw new UnifiedQueryException('UNIFIED_QUERY_EXPORT_DOWNLOAD_SCOPE_REVOKED', '当前权限已改变，原结果不能继续导出。', []);
            $leaseToken = hash('sha256', $taskNo . '|' . bin2hex(random_bytes(16)));
            $query = Db::name(self::TABLE)->where('id', (int)$task['id']);
            if ($canReclaim) {
                $query->where('status', 'running')->where('lease_expires_at', '<', $now);
            } else {
                $query->where('status', 'pending');
            }
            $updated = $query->update([
                'status' => 'running',
                'lease_token' => $leaseToken,
                'lease_expires_at' => $now + self::LEASE_SECONDS,
                'attempt_count' => (int)$task['attempt_count'] + 1,
                'frozen_scope' => $effectiveFrozenScope,
                'started_at' => $now,
                'updated_at' => $now,
            ]);
            if ((int)$updated !== 1) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_EXPORT_ALREADY_CLAIMED',
                    '该导出任务已被其他执行器处理。',
                    []
                );
            }
            $task['status'] = 'running';
            $task['lease_token'] = $leaseToken;
            $task['lease_expires_at'] = $now + self::LEASE_SECONDS;
            $task['attempt_count'] = (int)$task['attempt_count'] + 1;
            $task['frozen_scope'] = $effectiveFrozenScope;
            $task['claimed'] = true;
            $task['workerToken'] = $leaseToken;
            $task['effectiveDataScope'] = $effectiveScope;
            return $task;
        });
    }

    /**
     * Worker 在调用领域 provider 前，用当前权限重新验证冻结计划和字段快照。
     * 任务保存的是可追溯版本，不代表账号在执行时仍有权读取这些字段。
     */
    public function preflightFrozenExecution(
        array $rawContext,
        array $task,
        array $plan,
        array $fieldSnapshot
    ): void {
        try {
            $context = $this->access->normalizeContext($rawContext);
            $this->access->assertPageAccess($context);
            if (!$this->access->capabilityPermissions($context)['export']) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_EXPORT_FORBIDDEN',
                    '当前账号已无导出权限，任务已停止。',
                    []
                );
            }

            $pageCode = $this->contextPageCode($context);
            $this->assertTaskPage($pageCode, $task);
            if ((string)($plan['page_code'] ?? '') !== $pageCode) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_EXPORT_PLAN_INVALID',
                    '导出查询计划与页面不匹配。',
                    ['page_code' => $pageCode]
                );
            }
            if (!$this->isList($fieldSnapshot)
                || !$fieldSnapshot
                || count($fieldSnapshot) > self::MAX_EXPORT_FIELDS) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_EXPORT_FIELDS_INVALID',
                    '导出字段快照不合法。',
                    []
                );
            }

            $frozenDefinitions = $plan['custom_definitions'] ?? null;
            if (!is_array($frozenDefinitions) || !$this->isList($frozenDefinitions)) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_EXPORT_PLAN_INVALID',
                    '导出查询计划中的自定义字段定义不合法。',
                    []
                );
            }
            $currentDefinitions = $this->currentDefinitionsForFrozenPlan(
                $context,
                $pageCode,
                $frozenDefinitions
            );
            $canonicalPlan = $this->validatedFrozenPlan(
                $context,
                $pageCode,
                $plan,
                $currentDefinitions
            );
            $this->assertFrozenFieldSnapshot(
                $context,
                $pageCode,
                $fieldSnapshot,
                (array)$canonicalPlan['custom_definitions']
            );
        } catch (UnifiedQueryException $exception) {
            if ($exception->getErrorCode() === 'UNIFIED_QUERY_EXPORT_PREFLIGHT_FORBIDDEN') {
                throw $exception;
            }
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_PREFLIGHT_FORBIDDEN',
                '导出任务使用的字段、权限或查询规则已失效，请重新创建任务。',
                ['reason_code' => $exception->getErrorCode()]
            );
        }
    }

    /**
     * 只验证冻结导出列的身份与当前系统字段权限，不读取或计算业务数据。
     * 自定义字段使用调用方已经验证的冻结定义，成功文件因此不依赖字段当前状态。
     */
    public function assertFrozenFieldSnapshot(
        array $rawContext,
        string $pageCode,
        array $fieldSnapshot,
        array $customDefinitions
    ): void {
        $context = $this->access->normalizeContext($rawContext);
        $this->access->assertPageAccess($context);
        if (!$this->access->capabilityPermissions($context)['export']) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_FORBIDDEN',
                '当前账号已无导出权限。',
                []
            );
        }
        $this->registry->page($pageCode);
        if (!$this->isList($fieldSnapshot)
            || !$fieldSnapshot
            || count($fieldSnapshot) > self::MAX_EXPORT_FIELDS
            || !$this->isList($customDefinitions)) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_FIELDS_INVALID',
                '导出字段快照不合法。',
                []
            );
        }

        $customIndex = [];
        foreach ($customDefinitions as $definition) {
            if (!is_array($definition)) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_EXPORT_PLAN_INVALID',
                    '冻结自定义字段定义不合法。',
                    []
                );
            }
            $fieldKey = trim((string)($definition['field_key'] ?? ''));
            $version = (int)($definition['version'] ?? 0);
            $type = trim((string)($definition['return_type'] ?? ''));
            $operations = $definition['allowed_operations'] ?? null;
            if (!preg_match('/^cf_[a-f0-9]{20,40}$/D', $fieldKey)
                || $version <= 0
                || $type === ''
                || !is_array($operations)
                || !in_array('export', $operations, true)
                || isset($customIndex[$fieldKey])) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_EXPORT_PLAN_INVALID',
                    '冻结自定义字段身份或导出能力不合法。',
                    ['field_key' => $fieldKey]
                );
            }
            $customIndex[$fieldKey] = [
                'version' => $version,
                'type' => $type,
            ];
        }

        $seenFields = [];
        foreach ($fieldSnapshot as $field) {
            if (!is_array($field)) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_EXPORT_FIELDS_INVALID',
                    '导出字段快照不合法。',
                    []
                );
            }
            $fieldKey = trim((string)($field['key'] ?? ''));
            $fieldType = trim((string)($field['type'] ?? ''));
            $fieldVersion = (int)($field['version'] ?? 0);
            if ($fieldKey === '' || $fieldType === '' || $fieldVersion <= 0
                || isset($seenFields[$fieldKey])) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_EXPORT_FIELDS_INVALID',
                    '导出字段快照包含无效或重复字段。',
                    ['field_key' => $fieldKey]
                );
            }
            $seenFields[$fieldKey] = true;
            if (strpos($fieldKey, 'cf_') === 0) {
                if (!isset($customIndex[$fieldKey])
                    || $customIndex[$fieldKey]['version'] !== $fieldVersion
                    || $customIndex[$fieldKey]['type'] !== $fieldType) {
                    throw new UnifiedQueryException(
                        'UNIFIED_QUERY_EXPORT_FIELDS_INVALID',
                        '导出自定义字段快照与冻结版本不一致。',
                        ['field_key' => $fieldKey]
                    );
                }
                continue;
            }
            $definition = $this->registry->assertReadable(
                $pageCode,
                $fieldKey,
                $context['permissions']
            );
            if (!in_array('export', (array)$definition['allowedOperations'], true)
                || (string)$definition['type'] !== $fieldType
                || (int)$definition['version'] !== $fieldVersion) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_EXPORT_FIELD_FORBIDDEN',
                    '导出字段已不可用或超出权限。',
                    ['field_key' => $fieldKey]
                );
            }
        }
    }

    protected function currentDefinitionsForFrozenPlan(
        array $context,
        string $pageCode,
        array $frozenDefinitions
    ): array {
        $currentDefinitions = [];
        $seen = [];
        foreach ($frozenDefinitions as $definition) {
            if (!is_array($definition)) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_EXPORT_PLAN_INVALID',
                    '导出查询计划中的自定义字段定义不合法。',
                    []
                );
            }
            $fieldKey = trim((string)($definition['field_key'] ?? ''));
            $version = (int)($definition['version'] ?? 0);
            if (!preg_match('/^cf_[a-f0-9]{20,40}$/D', $fieldKey)
                || $version <= 0
                || isset($seen[$fieldKey])) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_EXPORT_PLAN_INVALID',
                    '导出查询计划中的自定义字段身份不合法。',
                    ['field_key' => $fieldKey]
                );
            }
            $seen[$fieldKey] = true;
            $current = $this->customFields->versionDefinition(
                $context,
                $pageCode,
                $fieldKey,
                $version
            );
            $current['page_code'] = $pageCode;
            $currentDefinitions[] = $current;
        }
        return $currentDefinitions;
    }

    protected function validatedFrozenPlan(
        array $context,
        string $pageCode,
        array $plan,
        array $customDefinitions
    ): array {
        if ((string)($plan['page_code'] ?? '') !== $pageCode) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_PLAN_INVALID',
                '导出查询计划与页面不匹配。',
                ['page_code' => $pageCode]
            );
        }
        $pagination = is_array($plan['pagination'] ?? null)
            ? $plan['pagination']
            : [];
        $domainScope = is_array($plan['domain_scope'] ?? null)
            ? $plan['domain_scope']
            : [];
        $query = [
            'pageCode' => $pageCode,
            'page' => (int)($pagination['page'] ?? 0),
            'limit' => (int)($pagination['limit'] ?? 0),
            'filters' => $plan['filters'] ?? null,
            'topFilterConditions' => $plan['top_filters'] ?? null,
            'keywordFilters' => $plan['keyword_filters'] ?? null,
            'quickFilters' => $plan['quick_filters'] ?? null,
            'filterRelation' => $plan['filter_relation'] ?? null,
            'sorts' => $plan['sorts'] ?? null,
            'groupBy' => $plan['groups'] ?? null,
            'summaries' => $plan['summaries'] ?? null,
            'dataScope' => $domainScope['data_scope'] ?? null,
            'businessStatus' => $domainScope['business_status'] ?? null,
            'visibleFields' => $plan['visible_fields'] ?? null,
            'queryCutoffDate' => $plan['query_cutoff_date'] ?? null,
        ];
        foreach ([
            'filters', 'topFilterConditions', 'keywordFilters', 'quickFilters',
            'sorts', 'groupBy', 'summaries', 'visibleFields',
        ] as $arrayKey) {
            if (!is_array($query[$arrayKey])) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_EXPORT_PLAN_INVALID',
                    '导出查询计划结构不完整。',
                    ['key' => $arrayKey]
                );
            }
        }
        $validationContext = $context;
        $validationContext['query_cutoff_date'] = (string)($plan['query_cutoff_date'] ?? '');
        $rebuilt = $this->execution->validatedPlan(
            $pageCode,
            $customDefinitions,
            $query,
            $validationContext
        );
        if (!hash_equals(
            hash('sha256', UnifiedQueryJson::encode($plan)),
            hash('sha256', UnifiedQueryJson::encode($rebuilt))
        )) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_PLAN_INVALID',
                '导出查询计划已失效，请重新创建任务。',
                ['page_code' => $pageCode]
            );
        }
        return $rebuilt;
    }

    public function complete(
        string $tenantId, string $taskNo, int $resultCount, string $storageKey, int $expiresAt, string $leaseToken
    ): void {
        $task = $this->lifecycleTask($tenantId, $taskNo);
        $this->withAiFence($task, 'complete', function () use ($task, $tenantId, $taskNo, $resultCount, $storageKey, $expiresAt, $leaseToken): void {
            $this->assertAiExecution($task);
            if ($this->sourceType($task) === 'AI') {
                $binding = UnifiedQueryJson::decode((string)$task['ai_binding']);
                $expiresAt = min($expiresAt, $binding['expires_at'], (int)$task['expires_at']);
                if ($expiresAt <= time()) throw new \RuntimeException('EXPORT_AI_EXPIRED');
            }
            $this->completeInternal($tenantId, $taskNo, $resultCount, $storageKey, $expiresAt, $leaseToken);
        });
    }

    private function completeInternal(
        string $tenantId,
        string $taskNo,
        int $resultCount,
        string $storageKey,
        int $expiresAt,
        string $leaseToken
    ): void {
        $this->assertStorageKey($storageKey);
        Db::transaction(function () use (
            $tenantId,
            $taskNo,
            $resultCount,
            $storageKey,
            $expiresAt,
            $leaseToken
        ) {
            $updated = Db::name(self::TABLE)
                ->where('tenant_id', $tenantId)
                ->where('task_no', $taskNo)
                ->where('status', 'running')
                ->where('lease_token', $leaseToken)
                ->where('lease_expires_at', '>=', time())
                ->update([
                    'status' => 'succeeded',
                    'result_count' => max(0, $resultCount),
                    'storage_key' => $storageKey,
                    'lease_token' => '',
                    'lease_expires_at' => 0,
                    'completed_at' => time(),
                    'expires_at' => $expiresAt,
                    'updated_at' => time(),
                ]);
            if ((int)$updated !== 1) {
                throw new \RuntimeException('导出任务状态不允许完成');
            }
            // 已生成文件保留冻结表头和结果，不再依赖当前字段继续存在。
            $this->references->release($tenantId, 'export_task', $taskNo);
        });
    }

    public function fail(
        string $tenantId, string $taskNo, string $reason, string $leaseToken
    ): void {
        $task=$this->lifecycleTask($tenantId,$taskNo);
        $this->withAiFence($task,'failure',function () use ($task,$tenantId,$taskNo,$reason,$leaseToken): void {
            $this->failInternal($tenantId,$taskNo,$this->sourceType($task)==='AI'?'AI_EXPORT_FAILED':$reason,$leaseToken);
        });
    }

    private function failInternal(
        string $tenantId,
        string $taskNo,
        string $reason,
        string $leaseToken
    ): void
    {
        Db::transaction(function () use ($tenantId, $taskNo, $reason, $leaseToken) {
            $updated = Db::name(self::TABLE)
                ->where('tenant_id', $tenantId)
                ->where('task_no', $taskNo)
                ->where('status', 'running')
                ->where('lease_token', $leaseToken)
                ->where('lease_expires_at', '>=', time())
                ->update([
                    'status' => 'failed',
                    'error_reason' => substr($reason, 0, 255),
                    'lease_token' => '',
                    'lease_expires_at' => 0,
                    'completed_at' => time(),
                    'updated_at' => time(),
                ]);
            if ((int)$updated !== 1) {
                throw new \RuntimeException('导出任务租约已失效，禁止旧 worker 回写失败');
            }
            $this->references->release($tenantId, 'export_task', $taskNo);
        });
    }

    public function failPending(string $tenantId, string $taskNo, string $reason): void
    {
        $task=$this->lifecycleTask($tenantId,$taskNo);
        $this->withAiFence($task,'failure',function () use ($task,$tenantId,$taskNo,$reason): void {
            $this->failPendingInternal($tenantId,$taskNo,$this->sourceType($task)==='AI'?'AI_EXPORT_FAILED':$reason);
        });
    }

    private function failPendingInternal(string $tenantId, string $taskNo, string $reason): void
    {
        Db::transaction(function () use ($tenantId, $taskNo, $reason) {
            $updated = Db::name(self::TABLE)
                ->where('tenant_id', $tenantId)
                ->where('task_no', $taskNo)
                ->where('status', 'pending')
                ->update([
                    'status' => 'failed',
                    'error_reason' => substr($reason, 0, 255),
                    'completed_at' => time(),
                    'updated_at' => time(),
                ]);
            if ((int)$updated === 1) {
                $this->references->release($tenantId, 'export_task', $taskNo);
            }
        });
    }

    /**
     * 仅允许收口已经失租的 running 任务。
     *
     * Worker 在 claim 前重建账号上下文；若账号已经停用，不能用 failPending()
     * 覆盖 running 行，也不能把仍被新 worker 持有的租约标为失败。这个 CAS 成功
     * 才释放 export_task 引用，失败说明任务已被接管或状态已变化，由调用方静默放弃。
     */
    public function failExpiredRunning(string $tenantId, string $taskNo, string $reason): bool
    {
        $task=$this->lifecycleTask($tenantId,$taskNo);
        if ($this->sourceType($task)==='AI') return false; // Unknown worker receipt remains fenced, not auto-reclaimed or silently failed.
        return Db::transaction(function () use ($tenantId, $taskNo, $reason): bool {
            $now = time();
            $updated = Db::name(self::TABLE)
                ->where('tenant_id', $tenantId)
                ->where('task_no', $taskNo)
                ->where('status', 'running')
                ->where('lease_expires_at', '>', 0)
                ->where('lease_expires_at', '<', $now)
                ->update([
                    'status' => 'failed',
                    'error_reason' => substr($reason, 0, 255),
                    'lease_token' => '',
                    'lease_expires_at' => 0,
                    'completed_at' => $now,
                    'updated_at' => $now,
                ]);
            if ((int)$updated !== 1) {
                return false;
            }
            $this->references->release($tenantId, 'export_task', $taskNo);
            return true;
        });
    }

    public function renewLease(
        string $tenantId, string $taskNo, string $leaseToken, int $seconds = 300
    ): int {
        $task = $this->lifecycleTask($tenantId, $taskNo);
        return $this->withAiFence($task, 'heartbeat', function () use ($task, $tenantId, $taskNo, $leaseToken, $seconds): int {
            $this->assertAiExecution($task);
            return $this->renewLeaseInternal($tenantId, $taskNo, $leaseToken, $seconds);
        });
    }

    private function renewLeaseInternal(
        string $tenantId,
        string $taskNo,
        string $leaseToken,
        int $seconds = 300
    ): int {
        $seconds = max(30, min(300, $seconds));
        $now = time();
        $updated = Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('task_no', $taskNo)
            ->where('status', 'running')
            ->where('lease_token', $leaseToken)
            ->where('lease_expires_at', '>=', $now)
            ->update([
                'lease_expires_at' => $now + $seconds,
                'updated_at' => $now,
            ]);
        if ((int)$updated !== 1) {
            // MySQL 默认只返回“发生变化的行数”。claim() 与首次 heartbeat 落在
            // 同一秒时，expires_at 本来就等于 now + seconds，UPDATE 合法但会返回 0。
            // 不能因此把仍由本 worker 持有的租约误判为失效；重新按完整 fencing
            // 条件读取一次，只有仍为当前 token 且未到期才接受这个 no-op。
            $unchangedExpiry = Db::name(self::TABLE)
                ->where('tenant_id', $tenantId)
                ->where('task_no', $taskNo)
                ->where('status', 'running')
                ->where('lease_token', $leaseToken)
                ->where('lease_expires_at', '>=', $now)
                ->value('lease_expires_at');
            if ($unchangedExpiry !== null && (int)$unchangedExpiry >= $now) {
                return (int)$unchangedExpiry;
            }
            throw new \RuntimeException('导出任务租约已失效');
        }
        return $now + $seconds;
    }

    /**
     * Excel 的内存占用按单元格而非仅按数据行增长。表头和请求的合计行也必须
     * 计入预算，避免 10,000 x 100 的任务绕过字段数或行数任一单独上限。
     */
    public static function assertCellBudget(
        int $fieldCount,
        int $rowCount,
        bool $includeSummary
    ): int {
        if ($fieldCount <= 0 || $fieldCount > self::MAX_EXPORT_FIELDS) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_FIELDS_INVALID',
                '导出字段快照不合法。',
                ['field_count' => $fieldCount, 'max_fields' => self::MAX_EXPORT_FIELDS]
            );
        }
        if ($rowCount < 0 || $rowCount > self::MAX_EXPORT_ROWS) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_ROW_LIMIT_EXCEEDED',
                '导出结果超过安全行数上限，请缩小查询范围。',
                ['row_count' => $rowCount, 'max_rows' => self::MAX_EXPORT_ROWS]
            );
        }
        // 一行表头；请求合计时即使当前值为空也预留一行，防止后续数据变化突破预算。
        $cellCount = $fieldCount * ($rowCount + 1 + ($includeSummary ? 1 : 0));
        if ($cellCount > self::MAX_EXPORT_CELLS) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_CELL_LIMIT_EXCEEDED',
                '当前导出字段和数据量组合过大，请减少字段、缩小查询范围或改为导出当前页。',
                [
                    'field_count' => $fieldCount,
                    'row_count' => $rowCount,
                    'include_summary' => $includeSummary,
                    'cell_count' => $cellCount,
                    'max_cells' => self::MAX_EXPORT_CELLS,
                ]
            );
        }
        return $cellCount;
    }

    public function findForAccount(array $rawContext, string $taskNo): array
    {
        $context = $this->access->normalizeContext($rawContext);
        $this->access->assertPageAccess($context);
        if (!$this->access->capabilityPermissions($context)['export']) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_FORBIDDEN',
                '你当前没有导出权限。',
                []
            );
        }
        $contextPageCode = $this->contextPageCode($context);
        $task = Db::name(self::TABLE)
            ->where('tenant_id', $context['tenant_id'])
            ->where('account_id', $context['account_id'])
            ->where('task_no', $taskNo)
            ->find();
        if (!$task) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_NOT_FOUND',
                '导出任务不存在或已不可见。',
                []
            );
        }
        $this->assertTaskPage($contextPageCode, $task);
        return $this->withAiFence($task, 'status', function () use ($task): array { return $this->present($task); });
    }

    /** Trusted Run controller only; a state change fences late complete, not proof of process exit. */
    public function cancelAiTask(string $tenantId, string $taskNo): bool
    {
        $task = $this->lifecycleTask($tenantId, $taskNo);
        if ($this->sourceType($task) !== 'AI') throw new \InvalidArgumentException('EXPORT_AI_SOURCE_REQUIRED');
        return $this->withAiFence($task, 'cancel', function () use ($tenantId, $taskNo): bool {
            return Db::transaction(function () use ($tenantId, $taskNo): bool {
                $changed = Db::name(self::TABLE)->where('tenant_id', $tenantId)->where('task_no', $taskNo)->where('source_type', 'AI')
                    ->whereIn('status', ['pending', 'running'])->update(['status'=>'cancelled', 'lease_token'=>'', 'lease_expires_at'=>0, 'completed_at'=>time(), 'updated_at'=>time()]);
                if ($changed) $this->references->release($tenantId, 'export_task', $taskNo);
                return (int)$changed === 1;
            });
        });
    }

    /** Clear every AI state at the original expiry. File deletion failures remain visible retries, never successful erasure. */
    public function cleanupAiExpired(int $limit, callable $deleteFiles): array
    {
        if (!$this->hasSourceContract()) return ['purged'=>0, 'retry'=>0];
        $tasks = Db::name(self::TABLE)->where('source_type', 'AI')->where(function ($q) {
            $q->whereBetween('expires_at',[1,time()])->whereOr('created_at','<=',time()-86400);
        })
            ->order('expires_at','asc')->limit(max(1,min(200,$limit)))->select()->toArray();
        $purged=0; $retry=0;
        foreach ($tasks as $task) {
            $id=(int)$task['id'];
            // Revoke first, then erase sensitive payload even when filesystem cleanup requires a retry.
            Db::transaction(function () use ($id, $task): void {
                Db::name(self::TABLE)->where('id',$id)->where('source_type','AI')->update([
                    'status'=>'expired', 'query_payload'=>'', 'field_snapshot'=>'', 'alias_snapshot'=>'', 'frozen_scope'=>'', 'ai_binding'=>'',
                    'permission_fingerprint'=>'', 'permission_version'=>'', 'account_id'=>0, 'operator_id'=>0, 'origin_store_id'=>0,
                    'origin_organization_id'=>0, 'file_name'=>'', 'error_reason'=>'', 'lease_token'=>'', 'lease_expires_at'=>0,
                    'result_count'=>0, 'query_cutoff_date'=>'1970-01-01', 'data_as_of'=>0, 'include_summary'=>0, 'updated_at'=>time(),
                ]);
                $this->references->release((string)$task['tenant_id'], 'export_task', (string)$task['task_no']);
            });
            try {
                if (call_user_func($deleteFiles,$task) !== true) throw new \RuntimeException('EXPORT_FILE_ERASURE_UNCONFIRMED');
                $purged += (int)Db::name(self::TABLE)->where('id',$id)->where('source_type','AI')->where('status','expired')->delete();
            } catch (\Throwable $e) { ++$retry; }
        }
        return ['purged'=>$purged, 'retry'=>$retry];
    }

    /**
     * 仅供已鉴权下载控制器取运行时对象键，不在轮询 projection 中暴露。
     */
    public function resolveDownload(array $rawContext, string $taskNo): string
    {
        return $this->resolveDownloadDescriptor($rawContext, $taskNo)['storageKey'];
    }

    /**
     * 下载控制器同时消费受控对象键与创建任务时冻结的用户文件名。
     */
    public function resolveDownloadDescriptor(array $rawContext, string $taskNo): array
    {
        $context = $this->access->normalizeContext($rawContext);
        $task = $this->lifecycleTask((string)$context['tenant_id'], $taskNo);
        if ((int)$task['account_id'] !== (int)$context['account_id']) throw new \RuntimeException('导出文件不可用');
        return $this->withAiFence($task, 'download', function () use ($rawContext, $taskNo): array {
            return $this->resolveDownloadDescriptorInternal($rawContext, $taskNo);
        });
    }

    private function resolveDownloadDescriptorInternal(array $rawContext, string $taskNo): array
    {
        $context = $this->access->normalizeContext($rawContext);
        $this->access->assertPageAccess($context);
        if (!$this->access->capabilityPermissions($context)['export']) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_FORBIDDEN',
                '你当前没有下载导出文件的权限。',
                []
            );
        }
        $contextPageCode = $this->contextPageCode($context);
        $task = Db::name(self::TABLE)
            ->where('tenant_id', $context['tenant_id'])
            ->where('account_id', $context['account_id'])
            ->where('task_no', $taskNo)
            ->find();
        if (!$task) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_DOWNLOAD_UNAVAILABLE',
                '导出文件尚未生成或已过期。',
                []
            );
        }
        $this->assertTaskPage($contextPageCode, $task);
        if ((string)$task['status'] !== 'succeeded'
            || (int)$task['expires_at'] <= time()) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_DOWNLOAD_UNAVAILABLE',
                '导出文件尚未生成或已过期。',
                []
            );
        }
        $this->assertDownloadScopeCurrent($context, $task);
        try {
            $plan = $this->executablePlan($task);
            $fieldSnapshot = UnifiedQueryJson::decode(
                (string)($task['field_snapshot'] ?? '')
            );
            $frozenDefinitions = $plan['custom_definitions'] ?? null;
            if (!is_array($frozenDefinitions) || !$this->isList($frozenDefinitions)) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_EXPORT_PLAN_INVALID',
                    '导出查询计划中的自定义字段定义不合法。',
                    []
                );
            }
            $canonicalPlan = $this->validatedFrozenPlan(
                $context,
                $contextPageCode,
                $plan,
                $frozenDefinitions
            );
            $this->assertFrozenFieldSnapshot(
                $context,
                $contextPageCode,
                $fieldSnapshot,
                (array)$canonicalPlan['custom_definitions']
            );
        } catch (UnifiedQueryException $exception) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_DOWNLOAD_FIELD_FORBIDDEN',
                '导出文件包含你当前无权读取的查询字段，无法下载。',
                ['reason_code' => $exception->getErrorCode()]
            );
        } catch (\Throwable $exception) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_DOWNLOAD_FIELD_FORBIDDEN',
                '导出文件的冻结字段合同已损坏，无法下载。',
                ['reason_code' => 'UNIFIED_QUERY_EXPORT_PLAN_INVALID']
            );
        }
        $storageKey = (string)$task['storage_key'];
        $this->assertStorageKey($storageKey);
        return [
            'storageKey' => $storageKey,
            'fileName' => $this->fileName((string)$task['file_name']),
        ];
    }

    public function exportCapability(array $permissions): array
    {
        return [
            'enabled' => !empty($permissions['export']),
            'allowCurrentQuery' => true,
            'allowCurrentPage' => true,
            'allowSummary' => true,
            'format' => 'xlsx',
            'formats' => ['xlsx'],
            'scopes' => ['query', 'page'],
            'maxFields' => self::MAX_EXPORT_FIELDS,
            'maxRows' => self::MAX_EXPORT_ROWS,
            'maxCells' => self::MAX_EXPORT_CELLS,
            'backgroundTask' => true,
        ];
    }

    protected function present(array $task): array
    {
        return [
            'taskId' => (string)$task['task_no'],
            'pageCode' => (string)$task['page_code'],
            'status' => (string)$task['status'],
            'fileName' => (string)$task['file_name'],
            'resultCount' => (int)$task['result_count'],
            'rowCount' => (int)$task['result_count'],
            'frozenFields' => $this->frozenFieldsFromTask($task),
            'errorReason' => (string)$task['error_reason'],
            'failureReason' => (string)$task['error_reason'],
            'downloadUrl' => '',
            'queryCutoffDate' => (string)$task['query_cutoff_date'],
            'dataAsOf' => date('Y-m-d H:i:s', (int)$task['data_as_of']),
            'createdAt' => date('Y-m-d H:i:s', (int)$task['created_at']),
            'completedAt' => (int)$task['completed_at'] > 0
                ? date('Y-m-d H:i:s', (int)$task['completed_at'])
                : '',
            'expiresAt' => (int)$task['expires_at'] > 0
                ? date('Y-m-d H:i:s', (int)$task['expires_at'])
                : '',
            'downloadAvailable' => (string)$task['status'] === 'succeeded'
                && (int)$task['expires_at'] > time(),
        ];
    }

    /**
     * 轮询任务只向创建人返回下载文件所需的冻结表头，不回传查询条件、权限快照或
     * 内部字段 ID。旧任务或坏快照安全降级为空数组，前端须明确提示不可回显表头。
     */
    protected function frozenFieldsFromTask(array $task): array
    {
        try {
            return $this->presentFrozenFields(UnifiedQueryJson::decode(
                (string)($task['field_snapshot'] ?? '')
            ));
        } catch (\Throwable $ignored) {
            return [];
        }
    }

    /**
     * @param array<int,array<string,mixed>> $fields
     * @return array<int,array<string,mixed>>
     */
    protected function presentFrozenFields(array $fields): array
    {
        $presented = [];
        foreach ($fields as $field) {
            if (!is_array($field) || count($presented) >= self::MAX_EXPORT_FIELDS) {
                continue;
            }
            $key = trim((string)($field['key'] ?? ''));
            $label = trim((string)($field['label'] ?? ''));
            $type = trim((string)($field['type'] ?? 'text'));
            if ($key === '' || $label === '') {
                continue;
            }
            $presented[] = [
                'key' => $key,
                'label' => $label,
                'type' => $type === '' ? 'text' : $type,
                'version' => max(0, (int)($field['version'] ?? 0)),
            ];
        }
        return $presented;
    }

    protected function assertNoExecutableText(array $value, int $depth = 0): void
    {
        if ($depth > 12) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_QUERY_TOO_COMPLEX',
                '导出查询条件过于复杂。',
                []
            );
        }
        $forbiddenKeys = ['sql', 'script', 'javascript', 'php', 'formula', 'excelformula', 'raw'];
        foreach ($value as $key => $item) {
            if (in_array(strtolower((string)$key), $forbiddenKeys, true)) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_EXECUTABLE_TEXT_FORBIDDEN',
                    '查询条件不能包含脚本或公式。',
                    ['key' => $key]
                );
            }
            if (is_array($item)) {
                $this->assertNoExecutableText($item, $depth + 1);
            }
        }
    }

    protected function fileName(string $fileName): string
    {
        $fileName = trim($fileName);
        if ($fileName === '') {
            $fileName = '查询结果_' . date('Ymd');
        }
        $fileName = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', '_', $fileName);
        $fileName = preg_replace('/\.xlsx$/i', '', $fileName);
        $fileName = str_replace('..', '_', $fileName);
        $fileName = trim($fileName, " .\t\n\r\0\x0B");
        if ($fileName === '') {
            $fileName = '查询结果_' . date('Ymd');
        }
        $length = function_exists('mb_strlen') ? mb_strlen($fileName, 'UTF-8') : strlen($fileName);
        if ($length > 60) {
            $fileName = function_exists('mb_substr')
                ? mb_substr($fileName, 0, 60, 'UTF-8')
                : substr($fileName, 0, 60);
        }
        return $fileName . '.xlsx';
    }

    protected function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        return $date !== false
            && ($errors === false || ((int)$errors['warning_count'] === 0 && (int)$errors['error_count'] === 0))
            && $date->format('Y-m-d') === $value;
    }

    protected function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    protected function assertStorageKey(string $storageKey): void
    {
        if (!preg_match('#^unified-query-exports/[A-Za-z0-9/_-]{1,400}\.xlsx$#D', $storageKey)
            || strpos($storageKey, '..') !== false
            || strpos($storageKey, '//') !== false) {
            throw new \RuntimeException('导出对象键不在受控运行时目录');
        }
    }

    protected function contextPageCode(array $context): string
    {
        $pageCode = trim((string)($context['page_code'] ?? ''));
        if ($pageCode === '') {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_PAGE_MISMATCH',
                '导出任务页面与当前页面不一致，请返回原页面重试。',
                ['context_page_code' => '']
            );
        }
        $this->registry->page($pageCode);
        return $pageCode;
    }

    protected function assertTaskPage(string $contextPageCode, array $task): void
    {
        $taskPageCode = trim((string)($task['page_code'] ?? ''));
        if ($taskPageCode === '' || !hash_equals($contextPageCode, $taskPageCode)) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_PAGE_MISMATCH',
                '导出任务页面与当前页面不一致，请返回原页面重试。',
                [
                    'context_page_code' => $contextPageCode,
                    'task_page_code' => $taskPageCode,
                ]
            );
        }
        $this->registry->page($taskPageCode);
    }

    protected function effectiveScope(array $current, array $task): array
    {
        $pageCode = trim((string)($task['page_code'] ?? ''));
        $frozen = $this->normalizeFrozenScope($pageCode, $task['frozen_scope'] ?? null);
        $currentDimensions = $this->registry->normalizeScopeDimensions(
            $pageCode,
            $current['scope_dimensions'] ?? []
        );
        $frozenDimensions = $this->registry->normalizeScopeDimensions(
            $pageCode,
            $frozen['scope_dimensions'] ?? []
        );
        $effectiveDimensions = [];
        foreach ((array)$this->registry->page($pageCode)['scopeDimensions'] as $dimension) {
            $currentValues = $currentDimensions[$dimension];
            $frozenValues = $frozenDimensions[$dimension];
            if ($currentValues === null && $frozenValues === null) {
                $effectiveDimensions[$dimension] = null;
            } elseif ($currentValues === null) {
                $effectiveDimensions[$dimension] = $frozenValues;
            } elseif ($frozenValues === null) {
                $effectiveDimensions[$dimension] = $currentValues;
            } else {
                $effectiveDimensions[$dimension] = array_values(array_intersect(
                    $frozenValues,
                    $currentValues
                ));
            }
        }
        $this->registry->assertScopeDimensionsNotEmpty($pageCode, $effectiveDimensions);
        $frozenAll = $frozen['all_stores'];
        $currentAll = !empty($current['all_stores']);
        $frozenStores = $frozen['visible_store_ids'];
        $currentStores = array_values(array_unique(array_map(
            'intval',
            (array)($current['visible_store_ids'] ?? [])
        )));
        if ($frozenAll && $currentAll) {
            $effectiveStores = null;
        } elseif ($frozenAll) {
            $effectiveStores = $currentStores;
        } elseif ($currentAll) {
            $effectiveStores = $frozenStores;
        } else {
            $effectiveStores = array_values(array_intersect($frozenStores, $currentStores));
        }
        $effectiveOrganizations = array_values(array_intersect(
            array_map('strval', (array)($frozen['ancestor_organization_ids'] ?? [])),
            array_map('strval', (array)($current['ancestor_organization_ids'] ?? []))
        ));
        return [
            'visible_store_ids' => $effectiveStores,
            'ancestor_organization_ids' => $effectiveOrganizations,
            'origin_store_id' => (int)$task['origin_store_id'],
            'origin_organization_id' => (string)$task['origin_organization_id'],
            'current_permission_version' => (string)($current['permission_version'] ?? ''),
            'frozen_permission_version' => (string)$task['permission_version'],
            'scope_dimensions' => $effectiveDimensions,
        ];
    }

    protected function canonicalScope(array $effectiveScope): array
    {
        $visibleStoreIds = $effectiveScope['visible_store_ids'] ?? null;
        return [
            'all_stores' => $visibleStoreIds === null,
            // 现有数据库合同用 [] 表示 all_stores=true 时无需枚举门店。
            'visible_store_ids' => $visibleStoreIds === null
                ? []
                : array_values($visibleStoreIds),
            'ancestor_organization_ids' => array_values(
                (array)($effectiveScope['ancestor_organization_ids'] ?? [])
            ),
            'scope_dimensions' => (array)($effectiveScope['scope_dimensions'] ?? []),
        ];
    }

    protected function assertDownloadScopeCurrent(array $context, array $task): void
    {
        $pageCode = trim((string)($task['page_code'] ?? ''));
        $frozen = $this->normalizeFrozenScope(
            $pageCode,
            $task['frozen_scope'] ?? null
        );
        $effective = $this->canonicalScope($this->effectiveScope($context, $task));
        if (!hash_equals(
            hash('sha256', UnifiedQueryJson::encode($frozen)),
            hash('sha256', UnifiedQueryJson::encode($effective))
        )) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_DOWNLOAD_SCOPE_REVOKED',
                '当前数据权限已缩小，无法下载此前生成的导出文件。',
                ['page_code' => $pageCode]
            );
        }
    }

    protected function normalizeFrozenScope(string $pageCode, $rawScope): array
    {
        try {
            if (!is_string($rawScope) || $rawScope === '') {
                throw new \InvalidArgumentException('冻结范围不是 JSON 字符串');
            }
            $scope = UnifiedQueryJson::decode($rawScope);
            $currentKeys = [
                'all_stores',
                'ancestor_organization_ids',
                'scope_dimensions',
                'visible_store_ids',
            ];
            $legacyKeys = [
                'all_stores',
                'ancestor_organization_ids',
                'visible_store_ids',
            ];
            $actualKeys = array_keys($scope);
            sort($actualKeys, SORT_STRING);
            if ($actualKeys === $legacyKeys) {
                if ((array)$this->registry->page($pageCode)['scopeDimensions'] !== []) {
                    throw new \InvalidArgumentException(
                        '声明维度的页面不能使用旧三键冻结范围'
                    );
                }
                $scope['scope_dimensions'] = [];
            } elseif ($actualKeys !== $currentKeys) {
                throw new \InvalidArgumentException('冻结范围键不合法');
            }
            if (!is_bool($scope['all_stores'])) {
                throw new \InvalidArgumentException('冻结范围键或 all_stores 类型不合法');
            }
            if (!is_array($scope['visible_store_ids'])
                || !$this->isList($scope['visible_store_ids'])
                || count($scope['visible_store_ids']) > UnifiedQueryContextFactory::MAX_SCOPE_IDS) {
                throw new \InvalidArgumentException('冻结门店范围不是有界列表');
            }
            if ($scope['all_stores'] && $scope['visible_store_ids'] !== []) {
                throw new \InvalidArgumentException('全部门店范围必须使用空门店列表');
            }
            $stores = [];
            foreach ($scope['visible_store_ids'] as $storeId) {
                if (!is_int($storeId) || $storeId <= 0 || isset($stores[$storeId])) {
                    throw new \InvalidArgumentException('冻结门店范围包含非法或重复标识');
                }
                $stores[$storeId] = $storeId;
            }
            $stores = array_values($stores);
            sort($stores, SORT_NUMERIC);

            if (!is_array($scope['ancestor_organization_ids'])
                || !$this->isList($scope['ancestor_organization_ids'])
                || count($scope['ancestor_organization_ids'])
                    > UnifiedQueryContextFactory::MAX_SCOPE_IDS) {
                throw new \InvalidArgumentException('冻结组织范围不是有界列表');
            }
            $organizations = [];
            foreach ($scope['ancestor_organization_ids'] as $organizationId) {
                $identity = is_string($organizationId)
                    ? 'value:' . $organizationId
                    : '';
                if (!is_string($organizationId)
                    || !preg_match(
                        '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D',
                        $organizationId
                    )
                    || isset($organizations[$identity])) {
                    throw new \InvalidArgumentException('冻结组织范围包含非法或重复标识');
                }
                $organizations[$identity] = $organizationId;
            }
            $organizations = array_values($organizations);
            sort($organizations, SORT_STRING);

            $scopeDimensions = $this->registry->normalizeScopeDimensions(
                $pageCode,
                $scope['scope_dimensions']
            );
            $this->registry->assertScopeDimensionsNotEmpty($pageCode, $scopeDimensions);
            return [
                'all_stores' => $scope['all_stores'],
                'visible_store_ids' => $stores,
                'ancestor_organization_ids' => $organizations,
                'scope_dimensions' => $scopeDimensions,
            ];
        } catch (\Throwable $exception) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_FROZEN_SCOPE_INVALID',
                '导出任务的数据权限快照已损坏，任务已停止。',
                ['page_code' => $pageCode]
            );
        }
    }
}
