<?php
/** Synthetic contract regression only: preserve the accepted turn relation
 * through local carrier corrections and never infer a new business query
 * from an unanchored follow-up. No provider, application boot or customer DB. */
require_once __DIR__.'/fixture-autoload.php';

use app\services\ai\contract\AiIntentResultContract as Binding;
use app\services\ai\contract\AiIntentUnderstandingContract as Meaning;
use app\services\ai\context\IntentContextMerger;
use app\services\ai\context\ResultReferenceResolver;

$checks=0;
$check=static function(bool $condition,string $label) use (&$checks):void {
    if (!$condition) throw new RuntimeException('FAIL '.$label);
    $checks++;
};
$question=static function(string $text,array $prior):array {
    return ['question'=>$text,'reference_date'=>'2026-10-11','recent_questions'=>[],
        'evidence_messages'=>[['id'=>'current','text'=>$text]],'prior_query'=>$prior];
};
$requirement=static function(string $id,string $text,array $fields,array $values):array {
    return ['id'=>$id,'meaning'=>$text,'fields'=>$fields,'values'=>$values,
        'evidence'=>[['message_id'=>'current','quote'=>$text]]];
};
$source=['metric_codes'=>['cash_performance'],'query_shape'=>'ranking',
    'start_date'=>'2026-10-01','end_date'=>'2026-10-11','compare_range'=>null,
    'ranking'=>['direction'=>'top','limit'=>1],'store_ids'=>[1],
    'business_filters'=>['object_kind'=>'store'],'aggregate_condition'=>null];
$prior=IntentContextMerger::modelView($source);
$masked='[local_condition_1]这个门店[local_condition_2]项目数最多的是哪个';
$safe=$question($masked,$prior);
$requirements=[
    $requirement('r1','[local_condition_1]',['store_term'],['store_term'=>'[local_condition_1]']),
    $requirement('r2','[local_condition_2]',['object_kind','object_relation'],
        ['object_kind'=>'position','object_relation'=>'selection']),
    $requirement('r3','项目数最多的是哪个',['metric_codes','operation','ranking'],
        ['metric_terms'=>['项目数'],'operation'=>'ranking','ranking'=>['direction'=>'top','limit'=>1]]),
];
$privateKinds=['local_condition_1'=>'store','local_condition_2'=>'position'];
foreach (['new_topic','continuation'] as $relation) {
    $base=Meaning::normalize(['goal'=>'查询门店岗位人员排行','status'=>'understood',
        'topic_relation'=>$relation,'requirements'=>$requirements],$safe);
    $duplicate=$base;
    $duplicate['requirements'][]=$requirement('r4','这个门店',['store_term'],['store_term'=>'这个门店']);
    $corrected=Meaning::reconcileCurrentStoreRequirements($duplicate,$safe,$privateKinds);
    $check(($corrected['topic_relation']??null)===$relation
        && count($corrected['requirements'])===3,'store correction preserves '.$relation);
    $corrected=Meaning::reconcileSelectedPositionAsPersonnel($base,$safe,$privateKinds);
    $check(($corrected['topic_relation']??null)===$relation
        && $corrected['requirements'][1]['values']['object_kind']==='person',
        'position correction preserves '.$relation);
    $misread=$base;
    $misread['requirements'][1]['values']=['object_kind'=>'project','object_relation'=>'analysis'];
    $corrected=Meaning::reconcileRankedRoleMetricObject($misread,$safe,$privateKinds,
        ['staff_project_num'],['staff_project_num'],[['object_kind'=>'project','object_label'=>'项目']]);
    $check(($corrected['topic_relation']??null)===$relation
        && $corrected['requirements'][1]['values']['object_kind']==='person',
        'registered metric correction preserves '.$relation);
}

$deictic='这个门店技师做的单数最多的是哪个';
$safe=$question($deictic,$prior+['sole_result_reference'=>['group'=>'top','ordinal'=>1]]);
$meaning=Meaning::normalize(['goal'=>'查询前述门店人员','status'=>'understood','topic_relation'=>'continuation',
    'requirements'=>[$requirement('r1','这个门店',['store_term'],['store_term'=>'这个门店'])]],$safe);
$reference=Meaning::reconcileSoleStoreReference($meaning,$safe);
$check(($reference['topic_relation']??null)==='continuation'
    && ($reference['requirements'][0]['values']['result_reference']??null)===['group'=>'top','ordinal'=>1],
    'a displayed store reference keeps its accepted continuation');
$newTopic=$meaning;$newTopic['topic_relation']='new_topic';
$check(Meaning::reconcileSoleStoreReference($newTopic,$safe)===$newTopic,
    'an explicit new topic never acquires an old store identity from a local repair');

// Typed partial response changes are meaningful only with a verified
// predecessor; they cannot manufacture a metric, population or authority.
foreach ([
    ['operation'=>'ranking'],
    ['ranking'=>['direction'=>'top','limit'=>3]],
    ['metric_exclusions'=>['退款']],
] as $values) {
    $partial=['status'=>'needs_clarification','requirements'=>[['values'=>$values]]];
    $check(Binding::hasConcreteQueryMeaning($partial,[],true),
        'a verified response/measurement change remains business guidance');
    $check(!Binding::hasConcreteQueryMeaning($partial,[],false),
        'the same unanchored fragment cannot become a business query');
}
$check(!Binding::hasConcreteQueryMeaning(['requirements'=>[['values'=>['operation'=>'unknown']]]],[],true),
    'an unknown operation is not proof of a business follow-up');

$periodText='上个月呢';
$periodMeaning=Meaning::normalize(['goal'=>'更改统计时间','status'=>'understood','topic_relation'=>'continuation',
    'requirements'=>[$requirement('r1',$periodText,['periods'],
        ['periods'=>[['kind'=>'calendar_period','unit'=>'month','offset'=>-1,'count'=>1,'end_mode'=>'full']]])]],
    $question($periodText,$prior));
$check(Binding::inheritedPeriodOnlyContextIntent($periodMeaning,$source)!==null,
    'verified date continuation keeps its existing structured fast path');
$periodMeaning['topic_relation']='new_topic';
$check(Binding::inheritedPeriodOnlyContextIntent($periodMeaning,$source)===null,
    'a new-topic marker fences date inheritance even if a caller retains a source');
$rankingMeaning=['status'=>'understood','topic_relation'=>'continuation',
    'requirements'=>[$requirement('r1','前三名',['ranking'],['ranking'=>['direction'=>'top','limit'=>3]])]];
$check(Binding::inheritedRankingOnlyContextIntent($rankingMeaning,$source)!==null,
    'verified ranking presentation remains an executable delta');
$rankingMeaning['topic_relation']='new_topic';
$check(Binding::inheritedRankingOnlyContextIntent($rankingMeaning,$source)===null,
    'a new-topic marker fences ranking inheritance');
$conditionSource=$source;
$conditionSource['query_shape']='condition_count';$conditionSource['ranking']=null;
$conditionSource['business_filters']=['object_kind'=>'member'];
$conditionSource['metric_codes']=['sales_collected_amount'];
$conditionSource['aggregate_condition']=['subject'=>'member','relation'=>'all','result_form'=>'count',
    'conditions'=>[['metric_code'=>'sales_collected_amount','operator'=>'eq','quantity'=>'4980','unit'=>'yuan']]];
$periodMeaning['topic_relation']='continuation';
$condition=Binding::inheritedConditionContinuationContextIntent($periodMeaning,$conditionSource);
$check($condition!==null && $condition['aggregate_condition']===$conditionSource['aggregate_condition'],
    'a date-only continuation preserves the exact verified monetary predicate');
$periodMeaning['topic_relation']='new_topic';
$check(Binding::inheritedConditionContinuationContextIntent($periodMeaning,$conditionSource)===null,
    'a new-topic marker fences prior member-condition inheritance');

// Resolving a displayed position and requesting a fresh rank are different
// operations. A missing displayed second row must not be invented or fetched
// by this immutable-reference resolver.
$snapshot=['results'=>[['rows'=>['top'=>[['store_id'=>17]],'bottom'=>[]]]]];
$resolved=ResultReferenceResolver::resolve($source,$snapshot,['group'=>'top','ordinal'=>1]);
$check($resolved['store_ids']===[17],'displayed identity comes only from the original snapshot');
try {
    ResultReferenceResolver::resolve($source,$snapshot,['group'=>'top','ordinal'=>2]);
    throw new RuntimeException('FAIL an unseen rank became a displayed identity');
} catch (RuntimeException $error) {
    $check($error->getMessage()==='AI_RESULT_REFERENCE_UNAVAILABLE',
        'a new ranking request cannot be disguised as an old displayed position');
}
echo 'PASS OPT3 context relation contract checks='.$checks."\n";
