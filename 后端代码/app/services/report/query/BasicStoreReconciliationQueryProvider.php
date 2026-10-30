<?php
namespace app\services\report\query;

use app\services\query\UnifiedQueryProvider;
use app\services\report\StoreReconciliationReportServices;

/** 门店对账独立查询能力；动态列在认证范围确定后由同一统一执行器登记与校验。 */
final class BasicStoreReconciliationQueryProvider implements UnifiedQueryProvider
{
    public function pageCode(): string { return StoreReconciliationReportServices::CODE; }
    public function query(array $context, array $payload): array
    {
        return (new StoreReconciliationReportServices())->query($context, $payload);
    }
    /** 缺少原动态列及业务日期的任务不能推断为当前查询；页面支持同口径完整同步导出。 */
    public function executeFrozenPlan(array $context, array $plan, string $exportScope, array $fieldKeys): array
    {
        throw new \InvalidArgumentException('请从当前门店对账查询结果导出');
    }
}
