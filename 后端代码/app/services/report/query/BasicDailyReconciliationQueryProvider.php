<?php
namespace app\services\report\query;

/** basic_daily_reconciliation的显式页面绑定；执行逻辑只维护在共用事实查询提供器中。 */
final class BasicDailyReconciliationQueryProvider extends BasicBusinessQueryProvider
{
    protected const CODE = 'basic_daily_reconciliation';
}
