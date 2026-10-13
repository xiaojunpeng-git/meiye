<?php
/** Exercises the real Run budget/attempt lifecycle with injected transport
 * failures. No customer DB, credentials or external provider are accessed. */
require __DIR__.'/r6-gateway-harness.php';

use app\services\ai\contract\AiContractException;
use app\services\ai\execution\AiRunStore;

$checks=0;
function transportCheck(bool $value,string $label): void
{
    global $checks;
    if (!$value) throw new RuntimeException('FAIL '.$label);
    ++$checks;
}

/** Keep the existing semantic/fact fixture, injecting only the observed
 * transport boundary. The wrapped adapter records every attempted phase. */
function transportHarness(array $failures,?callable $beforeFailure=null): array
{
    $h=new R6GatewayHarness(3,[1],'platform');
    // A broad request deliberately requires language understanding; the
    // exact-metric fast path would bypass the injected timeout entirely.
    $h->understandingOverride=['goal'=>'查看业绩','status'=>'needs_clarification','requirements'=>[[
        'id'=>'r1','meaning'=>'业绩','fields'=>['metric_codes','object_kind'],
        'values'=>['metric_terms'=>['业绩'],'object_kind'=>'store'],
        'evidence'=>[['message_id'=>'current','quote'=>'业绩']],
    ]]];
    $h->semanticIntent=['object_kind'=>'store','object_term'=>'','operation'=>'summary','metric_codes'=>[],
        'action_codes'=>[],'needs_metric_choice'=>true,'metric_choice_candidates'=>['cash_performance','consume_amount'],
        'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'authorized','unresolved_fragments'=>[]];
    $property=new ReflectionProperty($h->gateway,'model');
    if (PHP_VERSION_ID<80100) $property->setAccessible(true);
    $original=$property->getValue($h->gateway);
    $calls=(object)['phases'=>[],'views'=>[],'models'=>[],'repairs'=>[]];
    $property->setValue($h->gateway,static function($view,$candidates,$configuration,$checkpoint,$repair=null,$phase='binding',$understanding=null)
        use($h,$original,$failures,$beforeFailure,$calls) {
        $calls->phases[]=$phase;$calls->views[]=$view;
        $calls->models[]=$configuration['model'];$calls->repairs[]=$repair;
        if ($phase==='understanding') {
            $index=count(array_filter($calls->phases,static function($item){return $item==='understanding';}))-1;
            if (isset($failures[$index])) {
                if ($beforeFailure!==null) $beforeFailure($h,$index);
                throw $failures[$index];
            }
        }
        return $original($view,$candidates,$configuration,$checkpoint,$repair,$phase,$understanding);
    });
    return [$h,$calls];
}

function timeoutFailure(int $httpStatus=0): AiContractException
{
    return new AiContractException('AI_MODEL_RESULT_UNKNOWN',[
        'stage'=>'transport','predicate'=>'timeout','transport_errno'=>28,'http_status'=>$httpStatus,'elapsed_ms'=>30000,
    ]);
}

function runAttempts(R6GatewayHarness $h,array $run): array
{
    $statement=$h->db->prepare('SELECT attempt_code,state,input_tokens,output_tokens FROM mohe_ai_attempt WHERE run_id=? AND kind=\'model\' ORDER BY created_at,attempt_code');
    $statement->execute([$run['run_id']]);
    return array_column($statement->fetchAll(PDO::FETCH_ASSOC),null,'attempt_code');
}

[$h,$calls]=transportHarness([timeoutFailure()]);
try {
    $run=$h->start('业绩怎么样');
    transportCheck($run['status']==='WAITING_CLARIFICATION' && $h->queries===0,'recovery preserves missing business meaning before any fact query: '.($run['reason']??''));
    $attempts=runAttempts($h,$run);
    transportCheck($attempts['understand_meaning']['state']==='UNKNOWN'
        && $attempts['understand_meaning']['input_tokens']===null && $attempts['understand_meaning']['output_tokens']===null,
        'original request retains unknown outcome and token usage');
    transportCheck($attempts['understand_transport_retry']['state']==='SUCCEEDED','recovery has its own attempt receipt');
    transportCheck($calls->views[0]===$calls->views[1] && $calls->models[0]===$calls->models[1]
        && $calls->repairs[0]===null && $calls->repairs[1]===null,'recovery uses the same sanitized meaning and model');
    $counters=json_decode($h->row($run)['counters_json'],true);
    transportCheck($counters['model_recovery_count']===1,'existing shared recovery budget is consumed once');
    transportCheck(($counters['model_diagnostics']['understand_meaning']['transport_errno']??null)===28,
        'successful recovery does not erase the original timeout diagnostic');
    // Publication admission must stay closed for every unrelated or malformed
    // receipt. Mutations below are confined to this isolated SQLite fixture.
    $guard=new ReflectionMethod(AiRunStore::class,'hasUnresolvedAttempt');
    if (PHP_VERSION_ID<80100) $guard->setAccessible(true);
    $storeProperty=new ReflectionProperty($h->gateway,'runs');
    if (PHP_VERSION_ID<80100) $storeProperty->setAccessible(true);
    $store=$storeProperty->getValue($h->gateway);
    $retry=$h->db->query("SELECT * FROM mohe_ai_attempt WHERE attempt_code='understand_transport_retry'")->fetch(PDO::FETCH_ASSOC);
    foreach (['payload_hash'=>str_repeat('f',64),'kind'=>'tool','target_code'=>'other','state'=>'FAILED'] as $field=>$value) {
        $update=$h->db->prepare('UPDATE mohe_ai_attempt SET '.$field.'=? WHERE attempt_code=\'understand_transport_retry\'');
        $update->execute([$value]);
        transportCheck($guard->invoke($store,$run['run_id']),'mismatched '.$field.' cannot clear unknown publication fence');
        $update->execute([$retry[$field]]);
    }
    $originalCounters=$h->row($run)['counters_json'];
    $invalidCounters=$counters;unset($invalidCounters['model_diagnostics']['understand_meaning']);
    $update=$h->db->prepare('UPDATE mohe_ai_run SET counters_json=? WHERE run_id=?');
    $update->execute([json_encode($invalidCounters),$run['run_id']]);
    transportCheck($guard->invoke($store,$run['run_id']),'missing original transport diagnostic cannot clear unknown publication fence');
    $update->execute([$originalCounters,$run['run_id']]);
    $options=array_column($run['clarification']['fields'][0]['options'],'value');
    $choice=current(array_values(array_filter($options,static function($value){return strpos($value,'suggestion_')===0;})));
    $period=$h->choose($run,['semantic_reply'=>$choice]);
    $completed=$h->choose($period,['start_date'=>'2026-10-09','end_date'=>'2026-10-09']);
    transportCheck($completed['status']==='COMPLETED' && $completed['run_id']===$run['run_id'],'recovered Run continues through metric and period confirmation');
    transportCheck($h->queries===1,'recovery and clarification never duplicate the fact query');
} finally {$h->close();}

[$h,$calls]=transportHarness([timeoutFailure()]);
try {
    $h->understandingOverride=['goal'=>'无法识别经营查询目标','requirements'=>[],'status'=>'needs_clarification'];
    $run=$h->start('今天天气怎么样');
    transportCheck($run['reason']==='AI_INTENT_UNRESOLVED' && $h->queries===0,'unrelated meaning still stops before business facts');
    transportCheck(($run['failure_guidance']['kind']??null)==='capability_help'
        && !isset($run['failure_guidance']['options']),'recovered unrelated question receives capability help without a card');
    transportCheck($calls->phases===['understanding','understanding'],'no binding is invented for unrelated meaning');
} finally {$h->close();}

[$h,$calls]=transportHarness([timeoutFailure(),timeoutFailure(),timeoutFailure()]);
try {
    $run=$h->start('业绩怎么样');
    transportCheck($run['status']==='FAILED' && $run['reason']==='AI_MODEL_RESULT_UNKNOWN','a second timeout remains a technical failure');
    transportCheck($calls->phases===['understanding','understanding'] && $h->queries===0,'two failed attempts stop without a third request or fact read');
    $attempts=runAttempts($h,$run);
    transportCheck(count($attempts)===2 && count(array_filter($attempts,static function($row){return $row['state']==='UNKNOWN';}))===2,
        'both failed receipts remain unknown');
    transportCheck((int)$h->row($run)['slot_held']===0,'terminal transport failure releases the local execution slot');
} finally {$h->close();}

// No-response timeout is a specific admission rule. Other transport shapes,
// account failures, cancellation and authority changes do not qualify.
foreach ([timeoutFailure(100),timeoutFailure(200),new AiContractException('AI_MODEL_ACCOUNT_UNAVAILABLE'),
    new AiContractException('AI_CANCELLED'),new AiContractException('AI_AUTHORIZATION_CHANGED'),
    new AiContractException('AI_MODEL_RESULT_UNKNOWN',['stage'=>'transport','predicate'=>'connection','transport_errno'=>7,'http_status'=>0,'elapsed_ms'=>100])]
    as $failure) {
    [$h,$calls]=transportHarness([$failure]);
    try {
        $run=$h->start('业绩怎么样');
        transportCheck(count($calls->phases)===1 && $h->queries===0,'noneligible failure is not automatically retried');
        transportCheck(!isset(runAttempts($h,$run)['understand_transport_retry']),'noneligible failure has no recovery receipt');
    } finally {$h->close();}
}

[$h,$calls]=transportHarness([timeoutFailure()],static function($h): void {
    // Simulate an earlier clarification consuming the same Run reservation.
    $row=$h->db->query('SELECT counters_json FROM mohe_ai_run')->fetch(PDO::FETCH_ASSOC);
    $counters=json_decode($row['counters_json'],true);$counters['model_recovery_count']=1;
    $statement=$h->db->prepare('UPDATE mohe_ai_run SET counters_json=?');$statement->execute([json_encode($counters)]);
});
try {
    $run=$h->start('业绩怎么样');
    transportCheck($run['reason']==='AI_MODEL_RESULT_UNKNOWN' && count($calls->phases)===1,'spent recovery preserves actual timeout reason without another call');
    transportCheck($h->queries===0,'spent recovery cannot query facts');
} finally {$h->close();}

[$h,$calls]=transportHarness([timeoutFailure()],static function($h): void {$h->auth['can_use']=false;});
try {
    $run=$h->start('业绩怎么样');
    transportCheck($run['reason']==='AI_AUTHORIZATION_CHANGED','recovery rechecks current authority: '.($run['reason']??''));
    transportCheck(count($calls->phases)===1 && $h->queries===0,'revocation stops before the recovery provider request');
} finally {$h->close();}

[$h,$calls]=transportHarness([timeoutFailure()],static function($h): void {
    // The live deadline is checked again before another provider request.
    $h->db->exec('UPDATE mohe_ai_run SET deadline_at='.(int)(microtime(true)*1000-1));
});
try {
    $run=$h->start('业绩怎么样');
    transportCheck($run['reason']==='AI_RUN_DEADLINE' && count($calls->phases)===1,'expired execution budget prevents a recovery send');
    transportCheck($h->queries===0,'expired recovery never reads facts');
} finally {$h->close();}

echo 'PASS OPT1 transport recovery: '.$checks." checks (synthetic transport/model/facts only)\n";
