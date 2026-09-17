<?php
/** Contract tests use only fixed model-shaped data; no provider or database. */
require_once __DIR__ . '/fixture-autoload.php';

use app\services\ai\contract\AiContractException;
use app\services\ai\contract\AiIntentResultContract;
use app\services\ai\contract\AiIntentUnderstandingContract;

$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void {if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;};
$reject=static function(callable $call,string $label)use($check):void {try{$call();}catch(AiContractException $error){$check(true,$label);return;}throw new RuntimeException('FAIL accepted '.$label);};
$question=['schema_version'=>'sanitized-question-v2','question'=>'这个月收款，不要退款，前五家门店。','has_unresolved_conditions'=>false,
    'server_resolved_fields'=>[],'reference_date'=>'2026-09-12','recent_questions'=>['上个月服务情况'],
    'evidence_messages'=>[['id'=>'current','text'=>'这个月收款，不要退款，前五家门店。'],['id'=>'recent_1','text'=>'上个月服务情况']], 'prior_query'=>null];
$understanding=['goal'=>'查看本月收款，排除退款并列出前五家门店','status'=>'understood','requirements'=>[
    ['id'=>'r1','meaning'=>'查看本月收款','fields'=>['metric_codes','periods'],'values'=>['metric_terms'=>['收款'],'periods'=>[['kind'=>'month_offset','offset_months'=>0]]],'evidence'=>[['message_id'=>'current','quote'=>'这个月收款']]],
    ['id'=>'r2','meaning'=>'排除退款','fields'=>['metric_codes'],'values'=>['metric_exclusions'=>['退款']],'evidence'=>[['message_id'=>'current','quote'=>'不要退款']]],
    ['id'=>'r3','meaning'=>'列出前五家门店','fields'=>['object_kind','operation','ranking'],'values'=>['object_kind'=>'store','operation'=>'ranking','ranking'=>['direction'=>'top','limit'=>5]],'evidence'=>[['message_id'=>'current','quote'=>'前五家门店']]],
]];
$understanding=AiIntentUnderstandingContract::normalize($understanding,$question);
$check(AiIntentUnderstandingContract::ids($understanding)===['r1','r2','r3'],'understanding has request-local requirement identities');
$base=['object_kind'=>'store','object_term'=>'','operation'=>'ranking','metric_codes'=>['cash_performance'],'action_codes'=>[],
    'needs_metric_choice'=>false,'ranking'=>['direction'=>'top','limit'=>5],'periods'=>[['kind'=>'month_offset','offset_months'=>0]],
    'scope'=>'authorized','requirement_bindings'=>[
        ['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['cash_performance']],
        ['requirement_id'=>'r2','status'=>'satisfied','metric_codes'=>['cash_performance']],
    ],'unresolved_fragments'=>[],
    'provenance'=>[
        'object_kind'=>['source'=>'customer','requirements'=>['r3']],
        'metric_codes'=>['source'=>'customer','requirements'=>['r1','r2']],
        'operation'=>['source'=>'customer','requirements'=>['r3']],
        'ranking'=>['source'=>'customer','requirements'=>['r3']],
        'periods'=>['source'=>'customer','requirements'=>['r1']],
        'scope'=>['source'=>'system','requirements'=>[]],
    ]];
$bound=AiIntentResultContract::normalize($base,['cash_performance'],[],$question,$understanding);
$check($bound['metric_codes']===['cash_performance']&&$bound['provenance']['ranking']['requirements']===['r3'],'binding separately consumes accepted understanding');
$multipleRankingRecommendation=$base;$multipleRankingRecommendation['metric_codes']=['cash_performance','actual_performance'];$multipleRankingRecommendation['recommended_initial_answer']=true;
foreach($multipleRankingRecommendation['requirement_bindings'] as &$row)$row['metric_codes']=['cash_performance','actual_performance'];unset($row);
$reject(static function()use($multipleRankingRecommendation,$question,$understanding){AiIntentResultContract::normalize($multipleRankingRecommendation,['cash_performance','actual_performance'],[],$question,$understanding);},'a single ranking result cannot carry several competing measurement codes');
$wrongMetric=$base;$wrongMetric['metric_codes']=['refund_performance'];$wrongMetric['requirement_bindings']=[['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['refund_performance']],['requirement_id'=>'r2','status'=>'unavailable','metric_codes'=>[]]];
$reject(static function()use($wrongMetric,$question,$understanding){AiIntentResultContract::normalize($wrongMetric,['cash_performance','refund_performance'],[],$question,$understanding);},'binding cannot omit an explicitly understood exclusion requirement');
$wrongDerivedMetric=$base;$wrongDerivedMetric['metric_codes']=['actual_performance'];$wrongDerivedMetric['requirement_bindings']=[['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['actual_performance']],['requirement_id'=>'r2','status'=>'pending','metric_codes'=>[]]];
$reject(static function()use($wrongDerivedMetric,$question,$understanding){AiIntentResultContract::normalize($wrongDerivedMetric,['cash_performance','refund_performance','actual_performance'],[],$question,$understanding);},'binding cannot execute while one understood metric requirement remains pending');
$cashRefundQuestion=$question;$cashRefundQuestion['question']='收款，但不要现金退款';$cashRefundQuestion['evidence_messages'][0]['text']=$cashRefundQuestion['question'];
$cashRefundUnderstanding=AiIntentUnderstandingContract::normalize(['goal'=>'查看收款并排除现金退款','status'=>'understood','requirements'=>[
    ['id'=>'r1','meaning'=>'查看收款','fields'=>['metric_codes'],'values'=>['metric_terms'=>['收款']],'evidence'=>[['message_id'=>'current','quote'=>'收款']]],
    ['id'=>'r2','meaning'=>'排除现金退款','fields'=>['metric_codes'],'values'=>['metric_exclusions'=>['现金退款']],'evidence'=>[['message_id'=>'current','quote'=>'现金退款']]],
]],$cashRefundQuestion);
$cashRefundBinding=$base;$cashRefundBinding['operation']='summary';$cashRefundBinding['ranking']=['direction'=>'unspecified','limit'=>null];$cashRefundBinding['periods']=[];$cashRefundBinding['requirement_bindings']=[['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['cash_performance']],['requirement_id'=>'r2','status'=>'satisfied','metric_codes'=>['cash_performance']]];
$check(AiIntentResultContract::normalize($cashRefundBinding,['cash_performance'],[],$cashRefundQuestion,$cashRefundUnderstanding)['metric_codes']===['cash_performance'],'an exclusion mentioning cash refunds does not reject cash performance by word overlap');
$reorderedRanking=$base;$reorderedRanking['ranking']=['limit'=>5,'direction'=>'top'];
$check(AiIntentResultContract::normalize($reorderedRanking,['cash_performance'],[],$question,$understanding)['ranking']['limit']===5,'equivalent JSON object member order does not invalidate a binding');
$unbound=$base;$unbound['metric_codes']=[];$unbound['requirement_bindings']=[['requirement_id'=>'r1','status'=>'unavailable','metric_codes'=>[]],['requirement_id'=>'r2','status'=>'unavailable','metric_codes'=>[]]];$unbound['provenance']['metric_codes']=['source'=>'system','requirements'=>[]];
$check(AiIntentResultContract::normalize($unbound,[],[],$question,$understanding)['metric_codes']===[],'clear unbound meaning does not need a fake metric');
$missing=$base;unset($missing['provenance']);$check(AiIntentResultContract::normalize($missing,['cash_performance'],[],$question,$understanding)['provenance']['metric_codes']['requirements']===['r1','r2'],'server derives provenance when a model omits bookkeeping');
$missingFragments=$base;unset($missingFragments['unresolved_fragments']);
$check(AiIntentResultContract::normalize($missingFragments,['cash_performance'],[],$question,$understanding)['unresolved_fragments']===[],
    'an omitted empty unresolved-fragments carrier cannot block an otherwise complete business binding');
$spuriousFreshReference=$base;$spuriousFreshReference['result_reference']=['group'=>'top','ordinal'=>1];
$check(AiIntentResultContract::normalize($spuriousFreshReference,['cash_performance'],[],$question,$understanding)['result_reference']===null,
    'a fresh conversation discards an inert result-reference transport field instead of retrying customer meaning');
$malformed=$base;$malformed['provenance']='not-trusted';$check(AiIntentResultContract::normalize($malformed,['cash_performance'],[],$question,$understanding)['provenance']['ranking']['requirements']===['r3'],'malformed model provenance cannot block a valid query or alter the audit record');
$nullScope=$base;$nullScope['scope']=null;$check(AiIntentResultContract::normalize($nullScope,['cash_performance'],[],$question,$understanding)['scope']==='unspecified','null optional scope is not an implicit range change');
$invented=$base;$invented['ranking']=['direction'=>'top','limit'=>9];$invented['provenance']['ranking']=['source'=>'system','requirements'=>[]];
$anchoredRanking=AiIntentResultContract::normalize($invented,['cash_performance'],[],$question,$understanding);
$check($anchoredRanking['ranking']===['direction'=>'top','limit'=>5],'binding cannot replace a typed natural-language ranking from accepted understanding');
$naturalQuestion=$question;$naturalQuestion['question']='这个月到账多少款？';$naturalQuestion['evidence_messages'][0]['text']=$naturalQuestion['question'];
$naturalUnderstanding=AiIntentUnderstandingContract::normalize(['goal'=>'查看这个月到账金额','status'=>'understood','requirements'=>[
    ['id'=>'r1','meaning'=>'查看这个月到账金额','fields'=>['metric_codes','periods'],'values'=>['metric_terms'=>['到账'],'periods'=>[['kind'=>'month_offset','offset_months'=>0]]],'evidence'=>[['message_id'=>'current','quote'=>'这个月到账多少款']]],
]],$naturalQuestion);
$naturalBinding=$base;$naturalBinding['operation']='summary';$naturalBinding['ranking']=['direction'=>'unspecified','limit'=>null];$naturalBinding['periods']=[['kind'=>'month_offset','offset_months'=>0]];$naturalBinding['requirement_bindings']=[['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['cash_performance']]];
$check(AiIntentResultContract::normalize($naturalBinding,['cash_performance'],[],$naturalQuestion,$naturalUnderstanding)['metric_codes']===['cash_performance'],'natural meaning is not rejected because its customer wording is absent from metric aliases');
$missingAnalyticalTerm=$naturalBinding;unset($missingAnalyticalTerm['object_term']);
$check(AiIntentResultContract::normalize($missingAnalyticalTerm,['cash_performance'],[],$naturalQuestion,$naturalUnderstanding)['object_term']==='',
    'an omitted object identity normalizes only to empty where accepted meaning has no selected object');
$selectedObjectQuestion=$question;$selectedObjectQuestion['question']='二号门店的收款是多少？';$selectedObjectQuestion['recent_questions']=[];$selectedObjectQuestion['evidence_messages']=[['id'=>'current','text'=>$selectedObjectQuestion['question']]];
$selectedObjectUnderstanding=AiIntentUnderstandingContract::normalize(['goal'=>'查询二号门店收款','status'=>'understood','requirements'=>[
    ['id'=>'r1','meaning'=>'二号门店','fields'=>['object_kind','object_relation'],'values'=>['object_kind'=>'store','object_relation'=>'selection'],'evidence'=>[['message_id'=>'current','quote'=>'二号门店']]],
    ['id'=>'r2','meaning'=>'收款','fields'=>['metric_codes'],'values'=>['metric_terms'=>['收款']],'evidence'=>[['message_id'=>'current','quote'=>'收款']]],
]],$selectedObjectQuestion);
$missingSelectedTerm=$base;unset($missingSelectedTerm['object_term']);
$reject(static function()use($missingSelectedTerm,$selectedObjectQuestion,$selectedObjectUnderstanding){AiIntentResultContract::normalize($missingSelectedTerm,['cash_performance'],[],$selectedObjectQuestion,$selectedObjectUnderstanding);},
    'an omitted customer-selected object identity remains a hard contract failure');
$overviewQuestion=$question;$overviewQuestion['question']='今天经营怎么样？';$overviewQuestion['recent_questions']=[];$overviewQuestion['evidence_messages']=[['id'=>'current','text'=>$overviewQuestion['question']]];
$overviewUnderstanding=AiIntentUnderstandingContract::normalize(['goal'=>'了解今天整体经营情况','status'=>'understood','requirements'=>[
    ['id'=>'r1','meaning'=>'了解整体经营情况','fields'=>['metric_codes'],'values'=>['metric_terms'=>['经营']],'evidence'=>[['message_id'=>'current','quote'=>'经营']]],
    ['id'=>'r2','meaning'=>'今天','fields'=>['periods'],'values'=>['periods'=>[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]]],'evidence'=>[['message_id'=>'current','quote'=>'今天']]],
]],$overviewQuestion);
$overviewBinding=$naturalBinding;$overviewBinding['metric_codes']=['cash_performance','actual_performance','consume_amount'];$overviewBinding['initial_observation']=true;$overviewBinding['periods']=[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]];$overviewBinding['requirement_bindings']=[];
$normalizedOverview=AiIntentResultContract::normalize($overviewBinding,['cash_performance','actual_performance','consume_amount'],[],$overviewQuestion,$overviewUnderstanding);
$check($normalizedOverview['initial_observation']===true&&count($normalizedOverview['metric_codes'])===3&&$normalizedOverview['requirement_bindings']===[],'model-marked initial overview carries multiple independent platform observations without inventing a customer metric binding');
$emptyOverviewCandidate=['object_kind'=>'store','operation'=>'summary','metric_codes'=>[],'needs_metric_choice'=>false,'unresolved_fragments'=>[]];
$check(AiIntentResultContract::requiresEmptyRegisteredBindingRecovery($overviewUnderstanding,$emptyOverviewCandidate),
    'an understood store summary with a metric requirement but no candidate receives one model-owned registry rebind');
$emptyOverviewCandidate['object_kind']='person';
$check(!AiIntentResultContract::requiresEmptyRegisteredBindingRecovery($overviewUnderstanding,$emptyOverviewCandidate),
    'the empty-binding repair is a structural store-summary boundary, not a personnel or metric shortcut');
$redundantOverviewLabel=$overviewBinding;$redundantOverviewLabel['recommended_initial_answer']=true;
$normalizedRedundantOverviewLabel=AiIntentResultContract::normalize($redundantOverviewLabel,['cash_performance','actual_performance','consume_amount'],[],$overviewQuestion,$overviewUnderstanding);
$check($normalizedRedundantOverviewLabel['initial_observation']===true&&$normalizedRedundantOverviewLabel['recommended_initial_answer']===false,
    'a redundant first-answer label does not reject an otherwise valid overall operating observation');
$recommendedBinding=$naturalBinding;$recommendedBinding['recommended_initial_answer']=true;
$normalizedRecommendedBinding=AiIntentResultContract::normalize($recommendedBinding,['cash_performance'],[],$naturalQuestion,$naturalUnderstanding);
$check($normalizedRecommendedBinding['recommended_initial_answer']===false&&$normalizedRecommendedBinding['metric_codes']===['cash_performance'],
    'a stale recommended label on a customer-stated measurement is removed without retrying or changing the binding');
$contradictoryRecommendation=$recommendedBinding;$contradictoryRecommendation['needs_metric_choice']=true;unset($contradictoryRecommendation['recommended_initial_answer']);
$normalizedContradiction=AiIntentResultContract::normalize($contradictoryRecommendation,['cash_performance'],[],$naturalQuestion,$naturalUnderstanding);
$check($normalizedContradiction['recommended_initial_answer']===false&&!$normalizedContradiction['needs_metric_choice'],
    'a customer-bound metric clears only its contradictory choice marker, not its ownership');
$broadRankingQuestion=$question;$broadRankingQuestion['question']='谁的业绩最高';$broadRankingQuestion['recent_questions']=[];$broadRankingQuestion['evidence_messages']=[['id'=>'current','text'=>$broadRankingQuestion['question']]];
$broadRankingUnderstanding=AiIntentUnderstandingContract::normalize(['goal'=>'找出业绩最高的人员','status'=>'understood','requirements'=>[
    ['id'=>'r1','meaning'=>'找出业绩最高的人员','fields'=>['object_kind','operation','ranking'],'values'=>['object_kind'=>'person','operation'=>'ranking','ranking'=>['direction'=>'top','limit'=>1]],'evidence'=>[['message_id'=>'current','quote'=>$broadRankingQuestion['question']]]],
]],$broadRankingQuestion);
$broadRankingBinding=$naturalBinding;$broadRankingBinding['object_kind']='person';$broadRankingBinding['operation']='ranking';$broadRankingBinding['ranking']=['direction'=>'top','limit'=>1];$broadRankingBinding['periods']=[];$broadRankingBinding['needs_metric_choice']=true;$broadRankingBinding['requirement_bindings']=[];unset($broadRankingBinding['recommended_initial_answer']);
$normalizedBroadRanking=AiIntentResultContract::normalize($broadRankingBinding,['cash_performance'],[],$broadRankingQuestion,$broadRankingUnderstanding);
$check($normalizedBroadRanking['recommended_initial_answer']===true&&!$normalizedBroadRanking['needs_metric_choice']&&$normalizedBroadRanking['metric_codes']===['cash_performance'],
    'a broad singular ranking can retain the model-selected professional first measure without forcing the customer to name one');
$rankingChoiceCandidate=['operation'=>'ranking','metric_codes'=>['cash_performance'],'needs_metric_choice'=>false];
$check(AiIntentResultContract::canDeferMetricChoice($naturalUnderstanding,$rankingChoiceCandidate,false),
    'a candidate-bound ranking remains eligible for the independent semantic admission pass');
$misbookedBroadRanking=$broadRankingBinding;$misbookedBroadRanking['needs_metric_choice']=false;$misbookedBroadRanking['recommended_initial_answer']=true;
$misbookedBroadRanking['requirement_bindings']=[['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['cash_performance']]];
$normalizedMisbookedBroadRanking=AiIntentResultContract::normalize($misbookedBroadRanking,['cash_performance'],[],$broadRankingQuestion,$broadRankingUnderstanding);
$check($normalizedMisbookedBroadRanking['requirement_bindings']===[]
    && AiIntentResultContract::requiresSemanticBindingReview($broadRankingUnderstanding,$normalizedMisbookedBroadRanking),
    'a model-recommended ranking clears only redundant non-metric audit bookkeeping and still enters semantic review');
$followQuestion=['schema_version'=>'sanitized-question-v2','question'=>'产品呢？','has_unresolved_conditions'=>false,
    'server_resolved_fields'=>[],'reference_date'=>'2026-09-12','recent_questions'=>['哪个项目卖得最好？'],
    'evidence_messages'=>[['id'=>'current','text'=>'产品呢？'],['id'=>'recent_1','text'=>'哪个项目卖得最好？']],
    'prior_query'=>['metric_codes'=>['cash_performance'],'operation'=>'ranking',
        'periods'=>[['kind'=>'date_range','start'=>'2026-09-01','end'=>'2026-09-12']],
        'ranking'=>['direction'=>'top','limit'=>1],'scope'=>'authorized','object_kind'=>'project',
        'has_business_filter'=>true,'has_object_selection'=>false,'has_store_scope_restriction'=>false,
        'presentation_origin'=>'customer_or_verified_context']];
$followUnderstanding=AiIntentUnderstandingContract::normalize(['goal'=>'查看产品表现','status'=>'understood','requirements'=>[
    ['id'=>'r1','meaning'=>'查看产品','fields'=>['object_kind'],
        'values'=>['object_kind'=>'product'],'evidence'=>[['message_id'=>'current','quote'=>'产品']]],
]],$followQuestion);
$followDelta=array_fill_keys(AiIntentResultContract::DELTA_FIELDS,'inherit');$followDelta['object']='replace';
$followBinding=['object_kind'=>'product','object_term'=>'','operation'=>'unknown','metric_codes'=>[],'action_codes'=>[],
    'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified',
    'context_delta'=>$followDelta,'requirement_bindings'=>[['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['cash_performance']]],
    'unresolved_fragments'=>[]];
$normalizedFollow=AiIntentResultContract::normalize($followBinding,['cash_performance','actual_performance'],[],$followQuestion,$followUnderstanding);
$check($normalizedFollow['requirement_bindings']===[]&&$normalizedFollow['metric_codes']===[],
    'an inherited metric attached to a current object-only requirement is removed as structural bookkeeping');
$overviewAfterDimensionQuestion=$overviewQuestion;
$overviewAfterDimensionQuestion['prior_query']=$followQuestion['prior_query'];
$overviewAfterDimensionQuestion['prior_query']['has_business_filter']=true;
$overviewAfterDimensionQuestion['prior_query']['has_object_selection']=false;
$overviewAfterDimensionDelta=array_fill_keys(AiIntentResultContract::DELTA_FIELDS,'inherit');
$overviewAfterDimensionDelta['metric_codes']='replace';$overviewAfterDimensionDelta['operation']='replace';
$overviewAfterDimensionDelta['periods']='replace';$overviewAfterDimensionDelta['business_filters']='clear';
$overviewAfterDimensionBinding=$overviewBinding;
$overviewAfterDimensionBinding['context_delta']=$overviewAfterDimensionDelta;
$overviewAfterDimensionBinding['requirement_bindings']=[['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['cash_performance','actual_performance','consume_amount']]];
$normalizedOverviewAfterDimension=AiIntentResultContract::normalize($overviewAfterDimensionBinding,['cash_performance','actual_performance','consume_amount'],[],$overviewAfterDimensionQuestion,$overviewUnderstanding);
$check($normalizedOverviewAfterDimension['context_delta']['business_filters']==='clear',
    'a new broad topic can clear an old analytical dimension without pretending it selected an object');
$selectedStandaloneQuestion=['schema_version'=>'sanitized-question-v2','question'=>'本月现金业绩是多少？','has_unresolved_conditions'=>false,
    'server_resolved_fields'=>[],'reference_date'=>'2026-09-12','recent_questions'=>['现金业绩最高的人是谁？'],
    'evidence_messages'=>[['id'=>'current','text'=>'本月现金业绩是多少？'],['id'=>'recent_1','text'=>'现金业绩最高的人是谁？']],
    'prior_query'=>['metric_codes'=>['staff_sales_performance'],'operation'=>'ranking',
        'periods'=>[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]],
        'ranking'=>['direction'=>'top','limit'=>1],'scope'=>'authorized','object_kind'=>'person',
        'has_business_filter'=>true,'has_object_selection'=>true,'has_store_scope_restriction'=>false,
        'presentation_origin'=>'customer_or_verified_context']];
$selectedStandaloneUnderstanding=AiIntentUnderstandingContract::normalize(['goal'=>'查看本月现金业绩','status'=>'understood','requirements'=>[
    ['id'=>'r1','meaning'=>'查看现金业绩','fields'=>['metric_codes','operation'],
        'values'=>['metric_terms'=>['现金业绩'],'operation'=>'summary'],'evidence'=>[['message_id'=>'current','quote'=>'现金业绩是多少']]],
    ['id'=>'r2','meaning'=>'本月','fields'=>['periods'],
        'values'=>['periods'=>[['kind'=>'month_offset','offset_months'=>0]]],'evidence'=>[['message_id'=>'current','quote'=>'本月']]],
]],$selectedStandaloneQuestion);
$selectedStandaloneDelta=array_fill_keys(AiIntentResultContract::DELTA_FIELDS,'inherit');
$selectedStandaloneDelta['metric_codes']='replace';$selectedStandaloneDelta['object']='clear';
$selectedStandaloneDelta['business_filters']='clear';$selectedStandaloneDelta['periods']='replace';
$selectedStandaloneDelta['operation']='replace';$selectedStandaloneDelta['ranking_direction']='clear';$selectedStandaloneDelta['ranking_limit']='clear';
$selectedStandaloneBinding=['object_kind'=>'store','object_term'=>'','operation'=>'summary','metric_codes'=>['cash_performance'],'action_codes'=>[],
    'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[['kind'=>'month_offset','offset_months'=>0]],'scope'=>'unspecified',
    'context_delta'=>$selectedStandaloneDelta,'requirement_bindings'=>[['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['cash_performance']]],
    'unresolved_fragments'=>[]];
$normalizedSelectedStandalone=AiIntentResultContract::normalize($selectedStandaloneBinding,['cash_performance'],[],$selectedStandaloneQuestion,$selectedStandaloneUnderstanding);
$check($normalizedSelectedStandalone['context_delta']['business_filters']==='clear'
    && AiIntentResultContract::requiresSemanticBindingReview($selectedStandaloneUnderstanding,$normalizedSelectedStandalone),
    'a complete current aggregate query can explicitly clear a prior selected analytical object only through semantic review');
$periodOnlyAfterSelection=$selectedStandaloneQuestion;$periodOnlyAfterSelection['question']='本月呢？';$periodOnlyAfterSelection['evidence_messages'][0]['text']='本月呢？';
$periodOnlyUnderstanding=AiIntentUnderstandingContract::normalize(['goal'=>'查看本月','status'=>'understood','requirements'=>[
    ['id'=>'r1','meaning'=>'本月','fields'=>['periods'],'values'=>['periods'=>[['kind'=>'month_offset','offset_months'=>0]]],'evidence'=>[['message_id'=>'current','quote'=>'本月呢？']]],
]],$periodOnlyAfterSelection);
$unsafePeriodOnlyUnderstanding=['goal'=>'查看本月','status'=>'understood','requirements'=>[
    ['id'=>'r1','meaning'=>'本月','fields'=>['periods'],'values'=>['periods'=>[['kind'=>'month_offset','offset_months'=>0]]],'evidence'=>[['message_id'=>'current','quote'=>'本月']]],
]];
$reject(static function()use($unsafePeriodOnlyUnderstanding,$selectedStandaloneQuestion){AiIntentUnderstandingContract::normalize($unsafePeriodOnlyUnderstanding,$selectedStandaloneQuestion);},
    'a period-only shortcut cannot omit a different business fact from the same current message');
$check(AiIntentUnderstandingContract::repairable('period_only_coverage'),
    'an incomplete period-only evidence anchor receives one model-owned understanding repair');
$periodOnlyBinding=$selectedStandaloneBinding;$periodOnlyBinding['metric_codes']=[];$periodOnlyBinding['operation']='summary';$periodOnlyBinding['periods']=[['kind'=>'month_offset','offset_months'=>0]];$periodOnlyBinding['requirement_bindings']=[];
$periodOnlyBinding['context_delta']['metric_codes']='inherit';$periodOnlyBinding['context_delta']['operation']='inherit';
$reject(static function()use($periodOnlyBinding,$periodOnlyAfterSelection,$periodOnlyUnderstanding){AiIntentResultContract::normalize($periodOnlyBinding,['cash_performance'],[],$periodOnlyAfterSelection,$periodOnlyUnderstanding);},
    'a period-only continuation cannot clear a prior selected analytical object');
$periodOnlyDrift=$periodOnlyBinding;
$periodOnlyDrift['context_delta']['object']='clear';
$periodOnlyDrift['context_delta']['ranking_direction']='clear';
$periodOnlyDrift['context_delta']['ranking_limit']='clear';
$periodOnlyDrift['object_kind']='store';
$periodOnlyDrift['operation']='summary';
$reject(static function()use($periodOnlyDrift,$periodOnlyAfterSelection,$periodOnlyUnderstanding){AiIntentResultContract::normalize($periodOnlyDrift,['cash_performance'],[],$periodOnlyAfterSelection,$periodOnlyUnderstanding);},
    'a time-only continuation cannot turn a prior ranked analytical object into a store summary');
$check(AiIntentResultContract::repairableFormat('contextual_followup_changed:object'),
    'a time-only continuation drift is repaired by the model once without server-side metric or object selection');
$staleRecommendedCurrentMetric=$selectedStandaloneBinding;
$staleRecommendedCurrentMetric['recommended_initial_answer']=true;
$normalizedStaleRecommendedCurrentMetric=AiIntentResultContract::normalize($staleRecommendedCurrentMetric,['cash_performance'],[],$selectedStandaloneQuestion,$selectedStandaloneUnderstanding);
$check($normalizedStaleRecommendedCurrentMetric['recommended_initial_answer']===false
    && $normalizedStaleRecommendedCurrentMetric['metric_codes']===['cash_performance'],
    'a stale recommended presentation label never consumes a retry or changes a current metric binding');
$badOverviewCarryover=$normalizedOverviewAfterDimension;
$badOverviewCarryover['context_delta']['object']='inherit';
$badOverviewCarryover['context_delta']['business_filters']='inherit';
$badOverviewCarryover['context_delta']['ranking_direction']='inherit';
$badOverviewCarryover['context_delta']['ranking_limit']='inherit';
$badOverviewCarryover['object_kind']='project';
$badOverviewCarryover['ranking']=['direction'=>'top','limit'=>1];
$carryoverSource=['business_filters'=>['object_kind'=>'project'],'query_shape'=>'ranking'];
$check(AiIntentResultContract::requiresContextRebinding($carryoverSource,$overviewUnderstanding,$badOverviewCarryover,$badOverviewCarryover),
    'a non-selected analytical dimension carried into a new answer form is returned to the model for one delta repair');
$selectedCarryoverSource=['business_filters'=>['object_kind'=>'project','selection_ref'=>'private-ref'],'query_shape'=>'ranking'];
$check(!AiIntentResultContract::requiresContextRebinding($selectedCarryoverSource,$overviewUnderstanding,$badOverviewCarryover,$badOverviewCarryover),
    'a verified named selection is never cleared by the structural carryover repair');
$changedSubjectFollow=$followBinding;$changedSubjectFollow['metric_codes']=['actual_performance'];$changedSubjectFollow['context_delta']['metric_codes']='replace';
$changedSubjectFollow['requirement_bindings'][0]['metric_codes']=['actual_performance'];
$normalizedChangedSubjectFollow=AiIntentResultContract::normalize($changedSubjectFollow,['cash_performance','actual_performance'],[],$followQuestion,$followUnderstanding);
$check($normalizedChangedSubjectFollow['requirement_bindings']===[]&&$normalizedChangedSubjectFollow['metric_codes']===['actual_performance'],
    'a non-metric object follow-up clears only redundant bookkeeping while preserving its reviewable metric candidate');
$unknownRecommendedBinding=$misbookedBroadRanking;$unknownRecommendedBinding['requirement_bindings'][0]['requirement_id']='r9';
$reject(static function()use($unknownRecommendedBinding,$broadRankingQuestion,$broadRankingUnderstanding){AiIntentResultContract::normalize($unknownRecommendedBinding,['cash_performance'],[],$broadRankingQuestion,$broadRankingUnderstanding);},
    'a recommendation never clears an unknown requirement binding');
$duplicateRecommendedBinding=$misbookedBroadRanking;$duplicateRecommendedBinding['requirement_bindings'][]=['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['cash_performance']];
$reject(static function()use($duplicateRecommendedBinding,$broadRankingQuestion,$broadRankingUnderstanding){AiIntentResultContract::normalize($duplicateRecommendedBinding,['cash_performance'],[],$broadRankingQuestion,$broadRankingUnderstanding);},
    'a recommendation never clears duplicate requirement bookkeeping');
$pendingRecommendedBinding=$misbookedBroadRanking;$pendingRecommendedBinding['requirement_bindings'][0]['status']='pending';$pendingRecommendedBinding['requirement_bindings'][0]['metric_codes']=[];
$reject(static function()use($pendingRecommendedBinding,$broadRankingQuestion,$broadRankingUnderstanding){AiIntentResultContract::normalize($pendingRecommendedBinding,['cash_performance'],[],$broadRankingQuestion,$broadRankingUnderstanding);},
    'a recommendation never clears a non-satisfied requirement binding');
$differentRecommendedBinding=$misbookedBroadRanking;$differentRecommendedBinding['requirement_bindings'][0]['metric_codes']=['actual_performance'];
$normalizedDifferentRecommendedBinding=AiIntentResultContract::normalize($differentRecommendedBinding,['cash_performance','actual_performance'],[],$broadRankingQuestion,$broadRankingUnderstanding);
$check($normalizedDifferentRecommendedBinding['requirement_bindings']===[]&&$normalizedDifferentRecommendedBinding['metric_codes']===['cash_performance'],
    'a non-metric requirement cannot turn redundant model audit codes into customer-selected meaning');
$multipleRecommendedBinding=$misbookedBroadRanking;$multipleRecommendedBinding['metric_codes']=['cash_performance','actual_performance'];$multipleRecommendedBinding['requirement_bindings'][0]['metric_codes']=['cash_performance','actual_performance'];
$reject(static function()use($multipleRecommendedBinding,$broadRankingQuestion,$broadRankingUnderstanding){AiIntentResultContract::normalize($multipleRecommendedBinding,['cash_performance','actual_performance'],[],$broadRankingQuestion,$broadRankingUnderstanding);},
    'a ranking recommendation with several metric codes remains invalid');
$multipleCandidateChoice=$recommendedBinding;$multipleCandidateChoice['needs_metric_choice']=true;$multipleCandidateChoice['metric_codes']=['cash_performance','refund_performance'];$multipleCandidateChoice['requirement_bindings'][0]['metric_codes']=['cash_performance','refund_performance'];
$reject(static function() use ($multipleCandidateChoice,$naturalQuestion,$naturalUnderstanding): void {
    AiIntentResultContract::normalize($multipleCandidateChoice,['cash_performance','refund_performance'],[],$naturalQuestion,$naturalUnderstanding);
}, 'multiple unresolved candidates cannot be silently combined into a customer measurement');
$singleOverview=$overviewBinding;$singleOverview['metric_codes']=['cash_performance'];$singleOverview['requirement_bindings']=[['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['cash_performance']]];
$normalizedSingleOverview=AiIntentResultContract::normalize($singleOverview,['cash_performance','actual_performance','consume_amount'],[],$overviewQuestion,$overviewUnderstanding);
$check($normalizedSingleOverview['initial_observation']===false&&$normalizedSingleOverview['recommended_initial_answer']===true&&$normalizedSingleOverview['metric_codes']===['cash_performance'],
    'one model-selected broad observation is delivered as a labelled first answer instead of failing or inventing more metrics');
$badOverview=$overviewBinding;$badOverview['operation']='ranking';$badOverview['ranking']=['direction'=>'top','limit'=>5];
$reject(static function()use($badOverview,$overviewQuestion,$overviewUnderstanding){AiIntentResultContract::normalize($badOverview,['cash_performance','actual_performance','consume_amount'],[],$overviewQuestion,$overviewUnderstanding);},'initial overview cannot replace a requested response form');
$check(!AiIntentResultContract::canDeferMetricChoice($overviewUnderstanding,$normalizedOverview,false),'initial overview never falls into the single-metric choice branch');
$singleRowWrongId=$naturalBinding;$singleRowWrongId['requirement_bindings'][0]['requirement_id']='r9';
$check(AiIntentResultContract::normalize($singleRowWrongId,['cash_performance'],[],$naturalQuestion,$naturalUnderstanding)['requirement_bindings'][0]['requirement_id']==='r1','one metric requirement uses its accepted ID while preserving the selected code for semantic review');
$extraNonMetricRow=$base;$extraNonMetricRow['requirement_bindings'][]=['requirement_id'=>'r3','status'=>'satisfied','metric_codes'=>[]];
$check(count(AiIntentResultContract::normalize($extraNonMetricRow,['cash_performance'],[],$question,$understanding)['requirement_bindings'])===2,'empty audit row for a non-metric condition does not block independently checked period or ranking');
$codedNonMetricRow=$extraNonMetricRow;$codedNonMetricRow['requirement_bindings'][2]['metric_codes']=['cash_performance'];
$reject(static function()use($codedNonMetricRow,$question,$understanding){AiIntentResultContract::normalize($codedNonMetricRow,['cash_performance'],[],$question,$understanding);},'a non-metric requirement row carrying a code remains invalid');
$multipleRowsWrongId=$base;$multipleRowsWrongId['requirement_bindings'][0]['requirement_id']='r9';
$reject(static function()use($multipleRowsWrongId,$question,$understanding){AiIntentResultContract::normalize($multipleRowsWrongId,['cash_performance'],[],$question,$understanding);},'multiple metric requirements never guess which requirement a row fulfills');
$followQuestion=$naturalQuestion;$followQuestion['question']='这个月呢？';$followQuestion['evidence_messages'][0]['text']=$followQuestion['question'];$followQuestion['prior_query']=['metric_codes'=>['cash_performance'],'operation'=>'summary','periods'=>[['kind'=>'date_range','start'=>'2026-09-10','end'=>'2026-09-10']],'ranking'=>['direction'=>'unspecified','limit'=>null]];
$followUnderstanding=AiIntentUnderstandingContract::normalize(['goal'=>'查看这个月','status'=>'understood','requirements'=>[
    ['id'=>'r1','meaning'=>'查看这个月','fields'=>['periods'],'values'=>['periods'=>[['kind'=>'month_offset','offset_months'=>0]]],'evidence'=>[['message_id'=>'current','quote'=>'这个月呢？']]],
]],$followQuestion);
$followBinding=$naturalBinding;$followBinding['metric_codes']=[];$followBinding['requirement_bindings']=[];$followBinding['context_delta']=array_fill_keys(AiIntentResultContract::DELTA_FIELDS,'inherit');
$reject(static function()use($followBinding,$followQuestion,$followUnderstanding){AiIntentResultContract::normalize($followBinding,['cash_performance'],[],$followQuestion,$followUnderstanding);},'a current period cannot be falsely marked as inherited from the previous query');
$followBinding['context_delta']['periods']='replace';
$check(AiIntentResultContract::normalize($followBinding,['cash_performance'],[],$followQuestion,$followUnderstanding)['context_delta']['periods']==='replace','a current period is accepted only with an explicit replacement delta');
$wrongPeriod=$base;$wrongPeriod['periods']=[['kind'=>'month_offset','offset_months'=>-1]];
$anchoredPeriod=AiIntentResultContract::normalize($wrongPeriod,['cash_performance'],[],$question,$understanding);
$check($anchoredPeriod['periods']===[['kind'=>'month_offset','offset_months'=>0]],'binding cannot replace a typed natural-language period from accepted understanding');
$comparisonQuestion=$question;$comparisonQuestion['question']='这个月收款和上个月比怎么样？';$comparisonQuestion['evidence_messages'][0]['text']=$comparisonQuestion['question'];
$partialComparisonUnderstanding=AiIntentUnderstandingContract::normalize(['goal'=>'比较这个月和上个月收款','status'=>'understood','requirements'=>[
    ['id'=>'r1','meaning'=>'比较这个月和上个月收款','fields'=>['metric_codes','operation','periods'],'values'=>['metric_terms'=>['收款'],'operation'=>'comparison','periods'=>[['kind'=>'month_offset','offset_months'=>0],['kind'=>'month_offset','offset_months'=>-1]]],'evidence'=>[['message_id'=>'current','quote'=>$comparisonQuestion['question']]]],
]],$comparisonQuestion);
$completedComparison=$base;$completedComparison['operation']='comparison';$completedComparison['periods']=[['kind'=>'month_offset','offset_months'=>0],['kind'=>'month_offset','offset_months'=>-1]];$completedComparison['requirement_bindings']=[['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['cash_performance']]];
$normalizedComparison=AiIntentResultContract::normalize($completedComparison,['cash_performance'],[],$comparisonQuestion,$partialComparisonUnderstanding);
$check(count($normalizedComparison['periods'])===2&&$normalizedComparison['provenance']['periods']['source']==='customer',
    'a comparison preserves both understood time sides before the binding phase');
$notCovered=$base;$notCovered['provenance']['metric_codes']=['source'=>'customer','requirements'=>['r1']];$check(AiIntentResultContract::normalize($notCovered,['cash_performance'],[],$question,$understanding)['provenance']['metric_codes']['requirements']===['r1','r2'],'every understood requirement is covered by server derivation');
$plainQuestion=$question;$plainQuestion['question']='本月收款';$plainQuestion['evidence_messages'][0]['text']='本月收款';
$plainUnderstanding=AiIntentUnderstandingContract::normalize(['goal'=>'查看本月收款','status'=>'understood','requirements'=>[['id'=>'r1','meaning'=>'查看本月收款','fields'=>['metric_codes','periods'],'values'=>['metric_terms'=>['收款'],'periods'=>[['kind'=>'month_offset','offset_months'=>0]]],'evidence'=>[['message_id'=>'current','quote'=>'本月收款']]]]],$plainQuestion);
$inventedRanking=$base;$inventedRanking['ranking']=['direction'=>'top','limit'=>5];$inventedRanking['requirement_bindings']=[['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['cash_performance']]];$inventedRanking['provenance']['object_kind']=['source'=>'system','requirements'=>[]];$inventedRanking['provenance']['metric_codes']=['source'=>'customer','requirements'=>['r1']];$inventedRanking['provenance']['operation']=['source'=>'system','requirements'=>[]];$inventedRanking['provenance']['ranking']=['source'=>'customer','requirements'=>['r1']];$inventedRanking['provenance']['periods']=['source'=>'customer','requirements'=>['r1']];
$candidateRanking=AiIntentResultContract::normalize($inventedRanking,['cash_performance'],[],$plainQuestion,$plainUnderstanding);
$check($candidateRanking['provenance']['operation']['source']==='binding_candidate'
    && $candidateRanking['provenance']['ranking']['source']==='binding_candidate'
    && AiIntentResultContract::requiresSemanticBindingReview($plainUnderstanding,$candidateRanking),
    'a binding-only response form requires independent semantic admission instead of a PHP phrase rule');
$spuriousScope=$inventedRanking;$spuriousScope['scope']='current_store';
$normalizedSpuriousScope=AiIntentResultContract::normalize($spuriousScope,['cash_performance'],[],$plainQuestion,$plainUnderstanding);
$check($normalizedSpuriousScope['scope']==='unspecified'&&!$normalizedSpuriousScope['_scope_supplied'],
    'an analytical store candidate cannot silently narrow an otherwise unscoped authorized range');
$currentScopeQuestion=$plainQuestion;$currentScopeQuestion['question']='本店本月收款';$currentScopeQuestion['evidence_messages'][0]['text']=$currentScopeQuestion['question'];
$currentScopeUnderstanding=AiIntentUnderstandingContract::normalize(['goal'=>'查看本店本月收款','status'=>'understood','requirements'=>[
    ['id'=>'r1','meaning'=>'查看本月收款','fields'=>['metric_codes','periods'],'values'=>['metric_terms'=>['收款'],'periods'=>[['kind'=>'month_offset','offset_months'=>0]]],'evidence'=>[['message_id'=>'current','quote'=>'本店本月收款']]],
    ['id'=>'r2','meaning'=>'限定本店','fields'=>['scope'],'values'=>['scope'=>'current_store'],'evidence'=>[['message_id'=>'current','quote'=>'本店']]],
]],$currentScopeQuestion);
$currentScopeBinding=$naturalBinding;$currentScopeBinding['scope']='current_store';
$normalizedCurrentScope=AiIntentResultContract::normalize($currentScopeBinding,['cash_performance'],[],$currentScopeQuestion,$currentScopeUnderstanding);
$check($normalizedCurrentScope['scope']==='current_store'&&$normalizedCurrentScope['_scope_supplied'],
    'a first-pass understood scope remains an explicit customer restriction');
$analyticalStore=$inventedRanking;$analyticalStore['object_term']='门店';$analyticalStore['object_relation']='analysis';
$normalizedAnalyticalStore=AiIntentResultContract::normalize($analyticalStore,['cash_performance'],[],$plainQuestion,$plainUnderstanding);
$check($normalizedAnalyticalStore['object_relation']==='analysis'&&$normalizedAnalyticalStore['object_term']==='',
    'an analytical object is not transported as a named store selection');
$analyticalRelationQuestion=$plainQuestion;$analyticalRelationQuestion['question']='本月各门店收款排名';$analyticalRelationQuestion['evidence_messages'][0]['text']=$analyticalRelationQuestion['question'];
$analyticalRelationUnderstanding=AiIntentUnderstandingContract::normalize(['goal'=>'查看本月各门店收款排名','status'=>'understood','requirements'=>[
    ['id'=>'r1','meaning'=>'查看本月收款','fields'=>['metric_codes','periods'],'values'=>['metric_terms'=>['收款'],'periods'=>[['kind'=>'month_offset','offset_months'=>0]]],'evidence'=>[['message_id'=>'current','quote'=>'本月各门店收款排名']]],
    ['id'=>'r2','meaning'=>'按门店比较并排名','fields'=>['object_kind','object_relation','operation','ranking'],'values'=>['object_kind'=>'store','object_relation'=>'analysis','operation'=>'ranking','ranking'=>['direction'=>'top','limit'=>null]],'evidence'=>[['message_id'=>'current','quote'=>'各门店收款排名']]],
]],$analyticalRelationQuestion);
$malformedAnalyticalRelation=$analyticalStore;$malformedAnalyticalRelation['object_relation']='grouping';
$normalizedMalformedAnalyticalRelation=AiIntentResultContract::normalize($malformedAnalyticalRelation,['cash_performance'],[],$analyticalRelationQuestion,$analyticalRelationUnderstanding);
$check($normalizedMalformedAnalyticalRelation['object_relation']==='analysis'&&$normalizedMalformedAnalyticalRelation['object_term']==='',
    'an invalid binding relation reuses only the single accepted semantic relation, never a PHP text guess');
$selectedStoreQuestion=$plainQuestion;$selectedStoreQuestion['question']='查看二号门店本月收款';$selectedStoreQuestion['evidence_messages'][0]['text']=$selectedStoreQuestion['question'];
$selectedStoreUnderstanding=AiIntentUnderstandingContract::normalize(['goal'=>'查看二号门店本月收款','status'=>'understood','requirements'=>[
    ['id'=>'r1','meaning'=>'查看本月收款','fields'=>['metric_codes','periods'],'values'=>['metric_terms'=>['收款'],'periods'=>[['kind'=>'month_offset','offset_months'=>0]]],'evidence'=>[['message_id'=>'current','quote'=>'二号门店本月收款']]],
    ['id'=>'r2','meaning'=>'限定二号门店','fields'=>['object_kind','object_relation'],'values'=>['object_kind'=>'store','object_relation'=>'selection'],'evidence'=>[['message_id'=>'current','quote'=>'二号门店']]],
]],$selectedStoreQuestion);
$selectedStoreBinding=$inventedRanking;$selectedStoreBinding['object_term']='二号门店';$selectedStoreBinding['object_relation']='selection';
$normalizedSelectedStore=AiIntentResultContract::normalize($selectedStoreBinding,['cash_performance'],[],$selectedStoreQuestion,$selectedStoreUnderstanding);
$check($normalizedSelectedStore['object_relation']==='selection'&&$normalizedSelectedStore['object_term']==='二号门店',
    'a model-understood particular store remains an authorized catalog selection candidate');
$periodOnlyWithInventedMetric=['goal'=>'查看本月情况','status'=>'understood','requirements'=>[
    ['id'=>'r1','meaning'=>'查看本月情况','fields'=>['metric_codes','periods'],
        'values'=>['periods'=>[['kind'=>'month_offset','offset_months'=>0]]],
        'evidence'=>[['message_id'=>'current','quote'=>'本月呢']]],
]];
$periodOnlyMetricQuestion=$plainQuestion;$periodOnlyMetricQuestion['question']='本月呢';$periodOnlyMetricQuestion['evidence_messages'][0]['text']='本月呢';
$reject(static function()use($periodOnlyWithInventedMetric,$periodOnlyMetricQuestion){AiIntentUnderstandingContract::normalize($periodOnlyWithInventedMetric,$periodOnlyMetricQuestion);},
    'a metric requirement without an exact customer measurement cannot turn a time-only continuation into a new metric choice');
$check(AiIntentUnderstandingContract::repairable('values:metric_terms'),
    'a missing metric evidence carrier receives one model-owned repair');
$wrongHistory=['goal'=>$understanding['goal'],'status'=>$understanding['status'],'requirements'=>[['id'=>'r1','meaning'=>'查看上个月服务情况','fields'=>['metric_codes'],'values'=>['metric_terms'=>['服务']], 'evidence'=>[['message_id'=>'recent_1','quote'=>'上个月服务情况']]],['id'=>'r2','meaning'=>'排除退款','fields'=>['metric_codes'],'values'=>['metric_exclusions'=>['退款']],'evidence'=>[['message_id'=>'current','quote'=>'不要退款']]],['id'=>'r3','meaning'=>'列出前五家门店','fields'=>['object_kind','operation','ranking'],'values'=>['object_kind'=>'store','operation'=>'ranking','ranking'=>['direction'=>'top','limit'=>5]],'evidence'=>[['message_id'=>'current','quote'=>'前五家门店']]]]];
$check(AiIntentUnderstandingContract::normalize($wrongHistory,$question)['requirements'][0]['evidence'][0]['message_id']==='recent_1','specified prior message may be cited explicitly');
$ambiguous=$understanding;$ambiguous['requirements']=[$ambiguous['requirements'][0]];$ambiguous['requirements'][0]['evidence'][0]['quote']='这个月';$questionRepeated=$question;$questionRepeated['question']='这个月收款，这个月退款';$questionRepeated['evidence_messages'][0]['text']=$questionRepeated['question'];
$repeatedEvidence=AiIntentUnderstandingContract::normalize($ambiguous,$questionRepeated);
$check($repeatedEvidence['requirements'][0]['evidence'][0]['quote']===$questionRepeated['question']
    && $repeatedEvidence['requirements'][0]['evidence'][0]['start']===0,
    'repeated excerpt uses the full de-identified message instead of defaulting to its first occurrence');
$formatQuestion=$question;$formatQuestion['question']='这个月收款和上个月比怎么样？';$formatQuestion['evidence_messages'][0]['text']=$formatQuestion['question'];
$formatUnderstanding=AiIntentUnderstandingContract::normalize(['goal'=>'比较这个月和上个月收款','status'=>'understood','requirements'=>[
    ['id'=>'r1','meaning'=>'比较这个月和上个月收款','fields'=>['metric_codes','operation','periods'],'values'=>['metric_terms'=>['收款'],'operation'=>'comparison','periods'=>[['kind'=>'month_offset','offset_months'=>0],['kind'=>'month_offset','offset_months'=>-1]]],'evidence'=>[['message_id'=>'current','quote'=>'这个月 收款和上个月比怎么样']]],
]],$formatQuestion);
$check($formatUnderstanding['requirements'][0]['evidence'][0]['quote']===$formatQuestion['question']
    && $formatUnderstanding['requirements'][0]['evidence'][0]['start']===0,
    'spacing and punctuation variation anchors the actual de-identified customer message without semantic PHP matching');
$wrongFormatUnderstanding=['goal'=>'比较这个月和上个月收款','status'=>'understood','requirements'=>[
    ['id'=>'r1','meaning'=>'比较这个月和上个月收款','fields'=>['metric_codes','operation','periods'],'values'=>['metric_terms'=>['收款'],'operation'=>'comparison','periods'=>[['kind'=>'month_offset','offset_months'=>0],['kind'=>'month_offset','offset_months'=>-1]]],'evidence'=>[['message_id'=>'current','quote'=>'这个月服务和上个月比怎么样']]],
]];
$reject(static function()use($wrongFormatUnderstanding,$formatQuestion){AiIntentUnderstandingContract::normalize($wrongFormatUnderstanding,$formatQuestion);},
    'format tolerance never accepts a changed business word as evidence');
$paraphrasedMetricDetail=$plainUnderstanding;
$paraphrasedMetricDetail['requirements'][0]['values']['metric_terms']=['到账'];
$reject(static function()use($paraphrasedMetricDetail,$plainQuestion){AiIntentUnderstandingContract::normalize($paraphrasedMetricDetail,$plainQuestion);},
    'a non-verbatim metric aid cannot leave a metric requirement without its required customer evidence');
$badDate=$base;$badDate['periods']=[['kind'=>'date_range','start'=>'2026-02-30','end'=>'2026-03-01']];$reject(static function()use($badDate,$question,$understanding){AiIntentResultContract::normalize($badDate,['cash_performance'],[],$question,$understanding);},'invalid calendar date is a model contract error');
$reversed=$base;$reversed['periods']=[['kind'=>'date_range','start'=>'2026-09-12','end'=>'2026-09-01']];$reject(static function()use($reversed,$question,$understanding){AiIntentResultContract::normalize($reversed,['cash_performance'],[],$question,$understanding);},'reversed model date is a model contract error');
$prompt=AiIntentUnderstandingContract::modelInstruction().' '.AiIntentResultContract::modelInstruction(false);
$check(strpos($prompt,'requirements')!==false&&strpos($prompt,'provenance')!==false,'two stage model contracts are published');
$understandingPrompt=AiIntentUnderstandingContract::modelInstruction();
foreach (['metric_terms','metric_exclusions','object_kind','object_relation','operation','ranking','direction','limit','scope','date_range','relative_days','month_offset','end_offset_days','offset_months'] as $requiredShape) {
    $check(strpos($understandingPrompt,$requiredShape)!==false,'first-stage model is told the bounded shape of '.$requiredShape);
}
$missingRankValue=$understanding;
$missingRankValue['requirements'][2]['values']['ranking']=['direction'=>'top'];
$reject(static function()use($missingRankValue,$question){AiIntentUnderstandingContract::normalize($missingRankValue,$question);},
    'a declared ranking must retain its complete typed value for later binding');
$unknownCarrier=$understanding;$unknownCarrier['requirements'][0]['values']['unexpected']='ignored';
$recoveredCarrier=AiIntentUnderstandingContract::normalize($unknownCarrier,$question);
$check(!isset($recoveredCarrier['requirements'][0]['values']['unexpected'])
    && $recoveredCarrier['requirements'][0]['values']['periods']===[['kind'=>'month_offset','offset_months'=>0]],
    'unknown carrier detail is discarded without erasing valid understood time meaning');
$periodRepair=AiIntentUnderstandingContract::repairInstruction('values:missing_typed');
$check(strpos($periodRepair,'complete valid value')!==false&&strpos($periodRepair,'indicator')!==false,
    'missing typed meaning is repaired by the model without server-side indicator selection');
$check(AiIntentResultContract::normalizeSemanticReview(['decision'=>'metric_choice','rejected_requirement_ids'=>[]],$naturalUnderstanding)['decision']==='metric_choice',
    'semantic reviewer can request a registered metric choice without treating a customer requirement as rejected');
$check(AiIntentResultContract::normalizeSemanticReview(['decision'=>'reject','rejected_requirement_ids'=>['r1']],$naturalUnderstanding)['decision']==='reject',
    'semantic reviewer identifies the actual conflicting requirement on rejection');
$reject(static function()use($naturalUnderstanding){AiIntentResultContract::normalizeSemanticReview(['decision'=>'metric_choice','rejected_requirement_ids'=>['r1']],$naturalUnderstanding);},
    'metric choice cannot disguise a requirement rejection');
$reject(static function()use($naturalUnderstanding){AiIntentResultContract::normalizeSemanticReview(['decision'=>'reject','rejected_requirement_ids'=>[]],$naturalUnderstanding);},
    'a semantic rejection must identify a real rejected requirement');
$check(AiIntentResultContract::normalizeSemanticUniqueness(['decision'=>'unique','metric_code'=>'cash_performance'],['cash_performance'])['metric_code']==='cash_performance',
    'candidate-blind uniqueness can name only a registered metric');
$reject(static function(){AiIntentResultContract::normalizeSemanticUniqueness(['decision'=>'unique','metric_code'=>'invented'],['cash_performance']);},
    'candidate-blind uniqueness rejects an unregistered code');
echo 'PASS intent understanding/binding separation: '.$checks." checks\n";
