<?php
namespace app\services\report\query;

/** 单表身份绑定；统一执行器和后端逐表权限负责查询及导出范围。 */
final class BasicSalesAnalysisWorkerContextResolver extends BasicRegistrationWorkerContextResolver
{
    public function pageCode(): string { return 'basic_sales_analysis'; }
}
