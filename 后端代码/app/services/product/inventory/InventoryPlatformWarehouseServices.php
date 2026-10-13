<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\product\inventory\query\InventoryBatchStockDataScope;
use app\services\product\inventory\query\InventoryBatchStockQueryProvider;
use think\facade\Db;

/** Platform DataScope boundary for warehouse selection and batch inventory. */
final class InventoryPlatformWarehouseServices
{
    /**
     * @return array{access:array,locations:array}
     */
    public function resolvedLocations(array $adminInfo, bool $includeInactive = false): array
    {
        $access = (new InventoryPlatformAccessPolicy())->resolve($adminInfo);
        $query = Db::name('inventory_location')
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            // 本轮只开放总部多仓；门店目录仍只暴露原默认仓。
            ->where(function ($types): void {
                $types->where('location_type', 'HQ')->whereOr(function ($store): void {
                    $store->where('location_type', 'STORE')->where('is_default', 1);
                });
            });
        if (!$includeInactive) $query->where('location_status', 'ACTIVE');
        if (empty($access['is_super_admin'])) {
            $storeIds = array_values(array_filter(array_map('intval', (array)$access['store_ids'])));
            $hqIds = array_map('intval', array_column((new InventoryHqLocationServices())->locationsForAccess($access, $includeInactive), 'id'));
            if (!$storeIds && !$hqIds) throw new \InvalidArgumentException('inventory_platform_location_scope_empty');
            $query->where(function ($scope) use ($storeIds, $hqIds): void {
                // An HQ-only platform account has no store ids by design. Do
                // not emit an empty whereIn() branch; it must still resolve
                // its server-authorized headquarters locations.
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
        }
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
        $resolved = $this->resolvedLocations($adminInfo);
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
    public function dashboard(array $adminInfo, int $selectedLocationId = 0): array
    {
        $resolved = $this->resolvedLocations($adminInfo);
        $access = $resolved['access'];
        // 平台首页固定以总部仓为主体。门店仓可由平台端查询，但不能
        // 因账号具备跨门店范围而混入总部仓的首页指标。
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

    private function amountCents($value): int
    {
        if ($value === null || !preg_match('/^(\d+)\.(\d{2})$/D', (string)$value, $matches)) return 0;
        return (int)$matches[1] * 100 + (int)$matches[2];
    }

}
