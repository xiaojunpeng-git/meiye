<?php
// Read-only business audit: real gateway with injected model outputs and
// synthetic facts. Never boots the application or connects to a customer DB.
require __DIR__.'/r6-gateway-harness.php';

$failures=0;
foreach (['inherit','clear'] as $scopeDecision) {
    $h=new R6GatewayHarness(3,[1,2],'platform');
    try {
        $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'ranking',
            'metric_codes'=>['cash_performance'],'action_codes'=>[],'needs_metric_choice'=>false,
            'ranking'=>['direction'=>'top','limit'=>5],
            'periods'=>[['kind'=>'date_range','start'=>'2026-09-01','end'=>'2026-09-08']],
            'scope'=>'authorized','unresolved_fragments'=>[]];
        $ranking=$h->start('门店现金业绩排行');
        r6hCheck($ranking['status']==='COMPLETED','audit source ranking completed');
        $delta=array_fill_keys(\app\services\ai\contract\AiIntentResultContract::DELTA_FIELDS,'inherit');
        $delta['operation']='replace';$delta['ranking_direction']='clear';$delta['ranking_limit']='clear';
        $h->understandingOverride=['goal'=>'查看刚才第一家门店的汇总','status'=>'understood','requirements'=>[
            ['id'=>'r1','meaning'=>'引用刚才第一家门店','fields'=>['result_reference'],
                'values'=>['result_reference'=>['group'=>'top','ordinal'=>1]],
                'evidence'=>[['message_id'=>'current','quote'=>'刚才第一家门店']]],
        ]];
        $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary',
            'metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,
            'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified',
            'context_delta'=>$delta,'result_reference'=>['group'=>'top','ordinal'=>1],'unresolved_fragments'=>[]];
        $source=$h->start('刚才第一家门店的汇总',$ranking['answer']['context_ref']);
        r6hCheck($source['status']==='COMPLETED','audit single-store source completed');
        $before=$h->private->read($h->row($source)['evidence_ref']);
        r6hCheck($before['query']['store_ids']===[1],'audit source is restricted to one store');

        $delta=array_fill_keys(\app\services\ai\contract\AiIntentResultContract::DELTA_FIELDS,'inherit');
        $delta['periods']='replace';$delta['store_scope']=$scopeDecision;
        $periods=[['kind'=>'date_range','start'=>'2026-09-09','end'=>'2026-09-09']];
        $h->understandingOverride=['goal'=>'只修改查询日期','status'=>'understood','requirements'=>[
            ['id'=>'r1','meaning'=>'查询九月九日','fields'=>['periods'],'values'=>['periods'=>$periods],
                'evidence'=>[['message_id'=>'current','quote'=>'9月9日']]],
        ]];
        $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary',
            'metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,
            'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>$periods,'scope'=>'unspecified',
            'context_delta'=>$delta,'unresolved_fragments'=>[]];
        $queries=$h->queries;$models=$h->models;
        $answer=$h->start('改成9月9日',$source['answer']['context_ref']);
        $row=$h->row($answer);
        $after=$row['evidence_ref']?$h->private->read($row['evidence_ref']):[];
        $stores=$after['query']['store_ids']??null;
        // The ordinary continuation must execute the retained scope. A
        // fabricated removal must be rejected or require confirmation before
        // any new Reader call; the server must not reinterpret it silently.
        $pass=$scopeDecision==='inherit'
            ? $answer['status']==='COMPLETED' && $stores===[1]
            : in_array($answer['status'],['FAILED','WAITING_CLARIFICATION'],true) && $h->queries===$queries;
        if (!$pass) $failures++;
        echo json_encode(['case'=>$scopeDecision,'pass'=>$pass,'source_store_ids'=>[1],
            'status'=>$answer['status'],'reason'=>$answer['reason']??null,'query_store_ids'=>$stores,
            'model_calls'=>$h->models-$models,'reader_transactions'=>$h->queries-$queries],
            JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
    } finally {
        $h->close();
    }
}

// A historical request for all stores is context for understanding only.  It
// must not let a date-only follow-up remove the trusted one-store restriction.
$h=new R6GatewayHarness(3,[1,2],'platform');
try {
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'ranking',
        'metric_codes'=>['cash_performance'],'action_codes'=>[],'needs_metric_choice'=>false,
        'ranking'=>['direction'=>'top','limit'=>5],
        'periods'=>[['kind'=>'date_range','start'=>'2026-09-01','end'=>'2026-09-08']],
        'scope'=>'authorized','unresolved_fragments'=>[]];
    $ranking=$h->start('门店现金业绩排行');
    $delta=array_fill_keys(\app\services\ai\contract\AiIntentResultContract::DELTA_FIELDS,'inherit');
    $delta['operation']='replace';$delta['ranking_direction']='clear';$delta['ranking_limit']='clear';
    $h->understandingOverride=['goal'=>'查看刚才第一家门店的汇总','status'=>'understood','requirements'=>[
        ['id'=>'r1','meaning'=>'引用刚才第一家门店','fields'=>['result_reference'],
            'values'=>['result_reference'=>['group'=>'top','ordinal'=>1]],
            'evidence'=>[['message_id'=>'current','quote'=>'刚才第一家门店']]],
    ]];
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary',
        'metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,
        'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified',
        'context_delta'=>$delta,'result_reference'=>['group'=>'top','ordinal'=>1],'unresolved_fragments'=>[]];
    $source=$h->start('刚才第一家门店的汇总',$ranking['answer']['context_ref']);
    $delta=array_fill_keys(\app\services\ai\contract\AiIntentResultContract::DELTA_FIELDS,'inherit');
    $delta['periods']='replace';$delta['scope']='replace';$delta['store_scope']='clear';
    $periods=[['kind'=>'date_range','start'=>'2026-09-09','end'=>'2026-09-09']];
    $h->understandingOverride=['goal'=>'只修改查询日期','status'=>'understood','requirements'=>[
        ['id'=>'r1','meaning'=>'查询九月九日','fields'=>['periods'],'values'=>['periods'=>$periods],
            'evidence'=>[['message_id'=>'current','quote'=>'9月9日']]],
        ['id'=>'r2','meaning'=>'历史上提过全部门店','fields'=>['scope'],'values'=>['scope'=>'authorized'],
            'evidence'=>[['message_id'=>'recent_1','quote'=>'全部门店']]],
    ]];
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary',
        'metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,
        'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>$periods,'scope'=>'authorized',
        'context_delta'=>$delta,'unresolved_fragments'=>[]];
    $queries=$h->queries;
    $answer=$h->start('改成9月9日',$source['answer']['context_ref'],[
        ['question'=>'全部门店现金业绩排行','answer'=>''],
        ['question'=>'刚才第一家门店的汇总','answer'=>''],
    ]);
    $pass=in_array($answer['status'],['FAILED','WAITING_CLARIFICATION'],true) && $h->queries===$queries;
    if (!$pass) $failures++;
    echo json_encode(['case'=>'historical_authorized_scope','pass'=>$pass,'status'=>$answer['status'],
        'reason'=>$answer['reason']??null,'reader_transactions'=>$h->queries-$queries],
        JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
} finally {
    $h->close();
}

// Evidence is attached to a requirement, so a structurally valid current
// date excerpt cannot be reused for scope. The request is rejected before
// Reader execution; a server form would only make the customer repair a
// condition that they never supplied.
$h=new R6GatewayHarness(3,[1,2],'platform');
try {
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'ranking',
        'metric_codes'=>['cash_performance'],'action_codes'=>[],'needs_metric_choice'=>false,
        'ranking'=>['direction'=>'top','limit'=>5],
        'periods'=>[['kind'=>'date_range','start'=>'2026-09-01','end'=>'2026-09-08']],
        'scope'=>'authorized','unresolved_fragments'=>[]];
    $ranking=$h->start('门店现金业绩排行');
    $delta=array_fill_keys(\app\services\ai\contract\AiIntentResultContract::DELTA_FIELDS,'inherit');
    $delta['operation']='replace';$delta['ranking_direction']='clear';$delta['ranking_limit']='clear';
    $h->understandingOverride=['goal'=>'查看刚才第一家门店的汇总','status'=>'understood','requirements'=>[
        ['id'=>'r1','meaning'=>'引用刚才第一家门店','fields'=>['result_reference'],
            'values'=>['result_reference'=>['group'=>'top','ordinal'=>1]],
            'evidence'=>[['message_id'=>'current','quote'=>'刚才第一家门店']]],
    ]];
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary',
        'metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,
        'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified',
        'context_delta'=>$delta,'result_reference'=>['group'=>'top','ordinal'=>1],'unresolved_fragments'=>[]];
    $source=$h->start('刚才第一家门店的汇总',$ranking['answer']['context_ref']);
    $delta=array_fill_keys(\app\services\ai\contract\AiIntentResultContract::DELTA_FIELDS,'inherit');
    $delta['periods']='replace';$delta['scope']='replace';$delta['store_scope']='clear';
    $periods=[['kind'=>'date_range','start'=>'2026-09-09','end'=>'2026-09-09']];
    $h->understandingOverride=['goal'=>'改成九月九日','status'=>'understood','requirements'=>[
        ['id'=>'r1','meaning'=>'九月九日的全部门店','fields'=>['periods','scope'],
            'values'=>['periods'=>$periods,'scope'=>'authorized'],
            'evidence'=>[['message_id'=>'current','quote'=>'9月9日']]],
    ]];
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary',
        'metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,
        'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>$periods,'scope'=>'authorized',
        'context_delta'=>$delta,'unresolved_fragments'=>[]];
    // Structural evidence merely proves where the excerpt came from.  The
    // independent semantic reviewer must reject a date excerpt masquerading
    // as an authorized-scope request; the server deliberately has no phrase
    // matcher for that judgement.
    $h->bindingVerificationOverride=['decision'=>'reject','rejected_requirement_ids'=>['r1']];
    $queries=$h->queries;$models=$h->models;
    $answer=$h->start('改成9月9日',$source['answer']['context_ref']);
    $pass=$answer['status']==='FAILED' && $h->queries===$queries;
    if (!$pass) $failures++;
    echo json_encode(['case'=>'date_evidence_cannot_expand_scope','pass'=>$pass,'status'=>$answer['status'],
        'reason'=>$answer['reason']??null,'model_calls'=>$h->models-$models,
        'reader_transactions'=>0],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
} finally {
    $h->close();
}

// The guard must not turn into a blanket ban. A customer who explicitly asks
// to return to the authorized range is allowed to do so, and the Reader sees
// the canonical empty request list that it binds to current authority.
$h=new R6GatewayHarness(3,[1,2],'platform');
try {
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'ranking',
        'metric_codes'=>['cash_performance'],'action_codes'=>[],'needs_metric_choice'=>false,
        'ranking'=>['direction'=>'top','limit'=>5],
        'periods'=>[['kind'=>'date_range','start'=>'2026-09-01','end'=>'2026-09-08']],
        'scope'=>'authorized','unresolved_fragments'=>[]];
    $ranking=$h->start('门店现金业绩排行');
    $delta=array_fill_keys(\app\services\ai\contract\AiIntentResultContract::DELTA_FIELDS,'inherit');
    $delta['operation']='replace';$delta['ranking_direction']='clear';$delta['ranking_limit']='clear';
    $h->understandingOverride=['goal'=>'查看刚才第一家门店的汇总','status'=>'understood','requirements'=>[
        ['id'=>'r1','meaning'=>'引用刚才第一家门店','fields'=>['result_reference'],
            'values'=>['result_reference'=>['group'=>'top','ordinal'=>1]],
            'evidence'=>[['message_id'=>'current','quote'=>'刚才第一家门店']]],
    ]];
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary',
        'metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,
        'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified',
        'context_delta'=>$delta,'result_reference'=>['group'=>'top','ordinal'=>1],'unresolved_fragments'=>[]];
    $source=$h->start('刚才第一家门店的汇总',$ranking['answer']['context_ref']);
    $delta=array_fill_keys(\app\services\ai\contract\AiIntentResultContract::DELTA_FIELDS,'inherit');
    $delta['periods']='replace';$delta['scope']='replace';$delta['store_scope']='clear';
    $periods=[['kind'=>'date_range','start'=>'2026-09-09','end'=>'2026-09-09']];
    $h->understandingOverride=['goal'=>'查看全部门店的九月九日数据','status'=>'understood','requirements'=>[
        ['id'=>'r1','meaning'=>'查看全部门店','fields'=>['scope'],'values'=>['scope'=>'authorized'],
            'evidence'=>[['message_id'=>'current','quote'=>'全部门店']]],
        ['id'=>'r2','meaning'=>'查询九月九日','fields'=>['periods'],'values'=>['periods'=>$periods],
            'evidence'=>[['message_id'=>'current','quote'=>'9月9日']]],
    ]];
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary',
        'metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>false,
        'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>$periods,'scope'=>'authorized',
        'context_delta'=>$delta,'unresolved_fragments'=>[]];
    $queries=$h->queries;$answer=$h->start('改为全部门店，9月9日',$source['answer']['context_ref']);
    $evidence=$answer['status']==='COMPLETED'?$h->private->read($h->row($answer)['evidence_ref']):[];
    $pass=$answer['status']==='COMPLETED' && ($evidence['query']['store_ids']??null)===[] && $h->queries===$queries+1;
    if (!$pass) $failures++;
    echo json_encode(['case'=>'explicit_authorized_scope','pass'=>$pass,'status'=>$answer['status'],
        'query_store_ids'=>$evidence['query']['store_ids']??null,'reader_transactions'=>$h->queries-$queries],
        JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
} finally {
    $h->close();
}
echo 'AUDIT_FAILURES='.$failures.PHP_EOL;
exit($failures>0?1:0);
