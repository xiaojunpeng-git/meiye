<?php
require_once __DIR__.'/fixture-autoload.php';

use app\services\ai\semantic\AiExactRankingCollectionAdmission;

$admission=new AiExactRankingCollectionAdmission();
$objects=[
    ['object_kind'=>'business_date','object_label'=>'哪天'],
    ['object_kind'=>'business_date','object_label'=>'日期'],
    ['object_kind'=>'project','object_label'=>'项目'],
    ['object_kind'=>'store','object_label'=>'门店'],
    ['object_kind'=>'member','object_label'=>'会员'],
];
$metrics=['sales_amount','sales_record_count','cash_performance'];
$checks=0;
$verify=static function(bool $condition,string $message)use(&$checks):void{
    $checks++;if(!$condition)throw new RuntimeException($message);
};

$compound=$admission->match('这个月的销售额最高是哪天，销售记录最多的项目是哪个，最低是哪个',$objects,$metrics);
$verify(is_array($compound)&&count($compound)===2,'complete compound ranking should be admitted');
$verify(($compound[0]['metric_code']??null)==='sales_amount'
    &&($compound[0]['object_kind']??null)==='business_date'
    &&($compound[0]['direction']??null)==='top','date extremum keeps its registered metric and dimension');
$verify(($compound[1]['metric_code']??null)==='sales_record_count'
    &&($compound[1]['object_kind']??null)==='project'
    &&($compound[1]['direction']??null)==='top_and_bottom','coordinated bottom tail stays with the preceding project metric');
$prefixedCompound=$admission->match('那这个月的销售额最高是哪天，销售记录最多的项目是哪个，最低是哪个',$objects,$metrics);
$verify($prefixedCompound===$compound,
    'a leading conversational connector preserves the exact compound ranking plan');

$saleObjects=$objects;
$saleObjects[]=['object_kind'=>'product','object_label'=>'产品'];
$saleObjects[]=['object_kind'=>'card','object_label'=>'卡项'];
$saleObjects[]=['object_kind'=>'store','object_label'=>'哪家店'];
$sharedDefaults=['sales_amount'=>['card','project','product'],'cash_performance'=>['store','member']];
$shared=$admission->match('这个月项目、产品、卡项业绩最高的分别是什么',$saleObjects,$metrics,$sharedDefaults);
$verify(is_array($shared)&&array_column($shared,'object_kind')===['project','product','card']
    &&array_unique(array_column($shared,'metric_code'))===['sales_amount'],
    'a distributive object list uses its one shared registered ranking default in customer order');
$verify($admission->match('这个月门店、产品业绩最高的分别是什么',$saleObjects,$metrics,$sharedDefaults)===null,
    'objects without one shared registered ranking default remain model work');
$verify($admission->match('这个月项目、产品、卡项业绩最高的原因分别是什么',$saleObjects,$metrics,$sharedDefaults)===null,
    'open analysis residue cannot enter the shared-default ranking path');
$singleStore=$admission->match('今天业绩最高是哪家店',$saleObjects,$metrics,$sharedDefaults);
$verify($singleStore===[['metric_code'=>'cash_performance','object_kind'=>'store','direction'=>'top','limit'=>1]],
    'one registered object with one registry default can use a closed broad ranking path');
$verify($admission->match('那家店业绩最高是哪家店',$saleObjects,$metrics,$sharedDefaults)===null,
    'a referential store phrase stays outside the broad all-store ranking path');

$memberDetail=$admission->matchPopulationDetail(
    '会员业绩最高的前五名详情',$saleObjects,$metrics,$sharedDefaults,5
);
$verify(($memberDetail['ranking']??null)===['metric_code'=>'cash_performance','object_kind'=>'member',
        'direction'=>'top','limit'=>5]
    &&($memberDetail['detail']??null)===['view'=>'summary','target'=>'set','ordinal'=>null],
    'one closed broad member ranking uses the sole registry default before reading set details');
$verify($admission->matchPopulationDetail(
    '会员业绩最高的前五名详情并分析原因',$saleObjects,$metrics,$sharedDefaults,5
)===null,'open analysis remains model work instead of entering member detail execution');
$verify($admission->matchPopulationDetail(
    '会员现金业绩最高的前五名详情',$saleObjects,$metrics,$sharedDefaults,5
)===null,'an explicitly named metric remains on the ordinary exact-metric understanding path');

$verify($admission->match('这个月销售额最高是哪天，顺便分析原因',$objects,$metrics)===null,
    'open analysis residue must remain on the model path');
$verify($admission->match('这个月业绩最高是哪天，销售记录最多的项目是哪个',$objects,$metrics)===null,
    'an unregistered or ambiguous measurement must not receive a guessed metric');
$singleDate=$admission->match('这个月销售额最高是哪天',$objects,$metrics);
$verify(is_array($singleDate)&&count($singleDate)===1
    &&($singleDate[0]['metric_code']??null)==='sales_amount'
    &&($singleDate[0]['object_kind']??null)==='business_date'
    &&($singleDate[0]['direction']??null)==='top',
    'a fully closed single date extremum uses the same exact semantic boundary');
$singleHeadTail=$admission->match('这个月销售记录最多的项目是哪个，最低又是哪个',$objects,$metrics);
$verify(is_array($singleHeadTail)&&count($singleHeadTail)===1
    &&($singleHeadTail[0]['metric_code']??null)==='sales_record_count'
    &&($singleHeadTail[0]['object_kind']??null)==='project'
    &&($singleHeadTail[0]['direction']??null)==='top_and_bottom',
    'one metric may preserve a coordinated highest-and-lowest result without becoming two subjects');

// The exact admission and the registered compiler must agree on base grain:
// store is a valid ranked object for a store-grain metric, but it is not an
// optional analytical-dimension filter that should be sent to the compiler.
$gatewayReflection=new ReflectionClass(app\services\ai\AiGatewayServices::class);
$gateway=$gatewayReflection->newInstanceWithoutConstructor();
$vocabularyMethod=$gatewayReflection->getMethod('analysisObjectVocabulary');
$rankingMethod=$gatewayReflection->getMethod('compileExactRegisteredRankingCollection');
if (PHP_VERSION_ID<80100) {$vocabularyMethod->setAccessible(true);$rankingMethod->setAccessible(true);}
$contracts=app\services\query\metric\MetricDefinitionRegistry::capabilities();
$vocabulary=$vocabularyMethod->invoke($gateway,['metric_readiness'=>$contracts],null);
$capabilities=['metric_codes'=>array_keys($contracts),'metric_readiness'=>$contracts,
    'query_shapes'=>['summary','breakdown','trend','ranking','comparison','condition_count','condition_list'],
    'output_formats'=>['screen']];
$reason=null;
$args=['这个月销售额最高的门店是哪个',$vocabulary,$capabilities,'screen','2026-09-25',&$reason];
$baseGrainRanking=$rankingMethod->invokeArgs($gateway,$args);
$verify(($baseGrainRanking['plan']['query']['business_filters']??null)===[]
    &&($baseGrainRanking['plan']['query']['ranking']??null)===['direction'=>'top','limit'=>1],
    'an exact ranking at the metric base grain does not invent a redundant analytical-dimension filter');
$reason=null;
$prefixedArgs=['那这个月的销售额最高是哪天，销售记录最多的项目是哪个，最低是哪个',$vocabulary,$capabilities,'screen','2026-09-25',&$reason];
$prefixedPlan=$rankingMethod->invokeArgs($gateway,$prefixedArgs);
$verify($reason===null && count($prefixedPlan['plan']['items']??[])===2
    &&array_column($prefixedPlan['plan']['items'],'label')===['日期排行','项目排行'],
    'date parsing and exact ranking admission share leading-connector normalization');
$reason=null;
$sharedArgs=['这个月项目、产品、卡项业绩最高的分别是什么',$vocabulary,$capabilities,'screen','2026-09-25',&$reason];
$sharedPlan=$rankingMethod->invokeArgs($gateway,$sharedArgs);
$verify(array_column($sharedPlan['plan']['items']??[],'label')===['项目排行','产品排行','卡项排行']
    &&array_map(static function(array $item):array{return $item['plan']['query']['metric_codes'];},$sharedPlan['plan']['items'])
        ===[['sales_amount'],['sales_amount'],['sales_amount']],
    'the shared registry default compiles three independent existing Reader plans');
$reason=null;
$memberArgs=['会员业绩最高的前五名详情',$vocabulary,$capabilities,'screen','2026-09-25',&$reason];
$memberPlan=$rankingMethod->invokeArgs($gateway,$memberArgs);
$verify(($memberPlan['plan']['query']['metric_codes']??null)===['cash_performance']
    &&($memberPlan['plan']['query']['business_filters']['object_kind']??null)==='member'
    &&($memberPlan['plan']['query']['ranking']??null)===['direction'=>'top','limit'=>5]
    &&($memberPlan['_member_detail_request']??null)===['view'=>'summary','target'=>'set','ordinal'=>null],
    'the closed member population compiles one registered ranking and keeps its set-detail presentation');
$reason=null;
$storeArgs=['今天业绩最高是哪家店',$vocabulary,$capabilities,'screen','2026-09-25',&$reason];
$storePlan=$rankingMethod->invokeArgs($gateway,$storeArgs);
$verify($reason===null && ($storePlan['plan']['query']['metric_codes']??null)===['cash_performance']
    &&($storePlan['plan']['query']['ranking']??null)===['direction'=>'top','limit'=>1],
    'a broad store question compiles one registry-owned Reader ranking without model understanding');

foreach (['这个月门店业绩前三的门店详情'=>'store','这个月员工业绩前三名详情'=>'person'] as $question=>$kind) {
    $args=[$question,$vocabulary,$capabilities,'screen','2026-09-27',&$reason];
    $detailPlan=$rankingMethod->invokeArgs($gateway,$args);
    $verify($reason===null && ($detailPlan['plan']['query']['ranking']??null)===['direction'=>'top','limit'=>3]
        && count($detailPlan['plan']['query']['ranking_presentation_metrics']??[])>=3
        && !isset($detailPlan['_member_detail_request']), 'R48 '.$kind.' ranking and details form one registered query');
}
$verify($admission->matchPopulationDetail('这个月门店业绩前三名的后两名详情',$vocabulary,array_keys($contracts),$sharedDefaults,3)===null,
    'conflicting population directions cannot enter the closed path');
// R49: a named new ranking replaces the old dimension/ranking, while only
// compatible verified dates and store scope survive across turns.
$prior=$prefixedPlan['plan']['items'][1]['plan']['query'];
$prior['store_ids']=[12];
$source=['query'=>$prior];
$args=['会员业绩最高的前五名详情',$vocabulary,$capabilities,'screen','2026-09-27',&$reason,$source];
$continued=$rankingMethod->invokeArgs($gateway,$args);
$q=$continued['plan']['query'];
$verify($q['start_date']==='2026-09-01' && $q['end_date']==='2026-09-25'
    && $q['store_ids']===[12] && $q['business_filters']===['object_kind'=>'member']
    && $q['ranking']===['direction'=>'top','limit'=>5], 'R49 carries the verified period/scope without project or bottom-ranking residue');
$args[0]='今天会员业绩最高的前五名详情';
$explicit=$rankingMethod->invokeArgs($gateway,$args);
$verify($explicit['plan']['query']['start_date']==='2026-09-27'
    &&$explicit['plan']['query']['store_ids']===[12], 'explicit date wins without dropping store scope');
$verify($memberPlan['plan']['query']['start_date']==='2026-09-25','new conversation retains the documented same-day default');
$args[0]='会员业绩最高的前五名详情';
foreach (['selection','comparison','mixed_dates'] as $case) {
    $bad=$source;
    if ($case==='selection') $bad['query']['business_filters']['selection_ref']='project:1';
    if ($case==='comparison') $bad['query']['compare_range']=['start'=>'2026-08-01','end'=>'2026-08-25'];
    if ($case==='mixed_dates') {
        $other=$prior;$other['start_date']='2026-09-20';
        $bad=['items'=>[['query'=>$prior],['query'=>$other]]];
    }
    $args[6]=$bad;
    $verify($rankingMethod->invokeArgs($gateway,$args)===null && $reason==='context_constraints',
        'R49 '.$case.' retains ordinary contextual understanding instead of silently replacing restrictions');
}
$args[6]=['items'=>[['query'=>$prior],['query'=>$prior]]];
$verify($rankingMethod->invokeArgs($gateway,$args)['plan']['query']['start_date']==='2026-09-01',
    'consistent collection dates can be reused without an extra model call');
// Cross-dimension continuations include registered personnel cohorts. Those
// defaults follow their own metric, unlike a specifically selected employee.
$source=['query'=>$prior];
foreach (['门店'=>'store','员工'=>'person','项目'=>'project','产品'=>'product','会员'=>'member'] as $label=>$kind) {
    $args=[$label.'业绩最高的前五名详情',$vocabulary,$capabilities,'screen','2026-09-27',&$reason,$source];
    $next=$rankingMethod->invokeArgs($gateway,$args);
    $nextQuery=$next['plan']['query']??[];
    $actualKind=$nextQuery['business_filters']['object_kind']??'store';
    $verify($reason===null && $actualKind===$kind && ($nextQuery['start_date']??null)==='2026-09-01'
        &&($nextQuery['store_ids']??null)===[12] && ($nextQuery['ranking']['limit']??null)===5,
        'R49 sequential switch to '.$kind.' keeps only compatible context');
    $source=['query'=>$nextQuery];
}
echo "exact ranking collection admission: {$checks} checks PASS\n";
