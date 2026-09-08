<?php
declare(strict_types=1);

namespace app\services\ai\execution;

use PDO;
use RuntimeException;

/** Transactional execution metadata only: never pass questions, history, prompts or answers here. */
final class AiRunStore
{
    private $db;
    private $prefix;
    private $instance;
    private $sqlite;
    private $clock;

    public function __construct(PDO $db, string $prefix, string $instanceId, ?callable $clock = null)
    {
        if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix) || !preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/D', $instanceId)) {
            throw new RuntimeException('AI_STORE_CONFIGURATION_INVALID');
        }
        $this->db = $db;
        $this->prefix = $prefix;
        $this->instance = $instanceId;
        $this->sqlite = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        if (!$this->sqlite && $db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('AI_STORE_DRIVER_UNSUPPORTED');
        }
        $this->clock = $clock ?: function () { return (int) floor(microtime(true) * 1000); };
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /** $snapshot contains version/reference identifiers, not model or business payloads. */
    public function create(array $owner, string $requestId, string $requestHash, array $snapshot, int $budgetMs = 180000, int $capacity = 4, int $activeLimit = 8): array
    {
        $this->owner($owner);
        $this->identifier($requestId);
        if (!preg_match('/^[a-f0-9]{64}$/D', $requestHash) || $budgetMs < 5000 || $budgetMs > 300000 || $capacity < 1 || $capacity > 64 || $activeLimit<1 || $activeLimit>128) {
            throw new RuntimeException('AI_CREATE_INVALID');
        }
        $keys = ['capability_snapshot_ref','capability_snapshot_hash','budget_profile_version','authorization_version','model_config_version'];
        if (count($snapshot) !== count($keys)) { throw new RuntimeException('AI_SNAPSHOT_INVALID'); }
        foreach ($keys as $key) { if (!isset($snapshot[$key]) || !is_string($snapshot[$key])) { throw new RuntimeException('AI_SNAPSHOT_INVALID'); } $this->identifier($snapshot[$key]); }
        if (!preg_match('/^[a-f0-9]{64}$/D',$snapshot['capability_snapshot_hash'])) { throw new RuntimeException('AI_SNAPSHOT_INVALID'); }
        return $this->transaction(function () use ($owner, $requestId, $requestHash, $snapshot, $budgetMs, $capacity, $activeLimit) {
            $now = $this->now();
            $receiptKey = hash('sha256', json_encode([$this->instance,$owner['account_id'],$owner['terminal'],$owner['conversation_id'],$requestId]));
            $old = $this->one('SELECT * FROM '.$this->table('receipt').' WHERE instance_id=? AND receipt_key=?', [$this->instance,$receiptKey]);
            if ($old) {
                if ($old['request_hash'] !== $requestHash || $old['window_id'] !== $owner['window_id']) { throw new RuntimeException('AI_IDEMPOTENCY_CONFLICT'); }
                if ((int)$old['expires_at'] <= $now) { throw new RuntimeException('AI_REQUEST_EXPIRED'); }
                if (!$old['run_id']) { return ['accepted'=>false,'reason'=>$old['reason'],'replayed'=>true]; }
                return ['accepted'=>true,'run'=>$this->publicRun($this->read($owner,$old['run_id'],null)),'replayed'=>true];
            }
            $active = $this->rows('SELECT * FROM '.$this->table('run').' WHERE instance_id=? AND account_id=? AND terminal=? AND status NOT IN (\'COMPLETED\',\'PARTIAL_SUCCEEDED\',\'FAILED\',\'CANCELLED\')', [$this->instance,$owner['account_id'],$owner['terminal']]);
            $reason = $this->admissionReason($owner,$now);
            foreach ($active as $run) { if ($reason==='' && $run['conversation_id'] !== $owner['conversation_id']) { $reason='OTHER_CONVERSATION_ACTIVE'; } }
            $allActive=$this->one('SELECT COUNT(*) AS n FROM '.$this->table('run').' WHERE instance_id=? AND status NOT IN (\'COMPLETED\',\'PARTIAL_SUCCEEDED\',\'FAILED\',\'CANCELLED\')',[$this->instance]);
            if ($reason==='' && (int)$allActive['n']-count($active)>=$activeLimit) { $reason='CAPACITY_REJECTED'; }
            // A reserved slot is not reclaimed on deadline, lease expiry, or logical cancellation.
            $used = $this->one('SELECT COUNT(*) AS n FROM '.$this->table('run').' WHERE instance_id=? AND slot_held=1',[$this->instance]);
            $quarantine=$this->one('SELECT quarantined_slots FROM '.$this->table('mutex').' WHERE instance_id=?',[$this->instance]);
            if ($reason === '' && (int)$used['n']+(int)$quarantine['quarantined_slots'] >= $capacity) { $reason='CAPACITY_REJECTED'; }
            if ($reason !== '') {
                $this->insert('receipt',['instance_id'=>$this->instance,'receipt_key'=>$receiptKey,'request_hash'=>$requestHash,'window_id'=>$owner['window_id'],'run_id'=>'','reason'=>$reason,'expires_at'=>$now+86400000]);
                return ['accepted'=>false,'reason'=>$reason,'replayed'=>false];
            }
            $generationRow = $this->one('SELECT MAX(generation) AS n FROM '.$this->table('run').' WHERE instance_id=? AND account_id=? AND terminal=? AND conversation_id=?', [$this->instance,$owner['account_id'],$owner['terminal'],$owner['conversation_id']]);
            $generation = (int)$generationRow['n'] + 1;
            foreach ($active as $run) { $this->execute('UPDATE '.$this->table('run').' SET status=\'CANCELLED\', reason=\'SUPERSEDED\', last_clock_at=?, slot_held=?, version=version+1 WHERE instance_id=? AND run_id=?',[$now,$run['worker_token']===''?0:(int)$run['slot_held'],$this->instance,$run['run_id']]); }
            $id = bin2hex(random_bytes(24));
            $record = array_merge($owner,['instance_id'=>$this->instance,'run_id'=>$id,'generation'=>$generation,'status'=>'RECEIVED','reason'=>'','progress_code'=>'RECEIVED','clarification_ref'=>'','version'=>1,'created_at'=>$now,'expires_at'=>$now+86400000,'deadline_at'=>$now+$budgetMs,'last_clock_at'=>$now,'remaining_ms'=>$budgetMs,'pause_at'=>0,'clarification_count'=>0,'slot_held'=>1,'worker_token'=>'','evidence_ref'=>'','answer_ref'=>'','snapshot_json'=>json_encode($snapshot),'counters_json'=>'{}']);
            $this->insert('run',$record);
            $this->insert('receipt',['instance_id'=>$this->instance,'receipt_key'=>$receiptKey,'request_hash'=>$requestHash,'window_id'=>$owner['window_id'],'run_id'=>$id,'reason'=>'','expires_at'=>$now+86400000]);
            return ['accepted'=>true,'run'=>$this->publicRun($record),'replayed'=>false];
        });
    }

    public function get(array $owner, string $runId, int $generation): array
    {
        return $this->transaction(function () use ($owner,$runId,$generation) { return $this->publicRun($this->read($owner,$runId,$generation)); });
    }

    public function assertRequest(array $owner, string $runId, int $generation, string $requestHash): void
    {
        $this->transaction(function () use ($owner,$runId,$generation,$requestHash) {
            $this->read($owner,$runId,$generation);
            $receipt=$this->one('SELECT request_hash FROM '.$this->table('receipt').' WHERE instance_id=? AND run_id=?',[$this->instance,$runId]);
            if (!$receipt || !hash_equals($receipt['request_hash'],$requestHash)) { throw new RuntimeException('AI_IDEMPOTENCY_CONFLICT'); }
        });
    }

    public function snapshot(array $owner,string $runId,int $generation): array
    {
        return $this->transaction(function () use ($owner,$runId,$generation) {
            $r=$this->read($owner,$runId,$generation); $value=json_decode($r['snapshot_json'],true);
            if (!is_array($value)) { throw new RuntimeException('AI_SNAPSHOT_CORRUPT'); }
            return $value;
        });
    }

    /** Short Task lifecycle CAS only; never put model/query/file I/O inside this lock. */
    public function exportFence(array $owner,string $runId,int $generation,string $workerToken,string $phase,callable $action)
    {
        return $this->transaction(function () use ($owner,$runId,$generation,$workerToken,$phase,$action) {
            $r=$this->read($owner,$runId,$generation);
            if (!in_array($phase,['create','claim','complete','heartbeat','failure','cancel','status','download'],true)) { throw new RuntimeException('AI_EXPORT_PHASE_INVALID'); }
            if ($phase==='download') {
                if ($r['status']!=='COMPLETED') { throw new RuntimeException('AI_EXPORT_NOT_PUBLISHED'); }
            } elseif (in_array($phase,['cancel','status'],true)) {
                // Ownership remains mandatory after logical cancellation; no new execution is allowed.
            } else {
                $this->worker($r,$workerToken); $this->live($r);
            }
            return $action();
        });
    }

    /** Called after the parent thread's operations stop; an independently fenced export may now run. */
    public function waitForExport(array $owner,string $runId,int $generation,string $workerToken,string $handoffRef): array
    {
        $this->identifier($handoffRef);
        return $this->transaction(function () use ($owner,$runId,$generation,$workerToken,$handoffRef) {
            $r=$this->read($owner,$runId,$generation); $this->worker($r,$workerToken); $this->live($r);
            if ($r['status']!=='WORKFLOW_EXECUTING' || !(int)$r['slot_held']) { throw new RuntimeException('AI_EXPORT_HANDOFF_INVALID'); }
            $pending=$this->one('SELECT COUNT(*) AS n FROM '.$this->table('attempt').' WHERE instance_id=? AND run_id=? AND state IN (\'PREPARED\',\'IN_FLIGHT\',\'UNKNOWN\')',[$this->instance,$runId]);
            if ((int)$pending['n']!==0) { throw new RuntimeException('AI_ATTEMPT_UNRESOLVED'); }
            $this->execute('UPDATE '.$this->table('run').' SET status=\'WAITING_EXPORT\', progress_code=\'EXPORTING\', answer_ref=?, slot_held=0, version=version+1 WHERE instance_id=? AND run_id=?',[$handoffRef,$this->instance,$runId]);
            return $this->publicRun($this->read($owner,$runId,$generation));
        });
    }

    /** Only invoke after authoritative export receipt/physical completion. Waiting never extends deadline. */
    public function resumeAfterExport(array $owner,string $runId,int $generation,string $oldToken,string $newToken,int $capacity=4): array
    {
        $this->identifier($newToken);
        if ($newToken===$oldToken || $capacity<1 || $capacity>64) { throw new RuntimeException('AI_EXPORT_RESUME_INVALID'); }
        return $this->transaction(function () use ($owner,$runId,$generation,$oldToken,$newToken,$capacity) {
            $r=$this->read($owner,$runId,$generation); $this->worker($r,$oldToken); $this->live($r);
            if ($r['status']!=='WAITING_EXPORT' || (int)$r['slot_held']) { throw new RuntimeException('AI_EXPORT_RESUME_INVALID'); }
            $used=$this->one('SELECT COUNT(*) AS n FROM '.$this->table('run').' WHERE instance_id=? AND slot_held=1',[$this->instance]);
            $quarantine=$this->one('SELECT quarantined_slots FROM '.$this->table('mutex').' WHERE instance_id=?',[$this->instance]);
            if ((int)$used['n']+(int)$quarantine['quarantined_slots'] >= $capacity) {
                $this->execute('UPDATE '.$this->table('run').' SET status=\'FAILED\', reason=\'CAPACITY_STOPPED\', last_clock_at=?, version=version+1 WHERE instance_id=? AND run_id=?',[$this->now(),$this->instance,$runId]);
            } else {
                $this->execute('UPDATE '.$this->table('run').' SET status=\'WORKFLOW_EXECUTING\', progress_code=\'VERIFYING\', worker_token=?, slot_held=1, version=version+1 WHERE instance_id=? AND run_id=?',[$newToken,$this->instance,$runId]);
            }
            return $this->publicRun($this->read($owner,$runId,$generation));
        });
    }

    public function prepareAttempt(array $owner,string $runId,int $generation,string $workerToken,string $attemptCode,string $kind,string $payloadHash,string $targetCode): array
    {
        $this->identifier($attemptCode); $this->identifier($targetCode);
        if (!in_array($kind,['model','tool'],true) || !preg_match('/^[a-f0-9]{64}$/D',$payloadHash)) { throw new RuntimeException('AI_ATTEMPT_INVALID'); }
        return $this->transaction(function () use ($owner,$runId,$generation,$workerToken,$attemptCode,$kind,$payloadHash,$targetCode) {
            $r=$this->read($owner,$runId,$generation); $this->worker($r,$workerToken); $this->live($r);
            $existing=$this->attempt($runId,$attemptCode);
            if ($existing) {
                if ($existing['payload_hash']!==$payloadHash || $existing['kind']!==$kind || $existing['target_code']!==$targetCode) { throw new RuntimeException('AI_ATTEMPT_CONFLICT'); }
                return ['prepared'=>false,'state'=>$existing['state']];
            }
            $counter=$kind==='model'?'model_attempt_count':'tool_call_count';
            $counts=json_decode($r['counters_json'],true);
            if (!is_array($counts)) { throw new RuntimeException('AI_COUNTER_CORRUPT'); }
            $counts[$counter]=($counts[$counter]??0)+1;
            if ($counts[$counter]>($kind==='model'?3:8)) { throw new RuntimeException('AI_COUNTER_EXHAUSTED'); }
            if ($kind==='model' && $counts[$counter]>($counts['stage_count']??0)+($counts['model_recovery_count']??0)) { throw new RuntimeException('AI_COUNTER_SEQUENCE'); }
            $this->insert('attempt',['instance_id'=>$this->instance,'run_id'=>$runId,'attempt_code'=>$attemptCode,'kind'=>$kind,'target_code'=>$targetCode,'payload_hash'=>$payloadHash,'state'=>'PREPARED','input_tokens'=>null,'output_tokens'=>null,'created_at'=>$this->now(),'expires_at'=>(int)$r['expires_at']]);
            $this->execute('UPDATE '.$this->table('run').' SET counters_json=?, version=version+1 WHERE instance_id=? AND run_id=?',[json_encode($counts),$this->instance,$runId]);
            return ['prepared'=>true,'state'=>'PREPARED'];
        });
    }

    /** Returns false for every duplicate: never re-send an IN_FLIGHT/finished attempt. */
    public function sendAttempt(array $owner,string $runId,int $generation,string $workerToken,string $attemptCode): bool
    {
        $this->identifier($attemptCode);
        return $this->transaction(function () use ($owner,$runId,$generation,$workerToken,$attemptCode) {
            $r=$this->read($owner,$runId,$generation); $this->worker($r,$workerToken); $this->live($r);
            $a=$this->attempt($runId,$attemptCode);
            if (!$a) { throw new RuntimeException('AI_ATTEMPT_NOT_FOUND'); }
            if ($a['state']!=='PREPARED') { return false; }
            $this->execute('UPDATE '.$this->table('attempt').' SET state=\'IN_FLIGHT\' WHERE instance_id=? AND run_id=? AND attempt_code=?',[$this->instance,$runId,$attemptCode]);
            return true;
        });
    }

    /** Usage is confirmed numeric metadata only. UNKNOWN never means zero tokens. */
    public function finishAttempt(array $owner,string $runId,int $generation,string $workerToken,string $attemptCode,string $state,?int $inputTokens=null,?int $outputTokens=null): void
    {
        $this->identifier($attemptCode);
        if (!in_array($state,['SUCCEEDED','FAILED','UNKNOWN'],true) || ($inputTokens!==null && $inputTokens<0) || ($outputTokens!==null && $outputTokens<0) || ($state==='UNKNOWN' && ($inputTokens!==null || $outputTokens!==null))) { throw new RuntimeException('AI_ATTEMPT_INVALID'); }
        $this->transaction(function () use ($owner,$runId,$generation,$workerToken,$attemptCode,$state,$inputTokens,$outputTokens) {
            $r=$this->read($owner,$runId,$generation); $this->worker($r,$workerToken);
            $a=$this->attempt($runId,$attemptCode);
            if (!$a) { throw new RuntimeException('AI_ATTEMPT_NOT_FOUND'); }
            if ($a['state']!=='IN_FLIGHT') {
                $oldInput=$a['input_tokens']===null?null:(int)$a['input_tokens']; $oldOutput=$a['output_tokens']===null?null:(int)$a['output_tokens'];
                if ($a['state']===$state && $oldInput===$inputTokens && $oldOutput===$outputTokens) { return; }
                throw new RuntimeException('AI_ATTEMPT_TERMINAL');
            }
            $this->execute('UPDATE '.$this->table('attempt').' SET state=?, input_tokens=?, output_tokens=? WHERE instance_id=? AND run_id=? AND attempt_code=?',[$state,$inputTokens,$outputTokens,$this->instance,$runId,$attemptCode]);
            if ($state==='UNKNOWN' && !$this->terminal($r)) { $this->execute('UPDATE '.$this->table('run').' SET status=\'FAILED\', reason=\'ATTEMPT_UNKNOWN\', last_clock_at=?, version=version+1 WHERE instance_id=? AND run_id=?',[$this->now(),$this->instance,$runId]); }
        });
    }

    /** Reserve BEFORE any external send; retries cannot reset these counters. */
    public function reserve(array $owner,string $runId,int $generation,string $workerToken,string $counter,int $amount=1): int
    {
        $limits=['stage_count'=>2,'model_attempt_count'=>3,'model_recovery_count'=>1,'failover_count'=>1,'supplement_count'=>1,'workflow_transition_count'=>16,'skill_execution_count'=>8,'tool_call_count'=>8,'node_visit_count'=>12,'input_tokens'=>64000,'output_tokens'=>6000];
        if (!isset($limits[$counter]) || $amount<1) { throw new RuntimeException('AI_COUNTER_INVALID'); }
        return $this->transaction(function () use ($owner,$runId,$generation,$workerToken,$counter,$amount,$limits) {
            $r=$this->read($owner,$runId,$generation); $this->worker($r,$workerToken); $this->live($r);
            $counts=json_decode($r['counters_json'],true);
            if (!is_array($counts)) { throw new RuntimeException('AI_COUNTER_CORRUPT'); }
            $counts[$counter]=($counts[$counter]??0)+$amount;
            if ($counts[$counter]>$limits[$counter]) { throw new RuntimeException('AI_COUNTER_EXHAUSTED'); }
            if (($counts['model_attempt_count']??0) > ($counts['stage_count']??0)+($counts['model_recovery_count']??0) || ($counts['failover_count']??0)>($counts['model_recovery_count']??0)) { throw new RuntimeException('AI_COUNTER_SEQUENCE'); }
            $this->execute('UPDATE '.$this->table('run').' SET counters_json=?, version=version+1 WHERE instance_id=? AND run_id=?',[json_encode($counts),$this->instance,$runId]);
            return $counts[$counter];
        });
    }

    /** Called after planning has actually returned and before relinquishing its execution thread. */
    public function pauseForClarification(array $owner,string $runId,int $generation,string $workerToken,string $clarificationRef=''): array
    {
        if ($clarificationRef!=='') { $this->identifier($clarificationRef); }
        return $this->transaction(function () use ($owner,$runId,$generation,$workerToken,$clarificationRef) {
            $r=$this->read($owner,$runId,$generation); $this->worker($r,$workerToken); $this->live($r);
            if ((int)$r['clarification_count']!==0) { throw new RuntimeException('AI_CLARIFICATION_EXHAUSTED'); }
            $now=$this->now();
            $pending=$this->one('SELECT COUNT(*) AS n FROM '.$this->table('attempt').' WHERE instance_id=? AND run_id=? AND state IN (\'PREPARED\',\'IN_FLIGHT\',\'UNKNOWN\')',[$this->instance,$runId]);
            if ((int)$pending['n']!==0) { throw new RuntimeException('AI_ATTEMPT_UNRESOLVED'); }
            $this->execute('UPDATE '.$this->table('run').' SET status=\'WAITING_CLARIFICATION\', clarification_ref=?, clarification_count=1, pause_at=?, remaining_ms=?, slot_held=0, worker_token=\'\', version=version+1 WHERE instance_id=? AND run_id=?',[$clarificationRef,$now,(int)$r['deadline_at']-$now,$this->instance,$runId]);
            return $this->publicRun($this->read($owner,$runId,$generation));
        });
    }

    /** Resume does not clear counters, extend retention, or claim that a stale process stopped. */
    public function resume(array $owner,string $runId,int $generation,string $workerToken,int $capacity=4): array
    {
        $this->identifier($workerToken);
        if ($capacity<1 || $capacity>64) { throw new RuntimeException('AI_CAPACITY_INVALID'); }
        return $this->transaction(function () use ($owner,$runId,$generation,$workerToken,$capacity) {
            $r=$this->read($owner,$runId,$generation); $now=$this->now();
            if ($r['status']!=='WAITING_CLARIFICATION' || $now<(int)$r['pause_at'] || $now>=(int)$r['pause_at']+600000) { throw new RuntimeException('AI_CLARIFICATION_EXPIRED'); }
            $used=$this->one('SELECT COUNT(*) AS n FROM '.$this->table('run').' WHERE instance_id=? AND slot_held=1',[$this->instance]);
            $quarantine=$this->one('SELECT quarantined_slots FROM '.$this->table('mutex').' WHERE instance_id=?',[$this->instance]);
            if ((int)$used['n']+(int)$quarantine['quarantined_slots'] >= $capacity) {
                $this->execute('UPDATE '.$this->table('run').' SET status=\'FAILED\', reason=\'CAPACITY_STOPPED\', last_clock_at=?, version=version+1 WHERE instance_id=? AND run_id=?',[$this->now(),$this->instance,$runId]);
                return $this->publicRun($this->read($owner,$runId,$generation));
            }
            $this->execute('UPDATE '.$this->table('run').' SET status=\'WORKFLOW_EXECUTING\', worker_token=?, deadline_at=?, last_clock_at=?, pause_at=0, slot_held=1, version=version+1 WHERE instance_id=? AND run_id=?',[$workerToken,$now+(int)$r['remaining_ms'],$now,$this->instance,$runId]);
            return $this->publicRun($this->read($owner,$runId,$generation));
        });
    }

    /** Returns false for a duplicate claim: callers MUST NOT execute the request again. */
    public function claim(array $owner, string $runId, int $generation, string $workerToken): bool
    {
        $this->identifier($workerToken);
        return $this->transaction(function () use ($owner,$runId,$generation,$workerToken) {
            $r=$this->read($owner,$runId,$generation); $this->live($r);
            if ($r['worker_token'] !== '') { return false; }
            if (!(int)$r['slot_held'] || $r['status'] !== 'RECEIVED') { throw new RuntimeException('AI_CLAIM_INVALID'); }
            $this->execute('UPDATE '.$this->table('run').' SET worker_token=?, status=\'WORKFLOW_EXECUTING\', version=version+1 WHERE instance_id=? AND run_id=?',[$workerToken,$this->instance,$runId]);
            return true;
        });
    }

    public function checkpoint(array $owner, string $runId, int $generation, string $workerToken): array
    {
        return $this->transaction(function () use ($owner,$runId,$generation,$workerToken) { $r=$this->read($owner,$runId,$generation); $this->worker($r,$workerToken); $this->live($r); return $this->publicRun($r); });
    }

    public function progress(array $owner,string $runId,int $generation,string $workerToken,string $code): array
    {
        if (!in_array($code,['UNDERSTANDING','QUERYING','VERIFYING','EXPORTING','RENDERING'],true)) { throw new RuntimeException('AI_PROGRESS_INVALID'); }
        return $this->transaction(function () use ($owner,$runId,$generation,$workerToken,$code) {
            $r=$this->read($owner,$runId,$generation); $this->worker($r,$workerToken); $this->live($r);
            $this->execute('UPDATE '.$this->table('run').' SET progress_code=?, version=version+1 WHERE instance_id=? AND run_id=?',[$code,$this->instance,$runId]);
            return $this->publicRun($this->read($owner,$runId,$generation));
        });
    }

    public function cancel(array $owner, string $runId, int $generation): array
    {
        return $this->transaction(function () use ($owner,$runId,$generation) {
            $r=$this->read($owner,$runId,$generation);
            if (!$this->terminal($r)) {
                // Unclaimed work has never begun. Claimed work keeps its physical reservation.
                $this->execute('UPDATE '.$this->table('run').' SET status=\'CANCELLED\', reason=\'USER_CANCELLED\', last_clock_at=?, slot_held=?, version=version+1 WHERE instance_id=? AND run_id=?',[$this->now(),$r['worker_token']===''?0:(int)$r['slot_held'],$this->instance,$runId]);
            }
            return $this->publicRun($this->read($owner,$runId,$generation));
        });
    }

    /** The executing worker calls this only AFTER all its local operations have actually stopped. */
    public function release(array $owner, string $runId, int $generation, string $workerToken): void
    {
        $this->transaction(function () use ($owner,$runId,$generation,$workerToken) {
            $r=$this->read($owner,$runId,$generation,true); $this->worker($r,$workerToken);
            if (!$this->terminal($r)) { throw new RuntimeException('AI_RELEASE_BEFORE_STOP'); }
            $this->execute('UPDATE '.$this->table('run').' SET slot_held=0, version=version+1 WHERE instance_id=? AND run_id=?',[$this->instance,$runId]);
        });
    }

    public function publish(array $owner, string $runId, int $generation, string $workerToken, string $evidenceRef, string $answerRef, string $delivery='complete'): array
    {
        $this->identifier($evidenceRef); $this->identifier($answerRef);
        if (!in_array($delivery,['complete','data_only_export_failed'],true)) { throw new RuntimeException('AI_DELIVERY_INVALID'); }
        return $this->transaction(function () use ($owner,$runId,$generation,$workerToken,$evidenceRef,$answerRef,$delivery) {
            $r=$this->read($owner,$runId,$generation); $this->worker($r,$workerToken); $this->live($r);
            if ($r['status']!=='WORKFLOW_EXECUTING' || !(int)$r['slot_held']) { throw new RuntimeException('AI_PUBLICATION_NOT_CLAIMED'); }
            $pending=$this->one('SELECT COUNT(*) AS n FROM '.$this->table('attempt').' WHERE instance_id=? AND run_id=? AND state IN (\'PREPARED\',\'IN_FLIGHT\',\'UNKNOWN\')',[$this->instance,$runId]);
            if ((int)$pending['n']!==0) { throw new RuntimeException('AI_ATTEMPT_UNRESOLVED'); }
            $counters=json_decode($r['counters_json'],true)?:[];
            $counters['export_delivery']=$delivery==='data_only_export_failed'?2:($r['answer_ref']!==''?1:0);
            $this->execute('UPDATE '.$this->table('run').' SET counters_json=? WHERE instance_id=? AND run_id=?',[json_encode($counters),$this->instance,$runId]);
            $this->execute('UPDATE '.$this->table('run').' SET status=?, reason=?, evidence_ref=?, answer_ref=?, version=version+1 WHERE instance_id=? AND run_id=?',[$delivery==='complete'?'COMPLETED':'PARTIAL_SUCCEEDED',$delivery==='complete'?'':'DATA_ONLY_EXPORT_FAILED',$evidenceRef,$answerRef,$this->instance,$runId]);
            return $this->publicRun($this->read($owner,$runId,$generation));
        });
    }

    public function fail(array $owner, string $runId, int $generation, string $workerToken, string $reason): array
    {
        if (!preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D',$reason)) { throw new RuntimeException('AI_REASON_INVALID'); }
        return $this->transaction(function () use ($owner,$runId,$generation,$workerToken,$reason) {
            $r=$this->read($owner,$runId,$generation,true); $this->worker($r,$workerToken);
            if (!$this->terminal($r)) { $this->execute('UPDATE '.$this->table('run').' SET status=\'FAILED\', reason=?, last_clock_at=?, version=version+1 WHERE instance_id=? AND run_id=?',[$reason,$this->now(),$this->instance,$runId]); }
            return $this->publicRun($this->read($owner,$runId,$generation,true));
        });
    }

    /** No payload escapes retention. Unknown occupied slots become aggregate safety quarantine. */
    public function cleanup(): array
    {
        return $this->transaction(function () {
            $now=$this->now();
            $this->execute('UPDATE '.$this->table('run').' SET status=\'FAILED\', reason=CASE WHEN status=\'WAITING_EXPORT\' THEN \'AI_EXPORT_DEADLINE\' ELSE \'DEADLINE_EXCEEDED\' END, last_clock_at=?, slot_held=CASE WHEN worker_token=\'\' THEN 0 ELSE slot_held END, version=version+1 WHERE instance_id=? AND status NOT IN (\'COMPLETED\',\'PARTIAL_SUCCEEDED\',\'FAILED\',\'CANCELLED\',\'WAITING_CLARIFICATION\') AND deadline_at<=?',[$now,$this->instance,$now]);
            $this->execute('UPDATE '.$this->table('run').' SET status=\'FAILED\', reason=\'CLARIFICATION_EXPIRED\', last_clock_at=?, version=version+1 WHERE instance_id=? AND status=\'WAITING_CLARIFICATION\' AND pause_at<=?',[$now,$this->instance,$now-600000]);
            $this->execute('UPDATE '.$this->table('attempt').' SET state=\'UNKNOWN\' WHERE instance_id=? AND target_code=\'siliconflow_probe\' AND state=\'IN_FLIGHT\' AND created_at<=?',[$this->instance,$now-20000]);
            $expired=$this->one('SELECT COUNT(*) AS n, COALESCE(SUM(slot_held),0) AS held FROM '.$this->table('run').' WHERE instance_id=? AND expires_at<=?',[$this->instance,$now]);
            $this->execute('UPDATE '.$this->table('attempt').' SET state=\'UNKNOWN\' WHERE instance_id=? AND state=\'IN_FLIGHT\' AND run_id IN (SELECT run_id FROM '.$this->table('run').' WHERE instance_id=? AND status IN (\'FAILED\',\'CANCELLED\'))',[$this->instance,$this->instance]);
            if ((int)$expired['held']>0) { $this->execute('UPDATE '.$this->table('mutex').' SET quarantined_slots=quarantined_slots+? WHERE instance_id=?',[(int)$expired['held'],$this->instance]); }
            $this->execute('DELETE FROM '.$this->table('run').' WHERE instance_id=? AND expires_at<=?',[$this->instance,$now]);
            $receipts=$this->execute('DELETE FROM '.$this->table('receipt').' WHERE instance_id=? AND expires_at<=?',[$this->instance,$now])->rowCount();
            $attempts=$this->execute('DELETE FROM '.$this->table('attempt').' WHERE instance_id=? AND expires_at<=?',[$this->instance,$now])->rowCount();
            return ['deleted_runs'=>(int)$expired['n'],'deleted_receipts'=>$receipts,'deleted_attempts'=>$attempts,'quarantined_slots'=>(int)$expired['held']];
        });
    }

    /** Admission is serialized with create; receipts/replays do not consume a new Run. */
    private function admissionReason(array $owner,int $now): string
    {
        $n=$this->one('SELECT COUNT(*) AS n FROM '.$this->table('run').' WHERE instance_id=? AND account_id=? AND terminal=? AND created_at>?',[$this->instance,$owner['account_id'],$owner['terminal'],$now-600000]);
        if ((int)$n['n']>=20) return 'RATE_LIMITED';
        $recent=$this->rows('SELECT status,reason,last_clock_at FROM '.$this->table('run').' WHERE instance_id=? AND account_id=? AND terminal=? AND expires_at>? AND status IN (\'COMPLETED\',\'PARTIAL_SUCCEEDED\',\'FAILED\',\'CANCELLED\') ORDER BY last_clock_at DESC,created_at DESC LIMIT 10000',[$this->instance,$owner['account_id'],$owner['terminal'],$now]);
        $failures=0; $latest=0;
        foreach ($recent as $r) {
            $class=self::outcomeClass($r);
            if ($class==='success' || $class==='partial') break;
            if ($class!=='technical') continue;
            if ($latest===0) $latest=(int)$r['last_clock_at'];
            if (++$failures>=5) return $latest+120000>$now?'CIRCUIT_OPEN':'';
        }
        return '';
    }

    /** No subject identifiers or messages in aggregate diagnostics; underlying rows expire at 24 h. */
    public function diagnostics(): array
    {
        return $this->transaction(function () {
            $counts=['success'=>0,'partial'=>0,'technical'=>0,'export'=>0,'export_unknown'=>0,'security'=>0,'capacity'=>0,'neutral'=>0,'active'=>0];
            $rows=$this->rows('SELECT status,reason,COUNT(*) AS n FROM '.$this->table('run').' WHERE instance_id=? AND expires_at>? GROUP BY status,reason',[$this->instance,$this->now()]);
            foreach ($rows as $r) $counts[self::outcomeClass($r)]+=(int)$r['n'];
            $n=$this->one('SELECT COUNT(*) AS n FROM '.$this->table('receipt').' WHERE instance_id=? AND expires_at>? AND reason=\'CAPACITY_REJECTED\'',[$this->instance,$this->now()]);
            $counts['capacity_rejected']=(int)$n['n'];
            $usage=$this->one('SELECT COALESCE(SUM(input_tokens),0) AS input_tokens,COALESCE(SUM(output_tokens),0) AS output_tokens,SUM(CASE WHEN input_tokens IS NOT NULL AND output_tokens IS NOT NULL THEN 1 ELSE 0 END) AS known_usage_attempts,SUM(CASE WHEN state=\'UNKNOWN\' THEN 1 ELSE 0 END) AS unknown_attempts,SUM(CASE WHEN state<>\'PREPARED\' AND (input_tokens IS NULL OR output_tokens IS NULL) THEN 1 ELSE 0 END) AS usage_unknown_attempts FROM '.$this->table('attempt').' WHERE instance_id=? AND expires_at>? AND kind=\'model\'',[$this->instance,$this->now()]);
            $counts['usage']=array_map('intval',$usage);
            $counts['duration']=['terminal_count'=>0,'total_ms'=>0,'max_ms'=>0,'mean_ms'=>null];
            $counts['exports']=['succeeded'=>0,'failed'=>0,'consecutive_failed'=>0,'eligible'=>0,'failure_rate'=>null];
            $runs=$this->rows('SELECT status,reason,created_at,last_clock_at,counters_json FROM '.$this->table('run').' WHERE instance_id=? AND expires_at>? AND status IN (\'COMPLETED\',\'PARTIAL_SUCCEEDED\',\'FAILED\',\'CANCELLED\') ORDER BY last_clock_at DESC,created_at DESC',[$this->instance,$this->now()]);
            $streakOpen=true;
            foreach ($runs as $r) {
                $ms=max(0,(int)$r['last_clock_at']-(int)$r['created_at']);
                ++$counts['duration']['terminal_count']; $counts['duration']['total_ms']+=$ms; $counts['duration']['max_ms']=max($counts['duration']['max_ms'],$ms);
                $delivery=(int)((json_decode($r['counters_json'],true)?:[])['export_delivery']??0);
                if ($r['status']==='PARTIAL_SUCCEEDED') { ++$counts['exports']['failed']; if($streakOpen) ++$counts['exports']['consecutive_failed']; }
                elseif ($r['status']==='COMPLETED' && $delivery===1) { ++$counts['exports']['succeeded']; $streakOpen=false; }
                // Unknown, cancelled, rejected and unsafe outcomes are not file-rate samples.
            }
            $d=&$counts['duration']; if($d['terminal_count']) $d['mean_ms']=(int)round($d['total_ms']/$d['terminal_count']); unset($d);
            $x=&$counts['exports']; $x['eligible']=$x['succeeded']+$x['failed']; if($x['eligible'])$x['failure_rate']=$x['failed']/$x['eligible']; unset($x);
            return $counts;
        });
    }

    private static function outcomeClass(array $r): string
    {
        if ($r['status']==='COMPLETED') return 'success';
        if ($r['status']==='PARTIAL_SUCCEEDED') return 'partial';
        if ($r['status']==='CANCELLED') return 'neutral';
        if ($r['status']!=='FAILED') return 'active';
        if (in_array($r['reason'],['CAPACITY_STOPPED','CAPACITY_REJECTED','AI_EXPORT_CAPACITY_REJECTED'],true)) return 'capacity';
        if (in_array($r['reason'],['AI_EXPORT_NOT_READY','AI_EXPORT_PRINCIPAL_UNAVAILABLE','AI_EXPORT_NOT_PUBLISHED','AI_EXPORT_EXPIRED','METRIC_NOT_REGISTERED','METRIC_PERMISSION_CHANGED','METRIC_PERMISSION_DENIED','METRIC_PERMISSION_GRAIN_UNAVAILABLE','METRIC_QUERY_COVERAGE_UNAVAILABLE','METRIC_QUERY_RANGE_INVALID','METRIC_QUERY_SCHEMA_INVALID','METRIC_QUERY_SHAPE_UNAVAILABLE','METRIC_SEMANTICS_CONFLICT','CLARIFICATION_EXPIRED','AI_PERMISSION_DENIED','AI_AUTHORIZATION_CHANGED','AI_CAPABILITY_CHANGED','AI_INPUT_SCHEMA_INVALID','AI_UNSUPPORTED_CONDITION','AI_METRIC_NOT_READY','AI_QUERY_SHAPE_NOT_READY','AI_OUTPUT_FORMAT_INVALID','AI_CLARIFICATION_INVALID','AI_EVIDENCE_INCOMPLETE','AI_CANCELLED','AI_NOT_CONFIGURED','AI_METRIC_EXPLANATION_NOT_READY','AI_EXTERNAL_AUTHORIZATION_REQUIRED'],true)) return 'neutral';
        if (in_array($r['reason'],['AI_EXPORT_DISPATCH_UNKNOWN','AI_EXPORT_CANCELLATION_UNKNOWN'],true)) return 'export_unknown';
        if (in_array($r['reason'],['AI_EXPORT_CONTENT_MISMATCH','AI_EXPORT_SIGNATURE_INVALID','AI_EXPORT_SOURCE_MISMATCH','AI_EXPORT_OWNER_MISMATCH','AI_EXPORT_BINDING_INVALID','AI_EXPORT_TASK_BINDING_INVALID','AI_EXPORT_HANDOFF_INVALID','AI_EXPORT_UNSAFE_FAILURE','METRIC_READ_BINDING_MISMATCH'],true)) return 'security';
        if (in_array($r['reason'],['AI_EXPORT_FAILED','AI_EXPORT_DEADLINE','DATA_ONLY_EXPORT_FAILED'],true)) return 'export';
        return 'technical';
    }

    private function transaction(callable $fn)
    {
        if ($this->db->inTransaction()) { throw new RuntimeException('AI_STORE_NESTED_TRANSACTION'); }
        if ($this->sqlite) { $this->db->exec('BEGIN IMMEDIATE'); } else { $this->db->beginTransaction(); }
        try {
            $sql=($this->sqlite?'INSERT OR IGNORE':'INSERT IGNORE').' INTO '.$this->table('mutex').' (instance_id) VALUES (?)';
            $this->execute($sql,[$this->instance]);
            $this->one('SELECT instance_id FROM '.$this->table('mutex').' WHERE instance_id=?'.($this->sqlite?'':' FOR UPDATE'),[$this->instance]);
            $result=$fn();
            if ($this->sqlite) { $this->db->exec('COMMIT'); } else { $this->db->commit(); }
            return $result;
        } catch (\Throwable $e) {
            if ($this->sqlite) { $this->db->exec('ROLLBACK'); } elseif ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    private function read(array $owner,string $id,$generation,bool $allowExpired=false): array
    {
        $this->owner($owner); $this->identifier($id);
        $r=$this->one('SELECT * FROM '.$this->table('run').' WHERE instance_id=? AND run_id=?',[$this->instance,$id]);
        if (!$r) { throw new RuntimeException('AI_RUN_NOT_FOUND'); }
        foreach ($owner as $k=>$v) { if ((string)$r[$k] !== (string)$v) { throw new RuntimeException('AI_RUN_NOT_FOUND'); } }
        if ($generation !== null && (int)$r['generation'] !== $generation) { throw new RuntimeException('AI_RUN_NOT_FOUND'); }
        if (!$allowExpired && (int)$r['expires_at'] <= $this->now()) { throw new RuntimeException('AI_RUN_EXPIRED'); }
        return $r;
    }

    private function live(array $r): void
    {
        if ($this->terminal($r)) { throw new RuntimeException('AI_RUN_TERMINAL'); }
        $now=$this->now();
        if ($now < (int)$r['last_clock_at'] || $now >= (int)$r['deadline_at']) { throw new RuntimeException('AI_RUN_DEADLINE'); }
        $this->execute('UPDATE '.$this->table('run').' SET last_clock_at=? WHERE instance_id=? AND run_id=?',[$now,$this->instance,$r['run_id']]);
    }
    private function worker(array $r,string $token): void { if ($token==='' || !hash_equals($r['worker_token'],$token)) { throw new RuntimeException('AI_WORKER_FENCED'); } }
    private function terminal(array $r): bool { return in_array($r['status'],['COMPLETED','PARTIAL_SUCCEEDED','FAILED','CANCELLED'],true); }
    private function owner(array $owner): void
    {
        if (count($owner)!==4 || !isset($owner['account_id']) || !is_int($owner['account_id']) || $owner['account_id']<1) { throw new RuntimeException('AI_OWNER_INVALID'); }
        foreach (['terminal','conversation_id','window_id'] as $k) { if (!isset($owner[$k]) || !is_string($owner[$k])) { throw new RuntimeException('AI_OWNER_INVALID'); } $this->identifier($owner[$k]); }
        if (!in_array($owner['terminal'],['platform','store','merchant'],true)) { throw new RuntimeException('AI_OWNER_INVALID'); }
    }
    private function identifier(string $v): void { if (!preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D',$v)) { throw new RuntimeException('AI_REFERENCE_INVALID'); } }
    private function now(): int { $v=call_user_func($this->clock); if (!is_int($v)||$v<1) { throw new RuntimeException('AI_CLOCK_INVALID'); } return $v; }
    private function table(string $name): string { return $this->prefix.'mohe_ai_'.$name; }
    private function execute(string $sql,array $params) { $s=$this->db->prepare($sql); $s->execute($params); return $s; }
    private function one(string $sql,array $params) { return $this->execute($sql,$params)->fetch(PDO::FETCH_ASSOC); }
    private function rows(string $sql,array $params): array { return $this->execute($sql,$params)->fetchAll(PDO::FETCH_ASSOC); }
    private function attempt(string $runId,string $code) { return $this->one('SELECT * FROM '.$this->table('attempt').' WHERE instance_id=? AND run_id=? AND attempt_code=?',[$this->instance,$runId,$code]); }
    private function insert(string $name,array $r): void { $this->execute('INSERT INTO '.$this->table($name).' ('.implode(',',array_keys($r)).') VALUES ('.implode(',',array_fill(0,count($r),'?')).')',array_values($r)); }
    private function publicRun(array $r): array
    {
        $out=[];
        foreach (['run_id','generation','status','reason','progress_code','clarification_ref','version','created_at','expires_at','deadline_at','slot_held','evidence_ref','answer_ref'] as $k) { $out[$k]=$r[$k]; }
        foreach (['generation','version','created_at','expires_at','deadline_at','slot_held'] as $k) { $out[$k]=(int)$out[$k]; }
        return $out;
    }
}
