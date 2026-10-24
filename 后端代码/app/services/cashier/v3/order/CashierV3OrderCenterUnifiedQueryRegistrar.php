<?php

namespace app\services\cashier\v3\order;

use app\services\query\UnifiedQueryPageRegistrar;
use app\services\query\UnifiedQueryPageRegistry;
use app\services\cashier\v3\config\CashierV3BusinessSourcePolicyServices;

final class CashierV3OrderCenterUnifiedQueryRegistrar implements UnifiedQueryPageRegistrar
{
    private $sourceEnabled;

    /** Pin one policy read for this registration; workers rebuild their cached
     * capability graph only when the field visibility actually changes.
     */
    public function runtimeCacheKey(): string
    {
        $policy = (new CashierV3BusinessSourcePolicyServices())->read();
        $this->sourceEnabled = $policy['enabled'] !== false;
        return $this->sourceEnabled ? 'source-enabled' : 'source-disabled';
    }

    public function register(UnifiedQueryPageRegistry $registry): void
    {
        // 独立查询/导出也读取实例策略，不能依赖门店工作台已执行 bootstrap。
        if ($this->sourceEnabled === null) $this->runtimeCacheKey();
        foreach (CashierV3OrderCenterUnifiedQueryContract::PAGE_BY_TYPE as $pageCode) {
            $definition = CashierV3OrderCenterUnifiedQueryContract::definition($pageCode, $this->sourceEnabled);
            $registry->registerPage($pageCode, $definition['label'], $definition['fields'], 'record_id', [
                'keywordFields' => $definition['keywordFields'],
                'requiredFeature' => 'cashier.v3.order_center',
                'exportFeature' => 'cashier.v3.order_center',
            ]);
        }
    }
}
