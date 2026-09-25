<?php
require_once __DIR__.'/fixture-autoload.php';

use app\services\ai\AiGatewayServices;
use app\services\ai\contract\AiIntentUnderstandingContract;
use app\services\ai\context\ObjectDetailContinuationResolver;

$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void {
    if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;
};

// Natural-language understanding emits one generic presentation intent. PHP
// never decides whether one literal phrase such as “详情呢” means detail.
foreach (['详情呢','具体看看','展开说说','他怎么样'] as $question) {
    $safe=['schema_version'=>'sanitized-question-v2','question'=>$question,'has_unresolved_conditions'=>false,
        'server_resolved_fields'=>[],'reference_date'=>'2026-09-25','recent_questions'=>[],
        'evidence_messages'=>[['id'=>'current','text'=>$question]],'prior_query'=>['operation'=>'ranking']];
    $normalized=AiIntentUnderstandingContract::normalize([
        'goal'=>'继续查看上一结果对象','status'=>'understood','requirements'=>[[
            'id'=>'r1','meaning'=>'查看上一对象的相关信息','fields'=>['object_detail'],
            'values'=>['object_detail'=>['view'=>'summary','target'=>'single','ordinal'=>null]],
            'evidence'=>[['message_id'=>'current','quote'=>$question]],
        ]],
    ],$safe);
    $check(($normalized['requirements'][0]['values']['object_detail']['view']??null)==='summary',
        'generic object detail contract accepts semantic paraphrase '.$question);
    $check(!isset($normalized['requirements'][0]['values']['result_reference']),
        'pure detail continuation does not become a ranking reference '.$question);
}

$personQuery=['query_shape'=>'ranking','metric_codes'=>['staff_sales_yeji'],'start_date'=>'2026-09-01',
    'end_date'=>'2026-09-25','compare_range'=>null,'store_ids'=>[1],
    'business_filters'=>['object_kind'=>'person','selection_ref'=>'cohort:metric_fact_participants'],
    'ranking'=>['direction'=>'top','limit'=>1],'aggregate_condition'=>null,'ranking_presentation_metrics'=>[]];
$personView=['query'=>$personQuery,'results'=>[['object_kind'=>'person','rows'=>[
    'top'=>[['employee_id'=>7,'employee_name'=>'测试员工','amount_cents'=>7812200]],'bottom'=>[],
]]]];
$resolver=new ObjectDetailContinuationResolver();
$person=$resolver->resolve($personQuery,$personView,['view'=>'summary','target'=>'single','ordinal'=>null]);
$check($person===['object_kind'=>'person','selection_ref'=>'person:7','label'=>'测试员工','view'=>'summary'],
    'person identity comes only from the verified ranking row');

$storeQuery=$personQuery;$storeQuery['metric_codes']=['cash_performance'];$storeQuery['store_ids']=[];
$storeQuery['business_filters']=[];
$storeView=['query'=>$storeQuery,'results'=>[['object_kind'=>'store','rows'=>[
    'top'=>[['store_id'=>3,'store_name'=>'测试门店','amount_cents'=>998800]],'bottom'=>[],
]]]];
$store=$resolver->resolve($storeQuery,$storeView,['view'=>'summary','target'=>'single','ordinal'=>null]);
$check(($store['store_id']??null)===3&&($store['label']??null)==='测试门店',
    'store identity comes only from the verified ranking row');

$many=$personView;$many['results'][0]['rows']['top'][]=['employee_id'=>8,'employee_name'=>'另一员工','amount_cents'=>7000000];
try {$resolver->resolve($personQuery,$many,['view'=>'summary','target'=>'single','ordinal'=>null]);
    throw new LogicException('ambiguous person accepted');
} catch (RuntimeException $error) {
    $check($error->getMessage()==='AI_OBJECT_DETAIL_SELECTION_REQUIRED',
        'an unnumbered continuation never selects the first of several people');
}
$second=$resolver->resolve($personQuery,$many,['view'=>'summary','target'=>'single','ordinal'=>2]);
$check(($second['selection_ref']??null)==='person:8','explicit ordinal stays inside verified display order');

// The gateway compiles a pure detail continuation directly into one ordinary
// registered summary plan, preserving the preceding metric, period and scope.
$gatewayClass=new ReflectionClass(AiGatewayServices::class);
$gateway=$gatewayClass->newInstanceWithoutConstructor();
$compile=$gatewayClass->getMethod('compileObjectDetailContinuation');
$understanding=['goal'=>'继续查看','status'=>'understood','requirements'=>[[
    'id'=>'r1','meaning'=>'展开上一对象','fields'=>['object_detail'],
    'values'=>['object_detail'=>['view'=>'summary','target'=>'single','ordinal'=>null]],
    'evidence'=>[['message_id'=>'current','quote'=>'展开看看']],
]]];
$compiled=$compile->invoke($gateway,$understanding,['query'=>$personQuery,'view'=>$personView,
    'meaning'=>['presentation_origin'=>'customer_or_verified_context']],'screen');
$query=$compiled['plan']['query']??[];
$check(($compiled['kind']??null)==='plan'&&($query['query_shape']??null)==='summary'
    &&($query['metric_codes']??null)===['staff_sales_yeji']
    &&($query['business_filters']??null)===['object_kind'=>'person','selection_ref'=>'person:7']
    &&array_key_exists('ranking',$query)&&$query['ranking']===null,
    'detail continuation skips binding and changes only ranking into stable-object summary');

$compiledStore=$compile->invoke($gateway,$understanding,['query'=>$storeQuery,'view'=>$storeView,
    'meaning'=>['presentation_origin'=>'customer_or_verified_context']],'screen');
$storeDetailQuery=$compiledStore['plan']['query']??[];
$check(($storeDetailQuery['query_shape']??null)==='breakdown'
    &&($storeDetailQuery['store_ids']??null)===[3]
    &&($storeDetailQuery['business_filters']??null)===['object_kind'=>'store']
    &&($compiledStore['plan']['workflow_code']??null)==='wf_performance_breakdown',
    'store detail stays a one-row named breakdown instead of an unlabeled total');

// Repeated continuations must retain one verified identity after the ranking
// has become a summary/breakdown. Cohorts and mismatched rows remain rejected.
$summaryView=['query'=>$query,'personnel_selection_label'=>'测试员工','results'=>[]];
$again=$resolver->resolve($query,$summaryView,['view'=>'summary','target'=>'single','ordinal'=>null]);
$check(($again['selection_ref']??null)==='person:7','person summary supports a second continuation');
$detailView=['query'=>$storeDetailQuery,'results'=>[['object_kind'=>'store','has_more'=>false,
    'rows'=>[['entity_id'=>3,'entity_name'=>'测试门店','amount_cents'=>998800]]]]];
$again=$resolver->resolve($storeDetailQuery,$detailView,['view'=>'summary','target'=>'single','ordinal'=>null]);
$check(($again['store_id']??null)===3,'single-store breakdown supports a second continuation');
foreach ([$personView,$summaryView,$storeView,$detailView] as $singleton) {
    $one=$resolver->resolve($singleton['query'],$singleton,['view'=>'summary','target'=>'single','ordinal'=>null]);
    $set=$resolver->resolve($singleton['query'],$singleton,['view'=>'summary','target'=>'set','ordinal'=>null]);
    $check($one===$set,'a verified singleton set is the same target as one object');
}
try {$resolver->resolve($personQuery,$many,['view'=>'summary','target'=>'set','ordinal'=>null]);
    throw new LogicException('plural selection collapsed');
} catch (RuntimeException $error) {$check($error->getMessage()==='AI_OBJECT_DETAIL_SELECTION_REQUIRED','plural target never collapses to the first person');}
$recompiled=$compile->invoke($gateway,$understanding,['query'=>$storeDetailQuery,'view'=>$detailView],'screen');
$check($recompiled['plan']['query']===$storeDetailQuery,'repeated store continuation preserves metrics dates and scope');
$invalidSummary=$summaryView;$invalidSummary['query']['business_filters']['selection_ref']='cohort:metric_fact_participants';
$invalidStore=$detailView;$invalidStore['results'][0]['rows'][0]['entity_id']=99;
$multipleStores=$detailView;$multipleStores['query']['store_ids']=[3,4];
$truncatedStore=$detailView;$truncatedStore['results'][0]['has_more']=true;
foreach ([[$invalidSummary,'AI_OBJECT_DETAIL_NOT_READY'],[$invalidStore,'AI_RESULT_REFERENCE_UNAVAILABLE'],
    [$multipleStores,'AI_OBJECT_DETAIL_NOT_READY'],[$truncatedStore,'AI_RESULT_REFERENCE_UNAVAILABLE']] as [$view,$reason]) {
    try {$resolver->resolve($view['query'],$view,['view'=>'summary','target'=>'single','ordinal'=>null]);
        throw new LogicException('unverified singleton accepted');
    } catch (RuntimeException $error) {$check($error->getMessage()===$reason,'repeated detail protects '.$reason);}
}
try {$resolver->resolve($query,$summaryView,['view'=>'summary','target'=>'single','ordinal'=>2]);
    throw new LogicException('summary ordinal escaped');
} catch (RuntimeException $error) {$check($error->getMessage()==='AI_RESULT_REFERENCE_UNAVAILABLE','summary ordinal cannot escape the selected object');}

try {$compile->invoke($gateway,$understanding,null,'screen');throw new LogicException('context-free detail accepted');}
catch (RuntimeException $error) {$check($error->getMessage()==='AI_CONTEXT_REQUIRED',
    'a context-free continuation fails immediately instead of entering metric binding');}
$wrongObject=$understanding;
$wrongObject['requirements'][0]['fields'][]='object_kind';
$wrongObject['requirements'][0]['values']['object_kind']='store';
try {$compile->invoke($gateway,$wrongObject,['query'=>$personQuery,'view'=>$personView],'screen');
    throw new LogicException('contradictory detail object accepted');}
catch (RuntimeException $error) {$check($error->getMessage()==='AI_RESULT_REFERENCE_UNAVAILABLE',
    'a stated object cannot overwrite the object proven by the previous result');}

$progress=$gatewayClass->getMethod('progressText');
$outcome=(new ReflectionClass(app\services\ai\execution\AiRunStore::class))->getMethod('outcomeClass');
foreach (['AI_OBJECT_DETAIL_SELECTION_REQUIRED','AI_OBJECT_DETAIL_NOT_READY'] as $reason) {
    $run=['status'=>'FAILED','reason'=>$reason];
    $check($progress->invoke($gateway,$run)!=='本次查询未完成，系统没有读取或计算数据，请稍后重试。'
        &&$outcome->invoke(null,$run)==='neutral','detail boundary is actionable rather than a technical failure');
}

echo "object-detail-continuation: {$checks} checks passed\n";
