<?php
/** A broad metric choice must never erase an independently understood exclusion. */
require __DIR__.'/r6-gateway-harness.php';

$checks=0;
function sbgCheck($value,string $label): void { global $checks; if (!$value) throw new RuntimeException('semantic binding guard: '.$label); ++$checks; }

$h=new R6GatewayHarness(3,[1,2],'platform');
try {
    $h->understandingOverride=['goal'=>'查看收款，不要退款','status'=>'understood','requirements'=>[
        ['id'=>'r1','meaning'=>'查看收款','fields'=>['metric_codes'],'values'=>['metric_terms'=>['收款']],'evidence'=>[['message_id'=>'current','quote'=>'收款']]],
        ['id'=>'r2','meaning'=>'不要退款','fields'=>['metric_codes'],'values'=>['metric_exclusions'=>['退款']],'evidence'=>[['message_id'=>'current','quote'=>'不要退款']]],
        ['id'=>'r3','meaning'=>'今天','fields'=>['periods'],'values'=>['periods'=>[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]]],'evidence'=>[['message_id'=>'current','quote'=>'今天']]],
    ]];
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary','metric_codes'=>['cash_performance'],'action_codes'=>[],
        'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],
        'periods'=>[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]],'scope'=>'authorized',
        'requirement_bindings'=>[
            ['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['cash_performance']],
            ['requirement_id'=>'r2','status'=>'satisfied','metric_codes'=>['cash_performance']],
        ],'unresolved_fragments'=>[]];
    // A malformed/injected reviewer reply cannot turn this multi-part request
    // into an unrelated registered-metric selector.
    $h->bindingVerificationOverride=['decision'=>'metric_choice','rejected_requirement_ids'=>['r2']];
    $h->bindingReviewKind='candidate_blind_uniqueness';
    $before=$h->queries;
    $run=$h->start('今天收款，不要退款');
    sbgCheck(($run['status']??null)==='FAILED' && ($run['reason']??null)==='AI_BINDING_SEMANTIC_REJECTED','exclusion rejects a deferred metric choice');
    sbgCheck($h->queries===$before,'rejected semantic binding cannot query a reduced request');

    $plain=['goal'=>'查看收款','status'=>'understood','requirements'=>[
        ['id'=>'r1','meaning'=>'查看收款','fields'=>['metric_codes'],'values'=>['metric_terms'=>['收款']],'evidence'=>[['message_id'=>'current','quote'=>'收款']]],
    ]];
    $candidate=['metric_codes'=>['cash_performance'],'needs_metric_choice'=>false];
    sbgCheck(\app\services\ai\contract\AiIntentResultContract::canDeferMetricChoice($plain,$candidate,false),'one fresh positive metric may use candidate-blind ambiguity review');
    sbgCheck(!\app\services\ai\contract\AiIntentResultContract::canDeferMetricChoice($h->understandingOverride,$candidate,false),'an exclusion makes candidate-blind ambiguity ineligible');
    sbgCheck(!\app\services\ai\contract\AiIntentResultContract::canDeferMetricChoice($plain,$candidate,true),'a follow-up never defers by candidate-blind ambiguity');
    $recommended=$candidate+['recommended_initial_answer'=>true,'initial_observation'=>false];
    sbgCheck(\app\services\ai\contract\AiIntentResultContract::canDeferRejectedRecommendation($plain,$recommended,false),
        'a rejected broad professional recommendation becomes a safe registry choice rather than a failed run');
    $excludedRecommended=$recommended;
    sbgCheck(!\app\services\ai\contract\AiIntentResultContract::canDeferRejectedRecommendation($h->understandingOverride,$excludedRecommended,false),
        'a rejected recommendation with an exclusion remains blocked before any query');
    $recommendedHarness=new R6GatewayHarness(3,[1,2],'merchant');
    try {
        $recommendedHarness->understandingOverride=$plain;
        $recommendedHarness->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary','metric_codes'=>['cash_performance'],'action_codes'=>[],
            'needs_metric_choice'=>false,'recommended_initial_answer'=>true,'initial_observation'=>false,
            'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'authorized',
            'requirement_bindings'=>[['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['cash_performance']]],'unresolved_fragments'=>[]];
        $recommendedHarness->bindingVerificationOverride=['decision'=>'reject','rejected_requirement_ids'=>['r1']];
        $before=$recommendedHarness->queries;
        $deferred=$recommendedHarness->start('收款');
        sbgCheck(($deferred['status']??null)==='WAITING_CLARIFICATION' && $recommendedHarness->queries===$before,
            'a reviewer rejection of a broad first answer opens the registered choice without reading data');
    } finally { $recommendedHarness->close(); }
    echo 'PASS semantic binding guard: '.$checks." checks (offline)\n";
} finally { $h->close(); }
