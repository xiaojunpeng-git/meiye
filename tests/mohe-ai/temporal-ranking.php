<?php
/** Offline business-date ranking regression. No model, account, database or network. */
require_once __DIR__.'/fixture-autoload.php';

use app\services\ai\contract\AiIntentResultContract;
use app\services\ai\contract\AiIntentUnderstandingContract;
use app\services\ai\presentation\AiAnswerRenderer;
use app\services\ai\semantic\AiSemanticIntentParser;
use app\services\query\metric\MetricGroupedProjection;

$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void {
    if (!$ok) throw new RuntimeException('FAIL '.$label);
    ++$checks;
};

// The auxiliary parser may recognize the extremum word, but it must not
// convert that one word into a store ranking or store-comparison goal.
$projection=(new AiSemanticIntentParser())->parse('这个月的销售额最高是哪天');
$check(in_array('rank_top',$projection['signals'],true)
    && !in_array('ranking',$projection['signals'],true)
    && ($projection['semantic_intent']['goal']??null)==='business_results',
    'highest alone does not choose a store ranking');

$question=[
    'schema_version'=>'sanitized-question-v2','question'=>'这个月的销售额最高是哪天','has_unresolved_conditions'=>false,
    'server_resolved_fields'=>[],'reference_date'=>'2026-09-23','recent_questions'=>[],
    'evidence_messages'=>[['id'=>'current','text'=>'这个月的销售额最高是哪天']],'prior_query'=>null,
];
$understanding=AiIntentUnderstandingContract::normalize([
    'goal'=>'查看本月销售额最高的业务日期','status'=>'understood','requirements'=>[
        ['id'=>'r1','meaning'=>'本月销售额','fields'=>['metric_codes','periods'],
            'values'=>['metric_terms'=>['销售额'],'periods'=>[['kind'=>'month_offset','offset_months'=>0]]],
            'evidence'=>[['message_id'=>'current','quote'=>'这个月的销售额']]],
        ['id'=>'r2','meaning'=>'最高的业务日期','fields'=>['object_kind','object_relation','operation','ranking'],
            'values'=>['object_kind'=>'business_date','object_relation'=>'analysis','operation'=>'ranking','ranking'=>['direction'=>'top','limit'=>1]],
            'evidence'=>[['message_id'=>'current','quote'=>'最高是哪天']]],
    ],
],$question);
$binding=AiIntentResultContract::normalize([
    'object_kind'=>'business_date','object_term'=>'','object_relation'=>'analysis','operation'=>'ranking',
    'metric_codes'=>['sales_amount'],'action_codes'=>[],'needs_metric_choice'=>false,
    'ranking'=>['direction'=>'top','limit'=>1],'periods'=>[['kind'=>'month_offset','offset_months'=>0]],
    'scope'=>'authorized','requirement_bindings'=>[['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['sales_amount']]],
    'unresolved_fragments'=>[],
],['sales_amount'],[],$question,$understanding);
$check($binding['object_kind']==='business_date' && $binding['operation']==='ranking'
    && $binding['ranking']===['direction'=>'top','limit'=>1],
    'typed binding preserves the requested daily extremum');

$projector=new MetricGroupedProjection();
$rows=$projector->temporalRanking([
    ['business_date'=>'2026-09-01','store_id'=>1,'amount_cents'=>10000],
    ['business_date'=>'2026-09-01','store_id'=>2,'amount_cents'=>5000],
    ['business_date'=>'2026-09-02','store_id'=>1,'amount_cents'=>28000],
    ['business_date'=>'2026-09-03','store_id'=>2,'amount_cents'=>15000],
],['start'=>'2026-09-01','end'=>'2026-09-03'],['direction'=>'top_and_bottom','limit'=>1],'2026-09-23');
$check($rows['top'][0]===['business_date'=>'2026-09-02','amount_cents'=>28000]
    && $rows['bottom'][0]===['business_date'=>'2026-09-01','amount_cents'=>15000],
    'daily extrema aggregate all authorized stores before ordering');
$ties=$projector->temporalRanking([
    ['business_date'=>'2026-09-01','store_id'=>1,'amount_cents'=>28000],
    ['business_date'=>'2026-09-02','store_id'=>1,'amount_cents'=>28000],
    ['business_date'=>'2026-09-03','store_id'=>1,'amount_cents'=>10000],
],['start'=>'2026-09-01','end'=>'2026-09-03'],['direction'=>'top','limit'=>1],'2026-09-23');
$check(array_column($ties['top'],'business_date')===['2026-09-01','2026-09-02'],
    'all dates tied at the requested cutoff remain visible');

$view=[
    'query'=>['query_shape'=>'ranking','metric_codes'=>['sales_amount'],'start_date'=>'2026-09-01','end_date'=>'2026-09-03',
        'compare_range'=>null,'store_ids'=>[],'business_filters'=>['object_kind'=>'business_date'],
        'ranking'=>['direction'=>'top','limit'=>1],'aggregate_condition'=>null],
    'results'=>[['period'=>'current','metric_code'=>'sales_amount','storage_unit'=>'fen','object_kind'=>'business_date',
        'object_label'=>'日期','rows'=>['top'=>[['business_date'=>'2026-09-02','amount_cents'=>28000]]]]],
];
$answer=(new AiAnswerRenderer())->render($view);
$check(str_contains($answer['summary'],'2026-09-02')
    && ($answer['table']['columns'][0]['label']??null)==='日期'
    && ($answer['table']['rows'][0]['label']??null)==='2026-09-02',
    'answer presents the winning date instead of a store identity');

echo 'temporal-ranking: PASS ('.$checks." checks; offline fixtures)\n";
