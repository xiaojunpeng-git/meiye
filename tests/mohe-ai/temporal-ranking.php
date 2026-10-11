<?php
/** Offline business-date ranking regression. No model, account, database or network. */
require_once __DIR__.'/fixture-autoload.php';

use app\services\ai\contract\AiIntentResultContract;
use app\services\ai\contract\AiIntentUnderstandingContract;
use app\services\ai\execution\AiDimensionGuidancePlanner;
use app\services\ai\presentation\AiAnswerRenderer;
use app\services\ai\semantic\AiSemanticIntentParser;
use app\services\ai\semantic\AiExactRankingCollectionAdmission;
use app\services\query\metric\MetricDefinitionRegistry;
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

// A broad date ranking is a first-answer request, not a forced metric form.
// The sole registry declaration chooses a labelled perspective only after
// the model has accepted the date and ranking; an explicit metric still wins.
$capabilities=MetricDefinitionRegistry::capabilities();
$summaries=[];
foreach ($capabilities as $code=>$capability) $summaries[]=[
    'metric_code'=>$code,
    'default_rank_object_kinds'=>$capability['analysis_default_rank_object_kinds']??[],
];
$broadUnderstanding=['status'=>'understood','requirements'=>[
    ['id'=>'r1','fields'=>['metric_codes','object_kind','operation','periods','ranking'],
        'values'=>['metric_terms'=>['业绩'],'object_kind'=>'business_date','operation'=>'ranking',
            'periods'=>[['kind'=>'month_offset','offset_months'=>0]],'ranking'=>['direction'=>'top','limit'=>1]]],
]];
$broadIntent=['object_kind'=>'business_date','object_relation'=>'analysis','object_term'=>'',
    'operation'=>'ranking','metric_codes'=>[],'needs_metric_choice'=>true,
    'recommended_initial_answer'=>false,'initial_observation'=>false,'aggregate_condition'=>null,
    'result_reference'=>null];
$gateway=(new ReflectionClass(\app\services\ai\AiGatewayServices::class))->newInstanceWithoutConstructor();
$rankDefault=new ReflectionMethod($gateway,'applyRegisteredRankDefaultPolicy');
$firstAnswer=$rankDefault->invoke($gateway,$broadIntent,$broadUnderstanding,$summaries);
$check(($firstAnswer['metric_codes']??null)===['actual_performance']
    && ($firstAnswer['needs_metric_choice']??true)===false
    && ($firstAnswer['recommended_initial_answer']??false)===true,
    'broad date ranking receives one registered actual-performance first answer');
// A short ranked-date continuation may carry a discourse particle, while
// the signed previous metric remains the first matching measurement.
$rankingAdmission=new AiExactRankingCollectionAdmission();
$objectVocabulary=[['object_kind'=>'business_date','object_label'=>'哪天'],
    ['object_kind'=>'store','object_label'=>'门店']];
$defaults=[];
foreach ($capabilities as $code=>$contract) $defaults[$code]=$contract['analysis_default_rank_object_kinds']??[];
$short=$rankingAdmission->match('这个月呢，哪天最高',$objectVocabulary,array_keys($capabilities),$defaults);
$check(($short[0]['object_kind']??null)==='business_date'
    && ($short[0]['direction']??null)==='top',
    'calendar discourse particle preserves a fully closed date extremum');
$exactMethod=new ReflectionMethod($gateway,'compileExactRegisteredRankingCollection');
$reason=null;
$source=['query'=>['query_shape'=>'summary','metric_codes'=>['cash_performance'],
    'store_ids'=>[],'business_filters'=>[],'aggregate_condition'=>null,'condition_set'=>null,
    'compare_range'=>null],'meaning'=>['presentation_origin'=>'customer_or_verified_context']];
$ready=['metric_codes'=>array_keys($capabilities),'metric_readiness'=>$capabilities];
$args=['这个月业绩最高是哪天',$objectVocabulary,$ready,'screen','2026-10-08',&$reason,$source,[]];
$continued=$exactMethod->invokeArgs($gateway,$args);
$check(($continued['_context_reused']??false)===true
    && ($continued['plan']['query']['metric_codes']??null)===['cash_performance'],
    'complete broad date ranking keeps the verified cash metric');
$reason=null;$args[0]='这个月呢，哪天最高';
$shortContinued=$exactMethod->invokeArgs($gateway,$args);
$check(($shortContinued['_context_reused']??false)===true
    && ($shortContinued['plan']['query']['metric_codes']??null)===['cash_performance'],
    'short ranking change keeps the same verified metric');
$reason=null;$args[0]='这个月现金业绩最高是哪天';
$explicitCash=$exactMethod->invokeArgs($gateway,$args);
$check(($explicitCash['plan']['query']['metric_codes']??null)===['cash_performance'],
    'an explicitly stated measurement wins over the date default');
$source['query']['store_ids']=[9];$reason=null;$args[0]='这个月业绩最高是哪天';$args[6]=$source;
$scoped=$exactMethod->invokeArgs($gateway,$args);
$check($scoped===null && $reason==='context_scope_requires_understanding',
    'closed ranking never silently widens a signed selected-store scope');
$explicitIntent=$broadIntent;
$explicitIntent['metric_codes']=['sales_amount'];
$explicitIntent['needs_metric_choice']=false;
$check($rankDefault->invoke($gateway,$explicitIntent,$broadUnderstanding,$summaries)===$explicitIntent,
    'customer-selected sales amount is never replaced by a date-ranking default');

// Genuine ambiguity keeps the catalogue available for execution, while the
// four-choice UI receives only model-proposed, executable alternatives.
$dimensionCandidates=[];
foreach (['actual_performance','cash_performance','sales_amount','consume_amount'] as $code) {
    $dimensionCandidates[$code]=['name'=>$code,'summary'=>'registered metric','action_codes'=>[]];
}
$guided=(new AiDimensionGuidancePlanner())->start('business_date',[
    'operation'=>'ranking','metric_codes'=>[],'action_codes'=>[],
    'needs_metric_choice'=>true,
    'metric_choice_candidates'=>['sales_amount','cash_performance','actual_performance'],
    'ranking'=>['direction'=>'top','limit'=>1],
],['date_terms'=>[['code'=>'EXPLICIT','start'=>'2026-09-01','end'=>'2026-09-30']]],
    $dimensionCandidates,'screen','2026-10-08');
$options=$guided['fields'][0]['options']??[];
$check(array_column(array_slice($options,0,3),'value')===['sales_amount','cash_performance','actual_performance']
    && count(array_filter($options,static fn(array $option):bool=>($option['recommended']??false)===true))===3,
    'dimension guidance preserves three relevant executable recommendations');
$incompleteCard=(new AiDimensionGuidancePlanner())->start('business_date',[
    'operation'=>'ranking','metric_codes'=>[],'action_codes'=>[],
    'needs_metric_choice'=>true,'metric_choice_candidates'=>['sales_amount'],
    'ranking'=>['direction'=>'top','limit'=>1],
],['date_terms'=>[['code'=>'EXPLICIT','start'=>'2026-09-01','end'=>'2026-09-30']],
    'no_filler_guidance'=>false],
    $dimensionCandidates,'screen','2026-10-08');
$incompleteOptions=$incompleteCard['fields'][0]['options']??[];
$check(array_column(array_slice($incompleteOptions,0,3),'value')===
    ['sales_amount','__edit_original__','__retry_original__'],
    'one executable recommendation is preserved and safe actions fill the card');
$actionRejected=false;
try {(new AiDimensionGuidancePlanner())->choose($incompleteCard,['dimension_metric'=>'__edit_original__']);}
catch (RuntimeException $error) {$actionRejected=$error->getMessage()==='AI_CLARIFICATION_INVALID';}
$check($actionRejected,'recovery actions cannot be submitted as metric codes');

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
    && ($answer['table']['rows'][0]['label']??null)==='周三（2026-09-02）',
    'answer presents the winning weekday and exact date instead of a store identity');

$view['results'][0]['rows']=['bottom'=>[
    ['business_date'=>'2026-09-01','amount_cents'=>0],
    ['business_date'=>'2026-09-03','amount_cents'=>10000],
], 'top'=>[
    ['business_date'=>'2026-09-02','amount_cents'=>28000],
    ['business_date'=>'2026-09-03','amount_cents'=>10000],
]];
$bothAnswer=(new AiAnswerRenderer())->render($view);
$check(str_contains($bothAnswer['summary'],'第1名是周三（2026-09-02）')
    && str_contains($bothAnswer['summary'],'倒数第1名是周二（2026-09-01）')
    && array_column($bothAnswer['table']['rows'],'rank_direction')===['top','bottom','top','bottom'],
    'signed bottom-first evidence renders both extremes and alternates the two groups');

$unanchored=new ReflectionMethod(\app\services\ai\AiGatewayServices::class,'unanchoredCalendarQuestion');
$week=['requirements'=>[['fields'=>['metric_codes','periods'],'values'=>[
    'metric_codes'=>['actual_performance'],'periods'=>[['kind'=>'calendar_period','unit'=>'week',
        'offset'=>-1,'count'=>1,'end_mode'=>'full']]]]]];
$check($unanchored->invoke($gateway,'上周呢',$week)===true
    && $unanchored->invoke($gateway,'上周营业额呢',$week)===false,
    'a bare calendar after topic reset cannot acquire a model-invented business metric');

echo 'temporal-ranking: PASS ('.$checks." checks; offline fixtures)\n";
