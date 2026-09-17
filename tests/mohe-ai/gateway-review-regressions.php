<?php
// Read-only deterministic review regressions: no app boot, database, model or network.
$base=dirname(__DIR__,2).'/后端代码/app/services/';
foreach (['BaseServices.php','metric/MetricDictionaryServices.php','query/metric/MetricMoneyFormatter.php','query/metric/MetricSemanticCatalog.php','query/metric/MetricDefinitionRegistry.php','query/metric/MetricReadViewServices.php','ai/contract/AiContractException.php',
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
$answer=$renderer->render(['query'=>['query_shape'=>'comparison','start_date'=>'2026-09-08','end_date'=>'2026-09-08','compare_range'=>['start'=>'2026-09-07','end'=>'2026-09-07']],
    'data_as_of'=>'2026-09-08T12:00:00+08:00','results'=>[
        ['metric_code'=>'consume_amount','period'=>'current','amount_cents'=>10000],['metric_code'=>'consume_amount','period'=>'comparison','amount_cents'=>20000],
    ]]);
$check($answer['cards'][0]['start_date']==='2026-09-08' && $answer['cards'][1]['start_date']==='2026-09-07' && $answer['cards'][1]['end_date']==='2026-09-07','comparison card dates belong to their own evidence period');
$cashView=['query'=>['query_shape'=>'summary','start_date'=>'2026-09-08','end_date'=>'2026-09-08','compare_range'=>null],
    'data_as_of'=>'2026-09-08T12:00:00+08:00','results'=>[
        ['metric_code'=>'cash_performance','period'=>'current','amount_cents'=>15050],
        ['metric_code'=>'consume_amount','period'=>'current','amount_cents'=>20000],
    ]];
$cashAnswer=$renderer->render($cashView);
$check($cashAnswer['cards'][0]['metric_name']==='现金业绩' && $cashAnswer['cards'][0]['display_value']==='151' && $cashAnswer['cards'][1]['metric_name']==='消耗业绩','mixed cards preserve exact metric identity and integer display');
$check(strpos($cashAnswer['summary'],'现金业绩为151元')===0 && strpos($cashAnswer['summary'],'消耗业绩为200元')!==false,'first answer starts with verified summary facts rather than a generic processing sentence');
$check(strpos($cashAnswer['cards'][0]['tooltip']['include'],'充值')!==false && strpos($cashAnswer['cards'][0]['tooltip']['exclude'],'尚未收取')!==false && strpos($cashAnswer['cards'][0]['tooltip']['note'],'退款')!==false,'cash tooltip explains recharge received debt and separate refund');
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
$check(strpos($shortRanking['summary'],'前1是甲店，现金业绩为151元；前2是乙店，现金业绩为120元。')===0,
    'a short verified ranking names every displayed ordinal instead of hiding a follow-up target behind the first row');
$cashView['query']['query_shape']='summary';
$cashView['results']=[['metric_code'=>'actual_performance','period'=>'current','amount_cents'=>15050,'storage_unit'=>'fen']];
$actual=$renderer->render($cashView);
$check($actual['cards'][0]['metric_name']==='实际业绩' && $actual['cards'][0]['display_value']==='151','confirmed actual metric renders through its own registration');
$threshold=$renderer->render(['query'=>['query_shape'=>'threshold_count','metric_codes'=>['sales_collected_amount'],'start_date'=>'2026-09-01','end_date'=>'2026-09-16','compare_range'=>null,
    'business_filters'=>['object_kind'=>'member'],'aggregate_condition'=>['subject'=>'member','aggregation'=>'period_total','operator'=>'gte','amount_cents'=>498000]],
    'data_as_of'=>'2026-09-16T12:00:00+08:00','results'=>[
        ['metric_code'=>'sales_collected_amount','period'=>'current','storage_unit'=>'count','source_storage_unit'=>'fen','object_kind'=>'member',
            'aggregate_condition'=>['subject'=>'member','aggregation'=>'period_total','operator'=>'gte','amount_cents'=>498000],'count'=>3],
    ]]);
$check($threshold['summary']==='累计实际收款销售额达到4980元的会员共有3人。 统计时间：2026-09-01 至 2026-09-16。'
    && $threshold['cards'][0]['metric_name']==='达标会员数' && $threshold['cards'][0]['unit']==='人',
    'typed threshold evidence renders a natural member count without a metric-specific answer branch');
$check(app\services\query\metric\MetricMoneyFormatter::integerYuan(15149)==='151'
    && app\services\query\metric\MetricMoneyFormatter::integerYuan(15150)==='152'
    && app\services\query\metric\MetricMoneyFormatter::integerYuan(-15150)==='-152',
    'integer yuan formatting rounds cents symmetrically instead of truncating');
$http=file_get_contents(dirname(__DIR__,2).'/后端代码/app/controller/ai/AiHttpActions.php');
$check(strpos($http,'return new AiGatewayServices();')!==false,'framework adapter does not autowire optional fixture dependencies');
foreach ($failed as $label) echo 'FAIL '.$label."\n";
echo 'gateway-review-regressions: '.$passed.' passed, '.count($failed)." failed\n";
exit($failed?1:0);
