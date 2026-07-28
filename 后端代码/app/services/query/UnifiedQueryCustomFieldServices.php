<?php

namespace app\services\query;

use think\facade\Db;

/**
 * 自定义字段稳定身份、版本、范围与生命周期。
 */
class UnifiedQueryCustomFieldServices
{
    public const TABLE = 'unified_query_custom_field';
    public const VERSION_TABLE = 'unified_query_custom_field_version';

    /** @var UnifiedQueryPageRegistry */
    protected $registry;

    /** @var StructuredExpressionValidator */
    protected $validator;

    /** @var UnifiedQueryAccessPolicy */
    protected $access;

    /** @var UnifiedQueryFieldReferenceServices */
    protected $references;

    public function __construct(
        UnifiedQueryPageRegistry $registry,
        StructuredExpressionValidator $validator,
        UnifiedQueryAccessPolicy $access,
        UnifiedQueryFieldReferenceServices $references
    ) {
        $this->registry = $registry;
        $this->validator = $validator;
        $this->access = $access;
        $this->references = $references;
    }

    /**
     * 对齐 save-unified-query-custom-field。
     */
    public function save(array $rawContext, array $payload): array
    {
        $context = $this->access->normalizeContext($rawContext);
        $this->access->assertPageAccess($context);
        $pageCode = (string)($payload['pageCode'] ?? ($payload['page_code'] ?? ''));
        $this->registry->page($pageCode);
        $name = $this->normalizeName((string)($payload['name'] ?? ''));
        $returnType = UnifiedQueryPageRegistry::normalizeType(
            (string)($payload['returnType'] ?? ($payload['return_type'] ?? ''))
        );
        $expression = $payload['expression'] ?? ($payload['rule'] ?? null);
        if (!is_array($expression)) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPRESSION_INVALID',
                '请配置计算规则后再保存。',
                []
            );
        }
        $validated = $this->validator->validate(
            $pageCode,
            $expression,
            $returnType,
            $context['permissions']
        );
        $fieldKey = trim((string)($payload['fieldKey']
            ?? ($payload['field_key'] ?? ($payload['id'] ?? ''))));
        if ($fieldKey === '') {
            return $this->create($context, $payload, $pageCode, $name, $validated);
        }
        return $this->update($context, $payload, $fieldKey, $pageCode, $name, $validated);
    }

    /**
     * 对齐 change-unified-query-custom-field-status。
     */
    public function changeStatus(array $rawContext, array $payload): array
    {
        $context = $this->access->normalizeContext($rawContext);
        $fieldKey = trim((string)($payload['fieldKey'] ?? ($payload['field_key'] ?? '')));
        $expectedVersion = (int)($payload['expectedVersion'] ?? ($payload['expected_version'] ?? 0));
        $targetStatus = (string)($payload['status'] ?? '');
        if (!in_array($targetStatus, ['active', 'inactive'], true) || $expectedVersion <= 0) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_STATUS_INVALID',
                '字段状态或版本无效，请刷新后重试。',
                []
            );
        }
        return Db::transaction(function () use ($context, $fieldKey, $expectedVersion, $targetStatus) {
            $row = $this->lockField($context['tenant_id'], $fieldKey);
            $operation = $targetStatus === 'active' ? 'activate' : 'deactivate';
            $this->access->assertCanManageRow($context, $row, $operation);
            $this->assertVersion($row, $expectedVersion);
            if ((string)$row['status'] === 'archived') {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_FIELD_ARCHIVED',
                    '该字段已归档，不能再更改状态。',
                    ['field_key' => $fieldKey]
                );
            }
            if ((string)$row['status'] === $targetStatus) {
                return $this->present($row);
            }
            $newVersion = $expectedVersion + 1;
            $updated = Db::name(self::TABLE)
                ->where('id', (int)$row['id'])
                ->where('current_version', $expectedVersion)
                ->update([
                    'status' => $targetStatus,
                    'invalid_reason' => '',
                    'current_version' => $newVersion,
                    'updated_by' => $context['operator_id'],
                    'updated_at' => time(),
                ]);
            if ((int)$updated !== 1) {
                throw $this->versionConflict($expectedVersion);
            }
            $row['status'] = $targetStatus;
            $row['invalid_reason'] = '';
            $row['current_version'] = $newVersion;
            $this->insertVersionFromPrevious($row, $newVersion, $context['operator_id']);
            if ($targetStatus === 'inactive') {
                $this->references->invalidate(
                    $context['tenant_id'],
                    $fieldKey,
                    '字段已停用，请移除、替换或恢复字段后再执行。'
                );
            } else {
                $this->references->restoreAsUpgradeAvailable($context['tenant_id'], $fieldKey);
            }
            return $this->present($row);
        });
    }

    /**
     * 对齐 archive-unified-query-custom-field。永远只做可追溯归档。
     */
    public function archive(array $rawContext, array $payload): array
    {
        $context = $this->access->normalizeContext($rawContext);
        $fieldKey = trim((string)($payload['fieldKey'] ?? ($payload['field_key'] ?? '')));
        $expectedVersion = (int)($payload['expectedVersion'] ?? ($payload['expected_version'] ?? 0));
        return Db::transaction(function () use ($context, $fieldKey, $expectedVersion) {
            $row = $this->lockField($context['tenant_id'], $fieldKey);
            $this->access->assertCanManageRow($context, $row, 'archive');
            $this->assertVersion($row, $expectedVersion);
            $references = $this->references->blockingCount($context['tenant_id'], $fieldKey);
            if ($references > 0) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_FIELD_STILL_REFERENCED',
                    '该字段仍被已保存查询、报表或导出任务使用，只能先停用。',
                    ['field_key' => $fieldKey, 'reference_count' => $references]
                );
            }
            $newVersion = $expectedVersion + 1;
            $updated = Db::name(self::TABLE)
                ->where('id', (int)$row['id'])
                ->where('current_version', $expectedVersion)
                ->update([
                    'status' => 'archived',
                    'active_name_key' => hash('sha256', 'archived|' . $fieldKey . '|' . $newVersion),
                    'current_version' => $newVersion,
                    'archived_at' => time(),
                    'updated_by' => $context['operator_id'],
                    'updated_at' => time(),
                ]);
            if ((int)$updated !== 1) {
                throw $this->versionConflict($expectedVersion);
            }
            $row['status'] = 'archived';
            $row['current_version'] = $newVersion;
            $this->insertVersionFromPrevious($row, $newVersion, $context['operator_id']);
            return $this->present($row);
        });
    }

    public function listVisible(array $rawContext, string $pageCode, bool $includeInactive = true): array
    {
        $context = $this->access->normalizeContext($rawContext);
        $this->access->assertPageAccess($context);
        $this->registry->page($pageCode);
        $rows = Db::name(self::TABLE)
            ->alias('f')
            ->join(
                'unified_query_custom_field_version v',
                'v.custom_field_id=f.id AND v.version=f.current_version'
            )
            ->where('f.tenant_id', $context['tenant_id'])
            ->where('f.page_code', $pageCode)
            ->where('f.status', '<>', 'archived')
            ->field('f.*,v.expression,v.referenced_fields,v.referenced_field_contract,v.required_permissions')
            ->order('f.visibility asc,f.updated_at desc,f.id desc')
            ->select()
            ->toArray();
        $historyMap = $this->versionHistoryMap(array_map(function (array $row): int {
            return (int)$row['id'];
        }, $rows));
        $visible = [];
        foreach ($rows as $row) {
            if (!$this->access->canSeeRow($context, $row)) {
                continue;
            }
            if (!$includeInactive && (string)$row['status'] !== 'active') {
                continue;
            }
            $presented = $this->present($row);
            $canManage = $this->access->canManageRow($context, $row);
            $presented['canEdit'] = $canManage;
            $presented['canChangeStatus'] = $canManage;
            $presented['canArchive'] = $canManage;
            $presented['history'] = $historyMap[(int)$row['id']] ?? [];
            try {
                $required = UnifiedQueryJson::decode((string)$row['required_permissions']);
                foreach ($required as $permission) {
                    if (!$this->access->has($context, (string)$permission)) {
                        throw new UnifiedQueryException(
                            'UNIFIED_QUERY_SOURCE_PERMISSION_LOST',
                            '引用字段权限已失效，请编辑计算规则。',
                            ['permission' => $permission]
                        );
                    }
                }
                $validated = $this->validator->validate(
                    $pageCode,
                    UnifiedQueryJson::decode((string)$row['expression']),
                    (string)$row['return_type'],
                    $context['permissions']
                );
                if ($validated['referenced_fields'] !== UnifiedQueryJson::decode((string)$row['referenced_fields'])) {
                    throw new UnifiedQueryException(
                        'UNIFIED_QUERY_SOURCE_CHANGED',
                        '引用字段定义已变化，请编辑计算规则。',
                        []
                    );
                }
                $this->assertReferencedFieldContract(
                    $pageCode,
                    $validated['referenced_fields'],
                    UnifiedQueryJson::decode((string)$row['referenced_field_contract'])
                );
            } catch (UnifiedQueryException $exception) {
                $presented['status'] = 'invalid';
                $presented['statusLabel'] = '引用失效';
                $presented['invalidReason'] = $exception->getMessage();
                $presented['allowedOperations'] = [];
                $presented['capabilities'] = array_fill_keys(
                    array_keys($presented['capabilities']),
                    false
                );
            }
            $visible[] = $presented;
        }
        return $visible;
    }

    public function versionDefinition(
        array $rawContext,
        string $pageCode,
        string $fieldKey,
        int $version
    ): array {
        $context = $this->access->normalizeContext($rawContext);
        $row = $this->findVisibleField($context, $pageCode, $fieldKey);
        if ((string)$row['status'] !== 'active') {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_CUSTOM_FIELD_UNAVAILABLE',
                '该字段已停用或失效，请移除、替换或恢复字段后再执行。',
                ['field_key' => $fieldKey, 'status' => $row['status']]
            );
        }
        $snapshot = Db::name(self::VERSION_TABLE)
            ->where('custom_field_id', (int)$row['id'])
            ->where('version', $version)
            ->find();
        if (!$snapshot) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_FIELD_VERSION_NOT_FOUND',
                '字段版本已不可用，请重新选择。',
                ['field_key' => $fieldKey, 'version' => $version]
            );
        }
        $required = UnifiedQueryJson::decode((string)$snapshot['required_permissions']);
        foreach ($required as $permission) {
            if (!$this->access->has($context, (string)$permission)) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_SOURCE_PERMISSION_LOST',
                    '你已无权使用该字段引用的数据。',
                    ['field_key' => $fieldKey, 'permission' => $permission]
                );
            }
        }
        $expression = UnifiedQueryJson::decode((string)$snapshot['expression']);
        $referencedFields = UnifiedQueryJson::decode((string)$snapshot['referenced_fields']);
        $validated = $this->validator->validate(
            $pageCode,
            $expression,
            (string)$snapshot['return_type'],
            $context['permissions']
        );
        if ($validated['referenced_fields'] !== $referencedFields) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_SOURCE_CHANGED',
                '引用字段定义已变化，请编辑计算规则。',
                ['field_key' => $fieldKey, 'version' => $version]
            );
        }
        $this->assertReferencedFieldContract(
            $pageCode,
            $referencedFields,
            UnifiedQueryJson::decode((string)$snapshot['referenced_field_contract'])
        );
        $snapshot['expression'] = $validated['expression'];
        $snapshot['referenced_fields'] = $referencedFields;
        return $snapshot;
    }

    public function history(array $rawContext, string $pageCode, string $fieldKey): array
    {
        $context = $this->access->normalizeContext($rawContext);
        $row = $this->findVisibleField($context, $pageCode, $fieldKey);
        return Db::name(self::VERSION_TABLE)
            ->where('custom_field_id', (int)$row['id'])
            ->field('version,name,return_type,visibility,scope_type,scope_id,status,invalid_reason,created_by,created_at')
            ->order('version desc')
            ->select()
            ->toArray();
    }

    protected function create(
        array $context,
        array $payload,
        string $pageCode,
        string $name,
        array $validated
    ): array {
        list($visibility, $scopeType, $scopeId, $nameNamespace) =
            $this->resolveScope($context, $payload, null);
        $fieldKey = 'cf_' . bin2hex(random_bytes(12));
        $this->registry->assertCustomFieldNameAllowed($pageCode, $fieldKey, $name);
        $now = time();
        $nameKey = $this->nameKey($name);
        return Db::transaction(function () use (
            $context,
            $pageCode,
            $name,
            $validated,
            $visibility,
            $scopeType,
            $scopeId,
            $nameNamespace,
            $fieldKey,
            $now,
            $nameKey
        ) {
            $this->assertNameAvailable(
                $context['tenant_id'],
                $pageCode,
                $nameNamespace,
                $nameKey,
                0
            );
            $expressionJson = UnifiedQueryJson::encode($validated['expression']);
            $row = [
                'field_key' => $fieldKey,
                'tenant_id' => $context['tenant_id'],
                'page_code' => $pageCode,
                'name' => $name,
                'name_namespace' => $nameNamespace,
                'active_name_key' => $nameKey,
                'return_type' => $validated['return_type'],
                'visibility' => $visibility,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'owner_account_id' => $context['account_id'],
                'status' => 'active',
                'invalid_reason' => '',
                'current_version' => 1,
                'expression_hash' => hash('sha256', $expressionJson),
                'complexity_score' => $validated['complexity_score'],
                'uses_aggregate' => $validated['uses_aggregate'] ? 1 : 0,
                'created_by' => $context['operator_id'],
                'updated_by' => $context['operator_id'],
                'created_at' => $now,
                'updated_at' => $now,
                'archived_at' => 0,
            ];
            $id = (int)Db::name(self::TABLE)->insertGetId($row);
            $row['id'] = $id;
            $this->insertVersion($row, $validated, 1, $context['operator_id'], $now);
            return $this->present(array_merge($row, [
                'expression' => $expressionJson,
                'referenced_fields' => UnifiedQueryJson::encode($validated['referenced_fields']),
            ]));
        });
    }

    protected function update(
        array $context,
        array $payload,
        string $fieldKey,
        string $pageCode,
        string $name,
        array $validated
    ): array {
        $expectedVersion = (int)($payload['expectedVersion'] ?? ($payload['expected_version'] ?? 0));
        if ($expectedVersion <= 0) {
            throw $this->versionConflict($expectedVersion);
        }
        return Db::transaction(function () use (
            $context,
            $payload,
            $fieldKey,
            $pageCode,
            $name,
            $validated,
            $expectedVersion
        ) {
            $row = $this->lockField($context['tenant_id'], $fieldKey);
            $this->access->assertCanManageRow($context, $row, 'edit');
            $this->assertVersion($row, $expectedVersion);
            if ((string)$row['page_code'] !== $pageCode || (string)$row['status'] === 'archived') {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_FIELD_NOT_FOUND',
                    '该字段不存在或已归档，请刷新后重试。',
                    ['field_key' => $fieldKey]
                );
            }
            list($visibility, $scopeType, $scopeId, $nameNamespace) =
                $this->resolveScope($context, $payload, $row);
            $this->registry->assertCustomFieldNameAllowed($pageCode, $fieldKey, $name);
            $nameKey = $this->nameKey($name);
            $this->assertNameAvailable(
                $context['tenant_id'],
                $pageCode,
                $nameNamespace,
                $nameKey,
                (int)$row['id']
            );
            $expressionJson = UnifiedQueryJson::encode($validated['expression']);
            $newVersion = $expectedVersion + 1;
            $now = time();
            $changes = [
                'name' => $name,
                'name_namespace' => $nameNamespace,
                'active_name_key' => $nameKey,
                'return_type' => $validated['return_type'],
                'visibility' => $visibility,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'current_version' => $newVersion,
                'expression_hash' => hash('sha256', $expressionJson),
                'complexity_score' => $validated['complexity_score'],
                'uses_aggregate' => $validated['uses_aggregate'] ? 1 : 0,
                'invalid_reason' => '',
                'updated_by' => $context['operator_id'],
                'updated_at' => $now,
            ];
            $updated = Db::name(self::TABLE)
                ->where('id', (int)$row['id'])
                ->where('current_version', $expectedVersion)
                ->update($changes);
            if ((int)$updated !== 1) {
                throw $this->versionConflict($expectedVersion);
            }
            $row = array_merge($row, $changes);
            $this->insertVersion($row, $validated, $newVersion, $context['operator_id'], $now);
            $this->references->markUpgradeAvailable(
                $context['tenant_id'],
                $fieldKey,
                $newVersion
            );
            return $this->present(array_merge($row, [
                'expression' => $expressionJson,
                'referenced_fields' => UnifiedQueryJson::encode($validated['referenced_fields']),
            ]));
        });
    }

    protected function resolveScope(array $context, array $payload, ?array $current): array
    {
        $visibility = (string)($payload['visibility'] ?? ($current['visibility'] ?? 'personal'));
        if ($visibility === 'personal') {
            $ownerAccountId = $current === null
                ? (int)$context['account_id']
                : (int)$current['owner_account_id'];
            if ($current !== null
                && (string)$current['visibility'] === 'shared'
                && $ownerAccountId !== (int)$context['account_id']) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_OWNERSHIP_TRANSFER_FORBIDDEN',
                    '共享字段只能由原创建人改回个人字段。',
                    ['field_key' => $current['field_key']]
                );
            }
            return [
                'personal',
                'account',
                (string)$ownerAccountId,
                'account:' . $ownerAccountId,
            ];
        }
        if ($visibility !== 'shared') {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_VISIBILITY_INVALID',
                '请选择个人字段或共享字段。',
                []
            );
        }
        $scopeAliases = [
            'organization' => 'organization_subtree',
            'org' => 'organization_subtree',
            'all' => 'tenant',
        ];
        $scopeType = (string)($payload['shareScope']
            ?? ($payload['scopeType'] ?? ($payload['scope_type'] ?? ($current['scope_type'] ?? ''))));
        $scopeType = $scopeAliases[$scopeType] ?? $scopeType;
        $scopeId = trim((string)($payload['scopeId']
            ?? ($payload['scope_id'] ?? ($current['scope_id'] ?? ''))));
        if ($scopeType === 'store' && $scopeId === '') {
            $scopeId = (string)$context['store_id'];
        } elseif ($scopeType === 'organization_subtree' && $scopeId === '') {
            $scopeId = (string)$context['organization_id'];
        } elseif ($scopeType === 'tenant') {
            $scopeId = (string)$context['tenant_id'];
        }
        $this->access->assertShareScope($context, $scopeType, $scopeId);
        // 共享字段名在同商户页面内统一占位，避免组织上下级叠加后出现同名字段。
        return ['shared', $scopeType, $scopeId, 'shared'];
    }

    protected function lockField(string $tenantId, string $fieldKey): array
    {
        $row = Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('field_key', $fieldKey)
            ->lock(true)
            ->find();
        if (!$row) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_FIELD_NOT_FOUND',
                '该字段不存在或已被移除，请刷新后重试。',
                ['field_key' => $fieldKey]
            );
        }
        return $row;
    }

    protected function findVisibleField(array $context, string $pageCode, string $fieldKey): array
    {
        $this->access->assertPageAccess($context);
        $row = Db::name(self::TABLE)
            ->where('tenant_id', $context['tenant_id'])
            ->where('page_code', $pageCode)
            ->where('field_key', $fieldKey)
            ->where('status', '<>', 'archived')
            ->find();
        if (!$row || !$this->access->canSeeRow($context, $row)) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_FIELD_NOT_FOUND',
                '该字段不存在或你已无权查看。',
                ['field_key' => $fieldKey]
            );
        }
        return $row;
    }

    protected function assertVersion(array $row, int $expectedVersion): void
    {
        if ($expectedVersion <= 0 || (int)$row['current_version'] !== $expectedVersion) {
            throw $this->versionConflict($expectedVersion, (int)$row['current_version']);
        }
    }

    protected function assertNameAvailable(
        string $tenantId,
        string $pageCode,
        string $namespace,
        string $nameKey,
        int $exceptId
    ): void {
        $query = Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('page_code', $pageCode)
            ->where('name_namespace', $namespace)
            ->where('active_name_key', $nameKey)
            ->where('status', '<>', 'archived')
            ->lock(true);
        if ($exceptId > 0) {
            $query->where('id', '<>', $exceptId);
        }
        if ($query->find()) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_FIELD_NAME_DUPLICATE',
                '当前可见范围内已有同名字段，请更换名称。',
                []
            );
        }
    }

    protected function insertVersion(
        array $row,
        array $validated,
        int $version,
        int $operatorId,
        int $now
    ): void {
        Db::name(self::VERSION_TABLE)->insert([
            'custom_field_id' => (int)$row['id'],
            'tenant_id' => (string)$row['tenant_id'],
            'field_key' => (string)$row['field_key'],
            'version' => $version,
            'name' => (string)$row['name'],
            'return_type' => (string)$validated['return_type'],
            'expression' => UnifiedQueryJson::encode($validated['expression']),
            'expression_hash' => hash('sha256', UnifiedQueryJson::encode($validated['expression'])),
            'referenced_fields' => UnifiedQueryJson::encode($validated['referenced_fields']),
            'referenced_field_contract' => UnifiedQueryJson::encode(
                $this->referencedFieldContract((string)$row['page_code'], $validated['referenced_fields'])
            ),
            'required_permissions' => UnifiedQueryJson::encode($validated['required_permissions']),
            'complexity_score' => (int)$validated['complexity_score'],
            'uses_aggregate' => $validated['uses_aggregate'] ? 1 : 0,
            'visibility' => (string)$row['visibility'],
            'scope_type' => (string)$row['scope_type'],
            'scope_id' => (string)$row['scope_id'],
            'status' => (string)$row['status'],
            'invalid_reason' => (string)($row['invalid_reason'] ?? ''),
            'created_by' => $operatorId,
            'created_at' => $now,
        ]);
    }

    protected function insertVersionFromPrevious(array $row, int $version, int $operatorId): void
    {
        $previous = Db::name(self::VERSION_TABLE)
            ->where('custom_field_id', (int)$row['id'])
            ->where('version', $version - 1)
            ->find();
        if (!$previous) {
            throw new \RuntimeException('统一查询字段前一版本缺失');
        }
        unset($previous['id']);
        $previous['version'] = $version;
        $previous['name'] = (string)$row['name'];
        $previous['visibility'] = (string)$row['visibility'];
        $previous['scope_type'] = (string)$row['scope_type'];
        $previous['scope_id'] = (string)$row['scope_id'];
        $previous['status'] = (string)$row['status'];
        $previous['invalid_reason'] = (string)($row['invalid_reason'] ?? '');
        $previous['created_by'] = $operatorId;
        $previous['created_at'] = time();
        Db::name(self::VERSION_TABLE)->insert($previous);
    }

    protected function present(array $row): array
    {
        $typeLabels = [
            'amount' => '金额',
            'decimal' => '数字',
            'integer' => '整数',
            'date' => '日期',
            'datetime' => '日期时间',
            'text' => '文本',
            'boolean' => '是／否',
        ];
        $statusLabels = [
            'active' => '正常',
            'inactive' => '已停用',
            'invalid' => '引用失效',
            'archived' => '已归档',
        ];
        $operations = $this->allowedOperations(
            (string)$row['return_type'],
            !empty($row['uses_aggregate']),
            (string)$row['status']
        );
        $expression = [];
        if (isset($row['expression'])) {
            $expression = is_array($row['expression'])
                ? $row['expression']
                : UnifiedQueryJson::decode((string)$row['expression']);
        }
        $referencedFields = [];
        if (isset($row['referenced_fields'])) {
            $referencedFields = is_array($row['referenced_fields'])
                ? $row['referenced_fields']
                : UnifiedQueryJson::decode((string)$row['referenced_fields']);
        }
        return [
            'id' => (string)$row['field_key'],
            'key' => (string)$row['field_key'],
            'code' => strtoupper(str_replace('cf_', 'CF-', (string)$row['field_key'])),
            'customFieldId' => (int)($row['id'] ?? 0),
            'name' => (string)$row['name'],
            'label' => (string)$row['name'],
            'type' => (string)$row['return_type'],
            'returnType' => (string)$row['return_type'],
            'returnTypeLabel' => $typeLabels[(string)$row['return_type']] ?? (string)$row['return_type'],
            'visibility' => (string)$row['visibility'],
            'shareScope' => $this->presentScope((string)$row['scope_type']),
            'scopeId' => (string)$row['scope_id'],
            'ownerAccountId' => (int)$row['owner_account_id'],
            'version' => (int)$row['current_version'],
            'status' => (string)$row['status'],
            'statusLabel' => $statusLabels[(string)$row['status']] ?? (string)$row['status'],
            'invalidReason' => (string)($row['invalid_reason'] ?? ''),
            'expression' => $expression,
            'rule' => $expression,
            'referencedFields' => $referencedFields,
            'history' => [],
            'allowedOperations' => $operations,
            'capabilities' => [
                'list' => in_array('display', $operations, true),
                'quickFilter' => in_array('quick', $operations, true),
                'filter' => in_array('filter', $operations, true),
                'sort' => in_array('sort', $operations, true),
                'group' => in_array('group', $operations, true),
                'summary' => in_array('summary', $operations, true),
                'export' => in_array('export', $operations, true),
                'customInput' => in_array((string)$row['return_type'], [
                    'text', 'integer', 'decimal', 'amount', 'date', 'datetime',
                ], true),
            ],
            'defaultVisible' => false,
            'defaultQuick' => false,
        ];
    }

    protected function referencedFieldContract(string $pageCode, array $fieldKeys): array
    {
        $contract = [];
        foreach ($fieldKeys as $fieldKey) {
            $field = $this->registry->fieldDefinition($pageCode, (string)$fieldKey);
            $contract[(string)$fieldKey] = [
                'type' => (string)$field['type'],
                'permission' => (string)$field['permission'],
            ];
        }
        return $contract;
    }

    protected function assertReferencedFieldContract(
        string $pageCode,
        array $fieldKeys,
        array $storedContract
    ): void {
        $currentContract = $this->referencedFieldContract($pageCode, $fieldKeys);
        if (UnifiedQueryJson::encode($currentContract)
            !== UnifiedQueryJson::encode($storedContract)) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_SOURCE_CHANGED',
                '引用字段类型或权限定义已变化，请编辑计算规则。',
                []
            );
        }
    }

    protected function allowedOperations(string $type, bool $aggregate, string $status): array
    {
        if ($status !== 'active') {
            return [];
        }
        $operations = ['display', 'filter', 'sort', 'export'];
        if (!$aggregate) {
            $operations[] = 'quick';
        }
        if (in_array($type, ['text', 'date', 'datetime', 'boolean'], true) && !$aggregate) {
            $operations[] = 'group';
        }
        if (in_array($type, ['amount', 'decimal', 'integer'], true)) {
            $operations[] = 'summary';
        }
        return $operations;
    }

    protected function normalizeName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ($name === '' || $length > 32) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_FIELD_NAME_INVALID',
                '字段名称需为 1 到 32 个字符。',
                []
            );
        }
        return $name;
    }

    protected function nameKey(string $name): string
    {
        return hash('sha256', strtolower(preg_replace('/\s+/u', '', trim($name))));
    }

    protected function presentScope(string $scope): string
    {
        return $scope === 'organization_subtree' ? 'organization' : $scope;
    }

    protected function versionHistoryMap(array $fieldIds): array
    {
        if (!$fieldIds) {
            return [];
        }
        $rows = Db::name(self::VERSION_TABLE)
            ->whereIn('custom_field_id', array_values(array_unique($fieldIds)))
            ->field('custom_field_id,version,name,return_type,status,invalid_reason,created_by,created_at')
            ->order('custom_field_id asc,version desc')
            ->select()
            ->toArray();
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['custom_field_id']][] = [
                'version' => (int)$row['version'],
                'name' => (string)$row['name'],
                'returnType' => (string)$row['return_type'],
                'status' => (string)$row['status'],
                'invalidReason' => (string)$row['invalid_reason'],
                'authorId' => (int)$row['created_by'],
                'createdAt' => date('Y-m-d H:i:s', (int)$row['created_at']),
            ];
        }
        return $map;
    }

    protected function versionConflict(int $expected, int $current = 0): UnifiedQueryException
    {
        return new UnifiedQueryException(
            'UNIFIED_QUERY_VERSION_CONFLICT',
            '该字段已被其他人更新，请刷新后再编辑。',
            ['expected_version' => $expected, 'current_version' => $current]
        );
    }
}
