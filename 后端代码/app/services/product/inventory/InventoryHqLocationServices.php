<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\cashier\v3\CashierV3ScopeResolver;
use think\facade\Db;

/**
 * Resolves headquarters inventory locations from the authenticated platform
 * account. Headquarters stock is always owned by an organization root, never
 * by a store session or a fabricated store identifier.
 */
final class InventoryHqLocationServices
{
    /**
     * Resolves a headquarters warehouse for read-only inventory projections.
     * Unlike writes, reading does not require the separate warehouse-management
     * grant; InventoryPlatformAccessPolicy still derives its scope server-side.
     *
     * @return array{access:array,location:array}
     */
    public function readableLocation(array $adminInfo, int $locationId = 0): array
    {
        $access = (new InventoryPlatformAccessPolicy())->resolve($adminInfo);
        return $this->locationForAccess($access, $locationId, true);
    }

    /** 新建入口检查对应功能；通用管理仍需原生授权，入库作废由独立页面权限和冲销事务校验。 */
    public function writableLocation(array $adminInfo, int $locationId = 0, string $createPage = ''): array
    {
        $policy = new InventoryPlatformAccessPolicy();
        $access = $policy->resolve($adminInfo);
        if ($createPage !== '') {
            // 调拨生成只继承调拨页面；其他写命令仍走单据操作或仓库管理权限。
            $allowed = $createPage === 'transfer-generate'
                ? $policy->canGenerateTransfer($access)
                : $policy->canCreate($access, $createPage);
            if (!$allowed) throw new \app\services\query\UnifiedQueryException('INVENTORY_PLATFORM_ACCESS_DENIED', '当前岗位未配置对应单据的功能操作权限。', []);
        } else {
            $policy->assertFeature($access, 'inventory.location.manage', '当前岗位未配置“平台仓库管理”权限。');
        }

        return $this->locationForAccess($access, $locationId);
    }

    /** @return array{access:array,location:array} */
    private function locationForAccess(array $access, int $locationId, bool $includeInactive = false): array
    {
        $locations = $this->locationsForAccess($access, $includeInactive);
        if (!$locations) {
            throw new \RuntimeException('inventory_hq_location_scope_empty');
        }
        if ($locationId > 0) {
            foreach ($locations as $location) {
                if ((int)$location['id'] === $locationId) return ['access' => $access, 'location' => $location];
            }
            throw new \RuntimeException('inventory_hq_location_scope_denied');
        }
        if (count($locations) !== 1) {
            throw new \RuntimeException('inventory_hq_location_selection_required');
        }
        return ['access' => $access, 'location' => $locations[0]];
    }

    /** @return array<int,array<string,mixed>> */
    public function locationsForAccess(array $access, bool $includeInactive = false): array
    {
        $query = Db::name('inventory_location')
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('location_type', 'HQ');
        // 历史读取保留停用仓；写命令仍只能选择启用仓，默认标记不再决定总部仓可见性。
        if (!$includeInactive) $query->where('location_status', 'ACTIVE');
        if (empty($access['is_super_admin']) && empty($access['headquarters_only'])) {
            $roots = $this->rootsForStores((array)($access['store_ids'] ?? []));
            if (!$roots) return [];
            $query->whereIn('owner_id', $roots);
        }
        return $query->field('id,tenant_id,organization_id,organization_path,organization_name_snapshot,location_type,owner_id,location_code,location_name,store_id,store_name_snapshot,is_default,location_status,version')
            ->order('owner_id asc,id asc')->select()->toArray();
    }

    /** @return array{tenantId:string,organizationId:string,organizationPath:string,organizationName:string,storeId:int,storeName:string,operatorId:int,partyType:string} */
    public function scope(array $location, int $operatorId): array
    {
        if ((string)($location['location_type'] ?? '') !== 'HQ'
            || (int)($location['store_id'] ?? -1) !== 0
            || (int)($location['owner_id'] ?? 0) <= 0
            || (string)($location['tenant_id'] ?? '') !== CashierV3ScopeResolver::TENANT_SCOPE_ID) {
            throw new \RuntimeException('inventory_hq_location_invalid');
        }
        return [
            'tenantId' => (string)$location['tenant_id'],
            'organizationId' => (string)$location['organization_id'],
            'organizationPath' => (string)$location['organization_path'],
            'organizationName' => (string)$location['organization_name_snapshot'],
            'storeId' => 0,
            'storeName' => '',
            'operatorId' => $operatorId,
            'partyType' => 'HQ',
            'locationId' => (int)$location['id'], 'locationName' => (string)$location['location_name'],
        ];
    }

    /** @param int[] $storeIds @return int[] */
    private function rootsForStores(array $storeIds): array
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds), static fn (int $id): bool => $id > 0)));
        if (!$storeIds) return [];
        $paths = Db::name('inventory_location')
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('location_type', 'STORE')->where('is_default', 1)->where('location_status', 'ACTIVE')
            ->whereIn('store_id', $storeIds)->column('organization_path');
        $roots = [];
        foreach ($paths as $path) {
            $parts = explode('/', trim((string)$path, '/'));
            $root = (int)($parts[0] ?? 0);
            if ($root > 0) $roots[$root] = $root;
        }
        return array_values($roots);
    }
}
