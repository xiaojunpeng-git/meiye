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
$verify($admission->match('这个月项目卖得最好的是什么？',$saleObjects,$metrics,$sharedDefaults)
    ===[['metric_code'=>'sales_amount','object_kind'=>'project','direction'=>'top','limit'=>1]],
    'R55 punctuation cannot force a complete registry-default project ranking through the slow model path');
$verify($admission->match('这个月销售额最高的前两家门店',$saleObjects,$metrics,$sharedDefaults)
    ===[['metric_code'=>'sales_amount','object_kind'=>'store','direction'=>'top','limit'=>2]],
    'R55 bounded single ranking keeps its registered metric, object and limit');
$verify($admission->match('这个月销售额最高的前两家门店，排除加盟店',$saleObjects,$metrics,$sharedDefaults)===null,
    'R55 unexplained scope cannot be discarded to enter the fast ranking path');

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
$explicitMemberDetail=$admission->matchPopulationDetail(
    '会员现金业绩最高的前五名详情',$saleObjects,$metrics,$sharedDefaults,5
);
$verify(($explicitMemberDetail['ranking']['metric_code']??null)==='cash_performance'
    &&($explicitMemberDetail['ranking']['limit']??null)===5,
    'an explicitly named registered metric keeps the bounded ranking and detail together');
$verify(($admission->matchPopulationDetail(
    '本月销售额最高的项目详情',$saleObjects,$metrics,$sharedDefaults,null
)['ranking']??null)===['metric_code'=>'sales_amount','object_kind'=>'project','direction'=>'top','limit'=>1],
    'a named metric and a single superlative admit one exact ranked detail');
$verify($admission->matchPopulationDetail(
    '本月销售额最高的项目详情并分析原因',$saleObjects,$metrics,$sharedDefaults,null
)===null,'an unexplained analysis request cannot enter the closed detail path');

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
$dateOmittedArgs=['项目、卡项、产品卖得最好的分别是什么？',$vocabulary,$capabilities,'screen','2026-09-30',&$reason,
    ['query'=>['store_ids'=>[],'business_filters'=>[],'metric_codes'=>['cash_performance'],
        'start_date'=>'2026-09-01','end_date'=>'2026-09-30','compare_range'=>null]]];
$dateOmitted=$rankingMethod->invokeArgs($gateway,$dateOmittedArgs);
$verify($reason===null && count($dateOmitted['plan']['items']??[])===3
    &&($dateOmitted['plan']['items'][0]['plan']['query']['start_date']??null)==='2026-09-01',
    'R55 a fully closed three-object ranking inherits one verified period instead of entering slow model understanding');
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
$reason=null;
$singlePersonArgs=['本月销售人业绩最高的员工详情',$vocabulary,$capabilities,'screen','2026-09-29',&$reason];
$singlePersonDetail=$rankingMethod->invokeArgs($gateway,$singlePersonArgs);
$verify($reason===null
    &&($singlePersonDetail['plan']['query']['metric_codes']??null)===['staff_sales_yeji']
    &&($singlePersonDetail['plan']['query']['ranking']??null)===['direction'=>'top','limit'=>1]
    &&($singlePersonDetail['plan']['query']['ranking_presentation_metrics']??null)===[
        'staff_sales_yeji','staff_labor_yeji','staff_project_num','staff_labor_fee',
        'staff_service_num','staff_point_visit_num'],
    'a first-turn explicit employee superlative keeps its six-field detail profile');
$compiledPersonDetail=(new app\services\ai\execution\AiRegisteredPlanCompiler())->compile(
    $singlePersonDetail['plan'],$capabilities
);
$verify(($compiledPersonDetail['query']['ranking_presentation_metrics']??null)
    ===$singlePersonDetail['plan']['query']['ranking_presentation_metrics'],
    'the frozen business registry preserves detail ordering during compilation');
foreach (['今天劳动业绩第一名是谁？'=>'staff_labor_yeji','今天销售人业绩第1名是哪位？'=>'staff_sales_yeji'] as $question=>$code) {
    $winner=$admission->match($question,$vocabulary,array_keys($contracts),$sharedDefaults);
    $verify(($winner[0]??null)===['metric_code'=>$code,'object_kind'=>'person','direction'=>'top','limit'=>1],
        'R55 an exact person-grain winner question avoids repeated model binding: '.$question);
}
$verify($admission->match('今天现金业绩第一名是谁？',$vocabulary,array_keys($contracts),$sharedDefaults)===null
    &&$admission->match('今天劳动业绩第一名是谁，排除某店',$vocabulary,array_keys($contracts),$sharedDefaults)===null,
    'R55 non-person facts or independent filters cannot enter person-winner admission');
$reason=null;
$laborWinnerArgs=['今天劳动业绩第一名是谁？',$vocabulary,$capabilities,'screen','2026-09-30',&$reason];
$laborWinnerPlan=$rankingMethod->invokeArgs($gateway,$laborWinnerArgs);
$verify($reason===null && ($laborWinnerPlan['plan']['query']['metric_codes']??null)===['staff_labor_yeji']
    &&($laborWinnerPlan['plan']['query']['ranking']??null)===['direction'=>'top','limit'=>1],
    'R55 the same closed winner passes gateway admission with a registered Reader plan');
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
$priorCondition=$prior;
$priorCondition['condition_set']=['subject'=>'member','relation'=>'all','conditions'=>[]];
$priorCondition['aggregate_condition']=['subject'=>'member','relation'=>'all','conditions'=>[]];
$args=[ '这个月销售额最高的前两家门店',$vocabulary,$capabilities,'screen','2026-09-27',&$reason,
    ['query'=>$priorCondition] ];
$freshRanking=$rankingMethod->invokeArgs($gateway,$args);
$verify($reason===null && ($freshRanking['plan']['query']['metric_codes']??null)===['sales_amount']
    &&($freshRanking['plan']['query']['ranking']['limit']??null)===2
    &&($freshRanking['plan']['query']['store_ids']??null)===[12]
    &&empty($freshRanking['plan']['query']['condition_set'])
    &&empty($freshRanking['plan']['query']['aggregate_condition']),
    'R55 complete ranking replaces old customer predicates but retains compatible verified store scope');
$authorizedCapabilities=$capabilities;$authorizedCapabilities['store_ids']=[12];
$compiledFresh=(new app\services\ai\execution\AiRegisteredPlanCompiler())->compile(
    $freshRanking['plan'],$authorizedCapabilities
);
$verify(($compiledFresh['query']['ranking']??null)===['direction'=>'top','limit'=>2]
    &&($compiledFresh['query']['metric_codes']??null)===['sales_amount'],
    'R55 admitted store sales ranking survives the shared registered compiler');
$allStoreArgs=['2026年3月到今天所有门店现金业绩前两名分别是谁',
    $vocabulary,$capabilities,'screen','2026-09-30',&$reason,['query'=>$prior]];
$allStores=$rankingMethod->invokeArgs($gateway,$allStoreArgs);
$verify($reason===null && ($allStores['plan']['query']['store_ids']??null)===[]
    &&($allStores['plan']['query']['ranking']??null)===['direction'=>'top','limit'=>2],
    'R55 explicit all-authorized population clears a prior selected store before permissioned Reader execution');
$allStoreArgs[0]='不要只看肥西水晶城，统计2026年3月到今天我有权限的全部门店，现金业绩前两名分别是谁';
$allStoresNatural=$rankingMethod->invokeArgs($gateway,$allStoreArgs);
$verify($reason===null && ($allStoresNatural['plan']['query']['store_ids']??null)===[]
    &&($allStoresNatural['plan']['query']['ranking']??null)===['direction'=>'top','limit'=>2],
    'R55 a negated prior store plus explicit all-authorized scope still uses the Reader permission boundary');
$allStoreArgs[0]='2026年3月到今天所有门店现金业绩前两名，排除一家';
$verify($rankingMethod->invokeArgs($gateway,$allStoreArgs)===null,
    'R55 unparsed exclusions cannot be discarded while clearing the store scope');
$verify($memberPlan['plan']['query']['start_date']==='2026-09-25','new conversation retains the documented same-day default');
$args[0]='会员业绩最高的前五名详情';
foreach (['comparison','mixed_dates'] as $case) {
    $bad=$source;
    if ($case==='comparison') $bad['query']['compare_range']=['start'=>'2026-08-01','end'=>'2026-08-25'];
    if ($case==='mixed_dates') {
        $other=$prior;$other['start_date']='2026-09-20';
        $bad=['items'=>[['query'=>$prior],['query'=>$other]]];
    }
    $args[6]=$bad;
    $verify($rankingMethod->invokeArgs($gateway,$args)===null && $reason==='context_constraints',
        'R49 '.$case.' retains ordinary contextual understanding instead of silently replacing restrictions');
}
$selectedSource=$source;
$selectedSource['query']['business_filters']['selection_ref']='project:1';
$args[6]=$selectedSource;
$newSubject=$rankingMethod->invokeArgs($gateway,$args);
$verify($reason===null && ($newSubject['plan']['query']['business_filters']??null)===['object_kind'=>'member']
    &&($newSubject['plan']['query']['store_ids']??null)===[12],
    'R55 a complete new ranking drops an old selected object while retaining compatible store authority');
$personSource=$source;
$personSource['query']['business_filters']=['object_kind'=>'person','selection_ref'=>'person:7'];
$personSource['query']['metric_codes']=['staff_sales_yeji'];
$args=['哪个员工业绩最高？',$vocabulary,$capabilities,'screen','2026-09-30',&$reason,$personSource];
$allPersons=$rankingMethod->invokeArgs($gateway,$args);
$verify($reason===null && ($allPersons['plan']['query']['metric_codes']??null)===['staff_sales_yeji']
    &&($allPersons['plan']['query']['business_filters']['selection_ref']??null)!=='person:7',
    'R55 a self-contained employee ranking must not rank only the previously selected employee');
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
