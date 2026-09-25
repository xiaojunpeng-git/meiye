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

    $summaryQuestion=$safeQuestion;
    $summaryQuestion['question']='这个月的门店现金业绩、消耗业绩、退款金额分别是多少';
    $summaryQuestion['evidence_messages']=[['id'=>'current','text'=>$summaryQuestion['question']]];
    $summaryUnderstanding=AiIntentUnderstandingContract::normalize([
        'goal'=>'查看本月三项门店经营指标','status'=>'understood','requirements'=>[[
            'id'=>'r1','meaning'=>'查看本月门店现金业绩、消耗业绩和退款金额',
            'fields'=>['metric_codes','object_kind','object_relation','operation','periods'],
            'values'=>['metric_terms'=>['现金业绩','消耗业绩','退款金额'],'object_kind'=>'store',
                'object_relation'=>'analysis','operation'=>'summary','periods'=>[['kind'=>'month_offset','offset_months'=>0]]],
            'evidence'=>[['message_id'=>'current','quote'=>$summaryQuestion['question']]],
        ]],
    ],$summaryQuestion);
    $summaryCandidate=$coordinatedCandidate;
    $summaryCandidate['operation']='summary';
    $summaryCandidate['periods']=[['kind'=>'month_offset','offset_months'=>0]];
    $summaryCandidate['metric_codes']=['cash_performance'];
    $summaryCandidate['requirement_bindings']=[[
        'requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['cash_performance'],
    ]];
    $summaryCandidate=AiIntentResultContract::canonicalizeUniqueExactMetricBinding(
        $summaryCandidate,$summaryUnderstanding,$summaryQuestion,
        ['cash_performance','consume_amount','refund_performance']
    );
    $summaryIntent=AiIntentResultContract::normalize(
        $summaryCandidate,['cash_performance','consume_amount','refund_performance'],[],
        $summaryQuestion,$summaryUnderstanding
    );
    bdCheck(($summaryIntent['metric_codes']??null)===[
        'cash_performance','consume_amount','refund_performance',
    ] && ($summaryIntent['requirement_bindings'][0]['metric_codes']??null)===[
        'cash_performance','consume_amount','refund_performance',
    ],'exact coordinated summary metrics are recovered in customer order without another model repair');
    bdCheck(AiIntentResultContract::isUniqueExactMetricBinding(
        $summaryIntent,$summaryUnderstanding,$summaryQuestion,
        ['cash_performance','consume_amount','refund_performance']
    ),'registry-proven coordinated summary bypasses a redundant model review');
    $summaryFollowupQuestion=$summaryQuestion;
    $summaryFollowupQuestion['prior_query']=[
        'query_shape'=>'breakdown','metric_codes'=>['actual_performance'],
        'start_date'=>'2026-09-24','end_date'=>'2026-09-24','compare_range'=>null,
        'store_ids'=>[],'business_filters'=>['object_kind'=>'store'],
        'ranking'=>null,'aggregate_condition'=>null,
    ];
    $compiledSummary=AiIntentResultContract::exactCoordinatedIntent(
        $summaryUnderstanding,$summaryFollowupQuestion,
        ['cash_performance','consume_amount','refund_performance'],true
    );
    bdCheck(($compiledSummary['operation']??null)==='summary'
        &&($compiledSummary['context_delta']['business_filters']??null)==='clear'
        &&($compiledSummary['context_delta']['store_scope']??null)==='inherit',
        'accepted exact coordinated meaning compiles without a duplicate binding model call');
    $implicitAnalysis=$summaryUnderstanding;
    unset($implicitAnalysis['requirements'][0]['values']['object_relation']);
    $implicitAnalysis['requirements'][0]['fields']=array_values(array_filter(
        $implicitAnalysis['requirements'][0]['fields'],fn($field)=>$field!=='object_relation'
    ));
    bdCheck((AiIntentResultContract::exactCoordinatedIntent(
        $implicitAnalysis,$summaryQuestion,
        ['cash_performance','consume_amount','refund_performance'],false
    )['object_relation']??null)==='analysis',
        'typed summary object may compile its redundant analytical relation locally');
    $selectedObject=$summaryUnderstanding;
    $selectedObject['requirements'][0]['values']['object_relation']='selection';
    bdCheck(AiIntentResultContract::exactCoordinatedIntent(
        $selectedObject,$summaryQuestion,
        ['cash_performance','consume_amount','refund_performance'],false
    )===null,'an explicit selected target cannot enter the analytical fast path');

    $splitRequirements=$summaryUnderstanding;
    $splitRequirements['requirements']=[];
    foreach ([
        ['r1','现金业绩','现金业绩'],['r2','消耗业绩','消耗业绩'],['r3','退款金额','退款金额'],
    ] as [$id,$meaning,$term]) {
        $splitRequirements['requirements'][]=[
            'id'=>$id,'meaning'=>'查看'.$meaning,'fields'=>['metric_codes'],
            'values'=>['metric_terms'=>[$term]],
            'evidence'=>[['message_id'=>'current','quote'=>$summaryQuestion['question']]],
        ];
    }
    $splitRequirements=AiIntentUnderstandingContract::normalize($splitRequirements,$summaryQuestion);
    $splitCandidate=$summaryCandidate;
    $splitCandidate['requirement_bindings']=[[
        'requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['cash_performance'],
    ]];
    $splitCandidate=AiIntentResultContract::canonicalizeUniqueExactMetricBinding(
        $splitCandidate,$splitRequirements,$summaryQuestion,
        ['cash_performance','consume_amount','refund_performance']
    );
    bdCheck(array_column($splitCandidate['requirement_bindings'],'metric_codes')===[
        ['cash_performance'],['consume_amount'],['refund_performance'],
    ],'separately accepted metric requirements retain one-to-one audit ownership');
    bdCheck(AiIntentResultContract::isUniqueExactMetricBinding(
        $splitCandidate,$splitRequirements,$summaryQuestion,
        ['cash_performance','consume_amount','refund_performance']
    ),'separate exact requirement owners also satisfy deterministic admission');

    $excludedRequirements=$splitRequirements;
    $excludedRequirements['requirements'][2]['values']['metric_exclusions']=['退款金额'];
    $excludedCandidate=$summaryCandidate;
    $excludedCandidate['metric_codes']=['cash_performance'];
    $excludedCandidate=AiIntentResultContract::canonicalizeUniqueExactMetricBinding(
        $excludedCandidate,$excludedRequirements,$summaryQuestion,
        ['cash_performance','consume_amount','refund_performance']
    );
    bdCheck($excludedCandidate['metric_codes']===['cash_performance'],
        'an explicit metric exclusion cannot be converted into a positive coordinated binding');

    $unsafeSummary=$summaryCandidate;
    $unsafeSummary['operation']='comparison';
    $unsafeSummary['metric_codes']=['cash_performance'];
    $unsafeSummary['requirement_bindings'][0]['metric_codes']=['cash_performance'];
    $unsafeSummary=AiIntentResultContract::canonicalizeUniqueExactMetricBinding(
        $unsafeSummary,$summaryUnderstanding,$summaryQuestion,
        ['cash_performance','consume_amount','refund_performance']
    );
    bdCheck($unsafeSummary['metric_codes']===['cash_performance'],
        'comparison relationships remain model-owned instead of receiving a flat metric projection');
    $comparisonUnderstanding=$summaryUnderstanding;
    $comparisonUnderstanding['requirements'][0]['values']['operation']='comparison';
    bdCheck(AiIntentResultContract::exactCoordinatedIntent(
        $comparisonUnderstanding,$summaryQuestion,
        ['cash_performance','consume_amount','refund_performance'],false
    )===null,'comparison relationships cannot enter the coordinated summary fast path');

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
    $continuationQuestion=$safeQuestion;
    $continuationQuestion['question']='各对象的经营详情呢';
    $continuationQuestion['evidence_messages']=[['id'=>'current','text'=>$continuationQuestion['question']]];
    $continuationQuestion['prior_query']=[
        'query_shape'=>'summary','metric_codes'=>['cash_performance','actual_performance'],
        'start_date'=>'2026-09-01','end_date'=>'2026-09-24','compare_range'=>null,
        'periods'=>[['kind'=>'date_range','start'=>'2026-09-01','end'=>'2026-09-24']],
        'store_ids'=>[1,2],'has_store_scope_restriction'=>true,
        'business_filters'=>['object_kind'=>'store'],'has_business_filter'=>true,
        'ranking'=>null,'aggregate_condition'=>null,
    ];
    $continuationCapabilities=[];
    foreach ([
        ['actual_performance','store'],['staff_sales_yeji','person'],['cash_performance','member'],
    ] as [$code,$kind]) $continuationCapabilities[]=[
        'metric_code'=>$code,'default_breakdown_object_kinds'=>[$kind],
    ];
    foreach ([
        'store'=>'actual_performance','person'=>'staff_sales_yeji','member'=>'cash_performance',
    ] as $kind=>$expectedCode) {
        $continuationUnderstanding=AiIntentUnderstandingContract::normalize([
            'goal'=>'按对象查看经营详情','status'=>'understood','requirements'=>[[
                'id'=>'r1','meaning'=>'按对象查看经营详情',
                'fields'=>['object_kind','object_relation','operation'],
                'values'=>['object_kind'=>$kind,'object_relation'=>'analysis','operation'=>'breakdown'],
                'evidence'=>[['message_id'=>'current','quote'=>$continuationQuestion['question']]],
            ]],
        ],$continuationQuestion);
        $continuationIntent=AiIntentResultContract::registeredBreakdownContinuationIntent(
            $continuationUnderstanding,$continuationQuestion,$continuationCapabilities,
            $continuationQuestion['prior_query']
        );
        bdCheck(($continuationIntent['metric_codes']??null)===[$expectedCode]
            &&($continuationIntent['operation']??null)==='breakdown'
            &&($continuationIntent['context_delta']['periods']??null)==='inherit'
            &&($continuationIntent['context_delta']['business_filters']??null)==='clear',
            'typed '.$kind.' continuation uses its sole registry default while retaining date and authority');
        $implicitRelation=$continuationUnderstanding;
        unset($implicitRelation['requirements'][0]['values']['object_relation']);
        $implicitRelation['requirements'][0]['fields']=array_values(array_filter(
            $implicitRelation['requirements'][0]['fields'],static fn($field):bool=>$field!=='object_relation'
        ));
        bdCheck((AiIntentResultContract::registeredBreakdownContinuationIntent(
            $implicitRelation,$continuationQuestion,$continuationCapabilities,$continuationQuestion['prior_query']
        )['object_relation']??null)==='analysis',
            'typed '.$kind.' breakdown may compile its redundant analytical relation locally');
        $restatedPeriod=$continuationUnderstanding;
        $restatedPeriod['requirements'][0]['fields'][]='periods';
        $restatedPeriod['requirements'][0]['values']['periods']=[[
            'kind'=>'date_range','start'=>'2026-09-01','end'=>'2026-09-24',
        ]];
        bdCheck((AiIntentResultContract::registeredBreakdownContinuationIntent(
            $restatedPeriod,$continuationQuestion,$continuationCapabilities,$continuationQuestion['prior_query']
        )['context_delta']['periods']??null)==='inherit',
            'typed '.$kind.' continuation may restate only the same signed predecessor period');
    }
    $explicitContinuation=$continuationQuestion;
    $explicitContinuation['question']='各门店现金业绩详情';
    $explicitContinuation['evidence_messages']=[['id'=>'current','text'=>$explicitContinuation['question']]];
    $explicitUnderstanding=AiIntentUnderstandingContract::normalize([
        'goal'=>'查看各门店现金业绩','status'=>'understood','requirements'=>[[
            'id'=>'r1','meaning'=>'查看各门店现金业绩',
            'fields'=>['metric_codes','object_kind','object_relation','operation'],
            'values'=>['metric_terms'=>['现金业绩'],'object_kind'=>'store','object_relation'=>'analysis','operation'=>'breakdown'],
            'evidence'=>[['message_id'=>'current','quote'=>$explicitContinuation['question']]],
        ]],
    ],$explicitContinuation);
    bdCheck(AiIntentResultContract::registeredBreakdownContinuationIntent(
        $explicitUnderstanding,$explicitContinuation,$continuationCapabilities,$explicitContinuation['prior_query']
    )===null,'an explicit registered measurement never enters the broad continuation default');
    $detailUnderstanding=AiIntentUnderstandingContract::normalize([
        'goal'=>'查看具体对象详情','status'=>'understood','requirements'=>[[
            'id'=>'r1','meaning'=>'查看具体对象详情','fields'=>['object_detail'],
            'values'=>['object_detail'=>['view'=>'summary','target'=>'single','ordinal'=>null]],
            'evidence'=>[['message_id'=>'current','quote'=>$continuationQuestion['question']]],
        ]],
    ],$continuationQuestion);
    bdCheck(AiIntentResultContract::registeredBreakdownContinuationIntent(
        $detailUnderstanding,$continuationQuestion,$continuationCapabilities,$continuationQuestion['prior_query']
    )===null,'a concrete object-detail continuation cannot be rewritten as a population breakdown');
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
