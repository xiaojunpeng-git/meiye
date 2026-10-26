<?php
namespace app\services\cashier\v3\order;

/**
 * 历史充值日期的查询投影。新系统显式业务日期优先，旧记录从支付/创建时间恢复。
 * SQL 固定使用业务时区 UTC+8，不依赖 MySQL 会话时区；不产生资金或权限变更。
 */
final class CashierV3HistoricalRechargeDate
{
    /** 别名固定为充值查询的 r，调用方不能传入任意 SQL 标识符。 */
    public static function sql(): string
    {
        return "COALESCE(NULLIF(NULLIF(r.business_date, '0000-00-00'), ''), "
            . "CASE WHEN COALESCE(NULLIF(r.pay_time, 0), r.add_time) > 0 "
            . "THEN DATE(DATE_ADD('1970-01-01 08:00:00', "
            . "INTERVAL COALESCE(NULLIF(r.pay_time, 0), r.add_time) SECOND)) ELSE NULL END)";
    }
}
