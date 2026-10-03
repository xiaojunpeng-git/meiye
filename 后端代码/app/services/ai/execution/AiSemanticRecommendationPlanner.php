<?php
namespace app\services\ai\execution;

use app\services\query\metric\MetricSemanticCatalog;

/** Creates dry-run candidates from published contracts, never from customer
 * keywords. The language model ranks these candidates; it cannot author SQL,
 * alter a locked condition or turn an unavailable metric into a recommendation. */
final class AiSemanticRecommendationPlanner
{
    public function candidates(array $meaning,array $capabilities,string $today,string $format,callable $preflight,array $labels,?array $source=null,?int $currentStoreId=null): array
    {
        if (!empty($meaning['groups'])) return [];
        $values=[];
        foreach ($meaning['requirements'] as $requirement) {
            foreach ($requirement['fields'] as $field) {
                // Selection, cohort predicates and private references need the
                // ordinary semantic compiler. Never drop them to fill a card.
                if (!in_array($field,['object_kind','object_relation','operation','periods','metric_codes','ranking','scope'],true)) return [];
            }
            foreach ($requirement['values'] as $key=>$value) {
                // Selecting a named/private object is not equivalent to an
                // unfiltered analytical dimension. Never discard an opaque
                // object term simply because its field is object_kind.
                if (!in_array($key,['object_kind','object_relation','operation','periods','metric_terms','metric_exclusions','ranking','scope'],true)) return [];
                if (isset($values[$key]) && $values[$key]!==$value) return [];
                $values[$key]=$value;
            }
        }
        if (!empty($values['metric_exclusions'])) return [];
        if (isset($values['object_relation']) && $values['object_relation']!=='analysis') return [];
        // A model may legitimately preserve the customer's scope while asking
        // for a metric choice. Authorized/unspecified use the Reader's scope;
        // current_store is offered only with a freshly authenticated origin.
        $scope=$values['scope']??'unspecified';
        if (!in_array($scope,['authorized','unspecified','current_store'],true)) return [];
        if ($scope==='current_store' && ($currentStoreId===null || $currentStoreId<=0
            || !in_array($currentStoreId,$capabilities['store_ids']??[],true))) return [];
        // Only a model-declared continuation may reuse a signed query. Keep
        // its complete private scope/conditions on the server; never rebuild
        // them from the public model summary or a displayed object name.
        if ($source!==null && !isset($meaning['topic_relation'])) return [];
        if (($meaning['topic_relation']??null)!=='continuation') $source=null;
        // Explicitly asking for the whole authorized range cannot quietly
        // retain a narrower predecessor. The ordinary binder handles that
        // scope change instead of publishing misleading recommendations.
        if ($source!==null && $scope==='authorized' && ($source['store_ids']??[])!==[]) return [];
        $sourceKind=$source['business_filters']['object_kind']??'store';
        $kind=$values['object_kind']??($source===null?'store':$sourceKind);
        if ($source!==null && $kind!==$sourceKind) return [];
        if ($kind==='unknown') return [];
        if (!isset($labels[$kind])) return [];
        // A stated rank is part of the customer's requested result, not an
        // optional display hint. It overrides an inherited response form;
        // contradictory explicit forms cannot become unranked alternatives.
        $hasRanking=array_key_exists('ranking',$values);
        if ($hasRanking && isset($values['operation']) && $values['operation']!=='ranking') return [];
        $operation=$values['operation']??($hasRanking?'ranking':($source['query_shape']??null));
        $shapes=$operation===null?['summary','breakdown','ranking']:[$operation];
        $terms=$values['metric_terms']??[];
        // Coordinated named metrics require one complete multi-metric plan;
        // a recommendation cannot keep only one side of the conjunction.
        if (count($terms)>1) return [];
        $exact=MetricSemanticCatalog::uniqueCodeForTerms($terms,$capabilities['metric_codes']??[]);
        $periods=$values['periods']??[];
        if ($periods===[] && $source!==null) $periods=\app\services\ai\context\IntentContextMerger::modelView($source)['periods'];
        $planner=new AiWorkflowPlanner();$dates=[];
        foreach ($periods as $period) {
            $range=$planner->normalizeNaturalPeriod($period,$today);
            $dates[]=['code'=>'EXPLICIT','start'=>$range['start'],'end'=>$range['end']];
        }
        $pool=[];
        foreach ($shapes as $shape) {
            if (!in_array($shape,['summary','breakdown','ranking','trend','comparison'],true)) continue;
            // A named-member summary requires a private identity. A group of
            // members cannot borrow that contract merely because it exists.
            if ($kind==='member' && $shape==='summary') continue;
            $metrics=AiCapabilityGuidanceCatalog::discover($capabilities,$kind,$shape);
            foreach ($metrics as $code=>$metric) {
                if ($exact!==null && $code!==$exact) continue;
                // Existing amount predicates belong to their original metric;
                // retaining SQL fields while changing that metric is not
                // sufficient to preserve the customer's business condition.
                if ($source!==null && !empty($source['aggregate_condition'])
                    && ($source['metric_codes']??[])!==[$code]) continue;
                $timeSemantics=\app\services\query\metric\MetricDefinitionRegistry::classification($code)['time_semantics'];
                if ($periods!==[] && $timeSemantics==='current_state') continue;
                $rankings=$shape==='ranking'?([$values['ranking']??$source['ranking']??['direction'=>'top','limit'=>20]]):[null];
                foreach ($rankings as $ranking) {
                    $projection=['dates'=>[],'date_terms'=>$dates,'signals'=>[$code,$shape],
                        'semantic_intent'=>['constraints'=>[]],'blocking_reason'=>null,'unresolved_condition'=>false];
                    try {
                        $compiled=$planner->compile($projection,['decision'=>'query','query_shape'=>$shape,
                            'metric_codes'=>[$code],'object_kind'=>$kind,'ranking'=>$ranking],$capabilities,$format,$today);
                        if (($compiled['kind']??null)!=='plan') continue;
                        // The generic planner's store ranking has no business
                        // filter. Keep the selected dimension explicit before
                        // preflight so a member ranking cannot become a store
                        // ranking when its analytical subject is otherwise lost.
                        if ($kind!=='store') $compiled['plan']['query']['business_filters']=['object_kind'=>$kind];
                        if ($kind==='person') {
                            $cohort=$metric['default_selection_ref']??null;
                            if (!is_string($cohort) || strpos($cohort,'cohort:')!==0) continue;
                            $compiled['plan']['query']['business_filters']['selection_ref']=$cohort;
                        }
                        if ($source!==null) {
                            $compiled['plan']['query']['store_ids']=$source['store_ids'];
                            $sourceFilters=$source['business_filters'];
                            // An unselected store dimension has two registered
                            // carriers: breakdown uses object_kind=store,
                            // ranking/summary uses the store fact grain. This
                            // changes only that presentation carrier; the
                            // signed store_ids still enforce the same scope.
                            if ($sourceKind==='store' && ($sourceFilters===[] || $sourceFilters===['object_kind'=>'store'])) {
                                $sourceFilters=$shape==='breakdown'?['object_kind'=>'store']:[];
                            }
                            $compiled['plan']['query']['business_filters']=$sourceFilters;
                            $compiled['plan']['query']['aggregate_condition']=$source['aggregate_condition']??null;
                        }
                        if ($scope==='current_store') {
                            $existing=$compiled['plan']['query']['store_ids']??[];
                            if ($existing!==[] && $existing!==[$currentStoreId]) continue;
                            $compiled['plan']['query']['store_ids']=[$currentStoreId];
                        }
                        $preflight($compiled['plan']);
                    } catch (\app\services\ai\contract\AiContractException $error) { continue;
                    } catch (\app\services\query\metric\MetricQueryContractException $error) { continue;
                    } catch (\RuntimeException $error) {
                        // Registry compilation can reject a shape or execution
                        // budget. These are ineligible candidates, not queries.
                        if (strpos($error->getMessage(),'AI_')!==0) throw $error;
                        continue;
                    }
                    $id='candidate_'.(count($pool)+1);
                    $objectLabel=$labels[$kind];
                    $forms=['summary'=>'合计','breakdown'=>'逐个明细','ranking'=>'排名','trend'=>'按日变化','comparison'=>'期间对比'];
                    $label=$objectLabel.'的'.$metric['name'].'（'.$forms[$shape].'）';
                    if ($timeSemantics==='current_state') $label='当前'.$label;
                    if ($ranking!==null) $label=$objectLabel.'的'.$metric['name'].'（'.($ranking['direction']==='bottom'?'最低':($ranking['direction']==='top_and_bottom'?'最高和最低':'最高')).($ranking['limit']??20).'名）';
                    $pool[$id]=['label'=>$label,'meaning'=>$metric['summary'],'object_kind'=>$kind,
                        'query_shape'=>$shape,'compiled'=>$compiled];
                }
            }
        }
        return $pool;
    }

    /** Only three distinct server-owned candidate IDs can be published. The
     * model text never becomes a label or an executable plan. */
    public function select(array $pool,$ids): array
    {
        if (!is_array($ids) || array_keys($ids)!==[0,1,2] || count(array_filter($ids,'is_string'))!==3 || count(array_unique($ids))!==3) {
            throw new \RuntimeException('AI_RECOMMENDATION_INVALID');
        }
        $selected=[];
        foreach ($ids as $id) {
            if (!is_string($id) || !isset($pool[$id])) throw new \RuntimeException('AI_RECOMMENDATION_INVALID');
            $selected[$id]=$pool[$id];
        }
        return $selected;
    }
}
