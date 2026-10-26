<?php
namespace app\services\report\query;

/** basic_daily_reconciliation独立任务身份绑定；复用基础报表的当前权限重认证，不信任历史任务范围。 */
final class BasicDailyReconciliationWorkerContextResolver extends BasicRegistrationWorkerContextResolver
{
    public function pageCode(): string { return 'basic_daily_reconciliation'; }
}
