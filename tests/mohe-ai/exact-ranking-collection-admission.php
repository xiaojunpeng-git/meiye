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
$scoped=$admission->namedStoreScope('这个月甲店这个门店技师项目数最多的是哪个，最低的是哪个',[
    ['ref'=>'store:12','kind'=>'store','label'=>'甲店'],
    ['ref'=>'store:13','kind'=>'store','label'=>'乙店'],
]);
$verify(($scoped['store_id']??null)===12
    &&$admission->match($scoped['question'],[['object_kind'=>'person','object_label'=>'技师']],['staff_project_num'])
        ===[['metric_code'=>'staff_project_num','object_kind'=>'person','direction'=>'top_and_bottom','limit'=>1]],
    'an exact authorized store scope leaves the ordinary two-extrema ranking grammar intact');
$verify($admission->namedStoreScope('甲店这个门店技师项目数最多的是哪个',[
    ['ref'=>'store:12','kind'=>'store','label'=>'甲店'],
    ['ref'=>'store:13','kind'=>'store','label'=>'甲店'],
])===null,'duplicate authorized store names never select an arbitrary identity');
$verify($admission->namedStoreScope('这个门店技师项目数最多的是哪个',[
    ['ref'=>'store:12','kind'=>'store','label'=>'甲店'],
])===null,'a demonstrative without a named store stays on contextual understanding');

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
$verify($admission->match('这个月项目销售额第一名是谁',$saleObjects,$metrics,$sharedDefaults)
    ===[['metric_code'=>'sales_amount','object_kind'=>'project','direction'=>'top','limit'=>1]],
    'an exact first-place question is a complete registered project ranking');
$verify($admission->match('这个月项目销售额倒数第一名是谁',$saleObjects,$metrics,$sharedDefaults)
    ===[['metric_code'=>'sales_amount','object_kind'=>'project','direction'=>'bottom','limit'=>1]],
    'the same ordinal grammar admits an exact last-place question');
$verify($admission->match('这两家这个月项目销售额第一名是谁',$saleObjects,$metrics,$sharedDefaults)===null,
    'a prior-store reference cannot be dropped to enter the independent ranking path');
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
$realStorePhrase=$admission->namedStoreScope('这个月肥西水晶城这个门店技师项目数最多的是哪个，最低的是哪个',[
    ['ref'=>'store:133','kind'=>'store','label'=>'肥西水晶城'],
]);
$verify(($realStorePhrase['store_id']??null)===133
    &&$admission->match($realStorePhrase['question'],$vocabulary,$capabilities['metric_codes'])
        ===[['metric_code'=>'staff_project_num','object_kind'=>'person','direction'=>'top_and_bottom','limit'=>1]],
    'a registered metric containing 项目 does not conceal the actual 技师 ranked subject');
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
$verify($dateOmitted===null && $reason==='context_requires_understanding',
    'a ranking with a predecessor must pass topic understanding before any previous period can be reused');
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
// With a predecessor, an omitted period leaves topic continuity open. The
// exact shortcut may reset only a complete question with its own period;
// otherwise it cannot borrow the prior date, store or object shape.
$prior=$prefixedPlan['plan']['items'][1]['plan']['query'];
$prior['store_ids']=[12];
$reason=null;
$contextArgs=['会员业绩最高的前五名详情',$vocabulary,$capabilities,'screen','2026-09-27',&$reason,['query'=>$prior]];
$verify($rankingMethod->invokeArgs($gateway,$contextArgs)===null && $reason==='context_requires_understanding',
    'a contextual ranking cannot silently reuse a verified date or selected store');
$reason=null;
$freshArgs=['这个月销售额最高的前两家门店',$vocabulary,$capabilities,'screen','2026-09-27',&$reason];
$freshRanking=$rankingMethod->invokeArgs($gateway,$freshArgs);
$verify($reason===null && ($freshRanking['plan']['query']['metric_codes']??null)===['sales_amount']
    &&($freshRanking['plan']['query']['ranking']['limit']??null)===2
    &&($freshRanking['plan']['query']['store_ids']??null)===[],
    'an independent explicit-period ranking uses only its own scope and metric');
$authorizedCapabilities=$capabilities;$authorizedCapabilities['store_ids']=[12];
$compiledFresh=(new app\services\ai\execution\AiRegisteredPlanCompiler())->compile(
    $freshRanking['plan'],$authorizedCapabilities
);
$verify(($compiledFresh['query']['ranking']??null)===['direction'=>'top','limit'=>2]
    &&($compiledFresh['query']['metric_codes']??null)===['sales_amount'],
    'fresh ranking survives the shared registered compiler and permission boundary');
$reason=null;
$allStoreArgs=['2026年3月到今天所有门店现金业绩前两名分别是谁',
    $vocabulary,$capabilities,'screen','2026-09-30',&$reason];
$allStores=$rankingMethod->invokeArgs($gateway,$allStoreArgs);
$verify($reason===null && ($allStores['plan']['query']['store_ids']??null)===[]
    &&($allStores['plan']['query']['ranking']??null)===['direction'=>'top','limit'=>2],
    'explicit all-authorized population remains within the Reader permission boundary');
$allStoreArgs[0]='2026年3月到今天所有门店现金业绩前两名，排除一家';
$verify($rankingMethod->invokeArgs($gateway,$allStoreArgs)===null,
    'an unparsed exclusion cannot be discarded by the fast path');
$verify($memberPlan['plan']['query']['start_date']==='2026-09-25',
    'an independent ranking retains the documented same-day default');
echo "exact ranking collection admission: {$checks} checks PASS\n";
