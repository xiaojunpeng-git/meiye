<?php
declare(strict_types=1);

namespace app\services\report;

use think\facade\Db;

/**
 * 旧系统用 store_order 承载充值收款；迁入后的充值支付事实与该壳单是同一笔钱。
 * 只在已有可追溯的正向迁入事实且充值 ID、门店、会员、收款时间均相同时，移除旧壳单的统计投影。
 * 原订单与支付事实保持原样，作废、退款和审计仍使用各自的权威记录。
 */
final class LegacyRechargeCarrierScope
{
    /** 查询别名仅由调用方固定传入，禁止把请求参数拼进相关子查询。 */
    public static function excludeCovered($query, string $tenant, string $orderAlias = ''): void
    {
        if ($orderAlias !== '' && $orderAlias !== 'b') {
            throw new \InvalidArgumentException('旧充值壳单查询别名无效');
        }
        $order = $orderAlias !== '' ? $orderAlias : Db::name('store_order')->getTable();
        $query->whereNotExists(static function ($fact) use ($tenant, $order): void {
            $fact->name('cashier_v3_payment_fact')->alias('covered_recharge_payment')
                ->where('covered_recharge_payment.tenant_id', $tenant)
                ->where('covered_recharge_payment.source_document_type', 'recharge')
                ->where('covered_recharge_payment.fact_direction', 'forward')
                ->whereIn('covered_recharge_payment.fact_type', ['payment_collected', 'payment_received'])
                ->where('covered_recharge_payment.payment_authority_key', 'like', 'legacy-recharge:%')
                ->whereRaw($order . '.pid=-2 AND ' . $order . '.link_id>0')
                ->whereRaw("covered_recharge_payment.order_id=CONCAT('RCH:'," . $order . '.link_id)')
                ->whereRaw('covered_recharge_payment.store_id=' . $order . '.store_id')
                ->whereRaw('covered_recharge_payment.member_id=' . $order . '.uid')
                // 同会员同店历史上有过复用 link_id 的零元壳单；一小时收款窗口防止误排其他日期。
                ->whereRaw($order . '.pay_time BETWEEN covered_recharge_payment.settled_at-3600 AND covered_recharge_payment.settled_at+3600');
        });
    }
}
