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
    // This deliberately malformed understanding uses the current date phrase
    // as evidence for an ordinal. The structural contract cannot decide the
    // sentence meaning, so the independent semantic gate must stop it before
    // the private snapshot is resolved or Reader is called.
    $h->understandingOverride=['goal'=>'只修改为今天','status'=>'understood','requirements'=>[
        ['id'=>'r1','meaning'=>'引用前次排行第一家门店','fields'=>['result_reference'],
            'values'=>['result_reference'=>['group'=>'top','ordinal'=>1]],
            'evidence'=>[['message_id'=>'current','quote'=>'改成今天']]],
        ['id'=>'r2','meaning'=>'将时间修改为今天','fields'=>['periods'],
            'values'=>['periods'=>[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]]],
            'evidence'=>[['message_id'=>'current','quote'=>'今天']]],
    ]];
    $h->bindingVerificationOverride=['decision'=>'reject','rejected_requirement_ids'=>['r1']];
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'ranking','metric_codes'=>[],'action_codes'=>[],
        'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],
        'periods'=>[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]],'scope'=>'unspecified',
        'context_delta'=>$delta,'result_reference'=>['group'=>'top','ordinal'=>1],'unresolved_fragments'=>[]];
    $queries=$h->queries;$blocked=$h->start('改成今天',$source['answer']['context_ref']);
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
            'evidence'=>[['message_id'=>'current','quote'=>'今天']]],
    ]];
    // This is a deliberately malformed binding response: the customer only
    // changed time, but the binding attempts to narrow the old ranking to its
    // first row. The gateway must reject before issuing a new Reader query.
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'ranking','metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,
        'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]],'scope'=>'unspecified',
        'context_delta'=>$delta,'result_reference'=>['group'=>'top','ordinal'=>1],'unresolved_fragments'=>[]];
    $queries=$h->queries;$blocked=$h->start('改成今天',$source['answer']['context_ref']);
    if (($blocked['status']??null)!=='FAILED' || ($blocked['reason']??null)!=='AI_MODEL_INTENT_CONTRACT_INVALID' || $h->queries!==$queries) {
        throw new RuntimeException('unrequested result reference was not stopped before query');
    }
    echo "PASS result reference requires accepted current-request evidence\n";
} finally { if ($h) $h->close(); }
