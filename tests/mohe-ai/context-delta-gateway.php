<?php
// Gateway regressions for model context deltas. Synthetic facts/model only.
require __DIR__.'/r6-gateway-harness.php';

$checks=0;
function cdgCheck($ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException('context delta gateway: '.$label);$checks++;}
function cdgDelta(): array {return array_fill_keys(\app\services\ai\contract\AiIntentResultContract::DELTA_FIELDS,'inherit');}

$h=null;
try {
    $choiceHarness=new R6GatewayHarness(3,[1,2],'platform');
    $choiceHarness->understandingOverride=['goal'=>'查看今天的业绩','status'=>'understood','requirements'=>[
        ['id'=>'r1','meaning'=>'查看业绩','fields'=>['metric_codes'],'values'=>['metric_terms'=>['业绩']],'evidence'=>[['message_id'=>'current','quote'=>'业绩']]],
        ['id'=>'r2','meaning'=>'今天','fields'=>['periods'],'values'=>['periods'=>[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]]],'evidence'=>[['message_id'=>'current','quote'=>'今天']]],
    ]];
    $choiceHarness->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary','metric_codes'=>['actual_performance'],'action_codes'=>[],
        'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],
        'periods'=>[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]],'scope'=>'authorized',
        'requirement_bindings'=>[['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['actual_performance']]],'unresolved_fragments'=>[]];
    $choiceHarness->semanticIntentByRepair=['open_overview_candidate'=>['object_kind'=>'store','object_term'=>'','operation'=>'summary',
        'metric_codes'=>['cash_performance','actual_performance'],'action_codes'=>[],'needs_metric_choice'=>false,
        'initial_observation'=>true,'ranking'=>['direction'=>'unspecified','limit'=>null],
        'periods'=>[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]],'scope'=>'authorized',
        'requirement_bindings'=>[['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['cash_performance','actual_performance']]],'unresolved_fragments'=>[]]];
    $choiceHarness->bindingVerificationOverride=['decision'=>'metric_choice','rejected_requirement_ids'=>[]];
    $choiceHarness->bindingReviewKind='candidate_blind_uniqueness';
    $beforeChoice=$choiceHarness->queries;
    $choiceFinal=$choiceHarness->start('今天业绩多少');
    cdgCheck($choiceFinal['status']==='COMPLETED' && $choiceHarness->queries>$beforeChoice,
        'a reviewer-confirmed broad operating question repairs into the registered overview rather than opening a metric selector: '.($choiceFinal['status']??'missing').'/'.($choiceFinal['reason']??'none'));
    $choiceHarness->close();

    // A completed query is a signed context, regardless of whether its
    // metric perspective originated from the customer or the platform.
    $overviewMetricCodes=['metric_01','metric_02','metric_03','metric_04','metric_05','metric_06','metric_07','metric_08','metric_09'];
    $overviewInherited=\app\services\ai\contract\AiIntentResultContract::inheritedPeriodOnlyContextIntent(
        ['status'=>'understood','requirements'=>[
            ['id'=>'r1','meaning'=>'查看这个月','fields'=>['periods'],'values'=>['periods'=>[['kind'=>'month_offset','offset_months'=>0]]],'evidence'=>[['message_id'=>'current','quote'=>'这个月']]],
        ]],
        ['metric_codes'=>$overviewMetricCodes,'query_shape'=>'summary','ranking'=>['direction'=>'unspecified','limit'=>null],'business_filters'=>['object_kind'=>'store']],
        ['presentation_origin'=>'platform_observation']
    );
    cdgCheck(is_array($overviewInherited)&&($overviewInherited['metric_codes']??null)===$overviewMetricCodes
        &&($overviewInherited['initial_observation']??false)===true,
        'a signed query retains its complete registered profile on a typed period-only continuation');
    $periodWithPriorEvidence=\app\services\ai\contract\AiIntentResultContract::inheritedPeriodOnlyContextIntent(
        ['status'=>'understood','requirements'=>[
            ['id'=>'r1','meaning'=>'查看昨天','fields'=>['periods'],'values'=>['periods'=>[['kind'=>'date_range','start'=>'2026-09-19','end'=>'2026-09-19']]],'evidence'=>[
                ['message_id'=>'current','quote'=>'昨天呢','start'=>0],
                ['message_id'=>'recent_1','quote'=>'今天劳动业绩第一名是谁','start'=>0],
            ]],
        ]],
        ['metric_codes'=>['staff_labor_yeji'],'query_shape'=>'ranking','ranking'=>['direction'=>'top','limit'=>1],
            'business_filters'=>['object_kind'=>'person','selection_ref'=>'role:craftsman']],
        ['presentation_origin'=>'customer_or_verified_context']
    );
    cdgCheck(is_array($periodWithPriorEvidence)
        &&($periodWithPriorEvidence['metric_codes']??null)===['staff_labor_yeji']
        &&($periodWithPriorEvidence['operation']??null)==='ranking',
        'a complete current period anchor may keep explanatory prior evidence without re-binding a signed ranking metric');
    $selfContainedOverviewAfterCollection=\app\services\ai\contract\AiIntentResultContract::inheritedPeriodOnlyContextIntent(
        ['status'=>'understood','requirements'=>[
            ['id'=>'r1','meaning'=>'查看今天业绩','fields'=>['metric_codes','periods'],'values'=>[
                'metric_terms'=>['业绩'],'periods'=>[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]]
            ],'evidence'=>[['message_id'=>'current','quote'=>'今天业绩怎么样','start'=>0]]],
        ]],
        ['metric_codes'=>['sales_amount'],'query_shape'=>'ranking','ranking'=>['direction'=>'top','limit'=>1],
            'business_filters'=>['object_kind'=>'project']],
        ['presentation_origin'=>'customer_or_verified_context']
    );
    cdgCheck($selfContainedOverviewAfterCollection===null,
        'a self-contained current topic is never admitted as a date-only continuation of a prior collection item');
    cdgCheck(\app\services\ai\contract\AiIntentUnderstandingContract::hasCurrentTopicAnchor([
        'requirements'=>[
            ['fields'=>['metric_codes','periods'],'evidence'=>[['message_id'=>'current','quote'=>'今天业绩怎么样']]],
        ],
    ])===true && \app\services\ai\contract\AiIntentUnderstandingContract::hasCurrentTopicAnchor([
        'requirements'=>[
            ['fields'=>['periods'],'evidence'=>[['message_id'=>'current','quote'=>'昨天呢']]],
        ],
    ])===false && \app\services\ai\contract\AiIntentUnderstandingContract::hasCurrentTopicAnchor([
        'requirements'=>[
            ['fields'=>['ranking'],'evidence'=>[['message_id'=>'current','quote'=>'改为前五个']]],
        ],
    ])===false,
        'typed current-topic admission distinguishes a new analytical request from a pure-date continuation without reading question words');

    // A signed query may bypass an unstable model response only for a closed
    // date grammar. The gateway must reject any added business instruction so
    // topic changes still go through ordinary natural-language understanding.
    $localPeriodHarness=new R6GatewayHarness(3,[1,2],'platform');
    $localPeriodMethod=new ReflectionMethod($localPeriodHarness->gateway,'localVerifiedPeriodOnlyUnderstanding');
    if (PHP_VERSION_ID<80100) $localPeriodMethod->setAccessible(true);
    $monthOnly=$localPeriodMethod->invoke($localPeriodHarness->gateway,
        ['question'=>'这个月呢？','evidence_messages'=>[['id'=>'current','text'=>'这个月呢？']]],
        ['local_conditions'=>[]],'2026-09-20'
    );
    cdgCheck(($monthOnly['requirements'][0]['fields']??null)===['periods']
        &&($monthOnly['requirements'][0]['values']['periods'][0]['start']??null)==='2026-09-01',
        'a closed calendar-only continuation derives one server-owned period without a model contract response');
    cdgCheck($localPeriodMethod->invoke($localPeriodHarness->gateway,
        ['question'=>'这个月销售额呢？','evidence_messages'=>[['id'=>'current','text'=>'这个月销售额呢？']]],
        ['local_conditions'=>[]],'2026-09-20'
    )===null,
        'a current business measurement cannot enter the local date-only continuation path');
    $metricOnlyContextMethod=new ReflectionMethod($localPeriodHarness->gateway,'preserveVerifiedMetricOnlyContext');
    if (PHP_VERSION_ID<80100) $metricOnlyContextMethod->setAccessible(true);
    $metricOnlyIntent=$metricOnlyContextMethod->invoke($localPeriodHarness->gateway,
        ['metric_codes'=>['service_count'],'needs_metric_choice'=>false,'context_delta'=>cdgDelta()],
        ['requirements'=>[['id'=>'r1','fields'=>['metric_codes'],'evidence'=>[['message_id'=>'current','quote'=>'服务次数']]]]],
        ['metric_codes'=>['staff_sales_yeji'],'query_shape'=>'ranking','start_date'=>'2026-09-01','end_date'=>'2026-09-20']
    );
    cdgCheck(($metricOnlyIntent['context_delta']['metric_codes']??null)==='replace'
        &&array_diff((array)($metricOnlyIntent['context_delta']??[]),['inherit','replace'])===[],
        'a bound metric-only follow-up replaces only its measurement and retains verified query context');
    cdgCheck($metricOnlyContextMethod->invoke($localPeriodHarness->gateway,
        ['metric_codes'=>['service_count'],'needs_metric_choice'=>false,'context_delta'=>cdgDelta()],
        ['requirements'=>[['id'=>'r1','fields'=>['metric_codes','ranking'],'evidence'=>[['message_id'=>'current','quote'=>'按服务次数前五名']]]]],
        ['metric_codes'=>['staff_sales_yeji'],'query_shape'=>'ranking','start_date'=>'2026-09-01','end_date'=>'2026-09-20']
    )['context_delta']===cdgDelta(),
        'an explicitly changed presentation remains model-owned rather than being overwritten as a metric-only delta');
    $projectionRecovered=$metricOnlyContextMethod->invoke($localPeriodHarness->gateway,
        ['metric_codes'=>['service_count'],'needs_metric_choice'=>false,'context_delta'=>cdgDelta()],
        ['requirements'=>[['id'=>'r1','fields'=>['metric_codes','object_kind','operation'],'evidence'=>[['message_id'=>'current','quote'=>'按服务次数看']]]]],
        ['metric_codes'=>['staff_sales_yeji'],'query_shape'=>'ranking','start_date'=>'2026-09-01','end_date'=>'2026-09-20'],true
    );
    cdgCheck(($projectionRecovered['context_delta']['ranking_limit']??null)==='inherit'
        &&($projectionRecovered['context_delta']['periods']??null)==='inherit',
        'a closed registered metric projection restores omitted signed date and ranking context when a model overstates inferred fields');
    $registeredMetricProjectionMethod=new ReflectionMethod($localPeriodHarness->gateway,'hasClosedRegisteredMetricOnlyProjection');
    if (PHP_VERSION_ID<80100) $registeredMetricProjectionMethod->setAccessible(true);
    cdgCheck($registeredMetricProjectionMethod->invoke($localPeriodHarness->gateway,['question'=>'按服务次数看呢？'])===true
        &&$registeredMetricProjectionMethod->invoke($localPeriodHarness->gateway,['question'=>'这个月按服务次数看呢？'])===false,
        'registered object-grain metric signals may retain context only when no current date or other semantic delta is present');
    $closedMetricUnderstandingMethod=new ReflectionMethod($localPeriodHarness->gateway,'reconcileClosedMetricOnlyUnderstanding');
    if (PHP_VERSION_ID<80100) $closedMetricUnderstandingMethod->setAccessible(true);
    $overstatedMetricUnderstanding=[
        'goal'=>'按服务次数继续查看上一排名','status'=>'understood','requirements'=>[[
            'id'=>'r1','meaning'=>'按服务次数查看','fields'=>['metric_codes','object_kind','operation','ranking'],
            'values'=>['metric_terms'=>['服务次数'],'object_kind'=>'person','operation'=>'ranking','ranking'=>['direction'=>'top','limit'=>null]],
            'evidence'=>[['message_id'=>'current','quote'=>'按服务次数看呢？','start'=>0]],
        ]],
    ];
    $closedMetricUnderstanding=$closedMetricUnderstandingMethod->invoke(
        $localPeriodHarness->gateway,$overstatedMetricUnderstanding,true,
        ['metric_codes'=>['staff_sales_yeji'],'query_shape'=>'ranking','start_date'=>'2026-09-01','end_date'=>'2026-09-20']
    );
    cdgCheck(($closedMetricUnderstanding['requirements'][0]['fields']??null)===['metric_codes']
        &&($closedMetricUnderstanding['requirements'][0]['values']??null)===['metric_terms'=>['服务次数']]
        &&!isset($closedMetricUnderstanding['groups']),
        'a registry-proven metric-only continuation removes model-copied ranking and object fields before binding validation');
    cdgCheck($closedMetricUnderstandingMethod->invoke(
        $localPeriodHarness->gateway,$overstatedMetricUnderstanding,false,
        ['metric_codes'=>['staff_sales_yeji']]
    )===$overstatedMetricUnderstanding,
        'a turn with any independent semantic delta keeps the complete model-owned understanding');
    $rankingOnlyUnderstanding=['status'=>'understood','requirements'=>[[
        'id'=>'r1','fields'=>['operation','ranking'],'values'=>[
            'operation'=>'ranking','ranking'=>['direction'=>'top','limit'=>2],
        ],'evidence'=>[['message_id'=>'current','quote'=>'前两名呢？']],
    ]]];
    $rankingSource=['query_shape'=>'ranking','metric_codes'=>['project_sales_amount'],
        'ranking'=>['direction'=>'top','limit'=>1],'business_filters'=>['object_kind'=>'project']];
    $rankingOnlyIntent=\app\services\ai\contract\AiIntentResultContract::inheritedRankingOnlyContextIntent(
        $rankingOnlyUnderstanding,$rankingSource,[]
    );
    cdgCheck(($rankingOnlyIntent['ranking']??null)===['direction'=>'top','limit'=>2]
        &&($rankingOnlyIntent['context_delta']['periods']??null)==='inherit'
        &&($rankingOnlyIntent['context_delta']['metric_codes']??null)==='inherit',
        'a ranking-only continuation changes presentation while retaining each signed collection query');
    $collectionRankingMethod=new ReflectionMethod($localPeriodHarness->gateway,'compileCollectionRankingContinuation');
    if (PHP_VERSION_ID<80100) $collectionRankingMethod->setAccessible(true);
    $collectionRankingPlan=$collectionRankingMethod->invoke($localPeriodHarness->gateway,$rankingOnlyUnderstanding,[
        ['id'=>'q1','label'=>'项目排行','query'=>$rankingSource+['start_date'=>'2026-09-01','end_date'=>'2026-09-20']],
        ['id'=>'q2','label'=>'产品排行','query'=>array_replace_recursive($rankingSource,[
            'metric_codes'=>['product_sales_amount'],'business_filters'=>['object_kind'=>'product'],
            'start_date'=>'2026-09-01','end_date'=>'2026-09-20',
        ])],
    ],[],'screen');
    cdgCheck(count($collectionRankingPlan['plan']['items']??[])===2
        &&($collectionRankingPlan['plan']['items'][0]['plan']['query']['ranking']['limit']??null)===2
        &&($collectionRankingPlan['plan']['items'][1]['plan']['query']['metric_codes']??null)===['product_sales_amount'],
        'a collection ranking continuation updates every item symmetrically without replacing its metric');
    $localPeriodHarness->close();

    $overviewHarness=new R6GatewayHarness(3,[1,2],'platform');
    $capabilitiesMethod=new ReflectionMethod($overviewHarness->gateway,'capabilities');
    if (PHP_VERSION_ID<80100) $capabilitiesMethod->setAccessible(true);
    $overviewRecoveryMethod=new ReflectionMethod($overviewHarness->gateway,'requiresOpenOverviewRecovery');
    if (PHP_VERSION_ID<80100) $overviewRecoveryMethod->setAccessible(true);
    $overviewCapabilities=$capabilitiesMethod->invoke($overviewHarness->gateway,$overviewHarness->context);
    $periodContinuationUnderstanding=['status'=>'understood','requirements'=>[
        ['id'=>'r1','meaning'=>'查看昨天','fields'=>['periods'],'values'=>['periods'=>[['kind'=>'date_range','start'=>'2026-09-19','end'=>'2026-09-19']]],'evidence'=>[['message_id'=>'current','quote'=>'昨天呢','start'=>0]]],
    ]];
    $inheritedPersonnelRanking=['object_kind'=>'person','operation'=>'ranking','metric_codes'=>['staff_labor_yeji'],
        'needs_metric_choice'=>false,'initial_observation'=>false];
    cdgCheck($overviewRecoveryMethod->invoke($overviewHarness->gateway,$periodContinuationUnderstanding,
        $inheritedPersonnelRanking,$overviewCapabilities,false)===true,
        'the regression fixture reproduces the former store-overview recovery collision');
    cdgCheck($overviewRecoveryMethod->invoke($overviewHarness->gateway,$periodContinuationUnderstanding,
        $inheritedPersonnelRanking,$overviewCapabilities,true)===false,
        'a verified context binding cannot be reinterpreted as a new store overview');
    $overviewHarness->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary',
        'metric_codes'=>['cash_performance','consume_amount'],'action_codes'=>[],'needs_metric_choice'=>false,
        'initial_observation'=>true,'ranking'=>['direction'=>'unspecified','limit'=>null],
        'periods'=>[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]],'scope'=>'authorized','unresolved_fragments'=>[]];
    $overviewSource=$overviewHarness->start('今天整体经营怎么样？');
    cdgCheck($overviewSource['status']==='COMPLETED'&&isset($overviewSource['answer']['context_ref']),
        'the platform observation source query is available for a date-only continuation');
    $overviewHarness->understandingOverride=['goal'=>'查看这个月','status'=>'understood','requirements'=>[
        ['id'=>'r1','meaning'=>'查看这个月','fields'=>['periods'],'values'=>['periods'=>[['kind'=>'month_offset','offset_months'=>0]]],'evidence'=>[['message_id'=>'current','quote'=>'这个月呢？']]],
    ]];
    $overviewQueries=$overviewHarness->queries;$overviewModels=$overviewHarness->models;
    $overviewFollow=$overviewHarness->start('这个月呢？',$overviewSource['answer']['context_ref']);
    $overviewEvidence=$overviewHarness->private->read($overviewHarness->row($overviewFollow)['evidence_ref']);
    cdgCheck($overviewFollow['status']==='COMPLETED'&&$overviewHarness->queries===$overviewQueries+1
        &&$overviewHarness->models===$overviewModels
        &&($overviewEvidence['query']['start_date']??'')===substr((string)($overviewEvidence['query']['end_date']??''),0,7).'-01',
        'an exact calendar-only continuation reuses a signed query and skips the binding model');
    $overviewHarness->close();

    $h=new R6GatewayHarness(3,[1,2],'platform');
    $initialIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary','metric_codes'=>['cash_performance'],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[['kind'=>'date_range','start'=>'2026-09-01','end'=>'2026-09-08']],'scope'=>'authorized','unresolved_fragments'=>[]];

    // A current period must never be labelled inherit and then execute the
    // signed single-day query. The first response is rejected before querying;
    // the explicit replacement executes the calendar-month request instead.
    $monthHarness=new R6GatewayHarness(3,[1,2],'platform');$monthHarness->semanticIntent=$initialIntent;
    $monthSource=$monthHarness->start('9月1日到9月8日现金业绩多少？');
    $wrongMonthDelta=cdgDelta();
    $monthHarness->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[['kind'=>'month_offset','offset_months'=>0]],'scope'=>'unspecified','context_delta'=>$wrongMonthDelta,'unresolved_fragments'=>[]];
    $queriesBeforeMonthFollow=$monthHarness->queries;$modelsBeforeMonthFollow=$monthHarness->models;
    $monthHarness->modelInputs=[];
    $oldConversation=[
        ['question'=>'昨天的历史提问','answer'=>[]],
        ['question'=>'更早的历史提问','answer'=>[]],
    ];
    $wrongMonthFollow=$monthHarness->start('这个月呢？',$monthSource['answer']['context_ref'],$oldConversation);
    $wrongMonthEvidence=$monthHarness->private->read($monthHarness->row($wrongMonthFollow)['evidence_ref']);
    cdgCheck($wrongMonthFollow['status']==='COMPLETED'&&$monthHarness->queries===$queriesBeforeMonthFollow+1
        &&($wrongMonthEvidence['query']['start_date']??'')===substr((string)($wrongMonthEvidence['query']['end_date']??''),0,7).'-01',
        'a typed period-only continuation reuses the signed binding instead of risking an inherited-date binding error');
    cdgCheck($monthHarness->models===$modelsBeforeMonthFollow && $monthHarness->modelInputs===[],
        'a closed date-only continuation reuses signed context without an avoidable model call');
    $correctMonthDelta=cdgDelta();$correctMonthDelta['periods']='replace';
    $monthHarness->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[['kind'=>'month_offset','offset_months'=>0]],'scope'=>'unspecified','context_delta'=>$correctMonthDelta,'unresolved_fragments'=>[]];
    $monthFollow=$monthHarness->start('这个月呢？',$monthSource['answer']['context_ref']);
    $monthEvidence=$monthHarness->private->read($monthHarness->row($monthFollow)['evidence_ref']);$monthQuery=$monthEvidence['query']??[];
    cdgCheck($monthFollow['status']==='COMPLETED'&&($monthQuery['start_date']??'')===substr((string)($monthQuery['end_date']??''),0,7).'-01'&&($monthQuery['end_date']??'')!=='2026-09-08','a confirmed current month executes its own period instead of the prior single range');
    $monthHarness->close();

    // The signed context stores an explicit Reader range, while the model
    // correctly retains the customer's calendar-month meaning. When both
    // materialize to the same server-reference period, inheriting it is
    // faithful and must not fail just because their JSON shapes differ.
    $sameMonthHarness=new R6GatewayHarness(3,[1,2],'platform');
    $clock=new DateTimeImmutable('now',new DateTimeZone('Asia/Shanghai'));
    $monthStart=$clock->format('Y-m-01');$monthEnd=$clock->format('Y-m-d');
    $sameMonthHarness->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary','metric_codes'=>['cash_performance'],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[['kind'=>'date_range','start'=>$monthStart,'end'=>$monthEnd]],'scope'=>'authorized','unresolved_fragments'=>[]];
    $sameMonthSource=$sameMonthHarness->start($monthStart.'到'.$monthEnd.'现金业绩多少？');
    $sameMonthHarness->understandingOverride=['goal'=>'继续查看本月','status'=>'understood','requirements'=>[
        ['id'=>'r1','meaning'=>'继续查看本月','fields'=>['periods'],'values'=>['periods'=>[['kind'=>'month_offset','offset_months'=>0]]],'evidence'=>[['message_id'=>'current','quote'=>'继续看本月']]],
    ]];
    $sameMonthHarness->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[['kind'=>'month_offset','offset_months'=>0]],'scope'=>'unspecified','context_delta'=>cdgDelta(),'unresolved_fragments'=>[]];
    $sameMonthFollow=$sameMonthHarness->start('继续看本月',$sameMonthSource['answer']['context_ref']);
    cdgCheck($sameMonthFollow['status']==='COMPLETED',
        'a semantically identical current-month continuation inherits the verified month-to-date range');
    $sameMonthHarness->close();

    // A direct “top five” continuation may replace the quantity while the
    // merger retains the signed direction. Keep it isolated from the larger
    // conversation fixture so its extra run cannot consume that fixture's
    // admission budget.
    $directHarness=new R6GatewayHarness(3,[1,2],'platform');
    $directHarness->semanticIntent=['object_kind'=>'member','object_term'=>'','operation'=>'ranking','metric_codes'=>['cash_performance'],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'top','limit'=>1],'periods'=>[['kind'=>'date_range','start'=>'2026-09-01','end'=>'2026-09-08']],'scope'=>'authorized','unresolved_fragments'=>[]];
    $directSource=$directHarness->start('会员现金业绩排行');
    cdgCheck($directSource['status']==='COMPLETED',
        'the direct member ranking source is available before the ranking-count follow-up');
    $directLimit=cdgDelta();$directLimit['ranking_limit']='replace';
    $directHarness->understandingOverride=['goal'=>'将排行改为前五个','status'=>'understood','requirements'=>[
        ['id'=>'r1','meaning'=>'查看前五个','fields'=>['ranking'],'values'=>['ranking'=>['direction'=>'top','limit'=>5]],'evidence'=>[['message_id'=>'current','quote'=>'前五个']]],
    ]];
    $directHarness->semanticIntent=['object_kind'=>'member','object_term'=>'','operation'=>'ranking','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>5],'periods'=>[],'scope'=>'unspecified','context_delta'=>$directLimit,'unresolved_fragments'=>[]];
    $directTopFive=$directHarness->start('改为前五个',$directSource['answer']['context_ref']);
    $directTopFiveEvidence=$directHarness->private->read($directHarness->row($directTopFive)['evidence_ref']);
    cdgCheck($directTopFive['status']==='COMPLETED'&&($directTopFiveEvidence['query']['ranking']??null)===['direction'=>'top','limit'=>5],'direct ranking-count follow-up retains the verified direction before execution');
    $directHarness->close();

    // A previously valid store ranking may carry a metric that cannot rank a
    // newly requested registered dimension. The follow-up must not reuse that
    // metric, invent a replacement, or collapse into a generic failed run.
    // It instead asks from the target dimension's registered metric catalogue.
    $dimensionHarness=new R6GatewayHarness(3,[1,2],'platform');
    $dimensionHarness->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'ranking','metric_codes'=>['cash_performance'],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'top','limit'=>1],'periods'=>[['kind'=>'date_range','start'=>'2026-09-01','end'=>'2026-09-08']],'scope'=>'authorized','unresolved_fragments'=>[]];
    $dimensionSource=$dimensionHarness->start('门店现金业绩排名');
    $dimensionHarness->understandingOverride=['goal'=>'改看产品','status'=>'understood','requirements'=>[
        ['id'=>'r1','meaning'=>'比较产品','fields'=>['object_kind'],'values'=>['object_kind'=>'product'],'evidence'=>[['message_id'=>'current','quote'=>'产品']]],
    ]];
    $dimensionDelta=cdgDelta();$dimensionDelta['object']='replace';
    $dimensionHarness->semanticIntent=['object_kind'=>'product','object_term'=>'','operation'=>'unknown','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$dimensionDelta,'unresolved_fragments'=>[]];
    $dimensionQueries=$dimensionHarness->queries;
    $dimensionFollow=$dimensionHarness->start('产品呢',$dimensionSource['answer']['context_ref']);
    $dimensionOptions=array_column($dimensionFollow['clarification']['fields'][0]['options']??[],'value');
    cdgCheck($dimensionFollow['status']==='WAITING_CLARIFICATION'
        &&($dimensionFollow['clarification']['fields'][0]['key']??null)==='dimension_metric'
        &&$dimensionOptions===['sales_amount','sales_quantity']
        &&$dimensionHarness->queries===$dimensionQueries,
        'an incompatible inherited metric becomes a target-dimension metric choice without another query');
    $dimensionCompleted=$dimensionHarness->choose($dimensionFollow,['dimension_metric'=>'sales_amount']);
    $dimensionEvidence=$dimensionHarness->private->read($dimensionHarness->row($dimensionCompleted)['evidence_ref']);
    cdgCheck($dimensionCompleted['status']==='COMPLETED'
        &&($dimensionEvidence['query']['metric_codes']??null)===['sales_amount']
        &&($dimensionEvidence['query']['business_filters']??null)===['object_kind'=>'product']
        &&($dimensionEvidence['query']['ranking']??null)===['direction'=>'top','limit'=>1],
        'the customer-selected registered product metric retains the signed ranking context and executes');
    $dimensionHarness->close();

    $h->semanticIntent=$initialIntent;
    $source=$h->start('9月1日到9月8日现金业绩多少？');
    cdgCheck($source['status']==='COMPLETED'&&isset($source['answer']['context_ref']),'verified source query is available');

    // The two model stages are intentionally injected independently here.
    // A binding may not turn a customer exclusion into the excluded registered
    // metric, even when that metric is otherwise executable for this account.
    $contractHarness=new R6GatewayHarness(3,[1,2],'platform');
    $contractHarness->semanticIntent=$initialIntent;
    $contractSource=$contractHarness->start('9月1日到9月8日现金业绩多少？');
    cdgCheck($contractSource['status']==='COMPLETED','independent source query is available for semantic contract checks');
    $contractHarness->understandingOverride=['goal'=>'查看收款但不要退款','status'=>'understood','requirements'=>[
        ['id'=>'r1','meaning'=>'查看收款','fields'=>['metric_codes'],'values'=>['metric_terms'=>['收款']],'evidence'=>[['message_id'=>'current','quote'=>'收款']]],
        ['id'=>'r2','meaning'=>'不要退款','fields'=>['metric_codes'],'values'=>['metric_exclusions'=>['退款']],'evidence'=>[['message_id'=>'current','quote'=>'不要退款']]],
        ['id'=>'r3','meaning'=>'9月1日到9月8日','fields'=>['periods'],'values'=>['periods'=>[['kind'=>'date_range','start'=>'2026-09-01','end'=>'2026-09-08']]],'evidence'=>[['message_id'=>'current','quote'=>'9月1日到9月8日']]],
    ]];
    $contractHarness->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary','metric_codes'=>['refund_performance'],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[['kind'=>'date_range','start'=>'2026-09-01','end'=>'2026-09-08']],'scope'=>'authorized','requirement_bindings'=>[['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['refund_performance']],['requirement_id'=>'r2','status'=>'unavailable','metric_codes'=>[]]],'unresolved_fragments'=>[]];
    $beforeExcludedMetric=$contractHarness->queries;
    $excludedMetric=$contractHarness->start('9月1日到9月8日收款，不要退款');
    cdgCheck($excludedMetric['status']==='FAILED'&&($excludedMetric['reason']??null)==='AI_MODEL_INTENT_CONTRACT_INVALID'&&$contractHarness->queries===$beforeExcludedMetric,'an explicitly excluded registered metric never reaches query execution');

    // Structural coverage is not a semantic proof. Even when a binding falsely
    // labels every requirement as satisfied, an independent review must block
    // it before the Reader receives the selected refund metric.
    $contractHarness->semanticIntent['requirement_bindings']=[['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['refund_performance']],['requirement_id'=>'r2','status'=>'satisfied','metric_codes'=>['refund_performance']]];
    $contractHarness->bindingVerificationOverride=['decision'=>'reject','rejected_requirement_ids'=>['r2']];
    $beforeSemanticRejection=$contractHarness->queries;
    $semanticRejection=$contractHarness->start('9月1日到9月8日收款，不要退款');
    cdgCheck($semanticRejection['status']==='FAILED'&&($semanticRejection['reason']??null)==='AI_BINDING_SEMANTIC_REJECTED'&&$contractHarness->queries===$beforeSemanticRejection,'independent review blocks a false satisfied binding before query execution');
    $contractHarness->bindingVerificationOverride=null;

    // A pending presentation choice must not move semantic admission behind
    // the private fallback. Otherwise “不要退款” could be replaced with the
    // prior/refund query when the customer simply chooses how to display it.
    $pendingReviewHarness=new R6GatewayHarness(3,[1,2],'platform');
    $pendingReviewHarness->semanticIntent=$initialIntent;
    $pendingReviewSource=$pendingReviewHarness->start('9月1日到9月8日现金业绩多少？');
    $pendingReviewHarness->understandingOverride=['goal'=>'查看收款但不要退款','status'=>'understood','requirements'=>[
        ['id'=>'r1','meaning'=>'查看收款','fields'=>['metric_codes'],'values'=>['metric_terms'=>['收款']],'evidence'=>[['message_id'=>'current','quote'=>'收款']]],
        ['id'=>'r2','meaning'=>'不要退款','fields'=>['metric_codes'],'values'=>['metric_exclusions'=>['退款']],'evidence'=>[['message_id'=>'current','quote'=>'不要退款']]],
    ]];
    $pendingReviewDelta=cdgDelta();$pendingReviewDelta['metric_codes']='replace';$pendingReviewDelta['operation']='pending';
    $pendingReviewHarness->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'unknown','metric_codes'=>['refund_performance'],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$pendingReviewDelta,
        'requirement_bindings'=>[['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['refund_performance']],['requirement_id'=>'r2','status'=>'satisfied','metric_codes'=>['refund_performance']]],'unresolved_fragments'=>[]];
    $pendingReviewHarness->bindingVerificationOverride=['decision'=>'reject','rejected_requirement_ids'=>['r2']];
    $beforePendingReview=$pendingReviewHarness->queries;
    $pendingReview=$pendingReviewHarness->start('收款，不要退款',$pendingReviewSource['answer']['context_ref']);
    cdgCheck($pendingReview['status']==='FAILED'&&($pendingReview['reason']??null)==='AI_BINDING_SEMANTIC_REJECTED'&&$pendingReviewHarness->queries===$beforePendingReview,'pending presentation guidance cannot bypass semantic review of a newly selected metric');
    $pendingReviewHarness->close();

    // Metric clarification is an execution boundary, not merely a display
    // choice. A reviewer must see the metric selected by the customer before
    // the signed fallback can reach the Reader.
    $lateMetricReviewHarness=new R6GatewayHarness(3,[1,2],'platform');
    $lateMetricReviewHarness->semanticIntent=$initialIntent;
    $lateMetricSource=$lateMetricReviewHarness->start('9月1日到9月8日现金业绩多少？');
    $lateMetricReviewHarness->understandingOverride=['goal'=>'查看收款但不要退款','status'=>'understood','requirements'=>[
        ['id'=>'r1','meaning'=>'查看收款','fields'=>['metric_codes'],'values'=>['metric_terms'=>['收款']],'evidence'=>[['message_id'=>'current','quote'=>'收款']]],
        ['id'=>'r2','meaning'=>'不要退款','fields'=>['metric_codes'],'values'=>['metric_exclusions'=>['退款']],'evidence'=>[['message_id'=>'current','quote'=>'不要退款']]],
    ]];
    $lateMetricDelta=cdgDelta();$lateMetricDelta['metric_codes']='pending';
    $lateMetricReviewHarness->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>true,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$lateMetricDelta,'unresolved_fragments'=>[]];
    $lateMetricReviewHarness->bindingVerificationOverride=['decision'=>'reject','rejected_requirement_ids'=>['r2']];
    $lateMetricPending=$lateMetricReviewHarness->start('收款，不要退款',$lateMetricSource['answer']['context_ref']);
    $beforeLateMetric=$lateMetricReviewHarness->queries;
    $lateMetricFinal=$lateMetricReviewHarness->choose($lateMetricPending,['pending_metric_codes'=>'retain']);
    cdgCheck($lateMetricPending['status']==='WAITING_CLARIFICATION'&&$lateMetricFinal['status']==='FAILED'
        &&($lateMetricFinal['reason']??null)==='AI_BINDING_SEMANTIC_REJECTED'&&$lateMetricReviewHarness->queries===$beforeLateMetric,
        'a final retained metric is independently reviewed against the original exclusion');
    $lateMetricReviewHarness->close();

    // Definition reads have no data-query payload, but selecting a definition
    // metric is still a real, reviewed binding. It must reach the registered
    // metadata workflow instead of being mislabeled as stale guidance.
    $definitionHarness=new R6GatewayHarness(3,[1,2],'platform');
    $definitionHarness->understandingOverride=['goal'=>'解释业绩口径','status'=>'understood','requirements'=>[
        ['id'=>'r1','meaning'=>'了解业绩的定义','fields'=>['metric_codes','operation'],'values'=>['metric_terms'=>['业绩'],'operation'=>'definition'],'evidence'=>[['message_id'=>'current','quote'=>'业绩怎么算']]],
    ]];
    $definitionHarness->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'definition','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>true,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'authorized','unresolved_fragments'=>[]];
    $definitionPending=$definitionHarness->start('业绩怎么算');
    $definitionFinal=$definitionHarness->choose($definitionPending,['metric_code'=>'cash_performance']);
    cdgCheck($definitionPending['status']==='WAITING_CLARIFICATION'&&$definitionFinal['status']==='COMPLETED','a reviewed definition metric choice reaches the registered metadata workflow');
    $definitionHarness->close();

    // The same wording can bind cash performance when the binding stage
    // explicitly accounts for both the requested result and the exclusion.
    $contractHarness->semanticIntent['metric_codes']=['cash_performance'];
    $contractHarness->semanticIntent['requirement_bindings']=[['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['cash_performance']],['requirement_id'=>'r2','status'=>'satisfied','metric_codes'=>['cash_performance']]];
    $includedCash=$contractHarness->start('9月1日到9月8日收款，不要退款');
    cdgCheck($includedCash['status']==='COMPLETED','a complete semantic binding accepts a natural-language exclusion without phrase matching');

    // An understood but currently unbound follow-up may not inherit a prior
    // metric and silently answer the earlier, broader question.
    $contractHarness->understandingOverride=['goal'=>'仅统计周末','status'=>'understood','requirements'=>[
        ['id'=>'r1','meaning'=>'仅统计周末，当前没有对应筛选能力','fields'=>['unbound'],'values'=>[],'evidence'=>[['message_id'=>'current','quote'=>'只看周末']]],
    ]];
    $unboundDelta=cdgDelta();
    $contractHarness->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$unboundDelta,'requirement_bindings'=>[],'unresolved_fragments'=>[]];
    $beforeUnboundFollowup=$contractHarness->queries;
    $unboundFollowup=$contractHarness->start('那只看周末',$contractSource['answer']['context_ref']);
    cdgCheck($unboundFollowup['status']==='FAILED'&&($unboundFollowup['reason']??null)==='AI_ANALYSIS_COMBINATION_UNAVAILABLE'&&$contractHarness->queries===$beforeUnboundFollowup,'an understood but unbound condition reports a capability gap instead of retrying or inheriting a broader query');

    // A pending metric choice is not allowed to postpone that same capability
    // decision.  Otherwise selecting “retain” would execute the old metric
    // while silently dropping the understood weekend-only condition.
    $unboundPendingDelta=cdgDelta();$unboundPendingDelta['metric_codes']='pending';
    $contractHarness->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>true,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$unboundPendingDelta,'requirement_bindings'=>[],'unresolved_fragments'=>[]];
    $beforeUnboundPending=$contractHarness->queries;
    $unboundPending=$contractHarness->start('只看周末，指标由您选择',$contractSource['answer']['context_ref']);
    cdgCheck($unboundPending['status']==='FAILED'&&($unboundPending['reason']??null)==='AI_ANALYSIS_COMBINATION_UNAVAILABLE'&&$contractHarness->queries===$beforeUnboundPending,'an unbound condition cannot be hidden behind a pending metric choice');

    // “Unbound” has one precise meaning: the request is clear but unsupported.
    // A model must represent genuine ambiguity as needs_clarification instead,
    // so an invalid mixed response cannot later pass a clarification boundary.
    $invalidUnderstandingHarness=new R6GatewayHarness(3,[1,2],'platform');$invalidUnderstandingHarness->semanticIntent=$initialIntent;
    $invalidUnderstandingSource=$invalidUnderstandingHarness->start('9月1日到9月8日现金业绩多少？');
    $invalidUnderstandingHarness->understandingOverride=['goal'=>'范围未确定','status'=>'needs_clarification','requirements'=>[
        ['id'=>'r1','meaning'=>'范围未确定','fields'=>['unbound'],'values'=>[],'evidence'=>[['message_id'=>'current','quote'=>'范围未确定']]],
    ]];
    $invalidUnderstandingDelta=cdgDelta();$invalidUnderstandingDelta['store_scope']='pending';
    $invalidUnderstandingHarness->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$invalidUnderstandingDelta,'requirement_bindings'=>[],'unresolved_fragments'=>[]];
    $beforeInvalidUnderstanding=$invalidUnderstandingHarness->queries;
    $invalidUnderstanding=$invalidUnderstandingHarness->start('范围未确定',$invalidUnderstandingSource['answer']['context_ref']);
    cdgCheck($invalidUnderstanding['status']==='FAILED'&&($invalidUnderstanding['reason']??null)==='AI_MODEL_INTENT_CONTRACT_INVALID'&&$invalidUnderstandingHarness->queries===$beforeInvalidUnderstanding,'ambiguous understanding cannot misuse the unbound capability marker');
    $invalidUnderstandingHarness->close();

    // A pending response form cannot replace a typed natural-language period.
    // The accepted month is carried through the binding boundary, while only
    // the genuinely pending presentation decision is shown to the customer.
    $contractHarness->understandingOverride=['goal'=>'改查这个月','status'=>'understood','requirements'=>[
        ['id'=>'r1','meaning'=>'改查这个月','fields'=>['periods'],'values'=>['periods'=>[['kind'=>'month_offset','offset_months'=>0]]],'evidence'=>[['message_id'=>'current','quote'=>'这个月呢？']]],
    ]];
    $pendingMismatch=cdgDelta();$pendingMismatch['periods']='replace';$pendingMismatch['operation']='pending';
    $contractHarness->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'unknown','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[['kind'=>'date_range','start'=>'2026-09-10','end'=>'2026-09-10']],'scope'=>'unspecified','context_delta'=>$pendingMismatch,'unresolved_fragments'=>[]];
    $beforePendingMismatch=$contractHarness->queries;
    $pendingMismatchRun=$contractHarness->start('这个月呢？',$contractSource['answer']['context_ref']);
    $resolvedEvidence=$contractHarness->private->read($contractHarness->row($pendingMismatchRun)['evidence_ref']);
    cdgCheck($pendingMismatchRun['status']==='COMPLETED'&&$contractHarness->queries===$beforePendingMismatch+1
        && ($resolvedEvidence['query']['start_date']??'')===substr((string)($resolvedEvidence['query']['end_date']??''),0,7).'-01',
        'a period-only follow-up ignores an unrelated pending binding marker and executes the customer-stated month');
    $contractHarness->close();

    // A model-declared missing metric may not execute an empty query.  It
    // first asks whether the verified previous metric should be retained.
    $pendingDelta=cdgDelta();$pendingDelta['metric_codes']='pending';
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>true,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$pendingDelta,'unresolved_fragments'=>[]];
    $pending=$h->start('那这一段呢？',$source['answer']['context_ref']);
    cdgCheck($pending['status']==='WAITING_CLARIFICATION'&&$pending['clarification']['fields'][0]['key']==='pending_metric_codes','pending metric is held for an explicit context decision instead of executing or failing');
    $retained=$h->choose($pending,['pending_metric_codes'=>'retain']);
    cdgCheck($retained['status']==='COMPLETED','only an explicit retain decision may execute the prior verified metric');

    // Replacing the scope uses the verbatim current store name only to bind
    // an already-authorized catalog entry; it must not fall back to a choice.
    $storeDelta=cdgDelta();$storeDelta['store_scope']='replace';
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'二号门店','operation'=>'summary','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$storeDelta,'unresolved_fragments'=>[]];
    $switched=$h->start('改查二号门店，其他条件不变',$source['answer']['context_ref']);
    cdgCheck($switched['status']==='COMPLETED','a verbatim authorized replacement store binds without redundant store selection');
    $evidence=$h->private->read($h->row($switched)['evidence_ref']);
    cdgCheck(($evidence['query']['store_ids']??null)===[2],'replacement store plan contains only the authorized chosen store');

    // A missing store scope must retain the signed store restriction until the
    // customer chooses otherwise; it may never be compiled as all stores.
    $scopePending=cdgDelta();$scopePending['store_scope']='pending';
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$scopePending,'unresolved_fragments'=>[]];
    $heldScope=$h->start('那这个门店范围呢？',$switched['answer']['context_ref']);
    cdgCheck($heldScope['status']==='WAITING_CLARIFICATION'&&$heldScope['clarification']['fields'][0]['key']==='pending_store_scope','pending store scope cannot execute with an empty store list');
    $retainedScope=$h->choose($heldScope,['pending_store_scope'=>'retain']);
    $scopeEvidence=$h->private->read($h->row($retainedScope)['evidence_ref']);
    cdgCheck($retainedScope['status']==='COMPLETED'&&($scopeEvidence['query']['store_ids']??null)===[2],'retained store scope preserves the signed narrowed range');
    $heldClear=$h->start('改为全部范围',$switched['answer']['context_ref']);
    cdgCheck($heldClear['status']==='WAITING_CLARIFICATION','a second pending scope awaits a direct clear decision');
    $clearedScope=$h->choose($heldClear,['pending_store_scope'=>'clear_store_scope']);
    $clearEvidence=$h->private->read($h->row($clearedScope)['evidence_ref']);
    cdgCheck($clearedScope['status']==='COMPLETED'&&($clearEvidence['query']['store_ids']??null)===[],'explicitly clearing scope removes the previous store restriction from the executed query');

    // A missing response form is equally a clarification, rather than an
    // AI_INTENT_UNRESOLVED failure or a guessed summary.
    $operationPending=cdgDelta();$operationPending['operation']='pending';
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'unknown','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$operationPending,'unresolved_fragments'=>[]];
    $heldOperation=$h->start('展示方式还没确定',$switched['answer']['context_ref']);
    cdgCheck($heldOperation['status']==='WAITING_CLARIFICATION'&&$heldOperation['clarification']['fields'][0]['key']==='pending_operation','pending operation is a controlled decision, not a failed Run');
    cdgCheck($h->choose($heldOperation,['pending_operation'=>'retain'])['status']==='COMPLETED','retained response form completes only after confirmation');

    // Pending fields form one serial decision chain. Choosing the metric must
    // not release an unresolved period or execute the first preview plan.
    $multiDelta=cdgDelta();$multiDelta['metric_codes']='pending';$multiDelta['periods']='pending';
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>true,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$multiDelta,'unresolved_fragments'=>[]];
    $multi=$h->start('换一个指标和时间',$source['answer']['context_ref']);
    cdgCheck($multi['status']==='WAITING_CLARIFICATION'&&$multi['clarification']['fields'][0]['key']==='pending_metric_codes','first unresolved context field is presented');
    $multi=$h->choose($multi,['pending_metric_codes'=>'metric:consume_amount']);
    cdgCheck($multi['status']==='WAITING_CLARIFICATION'&&$multi['clarification']['fields'][0]['key']==='start_date','second unresolved context field remains required after metric selection');
    $multi=$h->choose($multi,['start_date'=>'2026-09-03','end_date'=>'2026-09-04']);
    $multiEvidence=$h->private->read($h->row($multi)['evidence_ref']);
    cdgCheck($multi['status']==='COMPLETED'&&($multiEvidence['query']['metric_codes']??null)===['consume_amount']&&($multiEvidence['query']['start_date']??null)==='2026-09-03','only the fully confirmed context reaches execution');

    // Removing a restriction from a member ranking must keep its member
    // dimension. It may never silently change the answer into a store ranking.
    $h->semanticIntent=['object_kind'=>'member','object_term'=>'','operation'=>'ranking','metric_codes'=>['cash_performance'],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'top','limit'=>5],'periods'=>[['kind'=>'date_range','start'=>'2026-09-01','end'=>'2026-09-08']],'scope'=>'authorized','unresolved_fragments'=>[]];
    $memberSource=$h->start('会员现金业绩排行');
    cdgCheck($memberSource['status']==='COMPLETED','member source ranking is available');

    // Operation choices come from the registered member/metric contract. A
    // member cash ranking must not offer a summary that will fail downstream.
    $memberOperationPending=cdgDelta();$memberOperationPending['operation']='pending';
    $h->semanticIntent=['object_kind'=>'member','object_term'=>'','operation'=>'unknown','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$memberOperationPending,'unresolved_fragments'=>[]];
    $memberOperation=$h->start('展示方式未确定',$memberSource['answer']['context_ref']);
    cdgCheck(array_column($memberOperation['clarification']['fields'][0]['options']??[],'value')===['retain','operation:ranking'],'operation guidance lists only shapes registered for the selected member metric');
    $forgedOperation=$h->choose($memberOperation,['pending_operation'=>'operation:summary']);
    cdgCheck($forgedOperation['status']==='WAITING_CLARIFICATION'&&($forgedOperation['clarification']['fields'][0]['key']??null)==='pending_operation','a forged unavailable operation is rejected at the clarification boundary');
    cdgCheck($h->choose($memberOperation,['pending_operation'=>'retain'])['status']==='COMPLETED','the supported prior member ranking remains executable after confirmation');

    // Metric choices are projected from the same object/shape contract as the
    // compiler. The UI must never offer a metric that fails immediately after
    // the customer selects it.
    $memberMetricPending=cdgDelta();$memberMetricPending['metric_codes']='pending';
    $h->semanticIntent=['object_kind'=>'member','object_term'=>'','operation'=>'ranking','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>true,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$memberMetricPending,'unresolved_fragments'=>[]];
    $memberMetric=$h->start('换一个评价指标',$memberSource['answer']['context_ref']);
    $memberMetricValues=array_column($memberMetric['clarification']['fields'][0]['options']??[],'value');
    cdgCheck($memberMetricValues===['retain','metric:cash_performance','metric:sales_collected_amount'],'member ranking metric choices contain only registered executable contracts');
    cdgCheck($h->choose($memberMetric,['pending_metric_codes'=>'metric:sales_amount'])['status']==='WAITING_CLARIFICATION','a forged incompatible metric cannot pass the clarification boundary');
    cdgCheck($h->choose($memberMetric,['pending_metric_codes'=>'retain'])['status']==='COMPLETED','every displayed member metric choice remains executable');

    $filterPending=cdgDelta();$filterPending['business_filters']='pending';
    $h->semanticIntent=['object_kind'=>'member','object_term'=>'','operation'=>'ranking','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$filterPending,'unresolved_fragments'=>[]];
    $memberFollow=$h->start('不沿用刚才的对象筛选',$memberSource['answer']['context_ref']);
    cdgCheck(in_array('clear_business_filter',array_column($memberFollow['clarification']['fields'][0]['options']??[],'value'),true),'only an option actually rendered to the customer may clear a member restriction');
    $memberFollow=$h->choose($memberFollow,['pending_business_filters'=>'clear_business_filter']);
    $memberEvidence=$h->private->read($h->row($memberFollow)['evidence_ref']);
    cdgCheck($memberFollow['status']==='COMPLETED'&&($memberEvidence['query']['business_filters']??null)===['object_kind'=>'member'],'clearing a member restriction retains the member query object');

    // Choosing comparison is incomplete until a second time range is supplied.
    $comparePending=cdgDelta();$comparePending['operation']='pending';
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'unknown','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$comparePending,'unresolved_fragments'=>[]];
    $compare=$h->start('改为对比',$source['answer']['context_ref']);
    $compare=$h->choose($compare,['pending_operation'=>'operation:comparison']);
    cdgCheck(($compare['clarification']['fields'][0]['key']??null)==='compare_start_date','comparison asks for the comparison period instead of executing an incomplete plan');
    $compare=$h->choose($compare,['compare_start_date'=>'2026-08-20','compare_end_date'=>'2026-08-21']);
    $compareEvidence=$h->private->read($h->row($compare)['evidence_ref']);
    cdgCheck($compare['status']==='COMPLETED'&&($compareEvidence['query']['compare_range']??null)===['start'=>'2026-08-20','end'=>'2026-08-21'],'comparison executes only after its second period is confirmed');

    // A new dimension plus an unfinished response form is a three-step
    // conversation, not an early "combination unavailable" failure.
    $replacePending=cdgDelta();$replacePending['object']='replace';$replacePending['operation']='pending';
    $h->semanticIntent=['object_kind'=>'member','object_term'=>'','operation'=>'unknown','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$replacePending,'unresolved_fragments'=>[]];
    $replacement=$h->start('改查看会员，但展示方式未确定',$source['answer']['context_ref']);
    cdgCheck($replacement['status']==='WAITING_CLARIFICATION'&&($replacement['clarification']['fields'][0]['key']??null)==='pending_operation','an object replacement after an unrestricted aggregate asks only for the missing response form');
    cdgCheck(array_column($replacement['clarification']['fields'][0]['options']??[],'value')===['operation:ranking'],'object replacement regenerates response choices from the new member contract and cannot retain an unsupported store summary');
    $replacement=$h->choose($replacement,['pending_operation'=>'operation:ranking']);
    $replacement=$h->choose($replacement,['pending_ranking_direction'=>'top','pending_ranking_limit'=>'5']);
    $replacementEvidence=$h->private->read($h->row($replacement)['evidence_ref']);
    cdgCheck($replacement['status']==='COMPLETED'&&($replacementEvidence['query']['business_filters']??null)===['object_kind'=>'member'],'replacement plus pending form recompiles a member ranking after all confirmations');

    // Moving from a member dimension to stores replaces an analytical
    // dimension, not a named member selected by the customer. It should
    // execute directly while still removing the old dimension from the query.
    $toStore=cdgDelta();$toStore['object']='replace';
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'ranking','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'top','limit'=>5],'periods'=>[],'scope'=>'unspecified','context_delta'=>$toStore,'unresolved_fragments'=>[]];
    $storeReplacement=$h->start('改查看门店排行',$memberSource['answer']['context_ref']);
    $storeReplacementEvidence=$h->private->read($h->row($storeReplacement)['evidence_ref']);
    cdgCheck($storeReplacement['status']==='COMPLETED'&&($storeReplacementEvidence['query']['business_filters']??null)===[],'dimension replacement from member to store executes without an unnecessary confirmation');

    // A single pending ranking field replaces just that field. The unchanged
    // direction/quantity remain from the signed query.
    $limitPending=cdgDelta();$limitPending['ranking_limit']='pending';
    $h->semanticIntent=['object_kind'=>'member','object_term'=>'','operation'=>'ranking','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$limitPending,'unresolved_fragments'=>[]];
    $newLimit=$h->start('改成前五个',$memberSource['answer']['context_ref']);
    $newLimit=$h->choose($newLimit,['pending_ranking_limit'=>'ranking_limit:5']);
    $newLimitEvidence=$h->private->read($h->row($newLimit)['evidence_ref']);
    cdgCheck($newLimit['status']==='COMPLETED'&&($newLimitEvidence['query']['ranking']??null)===['direction'=>'top','limit'=>5],'confirmed ranking quantity is not overwritten by an empty model delta');

    $directionPending=cdgDelta();$directionPending['ranking_direction']='pending';
    $h->semanticIntent=['object_kind'=>'member','object_term'=>'','operation'=>'ranking','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$directionPending,'unresolved_fragments'=>[]];
    $newDirection=$h->start('改为从低到高',$memberSource['answer']['context_ref']);
    $newDirection=$h->choose($newDirection,['pending_ranking_direction'=>'ranking_direction:bottom']);
    $newDirectionEvidence=$h->private->read($h->row($newDirection)['evidence_ref']);
    cdgCheck($newDirection['status']==='COMPLETED'&&($newDirectionEvidence['query']['ranking']??null)===['direction'=>'bottom','limit'=>5],'confirmed ranking direction is not overwritten by an empty model delta');

    // A direct, confirmed dimension replacement reaches the registered target
    // dimension after confirmation; it does not retain the prior store plan.
    $memberReplace=cdgDelta();$memberReplace['object']='replace';$memberReplace['operation']='replace';
    $h->semanticIntent=['object_kind'=>'member','object_term'=>'','operation'=>'ranking','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$memberReplace,'unresolved_fragments'=>[]];
    $memberReplacement=$h->start('改查看会员排行',$source['answer']['context_ref']);
    $memberReplacement=$h->choose($memberReplacement,['replace_previous_object_filter'=>'replace']);
    cdgCheck(($memberReplacement['clarification']['fields'][0]['key']??null)==='dimension_direction','a replacement query asks only for the target ranking details that the user did not state');
    $directionEnvelope=$h->private->read($h->row($memberReplacement)['clarification_ref']);
    cdgCheck(is_array($directionEnvelope['envelope']['_semantic_context']??null),'semantic context survives replacement into dimension guidance');
    cdgCheck(!array_key_exists('_semantic_context',$memberReplacement['clarification']??[]),'private semantic context is never projected to the browser');
    $memberReplacement=$h->choose($memberReplacement,['dimension_direction'=>'top']);
    cdgCheck(($memberReplacement['clarification']['fields'][0]['key']??null)==='dimension_limit','target ranking quantity remains an explicit choice when it was not stated');
    $limitEnvelope=$h->private->read($h->row($memberReplacement)['clarification_ref']);
    cdgCheck(is_array($limitEnvelope['envelope']['_semantic_context']??null),'semantic context survives every subsequent dimension guidance step');
    $memberReplacement=$h->choose($memberReplacement,['dimension_limit'=>'5']);
    $memberReplacementEvidence=$h->private->read($h->row($memberReplacement)['evidence_ref']);
    cdgCheck($memberReplacement['status']==='COMPLETED'&&($memberReplacementEvidence['query']['business_filters']??null)===['object_kind'=>'member'],'confirmed store-to-member replacement executes a member query');

    // Changing a comparison's main period keeps its independently confirmed
    // comparison period. Changing away from comparison clears it.
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'comparison','metric_codes'=>['cash_performance'],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[['kind'=>'date_range','start'=>'2026-09-01','end'=>'2026-09-08'],['kind'=>'date_range','start'=>'2026-08-20','end'=>'2026-08-27']],'scope'=>'authorized','unresolved_fragments'=>[]];
    $comparisonSource=$h->start('现金业绩对比');
    cdgCheck($comparisonSource['status']==='COMPLETED','comparison source query is available');
    $comparisonPeriod=cdgDelta();$comparisonPeriod['periods']='pending';
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'comparison','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$comparisonPeriod,'unresolved_fragments'=>[]];
    $changedPeriod=$h->start('换统计时间',$comparisonSource['answer']['context_ref']);
    $changedPeriod=$h->choose($changedPeriod,['start_date'=>'2026-09-03','end_date'=>'2026-09-05']);
    $changedPeriodEvidence=$h->private->read($h->row($changedPeriod)['evidence_ref']);
    cdgCheck($changedPeriod['status']==='COMPLETED'&&($changedPeriodEvidence['query']['compare_range']??null)===['start'=>'2026-08-20','end'=>'2026-08-27'],'changing a comparison period retains its confirmed comparison range');

    $comparisonOperation=cdgDelta();$comparisonOperation['operation']='pending';
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'unknown','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$comparisonOperation,'unresolved_fragments'=>[]];
    $changedOperation=$h->start('改为汇总',$comparisonSource['answer']['context_ref']);
    $changedOperation=$h->choose($changedOperation,['pending_operation'=>'operation:summary']);
    $changedOperationEvidence=$h->private->read($h->row($changedOperation)['evidence_ref']);
    cdgCheck($changedOperation['status']==='COMPLETED'&&array_key_exists('compare_range',$changedOperationEvidence['query']??[])&&$changedOperationEvidence['query']['compare_range']===null,'changing from comparison clears the obsolete comparison range');
    echo "PASS context delta gateway: $checks checks (SQLite, synthetic model/facts only)\n";
} finally {if($h)$h->close();}
