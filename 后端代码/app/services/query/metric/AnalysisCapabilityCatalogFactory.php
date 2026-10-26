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
            $baseOperations=[];
            foreach ((array)($capability['query_shapes']??[]) as $shape) {
                if (in_array($shape,['condition_count','condition_list'],true)
                    && !in_array($person?'person':'store',(array)($capability['condition_subjects']??[]),true)) continue;
                $baseOperations[]=$shape;
            }
            if ($baseOperations) $bindings[]=['capability_code'=>($person?'personnel_read_view.':'metric_read_view.').$code,'metric_code'=>$code,
                'object_kind'=>$person?'person':'store','operations'=>$baseOperations,
                'filter_keys'=>$person?['selection_ref']:[], 'relation_role'=>$person?'allocated_employee':'store_total',
                'contract_version'=>$capability['metric_version']];
            // Object dimensions are declared by the registered metric. They are
            // not inferred from user phrases or from a report page. A future
            // domain becomes discoverable by registering this lower-layer
            // contract; this factory has no object-name allowlist.
            foreach ((array)($capability['analysis_dimension_contracts']??[]) as $dimension) {
                if (!is_array($dimension)) continue;
                $dimensionOperations=['ranking','breakdown'];
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
                // A local exact-object filter is not an aggregate overview.
                // It is nevertheless a registered summary read when the
                // metric has explicitly declared the selection filter key.
                if (($dimension['filter_keys']??[])===['selection_ref']) $dimensionOperations[]='summary';
                // Aggregate member-threshold queries are also an explicit
                // object-dimension capability.  Admit them only when the
                // metric's registered threshold contract names this exact
                // dimension; ranking metadata alone can never create one.
                $threshold=$capability['threshold_count']??null;
                if (is_array($threshold)
                    && ($threshold['subject_dimension']??null)===($dimension['object_kind']??null)) {
                    $dimensionOperations[]='threshold_count';
                }
                // Generic population conditions are an explicit metric shape,
                // just like ranking and threshold_count.  Publish them for the
                // declared aggregate dimension only; a selected-object contract
                // has different filter keys and is filtered out by the caller.
                // This keeps future objects registry-driven without teaching
                // the gateway a list of member/product/project phrases.
                foreach (['condition_count','condition_list'] as $conditionShape) {
                    if (in_array($conditionShape,(array)($capability['query_shapes']??[]),true)
                        && in_array($dimension['object_kind']??null,(array)($capability['condition_subjects']??[]),true)) {
                        $dimensionOperations[]=$conditionShape;
                    }
                }
                $operations=array_values(array_intersect((array)$capability['query_shapes'],$dimensionOperations));
                if (!$operations) continue;
                $kind=$dimension['object_kind']??null;$role=$dimension['relation_role']??null;
                $filterKeys=$dimension['filter_keys']??null;
                if (!is_string($kind)||!is_string($role)||!is_array($filterKeys)) continue;
                // A metric can intentionally publish both an aggregate and
                // a locally-selected contract for the same object kind. The
                // dimension name is therefore part of the immutable binding
                // identity rather than silently collapsing one contract.
                $bindings[]=['capability_code'=>'metric_dimension.'.$kind.'.'.$dimension['dimension'].'.'.$code,'metric_code'=>$code,
                    'object_kind'=>$kind,'operations'=>$operations,'filter_keys'=>$filterKeys,
                    'relation_role'=>$role,'contract_version'=>$capability['metric_version']];
            }
        }
        return new AnalysisCapabilityCatalog($definitions,$bindings);
    }
}
