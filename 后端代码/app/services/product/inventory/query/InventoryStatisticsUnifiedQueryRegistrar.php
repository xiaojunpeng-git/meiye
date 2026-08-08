<?php
declare(strict_types=1);
namespace app\services\product\inventory\query;
use app\services\query\UnifiedQueryPageRegistrar;
use app\services\query\UnifiedQueryPageRegistry;
final class InventoryStatisticsUnifiedQueryRegistrar implements UnifiedQueryPageRegistrar
{
    public function register(UnifiedQueryPageRegistry $registry): void
    {
        foreach (InventoryStatisticsUnifiedQueryContract::PAGE_CODES as $code) {
            $d = InventoryStatisticsUnifiedQueryContract::definition($code);
            $registry->registerPage($code, $d['label'], $d['fields'], $d['stableRowKey'], ['keywordFields' => $d['keywordFields'], 'requiredFeature' => $d['requiredFeature'], 'exportFeature' => $d['exportFeature'], 'scopeDimensions' => ['location_id']]);
        }
    }
}
