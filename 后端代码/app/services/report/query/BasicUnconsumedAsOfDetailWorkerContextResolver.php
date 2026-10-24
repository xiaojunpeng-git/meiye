<?php
namespace app\services\report\query;

/** basic_unconsumed_as_of_detail独立任务身份绑定；复用基础报表的当前权限重认证，不信任历史任务范围。 */
final class BasicUnconsumedAsOfDetailWorkerContextResolver extends BasicRegistrationWorkerContextResolver
{
    public function pageCode(): string { return 'basic_unconsumed_as_of_detail'; }
}
