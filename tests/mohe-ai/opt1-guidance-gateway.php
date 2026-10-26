<?php
// Platform guidance regression with the existing synthetic Run/model harness.
// No external provider or customer database is used.
require __DIR__.'/r6-gateway-harness.php';

$h=new R6GatewayHarness(5,[1],'platform');
try {
    $h->understandingOverride=['goal'=>'查看业绩','status'=>'needs_clarification','requirements'=>[[
        'id'=>'r1','meaning'=>'业绩','fields'=>['metric_codes','object_kind'],
        'values'=>['metric_terms'=>['业绩'],'object_kind'=>'store'],
        'evidence'=>[['message_id'=>'current','quote'=>'业绩']],
    ]]];
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary','metric_codes'=>[],
        'action_codes'=>[],'needs_metric_choice'=>true,'metric_choice_candidates'=>['cash_performance','consume_amount'],
        'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'authorized','unresolved_fragments'=>[]];
    $first=$h->start('业绩怎么样');
    r6hCheck(($first['status']??null)==='WAITING_CLARIFICATION','broad performance asks for meaning');
    r6hCheck(($first['clarification']['fields'][0]['key']??null)==='semantic_reply',
        'first asks for a registered query choice');
    $values=array_column($first['clarification']['fields'][0]['options'],'value');
    r6hCheck(!in_array('__retry_original__',$values,true),'platform card has no filler retry');
    $suggestion=current(array_values(array_filter($values,static fn($value):bool=>str_starts_with($value,'suggestion_'))));
    r6hCheck(is_string($suggestion),'a registered metric is offered');
    $next=$h->choose($first,['semantic_reply'=>$suggestion]);
    r6hCheck(($next['status']??null)==='WAITING_CLARIFICATION','metric choice stays in the Run');
    r6hCheck(($next['clarification']['fields'][0]['key']??null)==='start_date','unconfirmed time is asked next');
    r6hCheck(($next['run_id']??null)===($first['run_id']??null),'guidance retains the same Run');
    $date=$h->choose($next,['start_date'=>'2026-10-09','end_date'=>'2026-10-09']);
    r6hCheck(($date['status']??null)==='COMPLETED','the confirmed metric and period execute together');
    $h->understandingOverride=['goal'=>'了解门店','status'=>'needs_clarification','requirements'=>[[
        'id'=>'r1','meaning'=>'门店','fields'=>['object_kind'],'values'=>['object_kind'=>'store'],
        'evidence'=>[['message_id'=>'current','quote'=>'门店']],
    ]]];
    $store=$h->start('门店怎么样');
    r6hCheck(($store['status']??null)==='WAITING_CLARIFICATION'
        && ($store['clarification']['fields'][0]['key']??null)==='semantic_reply',
        'a store-only question asks which registered result the user wants');
    // Real providers may call an object-only question "understood" and
    // propose an overview. Neither status nor the provisional metric set is
    // customer consent to choose a measurement or period.
    $h->understandingOverride['status']='understood';
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary',
        'metric_codes'=>['cash_performance'],'action_codes'=>[], 'needs_metric_choice'=>false,
        'initial_observation'=>true,'ranking'=>['direction'=>'unspecified','limit'=>null],
        'periods'=>[],'scope'=>'authorized','unresolved_fragments'=>[]];
    $proposed=$h->start('门店怎么样');
    r6hCheck(($proposed['status']??null)==='WAITING_CLARIFICATION'
        && ($proposed['clarification']['fields'][0]['key']??null)==='semantic_reply',
        'a model-proposed observation cannot execute an object-only question');
    $h->understandingOverride['requirements'][0]['fields'][]='operation';
    $h->understandingOverride['requirements'][0]['values']['operation']='summary';
    $repaired=$h->start('门店怎么样');
    r6hCheck(($repaired['status']??null)==='WAITING_CLARIFICATION'
        && ($repaired['clarification']['fields'][0]['key']??null)==='semantic_reply',
        'a result form alone does not choose the store measurement');
    // Expiry after selecting a metric preserves its private compiled plan.
    // Date recovery follows the ordinary compiler, without a model choosing
    // a different metric or the browser rebuilding the question text.
    $suggestion=$repaired['clarification']['fields'][0]['options'][0]['value'];
    $waiting=$h->choose($repaired,['semantic_reply'=>$suggestion]);
    $h->db->exec('UPDATE mohe_ai_run SET pause_at=pause_at-600001 WHERE run_id='.$h->db->quote($waiting['run_id']));
    $expired=$h->gateway->handle('status',$h->context,$h->binding($waiting),$waiting['run_id']);
    $source=['run_id'=>$expired['run_id']]+$h->binding($expired)+[
        'clarification_id'=>$expired['clarification']['id'],'choices'=>['start_date'=>'2026-09-01','end_date'=>'2026-09-30']];
    $retry=['client_request_id'=>'store-expiry-restart','conversation_id'=>'conversation1','client_session_id'=>'device1',
        'window_token'=>$h->boot['window_token'],'question'=>'门店怎么样','history'=>[],
        'output_format'=>'screen','guidance_schema_version'=>'mohe-clarification-v2','clarification_restart'=>$source];
    $created=$h->gateway->handle('create',$h->context,$retry);$before=$h->models;
    $restored=$h->gateway->handle('execute',$h->context,$h->binding($created)+$retry,$created['run_id']);
    r6hCheck($restored['status']==='COMPLETED','expired metric/date choice completes: '.($restored['reason']??''));
    r6hCheck($before===$h->models,'structured date recovery does not reinterpret the confirmed metric');
    $h->understandingOverride=null;$h->semanticIntent=null;
    $direct=$h->start('今天现金业绩多少');
    r6hCheck(($direct['status']??null)==='COMPLETED','a complete metric and period answer directly');
    // Out-of-domain classification is owned by the understanding model. A
    // calendar word alone must not cause the gateway to offer business facts.
    $h->understandingOverride=['goal'=>'查询天气','status'=>'needs_clarification','requirements'=>[]];
    $unrelated=$h->start('今天天气怎么样');
    r6hCheck(($unrelated['status']??null)==='FAILED'
        && ($unrelated['failure_guidance']['kind']??null)==='capability_help',
        'an unrelated weather question does not open a business guidance card: '
            .($unrelated['status']??'missing').'/'.($unrelated['reason']??'none').'/'
            .($unrelated['failure_guidance']['kind']??'none').'/'
            .($unrelated['clarification']['question']??'none'));
    echo "PASS OPT1 platform broad guidance: including expiry recovery (synthetic only)\n";
} finally {$h->close();}
