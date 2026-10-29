<?php
namespace app\services\report\query;

use app\services\query\UnifiedQueryPageRegistrar;
use app\services\query\UnifiedQueryPageRegistry;
use app\services\report\StoreReconciliationReportServices;

/** 静态能力目录只登记公共字段；动态门店/日期列在认证范围确定后建立本次查询白名单。 */
final class BasicStoreReconciliationQueryRegistrar implements UnifiedQueryPageRegistrar
{
    public function register(UnifiedQueryPageRegistry $registry): void
    {
        $fields = [UnifiedQueryPageRegistry::field('row_id', '行编号', 'text')];
        foreach ((new StoreReconciliationReportServices())->definitions(StoreReconciliationReportServices::CODE) as $field) {
            $fields[] = UnifiedQueryPageRegistry::field($field['key'], $field['label'], $field['type'], $field['defaultVisible'], $field['defaultQuick']);
        }
        $registry->registerPage(StoreReconciliationReportServices::CODE, '门店对账单', $fields, 'row_id',
            ['keywordFields' => ['payment_name'], 'requiredFeature' => 'cashier.v3.data']);
    }
}
