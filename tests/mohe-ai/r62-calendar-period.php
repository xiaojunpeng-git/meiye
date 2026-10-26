<?php
/** R62: model-carried calendar meaning resolves without customer-word keyword matching. */
require_once __DIR__.'/fixture-autoload.php';

use app\services\ai\contract\AiIntentResultContract;
use app\services\ai\contract\AiIntentUnderstandingContract;
use app\services\ai\execution\AiTemporalPeriodResolver;
use app\services\ai\execution\AiWorkflowPlanner;

$reference='2026-10-07';
$cases=[
    'previous quarter'=>[['kind'=>'calendar_period','unit'=>'quarter','offset'=>-1,'count'=>1,'end_mode'=>'full'],'2026-07-01','2026-09-30'],
    'previous week'=>[['kind'=>'calendar_period','unit'=>'week','offset'=>-1,'count'=>1,'end_mode'=>'full'],'2026-09-28','2026-10-04'],
    'current quarter'=>[['kind'=>'calendar_period','unit'=>'quarter','offset'=>0,'count'=>1,'end_mode'=>'to_reference'],'2026-10-01','2026-10-07'],
    'two prior weeks'=>[['kind'=>'calendar_period','unit'=>'week','offset'=>-1,'count'=>2,'end_mode'=>'full'],'2026-09-21','2026-10-04'],
    'two prior years'=>[['kind'=>'calendar_period','unit'=>'year','offset'=>-1,'count'=>2,'end_mode'=>'full'],'2024-01-01','2025-12-31'],
    'legacy month'=>[['kind'=>'month_offset','offset_months'=>-1],'2026-09-01','2026-09-30'],
    'rolling week'=>[['kind'=>'relative_days','days'=>7,'end_offset_days'=>-1],'2026-09-30','2026-10-06'],
];
$checks=0;$planner=new AiWorkflowPlanner();
foreach ($cases as $label=>[$period,$start,$end]) {
    if (!AiIntentResultContract::periods([$period])) throw new RuntimeException($label.' contract rejected');
    $expected=['start'=>$start,'end'=>$end];
    // The converter preserves long historical meaning. Any Reader capacity
    // decision is separate and must never shorten the period silently.
    if (AiTemporalPeriodResolver::resolve($period,$reference)!==$expected
        || $planner->normalizeNaturalPeriod($period,$reference)!==$expected) throw new RuntimeException($label.' range mismatch');
    ++$checks;
}
foreach ([
    [['kind'=>'calendar_period','unit'=>'week','offset'=>0,'count'=>1,'end_mode'=>'to_reference'],'2027-01-01','2026-12-28','2027-01-01'],
    [['kind'=>'calendar_period','unit'=>'quarter','offset'=>-1,'count'=>1,'end_mode'=>'full'],'2027-01-02','2026-10-01','2026-12-31'],
    [['kind'=>'calendar_period','unit'=>'week','offset'=>-1,'count'=>1,'end_mode'=>'full'],'2026-10-05','2026-09-28','2026-10-04'],
] as [$period,$day,$start,$end]) {
    if (AiTemporalPeriodResolver::resolve($period,$day)!==['start'=>$start,'end'=>$end]) {
        throw new RuntimeException('calendar boundary mismatch');
    }
    ++$checks;
}
if (AiIntentResultContract::periods([['kind'=>'calendar_period','unit'=>'quarter','offset'=>-1,'count'=>1,'end_mode'=>'to_reference']])) {
    throw new RuntimeException('historical to-reference period accepted');
}
++ $checks;
foreach (['last_quarter'=>'上个季度营业额总的是多少','last_week'=>'上周营业额是多少',
    'weekday_extrema'=>'上周营业额最高是周几，最低是周几'] as $name=>$questionText) {
    $period=$cases[$name==='last_quarter'?'previous quarter':'previous week'][0];
    $ranking=$name==='weekday_extrema';
    $question=['schema_version'=>'sanitized-question-v2','question'=>$questionText,
        'reference_date'=>$reference,'recent_questions'=>[],'evidence_messages'=>[['id'=>'current','text'=>$questionText]],
        'prior_query'=>null,'has_unresolved_conditions'=>false,'server_resolved_fields'=>[]];
    $values=['metric_terms'=>['营业额'],'periods'=>[$period]];
    $fields=['metric_codes','periods'];
    if ($ranking) {
        $fields=array_merge($fields,['object_kind','object_relation','operation','ranking']);
        $values+=['object_kind'=>'business_date','object_relation'=>'analysis','operation'=>'ranking',
            'ranking'=>['direction'=>'top_and_bottom','limit'=>1]];
    }
    $understanding=AiIntentUnderstandingContract::normalize(['goal'=>'查看营业额','status'=>'understood',
        'requirements'=>[['id'=>'r1','meaning'=>'查看营业额','fields'=>$fields,'values'=>$values,
            'evidence'=>[['message_id'=>'current','quote'=>$questionText]]]]],$question);
    $candidate=['object_kind'=>$ranking?'business_date':'store','object_term'=>'','object_relation'=>'analysis',
        'operation'=>$ranking?'ranking':'summary','metric_codes'=>['cash_performance'],'action_codes'=>[],
        'needs_metric_choice'=>false,'periods'=>[$period],'scope'=>'authorized',
        'requirement_bindings'=>[['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['cash_performance']]],
        'unresolved_fragments'=>[]];
    if ($ranking) $candidate['ranking']=['direction'=>'top_and_bottom','limit'=>1];
    $bound=AiIntentResultContract::normalize($candidate,['cash_performance'],[],$question,$understanding);
    if ($bound['periods']!==[$period] || ($ranking && $bound['object_kind']!=='business_date')) {
        throw new RuntimeException($name.' lost typed customer meaning');
    }
    ++$checks;
    if ($name==='last_quarter') {
        $wrong=$candidate;
        $wrong['periods']=[['kind'=>'calendar_period','unit'=>'month','offset'=>-3,'count'=>1,'end_mode'=>'full']];
        // The accepted first-pass period is authoritative: correction or a
        // hard contract rejection is safe; executing the narrowed month is not.
        try {
            $reviewed=AiIntentResultContract::normalize($wrong,['cash_performance'],[],$question,$understanding);
            if ($reviewed['periods']!==[$period]) throw new RuntimeException('quarter silently narrowed to one month');
        } catch (\app\services\ai\contract\AiContractException $expectedFailure) {}
        ++$checks;
    }
}
$week=$cases['previous week'][0];
$packagingQuestion=['schema_version'=>'sanitized-question-v2','question'=>'上周营业额是多少',
    'reference_date'=>$reference,'recent_questions'=>[],'evidence_messages'=>[['id'=>'current','text'=>'上周营业额是多少']],
    'prior_query'=>null,'has_unresolved_conditions'=>false,'server_resolved_fields'=>[]];
$packaged=AiIntentUnderstandingContract::normalize(['goal'=>'查询营业额','status'=>'understood','periods'=>[$week],
    'requirements'=>[['id'=>'r1','meaning'=>'上周营业额','fields'=>['metric_codes','periods'],
        'values'=>['metric_terms'=>['营业额']],
        'evidence'=>[['message_id'=>'current','quote'=>'上周营业额是多少']]]]],$packagingQuestion);
if (($packaged['requirements'][0]['values']['periods']??null)!==[$week]) throw new RuntimeException('unique typed period packaging not repaired');
++ $checks;
$extremeText='上周营业额最高是周几，最低是周几';
$extremeQuestion=['schema_version'=>'sanitized-question-v2','question'=>$extremeText,
    'reference_date'=>$reference,'recent_questions'=>[],'evidence_messages'=>[['id'=>'current','text'=>$extremeText]],
    'prior_query'=>null,'has_unresolved_conditions'=>false,'server_resolved_fields'=>[]];
$baseValues=['metric_terms'=>['营业额'],'object_kind'=>'business_date','object_relation'=>'analysis',
    'operation'=>'ranking','periods'=>[$week]];
$baseFields=['metric_codes','object_kind','object_relation','operation','periods','ranking'];
$splitRequirements=[];
foreach (['top','bottom'] as $index=>$direction) {
    $splitRequirements[]=['id'=>'r'.($index+1),'meaning'=>'比较上周营业额','fields'=>$baseFields,
        'values'=>$baseValues+['ranking'=>['direction'=>$direction,'limit'=>1]],
        'evidence'=>[['message_id'=>'current','quote'=>$extremeText]]];
}
$joined=AiIntentUnderstandingContract::normalize(['goal'=>'比较上周营业额最高和最低日',
    'status'=>'understood','requirements'=>$splitRequirements],$extremeQuestion);
if (count($joined['requirements'])!==1
    || ($joined['requirements'][0]['values']['ranking']['direction']??null)!=='top_and_bottom') {
    throw new RuntimeException('identical opposite ranking requirements not joined');
}
++ $checks;
$oneMissing=$splitRequirements;
unset($oneMissing[1]['values']['metric_terms']);
$oneMissing[1]['meaning']='上周营业额最低的那一天';
$joined=AiIntentUnderstandingContract::normalize(['goal'=>'比较上周营业额最高和最低日',
    'status'=>'understood','requirements'=>$oneMissing],$extremeQuestion);
if (count($joined['requirements'])!==1
    || ($joined['requirements'][0]['values']['metric_terms']??null)!==['营业额']) {
    throw new RuntimeException('evidence-backed repeated metric not joined');
}
++ $checks;
$oneMissing[1]['meaning']='另一项消耗业绩最低的那一天';
$notJoined=AiIntentUnderstandingContract::normalize(['goal'=>'比较不同指标最高最低日',
    'status'=>'understood','requirements'=>$oneMissing],$extremeQuestion);
if (count($notJoined['requirements'])!==2) throw new RuntimeException('different meaning inherited a metric');
++ $checks;
$distinct=$splitRequirements;
$distinct[1]['values']['periods']=[['kind'=>'calendar_period','unit'=>'week','offset'=>-2,'count'=>1,'end_mode'=>'full']];
$separate=AiIntentUnderstandingContract::normalize(['goal'=>'比较不同日期营业额',
    'status'=>'understood','requirements'=>$distinct],$extremeQuestion);
if (count($separate['requirements'])!==2) throw new RuntimeException('different periods merged');
++ $checks;
// A follow-up model may cite one shared week separately from its two
// ranking ends. This is one current-period meaning, not an invitation to
// inherit the preceding quarter or omit the typed date at execution.
$shared=$splitRequirements;
unset($shared[0]['values']['periods'],$shared[1]['values']['periods']);
$shared[]=['id'=>'r3','meaning'=>'上周','fields'=>['periods'],'values'=>['periods'=>[$week]],
    'evidence'=>[['message_id'=>'current','quote'=>'上周']]];
$followupQuestion=$extremeQuestion;
$followupQuestion['prior_query']=['metric_codes'=>['cash_performance'],'operation'=>'summary',
    'periods'=>[['kind'=>'calendar_period','unit'=>'quarter','offset'=>-1,'count'=>1,'end_mode'=>'full']]];
$followupQuestion['recent_questions']=['上个季度营业额总的是多少'];
$followupQuestion['evidence_messages'][]=['id'=>'recent_1','text'=>'上个季度营业额总的是多少'];
$joined=AiIntentUnderstandingContract::normalize(['goal'=>'比较上周营业额最高和最低日',
    'status'=>'understood','topic_relation'=>'new_topic','requirements'=>$shared],$followupQuestion);
if (count($joined['requirements'])!==1
    || ($joined['requirements'][0]['values']['periods']??null)!==[$week]
    || ($joined['requirements'][0]['values']['ranking']['direction']??null)!=='top_and_bottom') {
    throw new RuntimeException('shared ranking period not attached');
}
++ $checks;
$topLevelShared=$shared;
unset($topLevelShared[0]['values']['periods'],$topLevelShared[1]['values']['periods']);
unset($topLevelShared[0]['values']['operation'],$topLevelShared[1]['values']['operation']);
array_pop($topLevelShared);
$joined=AiIntentUnderstandingContract::normalize(['goal'=>'比较上周营业额最高和最低日',
    'status'=>'understood','topic_relation'=>'new_topic','periods'=>[$week],
    'requirements'=>$topLevelShared],$followupQuestion);
if (count($joined['requirements'])!==1
    || ($joined['requirements'][0]['values']['periods']??null)!==[$week]
    || ($joined['requirements'][0]['values']['ranking']['direction']??null)!=='top_and_bottom') {
    throw new RuntimeException('top-level shared ranking period not attached');
}
++ $checks;
$conflict=$shared;
$conflict[0]['values']['periods']=[['kind'=>'calendar_period','unit'=>'week','offset'=>-2,'count'=>1,'end_mode'=>'full']];
try {
    AiIntentUnderstandingContract::normalize(['goal'=>'比较上周营业额最高和最低日',
        'status'=>'understood','topic_relation'=>'new_topic','requirements'=>$conflict],$followupQuestion);
    throw new RuntimeException('conflicting ranking period accepted');
} catch (\app\services\ai\contract\AiContractException $expectedFailure) {}
++ $checks;
echo 'r62-calendar-period: PASS ('.$checks." checks)\n";
