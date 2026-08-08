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
    public function resolvedLocations(array $adminInfo): array
    {
        $access = (new InventoryPlatformAccessPolicy())->resolve($adminInfo);
        $query = Db::name('inventory_location')
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('location_status', 'ACTIVE')
            // Current rollout exposes only default physical locations. Store
            // locations retain the existing organization scope; HQ locations
            // are resolved from the same scope by their organization root.
            ->where('is_default', 1);
        if (empty($access['is_super_admin'])) {
            $storeIds = array_values(array_filter(array_map('intval', (array)$access['store_ids'])));
            $hqIds = array_map('intval', array_column((new InventoryHqLocationServices())->locationsForAccess($access), 'id'));
            $query->where(function ($scope) use ($storeIds, $hqIds): void {
                $scope->where(function ($stores) use ($storeIds): void {
                    $stores->where('location_type', 'STORE')->whereIn('store_id', $storeIds);
                });
                if ($hqIds) {
                    $scope->whereOr(function ($hq) use ($hqIds): void {
                        $hq->where('location_type', 'HQ')->whereIn('id', $hqIds);
                    });
                }
            });
        }
        $locations = $query->field('id,location_code,location_name,location_type,store_id,store_name_snapshot,organization_name_snapshot,is_default')
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
    public function dashboard(array $adminInfo): array
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
        return [
            'stock_product_count' => count(array_unique(array_filter(array_map('intval', array_column($rows, 'product_id'))))),
            'stock_amount_cents' => $totalCents,
            'can_view_cost' => $canViewCost,
            'expiring_90_batch_count' => $expiryRisk['expiring_90_batch_count'],
            'expiry_risk_buckets' => $expiryRisk['expiry_risk_buckets'],
            'count_pending_count' => (int)Db::name('inventory_stock_count_document')->where('tenant_id', $tenantId)->whereIn('location_id', $locationIds)->whereNotIn('document_status', ['CONFIRMED', 'CANCELLED'])->count(),
            'request_pending_count' => (int)Db::name('inventory_stock_request_document')->where('tenant_id', $tenantId)->whereIn('location_id', $locationIds)->whereIn('document_status', ['APPLIED', 'PARTIAL'])->count(),
            'data_as_of' => time(),
        ];
    }

    private function amountCents($value): int
    {
        if ($value === null || !preg_match('/^(\d+)\.(\d{2})$/D', (string)$value, $matches)) return 0;
        return (int)$matches[1] * 100 + (int)$matches[2];
    }

}
