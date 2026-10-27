<?php
namespace app\services\report\query;

/** basic_employee_summary的显式页面绑定；执行逻辑只维护在共用事实查询提供器中。 */
final class BasicEmployeeSummaryQueryProvider extends BasicBusinessQueryProvider
{
    protected const CODE = 'basic_employee_summary';
}
