<?php
declare(strict_types=1);

/** A dead consumer's physical slot is reclaimed without replaying its model
 * or query. Exercise the real runtime proof with this process's live PID,
 * including the Docker restart case where PID 1 exists again. */
require __DIR__.'/state-store-contract.php';
require_once __DIR__.'/../../后端代码/app/services/ai/execution/AiRunExecutionRuntime.php';

use app\services\ai\execution\AiRunStore;
use app\services\ai\execution\AiRunExecutionRuntime;

$startChecks=$checks;
$savedWorkerId=getenv('MOHE_AI_EXECUTION_WORKER_ID');
$pid=getmypid(); $host=(string)php_uname('n');
if (!preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D',$host)) $host='host-'.substr(hash('sha256',$host),0,32);
$currentId='ai-exec-'.$pid.'-'.str_repeat('a',24);
$oldId='ai-exec-'.$pid.'-'.str_repeat('b',24);
putenv('MOHE_AI_EXECUTION_WORKER_ID='.$currentId);

try {
    foreach (['running','deadline','cancelled','same_process','foreign_host','generic_worker','too_young'] as $case) {
        $db=new PDO('sqlite::memory:'); installAiStateFixture($db);
        $now=1000000;
        $store=new AiRunStore($db,'','opt3.worker',static function()use(&$now):int{return $now;});
        $owner=['account_id'=>21,'terminal'=>'platform','conversation_id'=>'conversation','window_id'=>'window'];
        $run=$store->create($owner,'request',hash('sha256','query'),$snapshot,300000)['run'];
        $id=$run['run_id']; $g=$run['generation']; $token='old-worker-token';
        $ref='request-'.str_repeat('c',48);
        $store->queueExecution($owner,$id,$g,'execute',$ref,hash('sha256','input'));
        $store->queuedExecution($id);
        $recordedId=$case==='same_process'?$currentId:($case==='generic_worker'?'job-generic':$oldId);
        $store->startQueuedExecutionWorker($id,$recordedId,$pid,$case==='foreign_host'?'foreign-host':$host);
        checkState($store->claim($owner,$id,$g,$token),$case.' owns physical slot');
        $store->prepareAttempt($owner,$id,$g,$token,'query','tool',hash('sha256','query payload'),'unified_metric_query');
        $store->sendAttempt($owner,$id,$g,$token,'query');
        if ($case==='cancelled') $store->cancel($owner,$id,$g);
        $now+=$case==='too_young'?1000:($case==='deadline'?300001:210001);
        // Deadline/cancellation is a logical state; neither may sell the
        // physical slot before the recorded process is proven stopped.
        $before=$store->get($owner,$id,$g);
        checkState($before['slot_held']===1,$case.' retains slot before proof');
        $expected=in_array($case,['running','deadline','cancelled'],true);
        checkState(AiRunExecutionRuntime::recoverStoppedWorkers($store)===($expected?1:0),$case.' exact physical-stop proof');
        $after=$store->get($owner,$id,$g);
        $attempt=$db->query("SELECT state,input_tokens,output_tokens FROM mohe_ai_attempt WHERE attempt_code='query'")->fetch(PDO::FETCH_ASSOC);
        if (!$expected) {
            checkState($after['slot_held']===1 && $attempt['state']==='IN_FLIGHT',$case.' remains fenced and unreplayed');
            continue;
        }
        checkState($after['slot_held']===0,$case.' physical slot released');
        checkState($after['reason']===($case==='deadline'?'DEADLINE_EXCEEDED':($case==='cancelled'?'USER_CANCELLED':'AI_EXECUTION_WORKER_LOST')),$case.' preserves original terminal cause');
        checkState($attempt['state']==='UNKNOWN' && $attempt['input_tokens']===null && $attempt['output_tokens']===null,$case.' unknown outcome has no fabricated usage');
        rejectsState(static function()use($store,$owner,$id,$g,$token){$store->publish($owner,$id,$g,$token,'evidence','answer');},'AI_WORKER_FENCED');
        checkState(AiRunExecutionRuntime::recoverStoppedWorkers($store)===0,$case.' recovery is idempotent');
        checkState($store->takeTerminalUnclaimedExecutionInputRefs()===[$ref],$case.' encrypted request can retire');
        $replacement=$store->create($owner,'next-request',hash('sha256','next'),$snapshot,180000,1);
        checkState($replacement['accepted']===true,$case.' replacement receives one actual free slot');
    }
} finally {
    putenv($savedWorkerId===false?'MOHE_AI_EXECUTION_WORKER_ID':'MOHE_AI_EXECUTION_WORKER_ID='.$savedWorkerId);
}
echo 'PASS OPT3 worker recovery '.($checks-$startChecks)." assertions\n";
