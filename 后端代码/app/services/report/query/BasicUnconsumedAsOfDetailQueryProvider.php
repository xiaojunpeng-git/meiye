<?php
namespace app\services\report\query;

/** basic_unconsumed_as_of_detail的显式页面绑定；执行逻辑只维护在共用事实查询提供器中。 */
final class BasicUnconsumedAsOfDetailQueryProvider extends BasicBusinessQueryProvider
{
    protected const CODE = 'basic_unconsumed_as_of_detail';
}
