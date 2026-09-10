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
            $person=($capability['filter_grain']??null)==='person';
            $bindings[]=['capability_code'=>($person?'personnel_read_view.':'metric_read_view.').$code,'metric_code'=>$code,
                'object_kind'=>$person?'person':'store','operations'=>$capability['query_shapes'],
                'filter_keys'=>$person?['selection_ref']:[], 'relation_role'=>$person?'allocated_employee':'store_total',
                'contract_version'=>$capability['metric_version']];
        }
        return new AnalysisCapabilityCatalog($definitions,$bindings);
    }
}
