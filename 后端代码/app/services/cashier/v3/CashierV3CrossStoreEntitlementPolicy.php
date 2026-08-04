<?php

namespace app\services\cashier\v3;

/**
 * V3 卡内项目统一跨店核销口径。
 *
 * 购卡门店是历史来源，实际服务门店是本次核销归属；来源门店不能再限制会员
 * 在其它门店使用其有效权益。
 */
final class CashierV3CrossStoreEntitlementPolicy
{
    public static function enabled(): bool
    {
        return true;
    }
}
