<?php
declare(strict_types=1);

namespace app\services\product\inventory\query;

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\product\inventory\InventoryDocumentCreatorScope;
use app\services\product\inventory\InventoryPlatformAccessPolicy;
use app\services\organization\OrganizationScopeService;
use app\services\query\UnifiedQueryContextFactory;
use app\services\query\UnifiedQueryException;
use think\facade\Db;

/** Builds the platform inventory query scope from the authenticated admin only. */
final class InventoryPlatformUnifiedQueryContextFactory
{
    private $core;

    public function __construct(UnifiedQueryContextFactory $core) { $this->core = $core; }

    /**
     * 页面授权后可选择一个有效门店或其组织；门店默认仓仍由服务端解析。
     * 此方法仅构造查询身份，不能把跨店读取范围传给写命令。
     */
    public function make(
        int $adminId,
        array $adminInfo,
        string $cutoffDate,
        int $selectedStoreId = 0,
        string $subject = 'HQ',
        string $pageCode = InventoryBatchStockQueryContract::PAGE_CODE,
        int $selectedHqLocationId = 0,
        ?int $selectedOrganizationId = null
    ): array
    {
        if ($adminId <= 0) throw new UnifiedQueryException('UNIFIED_QUERY_CONTEXT_INVALID', '平台库存登录身份无效。', []);
        $policy = new InventoryPlatformAccessPolicy();
        $access = !empty($adminInfo['id'])
            ? $policy->resolve($adminInfo)
            : $policy->resolveByAdminId($adminId);
        // 共用接口的参数只能选择已授权页面，不能借其他库存菜单扩大读取业务域。
        $policy->assertQueryPage($access, $pageCode);
        $hqAccess = $access;
        $subject = strtoupper(trim($subject));
        if (!in_array($subject, ['HQ', 'STORE'], true)) throw new UnifiedQueryException('UNIFIED_QUERY_SCOPE_INVALID', '库存主体参数不合法。', []);
        if ($pageCode === InventoryBatchStockQueryContract::PAGE_CODE) {
            $access = $policy->forStockRead($access);
        } elseif ($subject === 'STORE' && $pageCode === 'inventory_count') {
            $access = $policy->forCountRead($access);
        } elseif ($subject === 'STORE') {
            // 门店主体即使未指定组织或门店，也必须使用页面的员工数据范围。
            $access = $policy->forOperationalRead($access, $pageCode);
        }
        if (($selectedOrganizationId !== null && $selectedOrganizationId < 0)
            || ($selectedOrganizationId !== null && ($subject !== 'STORE' || $selectedStoreId > 0))) {
            throw new UnifiedQueryException('UNIFIED_QUERY_SCOPE_INVALID', '组织和门店筛选不能同时指定。', []);
        }
        if ($subject === 'STORE' && empty($access['store_ids'])) {
            // 空的员工范围不能因超管身份或未传筛选条件回退到全店。
            throw new UnifiedQueryException('UNIFIED_QUERY_SCOPE_INVALID', '当前权限范围内暂无可用门店库存仓。', []);
        }
        $locationsQuery = Db::name('inventory_location')->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('location_type', $subject);
        // 停用总部仓仍可查历史；门店继续维持单默认仓范围。
        if ($subject === 'STORE') $locationsQuery->where('location_status', 'ACTIVE')->where('is_default', 1);
        if ($subject === 'HQ') {
            $hqIds = array_map('intval', array_column((new \app\services\product\inventory\InventoryHqLocationServices())->locationsForAccess($hqAccess, true), 'id'));
            if (!$hqIds) throw new UnifiedQueryException('UNIFIED_QUERY_SCOPE_INVALID', '当前平台没有可用总部仓，查询已停止。', []);
            if ($selectedHqLocationId > 0 && !in_array($selectedHqLocationId, $hqIds, true)) {
                throw new UnifiedQueryException('UNIFIED_QUERY_SCOPE_INVALID', '所选总部仓不在当前平台库存范围内。', []);
            }
            if ($selectedHqLocationId > 0) $hqIds = [$selectedHqLocationId];
            $locationsQuery->whereIn('id', $hqIds);
        } else {
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
        if (!$locationIds) {
            // 超级管理员也不能在空仓范围上继续查询，避免空 scope_dimensions 被解释为无限制。
            $message = $subject === 'STORE' && $pageCode === InventoryBatchStockQueryContract::PAGE_CODE
                ? '当前权限范围内暂无可用门店库存仓。'
                : '当前平台没有可用库存仓库，查询已停止。';
            throw new UnifiedQueryException('UNIFIED_QUERY_SCOPE_INVALID', $message, []);
        }
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
        if ($selectedOrganizationId !== null) {
            // 复用当前页面实际返回的组织树，兼容组织迁移期的树结构；浏览器传来的组织 ID 不授予门店范围。
            $tree = app()->make(OrganizationScopeService::class)->buildPickerTree(array_keys($storeIds));
            $node = $this->organizationNode($tree, $selectedOrganizationId);
            if ($node === null) throw new UnifiedQueryException('UNIFIED_QUERY_SCOPE_INVALID', '所选组织不在当前库存范围内。', []);
            $selectedStores = array_fill_keys($this->descendantStoreIds($node), true);
            $storeIds = array_intersect_key($storeIds, $selectedStores);
            if (!$storeIds) throw new UnifiedQueryException('UNIFIED_QUERY_SCOPE_INVALID', '所选组织下暂无可用门店库存仓。', []);
            $locationIds = [];
            foreach ($locations as $location) {
                if (isset($storeIds[(int)$location['store_id']])) $locationIds[(string)(int)$location['id']] = true;
            }
        }
        $locationByStore = [];
        if ($subject === 'STORE') foreach ($locations as $location) {
            $storeId = (int)($location['store_id'] ?? 0);
            if (isset($storeIds[$storeId])) $locationByStore[$storeId] = (int)$location['id'];
        }
        $features = (array)($access['features'] ?? []);
        $canManageQuery = in_array('inventory.batch.query.manage', $features, true);
        $context = $this->core->make([
            'tenant_id' => (string)CashierV3ScopeResolver::TENANT_SCOPE_ID, 'account_id' => $adminId, 'operator_id' => $adminId,
            'store_id' => $subject === 'STORE' ? $selectedStoreId : 0, 'organization_id' => 'platform', 'visible_store_ids' => array_keys($storeIds),
            'ancestor_organization_ids' => array_values($organizationIds), 'shareable_store_ids' => array_keys($storeIds),
            'shareable_organization_ids' => array_values($organizationIds), 'permission_version' => (string)$access['permission_version'],
            'granted_features' => array_values(array_filter($features, static function (string $feature): bool {
                return $feature !== 'inventory.batch.query.manage';
            })),
            'manage_shared_fields' => $canManageQuery, 'share_tenant_fields' => $canManageQuery,
            // Platform outbound jobs use a different identity namespace from store sessions.
            'scope_dimensions' => ['location_id' => array_keys($locationIds)] + ($pageCode === 'inventory_outbound' ? ['inventory_actor_type' => ['platform']] : []),
            'query_cutoff_date' => InventoryBatchStockQueryContract::assertCutoffDate($cutoffDate), 'data_as_of' => time(),
        ], ['pageCode' => $pageCode]);
        // 查门店仓也保留平台认证身份，不能冒充同编号门店员工。
        if (in_array($pageCode, InventoryDocumentCreatorScope::PAGES, true)) {
            $context['inventory_document_creator'] = InventoryDocumentCreatorScope::platform($adminId);
        }
        // 总部草稿仅归当前平台创建人；门店查询不得借平台身份读到门店人员草稿。
        if ($pageCode === 'inventory_count' && $subject === 'HQ'
            && in_array('inventory.location.manage', $features, true)) {
            $context['inventory_count_operator_type'] = 'PLATFORM_ADMIN';
            $context['inventory_count_creator_id'] = $adminId;
            $context['inventory_count_operator_name'] = (string)($adminInfo['real_name'] ?? $adminInfo['account'] ?? '');
        }
        // 组织选择及“当前权限范围”的跨店读取都必须携带服务端门店集合；
        // 不能把未选单店的 store_id=0 解释为真实门店，也不得把这份范围带入写命令。
        if ($subject === 'STORE' && ($selectedOrganizationId !== null || $selectedStoreId === 0)) {
            $context['inventory_organization_read'] = true;
            $context['inventory_scope_store_ids'] = array_keys($storeIds);
            $context['inventory_location_by_store'] = $locationByStore;
        }
        return $context;
    }

    /** Match a selectable organization node, never a store leaf with a colliding numeric ID. */
    private function organizationNode(array $nodes, int $id): ?array
    {
        foreach ($nodes as $node) {
            if (($node['node_type'] ?? '') !== 'org') continue;
            if ((int)($node['id'] ?? 0) === $id) return $node;
            $found = $this->organizationNode((array)($node['children'] ?? []), $id);
            if ($found !== null) return $found;
        }
        return null;
    }

    /** The tree is already trimmed to active, page-readable default-warehouse stores. */
    private function descendantStoreIds(array $node): array
    {
        $ids = [];
        foreach ((array)($node['children'] ?? []) as $child) {
            if (($child['node_type'] ?? '') === 'store') $ids[] = (int)$child['id'];
            else $ids = array_merge($ids, $this->descendantStoreIds($child));
        }
        return array_values(array_unique(array_filter($ids)));
    }
}
