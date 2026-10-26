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
    // metric_choice may never conceal a rejected requirement. Its empty
    // rejection list makes this malformed reviewer outcome fail at the
    // semantic contract boundary before any data is read.
    $h->bindingVerificationOverride=['decision'=>'metric_choice','rejected_requirement_ids'=>[]];
    $h->bindingReviewKind='candidate_blind_uniqueness';
    $before=$h->queries;
    $run=$h->start('今天收款，不要退款');
    sbgCheck(($run['status']??null)==='FAILED' && ($run['reason']??null)==='AI_BINDING_SEMANTIC_REJECTED','exclusion rejects a deferred metric choice');
    sbgCheck($h->queries===$before,'rejected semantic binding cannot query a reduced request');

    $plain=['goal'=>'查看收款','status'=>'understood','requirements'=>[
        ['id'=>'r1','meaning'=>'查看收款','fields'=>['metric_codes'],'values'=>['metric_terms'=>['收款']],'evidence'=>[['message_id'=>'current','quote'=>'收款']]],
    ]];
    $candidate=['metric_codes'=>['cash_performance'],'needs_metric_choice'=>false];
    sbgCheck(\app\services\ai\contract\AiIntentResultContract::canUseCandidateBlindMetricReview($plain,$candidate),'one fresh positive metric may use candidate-blind ambiguity review');
    sbgCheck(!\app\services\ai\contract\AiIntentResultContract::canUseCandidateBlindMetricReview($h->understandingOverride,$candidate),'an exclusion makes candidate-blind ambiguity ineligible');
    sbgCheck(\app\services\ai\contract\AiIntentResultContract::canUseCandidateBlindMetricReview($plain,$candidate),'a new evidence-backed measurement keeps candidate-blind admission in a follow-up');
    $rankingCandidate=$candidate+['operation'=>'ranking'];
    sbgCheck(\app\services\ai\contract\AiIntentResultContract::canUseCandidateBlindMetricReview($plain,$rankingCandidate),'a clear ranking may use the same candidate-blind metric admission');
    $broadRankingUnderstanding=['goal'=>'谁的业绩最好','status'=>'understood','requirements'=>[
        ['id'=>'r1','meaning'=>'找出表现最佳的人员','fields'=>['object_kind','operation','ranking'],
            'values'=>['object_kind'=>'person','operation'=>'ranking','ranking'=>['direction'=>'top','limit'=>1]],
            'evidence'=>[['message_id'=>'current','quote'=>'谁的业绩最好']]],
    ]];
    $broadRankingIntent=['object_kind'=>'person','object_term'=>'','object_relation'=>'analysis','operation'=>'ranking',
        'metric_codes'=>['staff_sales_yeji','staff_labor_yeji'],'needs_metric_choice'=>true,
        'initial_observation'=>false,'aggregate_condition'=>null,'result_reference'=>null];
    sbgCheck(\app\services\ai\contract\AiIntentResultContract::canUseRegisteredRankDefault($broadRankingUnderstanding,$broadRankingIntent),
        'a condition-free broad ranking may use one registered first-answer declaration after semantic review');
    $conditionalRankingIntent=$broadRankingIntent;$conditionalRankingIntent['aggregate_condition']=['subject'=>'person'];
    sbgCheck(!\app\services\ai\contract\AiIntentResultContract::canUseRegisteredRankDefault($broadRankingUnderstanding,$conditionalRankingIntent),
        'a registered ranking default never replaces a customer condition set');
    $unboundCandidate=['metric_codes'=>[],'needs_metric_choice'=>true];
    sbgCheck(\app\services\ai\contract\AiIntentResultContract::canUseCandidateBlindMetricReview($plain,$unboundCandidate),
        'a fresh measurement may be model-resolved from the registry even when the first binder left it pending');
    sbgCheck(!\app\services\ai\contract\AiIntentResultContract::canUseCandidateBlindMetricReview($h->understandingOverride,$unboundCandidate),
        'a metric exclusion never enters the candidate-blind uniqueness pass');
    $splitCurrent=['goal'=>'查看两个当前指标','status'=>'understood','requirements'=>[
        ['id'=>'r1','meaning'=>'第一项当前指标','fields'=>['metric_codes'],'values'=>['metric_terms'=>['甲']], 'evidence'=>[['message_id'=>'current','quote'=>'甲']]],
        ['id'=>'r2','meaning'=>'第二项当前指标','fields'=>['metric_codes'],'values'=>['metric_terms'=>['乙']], 'evidence'=>[['message_id'=>'current','quote'=>'乙']]],
    ]];
    sbgCheck(\app\services\ai\contract\AiIntentResultContract::canUseCandidateBlindMetricReview($splitCurrent,$unboundCandidate),
        'a model may resolve or reject a current multi-fragment measurement without PHP merging its terms');
    $recommendedHarness=new R6GatewayHarness(3,[1,2],'merchant');
    try {
        $recommendedHarness->understandingOverride=['goal'=>'了解经营情况','status'=>'understood','requirements'=>[]];
        $recommendedHarness->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary','metric_codes'=>['cash_performance'],'action_codes'=>[],
            'needs_metric_choice'=>false,'recommended_initial_answer'=>true,'initial_observation'=>false,
            'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'authorized',
            'requirement_bindings'=>[],'unresolved_fragments'=>[]];
        $recommendedHarness->bindingVerificationOverride=['decision'=>'reject','rejected_requirement_ids'=>[]];
        $before=$recommendedHarness->queries;
        $rejected=$recommendedHarness->start('经营怎么样');
        sbgCheck(($rejected['status']??null)==='FAILED' && $recommendedHarness->queries===$before,
            'a reviewer rejection never converts a professional first answer into an unrelated selector');
    } finally { $recommendedHarness->close(); }
    echo 'PASS semantic binding guard: '.$checks." checks (offline)\n";
} finally { $h->close(); }
