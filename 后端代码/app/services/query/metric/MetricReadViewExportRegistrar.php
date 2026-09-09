<?php
namespace app\services\query\metric;

use app\services\query\UnifiedQueryPageRegistrar;
use app\services\query\UnifiedQueryPageRegistry;

/** Export-only projection of a previously authorized shared metric read view. */
final class MetricReadViewExportRegistrar implements UnifiedQueryPageRegistrar
{
    public function register(UnifiedQueryPageRegistry $registry): void
    {
        $fields=[];
        foreach (self::fields() as $key=>$label) $fields[]=UnifiedQueryPageRegistry::field($key,$label,$key==='amount_yuan'?'amount':'text',true,false,[],'',true);
        $registry->registerPage(MetricReadViewExportProvider::PAGE_CODE,'经营问数结果',$fields,'row_id',[
            'keywordFields'=>[], 'requiredFeature'=>'mohe.ai.export', 'exportFeature'=>'mohe.ai.export', 'scopeDimensions'=>['metric_read_ref'],
        ]);
    }
    public static function fields(): array
    {
        return ['row_id'=>'结果编号','metric_name'=>'指标','period_name'=>'统计周期','start_date'=>'开始日期','end_date'=>'结束日期',
            'store_name'=>'对象/范围','ranking_direction'=>'排行方向','business_date'=>'业务日期','amount_yuan'=>'金额（元）'];
    }
}
