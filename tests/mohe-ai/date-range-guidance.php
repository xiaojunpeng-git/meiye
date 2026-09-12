<?php
/** Server-owned date alternatives are confirmed, never silently clipped. */
require_once __DIR__.'/fixture-autoload.php';
use app\services\ai\execution\AiDateRangeGuidancePlanner;
use app\services\query\metric\MetricQueryDatePolicy;

$checks=0;
function drgCheck($value,string $label): void { global $checks; if (!$value) throw new RuntimeException('date range guidance: '.$label); ++$checks; }
function drgPlan(string $start,string $end,?array $compare=null): array { return ['workflow_code'=>'wf_performance_summary','query'=>[
    'query_shape'=>'summary','metric_codes'=>['cash_performance'],'start_date'=>$start,'end_date'=>$end,
    'compare_range'=>$compare,'store_ids'=>[],'business_filters'=>[],'ranking'=>null],'output_format'=>'screen']; }

$planner=new AiDateRangeGuidancePlanner();
$coverage=$planner->start(drgPlan('2026-08-01','2026-09-12'),'2026-09-12');
drgCheck(($coverage['kind']??null)==='clarification','early period receives a controlled clarification');
$field=$coverage['fields'][0];
drgCheck($field['key']==='current_date_candidate'&&count($field['options'])===1,'coverage-only period has one exact valid alternative');
drgCheck($field['options'][0]['value']==='2026-08-10/2026-09-12','coverage alternative begins at the registered source boundary');
$covered=$planner->choose($coverage,['current_date_candidate'=>$field['options'][0]['value']]);
drgCheck(($covered['kind']??null)==='plan'&&$covered['plan']['query']['start_date']==='2026-08-10','the choice replaces only the confirmed current range');

$long=$planner->start(drgPlan('2026-08-10','2027-12-31'),'2028-09-12');
drgCheck(count($long['fields'][0]['options'])===2,'long period offers early and late valid alternatives without a default');
foreach ($long['fields'][0]['options'] as $option) {
    [$start,$end]=explode('/',$option['value']);
    MetricQueryDatePolicy::assertExecutable(['start'=>$start,'end'=>$end],'2026-08-10','2028-09-12');
    drgCheck(true,'every offered alternative is executable');
}
$late=$planner->choose($long,['current_date_candidate'=>$long['fields'][0]['options'][1]['value']]);
drgCheck(($late['kind']??null)==='plan','customer can explicitly choose the later alternative');

$comparison=$planner->start(drgPlan('2026-09-01','2026-09-12',['start'=>'2025-08-01','end'=>'2026-09-12']),'2026-09-12');
drgCheck($comparison['fields'][0]['key']==='comparison_date_candidate','comparison boundary is guided independently');
$comparisonDone=$planner->choose($comparison,['comparison_date_candidate'=>$comparison['fields'][0]['options'][0]['value']]);
drgCheck(($comparisonDone['kind']??null)==='plan','comparison range confirmation produces a complete plan');

$future=$planner->start(drgPlan('2026-09-13','2026-09-13'),'2026-09-12');
drgCheck($future===null,'future period is not silently turned into a past-date choice');
$futureLong=$planner->start(drgPlan('2025-08-01','2027-12-31'),'2026-09-12');
drgCheck($futureLong===null,'a long range that explicitly reaches the future is not silently clipped to today');

$twoRanges=$planner->start(drgPlan('2026-08-01','2026-09-12',['start'=>'2026-08-01','end'=>'2026-09-12']),'2026-09-12');
$firstChoice=$twoRanges['fields'][0]['options'][0]['value'];
$comparisonStep=$planner->choose($twoRanges,['current_date_candidate'=>$firstChoice]);
drgCheck(($comparisonStep['fields'][0]['key']??null)==='comparison_date_candidate','a second boundary keeps a separate confirmation state');
require_once __DIR__.'/r6-gateway-harness.php';
$revisionHarness=new R6GatewayHarness(3,[1,2],'platform');
try {
    $advance=\Closure::bind(function(array $stored,string $ref,array $input): array {
        return $this->advanceGuidance($stored,$ref,$input);
    },$revisionHarness->gateway,get_class($revisionHarness->gateway));
    [$revised,$replayed]=$advance([
        'envelope'=>$comparisonStep,'origin'=>drgPlan('2026-08-01','2026-09-12',['start'=>'2026-08-01','end'=>'2026-09-12']),
        'accepted_steps'=>[['id'=>'first-date-step','envelope'=>$twoRanges,'choices'=>['current_date_candidate'=>$firstChoice]]],
    ],'comparison-step',['choices'=>['current_date_candidate'=>$firstChoice],'revise_clarification_id'=>'first-date-step']);
    drgCheck(($revised['kind']??null)==='clarification'&&($revised['fields'][0]['key']??null)==='comparison_date_candidate','revising the first date selection rebuilds the next saved clarification');
    drgCheck(($revised['date_range_guidance_state']['plan']['query']['start_date']??null)==='2026-08-10','revised date selection retains the confirmed range instead of returning to the rejected original');
    drgCheck(count($replayed)===1,'revision records the rebuilt accepted date step');
} finally { $revisionHarness->close(); }

$h=new R6GatewayHarness(3,[1,2],'platform');
try {
    $h->understandingOverride=['goal'=>'查看收款','status'=>'understood','requirements'=>[
        ['id'=>'r1','meaning'=>'收款','fields'=>['metric_codes'],'values'=>['metric_terms'=>['收款']],'evidence'=>[['message_id'=>'current','quote'=>'收款']]],
        ['id'=>'r2','meaning'=>'该期间','fields'=>['periods'],'values'=>['periods'=>[['kind'=>'date_range','start'=>'2026-08-01','end'=>'2026-09-12']]],'evidence'=>[['message_id'=>'current','quote'=>'2026年8月1日到9月12日']]],
    ]];
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary','metric_codes'=>['cash_performance'],'action_codes'=>[],
        'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],
        'periods'=>[['kind'=>'date_range','start'=>'2026-08-01','end'=>'2026-09-12']],'scope'=>'authorized',
        'requirement_bindings'=>[['requirement_id'=>'r1','status'=>'satisfied','metric_codes'=>['cash_performance']]],'unresolved_fragments'=>[]];
    $before=$h->queries;$waiting=$h->start('2026年8月1日到9月12日收款');
    drgCheck(($waiting['status']??null)==='WAITING_CLARIFICATION'&&($waiting['clarification']['fields'][0]['key']??null)==='current_date_candidate','gateway turns coverage rejection into a date confirmation');
    drgCheck($h->queries===$before,'no query runs before a date alternative is explicitly confirmed');
    $selected=$waiting['clarification']['fields'][0]['options'][0]['value'];
    $finished=$h->choose($waiting,['current_date_candidate'=>$selected]);
    drgCheck(($finished['status']??null)==='COMPLETED'&&$h->queries>$before,'confirmed range executes through the registered reader');
} finally { $h->close(); }
echo 'PASS date range guidance: '.$checks." checks (offline)\n";
