<?php
namespace app\services\report\query;

/** 导出任务仍须重认证当前单表权限；本报表不重放缺少日期/动态列的旧任务。 */
final class BasicStoreReconciliationWorkerContextResolver extends BasicRegistrationWorkerContextResolver
{
    public function pageCode(): string { return \app\services\report\StoreReconciliationReportServices::CODE; }
}
