<?php
declare(strict_types=1);

namespace app\services\product\inventory\query;

/** Builds a store-only batch query scope from trusted inventory locations. */
final class InventoryStoreBatchScopeResolver
{
    public function resolve(int $storeId, array $locations, bool $canViewCost): InventoryBatchStockDataScope
    {
        if ($storeId <= 0) {
            throw new \InvalidArgumentException('inventory_store_scope_invalid');
        }
        $tenantIds = [];
        $locationIds = [];
        foreach ($locations as $location) {
            if (!is_array($location)
                || !isset($location['id'], $location['tenant_id'], $location['store_id'], $location['location_status'])
                || (int)$location['store_id'] !== $storeId
                || (string)$location['location_status'] !== 'ACTIVE'
                || (int)$location['id'] <= 0
                || trim((string)$location['tenant_id']) === '') {
                throw new \InvalidArgumentException('inventory_store_location_invalid');
            }
            $tenantIds[(string)$location['tenant_id']] = true;
            $locationIds[(int)$location['id']] = (int)$location['id'];
        }
        if (!$locationIds) {
            throw new \InvalidArgumentException('inventory_store_locations_empty');
        }
        if (count($tenantIds) !== 1) {
            throw new \InvalidArgumentException('inventory_store_tenant_ambiguous');
        }
        $permissions = [InventoryBatchStockQueryContract::PERMISSION_VIEW];
        if ($canViewCost) {
            $permissions[] = InventoryBatchStockQueryContract::PERMISSION_COST;
        }
        return new InventoryBatchStockDataScope(
            (string)array_key_first($tenantIds),
            array_values($locationIds),
            $permissions
        );
    }
}
