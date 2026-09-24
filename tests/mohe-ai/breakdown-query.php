<?php
/** R38 generic object-breakdown contract; offline and free of customer data. */
require_once __DIR__.'/fixture-autoload.php';

use app\services\ai\contract\AiIntentResultContract;
use app\services\ai\contract\AiIntentUnderstandingContract;
use app\services\ai\execution\AiAuthority;
use app\services\ai\execution\AiCapabilityGuidanceCatalog;
use app\services\ai\execution\AiRegisteredPlanCompiler;
use app\services\ai\execution\AiWorkflowPlanner;
use app\services\ai\presentation\AiAnswerRenderer;
use app\services\ai\registry\AiBusinessRegistry;
use app\services\query\metric\MetricReadViewExportProvider;

$checks=0;
function bdCheck(bool $ok,string $label): void
{
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL: '.$label);
    ++$checks;
}

try {
    $understandingPrompt=AiIntentUnderstandingContract::modelInstruction();
    $bindingPrompt=AiIntentResultContract::modelInstruction(false);
    bdCheck(strpos($understandingPrompt,'各个门店业绩')!==false
        && strpos($understandingPrompt,'每位员工业绩')!==false,
        'language contract defines a generic each-object breakdown');
    bdCheck(strpos($bindingPrompt,'must never be downgraded to one overall summary or upgraded to a ranking')!==false,
        'binding contract preserves breakdown semantics');

    $context=['scope_mode'=>'stores','store_ids'=>[1,2],'analysis_personnel_ready'=>true];
    $capabilities=AiAuthority::capabilities(false,$context);
    foreach (['store','person','project'] as $objectKind) {
        $items=AiCapabilityGuidanceCatalog::discover($capabilities,$objectKind,'breakdown');
        bdCheck($items!==[],'registry exposes breakdown for '.$objectKind);
    }
    bdCheck(isset(AiCapabilityGuidanceCatalog::discover($capabilities,'store','breakdown')['cash_performance']),
        'cash performance can be listed by store');
    bdCheck(isset(AiCapabilityGuidanceCatalog::discover($capabilities,'person','breakdown')['staff_sales_yeji']),
        'staff sales performance can be listed by person');

    $planner=new AiWorkflowPlanner();
    $projection=['signals'=>['cash_performance','breakdown'],'dates'=>[],
        'date_terms'=>[['code'=>'TODAY']],'date_grouping_ambiguous'=>false];
    $selection=['decision'=>'query','query_shape'=>'breakdown','metric_codes'=>['cash_performance'],
        'object_kind'=>'store','ranking'=>null,'aggregate_condition'=>null];
    $planned=$planner->compile($projection,$selection,$capabilities,'screen','2026-09-24');
    bdCheck(($planned['kind']??null)==='plan'
        && ($planned['plan']['query']['business_filters']??null)===['object_kind'=>'store']
        && array_key_exists('ranking',$planned['plan']['query'])
        && $planned['plan']['query']['ranking']===null,
        'store breakdown compiles without a rank');
    $planned['plan']['query']['store_ids']=[1,2];
    $compiled=(new AiRegisteredPlanCompiler(new AiBusinessRegistry()))->compile($planned['plan'],$capabilities);
    bdCheck(($compiled['workflow_code']??null)==='wf_performance_breakdown'
        && ($compiled['query']['query_shape']??null)==='breakdown',
        'registered compiler accepts the generic breakdown workflow');

    $personProjection=['signals'=>['staff_sales_yeji','breakdown'],'dates'=>[],
        'date_terms'=>[['code'=>'TODAY']],'date_grouping_ambiguous'=>false];
    $personSelection=['decision'=>'query','query_shape'=>'breakdown','metric_codes'=>['staff_sales_yeji'],
        'object_kind'=>'person','ranking'=>null,'aggregate_condition'=>null];
    $personPlan=$planner->compile($personProjection,$personSelection,$capabilities,'screen','2026-09-24');
    bdCheck(($personPlan['plan']['query']['business_filters']??null)===['object_kind'=>'person'],
        'person breakdown is a population query, not a selected-person lookup');

    $view=[
        'query'=>['query_shape'=>'breakdown','metric_codes'=>['cash_performance'],
            'start_date'=>'2026-09-24','end_date'=>'2026-09-24','compare_range'=>null,
            'store_ids'=>[1,2],'business_filters'=>['object_kind'=>'store'],'ranking'=>null,'aggregate_condition'=>null],
        'results'=>[['period'=>'current','metric_code'=>'cash_performance','storage_unit'=>'fen',
            'object_kind'=>'store','object_label'=>'门店','has_more'=>false,'object_count'=>2,'list_limit'=>99,
            'rows'=>[
                ['entity_id'=>1,'entity_name'=>'甲店','amount_cents'=>1234500],
                ['entity_id'=>2,'entity_name'=>'乙店','amount_cents'=>0],
            ]]],
    ];
    $answer=(new AiAnswerRenderer())->render($view);
    bdCheck(($answer['table']['columns'][0]['label']??null)==='门店'
        && array_column($answer['table']['rows'],'label')===['甲店','乙店'],
        'renderer presents one row per object including zero activity');
    bdCheck(strpos($answer['summary'],'排名')===false && !array_key_exists('rank',$answer['table']['rows'][0]),
        'breakdown answer never claims or fabricates ranking');
    $export=MetricReadViewExportProvider::project($view);
    bdCheck(count($export)===2 && array_column($export,'store_name')===['甲店；范围：当前授权范围','乙店；范围：当前授权范围'],
        'Excel projection uses the same immutable breakdown rows');

    $truncated=$view;
    $truncated['results'][0]['has_more']=true;
    $truncated['results'][0]['object_count']=120;
    $limited=(new AiAnswerRenderer())->render($truncated);
    bdCheck(strpos($limited['summary'],'当前展示前99条')!==false
        && strpos($limited['summary'],'Excel 查看完整结果')===false,
        'bounded answer states its limit without promising unseen Excel rows');

    echo 'breakdown-query: PASS ('.$checks." checks; offline fixtures)\n";
} catch (Throwable $error) {
    fwrite(STDERR,$error->getMessage()."\n".$error->getTraceAsString()."\n");
    exit(1);
}
