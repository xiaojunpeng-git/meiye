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
        $queryJson = UnifiedQueryJson::encode($queryPlan);
        $fieldSnapshot = [];
        // 过滤、排序、分组或合计里的自定义字段同样决定任务是否可执行。
        // 不能只冻结导出列，否则停用字段时会漏掉仍在 pending 的任务。
        $customVersions = $resolvedVersions;
        $aliasMap = $this->aliases->aliases($context, $pageCode);
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
        ]));
        $frozenScope = UnifiedQueryJson::encode([
            'all_stores' => !empty($context['all_stores']),
            'visible_store_ids' => $context['visible_store_ids'],
            'ancestor_organization_ids' => $context['ancestor_organization_ids'],
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
            $reservedCellCount
        ) {
            Db::name(self::TABLE)->insert([
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
            ]);
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
    public function claim(array $rawContext, string $taskNo): array
    {
        $context = $this->access->normalizeContext($rawContext);
        if (!$this->access->capabilityPermissions($context)['export']) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_FORBIDDEN',
                '当前账号已无导出权限，任务已停止。',
                []
            );
        }
        return Db::transaction(function () use ($context, $taskNo) {
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
            $now = time();
            $canReclaim = (string)$task['status'] === 'running'
                && (int)$task['lease_expires_at'] > 0
                && (int)$task['lease_expires_at'] < $now;
            if ((string)$task['status'] !== 'pending' && !$canReclaim) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_EXPORT_ALREADY_CLAIMED',
                    '该导出任务已被其他执行器处理。',
                    ['status' => $task['status']]
                );
            }
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
            $task['claimed'] = true;
            $task['workerToken'] = $leaseToken;
            $task['effectiveDataScope'] = $this->effectiveScope($context, $task);
            return $task;
        });
    }

    public function complete(
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
        $this->registry->page((string)$task['page_code']);
        return $this->present($task);
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
        $this->access->assertPageAccess($context);
        if (!$this->access->capabilityPermissions($context)['export']) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_FORBIDDEN',
                '你当前没有下载导出文件的权限。',
                []
            );
        }
        $task = Db::name(self::TABLE)
            ->where('tenant_id', $context['tenant_id'])
            ->where('account_id', $context['account_id'])
            ->where('task_no', $taskNo)
            ->find();
        if (!$task || (string)$task['status'] !== 'succeeded'
            || (int)$task['expires_at'] <= time()) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_DOWNLOAD_UNAVAILABLE',
                '导出文件尚未生成或已过期。',
                []
            );
        }
        $this->registry->page((string)$task['page_code']);
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

    protected function assertStorageKey(string $storageKey): void
    {
        if (!preg_match('#^unified-query-exports/[A-Za-z0-9/_-]{1,400}\.xlsx$#D', $storageKey)
            || strpos($storageKey, '..') !== false
            || strpos($storageKey, '//') !== false) {
            throw new \RuntimeException('导出对象键不在受控运行时目录');
        }
    }

    protected function effectiveScope(array $current, array $task): array
    {
        $frozen = UnifiedQueryJson::decode((string)$task['frozen_scope']);
        $frozenAll = !empty($frozen['all_stores']);
        $currentAll = !empty($current['all_stores']);
        $frozenStores = array_values(array_unique(array_map(
            'intval',
            (array)($frozen['visible_store_ids'] ?? [])
        )));
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
        ];
    }
}
