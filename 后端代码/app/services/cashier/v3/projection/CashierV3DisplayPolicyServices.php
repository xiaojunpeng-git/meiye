<?php

namespace app\services\cashier\v3\projection;

/**
 * 平台与门店共用的实例展示开关唯一读取器。
 *
 * 页面只能消费这份权威快照，不能因入口、会话或根分区尚未加载而自行假定
 * 某个功能已开启。历史事实不受展示开关影响。
 */
final class CashierV3DisplayPolicyServices
{
    public function snapshot(): array
    {
        return [
            'debtPaymentEnabled' => (int)sys_config('cashier_debt_pay_switch', 0) === 1,
            'preSaleEnabled' => (int)sys_config('cashier_presale_switch', 1) === 1,
            'salesAttributionEnabled' => (int)sys_config('cashier_sales_attribution_switch', 1) === 1,
            'experienceEnabled' => (int)sys_config('cashier_experience_switch', 1) === 1,
            'craftsmanExcludeCountsEnabled' => (int)sys_config('cashier_craftsman_exclude_counts_switch', 1) === 1,
            'laborFeeVisible' => (int)sys_config('cashier_labor_fee_mode', 0) !== 1,
        ];
    }
}
