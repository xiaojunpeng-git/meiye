<?php
declare(strict_types=1);

/** The real-model driver's asynchronous guidance contract is tested without
 * ThinkPHP, credentials, network or business queries. Queued acknowledgements
 * retain their card; a client must poll, not submit the same revision again. */
define('MOHE_OPT3_DRIVER_LIBRARY',true);
require __DIR__.'/opt3-local-regression.php';
require __DIR__.'/fixture-autoload.php';
$checks=0;$failures=[];
$check=static function(bool $ok,string $label)use(&$checks,&$failures):void {
    $checks++;if (!$ok) $failures[]=$label;
};
$card=static function(int $intent=1,int $step=1):array {
    return ['id'=>'synthetic-card','intent_revision'=>$intent,'step_revision'=>$step,
        'fields'=>[['key'=>'semantic_reply','type'=>'select','options'=>[
            ['value'=>'__supplement__','label'=>'补充查询条件']]]]];
};
$waiting=static function(array $card,int $version=1):array {
    return ['run_id'=>'synthetic-run','status'=>'WAITING_CLARIFICATION','version'=>$version,'clarification'=>$card];
};
$case=['question'=>'业绩怎么样','metric'=>'actual_performance','period'=>'month','shape'=>'summary','kind'=>'store'];

// The status poll may observe both an unchanged queued acknowledgement and
// a changed Run row version; neither grants permission to answer the card twice.
$run=$waiting($card());$guidance=[];$submitted=[];$polls=0;$now=0.0;
$responses=[$waiting($card(),2),['run_id'=>'synthetic-run','status'=>'WORKFLOW_EXECUTING'],
    ['run_id'=>'synthetic-run','status'=>'COMPLETED']];
$status=static function(array $current)use(&$responses,&$polls):array {$polls++;return array_shift($responses);};
$clarify=static function(array $current,array $envelope,array $choices)use(&$submitted):array {
    $submitted[]=['card'=>$envelope,'choices'=>$choices];return $current;
};
$pause=static function()use(&$now):void {$now+=0.25;};
$clock=static function()use(&$now):float{return $now;};
$done=opt3DriveRun($run,$case,$status,$clarify,$guidance,$pause,$clock);
$check($done['status']==='COMPLETED' && $run===$done,'terminal result is returned and latest state retained');
$check(count($submitted)===1,'same queued card is submitted exactly once');
$check($polls===3,'unchanged card and active execution both poll');
$check(count($guidance)===1,'repeated queued envelopes do not inflate guidance steps');
$check(strpos($submitted[0]['choices']['supplement_text'],'实际业绩')!==false,
    'semantic supplement supplies the intended metric');
$check(strpos($submitted[0]['choices']['supplement_text'],opt3Range('month')[0])!==false,
    'semantic supplement supplies the missing date');
$check($submitted[0]['choices']['supplement_text']!==$case['question'],
    'semantic supplement does not repeat an ambiguous original question');

// A genuinely new semantic or step revision is a new question, even when the
// test server retains the same opaque card id; each accepted revision is unique.
$run=$waiting($card());$guidance=[];$submitted=[];$polls=0;$now=0.0;
$responses=[$waiting($card(2)), $waiting($card(2,2)),
    ['run_id'=>'synthetic-run','status'=>'COMPLETED']];
opt3DriveRun($run,$case,$status,$clarify,$guidance,$pause,$clock);
$check(count($submitted)===3,'new intent and step revisions each receive one answer');
$check(count($guidance)===3 && array_column($guidance,'intent_revision')===[1,2,2],
    'evidence records only the three genuine guidance steps');

// A lost worker cannot trigger unbounded resubmission; retain the last Run so
// the owner can legally cancel only this test's work if cleanup is necessary.
$run=$waiting($card());$guidance=[];$submitted=[];$now=0.0;
$stuck=static function(array $current):array {return $current;};
$slowPause=static function()use(&$now):void {$now+=100;};
try {opt3DriveRun($run,$case,$stuck,$clarify,$guidance,$slowPause,$clock);$check(false,'stuck card must time out');}
catch (RuntimeException $error) {$check($error->getMessage()==='TEST_RUN_POLL_TIMEOUT','stuck card times out honestly');}
$check(count($submitted)===1,'stuck async card is never resent');
$check($run['status']==='WAITING_CLARIFICATION','latest owned Run state remains available after timeout');

// Only advertised structured choices are selected. Missing identities cannot
// be fabricated or chosen arbitrarily to force a completed business query.
$dates=['fields'=>[['key'=>'start_date','type'=>'date'],['key'=>'end_date','type'=>'date']]];
$chosen=opt3Choices($dates,$case);$range=opt3Range('month');
$check($chosen===['start_date'=>$range[0],'end_date'=>$range[1]],'date slots use the scenario window');
$unknown=['fields'=>[['key'=>'member_id','type'=>'select','options'=>[['value'=>'private-person','label'=>'candidate']]]]];
try {opt3Choices($unknown,$case);$check(false,'unknown business identity must not be selected');}
catch (RuntimeException $error) {$check($error->getMessage()==='TEST_GUIDANCE_UNSUPPORTED_SLOT','unknown identity fails without choosing first option');}
$condition=['question'=>'本月消费低于500的客户有几个','metric'=>'cash_performance','period'=>'month',
    'shape'=>'condition_count','kind'=>'member','operator'=>'lt','quantity'=>'500'];
$supplement=opt3Supplement($condition);
$check(strpos($supplement,'现金业绩小于500元')!==false && strpos($supplement,'客户人数')!==false,
    'condition supplement preserves metric, inequality, amount and requested count');
$ranking=['question'=>'按门店排名','metric'=>'consume_amount','period'=>'month','shape'=>'ranking','kind'=>'store',
    'ranking'=>['direction'=>'bottom','limit'=>3]];
$supplement=opt3Supplement($ranking);
$check(strpos($supplement,'消耗业绩')!==false && strpos($supplement,'从低到高')!==false && strpos($supplement,'前3名')!==false,
    'ranking supplement supplies measurement, direction and bounded quantity');
try {opt3Supplement(['question'=>'第二名呢','reference_ordinal'=>2]);$check(false,'missing identity intent must not be invented');}
catch (RuntimeException $error) {$check($error->getMessage()==='TEST_GUIDANCE_MEANING_UNSPECIFIED','unrepresented reference supplement is rejected');}

$check(opt3Classification(['pass'=>false,'status'=>'COMPLETED','upstream_failures'=>[1],
    'checks'=>['period'=>false]])==='unverified_upstream_dependency','failed source is separated from a new context diagnosis');
$check(opt3Classification(['pass'=>false,'status'=>'FAILED','reason'=>'AI_MODEL_RESULT_UNKNOWN',
    'upstream_failures'=>[1]])==='failed_run','model failure is not disguised by an upstream dependency');
$check(opt3Classification(['pass'=>false,'status'=>'FAILED','reason'=>'AI_MODEL_RESULT_UNKNOWN',
    'telemetry'=>['diagnostics'=>['terminal_contract'=>['stage'=>'transport','predicate'=>'timeout','transport_errno'=>28]]]])
    ==='provider_dependency_failure','verified model transport failure is separated from functional failures');
$check(opt3Classification(['pass'=>false,'status'=>'FAILED','reason'=>'DEADLINE_EXCEEDED',
    'telemetry'=>['latest_diagnostic'=>['stage'=>'transport','predicate'=>'timeout']]])==='failed_run',
    'a Reader/process deadline cannot be relabelled as provider failure from an old diagnostic');
$check(opt3Classification(['pass'=>false,'status'=>'WAITING_CLARIFICATION','reason'=>'AI_CLARIFICATION_IN_PROGRESS'])
    ==='test_driver_failure','duplicate clarification remains a driver failure');
$plan=opt3Cases();
$check(count($plan)===5 && array_sum(array_map(static function(array $round):int{return count($round['turns']);},$plan))===50,
    'repaired protocol preserves five independent rounds and fifty original questions');
$check($plan[1]['turns'][1]['depends_on_turn']===1,'the broad store follow-up declares its failed-source dependency');

// The actual final batch completed a registered legacy threshold query. Its
// integer-cent schema is equivalent to one condition_set predicate, but is not
// input to the newer human-unit compiler. Keep both the correct and altered
// legacy carrier cases, so an oracle fix cannot excuse a different query.
$range=opt3Range('year');
$legacy=['query_shape'=>'threshold_count','metric_codes'=>['cash_performance'],
    'start_date'=>$range[0],'end_date'=>$range[1],'store_ids'=>[],
    'business_filters'=>['object_kind'=>'member'],'aggregate_condition'=>[
        'subject'=>'member','aggregation'=>'period_total','operator'=>'eq','amount_cents'=>498000]];
$expected=['question'=>'今年消费4980有多少人','metric'=>'cash_performance','period'=>'year',
    'shape'=>'condition_count','kind'=>'member','operator'=>'eq','quantity'=>'4980'];
$meaningChecks=opt3SemanticChecks($legacy,$expected,null);
$check(!in_array(false,$meaningChecks,true),'actual legacy threshold query passes equivalent meaning checks');
$altered=$legacy;$altered['aggregate_condition']['amount_cents']=4980;
$check(opt3SemanticChecks($altered,$expected,null)['condition']===false,'yuan accidentally stored as cents still fails');
$altered=$legacy;$altered['aggregate_condition']['operator']='gte';
$check(opt3SemanticChecks($altered,$expected,null)['condition']===false,'a changed inequality still fails');
$altered=$legacy;$altered['metric_codes']=['sales_collected_amount'];
$check(opt3SemanticChecks($altered,$expected,null)['metric']===false,'another financial perspective still fails');
foreach ([
    'aggregation'=>['aggregation'=>'lifetime_total'],
    'float amount'=>['amount_cents'=>498000.0],
    'extra field'=>['ignored'=>'condition'],
] as $label=>$change) {
    $altered=$legacy;$altered['aggregate_condition']=array_replace($altered['aggregate_condition'],$change);
    $check(opt3LegacyCondition($altered)===null,'legacy normalization rejects '.$label);
}
$altered=$legacy;$altered['condition_set']=['subject'=>'member','relation'=>'all','conditions'=>[]];
$check(opt3LegacyCondition($altered)===null,'competing condition_set cannot be ignored by legacy normalization');
$altered=$legacy;$altered['metric_codes'][]='actual_performance';
$check(opt3LegacyCondition($altered)===null,'multi-metric query cannot hide behind legacy single predicate');

$affected=opt3Plan('affected');
$check(array_map(static function(array $round):int{return count($round['turns']);},$affected)===[7,3,5],
    'approved affected retest remains three conversations and fifteen questions');
$check(array_column($affected[0]['turns'],'original_turn')===[1,2,3,4,8,9,10],
    'affected A retains original R2 case identifiers and ordering');
$check(array_column($affected[2]['turns'],'original_turn')===[4,5,6,7,8]
    && $affected[2]['turns'][1]['depends_on_turn']===1 && $affected[2]['turns'][4]['depends_on_turn']===4,
    'affected C remaps dependencies without losing the successful original preconditions');
$check($affected[0]['turns'][5]['guidance_required']===false && $affected[0]['turns'][6]['guidance_required']===false,
    'complete condition questions require direct first answers');
$check(!array_key_exists('guidance_required',$affected[0]['turns'][2]),
    'unspecified ranking still permits a real missing-condition card');
$check(opt3Classification(['pass'=>false,'status'=>'COMPLETED','checks'=>['direct_first_answer'=>false]])
    ==='unnecessary_guidance','extra card has its own UX failure classification');
try {opt3Plan('unknown');$check(false,'unapproved dynamic plan must fail');}
catch (RuntimeException $error) {$check($error->getMessage()==='TEST_PLAN_UNSUPPORTED','only explicit fixture plans can run');}

foreach ($failures as $failure) fwrite(STDERR,'FAIL '.$failure.PHP_EOL);
if ($failures!==[]) exit(1);
echo 'PASS OPT3 local driver async protocol checks='.$checks.PHP_EOL;
