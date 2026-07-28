<?php

namespace app\services\query;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use think\facade\Db;

/**
 * 将收银 V3 的可信会话与 DataScope 转成统一查询上下文。
 */
class UnifiedQueryContextFactory
{
    public function make(
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        array $payload = []
    ): array {
        $visibleStoreIds = $dataScope->authorizationMode() === CashierV3DataScopeContext::MODE_ALL
            ? null
            : $this->positiveIds((array)$dataScope->visibleStoreIds());
        $organizationIds = $this->organizationScopeIds(
            $visibleStoreIds,
            $operatorScope->organizationId()
        );
        $permissions = [];
        if ($dataScope->hasFeature('cashier.v3.member')) {
            $permissions[] = UnifiedQueryAccessPolicy::PAGE_POLICY;
            $permissions[] = UnifiedQueryAccessPolicy::EXPORT;
        }

        // 首批共享字段由超级管理员维护；普通账号只管理自己的个人字段。
        if ($permissions && ($dataScope->isSuperAdmin()
            || (int)($dataScope->operatorProfile()['level'] ?? -1) === 0)) {
            $permissions[] = UnifiedQueryAccessPolicy::MANAGE_SHARED;
            $permissions[] = UnifiedQueryAccessPolicy::SHARE_TENANT;
        }

        // 查询截止日属于服务端签发的统计时点，客户端不得覆盖。
        $cutoffDate = date('Y-m-d');
        return [
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
            'permissions' => array_values(array_unique($permissions)),
            'permission_version' => $dataScope->permissionVersion(),
            'authorization_mode' => $dataScope->authorizationMode(),
            'employee_id' => $dataScope->employeeId(),
            'query_cutoff_date' => $cutoffDate,
            'data_as_of' => time(),
        ];
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
            return array_values(array_map('strval', array_keys($resolved)));
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
        return array_values(array_unique(array_filter(array_map('intval', $values), function (int $id): bool {
            return $id > 0;
        })));
    }

}
