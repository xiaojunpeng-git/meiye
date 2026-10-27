<?php

namespace app\services\cashier\v3\order;

use app\services\query\UnifiedQueryPageRegistrar;
use app\services\query\UnifiedQueryPageRegistry;
use app\services\cashier\v3\config\CashierV3BusinessSourcePolicyServices;

final class CashierV3OrderCenterUnifiedQueryRegistrar implements UnifiedQueryPageRegistrar
{
    private $sourceEnabled;
    private $debtEnabled;

    /** Pin one policy read for this registration; workers rebuild their cached
     * capability graph only when the field visibility actually changes.
     */
    public function runtimeCacheKey(): string
    {
        $policy = (new CashierV3BusinessSourcePolicyServices())->read();
        $this->sourceEnabled = $policy['enabled'] !== false;
        // Workers must rebuild capabilities when either independent visibility switch changes.
        $this->debtEnabled = (int)sys_config('cashier_debt_pay_switch', 0) === 1;
        return ($this->sourceEnabled ? 'source-enabled' : 'source-disabled') . ($this->debtEnabled ? ':debt-enabled' : ':debt-disabled');
    }

    public function register(UnifiedQueryPageRegistry $registry): void
    {
        // 独立查询/导出也读取实例策略，不能依赖门店工作台已执行 bootstrap。
        if ($this->sourceEnabled === null) $this->runtimeCacheKey();
        foreach (CashierV3OrderCenterUnifiedQueryContract::PAGE_BY_TYPE as $pageCode) {
            // Same instance switch as cashier/recharge; saved preferences cannot restore a disabled debt column.
            $definition = CashierV3OrderCenterUnifiedQueryContract::definition($pageCode, $this->sourceEnabled, $this->debtEnabled);
            $registry->registerPage($pageCode, $definition['label'], $definition['fields'], 'record_id', [
                'keywordFields' => $definition['keywordFields'],
                'requiredFeature' => 'cashier.v3.order_center',
                'exportFeature' => 'cashier.v3.order_center',
            ]);
        }
    }
}
