<?php
/**
 * A new metric requirement must not be trapped by a context delta that
 * mechanically retains the previous metric. The gateway may ask the model
 * once to publish a coherent binding, but it must never choose that metric in
 * server code and a second rejection remains terminal.
 */
require __DIR__.'/r6-gateway-harness.php';

$checks=0;
function cmrCheck($value,string $label): void { global $checks; if (!$value) throw new RuntimeException('context metric rebinding: '.$label); ++$checks; }

$h=new R6GatewayHarness(3,[1,2],'platform');
try {
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary','metric_codes'=>['cash_performance'],'action_codes'=>[],
        'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]],'scope'=>'authorized','unresolved_fragments'=>[]];
    $source=$h->start('今天现金业绩是多少');
    cmrCheck(($source['status']??null)==='COMPLETED'&&isset($source['answer']['context_ref']),'verified source exists');

    $h->understandingOverride=['goal'=>'查看本次另一项已登记金额','status'=>'understood','requirements'=>[
        ['id'=>'r1','meaning'=>'查看另一项金额','fields'=>['metric_codes'],'values'=>['metric_terms'=>['另一项金额']],
            'evidence'=>[['message_id'=>'current','quote'=>'另一项已登记金额']]],
    ]];
    // The first binding mechanically retains its signed source metric. The
    // reviewer rejects it. The repair response is model-authored and selects
    // a registered alternative; the gateway only validates and executes it.
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary','metric_codes'=>[],'action_codes'=>[],
        'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'unspecified',
        'context_delta'=>array_fill_keys(\app\services\ai\contract\AiIntentResultContract::DELTA_FIELDS,'inherit'),'unresolved_fragments'=>[]];
    $repair=$h->semanticIntent;
    $repair['metric_codes']=['consume_amount'];
    $repair['context_delta']['metric_codes']='replace';
    $h->semanticIntentByRepair=['context_metric_carryover_rejected'=>$repair];
    $h->bindingVerificationSequence=[['decision'=>'reject','rejected_requirement_ids'=>['r1']],['decision'=>'accept','rejected_requirement_ids'=>[]]];
    $run=$h->start('查看本次另一项已登记金额',$source['answer']['context_ref']);
    $evidence=$h->private->read($h->row($run)['evidence_ref']);
    cmrCheck(($run['status']??null)==='COMPLETED','rejected inherited metric is rebound once');
    cmrCheck(($evidence['query']['metric_codes']??[])===['consume_amount'],'only model-selected repaired metric reaches Reader');
    cmrCheck($h->queries===2,'repair does not create duplicate Reader execution');
    cmrCheck(\app\services\ai\contract\AiIntentResultContract::requiresMetricContextRebinding(
        ['metric_codes'=>['cash_performance']],$h->understandingOverride,$h->semanticIntent
    ),'structural guard recognizes current metric requirement with inherited metric');
    cmrCheck(!\app\services\ai\contract\AiIntentResultContract::requiresMetricContextRebinding(
        ['metric_codes'=>['cash_performance']],['goal'=>'继续','status'=>'understood','requirements'=>[]],$h->semanticIntent
    ),'ellipsis without current metric requirement keeps verified context');
    cmrCheck(\app\services\ai\contract\AiIntentResultContract::requiresCurrentMetricRebinding($h->understandingOverride),
        'a semantic rejection with a current metric requirement may receive one model-only rebinding');
    echo 'PASS context metric rebinding: '.$checks." checks (offline)\n";
} finally { $h->close(); }
