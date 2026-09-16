<?php
// Deterministic protocol tests: no database, model or customer text parsing.
require_once __DIR__.'/fixture-autoload.php';
use app\services\ai\context\IntentContextMerger;
use app\services\ai\execution\AiAnalysisGuidancePlanner;
use app\services\ai\execution\AiRegisteredPlanCompiler;
use app\services\query\metric\PersonnelPerformanceReadServices;
use app\services\query\metric\AnalysisObjectCatalog;
$checks=0;
function qcCheck($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function qcReject($call,$reason){try{$call();}catch(Throwable $e){qcCheck($e->getMessage()===$reason,$reason.' got '.$e->getMessage());return;}throw new RuntimeException('Missing '.$reason);}
function qcNormalize($intent,$safe,$understanding=null){$ranking=(array)($intent['ranking']??[]);$boundCodes=(array)($intent['metric_codes']??[]);if(($intent['context_delta']['metric_codes']??null)==='inherit')$boundCodes=(array)(($safe['prior_query']['metric_codes']??[]));$intent['requirement_bindings']=[['requirement_id'=>'r1','status'=>$boundCodes===[]?'unavailable':'satisfied','metric_codes'=>$boundCodes]];$intent['provenance']=['object_kind'=>['source'=>(($intent['object_kind']??'store')==='store'&&($intent['object_term']??'')==='')?'system':'customer','requirements'=>(($intent['object_kind']??'store')==='store'&&($intent['object_term']??'')==='')?[]:['r1']],'metric_codes'=>['source'=>empty($intent['metric_codes'])?'system':'customer','requirements'=>empty($intent['metric_codes'])?[]:['r1']],'operation'=>['source'=>in_array(($intent['operation']??'summary'),['summary','unknown'],true)?'system':'customer','requirements'=>in_array(($intent['operation']??'summary'),['summary','unknown'],true)?[]:['r1']],'periods'=>['source'=>empty($intent['periods'])?'system':'customer','requirements'=>empty($intent['periods'])?[]:['r1']],'ranking'=>['source'=>(($ranking['direction']??'unspecified')==='unspecified'&&($ranking['limit']??null)===null)?'system':'customer','requirements'=>(($ranking['direction']??'unspecified')==='unspecified'&&($ranking['limit']??null)===null)?[]:['r1']],'scope'=>['source'=>($intent['scope']??'unspecified')==='current_store'?'customer':'system','requirements'=>($intent['scope']??'current_store')==='current_store'?['r1']:[]]];$understanding=$understanding??['requirements'=>[['id'=>'r1','fields'=>['metric_codes','object_kind','operation','periods','ranking','scope']]]];return app\services\ai\contract\AiIntentResultContract::normalize($intent,['staff_labor_yeji'],[],$safe,$understanding);}
$all=app\services\ai\contract\AiIntentResultContract::DELTA_FIELDS;
$delta=array_fill_keys($all,'inherit');
$source=['metric_codes'=>['staff_labor_yeji'],'query_shape'=>'ranking','start_date'=>'2026-09-11','end_date'=>'2026-09-11','compare_range'=>null,'ranking'=>['direction'=>'top','limit'=>1],'store_ids'=>[1],'business_filters'=>['object_kind'=>'person','selection_ref'=>'position:2']];
$view=IntentContextMerger::modelView($source);
qcCheck($view['object_kind']==='person'&&$view['has_object_selection']===true,'model sees only non-sensitive prior shape');
qcCheck($view['has_store_scope_restriction']===true&&$view['has_business_filter']===true,'model receives only non-sensitive presence markers for actual prior restrictions');
qcCheck(!isset($view['store_ids'],$view['business_filters'])&&strpos(json_encode($view),'position:2')===false,'private prior values never leave server');
$suggestedView=IntentContextMerger::modelView($source,['presentation_origin'=>'platform_observation']);
qcCheck($suggestedView['presentation_origin']==='platform_observation','model can distinguish a platform first answer from a customer-selected metric');
qcCheck(array_diff(array_keys($suggestedView),['metric_codes','operation','aggregate_condition','periods','ranking','scope','object_kind','has_store_scope_restriction','has_business_filter','has_object_selection','presentation_origin'])===[],'presentation provenance adds no answer, identity or result field to the model view');
qcCheck(strpos(\app\services\ai\contract\AiIntentResultContract::modelInstruction(true),'one deliberately presented overview group')!==false,
    'binding instruction preserves a model-understood overview group across a contextual follow-up without a metric rule');
qcCheck(strpos(\app\services\ai\contract\AiIntentResultContract::semanticReviewInstruction(),'presentation_origin is platform_observation')!==false,
    'independent review distinguishes a continued platform overview from a new multi-metric answer without a metric rule');
qcCheck(strpos(\app\services\ai\contract\AiIntentResultContract::semanticReviewInstruction(),'generic metric requirement')!==false,
    'independent review leaves broad-versus-specific business meaning to the model rather than a protocol-field shortcut');
qcCheck(strpos(\app\services\ai\contract\AiIntentResultContract::semanticReviewInstruction(),'accept|reject|metric_choice')===false,
    'binding-coverage reviewer is not instructed to emit a decision that only the separate candidate-blind pass may produce');
$summarySource=$source;$summarySource['query_shape']='summary';$summarySource['ranking']=null;
qcCheck(IntentContextMerger::modelView($summarySource)['ranking']===['direction'=>'unspecified','limit'=>null],'non-ranking prior shape projects a structural ranking placeholder without inventing a rank');
$prior=['metric_codes'=>['staff_labor_yeji'],'operation'=>'ranking','periods'=>$view['periods'],'ranking'=>$view['ranking'],'scope'=>'authorized','object_kind'=>'person','has_object_selection'=>true,'has_store_scope_restriction'=>true,'has_business_filter'=>true];
$safe=['question'=>'这个月呢','recent_questions'=>[],'prior_query'=>$prior];
$intent=['object_kind'=>'unknown','object_term'=>'','operation'=>'unknown','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[['kind'=>'month_offset','offset_months'=>0]],'scope'=>'unspecified','context_delta'=>$delta,'unresolved_fragments'=>[],
    'provenance'=>['object_kind'=>['source'=>'system','requirements'=>[]],'metric_codes'=>['source'=>'system','requirements'=>[]],'operation'=>['source'=>'system','requirements'=>[]],'periods'=>['source'=>'customer','requirements'=>['r1']],'ranking'=>['source'=>'system','requirements'=>[]],'scope'=>['source'=>'system','requirements'=>[]]]];
$intent['context_delta']['periods']='replace';
$canonical=qcNormalize($intent,$safe);
$merged=IntentContextMerger::merge($source,$canonical);
qcCheck($merged['intent']['metric_codes']===['staff_labor_yeji']&&$merged['intent']['operation']==='ranking','time-only continuation retains metric and operation');
qcCheck($merged['intent']['ranking']===['direction'=>'top','limit'=>1],'time-only continuation preserves singular ranking without defaulting to five');
qcCheck($merged['intent']['periods']===$intent['periods'],'explicit replacement changes only period');
qcCheck($merged['constraints']['business_filters']===$source['business_filters']&&$merged['constraints']['store_ids']===[1],'delta preserves verified constraints only when explicit inherit');
$limit=$intent;$limit['ranking']=['direction'=>'unspecified','limit'=>5];$limit['context_delta']['ranking_limit']='replace';
$limitMerged=IntentContextMerger::merge($source,qcNormalize($limit,$safe));
qcCheck($limitMerged['intent']['ranking']===['direction'=>'top','limit'=>5],'changing first to first five retains direction through protocol');
$clear=$intent;$clear['context_delta']['business_filters']='clear';
qcReject(function()use($clear,$safe){qcNormalize($clear,$safe);},'AI_MODEL_INTENT_CONTRACT_INVALID');
$unrestrictedSource=$source;$unrestrictedSource['store_ids']=[];$unrestrictedSource['business_filters']=[];
$unrestrictedView=IntentContextMerger::modelView($unrestrictedSource);
qcCheck($unrestrictedView['has_store_scope_restriction']===false&&$unrestrictedView['has_business_filter']===false,'an unrestricted predecessor projects no private placeholder restriction');
$unrestrictedSafe=$safe;$unrestrictedSafe['prior_query']=$unrestrictedView;
$noOpClear=$intent;$noOpClear['context_delta']['store_scope']='clear';$noOpClear['context_delta']['business_filters']='clear';
$noOpCanonical=qcNormalize($noOpClear,$unrestrictedSafe);
qcCheck($noOpCanonical['context_delta']['store_scope']==='inherit'&&$noOpCanonical['context_delta']['business_filters']==='inherit','clearing an already absent signed constraint becomes a no-op rather than a fake customer decision');
$emptySwitch=$noOpCanonical;$emptySwitch['context_delta']['object']='replace';$emptySwitch['object_kind']='person';
$emptySwitchMerge=IntentContextMerger::merge($unrestrictedSource,$emptySwitch);
qcCheck($emptySwitchMerge['constraints']['business_filters']===null&&$emptySwitchMerge['pending']===[]&&!$emptySwitchMerge['replacement_confirmation'],'switching an analytical object after an unrestricted result does not ask to replace a non-existent prior filter');
$dimensionSource=$unrestrictedSource;$dimensionSource['business_filters']=['object_kind'=>'project'];
$dimensionSwitch=IntentContextMerger::merge($dimensionSource,$emptySwitch);
qcCheck($dimensionSwitch['constraints']['business_filters']===null&&$dimensionSwitch['pending']===[]&&!$dimensionSwitch['replacement_confirmation'],
    'switching an analytical dimension does not pretend that the previous dimension was a selected object');
$selectedSwitch=IntentContextMerger::merge($source,$emptySwitch);
qcCheck($selectedSwitch['constraints']['business_filters']===null&&in_array('business_filters',$selectedSwitch['pending'],true)&&$selectedSwitch['replacement_confirmation'],
    'switching away from a signed concrete object still requires an explicit replacement decision');
$pending=$intent;$pending['context_delta']['operation']='pending';$pendingMerged=IntentContextMerger::merge($source,qcNormalize($pending,$safe));
qcCheck(in_array('operation',$pendingMerged['pending'],true)&&$pendingMerged['intent']['operation']==='unknown','uncertain semantic field becomes pending, never a default');
$pendingScope=$intent;$pendingScope['context_delta']['store_scope']='pending';$pendingScopeMerged=IntentContextMerger::merge($source,qcNormalize($pendingScope,$safe));
qcCheck($pendingScopeMerged['constraints']['store_ids']===[1]&&$pendingScopeMerged['fallback_constraints']['store_ids']===[1],'pending store scope retains the signed narrowing until a direct choice');
$pendingPlanner=new app\services\ai\execution\AiPendingContextGuidancePlanner();
$pendingEnvelope=$pendingPlanner->start(['kind'=>'plan','plan'=>['query'=>['store_ids'=>[],'business_filters'=>[]]]],['store_scope'],['store_ids'=>[1],'business_filters'=>$source['business_filters']]);
qcCheck(($pendingEnvelope['fields'][0]['key']??null)==='pending_store_scope','pending context has a server-owned choice envelope');
$retainedEnvelope=$pendingPlanner->choose($pendingEnvelope,['pending_store_scope'=>'retain']);
qcCheck(($retainedEnvelope['inherited_query_constraints']['store_ids']??null)===[1],'explicit retain returns the prior scope only after the choice');
$pendingEnvelope=$pendingPlanner->start(['kind'=>'plan','plan'=>['query'=>['store_ids'=>[],'business_filters'=>[]]]],['store_scope'],['store_ids'=>[1],'business_filters'=>$source['business_filters']]);
$clearedEnvelope=$pendingPlanner->choose($pendingEnvelope,['pending_store_scope'=>'clear_store_scope']);
qcCheck(array_key_exists('store_ids',$clearedEnvelope['inherited_query_constraints'])&&$clearedEnvelope['inherited_query_constraints']['store_ids']===null,'explicit clear is the only way a pending store scope can widen');
$objectPending=$intent;$objectPending['context_delta']['object']='pending';$objectPendingMerged=IntentContextMerger::merge($source,qcNormalize($objectPending,$safe));
qcCheck($objectPendingMerged['fallback_intent']['object_kind']==='person'&&$objectPendingMerged['fallback_constraints']['business_filters']===$source['business_filters'],'pending object retains its verified object binding');
$basePlan=['kind'=>'plan','plan'=>['workflow_code'=>'wf_performance_summary','query'=>['query_shape'=>'summary','metric_codes'=>['staff_labor_yeji'],'start_date'=>'2026-09-01','end_date'=>'2026-09-02','compare_range'=>null,'store_ids'=>[1],'business_filters'=>$source['business_filters'],'ranking'=>null]]];
$filterPending=$pendingPlanner->start($basePlan,['business_filters'],['store_ids'=>[1],'business_filters'=>$source['business_filters']]);
qcReject(function()use($pendingPlanner,$filterPending){$pendingPlanner->choose($filterPending,['pending_business_filters'=>'clear_business_filter']);},'AI_CLARIFICATION_INVALID');
qcCheck(($filterPending['fields'][0]['options']??[])===[['value'=>'retain','label'=>'沿用上一轮已确认条件']],'a personnel selection cannot be cleared into a different aggregate object');
$memberPlan=['kind'=>'plan','plan'=>['workflow_code'=>'wf_performance_ranking','query'=>['query_shape'=>'ranking','metric_codes'=>['cash_performance'],'start_date'=>'2026-09-01','end_date'=>'2026-09-02','compare_range'=>null,'store_ids'=>[1],'business_filters'=>['object_kind'=>'member'],'ranking'=>['direction'=>'top','limit'=>5]],'output_format'=>'screen']];
$memberPending=$pendingPlanner->start($memberPlan,['business_filters'],['store_ids'=>[1],'business_filters'=>['object_kind'=>'member']]);
$clearedFilter=$pendingPlanner->choose($memberPending,['pending_business_filters'=>'clear_business_filter']);
qcCheck($clearedFilter['plan']['query']['business_filters']===['object_kind'=>'member']&&$clearedFilter['inherited_query_constraints']['business_filters']===null,'clearing a member restriction retains the member aggregate object');
$multiPending=$pendingPlanner->start($basePlan,['store_scope','business_filters'],['store_ids'=>[1],'business_filters'=>$source['business_filters']]);
$afterFirst=$pendingPlanner->choose($multiPending,['pending_store_scope'=>'retain']);
qcCheck(($afterFirst['fields'][0]['key']??null)==='pending_business_filters','all pending fields remain in the chain after the first confirmation');
$metricPending=$pendingPlanner->start($basePlan,['metric_codes','periods'],['store_ids'=>[1],'business_filters'=>$source['business_filters']],[['value'=>'metric:cash_performance','label'=>'现金业绩']]);
$afterMetric=$pendingPlanner->choose($metricPending,['pending_metric_codes'=>'metric:cash_performance']);
qcCheck(($afterMetric['fields'][0]['key']??null)==='start_date','registered metric selection advances to explicit date input');
$afterPeriod=$pendingPlanner->choose($afterMetric,['start_date'=>'2026-09-03','end_date'=>'2026-09-04']);
qcCheck($afterPeriod['plan']['query']['metric_codes']===['cash_performance']&&$afterPeriod['plan']['query']['start_date']==='2026-09-03'&&$afterPeriod['plan']['query']['end_date']==='2026-09-04','confirmed metric and period both reach the rebuilt plan');
$comparisonPending=$pendingPlanner->start($memberPlan,['operation'],['store_ids'=>[1],'business_filters'=>['object_kind'=>'member']],[],[],['cash_performance'=>['ranking','comparison']]);
$comparisonDates=$pendingPlanner->choose($comparisonPending,['pending_operation'=>'operation:comparison']);
qcCheck(($comparisonDates['fields'][0]['key']??null)==='compare_start_date','comparison selection requires a separate comparison period before it can execute');
$comparisonPlan=$pendingPlanner->choose($comparisonDates,['compare_start_date'=>'2026-08-01','compare_end_date'=>'2026-08-02']);
qcCheck(($comparisonPlan['plan']['query']['compare_range']??null)===['start'=>'2026-08-01','end'=>'2026-08-02'],'comparison period is preserved as comparison range');
$memberSummaryPlan=$memberPlan;$memberSummaryPlan['plan']['query']['query_shape']='summary';$memberSummaryPlan['plan']['query']['ranking']=null;$memberSummaryPlan['plan']['workflow_code']='wf_performance_summary';
$rankingPending=$pendingPlanner->start($memberSummaryPlan,['operation'],['store_ids'=>[1],'business_filters'=>['object_kind'=>'member']],[],[],['cash_performance'=>['ranking']]);
$rankingOptions=$pendingPlanner->choose($rankingPending,['pending_operation'=>'operation:ranking']);
qcCheck(array_column($rankingOptions['fields'],'key')===['pending_ranking_direction','pending_ranking_limit'],'ranking direction and quantity are confirmed in one bounded step');
$rankingPlan=$pendingPlanner->choose($rankingOptions,['pending_ranking_direction'=>'top','pending_ranking_limit'=>'3']);
qcCheck(($rankingPlan['plan']['query']['ranking']??null)===['direction'=>'top','limit'=>3],'combined ranking confirmation becomes an executable ranking query');
$filterConflict=$intent;$filterConflict['object_kind']='member';$filterConflict['object_term']='某会员';$filterConflict['context_delta']['object']='replace';
$filterConflictMerged=IntentContextMerger::merge($source,qcNormalize($filterConflict,$safe));
qcCheck($filterConflictMerged['constraints']['business_filters']===null&&in_array('business_filters',$filterConflictMerged['pending'],true),'a replacement subject requires explicit confirmation before its inherited business filter can be removed');
$replacementPlanner=new app\services\ai\execution\AiContextReplacementGuidancePlanner();
$replacementStep=$replacementPlanner->start(['kind'=>'plan','plan'=>['query'=>['business_filters'=>[]]]]);
qcCheck(($replacementStep['fields'][0]['key']??null)==='replace_previous_object_filter','object replacement produces an explicit customer confirmation step');
qcReject(function()use($replacementPlanner,$replacementStep){$replacementPlanner->choose($replacementStep,['replace_previous_object_filter'=>'anything_else']);},'AI_CLARIFICATION_INVALID');
qcCheck(($replacementPlanner->choose($replacementStep,['replace_previous_object_filter'=>'replace'])['kind']??null)==='plan','only explicit confirmation releases the replacement branch');
$replacementPending=$replacementPlanner->start($basePlan,['store_scope'],['store_ids'=>[1],'business_filters'=>null]);
$afterReplacement=$replacementPlanner->choose($replacementPending,['replace_previous_object_filter'=>'replace']);
qcCheck(($afterReplacement['fields'][0]['key']??null)==='pending_store_scope','object replacement continues with every remaining pending field');
// A continuation can change its analytical object without pretending that a
// personnel-only metric is a store metric.  The successor must receive only
// registered choices for the replacement object; retaining the incompatible
// metric is deliberately not an available action.
$storeTarget=$intent;
$storeTarget['object_kind']='store';
$storeTarget['operation']='ranking';
$storeTarget['metric_codes']=[];
$storeTarget['ranking']=['direction'=>'top','limit'=>1];
$storeMetricReplacement=$replacementPlanner->start($basePlan,['metric_codes'],['store_ids'=>[1],'business_filters'=>null],
    [['value'=>'metric:cash_performance','label'=>'现金业绩']],$storeTarget,['cash_performance'=>['ranking']]);
$storeMetricChoice=$replacementPlanner->choose($storeMetricReplacement,['replace_previous_object_filter'=>'replace']);
qcCheck(array_column($storeMetricChoice['fields'][0]['options']??[],'value')===['metric:cash_performance'],'replacement object does not offer retain for a metric unavailable to that object');
$storeMetricPlan=$pendingPlanner->choose($storeMetricChoice,['pending_metric_codes'=>'metric:cash_performance']);
qcCheck(($storeMetricPlan['plan']['query']['metric_codes']??null)===['cash_performance']&&($storeMetricPlan['plan']['query']['business_filters']??null)===[],'replacement object executes only after a compatible registered metric is selected');
$storeScope=$intent;$storeScope['object_kind']='store';$storeScope['object_term']='二号门店';$storeScope['context_delta']['object']='replace';$storeScope['context_delta']['store_scope']='replace';
$storeScopeSafe=$safe;$storeScopeSafe['question']='改查二号门店，其他条件不变';
$storeScopeUnderstanding=['requirements'=>[['id'=>'r1','fields'=>['metric_codes','object_kind','operation','periods','ranking','scope'],'values'=>['object_kind'=>'store'],'evidence'=>[['message_id'=>'current','quote'=>'二号门店']]]]];
$storeScopeMerged=IntentContextMerger::merge($source,qcNormalize($storeScope,$storeScopeSafe,$storeScopeUnderstanding));
qcCheck($storeScopeMerged['intent']['object_term']==='二号门店','a replacement store scope preserves its verbatim target for authorized catalog binding');
$badNullRanking=$intent;$badNullRanking['ranking']=['direction'=>null,'limit'=>null,'unregistered_condition'=>'opaque'];
qcReject(function()use($badNullRanking,$safe){qcNormalize($badNullRanking,$safe);},'AI_MODEL_INTENT_CONTRACT_INVALID');
$missing=$intent;unset($missing['context_delta']);
qcReject(function()use($missing,$safe){app\services\ai\contract\AiIntentResultContract::normalize($missing,['staff_labor_yeji'],[],$safe,['requirements'=>[['id'=>'r1','fields'=>['metric_codes','object_kind','operation','periods','ranking','scope']]]]);},'AI_MODEL_INTENT_CONTRACT_INVALID');
foreach(['context_delta','metric_codes','operation'] as $key)qcCheck(app\services\ai\contract\AiIntentResultContract::repairableFormat('missing_key:'.$key),'one retry requests model-owned structural completion for '.$key);
qcCheck(app\services\ai\contract\AiIntentResultContract::repairableFormat('bad_value:requirement_bindings'),'one retry can correct an invalid metric requirement row without authorizing it');
qcCheck(app\services\ai\contract\AiIntentResultContract::repairableFormat('provenance_field_not_understood'),'one retry can align a binding field with accepted understanding without supplying business meaning');
qcCheck(app\services\ai\contract\AiIntentResultContract::repairableFormat('bad_value:result_reference'),'one retry can remove an ungrounded private-result reference without supplying customer meaning');
qcCheck(!app\services\ai\contract\AiIntentResultContract::repairableFormat('missing_replacement:periods'),'server never repairs an absent semantic replacement');
$candidates=['staff_labor_yeji'=>['name'=>'劳动业绩','summary'=>'劳动分配','query_shapes'=>['summary','ranking']]];
$catalog=[['ref'=>'position:2','kind'=>'position','label'=>'合成岗位甲','aliases'=>[],'version'=>'1','relations'=>['staff_labor_yeji']]];
$objects=new AnalysisObjectCatalog($catalog,static function(){return true;});
$resolved=IntentContextMerger::resolveSelection($objects->resolve('','position'),$catalog,$merged['constraints'],'person','staff_labor_yeji');
$planner=new AiAnalysisGuidancePlanner();$projection=['date_terms'=>[['code'=>'EXPLICIT','start'=>'2026-09-01','end'=>'2026-09-11']]];
$compiled=IntentContextMerger::bind($planner->start($merged['intent'],$projection,$candidates,$resolved,'screen','2026-09-11'),$merged['constraints']);
qcCheck($compiled['kind']==='plan','verified object is restored before planner asks for a person');
$caps=['store_ids'=>[1],'metric_codes'=>array_keys($candidates),'metric_readiness'=>PersonnelPerformanceReadServices::capabilities(),'query_shapes'=>['summary','ranking'],'output_formats'=>['screen']];
(new AiRegisteredPlanCompiler())->compile($compiled['plan'],$caps);qcCheck(true,'merged query still satisfies registered compiler');
echo "PASS intent context merger: $checks checks (synthetic protocol only)\n";
