<?php

namespace app\services\query;

/**
 * 将宿主已验证的身份、功能权限与数据范围规范化为中立统一查询上下文。
 */
class UnifiedQueryContextFactory
{
    public const MAX_SCOPE_IDS = 1000;

    /** @var UnifiedQueryPageRegistry */
    protected $registry;

    /** @var UnifiedQueryPagePermissionResolverRegistry */
    protected $permissionResolvers;

    public function __construct(
        UnifiedQueryPageRegistry $registry,
        UnifiedQueryPagePermissionResolverRegistry $permissionResolvers = null
    ) {
        $this->registry = $registry;
        if ($permissionResolvers === null) {
            $permissionResolvers = new UnifiedQueryPagePermissionResolverRegistry($registry);
            $permissionResolvers->freeze();
        }
        $this->permissionResolvers = $permissionResolvers;
    }

    /**
     * @param array<string,mixed> $trustedContext 仅可由宿主服务端权限层构造
     * @param array<string,mixed> $payload 只读取 pageCode，不读取权限、范围或统计时点
     */
    public function make(array $trustedContext, array $payload = []): array
    {
        $allowed = [
            'tenant_id', 'account_id', 'operator_id', 'store_id', 'organization_id',
            'visible_store_ids', 'ancestor_organization_ids',
            'shareable_store_ids', 'shareable_organization_ids',
            'permission_version', 'granted_features',
            'manage_shared_fields', 'share_tenant_fields',
            'scope_dimensions', 'query_cutoff_date', 'data_as_of',
        ];
        foreach (array_keys($trustedContext) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw $this->invalidContext('包含未声明的宿主上下文字段：' . (string)$key);
            }
        }

        $pageCode = trim((string)($payload['pageCode'] ?? ($payload['page_code'] ?? '')));
        $page = $this->registry->page($pageCode);
        if (!is_string($trustedContext['tenant_id'] ?? null)
            || !is_int($trustedContext['account_id'] ?? null)
            || !is_int($trustedContext['operator_id'] ?? null)
            || !is_int($trustedContext['store_id'] ?? null)
            || !is_string($trustedContext['organization_id'] ?? null)) {
            throw $this->invalidContext('身份字段类型不合法');
        }
        $tenantId = trim($trustedContext['tenant_id']);
        $accountId = $trustedContext['account_id'];
        $operatorId = $trustedContext['operator_id'];
        $storeId = $trustedContext['store_id'];
        $organizationId = trim($trustedContext['organization_id']);
        if ($tenantId === '' || strlen($tenantId) > 64
            || $accountId <= 0 || $operatorId <= 0 || $storeId < 0
            || ($organizationId !== ''
                && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $organizationId))) {
            throw $this->invalidContext('身份字段不完整或不合法');
        }

        if (!array_key_exists('visible_store_ids', $trustedContext)) {
            throw $this->invalidContext('缺少 visible_store_ids');
        }
        $visibleStoreIds = $trustedContext['visible_store_ids'] === null
            ? null
            : $this->positiveIds($trustedContext['visible_store_ids'], 'visible_store_ids');
        $organizationIds = $this->stableIds(
            $trustedContext['ancestor_organization_ids'] ?? null,
            'ancestor_organization_ids'
        );
        $shareableStoreIds = array_key_exists('shareable_store_ids', $trustedContext)
            ? $this->positiveIds($trustedContext['shareable_store_ids'], 'shareable_store_ids')
            : ($visibleStoreIds ?? []);
        $shareableOrganizationIds = array_key_exists(
            'shareable_organization_ids',
            $trustedContext
        )
            ? $this->stableIds(
                $trustedContext['shareable_organization_ids'],
                'shareable_organization_ids'
            )
            : $organizationIds;

        if (!is_string($trustedContext['permission_version'] ?? null)) {
            throw $this->invalidContext('permission_version 类型不合法');
        }
        $permissionVersion = trim($trustedContext['permission_version']);
        if ($permissionVersion === '' || strlen($permissionVersion) > 128) {
            throw $this->invalidContext('permission_version 不合法');
        }
        $grantedFeatures = $this->featureCodes(
            $trustedContext['granted_features'] ?? null
        );
        $manageShared = $this->trustedBoolean(
            $trustedContext,
            'manage_shared_fields',
            false
        );
        $shareTenant = $this->trustedBoolean(
            $trustedContext,
            'share_tenant_fields',
            false
        );
        if ($shareTenant && !$manageShared) {
            throw $this->invalidContext('全商户共享权限必须同时具备共享字段管理权限');
        }

        if (!is_array($trustedContext['scope_dimensions'] ?? null)) {
            throw $this->invalidContext('scope_dimensions 必须由宿主显式提供');
        }
        $scopeDimensions = $this->registry->normalizeScopeDimensions(
            $pageCode,
            $trustedContext['scope_dimensions']
        );
        $this->registry->assertScopeDimensionsNotEmpty($pageCode, $scopeDimensions);

        if (array_key_exists('query_cutoff_date', $trustedContext)
            && !is_string($trustedContext['query_cutoff_date'])) {
            throw $this->invalidContext('query_cutoff_date 类型不合法');
        }
        if (array_key_exists('data_as_of', $trustedContext)
            && !is_int($trustedContext['data_as_of'])) {
            throw $this->invalidContext('data_as_of 类型不合法');
        }
        $cutoffDate = array_key_exists('query_cutoff_date', $trustedContext)
            ? trim($trustedContext['query_cutoff_date'])
            : date('Y-m-d');
        $dataAsOf = array_key_exists('data_as_of', $trustedContext)
            ? $trustedContext['data_as_of']
            : time();
        if (!$this->validDate($cutoffDate) || $dataAsOf <= 0) {
            throw $this->invalidContext('查询截止日期或数据时点不合法');
        }

        $trusted = [
            'tenant_id' => $tenantId,
            'account_id' => $accountId,
            'operator_id' => $operatorId,
            'store_id' => $storeId,
            'organization_id' => $organizationId,
            'visible_store_ids' => $visibleStoreIds,
            'ancestor_organization_ids' => $organizationIds,
            'shareable_store_ids' => $shareableStoreIds,
            'shareable_organization_ids' => $shareableOrganizationIds,
            'permission_version' => $permissionVersion,
            'granted_features' => $grantedFeatures,
            'manage_shared_fields' => $manageShared,
            'share_tenant_fields' => $shareTenant,
            'page_code' => $pageCode,
            'scope_dimensions' => $scopeDimensions,
            'query_cutoff_date' => $cutoffDate,
            'data_as_of' => $dataAsOf,
        ];
        $authorization = $this->pageAuthorization($pageCode, $page, $trusted);
        $permissions = array_merge($grantedFeatures, $authorization['permissions']);
        if ($authorization['pageAllowed']) {
            $permissions[] = UnifiedQueryAccessPolicy::PAGE_POLICY;
        }
        if ($authorization['pageAllowed'] && $authorization['exportAllowed']) {
            $permissions[] = UnifiedQueryAccessPolicy::EXPORT;
        }
        if ($authorization['pageAllowed'] && $manageShared) {
            $permissions[] = UnifiedQueryAccessPolicy::MANAGE_SHARED;
        }
        if ($authorization['pageAllowed'] && $manageShared && $shareTenant) {
            $permissions[] = UnifiedQueryAccessPolicy::SHARE_TENANT;
        }

        unset(
            $trusted['granted_features'],
            $trusted['manage_shared_fields'],
            $trusted['share_tenant_fields']
        );
        $trusted['all_stores'] = $visibleStoreIds === null;
        $trusted['permissions'] = array_values(array_unique($permissions));
        return $trusted;
    }

    protected function pageAuthorization(
        string $pageCode,
        array $page,
        array $trustedContext
    ): array {
        $resolver = $this->permissionResolvers->resolverFor($pageCode);
        if ($resolver === null) {
            $requiredFeature = (string)$page['requiredFeature'];
            $exportFeature = (string)$page['exportFeature'];
            $features = (array)$trustedContext['granted_features'];
            $pageAllowed = $requiredFeature !== ''
                && in_array($requiredFeature, $features, true);
            return [
                'pageAllowed' => $pageAllowed,
                'exportAllowed' => $pageAllowed
                    && $exportFeature !== ''
                    && in_array($exportFeature, $features, true),
                'permissions' => [],
            ];
        }
        $authorization = $resolver->authorize($trustedContext, $page);
        foreach (array_keys($authorization) as $key) {
            if (!in_array($key, ['pageAllowed', 'exportAllowed', 'permissions'], true)) {
                throw $this->invalidPermissionResolver(
                    $pageCode,
                    '返回未知配置：' . $key
                );
            }
        }
        if (!isset($authorization['pageAllowed'], $authorization['exportAllowed'])
            || !is_bool($authorization['pageAllowed'])
            || !is_bool($authorization['exportAllowed'])
            || !is_array($authorization['permissions'] ?? null)
            || (!$authorization['pageAllowed'] && $authorization['exportAllowed'])) {
            throw $this->invalidPermissionResolver($pageCode, '返回值形状不合法');
        }
        $reserved = [
            '*',
            UnifiedQueryAccessPolicy::PAGE_POLICY,
            UnifiedQueryAccessPolicy::EXPORT,
            UnifiedQueryAccessPolicy::MANAGE_SHARED,
            UnifiedQueryAccessPolicy::SHARE_TENANT,
        ];
        $allowedFieldPermissions = [];
        foreach ((array)$page['fields'] as $field) {
            $permission = trim((string)($field['permission'] ?? ''));
            if ($permission !== '') {
                $allowedFieldPermissions[$permission] = true;
            }
        }
        $permissions = [];
        foreach ($authorization['permissions'] as $permission) {
            $permission = trim((string)$permission);
            if ($permission === ''
                || !preg_match('/^[a-z][a-z0-9._:-]{1,127}$/D', $permission)
                || in_array($permission, $reserved, true)
                || !isset($allowedFieldPermissions[$permission])) {
                throw $this->invalidPermissionResolver(
                    $pageCode,
                    '返回非法或跨页字段权限：' . $permission
                );
            }
            $permissions[$permission] = true;
        }
        $authorization['permissions'] = array_keys($permissions);
        return $authorization;
    }

    protected function positiveIds($values, string $key): array
    {
        if (!is_array($values) || !$this->isList($values)
            || count($values) > self::MAX_SCOPE_IDS) {
            throw $this->invalidContext($key . ' 必须是有界正整数列表');
        }
        $normalized = [];
        foreach ($values as $value) {
            if (!is_int($value) || $value <= 0 || isset($normalized[$value])) {
                throw $this->invalidContext($key . ' 包含非法或重复标识');
            }
            $normalized[$value] = $value;
        }
        $normalized = array_values($normalized);
        sort($normalized, SORT_NUMERIC);
        return $normalized;
    }

    protected function stableIds($values, string $key): array
    {
        if (!is_array($values) || !$this->isList($values)
            || count($values) > self::MAX_SCOPE_IDS) {
            throw $this->invalidContext($key . ' 必须是有界稳定标识列表');
        }
        $normalized = [];
        foreach ($values as $value) {
            $identity = is_string($value) ? 'value:' . $value : '';
            if (!is_string($value)
                || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $value)
                || isset($normalized[$identity])) {
                throw $this->invalidContext($key . ' 包含非法或重复标识');
            }
            $normalized[$identity] = $value;
        }
        $normalized = array_values($normalized);
        sort($normalized, SORT_STRING);
        return $normalized;
    }

    protected function featureCodes($values): array
    {
        if (!is_array($values) || !$this->isList($values) || count($values) > 512) {
            throw $this->invalidContext('granted_features 必须是有界功能码列表');
        }
        $reserved = [
            '*',
            UnifiedQueryAccessPolicy::PAGE_POLICY,
            UnifiedQueryAccessPolicy::EXPORT,
            UnifiedQueryAccessPolicy::MANAGE_SHARED,
            UnifiedQueryAccessPolicy::SHARE_TENANT,
        ];
        $normalized = [];
        foreach ($values as $feature) {
            if (!is_string($feature)
                || !preg_match('/^[a-z][a-z0-9._:-]{1,127}$/D', $feature)
                || in_array($feature, $reserved, true)
                || isset($normalized[$feature])) {
                throw $this->invalidContext('granted_features 包含非法、保留或重复功能码');
            }
            $normalized[$feature] = true;
        }
        return array_keys($normalized);
    }

    protected function trustedBoolean(array $context, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $context)) {
            return $default;
        }
        if (!is_bool($context[$key])) {
            throw $this->invalidContext($key . ' 必须是布尔值');
        }
        return $context[$key];
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

    protected function invalidContext(string $reason): UnifiedQueryException
    {
        return new UnifiedQueryException(
            'UNIFIED_QUERY_CONTEXT_INVALID',
            '统一查询宿主上下文无效，请重新登录或刷新权限。',
            ['reason' => $reason]
        );
    }

    protected function invalidPermissionResolver(
        string $pageCode,
        string $reason
    ): UnifiedQueryException {
        return new UnifiedQueryException(
            'UNIFIED_QUERY_PERMISSION_RESOLVER_INVALID',
            '当前页面权限配置异常，请联系管理员。',
            ['page_code' => $pageCode, 'reason' => $reason]
        );
    }
}
