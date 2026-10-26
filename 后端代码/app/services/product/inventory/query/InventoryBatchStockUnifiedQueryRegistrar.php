<?php
declare(strict_types=1);

namespace app\services\product\inventory\query;

use app\services\query\UnifiedQueryPageRegistrar;
use app\services\query\UnifiedQueryPageRegistry;

/** Registers the inventory-owned batch fact projection with the neutral UQ runtime. */
final class InventoryBatchStockUnifiedQueryRegistrar implements UnifiedQueryPageRegistrar
{
    public function register(UnifiedQueryPageRegistry $registry): void
    {
        $definition = (new InventoryBatchStockQueryProvider())->pageDefinition();
        $registry->registerPage(
            InventoryBatchStockQueryContract::PAGE_CODE,
            (string)$definition['label'],
            (array)$definition['fields'],
            InventoryBatchStockQueryContract::STABLE_ROW_KEY,
            [
                'keywordFields' => (array)$definition['keywordFields'],
                'requiredFeature' => InventoryBatchStockQueryContract::PERMISSION_VIEW,
                'exportFeature' => InventoryBatchStockQueryContract::PERMISSION_EXPORT,
                // The host must calculate this dimension from the logged-in store.
                'scopeDimensions' => ['location_id'],
            ]
        );
    }
}
