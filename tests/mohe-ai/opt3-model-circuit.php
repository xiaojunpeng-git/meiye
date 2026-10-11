<?php
declare(strict_types=1);

/** Model outages use real transactional Attempt receipts. All data and clocks
 * are synthetic; the shared-file case exercises two independent PHP workers. */
if (($argv[1]??null)==='--circuit-worker') {
    // STDIN is the start barrier. Both children open before the parent releases
    // either, and each child creates its own PDO connection to shared storage.
    require_once __DIR__.'/../../后端代码/app/services/ai/execution/AiRunStore.php';
    $input=json_decode((string)fgets(STDIN),true);
    if (!is_array($input)) exit(1);
    try {
        $childDb=new PDO('sqlite:'.$input['path']); $childDb->exec('PRAGMA busy_timeout=5000');
        $childStore=new \app\services\ai\execution\AiRunStore($childDb,'','opt3.concurrent',function()use($input){return $input['now'];});
        $childStore->prepareAttempt($input['owner'],$input['run']['run_id'],$input['run']['generation'],'worker',$input['attempt'],'model',$input['hash'],'siliconflow');
        exit(10);
    } catch (RuntimeException $error) {
        if ($error->getMessage()==='AI_MODEL_CIRCUIT_OPEN') exit(20);
        fwrite(STDERR,'Unexpected circuit test failure: '.$error->getMessage().PHP_EOL); exit(1);
    }
}
require __DIR__.'/state-store-contract.php';

use app\services\ai\execution\AiRunStore;

$circuitClock=100000000;
$circuitSerial=0;
$circuitOwner=['account_id'=>41,'terminal'=>'platform','conversation_id'=>'circuit','window_id'=>'window'];
$circuitSnapshot=$snapshot;
$circuitHash=hash('sha256','synthetic model request');
$timeoutDiagnostic=['stage'=>'transport','predicate'=>'timeout','transport_errno'=>28,'http_status'=>100,'elapsed_ms'=>10];

/** Reserve a synthetic live envelope through the same admission as the gateway. */
function circuitRun(AiRunStore $store,array $identity,array $configuration): array
{
    global $circuitClock,$circuitSerial,$circuitHash;
    ++$circuitClock;
    $created=$store->create($identity,'circuit-'.++$circuitSerial,$circuitHash,$configuration);
    checkState($created['accepted'],'local Run accepted independently of model health');
    $run=$created['run'];
    checkState($store->claim($identity,$run['run_id'],$run['generation'],'worker'),'synthetic worker owns envelope');
    return $run;
}

/** Finish an actual model/tool receipt, preserving UNKNOWN usage and failure
 * diagnostics. None of these helpers sends network or business requests. */
function circuitOutcome(AiRunStore $store,array $identity,array $configuration,string $state,?array $diagnostic=null,string $kind='model',string $target='siliconflow',bool $cancel=false): array
{
    global $circuitClock,$circuitHash;
    $run=circuitRun($store,$identity,$configuration); $id=$run['run_id']; $g=$run['generation'];
    if ($kind==='model') $store->reserve($identity,$id,$g,'worker','stage_count');
    $store->prepareAttempt($identity,$id,$g,'worker','call',$kind,$circuitHash,$target);
    $store->sendAttempt($identity,$id,$g,'worker','call');
    $circuitClock+=10;
    if ($diagnostic!==null) $store->recordDiagnostic($identity,$id,$g,'worker',$diagnostic,'call');
    $store->finishAttempt($identity,$id,$g,'worker','call',$state,$state==='SUCCEEDED'?1:null,$state==='SUCCEEDED'?1:null);
    if ($cancel) $store->cancel($identity,$id,$g);
    elseif ($state==='SUCCEEDED') $store->publish($identity,$id,$g,'worker','evidence','answer');
    else $store->fail($identity,$id,$g,'worker',$kind==='tool'?'AI_EXECUTION_FAILED':'AI_MODEL_RESULT_UNKNOWN');
    $store->release($identity,$id,$g,'worker');
    return $run;
}

$circuitDb=new PDO('sqlite::memory:'); installAiStateFixture($circuitDb);
$circuitStore=new AiRunStore($circuitDb,'','opt3.circuit',function()use(&$circuitClock){return $circuitClock;});

for ($i=0;$i<4;++$i) circuitOutcome($circuitStore,$circuitOwner,$circuitSnapshot,'UNKNOWN',$timeoutDiagnostic);
// Model semantic rejection, tool failure and user cancellation cannot trip
// the provider breaker or erase the preceding network diagnostics.
circuitOutcome($circuitStore,$circuitOwner,$circuitSnapshot,'FAILED',['stage'=>'intent_contract','predicate'=>'binding_row_id']);
circuitOutcome($circuitStore,$circuitOwner,$circuitSnapshot,'UNKNOWN',$timeoutDiagnostic,'tool','unified_metric_query');
circuitOutcome($circuitStore,$circuitOwner,$circuitSnapshot,'UNKNOWN',$timeoutDiagnostic,'model','siliconflow',true);
$fifth=circuitOutcome($circuitStore,$circuitOwner,$circuitSnapshot,'UNKNOWN',$timeoutDiagnostic);
$local=circuitRun($circuitStore,$circuitOwner,$circuitSnapshot); $localId=$local['run_id']; $localG=$local['generation'];
$circuitStore->reserve($circuitOwner,$localId,$localG,'worker','stage_count');
rejectsState(function()use($circuitStore,$circuitOwner,$localId,$localG,$circuitHash){
    $circuitStore->prepareAttempt($circuitOwner,$localId,$localG,'worker','blocked','model',$circuitHash,'siliconflow');
},'AI_MODEL_CIRCUIT_OPEN');
checkState((int)$circuitDb->query("SELECT COUNT(*) FROM mohe_ai_attempt WHERE attempt_code='blocked'")->fetchColumn()===0,'rejected model call has no phantom I/O receipt');
checkState($circuitStore->modelCircuitRetryAfterMs($circuitOwner,$localId,$localG)===119999,'owned retry information follows last transport completion');
$wrongOwner=$circuitOwner; $wrongOwner['account_id']=42;
rejectsState(function()use($circuitStore,$wrongOwner,$localId,$localG){$circuitStore->modelCircuitRetryAfterMs($wrongOwner,$localId,$localG);},'AI_RUN_NOT_FOUND');

$circuitStore->prepareAttempt($circuitOwner,$localId,$localG,'worker','query','tool',$circuitHash,'unified_metric_query');
$circuitStore->sendAttempt($circuitOwner,$localId,$localG,'worker','query');
$circuitStore->finishAttempt($circuitOwner,$localId,$localG,'worker','query','SUCCEEDED');
$circuitStore->publish($circuitOwner,$localId,$localG,'worker','evidence','answer');
$circuitStore->release($circuitOwner,$localId,$localG,'worker');
checkState($circuitStore->get($circuitOwner,$localId,$localG)['status']==='COMPLETED','deterministic tool query still completes during provider outage');
checkState($circuitStore->modelCircuitRetryAfterMs($circuitOwner,$localId,$localG)>0,'local success cannot falsely declare provider recovered');

// Target/configuration/account/terminal/instance are independent health scopes.
$newConfig=$circuitSnapshot; $newConfig['model_config_version']='model2';
circuitOutcome($circuitStore,$circuitOwner,$newConfig,'SUCCEEDED');
circuitOutcome($circuitStore,$circuitOwner,$circuitSnapshot,'SUCCEEDED',null,'model','another-provider');
circuitOutcome($circuitStore,$wrongOwner,$circuitSnapshot,'SUCCEEDED');
$otherTerminal=$circuitOwner; $otherTerminal['terminal']='store';
circuitOutcome($circuitStore,$otherTerminal,$circuitSnapshot,'SUCCEEDED');
$otherInstance=new AiRunStore($circuitDb,'','opt3.other',function()use(&$circuitClock){return $circuitClock;});
circuitOutcome($otherInstance,$circuitOwner,$circuitSnapshot,'SUCCEEDED');
checkState($circuitStore->modelCircuitRetryAfterMs($circuitOwner,$localId,$localG)>0,'isolated provider success cannot reset original scope');

$circuitClock+=120001;
$probe=circuitRun($circuitStore,$circuitOwner,$circuitSnapshot); $probeId=$probe['run_id']; $probeG=$probe['generation'];
$circuitStore->reserve($circuitOwner,$probeId,$probeG,'worker','stage_count',2);
checkState($circuitStore->prepareAttempt($circuitOwner,$probeId,$probeG,'worker','probe','model',$circuitHash,'siliconflow')['prepared'],'cooldown admits one half-open receipt');
checkState(!$circuitStore->prepareAttempt($circuitOwner,$probeId,$probeG,'worker','probe','model',$circuitHash,'siliconflow')['prepared'],'identical probe preparation remains idempotent');
rejectsState(function()use($circuitStore,$circuitOwner,$probeId,$probeG,$circuitHash){
    $circuitStore->prepareAttempt($circuitOwner,$probeId,$probeG,'worker','second','model',$circuitHash,'siliconflow');
},'AI_MODEL_CIRCUIT_OPEN');
$circuitStore->sendAttempt($circuitOwner,$probeId,$probeG,'worker','probe');
rejectsState(function()use($circuitStore,$circuitOwner,$probeId,$probeG,$circuitHash){
    $circuitStore->prepareAttempt($circuitOwner,$probeId,$probeG,'worker','second','model',$circuitHash,'siliconflow');
},'AI_MODEL_CIRCUIT_OPEN');
++$circuitClock;
$circuitStore->finishAttempt($circuitOwner,$probeId,$probeG,'worker','probe','SUCCEEDED',1,1);
checkState($circuitStore->modelCircuitRetryAfterMs($circuitOwner,$probeId,$probeG)===0,'successful model receipt closes circuit before Run finishes');
checkState($circuitStore->prepareAttempt($circuitOwner,$probeId,$probeG,'worker','second','model',$circuitHash,'siliconflow')['prepared'],'next semantic stage proceeds after successful probe');
$circuitStore->sendAttempt($circuitOwner,$probeId,$probeG,'worker','second');
$circuitStore->finishAttempt($circuitOwner,$probeId,$probeG,'worker','second','SUCCEEDED',1,1);
$circuitStore->publish($circuitOwner,$probeId,$probeG,'worker','evidence','answer');
$circuitStore->release($circuitOwner,$probeId,$probeG,'worker');
checkState((int)$circuitDb->query("SELECT COUNT(*) FROM mohe_ai_attempt WHERE kind='model' AND state='UNKNOWN' AND input_tokens IS NULL AND output_tokens IS NULL")->fetchColumn()===6,'recovery preserves original unknown receipts and cost uncertainty');

// Independent processes race to reserve half-open probes on the same owned
// envelope. A shared SQLite file exercises locking rather than test ordering.
if (!function_exists('proc_open')) throw new RuntimeException('Concurrent circuit test requires proc_open');
$sharedPath=tempnam(sys_get_temp_dir(),'mohe-opt3-circuit-');
if ($sharedPath===false) throw new RuntimeException('Cannot allocate isolated test database');
try {
    $sharedDb=new PDO('sqlite:'.$sharedPath); installAiStateFixture($sharedDb);
    $sharedStore=new AiRunStore($sharedDb,'','opt3.concurrent',function()use(&$circuitClock){return $circuitClock;});
    for ($i=0;$i<5;++$i) circuitOutcome($sharedStore,$circuitOwner,$circuitSnapshot,'UNKNOWN',$timeoutDiagnostic);
    $circuitClock+=120001;
    $sharedRun=circuitRun($sharedStore,$circuitOwner,$circuitSnapshot);
    $sharedStore->reserve($circuitOwner,$sharedRun['run_id'],$sharedRun['generation'],'worker','stage_count',2);
    $children=[];
    for ($i=0;$i<2;++$i) {
        $pipes=[];
        $process=proc_open([PHP_BINARY,__FILE__,'--circuit-worker'],[
            0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w'],
        ],$pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start synthetic worker');
        $children[]=['process'=>$process,'pipes'=>$pipes,'attempt'=>'race'.$i];
    }
    foreach ($children as $child) {
        fwrite($child['pipes'][0],json_encode(['path'=>$sharedPath,'now'=>$circuitClock,'owner'=>$circuitOwner,
            'run'=>$sharedRun,'attempt'=>$child['attempt'],'hash'=>$circuitHash])."\n");
        fclose($child['pipes'][0]);
    }
    $codes=[];
    foreach ($children as $child) {
        $stdout=stream_get_contents($child['pipes'][1]); $stderr=stream_get_contents($child['pipes'][2]);
        fclose($child['pipes'][1]); fclose($child['pipes'][2]);
        $codes[]=proc_close($child['process']);
        if ($stdout!=='' || $stderr!=='') throw new RuntimeException('Synthetic worker output: '.$stdout.$stderr);
    }
    sort($codes);
    checkState($codes===[10,20],'two concurrent workers reserve exactly one half-open probe');
    $winning=$sharedDb->query("SELECT attempt_code FROM mohe_ai_attempt WHERE state='PREPARED'")->fetchColumn();
    checkState(is_string($winning),'shared storage has one durable probe receipt');
    $sharedStore->sendAttempt($circuitOwner,$sharedRun['run_id'],$sharedRun['generation'],'worker',$winning);
    ++$circuitClock;
    $sharedStore->recordDiagnostic($circuitOwner,$sharedRun['run_id'],$sharedRun['generation'],'worker',[
        'stage'=>'transport','predicate'=>'http_server','transport_errno'=>0,'http_status'=>503,'elapsed_ms'=>1,
    ],$winning);
    $sharedStore->finishAttempt($circuitOwner,$sharedRun['run_id'],$sharedRun['generation'],'worker',$winning,'FAILED');
    checkState($sharedStore->modelCircuitRetryAfterMs($circuitOwner,$sharedRun['run_id'],$sharedRun['generation'])===120000,'failed half-open server response starts a new cooldown');
    $sharedStore->fail($circuitOwner,$sharedRun['run_id'],$sharedRun['generation'],'worker','AI_MODEL_REQUEST_FAILED');
    checkState($sharedStore->get($circuitOwner,$sharedRun['run_id'],$sharedRun['generation'])['slot_held']===1,'logical failure keeps physical execution slot until worker stops');
    $sharedStore->release($circuitOwner,$sharedRun['run_id'],$sharedRun['generation'],'worker');
    checkState($sharedStore->get($circuitOwner,$sharedRun['run_id'],$sharedRun['generation'])['slot_held']===0,'worker stop releases its physical slot normally');

    $circuitClock+=120001;
    $slowProbe=circuitRun($sharedStore,$circuitOwner,$circuitSnapshot);
    $sharedStore->reserve($circuitOwner,$slowProbe['run_id'],$slowProbe['generation'],'worker','stage_count');
    $sharedStore->prepareAttempt($circuitOwner,$slowProbe['run_id'],$slowProbe['generation'],'worker','slow-probe','model',$circuitHash,'siliconflow');
    $sharedStore->sendAttempt($circuitOwner,$slowProbe['run_id'],$slowProbe['generation'],'worker','slow-probe');
    // The observation history can expire while a worker is physically stuck.
    // Logical timeout must not release its half-open reservation or capacity.
    $circuitClock+=600001;
    $expiredProbe=$sharedStore->get($circuitOwner,$slowProbe['run_id'],$slowProbe['generation']);
    checkState($expiredProbe['status']==='FAILED' && $expiredProbe['slot_held']===1,'over-window probe retains its physical reservation');
    $next=circuitRun($sharedStore,$circuitOwner,$circuitSnapshot);
    $sharedStore->reserve($circuitOwner,$next['run_id'],$next['generation'],'worker','stage_count');
    rejectsState(function()use($sharedStore,$circuitOwner,$next,$circuitHash){
        $sharedStore->prepareAttempt($circuitOwner,$next['run_id'],$next['generation'],'worker','next','model',$circuitHash,'siliconflow');
    },'AI_MODEL_CIRCUIT_OPEN');
    // This is a synthetic stopped call; UNKNOWN records external uncertainty,
    // while release proves the local holder has stopped before another probe.
    $sharedStore->finishAttempt($circuitOwner,$slowProbe['run_id'],$slowProbe['generation'],'worker','slow-probe','UNKNOWN');
    $sharedStore->release($circuitOwner,$slowProbe['run_id'],$slowProbe['generation'],'worker');
    checkState($sharedStore->modelCircuitRetryAfterMs($circuitOwner,$next['run_id'],$next['generation'])===0,'old failures decay only after physical probe is released');
    checkState($sharedStore->prepareAttempt($circuitOwner,$next['run_id'],$next['generation'],'worker','next','model',$circuitHash,'siliconflow')['prepared'],'new probe can reserve after old holder stops');
    $sharedStore->sendAttempt($circuitOwner,$next['run_id'],$next['generation'],'worker','next');
    $sharedStore->finishAttempt($circuitOwner,$next['run_id'],$next['generation'],'worker','next','SUCCEEDED',1,1);
    $sharedStore->publish($circuitOwner,$next['run_id'],$next['generation'],'worker','evidence','answer');
    $sharedStore->release($circuitOwner,$next['run_id'],$next['generation'],'worker');
} finally {
    $sharedStore=null; $sharedDb=null;
    unlink($sharedPath);
}

// A model call that began before the observation window can still finish
// inside it. Filtering by creation alone would omit its recent failure.
$windowDb=new PDO('sqlite::memory:'); installAiStateFixture($windowDb);
$windowStore=new AiRunStore($windowDb,'','opt3.window',function()use(&$circuitClock){return $circuitClock;});
$crossing=circuitRun($windowStore,$circuitOwner,$circuitSnapshot);
$windowStore->reserve($circuitOwner,$crossing['run_id'],$crossing['generation'],'worker','stage_count');
$windowStore->prepareAttempt($circuitOwner,$crossing['run_id'],$crossing['generation'],'worker','crossing','model',$circuitHash,'siliconflow');
$windowStore->sendAttempt($circuitOwner,$crossing['run_id'],$crossing['generation'],'worker','crossing');
$circuitClock+=170000;
$windowStore->recordDiagnostic($circuitOwner,$crossing['run_id'],$crossing['generation'],'worker',$timeoutDiagnostic,'crossing');
$windowStore->finishAttempt($circuitOwner,$crossing['run_id'],$crossing['generation'],'worker','crossing','UNKNOWN');
$windowStore->fail($circuitOwner,$crossing['run_id'],$crossing['generation'],'worker','AI_MODEL_RESULT_UNKNOWN');
$windowStore->release($circuitOwner,$crossing['run_id'],$crossing['generation'],'worker');
$circuitClock+=450000;
for ($i=0;$i<4;++$i) circuitOutcome($windowStore,$circuitOwner,$circuitSnapshot,'UNKNOWN',$timeoutDiagnostic);
checkState($windowStore->modelCircuitRetryAfterMs($circuitOwner,$crossing['run_id'],$crossing['generation'])===120000,'recent completion counts despite older Attempt creation');
$circuitClock+=600001;
checkState($windowStore->modelCircuitRetryAfterMs($circuitOwner,$crossing['run_id'],$crossing['generation'])===0,'transport failures outside observation window no longer trip circuit');

echo 'PASS '.$checks." state/model-circuit assertions, including independent-process half-open race.\n";
