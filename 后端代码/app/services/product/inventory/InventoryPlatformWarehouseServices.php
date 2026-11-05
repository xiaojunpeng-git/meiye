<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\organization\OrganizationScopeService;
use app\services\product\inventory\query\InventoryBatchStockDataScope;
use app\services\product\inventory\query\InventoryBatchStockQueryProvider;
use think\facade\Db;

/** Platform DataScope boundary for warehouse selection and batch inventory. */
final class InventoryPlatformWarehouseServices
{
    /**
     * @return array{access:array,locations:array}
     */
    public function resolvedLocations(array $adminInfo, bool $includeInactive = false, bool $stockRead = false): array
    {
        $policy = new InventoryPlatformAccessPolicy();
        $access = $policy->resolve($adminInfo);
        $hqAccess = $access;
        // 仓库候选遵守集团看板数据范围；返回的写能力仍取原账号授权。
        if ($stockRead) $access = $policy->forStockRead($access);
        $query = Db::name('inventory_location')
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            // 本轮只开放总部多仓；门店目录仍只暴露原默认仓。
            ->where(function ($types): void {
                $types->where('location_type', 'HQ')->whereOr(function ($store): void {
                    $store->where('location_type', 'STORE')->where('is_default', 1);
                });
            });
        if (!$includeInactive) $query->where('location_status', 'ACTIVE');
        $storeIds = $policy->readableInventoryStoreIds($hqAccess);
        $hqIds = array_map('intval', array_column((new InventoryHqLocationServices())->locationsForAccess($hqAccess, $includeInactive), 'id'));
        if (!$storeIds && !$hqIds) throw new \InvalidArgumentException('inventory_platform_location_scope_empty');
        $query->where(function ($scope) use ($storeIds, $hqIds): void {
            // 空门店权限仅留下总部仓；空总部权限仅留下当前员工可见门店。
            if ($storeIds) {
                $scope->where(function ($stores) use ($storeIds): void {
                    $stores->where('location_type', 'STORE')->whereIn('store_id', $storeIds);
                });
            }
            if ($hqIds) {
                $method = $storeIds ? 'whereOr' : 'where';
                $scope->{$method}(function ($hq) use ($hqIds): void {
                    $hq->where('location_type', 'HQ')->whereIn('id', $hqIds);
                });
            }
        });
        $locations = $query->field('id,location_code,location_name,location_type,owner_id,store_id,store_name_snapshot,organization_name_snapshot,is_default,location_status,version')
            ->order('location_type asc,is_default desc,id asc')->select()->toArray();
        if (!$locations) {
            throw new \InvalidArgumentException('inventory_platform_location_scope_empty');
        }
        return ['access' => $access, 'locations' => $locations];
    }

    public function locations(array $adminInfo): array
    {
        return $this->resolvedLocations($adminInfo)['locations'];
    }

    /** 仓库设置列表按授权总部范围分页；总数与当前页使用相同名称/状态筛选。 */
    public function settings(array $adminInfo, string $keyword, string $status, int $page, int $limit = 20): array
    {
        $access = (new InventoryPlatformAccessPolicy())->resolve($adminInfo);
        $ids = array_map('intval', array_column((new InventoryHqLocationServices())->locationsForAccess($access, true), 'id'));
        $page = max(1, $page); $limit = max(1, min(100, $limit));
        if (!$ids) return ['list'=>[], 'count'=>0, 'page'=>1, 'limit'=>$limit];
        $query = Db::name('inventory_location')->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)->whereIn('id', $ids);
        if (trim($keyword) !== '') $query->whereLike('location_name', '%' . trim($keyword) . '%');
        if (in_array($status, ['ACTIVE','INACTIVE'], true)) $query->where('location_status', $status);
        $count = (int)(clone $query)->count();
        $page = min($page, max(1, (int)ceil($count/$limit)));
        return ['list'=>$query->order('owner_id asc,is_default desc,id asc')->page($page,$limit)->select()->toArray(), 'count'=>$count, 'page'=>$page, 'limit'=>$limit];
    }

    public function batchStock(array $adminInfo, int $locationId, string $cutoffDate): array
    {
        $resolved = $this->resolvedLocations($adminInfo, false, true);
        $access = $resolved['access'];
        $locations = $resolved['locations'];
        $locationIds = array_map('intval', array_column($locations, 'id'));
        $scope = new InventoryBatchStockDataScope(
            CashierV3ScopeResolver::TENANT_SCOPE_ID,
            $locationIds,
            (array)$access['features']
        );
        $rows = (new InventoryBatchStockQueryProvider())->sourceRows([
            'queryCutoffDate' => $cutoffDate,
            'locationIds' => $locationId > 0 ? [$locationId] : [],
            'includeZero' => false,
        ], $scope);
        return ['list' => $rows, 'count' => count($rows), 'locations' => $locations, 'query_cutoff_date' => $cutoffDate];
    }

    /** @return array<string,mixed> */
    public function dashboard(array $adminInfo, int $selectedLocationId = 0, string $subject = 'HQ', int $selectedStoreId = 0, ?int $selectedOrganizationId = null): array
    {
        $resolved = $this->resolvedLocations($adminInfo);
        $access = $resolved['access'];
        // 门店首页的范围由服务端重新解析；浏览器的组织和门店 ID 只能做交集筛选。
        if ($subject === 'STORE') {
            if ($selectedLocationId !== 0 || $selectedStoreId < 0 || ($selectedOrganizationId !== null && ($selectedOrganizationId < 0 || $selectedStoreId > 0))) {
                throw new \InvalidArgumentException('inventory_dashboard_scope_invalid');
            }
            return $this->storeDashboard($resolved, $selectedStoreId, $selectedOrganizationId);
        }
        if ($subject !== 'HQ' || $selectedStoreId !== 0 || $selectedOrganizationId !== null) {
            throw new \InvalidArgumentException('inventory_dashboard_scope_invalid');
        }
        // 总部仓是集团共用资产，受限门店账号不能借首页总量读取未授权门店对应的集团库存。
        if (!(new InventoryPlatformAccessPolicy())->canReadHqDashboard($access)) {
            throw new \app\services\query\UnifiedQueryException(
                'INVENTORY_PLATFORM_ACCESS_DENIED', '当前权限范围无可查看的总部仓。', []
            );
        }
        // 总部仓分支始终只聚合集团共用总部仓；门店主体由独立分支按门店事实读取。
        $locationIds = array_values(array_map(
            'intval',
            array_column(array_filter(
                $resolved['locations'],
                static fn (array $location): bool => (string)($location['location_type'] ?? '') === 'HQ'
            ), 'id')
        ));
        // 首页筛选只能缩小当前账号已授权总部仓，不能借仓库ID扩大范围。
        if ($selectedLocationId > 0) {
            if (!in_array($selectedLocationId, $locationIds, true)) throw new \InvalidArgumentException('inventory_platform_location_scope_invalid');
            $locationIds = [$selectedLocationId];
        }
        $dashboardFeatures = array_values(array_unique((array)($access['features'] ?? [])));
        $canViewCost = in_array('inventory.cost.view', $dashboardFeatures, true)
            || in_array('*', $dashboardFeatures, true);
        $scope = new InventoryBatchStockDataScope(
            CashierV3ScopeResolver::TENANT_SCOPE_ID,
            $locationIds,
            $dashboardFeatures
        );
        $cutoffDate = date('Y-m-d');
        $rows = (new InventoryBatchStockQueryProvider())->sourceRows([
            'queryCutoffDate' => $cutoffDate, 'locationIds' => $locationIds, 'includeZero' => false,
        ], $scope);
        $totalCents = null;
        if ($canViewCost) {
            $totalCents = 0;
            foreach ($rows as $row) {
                $totalCents += $this->amountCents($row['inventory_amount'] ?? null);
            }
        }
        $expiryRisk = (new InventoryExpiryRiskProjectionServices())->project($rows, $cutoffDate);
        $tenantId = CashierV3ScopeResolver::TENANT_SCOPE_ID;
        $transitQuery = Db::name('inventory_cross_transfer_document')->alias('d')->where('d.tenant_id', $tenantId)
            ->whereIn('d.from_location_id', $locationIds)->where('d.document_status', 'DISPATCHED');
        $transitCount = (int)(clone $transitQuery)->count();
        $transitCents = (clone $transitQuery)->join('inventory_cross_transfer_batch_allocation a','a.document_id=d.id')
            ->where('a.received_at',0)->fieldRaw('COALESCE(SUM(a.quantity_units*a.unit_cost_cents/POW(10,a.quantity_scale)),0) transit_cents')->find();
        $transitCents = (int)($transitCents['transit_cents'] ?? 0);
        return [
            'subject' => 'HQ',
            'in_transit_count' => $transitCount, 'in_transit_amount_cents' => $canViewCost ? (int)$transitCents : null,
            'stock_product_count' => count(array_unique(array_filter(array_map('intval', array_column($rows, 'product_id'))))),
            'stock_amount_cents' => $totalCents,
            'can_view_cost' => $canViewCost,
            'expiring_90_batch_count' => $expiryRisk['expiring_90_batch_count'],
            'expiry_risk_buckets' => $expiryRisk['expiry_risk_buckets'],
            'count_pending_count' => (int)Db::name('inventory_stock_count_document')->where('tenant_id', $tenantId)->whereIn('location_id', $locationIds)->whereNotIn('document_status', ['CONFIRMED', 'CANCELLED'])->count(),
            'request_pending_count' => (int)Db::name('inventory_stock_request_document')->where('tenant_id', $tenantId)->whereIn('supply_location_id', $locationIds)->whereIn('document_status', ['APPLIED', 'PARTIAL'])->count(),
            'request_outgoing_pending_count' => (int)Db::name('inventory_stock_request_document')->where('tenant_id', $tenantId)->whereIn('location_id', $locationIds)->whereIn('document_status', ['APPLIED', 'PARTIAL'])->count(),
            'data_as_of' => time(),
        ];
    }

    /** 按员工可见门店的有效默认仓聚合 settled 批次事实，绝不把集团共用总部仓分摊给门店。 */
    private function storeDashboard(array $resolved, int $selectedStoreId, ?int $selectedOrganizationId): array
    {
        $policy = new InventoryPlatformAccessPolicy();
        $access = $policy->forDashboardStoreRead($resolved['access']);
        $allowedStoreIds = array_values(array_map('intval', (array)$access['store_ids']));
        if (!$allowedStoreIds) throw new \app\services\query\UnifiedQueryException('INVENTORY_PLATFORM_ACCESS_DENIED', '当前权限范围无可查看的门店仓。', []);
        if ($selectedStoreId > 0) {
            if (!in_array($selectedStoreId, $allowedStoreIds, true)) throw new \InvalidArgumentException('inventory_dashboard_store_scope_invalid');
            $allowedStoreIds = [$selectedStoreId];
        } elseif ($selectedOrganizationId !== null) {
            $scope = app()->make(OrganizationScopeService::class);
            $allowedStoreIds = $scope->resolveDashboardStoreIds($selectedOrganizationId, 0, $allowedStoreIds);
            if (!$allowedStoreIds) throw new \InvalidArgumentException('inventory_dashboard_organization_scope_invalid');
        }
        $locations = array_values(array_filter($resolved['locations'], static fn (array $location): bool =>
            (string)($location['location_type'] ?? '') === 'STORE'
            && (string)($location['location_status'] ?? '') === 'ACTIVE'
            && in_array((int)($location['store_id'] ?? 0), $allowedStoreIds, true)
        ));
        $locationIds = array_values(array_map('intval', array_column($locations, 'id')));
        if (!$locationIds) throw new \InvalidArgumentException('inventory_dashboard_store_location_empty');
        $features = (array)$access['features'];
        $canViewCost = in_array('inventory.cost.view', $features, true) || in_array('*', $features, true);
        $tenantId = CashierV3ScopeResolver::TENANT_SCOPE_ID;
        $cutoffDate = date('Y-m-d');
        $scope = new InventoryBatchStockDataScope($tenantId, $locationIds, $features);
        // 首页与总部仓共用已结算批次投影，确保同一截止日和成本口径。
        $rows = (new InventoryBatchStockQueryProvider())->sourceRows([
            'queryCutoffDate' => $cutoffDate, 'locationIds' => $locationIds, 'includeZero' => false,
        ], $scope);
        $productIds = []; $totalCents = 0;
        foreach ($rows as $row) {
            $productId = (int)($row['product_id'] ?? 0);
            if ($productId > 0) $productIds[$productId] = true;
            if ($canViewCost) $totalCents += $this->amountCents($row['inventory_amount'] ?? null);
        }
        $expiryRisk = (new InventoryExpiryRiskProjectionServices())->project($rows, $cutoffDate);
        // 门店调拨列表同时包含授权门店的调出与已发货调入；一张跨店单据只计一次。
        $transitQuery = Db::name('inventory_cross_transfer_document')->alias('d')->where('d.tenant_id', $tenantId)
            ->where('d.document_status', 'DISPATCHED')->where(function ($query) use ($allowedStoreIds, $locationIds): void {
                $query->where(function ($source) use ($allowedStoreIds, $locationIds): void {
                    $source->where('d.from_party_type', 'STORE')->whereIn('d.from_party_id', $allowedStoreIds)->whereIn('d.from_location_id', $locationIds);
                })->whereOr(function ($target) use ($allowedStoreIds, $locationIds): void {
                    $target->where('d.to_party_type', 'STORE')->whereIn('d.to_party_id', $allowedStoreIds)->whereIn('d.to_location_id', $locationIds);
                });
            });
        $transitCount = (int)(clone $transitQuery)->count();
        $transitAmount = null;
        if ($canViewCost) {
            $sum = (clone $transitQuery)->join('inventory_cross_transfer_batch_allocation a', 'a.document_id=d.id')
                ->where('a.received_at', 0)->fieldRaw('COALESCE(SUM(a.quantity_units*a.unit_cost_cents/POW(10,a.quantity_scale)),0) transit_cents')->find();
            $transitAmount = (int)($sum['transit_cents'] ?? 0);
        }
        // 请货单既可能由授权门店发起，也可能由其供货；双角色在同一单上仍只返回一条。
        $requestQuery = Db::name('inventory_stock_request_document')->alias('d')->where('d.tenant_id', $tenantId)
            ->whereIn('d.document_status', ['APPLIED', 'PARTIAL'])->where(function ($query) use ($allowedStoreIds, $locationIds): void {
                $query->where(function ($requester) use ($allowedStoreIds, $locationIds): void {
                    $requester->whereIn('d.store_id', $allowedStoreIds)->whereIn('d.location_id', $locationIds);
                })->whereOr(function ($supplier) use ($allowedStoreIds): void {
                    $supplier->where('d.supply_party_type', 'STORE')->whereIn('d.supply_party_id', $allowedStoreIds);
                });
            });
        return [
            'subject' => 'STORE', 'visible_store_count' => count($allowedStoreIds),
            'in_transit_count' => $transitCount, 'in_transit_amount_cents' => $transitAmount,
            'stock_product_count' => count($productIds), 'stock_amount_cents' => $canViewCost ? $totalCents : null,
            'can_view_cost' => $canViewCost, 'expiring_90_batch_count' => $expiryRisk['expiring_90_batch_count'], 'expiry_risk_buckets' => $expiryRisk['expiry_risk_buckets'],
            'count_pending_count' => (int)Db::name('inventory_stock_count_document')->where('tenant_id', $tenantId)->whereIn('location_id', $locationIds)->whereNotIn('document_status', ['CONFIRMED', 'CANCELLED'])->count(),
            'request_pending_count' => (int)$requestQuery->count(),
            'data_as_of' => time(),
        ];
    }

    private function amountCents($value): int
    {
        if ($value === null || !preg_match('/^(\d+)\.(\d{2})$/D', (string)$value, $matches)) return 0;
        return (int)$matches[1] * 100 + (int)$matches[2];
    }

}
