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
            // Object dimensions are declared by the registered metric. They are
            // not inferred from user phrases or from a report page. A future
            // domain becomes discoverable by registering this lower-layer
            // contract; this factory has no object-name allowlist.
            foreach ((array)($capability['analysis_dimension_contracts']??[]) as $dimension) {
                if (!is_array($dimension)) continue;
                $dimensionOperations=['ranking'];
                // An object summary is executable only when the same metric
                // explicitly opts into that object's overview profile.  This
                // keeps open project/product summaries registry-driven while
                // preventing every rankable dimension from silently becoming
                // a summary capability.
                foreach ((array)($capability['overview']??[]) as $overview) {
                    if (is_array($overview) && ($overview['object_kind']??null)===($dimension['object_kind']??null)) {
                        $dimensionOperations[]='summary';
                        break;
                    }
                }
                // Aggregate member-threshold queries are also an explicit
                // object-dimension capability.  Admit them only when the
                // metric's registered threshold contract names this exact
                // dimension; ranking metadata alone can never create one.
                $threshold=$capability['threshold_count']??null;
                if (is_array($threshold)
                    && ($threshold['subject_dimension']??null)===($dimension['object_kind']??null)) {
                    $dimensionOperations[]='threshold_count';
                }
                $operations=array_values(array_intersect((array)$capability['query_shapes'],$dimensionOperations));
                if (!$operations) continue;
                $kind=$dimension['object_kind']??null;$role=$dimension['relation_role']??null;
                $filterKeys=$dimension['filter_keys']??null;
                if (!is_string($kind)||!is_string($role)||!is_array($filterKeys)) continue;
                $bindings[]=['capability_code'=>'metric_dimension.'.$kind.'.'.$code,'metric_code'=>$code,
                    'object_kind'=>$kind,'operations'=>$operations,'filter_keys'=>$filterKeys,
                    'relation_role'=>$role,'contract_version'=>$capability['metric_version']];
            }
        }
        return new AnalysisCapabilityCatalog($definitions,$bindings);
    }
}
