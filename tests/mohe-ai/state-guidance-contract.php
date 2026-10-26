<?php
declare(strict_types=1);
require_once __DIR__.'/state-store-contract.php';
// Reuse only the schema helper. Every test below owns a fresh in-memory database.
$guidanceChecks=0;
$ok=static function(bool $value,string $label) use(&$guidanceChecks): void { if(!$value)throw new RuntimeException('FAIL guidance '.$label); ++$guidanceChecks; };
$reject=static function(callable $fn,string $reason) use($ok): void { try{$fn();}catch(Throwable $e){$ok($e->getMessage()===$reason,'expected '.$reason.' got '.$e->getMessage());return;}throw new RuntimeException('Expected '.$reason); };
$fixture=static function(int $max) {
    $db=new PDO('sqlite::memory:'); installAiStateFixture($db); $clock=(object)['now'=>1000000];
    $store=new app\services\ai\execution\AiRunStore($db,'','guidance.instance',static function()use($clock):int{return $clock->now;});
    $owner=['account_id'=>1,'terminal'=>'store','conversation_id'=>'conversation','window_id'=>'window'];
    $snapshot=['capability_snapshot_ref'=>'registry1','capability_snapshot_hash'=>str_repeat('a',64),'budget_profile_version'=>'180000-v1','authorization_version'=>'auth1','model_config_version'=>'model1',
        'guidance_schema_version'=>'mohe-clarification-v2','guidance_profile_version'=>'guidance-v2-'.$max,'max_clarification_rounds'=>(string)$max];
    $run=$store->create($owner,'request',hash('sha256','synthetic body'),$snapshot)['run'];
    $store->claim($owner,$run['run_id'],$run['generation'],'worker0');
    return [$db,$clock,$store,$owner,$snapshot,$run];
};
$submit=static function(int $round,string $ref,string $id=''): array { return ['request_id'=>$id?:'submit'.$round,'request_hash'=>hash('sha256','choices'.$round),'clarification_ref'=>$ref,'intent_revision'=>$round,'step_revision'=>1]; };
foreach([3,4,5] as $max) {
    [$db,$clock,$store,$owner,$snapshot,$run]=$fixture($max); $id=$run['run_id'];$g=$run['generation'];$worker='worker0';
    for($round=1;$round<=$max;$round++) {
        $clock->now+=1000;
        $wait=$store->pauseForClarification($owner,$id,$g,$worker,'step'.$round);
        $ok($wait['clarification_count']===$round && $wait['max_clarification_rounds']===$max,'locked step '.$max.'/'.$round);
        $ok($wait['slot_held']===0 && $wait['status']==='WAITING_CLARIFICATION','wait yields physical slot');
        $clock->now+=10000; $request=$submit($round,'step'.$round);$worker='worker'.$round;
        $resumed=$store->resume($owner,$id,$g,$worker,4,$request);
        $store->acceptClarification($owner,$id,$g,$worker,$request['request_id']);
        $store->acceptClarification($owner,$id,$g,$worker,$request['request_id']);
        $ok($store->get($owner,$id,$g)['clarification_accepted_count']===$round,'accepted once');
        $replay=$store->resume($owner,$id,$g,'duplicate-worker',4,$request);
        $ok($replay['submission_replayed'] && $replay['version']===$store->get($owner,$id,$g)['version'],'duplicate cannot execute/advance version');
        $ok($resumed['deadline_at']===$clock->now+180000-$round*1000,'only human wait paused; execution not replenished');
        $bad=$request;$bad['request_hash']=hash('sha256','different body');
        $reject(static function()use($store,$owner,$id,$g,$bad){$store->resume($owner,$id,$g,'bad-worker',4,$bad);},'AI_IDEMPOTENCY_CONFLICT');
    }
    $reject(static function()use($store,$owner,$id,$g,$worker){$store->pauseForClarification($owner,$id,$g,$worker,'extra');},'AI_CLARIFICATION_EXHAUSTED');
    $done=$store->publish($owner,$id,$g,$worker,'evidence','answer');
    $ok($done['status']==='COMPLETED','final permitted step can publish');
    $store->release($owner,$id,$g,$worker);
    $ok($store->resume($owner,$id,$g,'late-duplicate',4,$request)['submission_replayed'],'completed submission replay never reruns');
    $data=json_encode($db->query('SELECT snapshot_json,counters_json FROM mohe_ai_run')->fetchAll(PDO::FETCH_ASSOC));
    $ok(strpos($data,'synthetic body')===false && strpos($data,'choices')===false,'database keeps hashes/counters not conversation or choices');
}
[$db,$clock,$store,$owner,$snapshot,$run]=$fixture(3); $id=$run['run_id'];$g=$run['generation'];
$clock->now+=1000;$store->pauseForClarification($owner,$id,$g,'worker0','step1');
$clock->now+=350000;$store->resume($owner,$id,$g,'worker1',4,$submit(1,'step1'));
$clock->now+=1000;$second=$store->pauseForClarification($owner,$id,$g,'worker1','step2');
$stale=$submit(1,'step1','stale-request');
$reject(static function()use($store,$owner,$id,$g,$stale){$store->resume($owner,$id,$g,'stale',4,$stale);},'AI_CLARIFICATION_STALE');
$ok($store->get($owner,$id,$g)['version']===$second['version'],'stale submission cannot mutate current step');
$clock->now+=250000;$store->cleanup();
$expired=$store->get($owner,$id,$g);
$ok($expired['status']==='FAILED' && $expired['reason']==='CLARIFICATION_EXPIRED','supervisor enforces cumulative ten minutes across steps');
[$db,$clock,$store,$owner,$snapshot,$run]=$fixture(3);$id=$run['run_id'];$g=$run['generation'];
$store->pauseForClarification($owner,$id,$g,'worker0','step1');
for($i=1;$i<=3;$i++) {
    $clock->now+=1000;$request=$submit(1,'step1','invalid'.$i);$worker='invalid-worker'.$i;
    $store->resume($owner,$id,$g,$worker,4,$request);
    $wait=$store->pauseForClarification($owner,$id,$g,$worker,'step1',false);
    $ok($wait['clarification_count']===1 && $wait['clarification_accepted_count']===0,'format correction no new semantic step');
}
$store->resume($owner,$id,$g,'invalid-worker4',4,$submit(1,'step1','invalid4'));
$reject(static function()use($store,$owner,$id,$g){$store->pauseForClarification($owner,$id,$g,'invalid-worker4','step1',false);},'AI_CLARIFICATION_INVALID_LIMIT');
$ok($store->get($owner,$id,$g)['clarification_wait_ms']===3000,'format correction cannot reset accumulated human wait');
echo 'R5 transactional guidance: '.$guidanceChecks." checks PASS (isolated SQLite only)\n";
