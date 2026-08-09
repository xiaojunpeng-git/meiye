<?php

namespace app\services\query\provider;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\query\UnifiedQueryContextFactory;
use app\services\query\UnifiedQueryException;
use think\facade\Db;

/**
 * 仅供现有收银会员入口兼容，将 Cashier scope 组合成中立 trusted context。
 */
class MemberUnifiedQueryContextFactory
{
    /** @var UnifiedQueryContextFactory */
    protected $core;

    public function __construct(UnifiedQueryContextFactory $core)
    {
        $this->core = $core;
    }

    public function make(
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        array $payload = []
    ): array {
        if (!isset($payload['pageCode']) && !isset($payload['page_code'])) {
            $payload['pageCode'] = MemberUnifiedQueryProvider::PAGE_CODE;
        }
        if ($operatorScope->storeId() <= 0) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_CONTEXT_INVALID',
                '会员查询必须从有效门店进入。',
                []
            );
        }
        $visibleStoreIds = $dataScope->authorizationMode()
            === CashierV3DataScopeContext::MODE_ALL
            ? null
            : $this->positiveIds((array)$dataScope->visibleStoreIds());
        $organizationIds = $this->organizationScopeIds(
            $visibleStoreIds,
            $operatorScope->organizationId()
        );
        $profile = $dataScope->operatorProfile();
        $canManageSharedFields = $dataScope->isSuperAdmin()
            || (array_key_exists('level', $profile) && (int)$profile['level'] === 0);
        // 产品当前确认：已接入统一查询的功能先对可进入页面的账号全部开放，
        // 细粒度角色配置后续再收紧。业务数据范围仍完全继承 DataScope，
        // 不允许查询设置或字段配置改变可见门店。
        $features = array_values(array_unique(array_merge(
            $dataScope->grantedFeatures(),
            ['cashier.v3.member']
        )));
        return $this->core->make([
            'tenant_id' => $operatorScope->tenantId(),
            'account_id' => $operatorScope->operatorId(),
            'operator_id' => $operatorScope->operatorId(),
            'store_id' => $operatorScope->storeId(),
            'organization_id' => $operatorScope->organizationId(),
            'visible_store_ids' => $visibleStoreIds,
            'ancestor_organization_ids' => $organizationIds,
            'shareable_store_ids' => $visibleStoreIds === null
                ? $this->allStoreIds()
                : $visibleStoreIds,
            'shareable_organization_ids' => $organizationIds,
            'permission_version' => $dataScope->permissionVersion(),
            'granted_features' => $features,
            'manage_shared_fields' => $canManageSharedFields,
            'share_tenant_fields' => $dataScope->isSuperAdmin(),
            'scope_dimensions' => [],
        ], $payload);
    }

    protected function organizationScopeIds($visibleStoreIds, string $currentOrganizationId): array
    {
        $direct = [];
        try {
            $query = Db::name('organization_store')->field('org_id,store_id');
            if ($visibleStoreIds !== null) {
                if (!$visibleStoreIds) {
                    return $currentOrganizationId !== '' ? [$currentOrganizationId] : [];
                }
                $query->whereIn('store_id', $visibleStoreIds);
            }
            foreach ($query->select()->toArray() as $row) {
                $id = (int)($row['org_id'] ?? 0);
                if ($id > 0) {
                    $direct[$id] = true;
                }
            }
            if ((int)$currentOrganizationId > 0) {
                $direct[(int)$currentOrganizationId] = true;
            }
            if (!$direct) {
                return [];
            }
            $parents = [];
            foreach (Db::name('organization')
                ->where('is_del', 0)
                ->field('id,pid')
                ->select()
                ->toArray() as $row) {
                $parents[(int)$row['id']] = (int)$row['pid'];
            }
            $resolved = $direct;
            foreach (array_keys($direct) as $id) {
                $seen = [];
                while ($id > 0 && !isset($seen[$id])) {
                    $seen[$id] = true;
                    $resolved[$id] = true;
                    $id = (int)($parents[$id] ?? 0);
                }
            }
            $values = array_values(array_map('strval', array_keys($resolved)));
            sort($values, SORT_STRING);
            return $values;
        } catch (\Throwable $exception) {
            return $currentOrganizationId !== '' ? [$currentOrganizationId] : [];
        }
    }

    protected function allStoreIds(): array
    {
        try {
            return $this->positiveIds(Db::name('system_store')
                ->where('is_del', 0)
                ->column('id'));
        } catch (\Throwable $exception) {
            return [];
        }
    }

    protected function positiveIds(array $values): array
    {
        $values = array_values(array_unique(array_filter(array_map(
            'intval',
            $values
        ), function (int $id): bool {
            return $id > 0;
        })));
        sort($values, SORT_NUMERIC);
        return $values;
    }
}
