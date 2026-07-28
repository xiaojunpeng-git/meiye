<?php

namespace app\services\query;

/**
 * 只消费控制器从登录态和后端数据权限服务生成的上下文；不读取客户端权限字段。
 */
class UnifiedQueryAccessPolicy
{
    public const PAGE_POLICY = 'policy:unified_query_page';
    public const MANAGE_SHARED = 'unified_query.custom_field.manage';
    public const SHARE_TENANT = 'unified_query.custom_field.share_tenant';
    public const EXPORT = 'unified_query.export';

    public function normalizeContext(array $context): array
    {
        $tenantId = trim((string)($context['tenant_id'] ?? ''));
        $accountId = (int)($context['account_id'] ?? ($context['operator_id'] ?? 0));
        $operatorId = (int)($context['operator_id'] ?? $accountId);
        if ($tenantId === '' || $accountId <= 0 || $operatorId <= 0) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_CONTEXT_INVALID',
                '当前登录信息不完整，请重新登录。',
                []
            );
        }
        $permissions = array_values(array_unique(array_map(
            'strval',
            is_array($context['permissions'] ?? null) ? $context['permissions'] : []
        )));
        $normalized = $context;
        $normalized['tenant_id'] = $tenantId;
        $normalized['account_id'] = $accountId;
        $normalized['operator_id'] = $operatorId;
        $normalized['store_id'] = (int)($context['store_id'] ?? 0);
        $normalized['organization_id'] = trim((string)($context['organization_id'] ?? ''));
        $normalized['all_stores'] = array_key_exists('visible_store_ids', $context)
            && $context['visible_store_ids'] === null;
        $visibleStoresProvided = array_key_exists('visible_store_ids', $context);
        $normalized['visible_store_ids'] = $this->positiveIntList(
            $visibleStoresProvided ? $context['visible_store_ids'] : [$normalized['store_id']]
        );
        if (!$visibleStoresProvided
            && $normalized['store_id'] > 0
            && !in_array($normalized['store_id'], $normalized['visible_store_ids'], true)) {
            $normalized['visible_store_ids'][] = $normalized['store_id'];
        }
        $normalized['ancestor_organization_ids'] = array_values(array_unique(array_filter(array_map(
            'strval',
            is_array($context['ancestor_organization_ids'] ?? null)
                ? $context['ancestor_organization_ids']
                : [$normalized['organization_id']]
        ), function (string $value): bool {
            return $value !== '';
        })));
        $normalized['shareable_store_ids'] = $this->positiveIntList(
            $context['shareable_store_ids'] ?? [$normalized['store_id']]
        );
        $normalized['shareable_organization_ids'] = array_values(array_unique(array_filter(array_map(
            'strval',
            is_array($context['shareable_organization_ids'] ?? null)
                ? $context['shareable_organization_ids']
                : [$normalized['organization_id']]
        ), function (string $value): bool {
            return $value !== '';
        })));
        $normalized['permissions'] = $permissions;
        return $normalized;
    }

    public function assertPageAccess(array $context): void
    {
        if (!$this->has($context, self::PAGE_POLICY)) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_FORBIDDEN',
                '你没有当前页面的查询权限。',
                []
            );
        }
    }

    public function capabilityPermissions(array $context): array
    {
        $base = $this->has($context, self::PAGE_POLICY);
        $shared = $base && $this->has($context, self::MANAGE_SHARED);
        $export = $base && ($this->has($context, self::EXPORT) || $this->has($context, '*'));
        return [
            'createCustomField' => $base,
            'editCustomField' => $base,
            'shareCustomField' => $shared,
            'changeCustomFieldStatus' => $base,
            'archiveCustomField' => $base,
            'renameFields' => $base,
            'export' => $export,
            // 内部兼容别名；用户端以以上稳定键为准。
            'create' => $base,
            'edit' => $base,
            'share' => $shared,
            'deactivate' => $base,
            'archive' => $base,
        ];
    }

    public function assertCanManageRow(array $context, array $row, string $operation): void
    {
        $this->assertPageAccess($context);
        if (!$this->canManageRow($context, $row)) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_FIELD_FORBIDDEN',
                '你无权' . $this->operationLabel($operation) . '该字段。',
                ['field_key' => $row['field_key'] ?? '', 'operation' => $operation]
            );
        }
    }

    public function canManageRow(array $context, array $row): bool
    {
        $personalOwner = (string)($row['visibility'] ?? '') === 'personal'
            && (int)($row['owner_account_id'] ?? 0) === (int)($context['account_id'] ?? 0);
        $sharedManager = (string)($row['visibility'] ?? '') === 'shared'
            && $this->canManageSharedRow($context, $row);
        return $personalOwner || $sharedManager;
    }

    public function assertShareScope(array $context, string $scopeType, string $scopeId): void
    {
        if (!$this->has($context, self::MANAGE_SHARED)) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_SHARE_FORBIDDEN',
                '你没有共享字段管理权限。',
                []
            );
        }
        if ($scopeType === 'store') {
            if (!in_array((int)$scopeId, $context['shareable_store_ids'], true)) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_SHARE_SCOPE_FORBIDDEN',
                    '共享范围超出你的门店管理范围。',
                    ['scope_type' => $scopeType, 'scope_id' => $scopeId]
                );
            }
            return;
        }
        if ($scopeType === 'organization_subtree') {
            if (!in_array($scopeId, $context['shareable_organization_ids'], true)) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_SHARE_SCOPE_FORBIDDEN',
                    '共享范围超出你的组织管理范围。',
                    ['scope_type' => $scopeType, 'scope_id' => $scopeId]
                );
            }
            return;
        }
        if ($scopeType === 'tenant') {
            if (!$this->has($context, self::SHARE_TENANT)) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_SHARE_SCOPE_FORBIDDEN',
                    '你没有全商户共享权限。',
                    ['scope_type' => $scopeType]
                );
            }
            return;
        }
        throw new UnifiedQueryException(
            'UNIFIED_QUERY_SHARE_SCOPE_INVALID',
            '请选择有效的共享范围。',
            ['scope_type' => $scopeType]
        );
    }

    public function canSeeRow(array $context, array $row): bool
    {
        if ((string)($row['tenant_id'] ?? '') !== (string)$context['tenant_id']) {
            return false;
        }
        if ((string)($row['visibility'] ?? '') === 'personal') {
            return (int)($row['owner_account_id'] ?? 0) === (int)$context['account_id'];
        }
        $scopeType = (string)($row['scope_type'] ?? '');
        $scopeId = (string)($row['scope_id'] ?? '');
        if ($scopeType === 'tenant') {
            return $scopeId === (string)$context['tenant_id'];
        }
        if ($scopeType === 'store') {
            if (!empty($context['all_stores'])) {
                return true;
            }
            return in_array((int)$scopeId, $context['visible_store_ids'], true);
        }
        if ($scopeType === 'organization_subtree') {
            return in_array($scopeId, $context['ancestor_organization_ids'], true);
        }
        return false;
    }

    public function has(array $context, string $permission): bool
    {
        $permissions = is_array($context['permissions'] ?? null) ? $context['permissions'] : [];
        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    protected function canManageSharedRow(array $context, array $row): bool
    {
        if (!$this->has($context, self::MANAGE_SHARED)) {
            return false;
        }
        $scopeType = (string)($row['scope_type'] ?? '');
        $scopeId = (string)($row['scope_id'] ?? '');
        if ($scopeType === 'store') {
            return in_array((int)$scopeId, $context['shareable_store_ids'], true);
        }
        if ($scopeType === 'organization_subtree') {
            return in_array($scopeId, $context['shareable_organization_ids'], true);
        }
        if ($scopeType === 'tenant') {
            return $scopeId === (string)$context['tenant_id']
                && $this->has($context, self::SHARE_TENANT);
        }
        return false;
    }

    protected function positiveIntList($values): array
    {
        if (!is_array($values)) {
            $values = [];
        }
        return array_values(array_unique(array_filter(array_map('intval', $values), function (int $id): bool {
            return $id > 0;
        })));
    }

    protected function operationLabel(string $operation): string
    {
        $labels = [
            'edit' => '编辑',
            'deactivate' => '停用',
            'activate' => '启用',
            'archive' => '归档',
        ];
        return $labels[$operation] ?? '操作';
    }
}
