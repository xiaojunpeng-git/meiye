<?php
namespace app\services\report\query;

/** basic_unconsumed_summary的显式页面绑定；执行逻辑只维护在共用事实查询提供器中。 */
final class BasicUnconsumedSummaryQueryProvider extends BasicBusinessQueryProvider
{
    protected const CODE = 'basic_unconsumed_summary';
}
