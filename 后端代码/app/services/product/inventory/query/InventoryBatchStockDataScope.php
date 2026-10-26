<?php
declare(strict_types=1);

namespace app\services\product\inventory\query;

final class InventoryBatchStockDataScope
{
    /** @var string */
    private $tenantId;

    /** @var array<int,int> */
    private $locationIds;

    /** @var array<int,string> */
    private $permissions;

    public function __construct(string $tenantId, array $locationIds, array $permissions)
    {
        $tenantId = trim($tenantId);
        if ($tenantId === '' || strlen($tenantId) > 32
            || preg_match('/^[A-Za-z0-9:._-]+$/D', $tenantId) !== 1) {
            throw new \InvalidArgumentException('inventory_query_tenant_invalid');
        }
        $normalizedLocations = [];
        foreach ($locationIds as $locationId) {
            if (!is_int($locationId) || $locationId <= 0) {
                throw new \InvalidArgumentException('inventory_query_location_invalid');
            }
            $normalizedLocations[$locationId] = $locationId;
        }
        if (!$normalizedLocations) {
            throw new \InvalidArgumentException('inventory_query_locations_empty');
        }
        sort($normalizedLocations, SORT_NUMERIC);
        $normalizedPermissions = array_values(array_unique(array_map('strval', $permissions)));
        if (!in_array('*', $normalizedPermissions, true)
            && !in_array(InventoryBatchStockQueryContract::PERMISSION_VIEW, $normalizedPermissions, true)) {
            throw new \InvalidArgumentException('inventory_query_permission_denied');
        }
        $this->tenantId = $tenantId;
        $this->locationIds = $normalizedLocations;
        $this->permissions = $normalizedPermissions;
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function locationIds(): array
    {
        return $this->locationIds;
    }

    public function canViewCost(): bool
    {
        return in_array('*', $this->permissions, true)
            || in_array(InventoryBatchStockQueryContract::PERMISSION_COST, $this->permissions, true);
    }

    public function assertRequestedLocations(array $requested): array
    {
        if (!$requested) {
            return $this->locationIds;
        }
        $allowed = array_fill_keys($this->locationIds, true);
        $result = [];
        foreach ($requested as $locationId) {
            if (!is_int($locationId) || !isset($allowed[$locationId])) {
                throw new \InvalidArgumentException('inventory_query_location_scope_denied');
            }
            $result[$locationId] = $locationId;
        }
        sort($result, SORT_NUMERIC);
        return array_values($result);
    }
}
