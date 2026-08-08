<?php
declare(strict_types=1);

namespace app\services\product\inventory\query;

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\product\inventory\InventoryPlatformAccessPolicy;
use app\services\query\UnifiedQueryContextFactory;
use app\services\query\UnifiedQueryException;
use think\facade\Db;

/** Builds the platform inventory query scope from the authenticated admin only. */
final class InventoryPlatformUnifiedQueryContextFactory
{
    private $core;

    public function __construct(UnifiedQueryContextFactory $core) { $this->core = $core; }

    /**
     * A browser may narrow the platform view to one authorized store. The
     * location set itself is always resolved server-side from that store scope.
     */
    public function make(
        int $adminId,
        array $adminInfo,
        string $cutoffDate,
        int $selectedStoreId = 0,
        string $subject = 'HQ',
        string $pageCode = InventoryBatchStockQueryContract::PAGE_CODE,
        int $selectedHqLocationId = 0
    ): array
    {
        if ($adminId <= 0) throw new UnifiedQueryException('UNIFIED_QUERY_CONTEXT_INVALID', '平台库存登录身份无效。', []);
        $policy = new InventoryPlatformAccessPolicy();
        $access = !empty($adminInfo['id'])
            ? $policy->resolve($adminInfo)
            : $policy->resolveByAdminId($adminId);
        $subject = strtoupper(trim($subject));
        if (!in_array($subject, ['HQ', 'STORE'], true)) throw new UnifiedQueryException('UNIFIED_QUERY_SCOPE_INVALID', '库存主体参数不合法。', []);
        $locationsQuery = Db::name('inventory_location')->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('location_status', 'ACTIVE')->where('location_type', $subject);
        if ($subject === 'HQ') {
            $hqIds = array_map('intval', array_column((new \app\services\product\inventory\InventoryHqLocationServices())->locationsForAccess($access), 'id'));
            if (!$hqIds) throw new UnifiedQueryException('UNIFIED_QUERY_SCOPE_INVALID', '当前平台没有可用总部仓，查询已停止。', []);
            if ($selectedHqLocationId > 0 && !in_array($selectedHqLocationId, $hqIds, true)) {
                throw new UnifiedQueryException('UNIFIED_QUERY_SCOPE_INVALID', '所选总部仓不在当前平台库存范围内。', []);
            }
            if ($selectedHqLocationId > 0) $hqIds = [$selectedHqLocationId];
            $locationsQuery->whereIn('id', $hqIds);
        } elseif (empty($access['is_super_admin'])) {
            $locationsQuery->whereIn('store_id', (array)$access['store_ids']);
        }
        $locations = $locationsQuery->field('id,store_id,organization_id')->order('id asc')->select()->toArray();
        $locationIds = []; $storeIds = []; $organizationIds = ['org:platform' => 'platform'];
        foreach ($locations as $location) {
            $id = (int)($location['id'] ?? 0); if ($id <= 0) continue;
            $locationIds[(string)$id] = true;
            $storeId = (int)($location['store_id'] ?? 0); if ($storeId > 0) $storeIds[$storeId] = true;
            $organizationId = trim((string)($location['organization_id'] ?? '')); if ($organizationId !== '') $organizationIds['org:' . $organizationId] = $organizationId;
        }
        if (!$locationIds) throw new UnifiedQueryException('UNIFIED_QUERY_SCOPE_INVALID', '当前平台没有可用库存仓库，查询已停止。', []);
        if ($selectedStoreId > 0) {
            if ($subject !== 'STORE') throw new UnifiedQueryException('UNIFIED_QUERY_SCOPE_INVALID', '总部仓查询不支持门店筛选。', []);
            if (!isset($storeIds[$selectedStoreId])) {
                throw new UnifiedQueryException('UNIFIED_QUERY_SCOPE_INVALID', '所选门店不在当前平台库存范围内。', []);
            }
            $locationIds = [];
            foreach ($locations as $location) {
                if ((int)($location['store_id'] ?? 0) === $selectedStoreId) {
                    $locationIds[(string)(int)$location['id']] = true;
                }
            }
            if (!$locationIds) throw new UnifiedQueryException('UNIFIED_QUERY_SCOPE_INVALID', '所选门店没有可用库存。', []);
            $storeIds = [$selectedStoreId => true];
        }
        $features = (array)($access['features'] ?? []);
        $canManageQuery = in_array('inventory.batch.query.manage', $features, true);
        return $this->core->make([
            'tenant_id' => (string)CashierV3ScopeResolver::TENANT_SCOPE_ID, 'account_id' => $adminId, 'operator_id' => $adminId,
            'store_id' => $subject === 'STORE' ? $selectedStoreId : 0, 'organization_id' => 'platform', 'visible_store_ids' => array_keys($storeIds),
            'ancestor_organization_ids' => array_values($organizationIds), 'shareable_store_ids' => array_keys($storeIds),
            'shareable_organization_ids' => array_values($organizationIds), 'permission_version' => (string)$access['permission_version'],
            'granted_features' => array_values(array_filter($features, static function (string $feature): bool {
                return $feature !== 'inventory.batch.query.manage';
            })),
            'manage_shared_fields' => $canManageQuery, 'share_tenant_fields' => $canManageQuery,
            'scope_dimensions' => ['location_id' => array_keys($locationIds)],
            'query_cutoff_date' => InventoryBatchStockQueryContract::assertCutoffDate($cutoffDate), 'data_as_of' => time(),
        ], ['pageCode' => $pageCode]);
    }
}
