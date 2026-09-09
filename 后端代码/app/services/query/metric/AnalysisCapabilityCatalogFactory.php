<?php
namespace app\services\query\metric;

/** Adapter for existing providers; future modules register contracts, not user questions. */
final class AnalysisCapabilityCatalogFactory
{
    public static function make(): AnalysisCapabilityCatalog
    {
        $definitions=(new \app\services\metric\MetricDictionaryServices())->getDefinitions();
        $bindings=[];
        foreach (MetricReadViewServices::metricCapabilities() as $code=>$capability) {
            if (($capability['ai_query_ready']??false)!==true) continue;
            // This adapter represents this reader only. It cannot promote arbitrary
            // dictionary entries, person facts or future provider declarations.
            if (($capability['filter_grain']??null)!=='store' || ($capability['business_filters']??null)!==[]) continue;
            $bindings[]=['capability_code'=>'metric_read_view.'.$code,'metric_code'=>$code,
                'object_kind'=>'store','operations'=>$capability['query_shapes'],'filter_keys'=>[],
                'relation_role'=>'store_total','contract_version'=>$capability['metric_version']];
        }
        foreach (PersonnelPerformanceReadServices::capabilities() as $code=>$capability) {
            $bindings[]=['capability_code'=>'personnel_read_view.'.$code,'metric_code'=>$code,'object_kind'=>'person',
                'operations'=>$capability['query_shapes'],'filter_keys'=>['selection_ref'],'relation_role'=>'allocated_employee',
                'contract_version'=>$capability['metric_version']];
        }
        return new AnalysisCapabilityCatalog($definitions,$bindings);
    }
}
