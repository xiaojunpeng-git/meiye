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
            ->where('location_status', 'ACTIVE');
        if (empty($access['is_super_admin'])) {
            $query->whereIn('store_id', (array)$access['store_ids']);
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

}
