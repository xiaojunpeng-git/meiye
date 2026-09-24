<?php
/** R38 generic object-breakdown contract; offline and free of customer data. */
require_once __DIR__.'/fixture-autoload.php';

use app\services\ai\contract\AiIntentResultContract;
use app\services\ai\contract\AiIntentUnderstandingContract;
use app\services\ai\contract\AiContractException;
use app\services\ai\execution\AiAuthority;
use app\services\ai\execution\AiCapabilityGuidanceCatalog;
use app\services\ai\execution\AiRegisteredPlanCompiler;
use app\services\ai\execution\AiWorkflowPlanner;
use app\services\ai\model\SiliconFlowClient;
use app\services\ai\presentation\AiAnswerRenderer;
use app\services\ai\registry\AiBusinessRegistry;
use app\services\query\metric\MetricReadViewExportProvider;
use app\services\query\metric\MetricDefinitionRegistry;

$checks=0;
function bdCheck(bool $ok,string $label): void
{
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL: '.$label);
    ++$checks;
}

function bdReject(callable $call,string $label): void
{
    try {$call();} catch (AiContractException $error) {bdCheck(true,$label);return;}
    throw new RuntimeException('FAIL: accepted '.$label);
}

try {
    $understandingPrompt=AiIntentUnderstandingContract::modelInstruction();
    $bindingPrompt=AiIntentResultContract::modelInstruction(false);
    bdCheck(strpos($understandingPrompt,'各个门店业绩')!==false
        && strpos($understandingPrompt,'每位员工业绩')!==false,
        'language contract defines a generic each-object breakdown');
    bdCheck(strpos($bindingPrompt,'must never be downgraded to one overall summary or upgraded to a ranking')!==false,
        'binding contract preserves breakdown semantics');
    bdCheck(strpos($bindingPrompt,'A broad professional first reading of one measurement uses exactly one registered metric')!==false,
        'a broad breakdown recommendation cannot expand into an overview metric group');
    $safeQuestion=['schema_version'=>'sanitized-question-v2','question'=>'今天各门店业绩多少','has_unresolved_conditions'=>false,
        'server_resolved_fields'=>[],'reference_date'=>'2026-09-24','recent_questions'=>[],
        'evidence_messages'=>[['id'=>'current','text'=>'今天各门店业绩多少']],'prior_query'=>null];
    $understanding=AiIntentUnderstandingContract::normalize([
        'goal'=>'查看今天各门店业绩','status'=>'understood','requirements'=>[[
            'id'=>'r1','meaning'=>'查看今天各门店业绩','fields'=>['metric_codes','object_kind','object_relation','operation','periods'],
            'values'=>['metric_terms'=>['业绩'],'object_kind'=>'store','object_relation'=>'analysis','operation'=>'breakdown',
                'periods'=>[['kind'=>'date_range','start'=>'2026-09-24','end'=>'2026-09-24']]],
            'evidence'=>[['message_id'=>'current','quote'=>'今天各门店业绩多少']],
        ]],
    ],$safeQuestion);
    $broadProviderEcho=[
        'goal'=>'查看今天各门店业绩','status'=>'understood','requirements'=>[[
            'id'=>'r1','meaning'=>'查看今天各门店业绩','fields'=>['metric_codes','object_kind','object_relation','operation','periods'],
            'values'=>['metric_terms'=>['门店整体业绩'],'object_kind'=>'store','object_relation'=>'analysis','operation'=>'breakdown',
                'periods'=>[['kind'=>'date_range','start'=>'2026-09-24','end'=>'2026-09-24']]],
            'evidence'=>[['message_id'=>'current','quote'=>'今天各门店业绩多少']],
        ]],
    ];
    $broadNormalized=AiIntentUnderstandingContract::normalize($broadProviderEcho,$safeQuestion);
    bdCheck(!isset($broadNormalized['requirements'][0]['values']['metric_terms'])
        &&($broadNormalized['requirements'][0]['values']['operation']??null)==='breakdown',
        'a broad unregistered measurement keeps its typed breakdown while dropping only a fabricated echo');
    bdReject(static function()use($safeQuestion,$understanding):void {
        AiIntentResultContract::normalize([
            'object_kind'=>'store','object_relation'=>'analysis','object_term'=>'','operation'=>'breakdown',
            'metric_codes'=>['actual_performance','sales_amount'],'action_codes'=>[],'needs_metric_choice'=>false,
            'initial_observation'=>false,'recommended_initial_answer'=>true,'requirement_bindings'=>[],
            'ranking'=>['direction'=>'unspecified','limit'=>null],
            'periods'=>[['kind'=>'date_range','start'=>'2026-09-24','end'=>'2026-09-24']],
            'scope'=>'unspecified','unresolved_fragments'=>[],
        ],['actual_performance','sales_amount'],[],$safeQuestion,$understanding);
    },'multi-metric professional recommendation is rejected before execution');

    $coordinatedQuestion=$safeQuestion;
    $coordinatedQuestion['question']='今天各门店现金业绩和销售额分别是多少';
    $coordinatedQuestion['evidence_messages']=[['id'=>'current','text'=>$coordinatedQuestion['question']]];
    $coordinatedUnderstanding=AiIntentUnderstandingContract::normalize([
        'goal'=>'查看今天各门店两项业绩','status'=>'understood','requirements'=>[[
            'id'=>'r1','meaning'=>'查看今天各门店现金业绩和销售额','fields'=>['metric_codes','object_kind','object_relation','operation','periods'],
            // Simulate a provider dropping its malformed optional metric-term
            // echo while the exact current-message evidence remains intact.
            'values'=>['object_kind'=>'store','object_relation'=>'analysis','operation'=>'breakdown',
                'periods'=>[['kind'=>'date_range','start'=>'2026-09-24','end'=>'2026-09-24']]],
            'evidence'=>[['message_id'=>'current','quote'=>$coordinatedQuestion['question']]],
        ]],
    ],$coordinatedQuestion);
    $coordinatedCandidate=AiIntentResultContract::canonicalizeUniqueExactMetricBinding([
        'object_kind'=>'store','object_relation'=>'analysis','object_term'=>'','operation'=>'breakdown',
        'metric_codes'=>['cash_performance'],'action_codes'=>[],'needs_metric_choice'=>false,
        'initial_observation'=>false,'recommended_initial_answer'=>false,
        'requirement_bindings'=>[['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['cash_performance']]],
        'ranking'=>['direction'=>'unspecified','limit'=>null],
        'periods'=>[['kind'=>'date_range','start'=>'2026-09-24','end'=>'2026-09-24']],
        'scope'=>'unspecified','unresolved_fragments'=>[],
    ],$coordinatedUnderstanding,$coordinatedQuestion,['cash_performance','sales_amount']);
    $coordinatedIntent=AiIntentResultContract::normalize(
        $coordinatedCandidate,['cash_performance','sales_amount'],[],$coordinatedQuestion,$coordinatedUnderstanding
    );
    bdCheck(($coordinatedIntent['metric_codes']??null)===['cash_performance','sales_amount']
        &&($coordinatedIntent['requirement_bindings'][0]['metric_codes']??null)===['cash_performance','sales_amount'],
        'exact coordinated measurements repair model bookkeeping without phrase slicing');

    // A model-added second metric must not be mistaken for an explicit
    // customer conjunction and bypass the existing exact-title correction.
    $singleQuestion=$safeQuestion;
    $singleQuestion['question']='今天各门店现金业绩是多少';
    $singleQuestion['evidence_messages']=[['id'=>'current','text'=>$singleQuestion['question']]];
    $singleMeaning=$understanding;
    $singleMeaning['requirements'][0]['values']['metric_terms']=['现金业绩'];
    $singleMeaning['requirements'][0]['evidence']=[['message_id'=>'current','quote'=>$singleQuestion['question']]];
    $singleCorrected=AiIntentResultContract::canonicalizeUniqueExactMetricBinding(
        $coordinatedCandidate,$singleMeaning,$singleQuestion,['cash_performance','sales_amount']
    );
    bdCheck($singleCorrected['metric_codes']===['cash_performance']
        &&$singleCorrected['requirement_bindings'][0]['metric_codes']===['cash_performance'],
        'one explicit measurement removes an extra model-selected column');

    $context=['scope_mode'=>'stores','store_ids'=>[1,2],'analysis_personnel_ready'=>true];
    $capabilities=AiAuthority::capabilities(false,$context);
    foreach (['store','person','member','project'] as $objectKind) {
        $items=AiCapabilityGuidanceCatalog::discover($capabilities,$objectKind,'breakdown');
        bdCheck($items!==[],'registry exposes breakdown for '.$objectKind);
    }
    bdCheck(isset(AiCapabilityGuidanceCatalog::discover($capabilities,'store','breakdown')['cash_performance']),
        'cash performance can be listed by store');
    bdCheck(isset(AiCapabilityGuidanceCatalog::discover($capabilities,'person','breakdown')['staff_sales_yeji']),
        'staff sales performance can be listed by person');
    bdCheck(isset(AiCapabilityGuidanceCatalog::discover($capabilities,'member','breakdown')['cash_performance']),
        'cash performance can be listed by member through its registered fact dimension');
    $definitions=MetricDefinitionRegistry::capabilities();
    bdCheck(($definitions['actual_performance']['analysis_default_breakdown_object_kinds']??null)===['store']
        &&($definitions['staff_sales_yeji']['analysis_default_breakdown_object_kinds']??null)===['person']
        &&($definitions['cash_performance']['analysis_default_breakdown_object_kinds']??null)===['member'],
        'registry owns one broad breakdown first-answer perspective per supported object');
    $bindingBoundary=Closure::bind(static function(SiliconFlowClient $client,array $items): array {
        return $client->bindingBoundary($items);
    },null,SiliconFlowClient::class);
    $providerCapability=[
        'metric_code'=>'actual_performance','name'=>'实际业绩','summary'=>'实际业绩口径',
        'object_contracts'=>[['object_kind'=>'store','action_codes'=>['read']]],
        'query_shapes'=>['breakdown'],'default_selection_ref'=>null,
        'default_rank_object_kinds'=>[],'default_breakdown_object_kinds'=>['store'],
    ];
    [$boundaryCodes]=$bindingBoundary(new SiliconFlowClient(),[$providerCapability]);
    bdCheck($boundaryCodes===['actual_performance'],
        'provider boundary accepts and validates the source-owned breakdown default carrier');
    $canonicalBreakdown=Closure::bind(static function($raw,array $meaning,array $question,array $items,array $codes) {
        return SiliconFlowClient::canonicalizeRegisteredBreakdownDefault($raw,$meaning,$question,$items,$codes);
    },null,SiliconFlowClient::class);
    $providerBroadCandidate=[
        'object_kind'=>'store','object_relation'=>'analysis','object_term'=>'','operation'=>'breakdown',
        'metric_codes'=>['sales_amount','actual_performance'],'action_codes'=>[],'needs_metric_choice'=>false,
        'initial_observation'=>true,'recommended_initial_answer'=>false,'requirement_bindings'=>[],
        'ranking'=>['direction'=>'unspecified','limit'=>null],
        'periods'=>[['kind'=>'date_range','start'=>'2026-09-24','end'=>'2026-09-24']],
        'scope'=>'unspecified','unresolved_fragments'=>[],
    ];
    $canonicalBroad=$canonicalBreakdown(
        $providerBroadCandidate,$understanding,$safeQuestion,[$providerCapability],['actual_performance','sales_amount']
    );
    bdCheck(($canonicalBroad['metric_codes']??null)===['actual_performance']
        &&($canonicalBroad['initial_observation']??null)===false
        &&($canonicalBroad['recommended_initial_answer']??null)===true,
        'unique registry default removes a redundant broad-breakdown model repair');
    $explicitCandidate=$canonicalBreakdown(
        $providerBroadCandidate,$coordinatedUnderstanding,$coordinatedQuestion,[$providerCapability],['actual_performance','cash_performance','sales_amount']
    );
    bdCheck($explicitCandidate===$providerBroadCandidate,
        'explicit coordinated measurements never collapse into the broad-breakdown default');

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
    $memberSelection=$personSelection;$memberSelection['metric_codes']=['cash_performance'];$memberSelection['object_kind']='member';
    $memberPlan=$planner->compile($projection,$memberSelection,$capabilities,'screen','2026-09-24');
    bdCheck(($memberPlan['plan']['query']['business_filters']??null)===['object_kind'=>'member'],
        'member breakdown uses the same generic population plan');

    $view=[
        'query'=>['query_shape'=>'breakdown','metric_codes'=>['cash_performance'],
            'start_date'=>'2026-09-24','end_date'=>'2026-09-24','compare_range'=>null,
            'store_ids'=>[1,2],'business_filters'=>['object_kind'=>'store'],'ranking'=>null,'aggregate_condition'=>null],
        'results'=>[['period'=>'current','metric_code'=>'cash_performance','storage_unit'=>'fen',
            'object_kind'=>'store','object_label'=>'门店','has_more'=>false,'object_count'=>3,'list_limit'=>99,
            'active_object_count'=>2,'aggregate_value'=>1229500,
            'rows'=>[
                ['entity_id'=>1,'entity_name'=>'甲店','amount_cents'=>1234500],
                ['entity_id'=>2,'entity_name'=>'乙店','amount_cents'=>0],
                ['entity_id'=>3,'entity_name'=>'丙店','amount_cents'=>-5000],
            ]]],
    ];
    $answer=(new AiAnswerRenderer())->render($view);
    bdCheck(($answer['table']['columns'][0]['label']??null)==='门店'
        && array_column($answer['table']['rows'],'label')===['甲店','丙店']
        && strpos($answer['summary'],'本期有2家门店产生现金业绩，合计12,295元')!==false
        && strpos($answer['summary'],'已隐藏1家0值门店')!==false,
        'renderer leads with an exact concise total, preserves negative values and hides zeros');
    bdCheck(strpos($answer['summary'],'排名')===false && !array_key_exists('rank',$answer['table']['rows'][0]),
        'breakdown answer never claims or fabricates ranking');
    bdCheck(array_column($answer['table']['columns'],'label')===['门店','现金业绩（元）']
        &&($answer['table']['rows'][0]['metric_cash_performance']??null)==='12,345',
        'single-metric breakdown uses one concise business column');

    $multi=$view;
    $multi['query']['metric_codes']=['cash_performance','sales_amount'];
    $multi['results'][]=['period'=>'current','metric_code'=>'sales_amount','storage_unit'=>'fen',
        'object_kind'=>'store','object_label'=>'门店','has_more'=>false,'object_count'=>3,'list_limit'=>99,
        'rows'=>[
            ['entity_id'=>1,'entity_name'=>'甲店','amount_cents'=>2234500],
            ['entity_id'=>2,'entity_name'=>'乙店','amount_cents'=>50000],
            ['entity_id'=>3,'entity_name'=>'丙店','amount_cents'=>0],
        ]];
    $multiAnswer=(new AiAnswerRenderer())->render($multi);
    bdCheck(count($multiAnswer['table']['rows'])===3
        &&array_column($multiAnswer['table']['columns'],'label')===['门店','现金业绩（元）','销售额（元）']
        &&($multiAnswer['table']['rows'][0]['metric_cash_performance']??null)==='12,345'
        &&($multiAnswer['table']['rows'][0]['metric_sales_amount']??null)==='22,345',
        'explicit multiple metrics stay on one row per object');
    $sparse=$multi;
    $sparse['results'][1]['rows']=[$sparse['results'][1]['rows'][0]];
    $sparse['results'][0]['rows'][1]['amount_cents']=10000;
    $sparseAnswer=(new AiAnswerRenderer())->render($sparse);
    bdCheck(!array_key_exists('metric_sales_amount',$sparseAnswer['table']['rows'][1]),
        'missing metric evidence is not fabricated as zero');
    $sameName=$multi;
    foreach ($sameName['results'] as &$metricResult) $metricResult['rows'][1]['entity_name']='甲店';
    unset($metricResult);
    $sameNameAnswer=(new AiAnswerRenderer())->render($sameName);
    bdCheck(count($sameNameAnswer['table']['rows'])===3
        &&$sameNameAnswer['table']['rows'][1]['metric_sales_amount']==='500',
        'identically named objects keep separate stable-identity rows');
    $export=MetricReadViewExportProvider::project($view);
    bdCheck(count($export)===3 && array_column($export,'store_name')===['甲店；范围：当前授权范围','乙店；范围：当前授权范围','丙店；范围：当前授权范围'],
        'Excel projection uses the same immutable breakdown rows');

    $truncated=$view;
    $truncated['results'][0]['has_more']=true;
    $truncated['results'][0]['object_count']=120;
    $truncated['results'][0]['active_object_count']=21;
    $truncated['results'][0]['rows']=[];
    for ($i=1;$i<=21;++$i) $truncated['results'][0]['rows'][]=[
        'entity_id'=>$i,'entity_name'=>'门店'.$i,'amount_cents'=>(22-$i)*100,
    ];
    $limited=(new AiAnswerRenderer())->render($truncated);
    bdCheck(count($limited['table']['rows'])===20
        && strpos($limited['summary'],'当前显示前20条')!==false
        && strpos($limited['summary'],'缩小范围')!==false,
        'bounded answer keeps the first screen concise without overstating unseen export coverage');

    echo 'breakdown-query: PASS ('.$checks." checks; offline fixtures)\n";
} catch (Throwable $error) {
    fwrite(STDERR,$error->getMessage()."\n".$error->getTraceAsString()."\n");
    exit(1);
}
