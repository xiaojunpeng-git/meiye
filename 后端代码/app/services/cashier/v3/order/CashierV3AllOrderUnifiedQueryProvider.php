<?php
namespace app\services\cashier\v3\order;

/** 四类订单共用权威查询、权限和导出契约，不建立新的资金事实。 */
final class CashierV3AllOrderUnifiedQueryProvider extends AbstractCashierV3OrderCenterUnifiedQueryProvider
{
    public function pageCode(): string
    {
        return CashierV3OrderCenterUnifiedQueryContract::PAGE_BY_TYPE['all'];
    }
}
