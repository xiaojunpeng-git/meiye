<?php
declare(strict_types=1);

namespace app\services\product\inventory\query;

use app\services\query\UnifiedQueryPageRegistrar;
use app\services\query\UnifiedQueryPageRegistry;

final class InventoryOperationalUnifiedQueryRegistrar implements UnifiedQueryPageRegistrar
{
    public function register(UnifiedQueryPageRegistry $registry): void
    {
        foreach (InventoryOperationalUnifiedQueryContract::PAGE_CODES as $pageCode) {
            $definition = InventoryOperationalUnifiedQueryContract::definition($pageCode);
            $registry->registerPage($pageCode, $definition['label'], $definition['fields'], 'record_id', [
                'keywordFields' => $definition['keywordFields'],
                'requiredFeature' => $definition['requiredFeature'],
                'exportFeature' => $definition['exportFeature'],
                // Outbound export identity is frozen alongside warehouse scope to prevent account-id collisions.
                'scopeDimensions' => $pageCode === 'inventory_outbound' ? ['location_id', 'inventory_actor_type'] : ['location_id'],
            ]);
        }
    }
}
