<?php
declare(strict_types=1);

namespace app\services\product\inventory;

/**
 * Product rollout gates. Disabled capabilities keep their source and schema so
 * a later approved release can restore them without inventing a parallel flow.
 */
final class InventoryV3RolloutPolicy
{
    public const MULTI_WAREHOUSE_ENABLED = false;
    public const MULTI_WAREHOUSE_DEFERRED_CODE = 'inventory_multi_warehouse_deferred';

    public static function assertMultiWarehouseEnabled(): void
    {
        if (!self::MULTI_WAREHOUSE_ENABLED) {
            throw new \RuntimeException(self::MULTI_WAREHOUSE_DEFERRED_CODE);
        }
    }
}
