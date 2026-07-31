<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\organization\OrganizationScopeService;
use app\services\product\inventory\query\InventoryBatchStockQueryContract;
use app\services\query\UnifiedQueryException;
use think\facade\Db;

/**
 * Trusted platform-inventory authority boundary.
 *
 * The browser supplies neither features nor stores.  A platform administrator
 * must hold both an enabled inventory API grant and an organization-admin
 * relation whose resolved stores contain the requested warehouse.  Level-0
 * platform administrators are the only intentionally global reader.
 */
final class InventoryPlatformAccessPolicy
{
    public const AUTH_VIEW = 'inventory-v3-platform-batch-view';
    public const AUTH_COST = 'inventory-v3-platform-batch-cost';
    public const AUTH_EXPORT = 'inventory-v3-platform-batch-export';
    public const AUTH_QUERY_MANAGE = 'inventory-v3-platform-query-manage';
    public const AUTH_WAREHOUSE_MANAGE = 'inventory-v3-platform-warehouse-manage';

    /**
     * @return array{admin_id:int,is_super_admin:bool,store_ids:array,unique_auth:array,features:array,permission_version:string}
     */
    public function resolve(array $adminInfo): array
    {
        $adminId = (int)($adminInfo['id'] ?? 0);
        if ($adminId <= 0) {
            throw $this->denied('无法确认平台库存登录身份。');
        }
        return $this->resolveByAdminId($adminId);
    }

    /**
     * Used by the export worker, which reconstructs authority from the saved
     * account identity rather than trusting a serialized browser profile.
     *
     * @return array{admin_id:int,is_super_admin:bool,store_ids:array,unique_auth:array,features:array,permission_version:string}
     */
    public function resolveByAdminId(int $adminId): array
    {
        if ($adminId <= 0) {
            throw $this->denied('无法确认平台库存登录身份。');
        }
        $admin = Db::name('system_admin')->where('id', $adminId)->where('is_del', 0)->find();
        if (!$admin || (int)($admin['status'] ?? 0) !== 1 || (int)($admin['admin_type'] ?? 0) === 3) {
            throw $this->denied('当前平台账号不可访问库存。');
        }

        $isSuperAdmin = (int)($admin['level'] ?? 1) === 0;
        $uniqueAuth = $isSuperAdmin ? [
            self::AUTH_VIEW,
            self::AUTH_COST,
            self::AUTH_EXPORT,
            self::AUTH_QUERY_MANAGE,
            self::AUTH_WAREHOUSE_MANAGE,
        ] : $this->roleUniqueAuth($admin);
        if (!in_array(self::AUTH_VIEW, $uniqueAuth, true)) {
            throw $this->denied('当前岗位未配置“平台库存查看”权限。');
        }

        $storeIds = $isSuperAdmin ? $this->allActiveStoreIds() : $this->organizationStoreIds($adminId);
        if (!$storeIds) {
            throw $this->denied('当前平台账号未被授予任何门店库存范围。');
        }

        $features = [InventoryBatchStockQueryContract::PERMISSION_VIEW];
        if ($isSuperAdmin || in_array(self::AUTH_COST, $uniqueAuth, true)) {
            $features[] = InventoryBatchStockQueryContract::PERMISSION_COST;
        }
        if ($isSuperAdmin || in_array(self::AUTH_EXPORT, $uniqueAuth, true)) {
            $features[] = InventoryBatchStockQueryContract::PERMISSION_EXPORT;
        }
        // UQ preference/custom-field commands are deliberately separate from
        // data viewing.  They still inherit the same trusted DataScope.
        if ($isSuperAdmin || in_array(self::AUTH_QUERY_MANAGE, $uniqueAuth, true)) {
            $features[] = 'inventory.batch.query.manage';
        }
        if ($isSuperAdmin || in_array(self::AUTH_WAREHOUSE_MANAGE, $uniqueAuth, true)) {
            $features[] = 'inventory.location.manage';
        }

        sort($storeIds, SORT_NUMERIC);
        sort($uniqueAuth, SORT_STRING);
        sort($features, SORT_STRING);
        return [
            'admin_id' => $adminId,
            'is_super_admin' => $isSuperAdmin,
            'store_ids' => $storeIds,
            'unique_auth' => $uniqueAuth,
            'features' => $features,
            'permission_version' => hash('sha256', implode('|', [
                (string)$adminId,
                (string)(int)($admin['level'] ?? 1),
                implode(',', $uniqueAuth),
                implode(',', $storeIds),
            ])),
        ];
    }

    /** @param string[] $features */
    public function assertFeature(array $access, string $feature, string $message): void
    {
        if (!in_array($feature, (array)($access['features'] ?? []), true)) {
            throw $this->denied($message);
        }
    }

    /** @return string[] */
    private function roleUniqueAuth(array $admin): array
    {
        $roleIds = [];
        foreach (explode(',', (string)($admin['roles'] ?? '')) as $value) {
            $id = (int)$value;
            if ($id > 0) $roleIds[$id] = $id;
        }
        if (!$roleIds) return [];
        $rules = Db::name('system_role')->whereIn('id', array_values($roleIds))->where('status', 1)->column('rules');
        $menuIds = [];
        foreach ((array)$rules as $ruleList) {
            foreach (explode(',', (string)$ruleList) as $value) {
                $id = (int)$value;
                if ($id > 0) $menuIds[$id] = $id;
            }
        }
        if (!$menuIds) return [];
        $rows = Db::name('system_menus')->whereIn('id', array_values($menuIds))
            ->where('type', 1)->where('is_del', 0)->field('unique_auth')->select()->toArray();
        $result = [];
        foreach ($rows as $row) {
            $auth = trim((string)($row['unique_auth'] ?? ''));
            if ($auth !== '') $result[$auth] = $auth;
        }
        return array_values($result);
    }

    /** @return int[] */
    private function organizationStoreIds(int $adminId): array
    {
        $orgAdminIds = Db::name('organization_admin')->where('admin_id', $adminId)
            ->where('is_del', 0)->column('id');
        if (!$orgAdminIds) return [];
        /** @var OrganizationScopeService $scope */
        $scope = app()->make(OrganizationScopeService::class);
        $storeIds = [];
        foreach ($orgAdminIds as $orgAdminId) {
            foreach ($scope->getAdminResolvedStoreIds((int)$orgAdminId) as $storeId) {
                $storeId = (int)$storeId;
                if ($storeId > 0) $storeIds[$storeId] = $storeId;
            }
        }
        return array_values($storeIds);
    }

    /** @return int[] */
    private function allActiveStoreIds(): array
    {
        return array_values(array_unique(array_filter(array_map('intval', Db::name('system_store')
            ->where('is_del', 0)->where('is_show', 1)->column('id')))));
    }

    private function denied(string $message): UnifiedQueryException
    {
        return new UnifiedQueryException('INVENTORY_PLATFORM_ACCESS_DENIED', $message, []);
    }
}
