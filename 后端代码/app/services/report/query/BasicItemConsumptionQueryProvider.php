<?php
namespace app\services\report\query;

/** basic_item_consumption的显式页面绑定；执行逻辑只维护在共用事实查询提供器中。 */
final class BasicItemConsumptionQueryProvider extends BasicBusinessQueryProvider
{
    protected const CODE = 'basic_item_consumption';
}
