<?php
declare(strict_types=1);

require_once __DIR__.'/../../后端代码/app/services/ai/execution/AiRunStore.php';
use app\services\ai\execution\AiRunStore;

function installAiStateFixture(PDO $db): void
{
    // SQLite is only a local transactional test adapter, not the production deployment schema.
    $db->exec('CREATE TABLE mohe_ai_mutex (instance_id TEXT PRIMARY KEY, quarantined_slots INTEGER NOT NULL DEFAULT 0)');
    $db->exec('CREATE TABLE mohe_ai_receipt (instance_id TEXT, receipt_key TEXT, request_hash TEXT, window_id TEXT, run_id TEXT, reason TEXT, expires_at INTEGER, PRIMARY KEY(instance_id,receipt_key))');
    $db->exec('CREATE TABLE mohe_ai_attempt (instance_id TEXT,run_id TEXT,attempt_code TEXT,kind TEXT,target_code TEXT,payload_hash TEXT,state TEXT,input_tokens INTEGER,output_tokens INTEGER,created_at INTEGER,expires_at INTEGER,PRIMARY KEY(instance_id,run_id,attempt_code))');
    $db->exec('CREATE TABLE mohe_ai_run (instance_id TEXT,run_id TEXT,account_id INTEGER,terminal TEXT,conversation_id TEXT,window_id TEXT,generation INTEGER,status TEXT,reason TEXT,progress_code TEXT,clarification_ref TEXT,version INTEGER,created_at INTEGER,expires_at INTEGER,deadline_at INTEGER,last_clock_at INTEGER,remaining_ms INTEGER,pause_at INTEGER,clarification_count INTEGER,slot_held INTEGER,worker_token TEXT,evidence_ref TEXT,answer_ref TEXT,snapshot_json TEXT,counters_json TEXT,PRIMARY KEY(instance_id,run_id))');
}
$checks=0;
function checkState(bool $condition,string $label): void { global $checks; ++$checks; if (!$condition) { throw new RuntimeException('FAIL '.$label); } }
function rejectsState(callable $fn,string $code): void { try { $fn(); } catch (RuntimeException $e) { checkState($e->getMessage()===$code,'expected '.$code.', got '.$e->getMessage()); return; } throw new RuntimeException('Expected '.$code); }
$db=new PDO('sqlite::memory:'); installAiStateFixture($db);
$now=1000000; $clock=function () use (&$now) { return $now; };
$store=new AiRunStore($db,'','fixture.instance',$clock);
$owner=['account_id'=>1,'terminal'=>'store','conversation_id'=>'conversation1','window_id'=>'window1'];
$snapshot=['capability_snapshot_ref'=>'cap1','capability_snapshot_hash'=>str_repeat('a',64),'budget_profile_version'=>'b1','authorization_version'=>'auth1','model_config_version'=>'model1'];
$hash=hash('sha256','fixture request');
$created=$store->create($owner,'request1',$hash,$snapshot,180000,2);
$r=$created['run']; $id=$r['run_id']; $g=$r['generation'];
checkState($created['accepted'] && !$created['replayed'],'create');
checkState($store->create($owner,'request1',$hash,$snapshot,180000,2)['replayed'],'idempotent create');
checkState(!isset($store->create($owner,'request1',$hash,$snapshot)['run']['worker_token']),'replay redacts internals');
rejectsState(function () use ($store,$owner,$snapshot) { $store->create($owner,'request1',str_repeat('b',64),$snapshot); },'AI_IDEMPOTENCY_CONFLICT');
$otherWindow=$owner; $otherWindow['window_id']='window2';
rejectsState(function () use ($store,$otherWindow,$hash,$snapshot) { $store->create($otherWindow,'request1',$hash,$snapshot); },'AI_IDEMPOTENCY_CONFLICT');
foreach (['account_id'=>2,'terminal'=>'platform','conversation_id'=>'other','window_id'=>'other'] as $key=>$value) {
    $wrong=$owner; $wrong[$key]=$value;
    rejectsState(function () use ($store,$wrong,$id,$g) { $store->get($wrong,$id,$g); },'AI_RUN_NOT_FOUND');
}
$otherInstance=new AiRunStore($db,'','fixture.other',$clock);
rejectsState(function () use ($otherInstance,$owner,$id,$g) { $otherInstance->get($owner,$id,$g); },'AI_RUN_NOT_FOUND');
checkState($store->claim($owner,$id,$g,'worker1'),'claim once');
checkState(!$store->claim($owner,$id,$g,'worker2'),'cannot duplicate execute');
rejectsState(function () use ($store,$owner,$id,$g) { $store->checkpoint($owner,$id,$g,'worker2'); },'AI_WORKER_FENCED');
$store->assertRequest($owner,$id,$g,$hash);
rejectsState(function () use ($store,$owner,$id,$g) { $store->assertRequest($owner,$id,$g,str_repeat('b',64)); },'AI_IDEMPOTENCY_CONFLICT');
rejectsState(function () use ($store,$owner,$id,$g) { $store->reserve($owner,$id,$g,'worker1','model_attempt_count'); },'AI_COUNTER_SEQUENCE');
checkState($store->reserve($owner,$id,$g,'worker1','stage_count')===1,'reserve stage');
checkState($store->reserve($owner,$id,$g,'worker1','model_attempt_count')===1,'reserve attempt');
checkState($store->reserve($owner,$id,$g,'worker1','tool_call_count',8)===8,'reserve eight tools');
rejectsState(function () use ($store,$owner,$id,$g) { $store->reserve($owner,$id,$g,'worker1','tool_call_count'); },'AI_COUNTER_EXHAUSTED');
$otherConversation=$owner; $otherConversation['conversation_id']='conversation2';
checkState($store->create($otherConversation,'requestOther',$hash,$snapshot)['reason']==='OTHER_CONVERSATION_ACTIVE','another conversation refused');
$cancelled=$store->cancel($owner,$id,$g);
checkState($cancelled['status']==='CANCELLED' && $cancelled['slot_held']===1,'cancel holds physical slot');
checkState($store->cancel($owner,$id,$g)['status']==='CANCELLED','cancel idempotent');
rejectsState(function () use ($store,$owner,$id,$g) { $store->publish($owner,$id,$g,'worker1','e1','a1'); },'AI_RUN_TERMINAL');
checkState($store->create($owner,'capacity1',$hash,$snapshot,180000,1)['reason']==='CAPACITY_REJECTED','logical cancel cannot sell slot');
$store->release($owner,$id,$g,'worker1');
checkState($store->create($owner,'capacity1',$hash,$snapshot,180000,1)['reason']==='CAPACITY_REJECTED','rejected receipt stable after release');
$r=$store->create($owner,'request2',$hash,$snapshot,180000,1)['run']; $id2=$r['run_id']; $g2=$r['generation'];
checkState($g2>$g,'generation monotonic');
$store->claim($owner,$id2,$g2,'worker2');
$now+=10000;
$paused=$store->pauseForClarification($owner,$id2,$g2,'worker2');
checkState($paused['status']==='WAITING_CLARIFICATION' && $paused['slot_held']===0,'clarification pauses and releases');
$now+=300000;
$resumed=$store->resume($owner,$id2,$g2,'worker3');
checkState($resumed['deadline_at']===$now+170000,'resume exact remaining budget');
rejectsState(function () use ($store,$owner,$id2,$g2) { $store->pauseForClarification($owner,$id2,$g2,'worker3'); },'AI_CLARIFICATION_EXHAUSTED');
$published=$store->publish($owner,$id2,$g2,'worker3','evidence2','answer2');
checkState($published['status']==='COMPLETED','publish');
checkState($store->cancel($owner,$id2,$g2)['status']==='COMPLETED','publish wins cancel race');
$store->release($owner,$id2,$g2,'worker3');
$r3=$store->create($owner,'request3',$hash,$snapshot,180000,2)['run'];
$r4=$store->create($owner,'request4',$hash,$snapshot,180000,2)['run'];
checkState($store->get($owner,$r3['run_id'],$r3['generation'])['status']==='CANCELLED','same conversation superseded');
checkState($store->get($owner,$r3['run_id'],$r3['generation'])['slot_held']===0,'unclaimed superseded reservation released');
$store->claim($owner,$r4['run_id'],$r4['generation'],'worker4');
$now+=180000;
rejectsState(function () use ($store,$owner,$r4) { $store->checkpoint($owner,$r4['run_id'],$r4['generation'],'worker4'); },'AI_RUN_DEADLINE');
$store->cleanup();
checkState($store->get($owner,$r4['run_id'],$r4['generation'])['status']==='FAILED','deadline supervisor terminalizes');
checkState($store->get($owner,$r4['run_id'],$r4['generation'])['slot_held']===1,'deadline never physical release');
$now+=86400000;
rejectsState(function () use ($store,$owner,$r4) { $store->get($owner,$r4['run_id'],$r4['generation']); },'AI_RUN_EXPIRED');
$clean=$store->cleanup();
checkState($clean['quarantined_slots']===1,'expired occupied slot quarantined');
checkState((int)$db->query('SELECT COUNT(*) FROM mohe_ai_run')->fetchColumn()===0,'all expired states purged');
checkState((int)$db->query('SELECT COUNT(*) FROM mohe_ai_receipt')->fetchColumn()===0,'receipts purged');
checkState($store->create($owner,'afterexpiry',$hash,$snapshot,180000,1)['reason']==='CAPACITY_REJECTED','no unsafe expiry recovery');
$badSnapshot=$snapshot; $badSnapshot['question']='never persist me';
rejectsState(function () use ($store,$owner,$hash,$badSnapshot) { $store->create($owner,'bad',$hash,$badSnapshot); },'AI_SNAPSHOT_INVALID');
echo 'PASS '.$checks." transactional state checks (isolated SQLite; no production DB).\n";
