<?php

namespace app\services\cashier\v3\order;

use app\services\query\UnifiedQueryPageRegistrar;
use app\services\query\UnifiedQueryPageRegistry;

final class CashierV3OrderCenterUnifiedQueryRegistrar implements UnifiedQueryPageRegistrar
{
    public function register(UnifiedQueryPageRegistry $registry): void
    {
        foreach (CashierV3OrderCenterUnifiedQueryContract::PAGE_BY_TYPE as $pageCode) {
            $definition = CashierV3OrderCenterUnifiedQueryContract::definition($pageCode);
            $registry->registerPage($pageCode, $definition['label'], $definition['fields'], 'record_id', [
                'keywordFields' => $definition['keywordFields'],
                'requiredFeature' => 'cashier.v3.order_center',
                'exportFeature' => 'cashier.v3.order_center',
            ]);
        }
    }
}
