<?php
// Result references are resolved from the prior encrypted read view, never
// from a newly issued ranking or a model-supplied name/ID.
require __DIR__.'/r6-gateway-harness.php';

// A previous question is background only. It cannot itself authorize a new
// narrowing to one row of a prior ranking.
$safe=['question'=>'改成今天','evidence_messages'=>[
    ['id'=>'current','text'=>'改成今天'],['id'=>'recent_1','text'=>'刚才第一个门店'],
]];
$historyOnlyReference=['goal'=>'查看刚才第一个门店','status'=>'understood','requirements'=>[
    ['id'=>'r1','meaning'=>'引用刚才第一个门店','fields'=>['result_reference'],
        'values'=>['result_reference'=>['group'=>'top','ordinal'=>1]],
        'evidence'=>[['message_id'=>'recent_1','quote'=>'刚才第一个门店']]],
]];
try {
    \app\services\ai\contract\AiIntentUnderstandingContract::normalize($historyOnlyReference,$safe);
    throw new RuntimeException('history-only result reference was accepted');
} catch (\app\services\ai\contract\AiContractException $error) {
    if (($error->diagnostic()['predicate']??null)!=='values:result_reference_current_evidence') throw $error;
}
echo "PASS result reference needs current-turn evidence\n";

// R51: a singular displayed store may scope a new population, but a requested
// top-one limit cannot authorize silently choosing one of several tied rows.
$storeQuery=['query_shape'=>'ranking','business_filters'=>['object_kind'=>'store']];
$singleView=['results'=>[['rows'=>['top'=>[['store_id'=>2]],'bottom'=>[]]]]];
$resolver=\app\services\ai\context\ResultReferenceResolver::class;
if ($resolver::soleReference($storeQuery,$singleView)!==['group'=>'top','ordinal'=>1]) {
    throw new RuntimeException('unique displayed store reference missing');
}
$tiedView=$singleView;$tiedView['results'][0]['rows']['top'][]=['store_id'=>3];
if ($resolver::soleReference($storeQuery,$tiedView)!==null
    || $resolver::soleReference(['query_shape'=>'summary'],$singleView)!==null) {
    throw new RuntimeException('ambiguous or nonranking result became a singular reference');
}
$storeReference=$resolver::resolve($storeQuery,$singleView,['group'=>'top','ordinal'=>1]);
foreach (['person','project','product'] as $subject) {
    $filters=['object_kind'=>$subject,'selection_ref'=>'fixture:requested'];
    $scoped=\app\services\ai\context\IntentContextMerger::applyResultReference(
        ['store_ids'=>[1,2],'business_filters'=>$filters],$storeReference
    );
    if ($scoped['store_ids']!==[2] || $scoped['business_filters']!==$filters) {
        throw new RuntimeException('store reference overwrote the new analytical subject: '.$subject);
    }
}
echo "PASS R51 unique result and cross-subject store scope\n";

// A quantity phrased in ordinary language must not be overwritten by the
// registry's monetary first-answer default before semantic verification.
$policy=(new ReflectionClass(\app\services\ai\AiGatewayServices::class))->newInstanceWithoutConstructor();
$method=new ReflectionMethod($policy,'applyRegisteredRankDefaultPolicy');
$measurement=['status'=>'understood','requirements'=>[
    ['id'=>'r1','fields'=>['metric_codes'],'values'=>['metric_terms'=>['做的单数']]],
]];
$quantityIntent=['operation'=>'ranking','object_kind'=>'person','object_term'=>'',
    'metric_codes'=>['staff_project_num'],'needs_metric_choice'=>false,'recommended_initial_answer'=>false];
$monetaryDefault=[['metric_code'=>'staff_sales_yeji','default_rank_object_kinds'=>['person']]];
if ($method->invoke($policy,$quantityIntent,$measurement,$monetaryDefault)!==$quantityIntent) {
    throw new RuntimeException('ordinary quantity was overwritten by monetary ranking default');
}
echo "PASS R51 customer measurement survives registry default policy\n";

$storeReferenceMethod=new ReflectionMethod($policy,'currentNamedStoreReference');
$storeEvidence=['requirements'=>[['id'=>'r1','fields'=>['store_term'],'values'=>['store_term'=>'这个门店'],
    'evidence'=>[['message_id'=>'current','quote'=>'门店 [local_condition_1] 这个门店的技师']]]]];
$storeSafe=['outbound'=>['question'=>'门店 [local_condition_1] 这个门店的技师'],'local_conditions'=>['local_condition_1'=>'合成门店']];
$storeKinds=['local_condition_1'=>'store'];
if ($storeReferenceMethod->invoke($policy,$storeEvidence,$storeSafe,$storeKinds)!=='[local_condition_1]') throw new RuntimeException('current named scope lost its uniquely cited store token');
$shortStoreEvidence=$storeEvidence;$shortStoreEvidence['requirements'][0]['evidence'][0]['quote']='这个门店';
if ($storeReferenceMethod->invoke($policy,$shortStoreEvidence,$storeSafe,$storeKinds)!=='[local_condition_1]')
    throw new RuntimeException('one current named store was left unresolved only because model evidence quoted its generic reference');
$roleMisfiledAsStore=$storeEvidence;
$roleMisfiledAsStore['requirements'][0]['values']['store_term']='[local_condition_2]';
$roleMisfiledAsStore['requirements'][0]['evidence'][0]['quote']='[local_condition_2]';
$storeAndRole=$storeSafe;$storeAndRole['outbound']['question'].=' 技师 [local_condition_2]';
$storeAndRole['local_conditions']['local_condition_2']='合成岗位';
if ($storeReferenceMethod->invoke($policy,$roleMisfiledAsStore,$storeAndRole,
    ['local_condition_1'=>'store','local_condition_2'=>'position'])!=='[local_condition_1]')
    throw new RuntimeException('an accepted current store restriction must not consume the separate role token');
$twoStores=$storeSafe;$twoStores['outbound']['question'].=' 和 [local_condition_2]';
$twoStores['local_conditions']['local_condition_2']='另一合成门店';
if ($storeReferenceMethod->invoke($policy,$shortStoreEvidence,$twoStores,
    ['local_condition_1'=>'store','local_condition_2'=>'store'])!==null)
    throw new RuntimeException('generic store evidence must not select one of multiple named stores');
$oldStoreEvidence=$storeEvidence;$oldStoreEvidence['requirements'][0]['evidence'][0]['message_id']='recent_1';
if ($storeReferenceMethod->invoke($policy,$oldStoreEvidence,$storeSafe,$storeKinds)!==null) throw new RuntimeException('historical store evidence incorrectly selected a current location');
if ($storeReferenceMethod->invoke($policy,$storeEvidence,$storeSafe,['local_condition_1'=>'member'])!==null) throw new RuntimeException('member identity incorrectly became a store scope');
$twoStores=$storeSafe;$twoStores['outbound']['question'].=' [local_condition_2]';$twoStores['local_conditions']['local_condition_2']='另一门店';
$twoEvidence=$storeEvidence;$twoEvidence['requirements'][0]['evidence'][0]['quote']=$twoStores['outbound']['question'];
if ($storeReferenceMethod->invoke($policy,$twoEvidence,$twoStores,$storeKinds+['local_condition_2'=>'store'])!==null) throw new RuntimeException('two named stores were silently reduced to one');
echo "PASS R51 current named-store reference requires unique typed evidence\n";

$h=null;
try {
    $h=new R6GatewayHarness(3,[1,2],'platform');
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'ranking','metric_codes'=>['cash_performance'],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'top','limit'=>5],'periods'=>[['kind'=>'date_range','start'=>'2026-09-01','end'=>'2026-09-08']],'scope'=>'authorized','unresolved_fragments'=>[]];
    $source=$h->start('门店现金业绩排行');
    if ($source['status']!=='COMPLETED') throw new RuntimeException('ranking source did not complete');
    $delta=array_fill_keys(\app\services\ai\contract\AiIntentResultContract::DELTA_FIELDS,'inherit');
    $delta['operation']='replace';$delta['ranking_direction']='clear';$delta['ranking_limit']='clear';
    $h->understandingOverride=['goal'=>'查看刚才第一个门店的汇总','status'=>'understood','requirements'=>[
        ['id'=>'r1','meaning'=>'引用刚才第一个门店','fields'=>['result_reference'],
            'values'=>['result_reference'=>['group'=>'top','ordinal'=>1]],
            'evidence'=>[['message_id'=>'current','quote'=>'刚才第一个门店']]],
    ]];
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified','context_delta'=>$delta,'result_reference'=>['group'=>'top','ordinal'=>1],'unresolved_fragments'=>[]];
    // R51: binding may omit a reference that understanding already accepted.
    // Its verified position must be carried, not reinterpreted or re-queried.
    $h->semanticIntent['result_reference']=null;
    $answer=$h->start('刚才第一个门店的汇总',$source['answer']['context_ref']);
    if ($answer['status']!=='COMPLETED') throw new RuntimeException('referenced query did not complete');
    $evidence=$h->private->read($h->row($answer)['evidence_ref']);
    if (($evidence['query']['store_ids']??null)!==[1]) throw new RuntimeException('reference did not bind original stable store key');
    // A top-and-bottom answer has two independently displayed rank groups.
    // "First" must remain scoped to the group the customer named, rather
    // than depending on the storage order of those groups.
    $query=['query_shape'=>'ranking','business_filters'=>['object_kind'=>'store']];
    $view=['results'=>[['rows'=>[
        'bottom'=>[['store_id'=>2],['store_id'=>3]],
        'top'=>[['store_id'=>1],['store_id'=>4]],
    ]]]];
    $top=\app\services\ai\context\ResultReferenceResolver::resolve($query,$view,['group'=>'top','ordinal'=>1]);
    $bottom=\app\services\ai\context\ResultReferenceResolver::resolve($query,$view,['group'=>'bottom','ordinal'=>1]);
    if (($top['store_ids']??null)!==[1] || ($bottom['store_ids']??null)!==[2]) {
        throw new RuntimeException('reference did not preserve the displayed ranking group');
    }
    $personQuery=['query_shape'=>'ranking','store_ids'=>[1],'business_filters'=>['object_kind'=>'person','selection_ref'=>'position:2']];
    $personView=['results'=>[
        ['rows'=>['top'=>[['employee_id'=>7]]]],
    ]];
    $person=\app\services\ai\context\ResultReferenceResolver::resolve($personQuery,$personView,['group'=>'top','ordinal'=>1]);
    $constraints=\app\services\ai\context\IntentContextMerger::applyResultReference(
        ['store_ids'=>[1],'business_filters'=>['object_kind'=>'person','selection_ref'=>'position:2']],$person
    );
    if (($constraints['store_ids']??null)!==[1]
        || ($constraints['business_filters']??null)!==['object_kind'=>'person','selection_ref'=>'person:7']) {
        throw new RuntimeException('person result reference changed the confirmed store scope');
    }
    echo "PASS result reference snapshot binding\n";
} finally { if ($h) $h->close(); }

$h=null;
try {
    $h=new R6GatewayHarness(3,[1,2],'platform');
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'ranking','metric_codes'=>['cash_performance'],'action_codes'=>[],
        'needs_metric_choice'=>false,'ranking'=>['direction'=>'top','limit'=>5],
        'periods'=>[['kind'=>'date_range','start'=>'2026-09-01','end'=>'2026-09-08']],'scope'=>'authorized','unresolved_fragments'=>[]];
    $source=$h->start('门店现金业绩排行');
    if ($source['status']!=='COMPLETED') throw new RuntimeException('semantic-review source did not complete');
    $delta=array_fill_keys(\app\services\ai\contract\AiIntentResultContract::DELTA_FIELDS,'inherit');$delta['periods']='replace';
    // This deliberately malformed understanding turns a vague presentation
    // word into an ordinal result reference. Unlike a closed calendar edit,
    // the phrase needs model understanding; the independent semantic gate
    // must therefore stop the invented row before Reader is called.
    $h->understandingOverride=['goal'=>'只修改为今天','status'=>'understood','requirements'=>[
        ['id'=>'r1','meaning'=>'引用前次排行第一家门店','fields'=>['result_reference'],
            'values'=>['result_reference'=>['group'=>'top','ordinal'=>1]],
            'evidence'=>[['message_id'=>'current','quote'=>'表现']]],
        ['id'=>'r2','meaning'=>'将时间修改为今天','fields'=>['periods'],
            'values'=>['periods'=>[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]]],
            'evidence'=>[['message_id'=>'current','quote'=>'今天']]],
    ]];
    $h->bindingVerificationOverride=['decision'=>'reject','rejected_requirement_ids'=>['r1']];
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'ranking','metric_codes'=>[],'action_codes'=>[],
        'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],
        'periods'=>[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]],'scope'=>'unspecified',
        'context_delta'=>$delta,'result_reference'=>['group'=>'top','ordinal'=>1],'unresolved_fragments'=>[]];
    $queries=$h->queries;$blocked=$h->start('今天表现怎么样',$source['answer']['context_ref']);
    if (($blocked['status']??null)!=='FAILED' || ($blocked['reason']??null)!=='AI_BINDING_SEMANTIC_REJECTED' || $h->queries!==$queries) {
        throw new RuntimeException('semantic reviewer did not stop invented result reference before query');
    }
    echo "PASS result reference is semantically reviewed before query\n";
} finally { if ($h) $h->close(); }

$h=null;
try {
    $h=new R6GatewayHarness(3,[1,2],'platform');
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'ranking','metric_codes'=>['cash_performance'],'action_codes'=>[],'needs_metric_choice'=>false,
        'ranking'=>['direction'=>'top','limit'=>5],'periods'=>[['kind'=>'date_range','start'=>'2026-09-01','end'=>'2026-09-08']],'scope'=>'authorized','unresolved_fragments'=>[]];
    $source=$h->start('门店现金业绩排行');
    if ($source['status']!=='COMPLETED') throw new RuntimeException('range source did not complete');
    $delta=array_fill_keys(\app\services\ai\contract\AiIntentResultContract::DELTA_FIELDS,'inherit');$delta['periods']='replace';
    $h->understandingOverride=['goal'=>'只修改为今天','status'=>'understood','requirements'=>[
        ['id'=>'r1','meaning'=>'将时间修改为今天','fields'=>['periods'],
            'values'=>['periods'=>[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]]],
            'evidence'=>[['message_id'=>'current','quote'=>'改成今天']]],
    ]];
    // This is a deliberately malformed binding response: the customer only
    // changed time, but the binding attempts to narrow the old ranking to its
    // first row. The gateway must reject before issuing a new Reader query.
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'ranking','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,
        'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]],'scope'=>'unspecified',
        'context_delta'=>$delta,'result_reference'=>['group'=>'top','ordinal'=>1],'unresolved_fragments'=>[]];
    // A verified time-only fast path deliberately does not call the binding
    // model, so this injected binding candidate is never admitted.  The
    // secure outcome is a normal inherited query without a result-row
    // selection, not a synthetic failure merely because the unused fixture
    // carried one.  Compare only executable constraints; the period itself
    // is expected to change.
    $sourceEvidence=$h->private->read($h->row($source)['evidence_ref']);
    $queries=$h->queries;$models=$h->models;$continued=$h->start('改成今天',$source['answer']['context_ref']);
    $continuedEvidence=$h->private->read($h->row($continued)['evidence_ref']);
    if (($continued['status']??null)!=='COMPLETED' || $h->queries!==$queries+1 || $h->models!==$models
        || ($continuedEvidence['query']['store_ids']??null)!==($sourceEvidence['query']['store_ids']??null)
        || ($continuedEvidence['query']['business_filters']??null)!==($sourceEvidence['query']['business_filters']??null)
        || isset($continuedEvidence['query']['business_filters']['selection_ref'])) {
        throw new RuntimeException('time-only fast path accepted an unused result reference');
    }
    echo "PASS time-only fast path ignores an unused result reference\n";
} finally { if ($h) $h->close(); }
