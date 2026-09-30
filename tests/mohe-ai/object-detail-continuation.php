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
$separatePopulationDetail=$gatewayClass->getMethod('separatePopulationDetail');
$compoundUnderstanding=['goal'=>'查看会员业绩前五名及详情','status'=>'understood','requirements'=>[
    ['id'=>'r1','meaning'=>'分析会员','fields'=>['object_kind','object_relation'],
        'values'=>['object_kind'=>'member','object_relation'=>'analysis']],
    ['id'=>'r2','meaning'=>'按会员业绩衡量','fields'=>['metric_codes'],
        'values'=>['metric_codes'=>[],'metric_terms'=>['会员业绩']]],
    ['id'=>'r3','meaning'=>'取最高的前五名','fields'=>['operation','ranking'],
        'values'=>['operation'=>'ranking','ranking'=>['direction'=>'top','limit'=>5]]],
    ['id'=>'r4','meaning'=>'同时查看这些会员详情','fields'=>['object_detail'],
        'values'=>['object_detail'=>['view'=>'summary','target'=>'set','ordinal'=>null]]],
]];
[$populationUnderstanding,$detailRequest]=$separatePopulationDetail->invoke($gateway,$compoundUnderstanding);
$populationFields=array_merge(...array_column($populationUnderstanding['requirements'],'fields'));
$check($detailRequest===['view'=>'summary','target'=>'set','ordinal'=>null]
    &&!in_array('object_detail',$populationFields,true)
    &&in_array('metric_codes',$populationFields,true)&&in_array('ranking',$populationFields,true),
    'one typed member ranking plus detail request binds the population first and retains its presentation request');
$personCompound=$compoundUnderstanding;
$personCompound['requirements'][0]['values']['object_kind']='person';
[$unchangedPerson,$personDetail]=$separatePopulationDetail->invoke($gateway,$personCompound);
$check(($personDetail['_ranking_object_kind']??null)==='person' && $unchangedPerson!==$personCompound,
    'person ranking detail is retained as registered presentation, not member assets');
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
    &&($query['metric_codes']??null)===['staff_sales_yeji','staff_labor_yeji','staff_project_num',
        'staff_labor_fee','staff_service_num','staff_point_visit_num']
    &&($query['business_filters']??null)===['object_kind'=>'person','selection_ref'=>'person:7']
    &&array_key_exists('ranking',$query)&&$query['ranking']===null,
    'detail continuation skips binding and changes only ranking into stable-object summary');
$readViewClass=new ReflectionClass(app\services\query\metric\MetricReadViewServices::class);
$readView=$readViewClass->newInstanceWithoutConstructor();
$readViewClass->getProperty('clock')->setValue($readView,static function():int { return strtotime('2026-09-25 12:00:00'); });
$readerQuery=$readViewClass->getMethod('query');
$check(($readerQuery->invoke($readView,$query)['metric_codes']??null)===$query['metric_codes'],
    'the Reader admits the exact registered six-column single-person detail profile');
$forgedSix=$query;$forgedSix['metric_codes'][5]='service_people';
try {
    $readerQuery->invoke($readView,$forgedSix);
    throw new LogicException('arbitrary six-column person summary accepted');
} catch (app\services\query\metric\MetricQueryContractException $error) {
    $check($error->getErrorCode()==='METRIC_QUERY_SHAPE_UNAVAILABLE',
        'the Reader still rejects arbitrary wide person summaries');
}

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
$plural=$resolver->resolve($personQuery,$many,['view'=>'summary','target'=>'set','ordinal'=>null]);
$check(array_column($plural['objects'],'id')===[7,8],'plural target retains the complete verified display order');
$expandedMany=$many;
$expandedMany['query']['condition_set']=null;
$expandedMany['results'][0]['ranking_detail_metrics']=[['metric_code'=>'staff_labor_yeji']];
$localOrdinal=$gatewayClass->getMethod('localVerifiedOrdinalDetailUnderstanding');
$ordinalSafe=['schema_version'=>'sanitized-question-v2','question'=>'第二位具体看看',
    'has_unresolved_conditions'=>false,'server_resolved_fields'=>[],'reference_date'=>'2026-09-25',
    'recent_questions'=>[],'evidence_messages'=>[['id'=>'current','text'=>'第二位具体看看']],
    'prior_query'=>['operation'=>'ranking']];
$localOrdinalMeaning=$localOrdinal->invoke($gateway,$ordinalSafe,['local_conditions'=>[]],
    ['query'=>$expandedMany['query'],'view'=>$expandedMany]);
$check(($localOrdinalMeaning['requirements'][0]['values']['object_detail']['ordinal']??null)===2,
    'closed second-position detail uses the signed ranking without model understanding');
$localOrdinalPlan=$compile->invoke($gateway,$localOrdinalMeaning,
    ['query'=>$expandedMany['query'],'view'=>$expandedMany],'screen');
$check(($localOrdinalPlan['plan']['query']['business_filters']['selection_ref']??null)==='person:8',
    'locally admitted ordinal selects only the second verified person');
$mixedOrdinal=$ordinalSafe;$mixedOrdinal['question']='第二位销售额详情';
$mixedOrdinal['evidence_messages'][0]['text']=$mixedOrdinal['question'];
$check($localOrdinal->invoke($gateway,$mixedOrdinal,['local_conditions'=>[]],
    ['query'=>$expandedMany['query'],'view'=>$expandedMany])===null,
    'new metric wording cannot enter the ordinal-only fast path');
$outsideOrdinal=$ordinalSafe;$outsideOrdinal['question']='第三位具体看看';
$outsideOrdinal['evidence_messages'][0]['text']=$outsideOrdinal['question'];
$check($localOrdinal->invoke($gateway,$outsideOrdinal,['local_conditions'=>[]],
    ['query'=>$expandedMany['query'],'view'=>$expandedMany])===null,
    'an ordinal outside the displayed set cannot become a guessed identity');
$secondAfterSet=$understanding;
$secondAfterSet['requirements'][0]['values']['object_detail']=['view'=>'summary','target'=>'single','ordinal'=>2];
$secondAfterSet['requirements'][]=['id'=>'r2','meaning'=>'repeated prior ranking','fields'=>['operation','ranking'],
    'values'=>['operation'=>'ranking','ranking'=>$personQuery['ranking']],
    'evidence'=>[['message_id'=>'current','quote'=>'第二位具体看看']]];
$selectedAfterSet=$compile->invoke($gateway,$secondAfterSet,['query'=>$expandedMany['query'],'view'=>$expandedMany],'screen');
$check(($selectedAfterSet['plan']['query']['business_filters']['selection_ref']??null)==='person:8'
    &&array_key_exists('ranking',$selectedAfterSet['plan']['query'])
    &&$selectedAfterSet['plan']['query']['ranking']===null,
    'ordinal after expanded detail set resolves its signed second person, ignoring only an unchanged ranking echo');
$changedAfterSet=$secondAfterSet;
$changedAfterSet['requirements'][1]['values']['ranking']=['direction'=>'bottom','limit'=>2];
$check($compile->invoke($gateway,$changedAfterSet,['query'=>$expandedMany['query'],'view'=>$expandedMany],'screen')===null,
    'a changed ranking is never reclassified as a pure detail continuation');
$storeSet=$storeView;
$storeSet['results'][0]['rows']['top'][]=['store_id'=>4,'store_name'=>'另一门店','amount_cents'=>890000];
$storeSet['results'][0]['ranking_detail_metrics']=[['metric_code'=>'consume_amount']];
$setUnderstanding=$understanding;
$setUnderstanding['requirements'][0]['values']['object_detail']=['view'=>'summary','target'=>'set','ordinal'=>null];
$setUnderstanding['requirements'][]=['id'=>'r2','meaning'=>'repeated prior ranking','fields'=>['operation','ranking'],
    'values'=>['operation'=>'ranking','ranking'=>$storeQuery['ranking']],
    'evidence'=>[['message_id'=>'current','quote'=>'把这几家展开看看']]];
$storeSetCompiled=$compile->invoke($gateway,$setUnderstanding,['query'=>$storeQuery,'view'=>$storeSet],'screen');
$check(($storeSetCompiled['kind']??null)==='verified_ranking_detail',
    'plural store details replay the signed set even when the model repeats its old rank');
foreach (['summary','breakdown'] as $presentationOperation) {
    $setWithPresentationOperation=$setUnderstanding;
    $setWithPresentationOperation['requirements'][1]['values']['operation']=$presentationOperation;
    $compiledSet=$compile->invoke($gateway,$setWithPresentationOperation,
        ['query'=>$storeQuery,'view'=>$storeSet],'screen');
    $check(($compiledSet['kind']??null)==='verified_ranking_detail',
        'a model presentation operation cannot replace the signed store set: '.$presentationOperation);
}
$setWithScopeEcho=$setUnderstanding;
$setWithScopeEcho['requirements'][1]['fields'][]='scope';
$setWithScopeEcho['requirements'][1]['values']['scope']='authorized';
$check(($compile->invoke($gateway,$setWithScopeEcho,['query'=>$storeQuery,'view'=>$storeSet],'screen')['kind']??null)
        ==='verified_ranking_detail',
    'an authorised scope echo cannot force a second binding of an already signed all-store set');
$restrictedStoreQuery=$storeQuery;$restrictedStoreQuery['store_ids']=[3];
$restrictedStoreSet=$storeSet;$restrictedStoreSet['query']=$restrictedStoreQuery;
$check($compile->invoke($gateway,$setWithScopeEcho,
        ['query'=>$restrictedStoreQuery,'view'=>$restrictedStoreSet],'screen')===null,
    'an authorised scope claim cannot silently widen a selected-store ranking');
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

// R49 sale-object detail follow-ups reuse the already captured profile and
// IDs. A plural request stays a set even when just one result exists.
foreach (['project','product'] as $kind) {
    $q=$personQuery;$q['metric_codes']=['sales_amount'];$q['business_filters']=['object_kind'=>$kind];
    $v=['query'=>$q,'results'=>[['object_kind'=>$kind,'rows'=>['top'=>[
        ['entity_id'=>71,'entity_name'=>'测试对象','amount_cents'=>0]
    ]]]]];
    $set=$resolver->resolve($q,$v,['view'=>'summary','target'=>'set','ordinal'=>null]);
    $check($set['object_kind']===$kind && $set['objects']===[['id'=>71,'label'=>'测试对象']],
        $kind.' singleton plural detail keeps the original zero-valued identity');
}
echo "object-detail-continuation: {$checks} checks passed\n";
