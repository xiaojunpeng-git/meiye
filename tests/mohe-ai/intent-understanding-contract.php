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
$overviewQuestion=$question;$overviewQuestion['question']='今天经营怎么样？';$overviewQuestion['recent_questions']=[];$overviewQuestion['evidence_messages']=[['id'=>'current','text'=>$overviewQuestion['question']]];
$overviewUnderstanding=AiIntentUnderstandingContract::normalize(['goal'=>'了解今天整体经营情况','status'=>'understood','requirements'=>[
    ['id'=>'r1','meaning'=>'了解整体经营情况','fields'=>['metric_codes'],'values'=>['metric_terms'=>['经营']],'evidence'=>[['message_id'=>'current','quote'=>'经营']]],
    ['id'=>'r2','meaning'=>'今天','fields'=>['periods'],'values'=>['periods'=>[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]]],'evidence'=>[['message_id'=>'current','quote'=>'今天']]],
]],$overviewQuestion);
$overviewBinding=$naturalBinding;$overviewBinding['metric_codes']=['cash_performance','actual_performance','consume_amount'];$overviewBinding['initial_observation']=true;$overviewBinding['periods']=[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]];$overviewBinding['requirement_bindings']=[['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['cash_performance','actual_performance','consume_amount']]];
$normalizedOverview=AiIntentResultContract::normalize($overviewBinding,['cash_performance','actual_performance','consume_amount'],[],$overviewQuestion,$overviewUnderstanding);
$check($normalizedOverview['initial_observation']===true&&count($normalizedOverview['metric_codes'])===3,'model-marked initial overview carries multiple independent observation bindings');
$redundantOverviewLabel=$overviewBinding;$redundantOverviewLabel['recommended_initial_answer']=true;
$normalizedRedundantOverviewLabel=AiIntentResultContract::normalize($redundantOverviewLabel,['cash_performance','actual_performance','consume_amount'],[],$overviewQuestion,$overviewUnderstanding);
$check($normalizedRedundantOverviewLabel['initial_observation']===true&&$normalizedRedundantOverviewLabel['recommended_initial_answer']===false,
    'a redundant first-answer label does not reject an otherwise valid overall operating observation');
$recommendedBinding=$naturalBinding;$recommendedBinding['recommended_initial_answer']=true;
$normalizedRecommended=AiIntentResultContract::normalize($recommendedBinding,['cash_performance'],[],$naturalQuestion,$naturalUnderstanding);
$check($normalizedRecommended['recommended_initial_answer']===true&&!AiIntentResultContract::canDeferMetricChoice($naturalUnderstanding,$normalizedRecommended,false),
    'a model-selected professional first answer is independently reviewed instead of becoming a metric-choice form');
$contradictoryRecommendation=$recommendedBinding;$contradictoryRecommendation['needs_metric_choice']=true;unset($contradictoryRecommendation['recommended_initial_answer']);
$normalizedContradiction=AiIntentResultContract::normalize($contradictoryRecommendation,['cash_performance'],[],$naturalQuestion,$naturalUnderstanding);
$check($normalizedContradiction['recommended_initial_answer']===true&&!$normalizedContradiction['needs_metric_choice'],
    'one model-selected metric overrides only its contradictory choice marker and remains subject to semantic review');
$multipleCandidateChoice=$recommendedBinding;$multipleCandidateChoice['needs_metric_choice']=true;$multipleCandidateChoice['metric_codes']=['cash_performance','refund_performance'];$multipleCandidateChoice['requirement_bindings'][0]['metric_codes']=['cash_performance','refund_performance'];
$normalizedMultipleChoice=AiIntentResultContract::normalize($multipleCandidateChoice,['cash_performance','refund_performance'],[],$naturalQuestion,$naturalUnderstanding);
$check($normalizedMultipleChoice['recommended_initial_answer']===true&&count($normalizedMultipleChoice['metric_codes'])===2,
    'compatible model-selected perspectives become one labelled professional first answer, not a customer metric form');
$singleOverview=$overviewBinding;$singleOverview['metric_codes']=['cash_performance'];$singleOverview['requirement_bindings']=[['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['cash_performance']]];
$reject(static function()use($singleOverview,$overviewQuestion,$overviewUnderstanding){AiIntentResultContract::normalize($singleOverview,['cash_performance','actual_performance','consume_amount'],[],$overviewQuestion,$overviewUnderstanding);},'initial overview cannot silently collapse to one observation');
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
    ['id'=>'r1','meaning'=>'查看这个月','fields'=>['periods'],'values'=>['periods'=>[['kind'=>'month_offset','offset_months'=>0]]],'evidence'=>[['message_id'=>'current','quote'=>'这个月']]],
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
    ['id'=>'r1','meaning'=>'比较这个月和上个月收款','fields'=>['metric_codes','operation','periods'],'values'=>['metric_terms'=>['收款'],'operation'=>'comparison','periods'=>[['kind'=>'month_offset','offset_months'=>0]]],'evidence'=>[['message_id'=>'current','quote'=>$comparisonQuestion['question']]]],
]],$comparisonQuestion);
$completedComparison=$base;$completedComparison['operation']='comparison';$completedComparison['periods']=[['kind'=>'month_offset','offset_months'=>0],['kind'=>'month_offset','offset_months'=>-1]];$completedComparison['requirement_bindings']=[['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['cash_performance']]];
$normalizedComparison=AiIntentResultContract::normalize($completedComparison,['cash_performance'],[],$comparisonQuestion,$partialComparisonUnderstanding);
$check(count($normalizedComparison['periods'])===2&&$normalizedComparison['provenance']['periods']['source']==='binding_candidate'
    && AiIntentResultContract::requiresSemanticBindingReview($partialComparisonUnderstanding,$normalizedComparison),
    'a binding may complete an understood-but-partial comparison pair only through independent semantic review');
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
$selectedStoreQuestion=$plainQuestion;$selectedStoreQuestion['question']='查看二号门店本月收款';$selectedStoreQuestion['evidence_messages'][0]['text']=$selectedStoreQuestion['question'];
$selectedStoreUnderstanding=AiIntentUnderstandingContract::normalize(['goal'=>'查看二号门店本月收款','status'=>'understood','requirements'=>[
    ['id'=>'r1','meaning'=>'查看本月收款','fields'=>['metric_codes','periods'],'values'=>['metric_terms'=>['收款'],'periods'=>[['kind'=>'month_offset','offset_months'=>0]]],'evidence'=>[['message_id'=>'current','quote'=>'二号门店本月收款']]],
    ['id'=>'r2','meaning'=>'限定二号门店','fields'=>['object_kind','object_relation'],'values'=>['object_kind'=>'store','object_relation'=>'selection'],'evidence'=>[['message_id'=>'current','quote'=>'二号门店']]],
]],$selectedStoreQuestion);
$selectedStoreBinding=$inventedRanking;$selectedStoreBinding['object_term']='二号门店';$selectedStoreBinding['object_relation']='selection';
$normalizedSelectedStore=AiIntentResultContract::normalize($selectedStoreBinding,['cash_performance'],[],$selectedStoreQuestion,$selectedStoreUnderstanding);
$check($normalizedSelectedStore['object_relation']==='selection'&&$normalizedSelectedStore['object_term']==='二号门店',
    'a model-understood particular store remains an authorized catalog selection candidate');
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
$recoveredRanking=AiIntentUnderstandingContract::normalize($missingRankValue,$question);
$check(in_array('ranking',$recoveredRanking['requirements'][2]['fields'],true)
    && $recoveredRanking['requirements'][2]['values']===[],
    'malformed optional ranking detail cannot erase a grounded customer requirement');
$unknownCarrier=$understanding;$unknownCarrier['requirements'][0]['values']['unexpected']='ignored';
$recoveredCarrier=AiIntentUnderstandingContract::normalize($unknownCarrier,$question);
$check($recoveredCarrier['requirements'][0]['values']===[]&&in_array('metric_codes',$recoveredCarrier['requirements'][0]['fields'],true),
    'unknown optional carrier detail is discarded before binding rather than failing the customer request');
$periodRepair=AiIntentUnderstandingContract::repairInstruction('values:periods');
$check(strpos($periodRepair,'values.periods')!==false&&strpos($periodRepair,'optional detail')!==false,
    'time-carrier repair preserves model-owned time meaning without making a carrier value mandatory');
$check(AiIntentResultContract::normalizeSemanticReview(['decision'=>'metric_choice','rejected_requirement_ids'=>['r1']],$naturalUnderstanding)['decision']==='metric_choice',
    'semantic reviewer can request a registered metric choice without authorizing a candidate');
$check(AiIntentResultContract::normalizeSemanticUniqueness(['decision'=>'unique','metric_code'=>'cash_performance'],['cash_performance'])['metric_code']==='cash_performance',
    'candidate-blind uniqueness can name only a registered metric');
$reject(static function(){AiIntentResultContract::normalizeSemanticUniqueness(['decision'=>'unique','metric_code'=>'invented'],['cash_performance']);},
    'candidate-blind uniqueness rejects an unregistered code');
echo 'PASS intent understanding/binding separation: '.$checks." checks\n";
