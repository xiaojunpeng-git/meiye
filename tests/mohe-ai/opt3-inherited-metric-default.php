<?php
declare(strict_types=1);

/** Synthetic proof of default-policy boundaries after a signed context merge.
 * No provider, app bootstrap, credentials, database or business facts are used.
 * The prior source and already accepted typed deltas are explicit fixtures;
 * this does not claim to reconstruct the unpersisted live model payload. */
require __DIR__.'/fixture-autoload.php';

use app\services\ai\AiGatewayServices;
use app\services\ai\context\IntentContextMerger;
use app\services\ai\contract\AiIntentResultContract;
use app\services\query\metric\MetricDefinitionRegistry;

$gateway=(new ReflectionClass(AiGatewayServices::class))->newInstanceWithoutConstructor();
$methods=[];
foreach (['applyRegisteredRankDefaultPolicy','applyRegisteredBreakdownDefaultPolicy','registeredRankDefault'] as $name) {
    $methods[$name]=new ReflectionMethod($gateway,$name);
    if (PHP_VERSION_ID<80100) $methods[$name]->setAccessible(true);
}
$summaries=[];
foreach (MetricDefinitionRegistry::capabilities() as $code=>$contract) {
    $summaries[]=['metric_code'=>$code,
        'default_rank_object_kinds'=>$contract['analysis_default_rank_object_kinds']??[],
        'default_breakdown_object_kinds'=>$contract['analysis_default_breakdown_object_kinds']??[]];
}
$prior=['query_shape'=>'summary','metric_codes'=>['consume_amount'],'start_date'=>'2026-10-01',
    'end_date'=>'2026-10-11','compare_range'=>null,'store_ids'=>[],'business_filters'=>[],
    'ranking'=>null,'aggregate_condition'=>null];
$checks=0;$failures=[];$observations=[];
$check=static function(bool $ok,string $label)use(&$checks,&$failures):void {
    ++$checks;if (!$ok) $failures[]=$label;
};
foreach (['ranking'=>'applyRegisteredRankDefaultPolicy','breakdown'=>'applyRegisteredBreakdownDefaultPolicy'] as $shape=>$method) {
    $meaning=['status'=>'understood','topic_relation'=>'continuation','requirements'=>[[
        'id'=>'r1','meaning'=>'change presentation only','fields'=>['operation','object_kind','object_relation'],
        'values'=>['operation'=>$shape,'object_kind'=>'store','object_relation'=>'analysis'],
        'evidence'=>[['message_id'=>'current','quote'=>$shape==='ranking'?'按门店排名':'按门店显示']]]]];
    $delta=array_fill_keys(AiIntentResultContract::DELTA_FIELDS,'inherit');
    $delta['operation']='replace';
    if ($shape==='ranking') {$delta['ranking_direction']='replace';$delta['ranking_limit']='replace';}
    $intent=['object_kind'=>'store','object_relation'=>'analysis','object_term'=>'','operation'=>$shape,
        'metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'recommended_initial_answer'=>false,
        'periods'=>[],'ranking'=>$shape==='ranking'?['direction'=>'top','limit'=>20]:['direction'=>'unspecified','limit'=>null],
        'scope'=>'authorized','aggregate_condition'=>null,'result_reference'=>null,
        'requirement_bindings'=>[],'unresolved_fragments'=>[],'context_delta'=>$delta];
    $merged=IntentContextMerger::merge($prior,$intent);
    $candidate=$merged['prospective_intent'];
    $check($candidate['metric_codes']===['consume_amount'],'merge owns the inherited '.$shape.' measurement');
    $changed=$methods[$method]->invoke($gateway,$candidate,$meaning,$summaries);
    $observations[$shape]=['before'=>$candidate['metric_codes'],'review_candidate'=>$changed['metric_codes'],
        'post_default_merge'=>IntentContextMerger::merge($prior,$changed)['intent']['metric_codes']];
    $check($changed['metric_codes']===['consume_amount'], $shape.' default must not replace the verified inherited measurement');
    $check($changed['metric_codes']===IntentContextMerger::merge($prior,$changed)['intent']['metric_codes'],
        $shape.' reviewer and eventual merged execution must see the same measurement');
    $check($changed===$candidate, $shape.' inherited policy preserves the complete accepted candidate');
    $check($changed['periods']===$candidate['periods'] && $changed['scope']===$candidate['scope']
        && $changed['context_delta']===$candidate['context_delta'] && $changed['ranking']===$candidate['ranking'],
        $shape.' dates, scope, typed deltas and ranking are never rewritten by metric defaults');
    $scoped=$prior;$scoped['store_ids']=[7,9];
    $scoped['business_filters']=['object_kind'=>'store'];
    $scopedMerge=IntentContextMerger::merge($scoped,$intent);
    $scopedDefault=$methods[$method]->invoke($gateway,$scopedMerge['prospective_intent'],$meaning,$summaries);
    $scopedAfter=IntentContextMerger::merge($scoped,$scopedDefault);
    $check($scopedAfter['constraints']===$scopedMerge['constraints']
        && $scopedAfter['intent']['periods']===$scopedMerge['intent']['periods'],
        $shape.' named store scope and verified calendar constraints survive the policy boundary');
    $gate=$shape==='ranking'?'canUseRegisteredRankDefault':'canUseRegisteredBreakdownDefault';
    foreach ([[],['consume_amount']] as $codes) foreach ([null,'continuation','new_topic'] as $relation) {
        $preMerge=$candidate;$preMerge['metric_codes']=$codes;
        $labelMeaning=$meaning;
        if ($relation===null) unset($labelMeaning['topic_relation']);else $labelMeaning['topic_relation']=$relation;
        $check(!AiIntentResultContract::$gate($labelMeaning,$preMerge),
            $shape.' inherit rejects defaults with '.($codes===[]?'empty raw':'merged').' metrics and '.($relation??'absent').' topic label');
    }
    // Incompatibility belongs to the ordinary capability/merger boundary. A
    // default cannot silently transform an unchanged metric into a new one.
    $incompatible=$candidate;$incompatible['object_kind']='inventory';$incompatible['context_delta']['object']='replace';
    $check(!AiIntentResultContract::$gate($meaning,$incompatible),
        $shape.' incompatible replacement object cannot erase metric inheritance');
    foreach (['replace','clear','pending'] as $decision) {
        $explicit=$candidate;$explicit['context_delta']['metric_codes']=$decision;
        $check(AiIntentResultContract::$gate($meaning,$explicit),
            $shape.' '.$decision.' keeps its existing broad-default admission, subject to later merger checks');
    }
    $fresh=$candidate;unset($fresh['context_delta']);$fresh['metric_codes']=[];
    $freshMeaning=$meaning;$freshMeaning['topic_relation']='new_topic';
    $default=$methods[$method]->invoke($gateway,$fresh,$freshMeaning,$summaries);
    $check($default['metric_codes']!==[], $shape.' fresh broad request keeps its registered default');
    if ($shape==='ranking') {
        // The rank-choice recovery calls the same source-owned selector before
        // ordinary merging, so inheritance protection must cover this entry too.
        $choice=$methods['registeredRankDefault']->invoke($gateway,$candidate,$meaning,$summaries,
            ['consume_amount','cash_performance']);
        $check($choice===null || $choice==='consume_amount',
            'rank metric-choice recovery must not propose another inherited measurement');
    }
}
echo json_encode(['suite'=>'OPT3-inherited-metric-default','checks'=>$checks,'failures'=>$failures,
    'observations'=>$observations,'pass'=>$failures===[]],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
if ($failures!==[]) exit(1);
