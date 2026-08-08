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
                'scopeDimensions' => ['location_id'],
            ]);
        }
    }
}
