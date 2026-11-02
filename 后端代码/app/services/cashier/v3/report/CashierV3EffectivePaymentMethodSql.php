<?php
declare(strict_types=1);

namespace app\services\cashier\v3\report;

/**
 * 统一报表的有效收款方式 SQL 契约。原 payment_collected 与销售分摊事实
 * 不改写；反向事实通过 reversal_of 继承原收款的更正投影，以保持净额抵销。
 */
final class CashierV3EffectivePaymentMethodSql
{
    /** 给已有 payment_fact 别名附加当前方式投影，别名由调用方固定为可信常量。 */
    public static function join($query, string $fact, string $current, string $reversal): void
    {
        $query->leftJoin('cashier_v3_payment_method_current ' . $current,
            $current . '.tenant_id=' . $fact . '.tenant_id AND '
            . $current . '.payment_fact_id=' . $fact . '.fact_id');
        $query->leftJoin('cashier_v3_payment_method_current ' . $reversal,
            $reversal . '.tenant_id=' . $fact . '.tenant_id AND '
            . $reversal . '.payment_fact_id=' . $fact . '.reversal_of');
    }

    /** 返回后端统一的有效代码表达式；金额和业务日仍取原事实。 */
    public static function expression(string $fact, string $current, string $reversal): string
    {
        return 'COALESCE(' . $current . '.current_method,' . $reversal . '.current_method,'
            . $fact . '.payment_method)';
    }
}
