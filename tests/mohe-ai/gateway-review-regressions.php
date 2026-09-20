<?php
// Read-only deterministic review regressions: no app boot, database, model or network.
$base=dirname(__DIR__,2).'/后端代码/app/services/';
foreach (['BaseServices.php','metric/MetricDictionaryServices.php','query/metric/MetricMoneyFormatter.php','query/metric/MetricSemanticCatalog.php','query/metric/MetricDefinitionRegistry.php','query/metric/MetricReadViewServices.php','ai/contract/AiContractException.php','ai/contract/AiIntentUnderstandingContract.php','ai/contract/AiIntentResultContract.php',
    'ai/model/AiModelInputProjector.php','ai/execution/AiWorkflowPlanner.php','ai/presentation/AiAnswerRenderer.php','ai/AiGatewayServices.php'] as $file) require_once $base.$file;
$passed=0; $failed=[];
$check=function($ok,$label) use (&$passed,&$failed) {if ($ok) ++$passed; else $failed[]=$label;};
$projector=new app\services\ai\model\AiModelInputProjector();
$planner=new app\services\ai\execution\AiWorkflowPlanner();
$caps=['metric_codes'=>['consume_amount'],'query_shapes'=>['summary','trend','ranking','comparison'],'current_store_bound'=>true,'output_formats'=>['screen']];
$question='今天消耗业绩趋势和排行';
$rejected=false;
try {$planner->compile($projector->project($question),['decision'=>'query','metric_codes'=>['consume_amount'],'query_shape'=>'ranking','date_code'=>'TODAY'],$caps,'screen','2026-09-08');}
catch (Throwable $e) {$rejected=true;}
$check($rejected,'compound shape must not silently discard trend');
$rejected=false;
try {$planner->compile($projector->project('今天消耗业绩导出Excel'),['decision'=>'query','metric_codes'=>['consume_amount'],'query_shape'=>'summary','date_code'=>'TODAY'],$caps,'screen','2026-09-08');}
catch (Throwable $e) {$rejected=true;}
$check($rejected,'text requests Excel while capability unavailable must not succeed as screen only');
$raw=['question'=>'今天消耗业绩','history'=>array_fill(0,20,['question'=>'张三电话13800138000今天现金多少','answer'=>['name'=>'王小明','phone'=>'13900139000','amount'=>98765432,'api_key'=>'fixture-secret-token']])];
$view=$projector->modelView($projector->validateConversation($raw['question'],$raw['history']));
$wire=json_encode($view,JSON_UNESCAPED_UNICODE);
$check(count($view['recent_user_intents'])===20,'accept latest twenty local rounds');
foreach (['张三','王小明','13800138000','13900139000','98765432','fixture-secret-token'] as $secret) $check(strpos($wire,$secret)===false,'PII/answer content excluded: '.substr(hash('sha256',$secret),0,8));
$check($projector->project('今天张三消耗业绩')['unresolved_condition']===true,'unknown person never becomes unfiltered query');
$renderer=new app\services\ai\presentation\AiAnswerRenderer();
$gatewaySource=file_get_contents($base.'ai/AiGatewayServices.php');
$check(strpos($gatewaySource,'AiFollowupQueryPlanner')===false && strpos($gatewaySource,'registeredSelectionFallback')===false,
    'gateway has no keyword-parser or local fallback execution path');
$check(strpos($gatewaySource,"array_merge([\$o['label']],\$o['aliases'])")!==false,
    'gateway keeps duplicate exact person aliases as controlled choices instead of widening them to a role');
$check(strpos($gatewaySource,"strpos(\$currentQuestion,\$currentToken)===false")!==false
    && strpos($gatewaySource,"!in_array(\$intent['object_kind']??null,['person','position'],true)")!==false,
    'a replaced analytical object drops only stale prior private selections, never a reference in the current turn');
$answer=$renderer->render(['query'=>['query_shape'=>'comparison','start_date'=>'2026-09-08','end_date'=>'2026-09-08','compare_range'=>['start'=>'2026-09-07','end'=>'2026-09-07']],
    'data_as_of'=>'2026-09-08T12:00:00+08:00','results'=>[
        ['metric_code'=>'consume_amount','period'=>'current','amount_cents'=>10000],['metric_code'=>'consume_amount','period'=>'comparison','amount_cents'=>20000],
    ]]);
$check($answer['cards']===[] && strpos($answer['summary'], '消耗业绩为100元；对比期间为200元')===0,
    'comparison answers state verified current and comparison facts once without duplicate cards');
$cashView=['query'=>['query_shape'=>'summary','start_date'=>'2026-09-08','end_date'=>'2026-09-08','compare_range'=>null],
    'data_as_of'=>'2026-09-08T12:00:00+08:00','results'=>[
        ['metric_code'=>'cash_performance','period'=>'current','amount_cents'=>15050],
        ['metric_code'=>'consume_amount','period'=>'current','amount_cents'=>20000],
    ]];
$cashAnswer=$renderer->render($cashView);
$check($cashAnswer['cards']===[] && strpos($cashAnswer['summary'],'现金业绩为151元；消耗业绩为200元。')===0,
    'mixed summaries render each verified metric once in a concise conclusion');
$check(($cashAnswer['presentation']['version'] ?? null)===1
    && ($cashAnswer['presentation']['headline'] ?? null)==='本期经营概览'
    && $cashAnswer['presentation']['facts']=== [
        ['label'=>'现金业绩','value'=>'151','unit'=>'元','section'=>'收款结果'], ['label'=>'消耗业绩','value'=>'200','unit'=>'元','section'=>'服务消耗'],
    ] && ($cashAnswer['presentation']['period_label'] ?? null)==='统计时间：2026-09-08 至 2026-09-08。',
    'multiple verified summary facts use one portable conclusion-first presentation without duplicate cards');
$check(strpos($cashAnswer['summary'], '我先从')===false,
    'summary answers omit generic process narration that does not help the operator decide');
$cashTooltip=(new app\services\metric\MetricDictionaryServices())->getTooltip('cash_performance');
$check(strpos($cashTooltip['include'],'充值')!==false && strpos($cashTooltip['exclude'],'尚未收取')!==false && strpos($cashTooltip['note'],'退款')!==false,
    'cash metric explanation remains available from the registered dictionary');
foreach (['trend','ranking'] as $shape) {
    $cashView['query']['query_shape']=$shape;
    $point=['business_date'=>'2026-09-08','store_id'=>1,'store_name'=>'测试门店','amount_cents'=>15050];
    $cashView['results']=[['metric_code'=>'cash_performance','period'=>'current','rows'=>$shape==='trend'?[$point]:['top'=>[$point]]]];
    $projected=$renderer->render($cashView);
    $check($projected['table']['rows'][0]['metric']==='现金业绩' && $projected['table']['rows'][0]['value']==='151','cash '.$shape.' never mislabeled consumption');
}
$cashView['query']['query_shape']='ranking';
$cashView['results']=[['metric_code'=>'cash_performance','period'=>'current','rows'=>['top'=>[
    ['store_id'=>1,'store_name'=>'甲店','amount_cents'=>15050],
    ['store_id'=>2,'store_name'=>'乙店','amount_cents'=>12000],
]]]];
$shortRanking=$renderer->render($cashView);
$check(strpos($shortRanking['summary'],'按现金业绩看，第1名是甲店，现金业绩为151元；第2名是乙店，现金业绩为120元。')===0,
    'a short verified ranking names every displayed ordinal instead of hiding a follow-up target behind the first row');
$cashView['results']=[['metric_code'=>'cash_performance','period'=>'current','rows'=>['top'=>[
    ['store_id'=>1,'store_name'=>'甲店','amount_cents'=>15050],
    ['store_id'=>2,'store_name'=>'乙店','amount_cents'=>15050],
    ['store_id'=>3,'store_name'=>'丙店','amount_cents'=>12000],
]]]];
$tiedRanking=$renderer->render($cashView);
$check(array_column($tiedRanking['table']['rows'],'rank')===['并列第1名','并列第1名','第3名']
    && strpos($tiedRanking['summary'],'按现金业绩看，并列第1名是甲店')===0,
    'equal verified values retain their shared ordinal instead of becoming false first and second places');
$cashView['query']['compare_range']=['start'=>'2026-09-07','end'=>'2026-09-07'];
$cashView['results']=[
    ['metric_code'=>'cash_performance','period'=>'current','rows'=>['top'=>[['store_id'=>1,'store_name'=>'甲店','amount_cents'=>15050]]]],
    ['metric_code'=>'cash_performance','period'=>'comparison','rows'=>['top'=>[['store_id'=>2,'store_name'=>'乙店','amount_cents'=>12000]]]],
];
$comparisonRanking=$renderer->render($cashView);
$check(($comparisonRanking['table']['columns'][0]['key'] ?? null)==='period_label'
    && array_column($comparisonRanking['table']['rows'],'period_label')===['本期','对比期']
    && strpos($comparisonRanking['summary'],'本期：按现金业绩看，第1名是甲店')===0 && strpos($comparisonRanking['summary'],'对比期：按现金业绩看，第1名是乙店')!==false,
    'ranking comparisons retain each evidence period in both conclusion and table');
$cashView['query']['compare_range']=null;
$cashView['results']=[['metric_code'=>'cash_performance','period'=>'current','rows'=>['top'=>[]]]];
$emptyRanking=$renderer->render($cashView);
$check(strpos($emptyRanking['summary'],'本期间没有符合当前筛选条件的现金业绩数据。')===0 && !isset($emptyRanking['table']),
    'empty verified rankings state no matching data instead of a generic processing claim');
$trend=$renderer->render(['query'=>['query_shape'=>'trend','start_date'=>'2026-09-08','end_date'=>'2026-09-08','compare_range'=>['start'=>'2026-09-07','end'=>'2026-09-07']],
    'data_as_of'=>'2026-09-08T12:00:00+08:00','results'=>[
        ['metric_code'=>'cash_performance','period'=>'current','rows'=>[['business_date'=>'2026-09-08','amount_cents'=>15050]]],
        ['metric_code'=>'cash_performance','period'=>'comparison','rows'=>[['business_date'=>'2026-09-07','amount_cents'=>12000]]],
    ]]);
$check(($trend['table']['columns'][0]['key'] ?? null)==='period_label' && strpos($trend['summary'],'2026-09-08的现金业绩为151元。')===0,
    'trend comparison labels rows and keeps the conclusion on the current period');
$cashView['query']['query_shape']='summary';
$cashView['results']=[['metric_code'=>'actual_performance','period'=>'current','amount_cents'=>15050,'storage_unit'=>'fen']];
$actual=$renderer->render($cashView);
$check($actual['cards']===[] && strpos($actual['summary'], '实际业绩为151元')===0,
    'confirmed actual metric renders through its own registration without a duplicate card');
$check(($actual['presentation']['headline'] ?? null)==='实际业绩为151元。' && ($actual['presentation']['facts'] ?? null)===[],
    'a single verified metric keeps its conclusion as the first visible answer rather than manufacturing a generic card');
$large=$renderer->render(['query'=>['query_shape'=>'summary','start_date'=>'2026-09-08','end_date'=>'2026-09-08','compare_range'=>null],
    'data_as_of'=>'2026-09-08T12:00:00+08:00','results'=>[
        ['metric_code'=>'cash_performance','period'=>'current','amount_cents'=>4484000],
    ]]);
$check(strpos((string)($large['presentation']['headline'] ?? ''),'44,840元')!==false
    && strpos((string)($large['summary'] ?? ''),'44,840元')!==false,
    'answer values add only display separators after the authoritative integer-yuan rounding');
$threshold=$renderer->render(['query'=>['query_shape'=>'threshold_count','metric_codes'=>['sales_collected_amount'],'start_date'=>'2026-09-01','end_date'=>'2026-09-16','compare_range'=>null,
    'business_filters'=>['object_kind'=>'member'],'aggregate_condition'=>['subject'=>'member','aggregation'=>'period_total','operator'=>'gte','amount_cents'=>498000]],
    'data_as_of'=>'2026-09-16T12:00:00+08:00','results'=>[
        ['metric_code'=>'sales_collected_amount','period'=>'current','storage_unit'=>'count','source_storage_unit'=>'fen','object_kind'=>'member',
            'aggregate_condition'=>['subject'=>'member','aggregation'=>'period_total','operator'=>'gte','amount_cents'=>498000],'count'=>3],
    ]]);
$check($threshold['summary']==='累计实际收款销售额达到4,980元的会员共有3人。 统计时间：2026-09-01 至 2026-09-16。'
    && $threshold['cards']===[],
    'typed threshold evidence renders a natural member count without a metric-specific answer branch');
$quantity=$renderer->render(['query'=>['query_shape'=>'summary','start_date'=>'2026-09-08','end_date'=>'2026-09-08','compare_range'=>null],
    'data_as_of'=>'2026-09-08T12:00:00+08:00','results'=>[
        ['metric_code'=>'sales_quantity','period'=>'current','storage_unit'=>'count','count'=>2],
        ['metric_code'=>'completed_service_item_count','period'=>'current','storage_unit'=>'count','count'=>3],
    ]]);
$check(strpos($quantity['summary'], '销售数量为2件；完成服务项目数量为3项。')===0,
    'count units come from metric dictionary metadata instead of renderer metric branches');
$personOverview=$renderer->render(['query'=>['query_shape'=>'summary','start_date'=>'2026-09-19','end_date'=>'2026-09-19','compare_range'=>null,
    'business_filters'=>['object_kind'=>'person']],
    'personnel_selection_label'=>'测试人员（按当前任职）','data_as_of'=>'2026-09-19T12:00:00+08:00','results'=>[
        ['metric_code'=>'staff_sales_yeji','period'=>'current','storage_unit'=>'fen','amount_cents'=>10000],
        ['metric_code'=>'staff_project_num','period'=>'current','storage_unit'=>'project_count_micro','count'=>2500000],
        ['metric_code'=>'staff_service_num','period'=>'current','storage_unit'=>'customer_tenth','count'=>13],
    ]]);
$check(strpos($personOverview['summary'], '销售人业绩为100元；项目数为2.5项；服务人次为1.3人次。')===0,
    'person overview renders mixed registered money and count storage units through their shared evidence contract');
$serviceVisits=$renderer->render(['query'=>['query_shape'=>'ranking','start_date'=>'2026-09-16','end_date'=>'2026-09-16','compare_range'=>null,'business_filters'=>['object_kind'=>'person']],
    'personnel_selection_label'=>'全部授权人员','data_as_of'=>'2026-09-16T12:00:00+08:00','results'=>[
        ['metric_code'=>'staff_service_num','period'=>'current','storage_unit'=>'customer_tenth','rows'=>['top'=>[
            ['employee_name'=>'测试员工','amount_cents'=>13],
        ]]],
    ]]);
$check(strpos($serviceVisits['summary'], '服务人次为1.3人次')!==false
    && ($serviceVisits['table']['rows'][0]['value']??null)==='1.3'
    && ($serviceVisits['table']['rows'][0]['unit']??null)==='人次',
    'personnel service visits retain exact tenth allocations and dictionary units in answers');
$check(app\services\query\metric\MetricMoneyFormatter::integerYuan(15149)==='151'
    && app\services\query\metric\MetricMoneyFormatter::integerYuan(15150)==='152'
    && app\services\query\metric\MetricMoneyFormatter::integerYuan(-15150)==='-152',
    'integer yuan formatting rounds cents symmetrically instead of truncating');
$http=file_get_contents(dirname(__DIR__,2).'/后端代码/app/controller/ai/AiHttpActions.php');
$check(strpos($http,'return new AiGatewayServices();')!==false,'framework adapter does not autowire optional fixture dependencies');
$siliconFlow=file_get_contents(dirname(__DIR__,2).'/后端代码/app/services/ai/model/SiliconFlowClient.php');
$check(strpos($siliconFlow,"context_constraint_without_source:business_filters")!==false
    && strpos($siliconFlow,'use context_delta business_filters=clear so person, position, member or other object-selection filters from the old subject cannot leak into the new subject')!==false,
    'one bounded model repair clears prior subject filters when a self-contained turn replaces the analytical object');
$check(strpos($siliconFlow,'a self-contained non-condition request after a prior condition must use context_delta aggregate_condition=clear')!==false,
    'one bounded structural repair prevents an old threshold or condition set from leaking into a natural topic switch');
$check(strpos($siliconFlow,'A pronoun or other anaphoric reference to the previously selected object retains both object and business_filters')!==false,
    'binding keeps an anaphoric personnel follow-up on the confirmed person instead of widening it to a whole role');
$check(strpos($siliconFlow,'do not turn an explicit conjunction into alternatives')!==false,
    'binding executes compatible coordinated summary metrics together instead of reopening a metric choice');
$check(strpos($siliconFlow,'The current customer meaning owns the response form')!==false
    && strpos($siliconFlow,'must not inherit a previous threshold_count or aggregate_condition')!==false,
    'binding chooses a natural topic switch from current comparative meaning rather than a literal reset command');
$check(strpos($siliconFlow,'must preserve both its comparative response form and complete ranking requirement with current-question evidence')!==false,
    'language understanding retains a self-contained rank instead of treating it as continuation of an earlier threshold');
$gateway=file_get_contents(dirname(__DIR__,2).'/后端代码/app/services/ai/AiGatewayServices.php');
$check(strpos($siliconFlow,"'unknown_metric_code'=>")!==false
    && strpos($gateway,"['AI_MODEL_INTENT_CONTRACT_INVALID','AI_MODEL_METRIC_UNKNOWN']")!==false,
    'a hallucinated metric code gets one bounded repair against supplied registry capabilities');
$check(strpos($gateway,'$terms===[]?null:')!==false && strpos($gateway,'MetricSemanticCatalog::uniqueTermInText')!==false
    && strpos($gateway,"\$intent['needs_metric_choice']=false;")!==false,
    'an exact current registered measurement remains bindable when the language pass omitted only its metric requirement');
$check(strpos($gateway,"if ((\$intent['needs_metric_choice']??false)!==true && !empty(\$intent['metric_codes'])) return \$intent;")===false
    && strpos($gateway,"(\$intent['context_delta']['metric_codes']??null)==='replace'")!==false,
    'an inherited complete-looking metric cannot bypass a unique exact current-turn registered metric');
$check(strpos($gateway,'if (count($requirements)>1) {')!==false
    && strpos($gateway,"mb_strpos(\$exact['term'],\$fragment")!==false
    && strpos($gateway,'isset($metricRequirementIds[$binding[\'requirement_id\']??\'\'])')!==false,
    'overlapping fragments of one exact registered title converge while independent metric requirements stay strict');
$intentContract=file_get_contents(dirname(__DIR__,2).'/后端代码/app/services/ai/contract/AiIntentResultContract.php');
$check(strpos($gateway,'prepareUniqueExactMetricCandidate($intent,$understanding,$safeQuestion,$metricCodes)')!==false
    && strpos($intentContract,"\$intent['requirement_bindings']=array_map")!==false
    && strpos($intentContract,"\$intent['context_delta']['metric_codes']='replace'")!==false,
    'unique exact registered metric titles are canonicalized before strict JSON audit validation');
$gatewayReflection=new ReflectionClass(app\services\ai\AiGatewayServices::class);
$gatewayFixture=$gatewayReflection->newInstanceWithoutConstructor();
$attachDefault=$gatewayReflection->getMethod('attachRegisteredDefaultAnalysisObject');
if (PHP_VERSION_ID<80100) $attachDefault->setAccessible(true);
$positionObject=['ref'=>'position:2','kind'=>'position','label'=>'美容师','aliases'=>[],'version'=>'1','relations'=>['staff_sales_yeji']];
$factCohort=['ref'=>app\services\query\metric\MetricDefinitionRegistry::PERSONNEL_FACT_PARTICIPANT_REF,'kind'=>'cohort',
    'label'=>'本期有业绩归属的人员','aliases'=>[],'version'=>'1','relations'=>['staff_sales_yeji']];
$defaultObjects=$attachDefault->invoke($gatewayFixture,['status'=>'choose','objects'=>[$positionObject],'catalog_ref'=>'fixture'],
    [$positionObject,$factCohort],['default_selection_ref'=>app\services\query\metric\MetricDefinitionRegistry::PERSONNEL_FACT_PARTICIPANT_REF],
    'staff_sales_yeji','');
$explicitObjects=$attachDefault->invoke($gatewayFixture,['status'=>'choose','objects'=>[$positionObject],'catalog_ref'=>'fixture'],
    [$positionObject,$factCohort],['default_selection_ref'=>app\services\query\metric\MetricDefinitionRegistry::PERSONNEL_FACT_PARTICIPANT_REF],
    'staff_sales_yeji','美容师');
$check(array_column($defaultObjects['objects'],'ref')===['position:2',app\services\query\metric\MetricDefinitionRegistry::PERSONNEL_FACT_PARTICIPANT_REF]
    && array_column($explicitObjects['objects'],'ref')===['position:2'],
    'a broad personnel question receives only its registered fact cohort while an explicit personnel term remains customer-selected');
$replaceCohort=$gatewayReflection->getMethod('replaceInheritedSystemCohortForCurrentLocalSelection');
if (PHP_VERSION_ID<80100) $replaceCohort->setAccessible(true);
$cohortIntent=['object_kind'=>'position','object_term'=>'',
    'context_delta'=>['business_filters'=>'inherit']];
$replacedCohort=$replaceCohort->invoke($gatewayFixture,$cohortIntent,
    ['business_filters'=>['object_kind'=>'person','selection_ref'=>app\services\query\metric\MetricDefinitionRegistry::PERSONNEL_FACT_PARTICIPANT_REF]],
    ['question'=>'本月[local_condition_1]的销售业绩排名第一是谁'],['local_condition_1'=>'position']);
$retainedExplicit=$replaceCohort->invoke($gatewayFixture,$cohortIntent,
    ['business_filters'=>['object_kind'=>'person','selection_ref'=>'role:salesperson']],
    ['question'=>'本月[local_condition_1]的销售业绩排名第一是谁'],['local_condition_1'=>'position']);
$check(($replacedCohort['context_delta']['business_filters']??null)==='replace'
    &&($retainedExplicit['context_delta']['business_filters']??null)==='inherit',
    'a current explicit local personnel selection replaces only a system cohort, never an earlier customer-selected role');
$exactCandidate=$gatewayReflection->getMethod('prepareUniqueExactMetricCandidate');
if (PHP_VERSION_ID<80100) $exactCandidate->setAccessible(true);
$candidate=$exactCandidate->invoke($gatewayFixture,[
    'metric_codes'=>['invented_metric'],'needs_metric_choice'=>false,'requirement_bindings'=>[],
    'context_delta'=>array_fill_keys(app\services\ai\contract\AiIntentResultContract::DELTA_FIELDS,'inherit'),
],['requirements'=>[ ['id'=>'r1','fields'=>['metric_codes'],'values'=>['metric_terms'=>['劳动业绩']]] ]],
    ['question'=>'9月16日哪个手艺人的劳动业绩最高？'],['staff_labor_yeji','staff_sales_yeji']);
$check(($candidate['metric_codes']??null)===['staff_labor_yeji']
    && ($candidate['requirement_bindings']??null)===[['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['staff_labor_yeji']]]
    && ($candidate['context_delta']['metric_codes']??null)==='replace',
    'pre-contract exact binding repairs an unknown code and missing audit row without choosing a fuzzy metric');
$check(app\services\ai\contract\AiIntentResultContract::isUniqueExactMetricBinding(
        $candidate,
        ['requirements'=>[ ['id'=>'r1','fields'=>['metric_codes'],'values'=>['metric_terms'=>['劳动业绩']]] ]],
        ['question'=>'9月16日哪个手艺人的劳动业绩最高？'],
        ['staff_labor_yeji','staff_sales_yeji']
    )
    && strpos($gateway,'exact_registry_metric_binding_admitted')!==false,
    'a unique exact registry binding bypasses only the fallible semantic reviewer, not strict execution checks');
$check(strpos($gateway,"(\$intent['object_relation']??null)==='analysis'")!==false
    && strpos($gateway,'($privateKindsByReference[$referenceMatch[1]]??null)===($intent[\'object_kind\']??null)')!==false
    && strpos($gateway,"unset(\$safe['local_conditions'][\$referenceMatch[1]])")!==false
    && strpos($gateway,'local_analytical_object_reference_bound')!==false,
    'a proven local analytical-class token is consumed without weakening named-object selection');
$check(strpos($gateway,'reconcileExactRegisteredAnalyticalObject(')!==false
    && strpos($gateway,'count($owners)!==1')!==false
    && strpos($gateway,"'object_relation'=>'analysis'")!==false
    && strpos($gateway,"preg_match('/\\[local_condition_[0-9]+\\]/D',\$question)")!==false,
    'one exact registry-published analytical object label corrects a generic overview without touching local identities');
$reuseInit=strpos($gateway,'$bindingRankRecovery=false;$groupedItems=null;$groupedUnderstanding=null;');
$reuseBranch=strpos($gateway,'if ($reusedConditionIntent!==null)');
$check($reuseInit!==false && $reuseBranch!==false && $reuseInit<$reuseBranch,
    'collection carriers are initialized before deterministic context-reuse branches');
// Exercise object-first reconciliation without booting the application: the
// same measurement wording must bind differently for personnel and projects.
$gatewayClass=new ReflectionClass(app\services\ai\AiGatewayServices::class);
$gatewayWithoutDependencies=$gatewayClass->newInstanceWithoutConstructor();
$objectVocabularyMethod=$gatewayClass->getMethod('analysisObjectVocabulary');
$objectVocabulary=$objectVocabularyMethod->invoke($gatewayWithoutDependencies,[
    'metric_readiness'=>app\services\query\metric\MetricDefinitionRegistry::capabilities(),
]);
$reconcileObject=$gatewayClass->getMethod('reconcileExactRegisteredAnalyticalObject');
$questionText='本月完成服务项目数量最多的员工是谁';
$safeObjectQuestion=['question'=>$questionText,'evidence_messages'=>[['id'=>'current','text'=>$questionText]]];
$misclassifiedUnderstanding=['goal'=>'查询排行','requirements'=>[[
    'id'=>'r1','meaning'=>'查询完成服务项目数量最多的员工',
    'fields'=>['object_kind','object_relation','operation','ranking','metric_codes'],
    'values'=>['object_kind'=>'project','object_relation'=>'analysis','operation'=>'ranking',
        'ranking'=>['direction'=>'top','limit'=>1],'metric_terms'=>['完成服务项目数量']],
    'evidence'=>[['message_id'=>'current','quote'=>$questionText]],
]],'status'=>'understood'];
$personReconciled=$reconcileObject->invoke(
    $gatewayWithoutDependencies,$misclassifiedUnderstanding,$safeObjectQuestion,$objectVocabulary
);
$check(($personReconciled['requirements'][0]['values']['object_kind']??null)==='person'
    && app\services\query\metric\MetricSemanticCatalog::uniqueTermInText(
        $questionText,['staff_project_num','staff_sales_yeji']
    )===['metric_code'=>'staff_project_num','term'=>'完成服务项目数量'],
    'an explicit employee answer object wins over the project noun embedded in a metric title and narrows binding to wage projects');
$projectQuestion='本月完成服务项目数量最多的项目是什么';
$projectUnderstanding=$misclassifiedUnderstanding;
$projectUnderstanding['requirements'][0]['evidence'][0]['quote']=$projectQuestion;
$projectUnderstanding['requirements'][0]['values']['object_kind']='person';
$projectReconciled=$reconcileObject->invoke(
    $gatewayWithoutDependencies,$projectUnderstanding,
    ['question'=>$projectQuestion,'evidence_messages'=>[['id'=>'current','text'=>$projectQuestion]]],
    $objectVocabulary
);
$check(($projectReconciled['requirements'][0]['values']['object_kind']??null)==='project'
    && app\services\query\metric\MetricSemanticCatalog::uniqueTermInText(
        $projectQuestion,['completed_service_item_count','sales_amount']
    )===['metric_code'=>'completed_service_item_count','term'=>'完成服务项目数量'],
    'a separately stated project answer object keeps the project completion metric under the same generic reconciliation');
$check(strpos($intentContract,"\$hasField('metric_codes',true)")!==false
    && strpos($intentContract,"\$hasField('operation',true)")!==false
    && strpos($intentContract,"\$intent['operation']!=='threshold_count' || \$hasField('aggregate_condition',true)")!==false,
    'a complete current analytical request may release an old selected object without relying on a topic-switch phrase');
$check(strpos($intentContract,"\$currentUnboundRequest=false;")!==false
    && strpos($intentContract,"&& !\$currentUnboundRequest")!==false,
    'a clear unsupported new topic releases the prior selected object but still stops before any data read');
$check(strpos($intentContract,"if (count(\$metricRequirementIds)===1 && count(\$value)>1)")!==false
    && strpos($intentContract,"if (\$duplicates) \$value=[['requirement_id'=>\$metricRequirementIds[0]")!==false,
    'identical duplicate audit rows for one metric requirement collapse without accepting conflicting semantics');
$check(strpos($intentContract,"\$clearedStaleAggregateCondition=\$aggregateCondition!==null")!==false
    && strpos($intentContract,"\$currentRankingReplacesThreshold=isset(\$currentFields['ranking'])")!==false
    && strpos($intentContract,"\$value['operation']='ranking';")!==false
    && strpos($intentContract,"\$delta['aggregate_condition']='clear';")!==false,
    'a current non-threshold operation structurally retires an echoed prior threshold without parsing a question phrase');
$check(strpos($intentContract,'An exact, unambiguous supplied registered metric display title used as the requested measurement is an identified basis')!==false,
    'candidate-blind review accepts an exact registered measurement title while keeping broad category wording ambiguous');
$understandingContract=file_get_contents(dirname(__DIR__,2).'/后端代码/app/services/ai/contract/AiIntentUnderstandingContract.php');
$check(strpos($understandingContract,'use the complete de-identified current message verbatim as its evidence quote')!==false,
    'the one structural repair corrects non-unique evidence without restoring a private name or changing meaning');
$check(strpos($understandingContract,'preserve every measurement separately')!==false,
    'understanding preserves coordinated measurements instead of turning an explicit multi-metric summary into a choice');
$check(strpos($understandingContract,'Prefer copying each metric_term character-for-character')!==false
    &&strpos($understandingContract,'never invent a paraphrase or insert an object qualifier')!==false,
    'understanding keeps literal evidence by default and admits only registry-owned aliases for generic conditions');
$check(strpos($intentContract,'must reject an inherited threshold_count, aggregate condition or aggregate summary')!==false,
    'independent review rejects a stale aggregate form when the current question requests a ranked object');
foreach ($failed as $label) echo 'FAIL '.$label."\n";
echo 'gateway-review-regressions: '.$passed.' passed, '.count($failed)." failed\n";
exit($failed?1:0);
