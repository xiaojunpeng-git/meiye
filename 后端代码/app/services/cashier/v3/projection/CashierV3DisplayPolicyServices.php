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
        $sourcePolicy = (new \app\services\cashier\v3\config\CashierV3BusinessSourcePolicyServices())->read();
        return [
            'businessSourceEnabled' => ($sourcePolicy['enabled'] ?? true) === true,
            'debtPaymentEnabled' => (int)sys_config('cashier_debt_pay_switch', 0) === 1,
            'preSaleEnabled' => (int)sys_config('cashier_presale_switch', 1) === 1,
            'salesAttributionEnabled' => (int)sys_config('cashier_sales_attribution_switch', 1) === 1,
            'experienceEnabled' => (int)sys_config('cashier_experience_switch', 1) === 1,
            'craftsmanExcludeCountsEnabled' => (int)sys_config('cashier_craftsman_exclude_counts_switch', 1) === 1,
            // New purchases use this one shared fulfillment setting.
            'projectAutoComplete' => \app\services\cashier\v3\settlement\CashierV3ProjectPurchasePolicy::enabled(),
            'laborFeeVisible' => (int)sys_config('cashier_labor_fee_mode', 0) !== 1,
            // 本开关只决定会员选择器的默认查询范围，不改变会员归属或后端数据权限。
            'memberDefaultAll' => (int)sys_config('cashier_member_default_all_switch', 0) === 1,
        ];
    }
}
