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
    private $clockRegressionMs = null;
    private $previousClockSample = null;
    private $minimumClockDelta = 0;

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
        $guidanceKeys=['guidance_schema_version','guidance_profile_version','max_clarification_rounds','management_revision','intent_contract_version'];
        if (array_diff(array_keys($snapshot),array_merge($keys,$guidanceKeys))) { throw new RuntimeException('AI_SNAPSHOT_INVALID'); }
        foreach ($keys as $key) { if (!isset($snapshot[$key]) || !is_string($snapshot[$key])) { throw new RuntimeException('AI_SNAPSHOT_INVALID'); } $this->identifier($snapshot[$key]); }
        if (array_intersect(array_keys($snapshot),$guidanceKeys)) {
            if (($snapshot['guidance_schema_version']??'')!=='mohe-clarification-v2' || !in_array($snapshot['max_clarification_rounds']??null,['3','4','5'],true)
                || !is_string($snapshot['guidance_profile_version']??null)) { throw new RuntimeException('AI_SNAPSHOT_INVALID'); }
            $this->identifier($snapshot['guidance_profile_version']);
            if (isset($snapshot['management_revision'])) $this->identifier($snapshot['management_revision']);
            if (isset($snapshot['intent_contract_version'])) $this->identifier($snapshot['intent_contract_version']);
        }
        if (!preg_match('/^[a-f0-9]{64}$/D',$snapshot['capability_snapshot_hash'])) { throw new RuntimeException('AI_SNAPSHOT_INVALID'); }
        return $this->transaction(function () use ($owner, $requestId, $requestHash, $snapshot, $budgetMs, $capacity, $activeLimit) {
            $now = $this->now();
            // A new request is also an admission checkpoint.  The background
            // supervisor is intentionally best-effort, so it must not be the
            // only way a disconnected client can stop an expired Run from
            // blocking its next question.
            $this->reconcileAdmissionRuns($owner,$now);
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
        return $this->transaction(function () use ($owner,$runId,$generation) {
            $r=$this->read($owner,$runId,$generation); $counts=json_decode($r['counters_json'],true)?:[];
            // A status read is also a safe deadline fence for unclaimed work.
            // Do not wait for the periodic supervisor before telling a client
            // that a request which can no longer be executed has ended.
            if (!$this->terminal($r) && $r['status']!=='WAITING_CLARIFICATION' && (int)$r['deadline_at']<=$this->now()) {
                $this->expireRun($r);
                $r=$this->read($owner,$runId,$generation); $counts=json_decode($r['counters_json'],true)?:[];
            }
            if ($r['status']==='WAITING_CLARIFICATION' && $this->now()-(int)$r['pause_at']+($counts['clarification_wait_ms']??0)>=600000) {
                $this->execute('UPDATE '.$this->table('run').' SET status=\'FAILED\',reason=\'CLARIFICATION_EXPIRED\',version=version+1,last_clock_at=? WHERE instance_id=? AND run_id=?',[$this->now(),$this->instance,$runId]);
                $r=$this->read($owner,$runId,$generation);
            }
            return $this->publicRun($r);
        });
    }

    public function assertRequest(array $owner, string $runId, int $generation, string $requestHash): void
    {
        $this->transaction(function () use ($owner,$runId,$generation,$requestHash) {
            $this->read($owner,$runId,$generation);
            $receipt=$this->one('SELECT request_hash FROM '.$this->table('receipt').' WHERE instance_id=? AND run_id=?',[$this->instance,$runId]);
            if (!$receipt || !hash_equals($receipt['request_hash'],$requestHash)) { throw new RuntimeException('AI_IDEMPOTENCY_CONFLICT'); }
        });
    }

    /**
     * The database only retains a signed reference and hash.  The encrypted
     * request itself lives in AiPrivateStorage, and the queue carries only a
     * Run id.  This makes the create response a real acceptance point rather
     * than a promise that a later browser /execute request will arrive.
     */
    public function queueExecution(array $owner,string $runId,int $generation,string $operation,string $requestRef,string $requestHash,bool $allowCreateReplay=false): array
    {
        if (!in_array($operation,['execute','clarify'],true) || !preg_match('/^request-[a-f0-9]{48}$/D',$requestRef)
            || !preg_match('/^[a-f0-9]{64}$/D',$requestHash)) throw new RuntimeException('AI_EXECUTION_QUEUE_INVALID');
        return $this->transaction(function () use($owner,$runId,$generation,$operation,$requestRef,$requestHash,$allowCreateReplay) {
            $r=$this->read($owner,$runId,$generation); $counts=json_decode($r['counters_json'],true)?:[];
            $expected=$operation==='execute'?'RECEIVED':'WAITING_CLARIFICATION';
            if (($counts['execution_operation']??'')===$operation && ($counts['execution_hash']??'')===$requestHash) {
                return $this->publicRun($r)+['execution_replayed'=>true];
            }
            // A clarification acknowledgement is a single immutable choice.
            // Once the queue has accepted it, a later request must either be
            // the same idempotent submission or wait for the worker's result;
            // otherwise it could replace what the customer actually chose.
            if ($operation==='clarify' && ($counts['execution_operation']??'')==='clarify'
                && in_array($counts['execution_state']??'', ['QUEUED','DISPATCHING'],true)) {
                throw new RuntimeException('AI_CLARIFICATION_IN_PROGRESS');
            }
            if ($r['status']!==$expected || $r['worker_token']!=='') {
                // A retried /runs request has already passed receipt/body
                // idempotency validation in the gateway.  Once its worker has
                // claimed or terminalized the Run, there is nothing left to
                // enqueue; returning the current projection lets a refreshed
                // client resume polling the original task.
                if ($allowCreateReplay && $operation==='execute') return $this->publicRun($r)+['execution_replayed'=>true];
                throw new RuntimeException('AI_EXECUTION_QUEUE_INVALID');
            }
            $counts['execution_operation']=$operation;
            $counts['execution_ref']=$requestRef;
            $counts['execution_hash']=$requestHash;
            $counts['execution_state']='QUEUED';
            $acceptedAt=$this->now();
            $counts['execution_accepted_at']=$acceptedAt;
            $counts['execution_queued_at']=$acceptedAt;
            $segments=is_array($counts['execution_segments']??null)?$counts['execution_segments']:[];
            // Keep the initial request and each clarification continuation as
            // separate latency samples. This is operational telemetry only.
            if (count($segments)>=5) throw new RuntimeException('AI_EXECUTION_QUEUE_INVALID');
            $segments[]=['operation'=>$operation,'mode'=>'async','accepted_at'=>$acceptedAt,'queued_at'=>$acceptedAt];
            $counts['execution_segments']=$segments;
            $counts['execution_segment_index']=count($segments)-1;
            $counts['execution_mode']='async';
            if ($operation==='clarify') unset($counts['clarification_rejected_ref']);
            $counts['execution_dispatch_count']=(int)($counts['execution_dispatch_count']??0);
            $this->execute('UPDATE '.$this->table('run').' SET counters_json=?, version=version+1 WHERE instance_id=? AND run_id=?',[
                json_encode($counts),$this->instance,$runId
            ]);
            return $this->publicRun($this->read($owner,$runId,$generation));
        });
    }

    /**
     * Compatibility execution is still an execution sample.  Record it in
     * exactly the same per-operation shape as a queued Run, so performance
     * reports never compare an async queue-inclusive duration with a sync
     * execution-only duration.  This contains timestamps only, no question,
     * answer, identity, or model payload.
     */
    public function beginCompatibilityExecution(array $owner,string $runId,int $generation,string $operation): array
    {
        if (!in_array($operation,['execute','clarify'],true)) throw new RuntimeException('AI_EXECUTION_QUEUE_INVALID');
        return $this->transaction(function () use($owner,$runId,$generation,$operation) {
            $r=$this->read($owner,$runId,$generation); $counts=json_decode($r['counters_json'],true)?:[];
            if ($this->terminal($r) || $r['worker_token']!=='') return $this->publicRun($r);
            $expected=$operation==='execute'?'RECEIVED':'WAITING_CLARIFICATION';
            if ($r['status']!==$expected) return $this->publicRun($r);
            $segments=is_array($counts['execution_segments']??null)?$counts['execution_segments']:[];
            $index=(int)($counts['execution_segment_index']??-1);
            if (($counts['execution_mode']??'')==='compatibility' && isset($segments[$index])
                && is_array($segments[$index]) && ($segments[$index]['operation']??'')===$operation) {
                return $this->publicRun($r);
            }
            if (count($segments)>=5) throw new RuntimeException('AI_EXECUTION_QUEUE_INVALID');
            $now=$this->now();
            $segments[]=['operation'=>$operation,'mode'=>'compatibility','accepted_at'=>$now,'queued_at'=>$now,'started_at'=>$now];
            $counts['execution_segments']=$segments;
            $counts['execution_segment_index']=count($segments)-1;
            $counts['execution_mode']='compatibility';
            $counts['execution_accepted_at']=$now;
            $counts['execution_queued_at']=$now;
            $counts['execution_started_at']=$now;
            $this->execute('UPDATE '.$this->table('run').' SET counters_json=?, version=version+1 WHERE instance_id=? AND run_id=?',[
                json_encode($counts),$this->instance,$runId
            ]);
            return $this->publicRun($this->read($owner,$runId,$generation));
        });
    }

    /**
     * Stores one bounded browser-observed elapsed duration for the current
     * execution segment. The browser's number is intentionally kept separate
     * from authoritative server timings: it measures click-to-render, but can
     * never affect workflow state, permissions, answer content, or health
     * decisions. A duplicate notification is an idempotent no-op.
     */
    public function recordClientDelivery(array $owner,string $runId,int $generation,int $elapsedMs): void
    {
        if ($elapsedMs<0 || $elapsedMs>300000) throw new RuntimeException('AI_EXECUTION_QUEUE_INVALID');
        $this->transaction(function () use($owner,$runId,$generation,$elapsedMs) {
            $r=$this->read($owner,$runId,$generation);
            if (!in_array($r['status'],['COMPLETED','PARTIAL_SUCCEEDED'],true)) return;
            $counts=json_decode($r['counters_json'],true)?:[];
            // Private-envelope retirement removes the active index after the
            // Worker finishes.  A terminal Run cannot gain another segment,
            // so the retained final array item is the only safe target for a
            // first browser-visible answer receipt.
            $segments=is_array($counts['execution_segments']??null)?$counts['execution_segments']:[];
            $index=count($segments)-1;
            if ($index<0 || !isset($segments[$index]) || !is_array($segments[$index])) return;
            // First visible render is the comparable outcome. A reload or
            // duplicate poll cannot replace it with a later duration.
            if (isset($segments[$index]['client_elapsed_ms'])) return;
            $segments[$index]['client_elapsed_ms']=$elapsedMs;
            $segments[$index]['client_reported_at']=$this->now();
            $counts['execution_segments']=$segments;
            $this->execute('UPDATE '.$this->table('run').' SET counters_json=? WHERE instance_id=? AND run_id=?',[
                json_encode($counts),$this->instance,$runId
            ]);
        });
    }

    /** Internal worker lookup.  Never expose this envelope through status. */
    public function queuedExecution(string $runId): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/D',$runId)) return null;
        return $this->transaction(function () use($runId) {
            $r=$this->one('SELECT * FROM '.$this->table('run').' WHERE instance_id=? AND run_id=?',[$this->instance,$runId]);
            if (!$r || (int)$r['expires_at']<=$this->now()) return null;
            $counts=json_decode($r['counters_json'],true)?:[];
            if (($counts['execution_state']??'')!=='QUEUED' || !in_array($counts['execution_operation']??'', ['execute','clarify'],true)
                || !is_string($counts['execution_ref']??null) || !preg_match('/^request-[a-f0-9]{48}$/D',$counts['execution_ref'])
                || !is_string($counts['execution_hash']??null) || !preg_match('/^[a-f0-9]{64}$/D',$counts['execution_hash'])) return null;
            $expected=$counts['execution_operation']==='execute'?'RECEIVED':'WAITING_CLARIFICATION';
            if ($r['status']!==$expected || $r['worker_token']!=='') return null;
            // A queued message is only an invitation to start work, never an
            // extension of the Run's original execution budget.
            if ((int)$r['deadline_at']<=$this->now()) {
                $this->expireRun($r);
                return null;
            }
            $counts['execution_state']='DISPATCHING';
            $counts['execution_dispatching_at']=$this->now();
            $counts['execution_dispatch_count']=(int)($counts['execution_dispatch_count']??0)+1;
            $this->execute('UPDATE '.$this->table('run').' SET counters_json=?, version=version+1 WHERE instance_id=? AND run_id=?',[
                json_encode($counts),$this->instance,$runId
            ]);
            return ['owner'=>['account_id'=>(int)$r['account_id'],'terminal'=>$r['terminal'],'conversation_id'=>$r['conversation_id'],'window_id'=>$r['window_id']],
                'run_id'=>$r['run_id'],'generation'=>(int)$r['generation'],'operation'=>$counts['execution_operation'],'request_ref'=>$counts['execution_ref'],'request_hash'=>$counts['execution_hash'],
                'segment_index'=>(int)($counts['execution_segment_index']??-1)];
        });
    }

    /** A queue message can be duplicated.  Only an untouched queued Run may be offered again. */
    public function restoreQueuedExecution(string $runId): void
    {
        if (!preg_match('/^[a-f0-9]{48}$/D',$runId)) return;
        $this->transaction(function () use($runId) {
            $r=$this->one('SELECT * FROM '.$this->table('run').' WHERE instance_id=? AND run_id=?',[$this->instance,$runId]);
            if (!$r || $r['worker_token']!=='' || !in_array($r['status'],['RECEIVED','WAITING_CLARIFICATION'],true)) return;
            $counts=json_decode($r['counters_json'],true)?:[];
            if (($counts['execution_state']??'')==='DISPATCHING') {
                $counts['execution_state']='QUEUED';
                unset($counts['execution_dispatching_at']);
                $this->execute('UPDATE '.$this->table('run').' SET counters_json=?, version=version+1 WHERE instance_id=? AND run_id=?',[json_encode($counts),$this->instance,$runId]);
            }
        });
    }

    /**
     * Returns whether a durable request still needs a queue message.  A worker
     * can die after reserving the envelope but before the gateway claims a
     * Run; that period is safe to redeliver because worker_token is still
     * empty and the gateway claim is the only model-call admission point.
     */
    public function executionNeedsDispatch(array $owner,string $runId,int $generation,int $staleMilliseconds=15000): bool
    {
        if ($staleMilliseconds<1000 || $staleMilliseconds>300000) throw new RuntimeException('AI_EXECUTION_QUEUE_INVALID');
        return $this->transaction(function () use($owner,$runId,$generation,$staleMilliseconds) {
            $r=$this->read($owner,$runId,$generation);
            if ($r['worker_token']!=='' || !in_array($r['status'],['RECEIVED','WAITING_CLARIFICATION'],true)) return false;
            $counts=json_decode($r['counters_json'],true)?:[];
            if ($this->restoreStaleDispatch($counts,$staleMilliseconds)) {
                $this->execute('UPDATE '.$this->table('run').' SET counters_json=?, version=version+1 WHERE instance_id=? AND run_id=?',[json_encode($counts),$this->instance,$runId]);
            }
            return ($counts['execution_state']??'')==='QUEUED' && is_string($counts['execution_ref']??null);
        });
    }

    /** Only pre-claim validation failures may release a queued reservation. */
    public function failQueuedExecution(string $runId,string $reason): void
    {
        if (!preg_match('/^[a-f0-9]{48}$/D',$runId) || !preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D',$reason)) return;
        $this->transaction(function () use($runId,$reason) {
            $r=$this->one('SELECT * FROM '.$this->table('run').' WHERE instance_id=? AND run_id=?',[$this->instance,$runId]);
            if (!$r || $r['worker_token']!=='' || !in_array($r['status'],['RECEIVED','WAITING_CLARIFICATION'],true)) return;
            $counts=json_decode($r['counters_json'],true)?:[];
            if (($counts['execution_state']??'')!=='DISPATCHING') return;
            $this->execute('UPDATE '.$this->table('run').' SET status=\'FAILED\', reason=?, slot_held=0, last_clock_at=?, version=version+1 WHERE instance_id=? AND run_id=?',[$reason,$this->now(),$this->instance,$runId]);
        });
    }

    /** Bounded supervisor feed for requests accepted while Redis was unavailable. */
    public function pendingExecutionIds(int $limit=32): array
    {
        if ($limit<1 || $limit>128) throw new RuntimeException('AI_EXECUTION_QUEUE_INVALID');
        return $this->transaction(function () use($limit) {
            $ids=[];
            // The queue state is encoded by this class with compact JSON.  Do
            // the coarse filter in SQL first: a user paused at a clarification
            // must never consume a supervisor page merely because it shares a
            // WAITING_CLARIFICATION status with a queued follow-up.
            $now=$this->now();
            $rows=$this->rows('SELECT run_id,counters_json FROM '.$this->table('run').' WHERE instance_id=? AND status IN (\'RECEIVED\',\'WAITING_CLARIFICATION\') AND worker_token=\'\' AND expires_at>? AND deadline_at>? AND (counters_json LIKE ? OR counters_json LIKE ?) ORDER BY created_at ASC LIMIT '.$limit,[
                $this->instance,$now,$now,'%' . '"execution_state":"QUEUED"' . '%','%' . '"execution_state":"DISPATCHING"' . '%'
            ]);
            foreach ($rows as $row) {
                $counts=json_decode($row['counters_json'],true)?:[];
                if ($this->restoreStaleDispatch($counts,15000)) {
                    $this->execute('UPDATE '.$this->table('run').' SET counters_json=?, version=version+1 WHERE instance_id=? AND run_id=?',[json_encode($counts),$this->instance,$row['run_id']]);
                }
                if (($counts['execution_state']??'')==='QUEUED' && is_string($counts['execution_ref']??null)) $ids[]=$row['run_id'];
            }
            return $ids;
        });
    }

    private function restoreStaleDispatch(array &$counts,int $staleMilliseconds): bool
    {
        if (($counts['execution_state']??'')!=='DISPATCHING') return false;
        $started=(int)($counts['execution_dispatching_at']??0);
        if ($started<1 || $this->now()-$started<$staleMilliseconds) return false;
        $counts['execution_state']='QUEUED';
        unset($counts['execution_dispatching_at']);
        return true;
    }

    /**
     * A dedicated consumer sends this compact heartbeat while it is listening.
     * It contains process metadata only; no owner, query, prompt or result is
     * stored here.  The release gate refuses async admission without one.
     */
    public function heartbeatExecutionConsumer(string $workerId,int $processId,string $host,int $ttlSeconds=210): void
    {
        if (strpos($workerId,'supervisor-')===0) throw new RuntimeException('AI_EXECUTION_WORKER_INVALID');
        $this->heartbeatExecutionRole($workerId,$processId,$host,$ttlSeconds);
    }

    /** The supervisor independently redelivers a missed first queue push. */
    public function heartbeatExecutionSupervisor(string $supervisorId,int $processId,string $host,int $ttlSeconds=210): void
    {
        if (strpos($supervisorId,'supervisor-')!==0) throw new RuntimeException('AI_EXECUTION_WORKER_INVALID');
        $this->heartbeatExecutionRole($supervisorId,$processId,$host,$ttlSeconds);
    }

    private function heartbeatExecutionRole(string $workerId,int $processId,string $host,int $ttlSeconds): void
    {
        $this->identifier($workerId); $this->identifier($host);
        if ($processId<1 || $processId>2147483647 || $ttlSeconds<30 || $ttlSeconds>300) throw new RuntimeException('AI_EXECUTION_WORKER_INVALID');
        $this->transaction(function () use($workerId,$processId,$host,$ttlSeconds) {
            $now=$this->now();
            $sql=$this->sqlite
                ? 'INSERT INTO '.$this->table('execution_worker').' (instance_id,worker_id,host_name,process_id,heartbeat_at,expires_at) VALUES (?,?,?,?,?,?) ON CONFLICT(instance_id,worker_id) DO UPDATE SET host_name=excluded.host_name,process_id=excluded.process_id,heartbeat_at=excluded.heartbeat_at,expires_at=excluded.expires_at'
                : 'INSERT INTO '.$this->table('execution_worker').' (instance_id,worker_id,host_name,process_id,heartbeat_at,expires_at) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE host_name=VALUES(host_name),process_id=VALUES(process_id),heartbeat_at=VALUES(heartbeat_at),expires_at=VALUES(expires_at)';
            $this->execute($sql,[$this->instance,$workerId,$host,$processId,$now,$now+$ttlSeconds*1000]);
        });
    }

    public function hasLiveExecutionConsumer(int $staleSeconds): bool
    {
        if ($staleSeconds<30 || $staleSeconds>300) return false;
        return $this->transaction(function () use($staleSeconds) {
            $now=$this->now();
            $row=$this->one('SELECT COUNT(*) AS n FROM '.$this->table('execution_worker').' WHERE instance_id=? AND worker_id NOT LIKE ? AND heartbeat_at>? AND expires_at>?',[$this->instance,'supervisor-%',$now-$staleSeconds*1000,$now]);
            return (int)($row['n']??0)>0;
        });
    }

    public function hasLiveExecutionSupervisor(int $staleSeconds): bool
    {
        if ($staleSeconds<30 || $staleSeconds>300) return false;
        return $this->transaction(function () use($staleSeconds) {
            $now=$this->now();
            $row=$this->one('SELECT COUNT(*) AS n FROM '.$this->table('execution_worker').' WHERE instance_id=? AND worker_id LIKE ? AND heartbeat_at>? AND expires_at>?',[$this->instance,'supervisor-%',$now-$staleSeconds*1000,$now]);
            return (int)($row['n']??0)>0;
        });
    }

    public function cleanupExpiredExecutionConsumers(): int
    {
        return $this->transaction(function () {
            return $this->execute('DELETE FROM '.$this->table('execution_worker').' WHERE instance_id=? AND expires_at<=?',[$this->instance,$this->now()])->rowCount();
        });
    }

    /**
     * Returns and atomically detaches encrypted request references for Runs
     * which are terminal before a gateway worker could claim them.  The
     * supervisor owns the subsequent private-store deletion; a failed delete
     * merely leaves an already-unreachable object for normal 24-hour expiry.
     */
    public function takeTerminalUnclaimedExecutionInputRefs(int $limit=64): array
    {
        if ($limit<1 || $limit>256) throw new RuntimeException('AI_EXECUTION_QUEUE_INVALID');
        return $this->transaction(function () use($limit) {
            $rows=$this->rows('SELECT run_id,counters_json FROM '.$this->table('run').' WHERE instance_id=? AND worker_token=\'\' AND status IN (\'COMPLETED\',\'PARTIAL_SUCCEEDED\',\'FAILED\',\'CANCELLED\') AND counters_json LIKE ? ORDER BY last_clock_at ASC LIMIT '.$limit,[
                $this->instance,'%' . '"execution_ref":"request-' . '%'
            ]);
            $refs=[];
            foreach ($rows as $row) {
                $counts=json_decode($row['counters_json'],true)?:[];
                $ref=$counts['execution_ref']??null;
                if (!is_string($ref) || !preg_match('/^request-[a-f0-9]{48}$/D',$ref)) continue;
                $this->retireExecutionEnvelope($counts);
                $this->execute('UPDATE '.$this->table('run').' SET counters_json=?, version=version+1 WHERE instance_id=? AND run_id=?',[json_encode($counts),$this->instance,$row['run_id']]);
                $refs[]=$ref;
            }
            return $refs;
        });
    }

    /** The queue job records the exact local process before it can claim a Run. */
    public function startQueuedExecutionWorker(string $runId,string $workerId,int $processId,string $host): void
    {
        $this->identifier($runId); $this->identifier($workerId); $this->identifier($host);
        if ($processId<1 || $processId>2147483647) throw new RuntimeException('AI_EXECUTION_WORKER_INVALID');
        $this->transaction(function () use($runId,$workerId,$processId,$host) {
            $r=$this->one('SELECT * FROM '.$this->table('run').' WHERE instance_id=? AND run_id=?',[$this->instance,$runId]);
            if (!$r) return;
            $counts=json_decode($r['counters_json'],true)?:[];
            if (($counts['execution_state']??'')!=='DISPATCHING' || $r['worker_token']!=='' || !in_array($r['status'],['RECEIVED','WAITING_CLARIFICATION'],true)) return;
            $counts['execution_worker']=['id'=>$workerId,'host'=>$host,'pid'=>$processId,'started_at'=>$this->now()];
            $counts['execution_started_at']=$counts['execution_worker']['started_at'];
            $this->segmentAt($counts,(int)($counts['execution_segment_index']??-1),'started_at',$counts['execution_started_at']);
            $this->execute('UPDATE '.$this->table('run').' SET counters_json=?, version=version+1 WHERE instance_id=? AND run_id=?',[json_encode($counts),$this->instance,$runId]);
        });
    }

    public function finishQueuedExecutionWorker(string $runId,string $workerId): void
    {
        $this->identifier($runId); $this->identifier($workerId);
        $this->transaction(function () use($runId,$workerId) {
            $r=$this->one('SELECT counters_json FROM '.$this->table('run').' WHERE instance_id=? AND run_id=?',[$this->instance,$runId]);
            if (!$r) return;
            $counts=json_decode($r['counters_json'],true)?:[];
            if (($counts['execution_worker']['id']??null)!==$workerId) return;
            unset($counts['execution_worker']);
            $this->execute('UPDATE '.$this->table('run').' SET counters_json=?, version=version+1 WHERE instance_id=? AND run_id=?',[json_encode($counts),$this->instance,$runId]);
        });
    }

    /**
     * A claimed Run is never retried.  It becomes failed only when the local
     * supervisor proves the recorded worker PID is gone. A terminal fence
     * prevents a delayed/duplicate worker from publishing afterwards.
     */
    public function recoverStoppedExecutionWorkers(int $staleSeconds,callable $isStopped): int
    {
        if ($staleSeconds<30 || $staleSeconds>300) throw new RuntimeException('AI_EXECUTION_WORKER_INVALID');
        return $this->transaction(function () use($staleSeconds,$isStopped) {
            $now=$this->now(); $recovered=0;
            $rows=$this->rows('SELECT run_id,counters_json FROM '.$this->table('run').' WHERE instance_id=? AND status=\'WORKFLOW_EXECUTING\' AND worker_token<>\'\'',[$this->instance]);
            foreach ($rows as $row) {
                $counts=json_decode($row['counters_json'],true)?:[]; $worker=$counts['execution_worker']??null;
                if (!is_array($worker) || !is_string($worker['id']??null) || !is_string($worker['host']??null)
                    || !is_int($worker['pid']??null) || !is_int($worker['started_at']??null) || $now-$worker['started_at']<$staleSeconds*1000) continue;
                if (!$isStopped($worker['host'],$worker['pid'])) continue;
                $this->execute('UPDATE '.$this->table('attempt').' SET state=\'UNKNOWN\' WHERE instance_id=? AND run_id=? AND state IN (\'PREPARED\',\'IN_FLIGHT\')',[$this->instance,$row['run_id']]);
                unset($counts['execution_worker']); $counts['execution_worker_lost_at']=$now;
                // PID absence is the terminal fence: clearing this token both
                // prevents a late Worker from publishing and makes its now
                // unusable encrypted request eligible for immediate cleanup.
                $this->execute('UPDATE '.$this->table('run').' SET status=\'FAILED\',reason=\'AI_EXECUTION_WORKER_LOST\',slot_held=0,worker_token=\'\',last_clock_at=?,counters_json=?,version=version+1 WHERE instance_id=? AND run_id=?',[$now,json_encode($counts),$this->instance,$row['run_id']]);
                ++$recovered;
            }
            // Keep consumer heartbeat cleanup out of recovery.  Recovery must
            // work safely during a staged rollout before this additive table
            // has been migrated on an older instance.
            return $recovered;
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
    public function exportFence(array $owner,string $runId,int $generation,string $workerToken,string $phase,callable $action,bool $independent=false)
    {
        return $this->transaction(function () use ($owner,$runId,$generation,$workerToken,$phase,$action,$independent) {
            $r=$this->read($owner,$runId,$generation);
            if (!in_array($phase,['create','claim','complete','heartbeat','failure','cancel','status','download'],true)) { throw new RuntimeException('AI_EXPORT_PHASE_INVALID'); }
            // A completed answer is immutable. Its file task has its own
            // deadline and must never reacquire the answer's execution slot.
            if ($independent) {
                if ($r['status']!=='COMPLETED' || $r['evidence_ref']==='' || $r['answer_ref']==='') throw new RuntimeException('AI_EXPORT_NOT_PUBLISHED');
            } elseif ($phase==='download') {
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
            if ($this->hasUnresolvedAttempt($runId)) { throw new RuntimeException('AI_ATTEMPT_UNRESOLVED'); }
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
            if ($counts[$counter]>($kind==='model'?5:8)) { throw new RuntimeException('AI_COUNTER_EXHAUSTED'); }
            if ($kind==='model' && $counts[$counter]>($counts['stage_count']??0)+($counts['model_recovery_count']??0)) { throw new RuntimeException('AI_COUNTER_SEQUENCE'); }
            $counts['attempt_kinds']=$counts['attempt_kinds']??[];
            $counts['attempt_kinds'][$attemptCode]=$kind;
            $counts['attempt_targets']=$counts['attempt_targets']??[];
            $counts['attempt_targets'][$attemptCode]=$targetCode;
            // Each model attempt belongs to one customer-triggered execution
            // segment.  Keeping this bounded numeric relation prevents a
            // clarification continuation from being reported as though its
            // model time were part of the original request.
            $segmentIndex=(int)($counts['execution_segment_index']??-1);
            $segments=is_array($counts['execution_segments']??null)?$counts['execution_segments']:[];
            if ($segmentIndex>=0 && isset($segments[$segmentIndex]) && is_array($segments[$segmentIndex])) {
                $counts['attempt_segment_index']=is_array($counts['attempt_segment_index']??null)?$counts['attempt_segment_index']:[];
                $counts['attempt_segment_index'][$attemptCode]=$segmentIndex;
            }
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
            $counts=json_decode($r['counters_json'],true)?:[];
            $counts['attempt_started_at']=$counts['attempt_started_at']??[];
            $counts['attempt_started_at'][$attemptCode]=$this->now();
            $this->execute('UPDATE '.$this->table('attempt').' SET state=\'IN_FLIGHT\' WHERE instance_id=? AND run_id=? AND attempt_code=?',[$this->instance,$runId,$attemptCode]);
            $this->execute('UPDATE '.$this->table('run').' SET counters_json=?, version=version+1 WHERE instance_id=? AND run_id=?',[json_encode($counts),$this->instance,$runId]);
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
            $counts=json_decode($r['counters_json'],true)?:[];
            $started=(int)(($counts['attempt_started_at']??[])[$attemptCode]??0);
            if ($started>0) {
                $counts['attempt_elapsed_ms']=$counts['attempt_elapsed_ms']??[];
                $counts['attempt_elapsed_ms'][$attemptCode]=min(180000,max(0,$this->now()-$started));
                unset($counts['attempt_started_at'][$attemptCode]);
            }
            $this->execute('UPDATE '.$this->table('attempt').' SET state=?, input_tokens=?, output_tokens=? WHERE instance_id=? AND run_id=? AND attempt_code=?',[$state,$inputTokens,$outputTokens,$this->instance,$runId,$attemptCode]);
            $this->execute('UPDATE '.$this->table('run').' SET counters_json=?, version=version+1 WHERE instance_id=? AND run_id=?',[json_encode($counts),$this->instance,$runId]);
            // UNKNOWN means the provider outcome is not known.  The gateway
            // may still run one separately recorded, bounded recovery attempt
            // for a read-only stage.  If recovery is not allowed or fails, its
            // normal exception path terminalizes the Run with the real reason.
        });
    }

    /** Payload-free model response diagnostics expire with the Run. */
    public function recordDiagnostic(array $owner,string $runId,int $generation,string $workerToken,array $diagnostic,string $attemptCode=''): void
    {
        // Diagnostics deliberately hold only bounded transport facts. They
        // must never become a back door for customer wording, model output,
        // identities or returned business values.
        $allowed=['stage','predicate','finish_reason','content_bytes','recommended_value_type','transport_errno','http_status','elapsed_ms',
            'initial_observation','needs_metric_choice','selected_metric_count','operation','field','metric_requirement_count',
            'binding_row_count','selected_code_count','row_code_count','row_status','condition_mismatch'];
        if (array_diff(array_keys($diagnostic),$allowed) || !is_string($diagnostic['stage']??null)
            || !preg_match('/^[a-z_]{1,48}$/D',$diagnostic['stage']) || !is_string($diagnostic['predicate']??null)
            || !preg_match('/^[a-z0-9_:]{1,96}$/D',$diagnostic['predicate'])
            || (isset($diagnostic['finish_reason']) && (!is_string($diagnostic['finish_reason']) || !preg_match('/^[a-z_]{1,32}$/D',$diagnostic['finish_reason'])))
            || (isset($diagnostic['content_bytes']) && (!is_int($diagnostic['content_bytes']) || $diagnostic['content_bytes']<0 || $diagnostic['content_bytes']>131072))
            || (isset($diagnostic['transport_errno']) && (!is_int($diagnostic['transport_errno']) || $diagnostic['transport_errno']<0 || $diagnostic['transport_errno']>999))
            || (isset($diagnostic['http_status']) && (!is_int($diagnostic['http_status']) || $diagnostic['http_status']<0 || $diagnostic['http_status']>599))
            || (isset($diagnostic['elapsed_ms']) && (!is_int($diagnostic['elapsed_ms']) || $diagnostic['elapsed_ms']<0 || $diagnostic['elapsed_ms']>180000))
            || (isset($diagnostic['recommended_value_type']) && (!is_string($diagnostic['recommended_value_type']) || !preg_match('/^[a-z_]{1,32}$/D',$diagnostic['recommended_value_type'])))
            || (isset($diagnostic['initial_observation']) && !is_bool($diagnostic['initial_observation']))
            || (isset($diagnostic['needs_metric_choice']) && !is_bool($diagnostic['needs_metric_choice']))
            || (isset($diagnostic['selected_metric_count']) && (!is_int($diagnostic['selected_metric_count']) || $diagnostic['selected_metric_count']<0 || $diagnostic['selected_metric_count']>64))
            || (isset($diagnostic['operation']) && (!is_string($diagnostic['operation']) || !preg_match('/^[a-z_]{1,32}$/D',$diagnostic['operation'])))
            || (isset($diagnostic['field']) && (!is_string($diagnostic['field']) || !preg_match('/^[a-z_]{1,32}$/D',$diagnostic['field'])))
            || (isset($diagnostic['metric_requirement_count']) && (!is_int($diagnostic['metric_requirement_count']) || $diagnostic['metric_requirement_count']<0 || $diagnostic['metric_requirement_count']>12))
            || (isset($diagnostic['binding_row_count']) && (!is_int($diagnostic['binding_row_count']) || $diagnostic['binding_row_count']<0 || $diagnostic['binding_row_count']>12))
            || (isset($diagnostic['selected_code_count']) && (!is_int($diagnostic['selected_code_count']) || $diagnostic['selected_code_count']<0 || $diagnostic['selected_code_count']>64))
            || (isset($diagnostic['row_code_count']) && (!is_int($diagnostic['row_code_count']) || $diagnostic['row_code_count']<0 || $diagnostic['row_code_count']>64))
            || (isset($diagnostic['condition_mismatch']) && (!is_string($diagnostic['condition_mismatch'])
                || !in_array($diagnostic['condition_mismatch'],['semantic_shape','bound_shape','subject','relation','result_form','condition_count','condition_operator','condition_quantity','condition_unit','unknown'],true)))
            || (isset($diagnostic['row_status']) && (!is_string($diagnostic['row_status']) || !in_array($diagnostic['row_status'],['satisfied','unavailable','pending','invalid'],true)))) {
            throw new RuntimeException('AI_DIAGNOSTIC_INVALID');
        }
        if ($attemptCode!=='') { $this->identifier($attemptCode); }
        $this->transaction(function () use ($owner,$runId,$generation,$workerToken,$diagnostic,$attemptCode) {
            $r=$this->read($owner,$runId,$generation,true); $this->worker($r,$workerToken);
            $counters=json_decode($r['counters_json'],true)?:[];
            $counters['model_diagnostic']=$diagnostic;
            // Keep a bounded diagnostic per model attempt as well as the
            // latest summary.  A recovery must not erase why the original
            // request failed; no prompt, completion, identity or business
            // value is stored here.
            if ($attemptCode!=='') {
                $attempts=is_array($counters['model_diagnostics']??null)?$counters['model_diagnostics']:[];
                $attempts[$attemptCode]=$diagnostic;
                $counters['model_diagnostics']=$attempts;
            }
            $this->execute('UPDATE '.$this->table('run').' SET counters_json=?, version=version+1 WHERE instance_id=? AND run_id=?',[json_encode($counters),$this->instance,$runId]);
        });
    }

    public function runDiagnostic(array $owner,string $runId,int $generation): ?array
    {
        return $this->transaction(function () use ($owner,$runId,$generation) {
            $r=$this->read($owner,$runId,$generation,true); $counters=json_decode($r['counters_json'],true)?:[];
            return is_array($counters['model_diagnostic']??null)?$counters['model_diagnostic']:null;
        });
    }

    /** Reserve BEFORE any external send; retries cannot reset these counters. */
    public function reserve(array $owner,string $runId,int $generation,string $workerToken,string $counter,int $amount=1): int
    {
        $limits=['stage_count'=>5,'model_attempt_count'=>5,'model_recovery_count'=>1,'failover_count'=>1,'supplement_count'=>1,'workflow_transition_count'=>16,'skill_execution_count'=>8,'tool_call_count'=>8,'node_visit_count'=>12,'input_tokens'=>96000,'output_tokens'=>6000];
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
    public function pauseForClarification(array $owner,string $runId,int $generation,string $workerToken,string $clarificationRef='',bool $newStep=true): array
    {
        if ($clarificationRef!=='') { $this->identifier($clarificationRef); }
        return $this->transaction(function () use ($owner,$runId,$generation,$workerToken,$clarificationRef,$newStep) {
            $r=$this->read($owner,$runId,$generation); $this->worker($r,$workerToken); $this->live($r);
            $snapshot=json_decode($r['snapshot_json'],true); $max=(int)($snapshot['max_clarification_rounds']??1);
            $counts=json_decode($r['counters_json'],true)?:[];
            if ($newStep && (int)$r['clarification_count'] >= $max) { throw new RuntimeException('AI_CLARIFICATION_EXHAUSTED'); }
            if (!$newStep && ($clarificationRef!==$r['clarification_ref'] || (int)$r['clarification_count']<1)) { throw new RuntimeException('AI_CLARIFICATION_INVALID'); }
            if (!$newStep) { $counts['clarification_invalid_count']=($counts['clarification_invalid_count']??0)+1; }
            if (($counts['clarification_invalid_count']??0)>3) { throw new RuntimeException('AI_CLARIFICATION_INVALID_LIMIT'); }
            if (($counts['clarification_wait_ms']??0)>=600000) { throw new RuntimeException('AI_CLARIFICATION_EXPIRED'); }
            $now=$this->now();
            if ($this->hasUnresolvedAttempt($runId)) { throw new RuntimeException('AI_ATTEMPT_UNRESOLVED'); }
            // A waiting customer has no execution envelope.  Leaving the
            // previous DISPATCHING marker here makes supervision redeliver a
            // completed worker attempt as though it were a lost queue job.
            unset($counts['execution_operation'],$counts['execution_ref'],$counts['execution_hash'],$counts['execution_state'],$counts['execution_queued_at'],$counts['execution_dispatching_at']);
            $counts['clarification_invalid_count']=$counts['clarification_invalid_count']??0;
            $this->execute('UPDATE '.$this->table('run').' SET status=\'WAITING_CLARIFICATION\', clarification_ref=?, clarification_count=?, counters_json=?, pause_at=?, remaining_ms=?, slot_held=0, worker_token=\'\', version=version+1 WHERE instance_id=? AND run_id=?',[$clarificationRef,(int)$r['clarification_count']+($newStep?1:0),json_encode($counts),$now,(int)$r['deadline_at']-$now,$this->instance,$runId]);
            return $this->publicRun($this->read($owner,$runId,$generation));
        });
    }

    /** Re-open the same clarification after a worker rejects its submitted values. */
    public function rejectClarification(array $owner,string $runId,int $generation,string $workerToken,string $requestId): void
    {
        $this->identifier($requestId);
        $this->transaction(function () use($owner,$runId,$generation,$workerToken,$requestId) {
            $r=$this->read($owner,$runId,$generation); $this->worker($r,$workerToken); $this->live($r);
            $counts=json_decode($r['counters_json'],true)?:[]; $key=hash('sha256',$requestId);
            if (isset($counts['clarification_submissions'][$key]) && empty($counts['clarification_submissions'][$key]['accepted'])) {
                // Keep the original request hash as an immutable rejection
                // receipt. A delayed duplicate must replay this state rather
                // than re-admit an old choice after the customer has corrected
                // the form.
                $counts['clarification_submissions'][$key]['rejected']=true;
                $counts['clarification_rejected_ref']=$r['clarification_ref'];
                $this->execute('UPDATE '.$this->table('run').' SET counters_json=?, version=version+1 WHERE instance_id=? AND run_id=?',[json_encode($counts),$this->instance,$runId]);
            }
        });
    }

    /**
     * Resolve an already-known client submission before it is allowed back
     * onto the queue. The choice payload never leaves the encrypted request;
     * the Run only retains this idempotency verdict and request hash.
     */
    public function clarificationSubmissionState(array $owner,string $runId,int $generation,string $requestId,string $requestHash): string
    {
        $this->identifier($requestId);
        if (!preg_match('/^[a-f0-9]{64}$/D',$requestHash)) throw new RuntimeException('AI_CLARIFICATION_INVALID');
        return $this->transaction(function () use($owner,$runId,$generation,$requestId,$requestHash) {
            $r=$this->read($owner,$runId,$generation); $counts=json_decode($r['counters_json'],true)?:[]; $key=hash('sha256',$requestId);
            $submission=$counts['clarification_submissions'][$key]??null;
            if (!is_array($submission)) return 'new';
            if (!is_string($submission['hash']??null) || !hash_equals($submission['hash'],$requestHash)) throw new RuntimeException('AI_IDEMPOTENCY_CONFLICT');
            if (!empty($submission['rejected'])) return 'rejected';
            return !empty($submission['accepted']) ? 'accepted' : 'pending';
        });
    }

    /** Resume does not clear counters, extend retention, or claim that a stale process stopped. */
    public function resume(array $owner,string $runId,int $generation,string $workerToken,int $capacity=4,?array $submission=null): array
    {
        $this->identifier($workerToken);
        if ($capacity<1 || $capacity>64) { throw new RuntimeException('AI_CAPACITY_INVALID'); }
        return $this->transaction(function () use ($owner,$runId,$generation,$workerToken,$capacity,$submission) {
            $r=$this->read($owner,$runId,$generation); $now=$this->now();
            $counts=json_decode($r['counters_json'],true)?:[];
            if ($submission!==null) {
                $this->validateSubmission($submission);
                $key=hash('sha256',$submission['request_id']);
                if (isset($counts['clarification_submissions'][$key])) {
                    if (!hash_equals($counts['clarification_submissions'][$key]['hash'],$submission['request_hash'])) { throw new RuntimeException('AI_IDEMPOTENCY_CONFLICT'); }
                    return $this->publicRun($r)+['submission_replayed'=>true];
                }
                if ($r['clarification_ref']!==$submission['clarification_ref'] || (int)$r['clarification_count']!==$submission['intent_revision'] || $submission['step_revision']!==1) { throw new RuntimeException('AI_CLARIFICATION_STALE'); }
            }
            if ($r['status']!=='WAITING_CLARIFICATION' || $now<(int)$r['pause_at'] || $now-(int)$r['pause_at']+($counts['clarification_wait_ms']??0)>=600000) { throw new RuntimeException('AI_CLARIFICATION_EXPIRED'); }
            $used=$this->one('SELECT COUNT(*) AS n FROM '.$this->table('run').' WHERE instance_id=? AND slot_held=1',[$this->instance]);
            $quarantine=$this->one('SELECT quarantined_slots FROM '.$this->table('mutex').' WHERE instance_id=?',[$this->instance]);
            if ((int)$used['n']+(int)$quarantine['quarantined_slots'] >= $capacity) {
                $this->execute('UPDATE '.$this->table('run').' SET status=\'FAILED\', reason=\'CAPACITY_STOPPED\', last_clock_at=?, version=version+1 WHERE instance_id=? AND run_id=?',[$this->now(),$this->instance,$runId]);
                return $this->publicRun($this->read($owner,$runId,$generation));
            }
            $counts['clarification_wait_ms']=($counts['clarification_wait_ms']??0)+$now-(int)$r['pause_at'];
            if ($submission!==null) {
                if (count($counts['clarification_submissions']??[])>=20) { throw new RuntimeException('AI_CLARIFICATION_INVALID_LIMIT'); }
                $counts['clarification_submissions'][$key]=['hash'=>$submission['request_hash'],'accepted'=>false];
            }
            $this->execute('UPDATE '.$this->table('run').' SET status=\'WORKFLOW_EXECUTING\', worker_token=?, deadline_at=?, last_clock_at=?, counters_json=?, pause_at=0, slot_held=1, version=version+1 WHERE instance_id=? AND run_id=?',[$workerToken,$now+(int)$r['remaining_ms'],$now,json_encode($counts),$this->instance,$runId]);
            return $this->publicRun($this->read($owner,$runId,$generation));
        });
    }

    /** Records validated choices once. Only opaque references/hashes, never text or choice payloads. */
    public function acceptClarification(array $owner,string $id,int $generation,string $worker,string $requestId): void
    {
        $this->identifier($requestId);
        $this->transaction(function () use($owner,$id,$generation,$worker,$requestId) {
            $r=$this->read($owner,$id,$generation); $this->worker($r,$worker); $this->live($r);
            $counts=json_decode($r['counters_json'],true)?:[]; $key=hash('sha256',$requestId);
            if (!isset($counts['clarification_submissions'][$key])) { throw new RuntimeException('AI_CLARIFICATION_INVALID'); }
            if (!$counts['clarification_submissions'][$key]['accepted']) {
                $counts['clarification_submissions'][$key]['accepted']=true;
                $counts['clarification_accepted_count']=($counts['clarification_accepted_count']??0)+1;
                $this->execute('UPDATE '.$this->table('run').' SET counters_json=?, version=version+1 WHERE instance_id=? AND run_id=?',[json_encode($counts),$this->instance,$id]);
            }
        });
    }

    private function validateSubmission(array $s): void
    {
        if (array_diff(array_keys($s),['request_id','request_hash','clarification_ref','intent_revision','step_revision']) || count($s)!==5
            || !is_string($s['request_hash']??null) || !preg_match('/^[a-f0-9]{64}$/D',$s['request_hash'])
            || !is_int($s['intent_revision']??null) || !is_int($s['step_revision']??null)) { throw new RuntimeException('AI_CLARIFICATION_INVALID'); }
        foreach (['request_id','clarification_ref'] as $key) { if (!is_string($s[$key]??null)) { throw new RuntimeException('AI_CLARIFICATION_INVALID'); } $this->identifier($s[$key]); }
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
        if (!in_array($code,['UNDERSTANDING','QUERYING','VERIFYING','EXPORTING','RENDERING','PUBLISHING'],true)) { throw new RuntimeException('AI_PROGRESS_INVALID'); }
        return $this->transaction(function () use ($owner,$runId,$generation,$workerToken,$code) {
            $r=$this->read($owner,$runId,$generation); $this->worker($r,$workerToken); $this->live($r);
            $counts=json_decode($r['counters_json'],true)?:[];
            $counts['phase_started_at']=$counts['phase_started_at']??[];
            if (!isset($counts['phase_started_at'][$code])) $counts['phase_started_at'][$code]=$this->now();
            if ($code==='PUBLISHING') $this->segmentAt($counts,(int)($counts['execution_segment_index']??-1),'publishing_at',(int)$counts['phase_started_at'][$code]);
            $this->execute('UPDATE '.$this->table('run').' SET progress_code=?, counters_json=?, version=version+1 WHERE instance_id=? AND run_id=?',[$code,json_encode($counts),$this->instance,$runId]);
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

    /** A cancelled, unclaimed job has no future use for encrypted input. */
    public function discardUnclaimedExecutionInput(array $owner,string $runId,int $generation): ?string
    {
        return $this->transaction(function () use($owner,$runId,$generation) {
            $r=$this->read($owner,$runId,$generation,true);
            if (!$this->terminal($r) || $r['worker_token']!=='') return null;
            $counts=json_decode($r['counters_json'],true)?:[]; $ref=$counts['execution_ref']??null;
            if (!is_string($ref) || !preg_match('/^request-[a-f0-9]{48}$/D',$ref)) return null;
            $this->retireExecutionEnvelope($counts);
            $this->execute('UPDATE '.$this->table('run').' SET counters_json=?, version=version+1 WHERE instance_id=? AND run_id=?',[json_encode($counts),$this->instance,$runId]);
            return $ref;
        });
    }

    /** The executing worker calls this only AFTER all its local operations have actually stopped. */
    public function release(array $owner, string $runId, int $generation, string $workerToken): void
    {
        $this->transaction(function () use ($owner,$runId,$generation,$workerToken) {
            $r=$this->read($owner,$runId,$generation,true); $this->worker($r,$workerToken);
            if (!$this->terminal($r)) { throw new RuntimeException('AI_RELEASE_BEFORE_STOP'); }
            // The worker has stopped all local work.  Clearing its fencing
            // token makes the terminal Run eligible for supervisor-owned
            // encrypted-input cleanup; keeping it would retain the private
            // request until expiry and make a finished worker look active.
            $this->execute('UPDATE '.$this->table('run').' SET slot_held=0, worker_token=\'\', version=version+1 WHERE instance_id=? AND run_id=?',[$this->instance,$runId]);
        });
    }

    public function publish(array $owner, string $runId, int $generation, string $workerToken, string $evidenceRef, string $answerRef, string $delivery='complete'): array
    {
        $this->identifier($evidenceRef); $this->identifier($answerRef);
        if (!in_array($delivery,['complete','data_only_export_failed'],true)) { throw new RuntimeException('AI_DELIVERY_INVALID'); }
        return $this->transaction(function () use ($owner,$runId,$generation,$workerToken,$evidenceRef,$answerRef,$delivery) {
            $r=$this->read($owner,$runId,$generation); $this->worker($r,$workerToken); $this->live($r);
            if ($r['status']!=='WORKFLOW_EXECUTING' || !(int)$r['slot_held']) { throw new RuntimeException('AI_PUBLICATION_NOT_CLAIMED'); }
            if ($this->hasUnresolvedAttempt($runId)) { throw new RuntimeException('AI_ATTEMPT_UNRESOLVED'); }
            $counters=json_decode($r['counters_json'],true)?:[];
            $counters['export_delivery']=$delivery==='data_only_export_failed'?2:($r['answer_ref']!==''?1:0);
            $counters['execution_delivered_at']=$this->now();
            $this->segmentAt($counters,(int)($counters['execution_segment_index']??-1),'delivered_at',$counters['execution_delivered_at']);
            $this->execute('UPDATE '.$this->table('run').' SET counters_json=?, last_clock_at=? WHERE instance_id=? AND run_id=?',[json_encode($counters),$counters['execution_delivered_at'],$this->instance,$runId]);
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
            foreach ($this->rows('SELECT run_id,pause_at,counters_json FROM '.$this->table('run').' WHERE instance_id=? AND status=\'WAITING_CLARIFICATION\'',[$this->instance]) as $waiting) {
                $waited=(int)((json_decode($waiting['counters_json'],true)?:[])['clarification_wait_ms']??0);
                if ($now-(int)$waiting['pause_at']+$waited>=600000) { $this->execute('UPDATE '.$this->table('run').' SET status=\'FAILED\', reason=\'CLARIFICATION_EXPIRED\', last_clock_at=?, version=version+1 WHERE instance_id=? AND run_id=?',[$now,$this->instance,$waiting['run_id']]); }
            }
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

    /**
     * Admission may safely retire work that cannot still be doing business
     * work: expired logical Runs, expired clarification waits, and an
     * unclaimed/paused conversation abandoned by the same authenticated
     * account.  It deliberately never releases a worker-held slot and never
     * supersedes an executing/exporting worker: those remain fenced until the
     * worker itself stops or the normal deadline/quarantine path resolves it.
     */
    private function reconcileAdmissionRuns(array $owner,int $now): void
    {
        $this->execute('UPDATE '.$this->table('run').' SET status=\'FAILED\', reason=CASE WHEN status=\'WAITING_EXPORT\' THEN \'AI_EXPORT_DEADLINE\' ELSE \'DEADLINE_EXCEEDED\' END, last_clock_at=?, slot_held=CASE WHEN worker_token=\'\' THEN 0 ELSE slot_held END, version=version+1 WHERE instance_id=? AND status NOT IN (\'COMPLETED\',\'PARTIAL_SUCCEEDED\',\'FAILED\',\'CANCELLED\',\'WAITING_CLARIFICATION\') AND deadline_at<=?',[$now,$this->instance,$now]);
        foreach ($this->rows('SELECT run_id,pause_at,counters_json FROM '.$this->table('run').' WHERE instance_id=? AND account_id=? AND terminal=? AND status=\'WAITING_CLARIFICATION\'',[$this->instance,$owner['account_id'],$owner['terminal']]) as $waiting) {
            $waited=(int)((json_decode($waiting['counters_json'],true)?:[])['clarification_wait_ms']??0);
            if ($now-(int)$waiting['pause_at']+$waited>=600000) {
                $this->execute('UPDATE '.$this->table('run').' SET status=\'FAILED\', reason=\'CLARIFICATION_EXPIRED\', last_clock_at=?, version=version+1 WHERE instance_id=? AND run_id=?',[$now,$this->instance,$waiting['run_id']]);
            }
        }
        // No external work exists before claim or while waiting for a human
        // choice.  Starting a different conversation explicitly abandons
        // those states, rather than forcing the user to recover a panel that
        // may have been closed or reloaded.
        $this->execute('UPDATE '.$this->table('run').' SET status=\'CANCELLED\', reason=\'SUPERSEDED\', last_clock_at=?, slot_held=0, version=version+1 WHERE instance_id=? AND account_id=? AND terminal=? AND conversation_id<>? AND worker_token=\'\' AND status IN (\'RECEIVED\',\'WAITING_CLARIFICATION\')',[$now,$this->instance,$owner['account_id'],$owner['terminal'],$owner['conversation_id']]);
    }

    /** No subject identifiers or messages in aggregate diagnostics; underlying rows expire at 24 h. */
    public function diagnostics(): array
    {
        return $this->transaction(function () {
            $counts=['success'=>0,'partial'=>0,'technical'=>0,'export'=>0,'export_unknown'=>0,'security'=>0,'capacity'=>0,'neutral'=>0,'active'=>0];
            // These aggregates are intentionally keyed by server-owned
            // lifecycle/attempt codes only.  They contain no Run identifier,
            // question, customer, metric, result or raw provider response,
            // but make a single "technical failure" counter actionable.
            $technicalReasons=[];
            // Model diagnostics are recorded through recordDiagnostic(),
            // which admits only bounded server-side stage and predicate
            // carriers. Aggregate those carriers separately from terminal
            // reasons: a bounded recovery can change the terminal reason,
            // but the rejected response shape remains useful for fixing the
            // protocol without retaining customer or provider content.
            $diagnosticPredicates=[];
            $rows=$this->rows('SELECT status,reason,COUNT(*) AS n FROM '.$this->table('run').' WHERE instance_id=? AND expires_at>? GROUP BY status,reason',[$this->instance,$this->now()]);
            foreach ($rows as $r) {
                $class=self::outcomeClass($r);
                $counts[$class]+=(int)$r['n'];
                if ($class==='technical' && is_string($r['reason']) && preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D',$r['reason'])) {
                    $technicalReasons[$r['reason']]=($technicalReasons[$r['reason']]??0)+(int)$r['n'];
                }
            }
            $counts['technical_reasons']=self::topReasonCounts($technicalReasons);
            $n=$this->one('SELECT COUNT(*) AS n FROM '.$this->table('receipt').' WHERE instance_id=? AND expires_at>? AND reason=\'CAPACITY_REJECTED\'',[$this->instance,$this->now()]);
            $counts['capacity_rejected']=(int)$n['n'];
            $usage=$this->one('SELECT COALESCE(SUM(input_tokens),0) AS input_tokens,COALESCE(SUM(output_tokens),0) AS output_tokens,SUM(CASE WHEN input_tokens IS NOT NULL AND output_tokens IS NOT NULL THEN 1 ELSE 0 END) AS known_usage_attempts,SUM(CASE WHEN state=\'UNKNOWN\' THEN 1 ELSE 0 END) AS unknown_attempts,SUM(CASE WHEN state<>\'PREPARED\' AND (input_tokens IS NULL OR output_tokens IS NULL) THEN 1 ELSE 0 END) AS usage_unknown_attempts FROM '.$this->table('attempt').' WHERE instance_id=? AND expires_at>? AND kind=\'model\'',[$this->instance,$this->now()]);
            $counts['usage']=array_map('intval',$usage);
            $counts['duration']=['terminal_count'=>0,'total_ms'=>0,'max_ms'=>0,'mean_ms'=>null];
            $counts['attempt_timing']=['model'=>['count'=>0,'total_ms'=>0,'max_ms'=>0,'mean_ms'=>null],'tool'=>['count'=>0,'total_ms'=>0,'max_ms'=>0,'mean_ms'=>null]];
            // Fixed stage names are technical timings, not a business intent
            // grammar.  They let operations locate latency without retaining
            // query text, metrics, object names or result values.
            $samples=['acceptance'=>[],'queue'=>[],'model'=>[],'reader'=>[],'delivery'=>[],'answer'=>[],'browser_observed'=>[]];
            $modelStages=[];
            // A cohort is a completed customer operation, rather than one
            // provider attempt.  This is the only safe basis for comparing
            // compatibility and asynchronous user-visible wait budgets.
            $cohorts=['async'=>['answer'=>[],'execution'=>[],'model_total'=>[],'browser_observed'=>[]],
                'compatibility'=>['answer'=>[],'execution'=>[],'model_total'=>[],'browser_observed'=>[]]];
            // Keep the completion denominator aligned with the matching
            // latency cohort. A browser-observed async wait sample must not
            // be presented beside a success rate that also includes the
            // synchronous compatibility fallback.
            $outcomeCohorts=['async'=>self::emptyOutcomeCounts(),'compatibility'=>self::emptyOutcomeCounts()];
            // A latency comparison is meaningful only inside one immutable
            // execution profile. Keep just an opaque hash of safe version
            // references, never model text, questions, answers or business
            // payloads; management receives the number of profiles only.
            $comparisonProfiles=['async'=>[],'compatibility'=>[]];
            $counts['exports']=['succeeded'=>0,'failed'=>0,'consecutive_failed'=>0,'eligible'=>0,'failure_rate'=>null];
            $runs=$this->rows('SELECT status,reason,created_at,last_clock_at,snapshot_json,counters_json FROM '.$this->table('run').' WHERE instance_id=? AND expires_at>? AND status IN (\'COMPLETED\',\'PARTIAL_SUCCEEDED\',\'FAILED\',\'CANCELLED\') ORDER BY last_clock_at DESC,created_at DESC',[$this->instance,$this->now()]);
            $streakOpen=true;
            foreach ($runs as $r) {
                $ms=max(0,(int)$r['last_clock_at']-(int)$r['created_at']);
                ++$counts['duration']['terminal_count']; $counts['duration']['total_ms']+=$ms; $counts['duration']['max_ms']=max($counts['duration']['max_ms'],$ms);
                $delivery=(int)((json_decode($r['counters_json'],true)?:[])['export_delivery']??0);
                $detail=json_decode($r['counters_json'],true)?:[];
                $segments=is_array($detail['execution_segments']??null)?$detail['execution_segments']:[];
                // Older retained Runs have only scalar telemetry; current Runs
                // keep one segment per initial/clarification execution.
                if (!$segments) $segments=[['accepted_at'=>$detail['execution_accepted_at']??0,'queued_at'=>$detail['execution_queued_at']??0,'started_at'=>$detail['execution_started_at']??0,'publishing_at'=>($detail['phase_started_at']??[])['PUBLISHING']??0,'delivered_at'=>$detail['execution_delivered_at']??0]];
                $runMode=($detail['execution_mode']??'')==='async'?'async':'compatibility';
                $outcomeCohorts[$runMode][self::outcomeClass($r)]++;
                $snapshot=json_decode($r['snapshot_json'],true)?:[];
                $comparisonProfiles[$runMode][self::comparisonProfile($snapshot)]=true;
                $segmentModelTotals=[];
                foreach ((array)($detail['attempt_elapsed_ms']??[]) as $code=>$elapsed) {
                    if (($detail['attempt_kinds'][$code]??'')!=='model' || !is_int($elapsed)) continue;
                    $segmentIndex=($detail['attempt_segment_index'][$code]??null);
                    // Retained pre-segment records can only be comparable when
                    // their Run has a single execution. Multi-step history
                    // without a mapping is deliberately omitted rather than
                    // inventing a relationship between attempts and choices.
                    if (!is_int($segmentIndex) && count($segments)===1) $segmentIndex=0;
                    if (is_int($segmentIndex) && $segmentIndex>=0 && $segmentIndex<count($segments)) {
                        $segmentModelTotals[$segmentIndex]=($segmentModelTotals[$segmentIndex]??0)+max(0,$elapsed);
                    }
                }
                // `model_diagnostics` belongs to a named model attempt, while
                // `model_diagnostic` is also used for a non-model runtime
                // boundary such as a verified context fast path.  Surface the
                // latter as well, but do not count the same model failure
                // twice merely because it is also the latest diagnostic.
                $seenDiagnostics=[];
                foreach ((array)($detail['model_diagnostics']??[]) as $diagnostic) {
                    $stage=$diagnostic['stage']??null;
                    $predicate=$diagnostic['predicate']??null;
                    if (!is_string($stage) || !preg_match('/^[a-z_]{1,48}$/D',$stage)
                        || !is_string($predicate) || !preg_match('/^[a-z0-9_:]{1,96}$/D',$predicate)) continue;
                    $key=$stage.'/'.$predicate;
                    $diagnosticPredicates[$key]=($diagnosticPredicates[$key]??0)+1;
                    $seenDiagnostics[$key]=true;
                }
                $diagnostic=$detail['model_diagnostic']??null;
                $stage=is_array($diagnostic)?($diagnostic['stage']??null):null;
                $predicate=is_array($diagnostic)?($diagnostic['predicate']??null):null;
                if (is_string($stage) && preg_match('/^[a-z_]{1,48}$/D',$stage)
                    && is_string($predicate) && preg_match('/^[a-z0-9_:]{1,96}$/D',$predicate)) {
                    $key=$stage.'/'.$predicate;
                    if (!isset($seenDiagnostics[$key])) $diagnosticPredicates[$key]=($diagnosticPredicates[$key]??0)+1;
                }
                foreach ($segments as $segmentIndex=>$segment) {
                    if (!is_array($segment)) continue;
                    $mode=($segment['mode']??$runMode)==='async'?'async':'compatibility';
                    $accepted=(int)($segment['accepted_at']??0); $queued=(int)($segment['queued_at']??0); $started=(int)($segment['started_at']??0);
                    $publish=(int)($segment['publishing_at']??0); $delivered=(int)($segment['delivered_at']??0);
                    if ($accepted>0 && $accepted>=(int)$r['created_at']) $samples['acceptance'][]=min(180000,$accepted-(int)$r['created_at']);
                    if ($queued>0 && $started>=$queued) $samples['queue'][]=min(180000,$started-$queued);
                    if ($delivered>0 && $publish>0 && $delivered>=$publish) $samples['delivery'][]=min(180000,$delivered-$publish);
                    if ($delivered>0 && $accepted>0 && $delivered>=$accepted) {
                        $answer=min(180000,$delivered-$accepted);
                        $samples['answer'][]=$answer;
                        $cohorts[$mode]['answer'][]=$answer;
                    }
                    if ($delivered>0 && $started>0 && $delivered>=$started) $cohorts[$mode]['execution'][]=min(180000,$delivered-$started);
                    $browserElapsed=$segment['client_elapsed_ms']??null;
                    if (is_int($browserElapsed) && $browserElapsed>=0 && $browserElapsed<=300000) {
                        $samples['browser_observed'][]=$browserElapsed;
                        $cohorts[$mode]['browser_observed'][]=$browserElapsed;
                    }
                    if (($segmentModelTotals[$segmentIndex]??0)>0) $cohorts[$mode]['model_total'][]=min(180000,$segmentModelTotals[$segmentIndex]);
                }
                foreach ((array)($detail['attempt_elapsed_ms']??[]) as $code=>$elapsed) {
                    $kind=($detail['attempt_kinds'][$code]??'');
                    if (!isset($counts['attempt_timing'][$kind]) || !is_int($elapsed)) continue;
                    ++$counts['attempt_timing'][$kind]['count'];
                    $counts['attempt_timing'][$kind]['total_ms']+=$elapsed;
                    $counts['attempt_timing'][$kind]['max_ms']=max($counts['attempt_timing'][$kind]['max_ms'],$elapsed);
                    if ($kind==='model') $samples['model'][]=$elapsed;
                    if ($kind==='model' && is_string($code) && preg_match('/^[a-z][a-z0-9_-]{0,63}$/D',$code)) {
                        $modelStages[$code][]=$elapsed;
                    }
                    if (($detail['attempt_targets'][$code]??'')==='unified_metric_query') $samples['reader'][]=$elapsed;
                }
                if ($r['status']==='PARTIAL_SUCCEEDED') { ++$counts['exports']['failed']; if($streakOpen) ++$counts['exports']['consecutive_failed']; }
                elseif ($r['status']==='COMPLETED' && $delivery===1) { ++$counts['exports']['succeeded']; $streakOpen=false; }
                // Unknown, cancelled, rejected and unsafe outcomes are not file-rate samples.
            }
            $d=&$counts['duration']; if($d['terminal_count']) $d['mean_ms']=(int)round($d['total_ms']/$d['terminal_count']); unset($d);
            foreach ($counts['attempt_timing'] as &$timing) if ($timing['count']) $timing['mean_ms']=(int)round($timing['total_ms']/$timing['count']); unset($timing);
            $counts['segments']=[];
            foreach ($samples as $name=>$values) $counts['segments'][$name]=self::timingSummary($values);
            $counts['model_stages']=self::stageTimingSummaries($modelStages);
            $counts['diagnostic_predicates']=self::topReasonCounts($diagnosticPredicates);
            $counts['latency_cohorts']=[];
            foreach ($cohorts as $mode=>$measurements) {
                $counts['latency_cohorts'][$mode]=[];
                foreach ($measurements as $name=>$values) $counts['latency_cohorts'][$mode][$name]=self::timingSummary($values);
            }
            $counts['outcome_cohorts']=$outcomeCohorts;
            $counts['comparison_profile_counts']=array_map('count',$comparisonProfiles);
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
        if (in_array($r['reason'],['AI_ANALYSIS_COMBINATION_UNAVAILABLE','AI_OBJECT_BINDING_UNAVAILABLE','AI_OBJECT_SCOPE_TOO_LARGE',
            'AI_PERSONNEL_PERMISSION_REQUIRED','AI_EXTERNAL_SCOPE_REQUIRED','AI_LOCAL_CONDITION_REQUIRED','AI_FOLLOWUP_CONDITION_REQUIRED',
            'AI_PROJECT_OBJECT_NOT_READY','AI_PRODUCT_OBJECT_NOT_READY','AI_CATEGORY_OBJECT_NOT_READY','AI_PARTNER_OBJECT_NOT_READY',
            'AI_MEMBER_OBJECT_NOT_READY','AI_INVENTORY_OBJECT_NOT_READY','AI_OBJECT_CONTRACT_NOT_READY','AI_BINDING_SEMANTIC_REJECTED'],true)) return 'neutral';
        if (in_array($r['reason'],['CAPACITY_STOPPED','CAPACITY_REJECTED','AI_EXPORT_CAPACITY_REJECTED'],true)) return 'capacity';
        if (in_array($r['reason'],['AI_INTENT_UNRESOLVED','AI_CAPABILITY_NOT_READY','AI_CONTEXT_REQUIRED','AI_RANK_LIMIT_NOT_READY','AI_DIMENSION_RANK_LIMIT_NOT_READY','AI_FUTURE_ACTUALS_UNAVAILABLE','AI_DATA_COVERAGE_INCOMPLETE',
            'AI_DATE_INVALID','AI_DATE_REVERSED','AI_DATE_RANGE_TOO_LONG','METRIC_QUERY_RANGE_REVERSED','METRIC_QUERY_RANGE_TOO_LONG','METRIC_QUERY_FUTURE_UNAVAILABLE','AI_RESULT_REFERENCE_UNAVAILABLE','AI_CLARIFICATION_EXHAUSTED','AI_CLARIFICATION_INVALID_LIMIT','AI_CLARIFICATION_EXPIRED','AI_CLARIFICATION_STALE','AI_CLIENT_UPGRADE_REQUIRED','AI_METADATA_NOT_READY'],true)) return 'neutral';
        if (in_array($r['reason'],['AI_EXPORT_NOT_READY','AI_EXPORT_PRINCIPAL_UNAVAILABLE','AI_EXPORT_NOT_PUBLISHED','AI_EXPORT_EXPIRED','METRIC_NOT_REGISTERED','METRIC_PERMISSION_CHANGED','METRIC_PERMISSION_DENIED','METRIC_PERMISSION_GRAIN_UNAVAILABLE','METRIC_QUERY_COVERAGE_UNAVAILABLE','METRIC_QUERY_RANGE_INVALID','METRIC_QUERY_SCHEMA_INVALID','METRIC_QUERY_SHAPE_UNAVAILABLE','METRIC_SEMANTICS_CONFLICT','CLARIFICATION_EXPIRED','AI_PERMISSION_DENIED','AI_AUTHORIZATION_CHANGED','AI_CAPABILITY_CHANGED','AI_INPUT_SCHEMA_INVALID','AI_UNSUPPORTED_CONDITION','AI_METRIC_NOT_READY','AI_QUERY_SHAPE_NOT_READY','AI_OUTPUT_FORMAT_INVALID','AI_CLARIFICATION_INVALID','AI_EVIDENCE_INCOMPLETE','AI_CANCELLED','AI_NOT_CONFIGURED','AI_METRIC_EXPLANATION_NOT_READY','AI_EXTERNAL_AUTHORIZATION_REQUIRED'],true)) return 'neutral';
        if (in_array($r['reason'],['AI_EXPORT_DISPATCH_UNKNOWN','AI_EXPORT_CANCELLATION_UNKNOWN'],true)) return 'export_unknown';
        if (in_array($r['reason'],['AI_EXPORT_CONTENT_MISMATCH','AI_EXPORT_SIGNATURE_INVALID','AI_EXPORT_SOURCE_MISMATCH','AI_EXPORT_OWNER_MISMATCH','AI_EXPORT_BINDING_INVALID','AI_EXPORT_TASK_BINDING_INVALID','AI_EXPORT_HANDOFF_INVALID','AI_EXPORT_UNSAFE_FAILURE','METRIC_READ_BINDING_MISMATCH'],true)) return 'security';
        if (in_array($r['reason'],['AI_EXPORT_FAILED','AI_EXPORT_DEADLINE','DATA_ONLY_EXPORT_FAILED'],true)) return 'export';
        if ($r['reason']==='AI_WORKFLOW_DISABLED') return 'neutral';
        return 'technical';
    }

    private static function emptyOutcomeCounts(): array
    {
        return ['success'=>0,'partial'=>0,'technical'=>0,'export'=>0,'export_unknown'=>0,'security'=>0,'capacity'=>0,'neutral'=>0,'active'=>0];
    }

    /** Immutable technical refs only; diagnostics exposes the resulting count, never this hash. */
    private static function comparisonProfile(array $snapshot): string
    {
        $keys=['model_config_version','capability_snapshot_hash','budget_profile_version','guidance_profile_version','intent_contract_version','management_revision'];
        $profile=[];
        foreach ($keys as $key) $profile[$key]=is_string($snapshot[$key]??null)?$snapshot[$key]:'';
        return hash('sha256',json_encode($profile));
    }

    /** Integer-only aggregate. Raw per-Run timing is never exposed here. */
    private static function timingSummary(array $values): array
    {
        // Server stages are capped at the execution budget (180s). Browser
        // observation includes one final poll/render round, so its separately
        // bounded receipt may be up to 300s without being discarded as if it
        // were not a real customer-visible sample.
        $values=array_values(array_filter($values,static function($value): bool { return is_int($value) && $value>=0 && $value<=300000; }));
        if (!$values) return ['count'=>0,'mean_ms'=>null,'p50_ms'=>null,'p95_ms'=>null,'max_ms'=>null];
        sort($values,SORT_NUMERIC); $n=count($values); $sum=array_sum($values);
        $at=static function(float $quantile) use($values,$n): int { return $values[(int)ceil($quantile*$n)-1]; };
        return ['count'=>$n,'mean_ms'=>(int)round($sum/$n),'p50_ms'=>$at(0.50),'p95_ms'=>$at(0.95),'max_ms'=>$values[$n-1]];
    }

    /** A bounded ranked reason list avoids turning retained diagnostics into a payload channel. */
    private static function topReasonCounts(array $reasons): array
    {
        uksort($reasons,static function(string $left,string $right) use ($reasons): int {
            $byCount=($reasons[$right]??0)<=>($reasons[$left]??0);
            return $byCount!==0?$byCount:strcmp($left,$right);
        });
        return array_slice($reasons,0,8,true);
    }

    /** Model-stage names are server-owned attempt codes, and timing stays aggregate-only. */
    private static function stageTimingSummaries(array $stages): array
    {
        $out=[];
        foreach ($stages as $stage=>$values) $out[$stage]=self::timingSummary($values);
        uasort($out,static function(array $left,array $right): int {
            $byCount=($right['count']??0)<=>($left['count']??0);
            return $byCount!==0?$byCount:(($right['p95_ms']??0)<=>($left['p95_ms']??0));
        });
        return array_slice($out,0,8,true);
    }

    private function transaction(callable $fn)
    {
        if ($this->db->inTransaction()) { throw new RuntimeException('AI_STORE_NESTED_TRANSACTION'); }
        if ($this->sqlite) { $this->db->exec('BEGIN IMMEDIATE'); } else { $this->db->beginTransaction(); }
        try {
            // MySQL INSERT IGNORE takes a shared duplicate-key lock. Concurrent
            // requests then upgrading it with FOR UPDATE can deadlock. Acquire
            // the exclusive row lock directly, without changing its counters.
            $sql=$this->sqlite
                ? 'INSERT OR IGNORE INTO '.$this->table('mutex').' (instance_id) VALUES (?)'
                : 'INSERT INTO '.$this->table('mutex').' (instance_id) VALUES (?) ON DUPLICATE KEY UPDATE instance_id=VALUES(instance_id)';
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
        if ($now < (int)$r['last_clock_at']) {
            $this->clockRegressionMs=(int)$r['last_clock_at']-$now;
            throw new RuntimeException('AI_EXECUTION_CLOCK_REGRESSED');
        }
        if ($now >= (int)$r['deadline_at']) { throw new RuntimeException('AI_RUN_DEADLINE'); }
        $this->execute('UPDATE '.$this->table('run').' SET last_clock_at=? WHERE instance_id=? AND run_id=?',[$now,$this->instance,$r['run_id']]);
    }

    /** End a single budget-expired Run without ever releasing an active worker. */
    private function expireRun(array $r): void
    {
        if ($this->terminal($r) || $r['status']==='WAITING_CLARIFICATION') return;
        $now=$this->now();
        $this->execute('UPDATE '.$this->table('run').' SET status=\'FAILED\', reason=CASE WHEN status=\'WAITING_EXPORT\' THEN \'AI_EXPORT_DEADLINE\' ELSE \'DEADLINE_EXCEEDED\' END, last_clock_at=?, slot_held=CASE WHEN worker_token=\'\' THEN 0 ELSE slot_held END, version=version+1 WHERE instance_id=? AND run_id=? AND status NOT IN (\'COMPLETED\',\'PARTIAL_SUCCEEDED\',\'FAILED\',\'CANCELLED\',\'WAITING_CLARIFICATION\') AND deadline_at<=?',[$now,$this->instance,$r['run_id'],$now]);
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
    private function now(): int {
        $v=call_user_func($this->clock); if (!is_int($v)||$v<1) { throw new RuntimeException('AI_CLOCK_INVALID'); }
        if ($this->previousClockSample!==null) $this->minimumClockDelta=min($this->minimumClockDelta,$v-$this->previousClockSample);
        $this->previousClockSample=$v;
        return $v;
    }
    private function table(string $name): string { return $this->prefix.'mohe_ai_'.$name; }
    private function execute(string $sql,array $params) { $s=$this->db->prepare($sql); $s->execute($params); return $s; }
    private function one(string $sql,array $params) { return $this->execute($sql,$params)->fetch(PDO::FETCH_ASSOC); }
    private function rows(string $sql,array $params): array { return $this->execute($sql,$params)->fetchAll(PDO::FETCH_ASSOC); }
    private function attempt(string $runId,string $code) { return $this->one('SELECT * FROM '.$this->table('attempt').' WHERE instance_id=? AND run_id=? AND attempt_code=?',[$this->instance,$runId,$code]); }

    /** Mutates only bounded numeric lifecycle telemetry for the active envelope. */
    private function segmentAt(array &$counts,int $index,string $field,int $at): void
    {
        if ($index<0 || !is_array($counts['execution_segments']??null) || !isset($counts['execution_segments'][$index]) || !is_array($counts['execution_segments'][$index])) return;
        if (!isset($counts['execution_segments'][$index][$field])) $counts['execution_segments'][$index][$field]=$at;
    }

    /**
     * Detach only the private operational envelope once a Run is terminal.
     * Timing fields deliberately survive: they are bounded numeric evidence
     * for latency diagnostics and contain neither question text nor results.
     * In particular, do not remove accepted/queued timestamps, otherwise
     * older scalar telemetry loses its acceptance and queue samples during
     * ordinary encrypted-input cleanup.
     */
    private function retireExecutionEnvelope(array &$counts): void
    {
        unset(
            $counts['execution_operation'],
            $counts['execution_ref'],
            $counts['execution_hash'],
            $counts['execution_state'],
            $counts['execution_dispatching_at'],
            $counts['execution_segment_index'],
            $counts['execution_worker']
        );
    }

    /** Any unknown provider attempt blocks guidance, export and publication. */
    private function hasUnresolvedAttempt(string $runId): bool
    {
        $rows=$this->rows('SELECT attempt_code,state FROM '.$this->table('attempt').' WHERE instance_id=? AND run_id=?',[$this->instance,$runId]);
        $states=[];
        foreach ($rows as $row) {
            $states[$row['attempt_code']]=$row['state'];
            if (in_array($row['state'],['PREPARED','IN_FLIGHT'],true)) return true;
        }
        foreach ($states as $code=>$state) {
            if ($state!=='UNKNOWN') continue;
            return true;
        }
        return false;
    }
    private function insert(string $name,array $r): void { $this->execute('INSERT INTO '.$this->table($name).' ('.implode(',',array_keys($r)).') VALUES ('.implode(',',array_fill(0,count($r),'?')).')',array_values($r)); }
    private function publicRun(array $r): array
    {
        $out=[];
        foreach (['run_id','generation','status','reason','progress_code','clarification_ref','version','created_at','expires_at','deadline_at','slot_held','evidence_ref','answer_ref'] as $k) { $out[$k]=$r[$k]; }
        foreach (['generation','version','created_at','expires_at','deadline_at','slot_held'] as $k) { $out[$k]=(int)$out[$k]; }
        $snapshot=json_decode($r['snapshot_json'],true)?:[]; $counts=json_decode($r['counters_json'],true)?:[];
        $out['guidance_schema_version']=$snapshot['guidance_schema_version']??'mohe-clarification-v1';
        $out['max_clarification_rounds']=(int)($snapshot['max_clarification_rounds']??1);
        $out['clarification_count']=(int)$r['clarification_count'];
        $out['clarification_accepted_count']=(int)($counts['clarification_accepted_count']??0);
        $out['clarification_wait_ms']=(int)($counts['clarification_wait_ms']??0);
        $out['clarification_rejected']=($r['clarification_ref']??'')!=='' && ($counts['clarification_rejected_ref']??'')===$r['clarification_ref'];
        $out['execution_mode']=($counts['execution_mode']??'')==='async'?'async':'compatibility';
        return $out;
    }
}
