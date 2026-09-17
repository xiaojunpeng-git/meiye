<?php
declare(strict_types=1);

require_once __DIR__.'/../../后端代码/app/services/ai/execution/AiRunStore.php';
use app\services\ai\execution\AiRunStore;

$db=new PDO('sqlite::memory:');
foreach ([
    'CREATE TABLE mohe_ai_mutex(instance_id TEXT PRIMARY KEY,quarantined_slots INTEGER NOT NULL DEFAULT 0)',
    'CREATE TABLE mohe_ai_receipt(instance_id TEXT,receipt_key TEXT,request_hash TEXT,window_id TEXT,run_id TEXT,reason TEXT,expires_at INTEGER,PRIMARY KEY(instance_id,receipt_key))',
    'CREATE TABLE mohe_ai_attempt(instance_id TEXT,run_id TEXT,attempt_code TEXT,kind TEXT,target_code TEXT,payload_hash TEXT,state TEXT,input_tokens INTEGER,output_tokens INTEGER,created_at INTEGER,expires_at INTEGER,PRIMARY KEY(instance_id,run_id,attempt_code))',
    'CREATE TABLE mohe_ai_execution_worker(instance_id TEXT,worker_id TEXT,host_name TEXT,process_id INTEGER,heartbeat_at INTEGER,expires_at INTEGER,PRIMARY KEY(instance_id,worker_id))',
    'CREATE TABLE mohe_ai_run(instance_id TEXT,run_id TEXT,account_id INTEGER,terminal TEXT,conversation_id TEXT,window_id TEXT,generation INTEGER,status TEXT,reason TEXT,progress_code TEXT,clarification_ref TEXT,version INTEGER,created_at INTEGER,expires_at INTEGER,deadline_at INTEGER,last_clock_at INTEGER,remaining_ms INTEGER,pause_at INTEGER,clarification_count INTEGER,slot_held INTEGER,worker_token TEXT,evidence_ref TEXT,answer_ref TEXT,snapshot_json TEXT,counters_json TEXT,PRIMARY KEY(instance_id,run_id))'
] as $sql) $db->exec($sql);
$now=2000000; $runs=new AiRunStore($db,'','async-fixture',static function () use (&$now): int { return $now; });
$owner=['account_id'=>9,'terminal'=>'merchant','conversation_id'=>'conversation','window_id'=>'window'];
$snapshot=['capability_snapshot_ref'=>'cap','capability_snapshot_hash'=>str_repeat('a',64),'budget_profile_version'=>'budget','authorization_version'=>'auth','model_config_version'=>'model'];
$run=$runs->create($owner,'request',hash('sha256','question'),$snapshot)['run'];
$ref='request-'.str_repeat('a',48); $hash=hash('sha256','encrypted input fixture');
$queued=$runs->queueExecution($owner,$run['run_id'],$run['generation'],'execute',$ref,$hash);
if ($queued['status']!=='RECEIVED') throw new RuntimeException('queue changed logical state before worker claim');
if (!$runs->pendingExecutionIds()) throw new RuntimeException('durably queued Run is supervisor-visible');
$job=$runs->queuedExecution($run['run_id']);
if (!$job || $job['request_ref']!==$ref || $job['operation']!=='execute') throw new RuntimeException('worker receives only durable opaque execution envelope');
if ($runs->queuedExecution($run['run_id'])!==null) throw new RuntimeException('duplicate queue message cannot start a second worker');
if ($runs->executionNeedsDispatch($owner,$run['run_id'],$run['generation'])) throw new RuntimeException('live pre-claim worker is not re-enqueued by each status poll');
// Simulate a process dying after reserving the durable envelope but before it
// reaches the gateway claim.  No catch/finally runs in that failure mode.
$now+=15001;
if (!$runs->executionNeedsDispatch($owner,$run['run_id'],$run['generation'])) throw new RuntimeException('stale pre-claim dispatch becomes status-redeliverable');
if (!$runs->queuedExecution($run['run_id'])) throw new RuntimeException('stale pre-claim interruption can be redelivered safely');
$runs->failQueuedExecution($run['run_id'],'AI_AUTHORIZATION_CHANGED');
if ($runs->get($owner,$run['run_id'],$run['generation'])['status']!=='FAILED') throw new RuntimeException('pre-claim permission failure terminates without a stranded slot');

// A configured boolean is not treated as consumer proof.  The dedicated
// worker heartbeat is required, and a claimed run is released only after its
// own recorded local process has been proven gone.
$runs->heartbeatExecutionConsumer('fixture-worker',1234,'fixture-host',210);
if (!$runs->hasLiveExecutionConsumer(210)) throw new RuntimeException('live dedicated consumer heartbeat is required');
$runs->heartbeatExecutionSupervisor('supervisor-fixture',1235,'fixture-host',210);
if (!$runs->hasLiveExecutionSupervisor(210)) throw new RuntimeException('live independent supervisor heartbeat is required');
$lost=$runs->create($owner,'lost-request',hash('sha256','lost question'),$snapshot)['run'];
$lostRef='request-'.str_repeat('d',48);
$runs->queueExecution($owner,$lost['run_id'],$lost['generation'],'execute',$lostRef,hash('sha256','lost input'));
$runs->queuedExecution($lost['run_id']);
$runs->startQueuedExecutionWorker($lost['run_id'],'fixture-worker',1234,'fixture-host');
if (!$runs->claim($owner,$lost['run_id'],$lost['generation'],'lost-worker-token')) throw new RuntimeException('lost worker fixture claim');
$now+=210001;
if ($runs->recoverStoppedExecutionWorkers(210,static function(string $host,int $pid): bool { return $host==='fixture-host' && $pid===1234; })!==1) throw new RuntimeException('only a proven stopped worker is reclaimed');
$lostState=$runs->get($owner,$lost['run_id'],$lost['generation']);
if ($lostState['status']!=='FAILED' || (int)$lostState['slot_held']!==0 || $lostState['reason']!=='AI_EXECUTION_WORKER_LOST') throw new RuntimeException('proven stopped worker becomes fenced terminal and releases capacity');
if (!in_array($lostRef,$runs->takeTerminalUnclaimedExecutionInputRefs(),true)) throw new RuntimeException('a proven-dead worker must release its request for private-store cleanup');

// A queue delay must never extend the execution deadline.  The Worker refuses
// the expired envelope, the Run becomes terminal with no held slot, and the
// supervisor receives the request reference for prompt private-store cleanup.
$expired=$runs->create($owner,'expired-request',hash('sha256','expired question'),$snapshot)['run'];
$expiredRef='request-'.str_repeat('9',48);
$runs->queueExecution($owner,$expired['run_id'],$expired['generation'],'execute',$expiredRef,hash('sha256','expired input'));
$now+=180001;
if ($runs->queuedExecution($expired['run_id'])!==null) throw new RuntimeException('a deadline-expired queued envelope must never start');
$expiredState=$runs->get($owner,$expired['run_id'],$expired['generation']);
if ($expiredState['status']!=='FAILED' || $expiredState['reason']!=='DEADLINE_EXCEEDED' || (int)$expiredState['slot_held']!==0) throw new RuntimeException('queued deadline expiry must be terminal and release its unclaimed slot');
if (!in_array($expiredRef,$runs->takeTerminalUnclaimedExecutionInputRefs(),true)) throw new RuntimeException('expired unclaimed input must be handed to private-store cleanup');

// Aggregates retain only bounded timing numbers. The five operations metrics
// remain distinguishable without persisting business content or model text.
$timed=$runs->create($owner,'timed-request',hash('sha256','timed question'),$snapshot)['run'];
$runs->queueExecution($owner,$timed['run_id'],$timed['generation'],'execute','request-'.str_repeat('e',48),hash('sha256','timed input'));
$now+=25; $runs->queuedExecution($timed['run_id']); $runs->startQueuedExecutionWorker($timed['run_id'],'fixture-worker',1234,'fixture-host');
if (!$runs->claim($owner,$timed['run_id'],$timed['generation'],'timed-worker-token')) throw new RuntimeException('timed worker fixture claim');
$runs->reserve($owner,$timed['run_id'],$timed['generation'],'timed-worker-token','stage_count',1);
$runs->prepareAttempt($owner,$timed['run_id'],$timed['generation'],'timed-worker-token','timed-model','model',hash('sha256','timed-model'),'siliconflow');
$runs->sendAttempt($owner,$timed['run_id'],$timed['generation'],'timed-worker-token','timed-model'); $now+=40;
$runs->finishAttempt($owner,$timed['run_id'],$timed['generation'],'timed-worker-token','timed-model','SUCCEEDED',1,1);
$runs->prepareAttempt($owner,$timed['run_id'],$timed['generation'],'timed-worker-token','timed-reader','tool',hash('sha256','timed-reader'),'unified_metric_query');
$runs->sendAttempt($owner,$timed['run_id'],$timed['generation'],'timed-worker-token','timed-reader'); $now+=30;
$runs->finishAttempt($owner,$timed['run_id'],$timed['generation'],'timed-worker-token','timed-reader','SUCCEEDED');
$runs->progress($owner,$timed['run_id'],$timed['generation'],'timed-worker-token','PUBLISHING'); $now+=20;
$runs->publish($owner,$timed['run_id'],$timed['generation'],'timed-worker-token','timed-evidence','timed-answer');
$runs->release($owner,$timed['run_id'],$timed['generation'],'timed-worker-token');
$timedRef='request-'.str_repeat('e',48);
if (!in_array($timedRef,$runs->takeTerminalUnclaimedExecutionInputRefs(),true)) throw new RuntimeException('terminal successful input is handed to private-store cleanup');
$raw=$db->query("SELECT counters_json FROM mohe_ai_run WHERE run_id=".$db->quote($timed['run_id']))->fetch(PDO::FETCH_ASSOC);
$timedCounters=json_decode($raw['counters_json'],true)?:[];
if (isset($timedCounters['execution_ref'],$timedCounters['execution_hash'],$timedCounters['execution_worker'])) throw new RuntimeException('terminal cleanup retains private execution envelope');
if (($timedCounters['execution_accepted_at']??0)<1 || ($timedCounters['execution_queued_at']??0)<1 || !is_array($timedCounters['execution_segments']??null)) throw new RuntimeException('terminal cleanup must retain bounded acceptance and queue timing evidence');
$timing=$runs->diagnostics()['segments'];
foreach (['acceptance','queue','model','reader','delivery','answer'] as $name) if (($timing[$name]['count']??0)<1 || !is_int($timing[$name]['p50_ms']??null) || !is_int($timing[$name]['p95_ms']??null)) throw new RuntimeException('segmented timing is incomplete: '.$name);
$runs->recordClientDelivery($owner,$timed['run_id'],$timed['generation'],137);
// A duplicated browser receipt after a retry/reload must not replace the
// first visible-render time with a later value.
$runs->recordClientDelivery($owner,$timed['run_id'],$timed['generation'],999);
$browserTiming=$runs->diagnostics()['segments']['browser_observed']??[];
if (($browserTiming['count']??0)<1 || ($browserTiming['p50_ms']??null)!==137) throw new RuntimeException('browser-visible timing is one-time bounded telemetry');
$asyncCohort=$runs->diagnostics()['latency_cohorts']['async']??[];
foreach (['answer','execution','model_total','browser_observed'] as $name) if (($asyncCohort[$name]['count']??0)<1 || !is_int($asyncCohort[$name]['p50_ms']??null)) throw new RuntimeException('async customer-operation timing cohort is incomplete: '.$name);
$stageTiming=$runs->diagnostics()['model_stages']['timed-model']??[];
if (($stageTiming['count']??0)!==1 || ($stageTiming['p50_ms']??null)!==40 || ($stageTiming['p95_ms']??null)!==40) throw new RuntimeException('model stages expose only aggregate technical timings');
$diagnosticFailure=$runs->create($owner,'diagnostic-failure',hash('sha256','diagnostic failure'),$snapshot)['run'];
if (!$runs->claim($owner,$diagnosticFailure['run_id'],$diagnosticFailure['generation'],'diagnostic-failure-worker')) throw new RuntimeException('diagnostic failure fixture claim');
$runs->recordDiagnostic($owner,$diagnosticFailure['run_id'],$diagnosticFailure['generation'],'diagnostic-failure-worker',['stage'=>'intent_contract','predicate'=>'unexpected_requirement_binding'],'diagnostic-model');
$runs->fail($owner,$diagnosticFailure['run_id'],$diagnosticFailure['generation'],'diagnostic-failure-worker','AI_MODEL_RESULT_UNKNOWN');
$runs->release($owner,$diagnosticFailure['run_id'],$diagnosticFailure['generation'],'diagnostic-failure-worker');
$technicalReasons=$runs->diagnostics()['technical_reasons']??[];
if (($technicalReasons['AI_MODEL_RESULT_UNKNOWN']??0)!==1) throw new RuntimeException('technical reason aggregates preserve a safe actionable code count');
$diagnosticPredicates=$runs->diagnostics()['diagnostic_predicates']??[];
if (($diagnosticPredicates['intent_contract/unexpected_requirement_binding']??0)!==1) throw new RuntimeException('model diagnostics aggregate only bounded structural predicate counts');

// Synchronous compatibility mode is an execution mode, not a missing timing
// sample.  Its accepted, queued and started timestamps are deliberately the
// same, so comparing it to an async run never omits queue wait on only one
// side of the report.
$compat=$runs->create($owner,'compat-request',hash('sha256','compat question'),$snapshot)['run'];
$runs->beginCompatibilityExecution($owner,$compat['run_id'],$compat['generation'],'execute');
$now+=15;
if (!$runs->claim($owner,$compat['run_id'],$compat['generation'],'compat-worker-token')) throw new RuntimeException('compatibility timing fixture claim');
$runs->reserve($owner,$compat['run_id'],$compat['generation'],'compat-worker-token','stage_count',1);
$runs->prepareAttempt($owner,$compat['run_id'],$compat['generation'],'compat-worker-token','compat-model','model',hash('sha256','compat-model'),'siliconflow');
$runs->sendAttempt($owner,$compat['run_id'],$compat['generation'],'compat-worker-token','compat-model'); $now+=35;
$runs->finishAttempt($owner,$compat['run_id'],$compat['generation'],'compat-worker-token','compat-model','SUCCEEDED',1,1);
$runs->progress($owner,$compat['run_id'],$compat['generation'],'compat-worker-token','PUBLISHING'); $now+=10;
$runs->publish($owner,$compat['run_id'],$compat['generation'],'compat-worker-token','compat-evidence','compat-answer');
$runs->release($owner,$compat['run_id'],$compat['generation'],'compat-worker-token');
$runs->recordClientDelivery($owner,$compat['run_id'],$compat['generation'],61);
$compatCohort=$runs->diagnostics()['latency_cohorts']['compatibility']??[];
foreach (['answer','execution','model_total','browser_observed'] as $name) if (($compatCohort[$name]['count']??0)<1 || !is_int($compatCohort[$name]['p95_ms']??null)) throw new RuntimeException('compatibility customer-operation timing cohort is incomplete: '.$name);

// A worker that asks a customer a question has completed its queue work.  The
// waiting Run must not retain the old DISPATCHING marker, otherwise a queue
// supervisor can keep redispatching it while the customer is deciding.
$paused=$runs->create($owner,'paused-request',hash('sha256','paused question'),$snapshot)['run'];
$runs->queueExecution($owner,$paused['run_id'],$paused['generation'],'execute',$ref,$hash);
$runs->queuedExecution($paused['run_id']);
$runs->startQueuedExecutionWorker($paused['run_id'],'fixture-worker',1234,'fixture-host');
if (!$runs->claim($owner,$paused['run_id'],$paused['generation'],'clarification-worker')) throw new RuntimeException('clarification fixture worker claim');
$runs->reserve($owner,$paused['run_id'],$paused['generation'],'clarification-worker','stage_count',1);
$runs->prepareAttempt($owner,$paused['run_id'],$paused['generation'],'clarification-worker','clarification-initial-model','model',hash('sha256','clarification-initial-model'),'siliconflow');
$runs->sendAttempt($owner,$paused['run_id'],$paused['generation'],'clarification-worker','clarification-initial-model'); $now+=31;
$runs->finishAttempt($owner,$paused['run_id'],$paused['generation'],'clarification-worker','clarification-initial-model','SUCCEEDED',1,1);
$runs->pauseForClarification($owner,$paused['run_id'],$paused['generation'],'clarification-worker','clarification-step');
$now+=16000;
if ($runs->executionNeedsDispatch($owner,$paused['run_id'],$paused['generation'])) throw new RuntimeException('a customer-waiting Run is never re-enqueued as stale worker work');
if (in_array($paused['run_id'],$runs->pendingExecutionIds(),true)) throw new RuntimeException('a customer-waiting Run never consumes the queue supervisor feed');

// Validation happens after the durable acknowledgement.  A rejected choice is
// removed from the idempotency set and explicitly projected so the same form
// can be shown again instead of leaving the device in a hidden wait loop.
$submission=['request_id'=>'clarification-submission','request_hash'=>hash('sha256','choices'),'clarification_ref'=>'clarification-step','intent_revision'=>1,'step_revision'=>1];
$runs->resume($owner,$paused['run_id'],$paused['generation'],'clarification-reject-worker',4,$submission);
$runs->rejectClarification($owner,$paused['run_id'],$paused['generation'],'clarification-reject-worker',$submission['request_id']);
$reopened=$runs->pauseForClarification($owner,$paused['run_id'],$paused['generation'],'clarification-reject-worker','clarification-step',false);
if (empty($reopened['clarification_rejected'])) throw new RuntimeException('rejected choice is explicitly visible to a polling client');
if ($runs->clarificationSubmissionState($owner,$paused['run_id'],$paused['generation'],$submission['request_id'],$submission['request_hash'])!=='rejected') throw new RuntimeException('rejected choice remains a durable idempotency receipt');
$delayed=$runs->resume($owner,$paused['run_id'],$paused['generation'],'delayed-old-submission',4,$submission);
if (empty($delayed['submission_replayed']) || $delayed['status']!=='WAITING_CLARIFICATION') throw new RuntimeException('delayed rejected submission remains an inert idempotent replay');
$clarifyRef='request-'.str_repeat('b',48);
$runs->queueExecution($owner,$paused['run_id'],$paused['generation'],'clarify',$clarifyRef,hash('sha256','next choices'));
$raw=$db->query("SELECT counters_json FROM mohe_ai_run WHERE run_id=".$db->quote($paused['run_id']))->fetch(PDO::FETCH_ASSOC);
$segments=json_decode($raw['counters_json'],true)['execution_segments']??[];
if (count($segments)!==2 || ($segments[0]['operation']??'')!=='execute' || ($segments[0]['started_at']??0)<1 || ($segments[1]['operation']??'')!=='clarify') throw new RuntimeException('initial and clarification execution timings must remain separate');
if (($segments[0]['mode']??'')!=='async' || ($segments[1]['mode']??'')!=='async') throw new RuntimeException('each queued execution segment retains its own mode');
$pausedCounters=json_decode($raw['counters_json'],true)?:[];
if (($pausedCounters['attempt_segment_index']['clarification-initial-model']??null)!==0) throw new RuntimeException('initial model attempt must remain attached to the initial customer operation');
if (!empty($runs->get($owner,$paused['run_id'],$paused['generation'])['clarification_rejected'])) throw new RuntimeException('new choice acknowledgement clears stale rejected-form state');
try {
    $runs->queueExecution($owner,$paused['run_id'],$paused['generation'],'clarify','request-'.str_repeat('c',48),hash('sha256','different choices'));
    throw new RuntimeException('a later clarification must not overwrite an accepted queue envelope');
} catch (RuntimeException $error) {
    if ($error->getMessage()!=='AI_CLARIFICATION_IN_PROGRESS') throw $error;
}
// A completion after a clarification has two retained segments. The browser
// receipt must attach to the latest continuation, never retroactively to the
// initial request that ended in a human choice.
$runs->queuedExecution($paused['run_id']);
$runs->startQueuedExecutionWorker($paused['run_id'],'fixture-worker',1234,'fixture-host');
$finalSubmission=['request_id'=>'clarification-final','request_hash'=>hash('sha256','final choices'),'clarification_ref'=>'clarification-step','intent_revision'=>1,'step_revision'=>1];
$runs->resume($owner,$paused['run_id'],$paused['generation'],'clarification-final-worker',4,$finalSubmission);
$runs->acceptClarification($owner,$paused['run_id'],$paused['generation'],'clarification-final-worker',$finalSubmission['request_id']);
$runs->reserve($owner,$paused['run_id'],$paused['generation'],'clarification-final-worker','stage_count',1);
$runs->prepareAttempt($owner,$paused['run_id'],$paused['generation'],'clarification-final-worker','clarification-final-model','model',hash('sha256','clarification-final-model'),'siliconflow');
$runs->sendAttempt($owner,$paused['run_id'],$paused['generation'],'clarification-final-worker','clarification-final-model'); $now+=70;
$runs->finishAttempt($owner,$paused['run_id'],$paused['generation'],'clarification-final-worker','clarification-final-model','SUCCEEDED',1,1);
$runs->progress($owner,$paused['run_id'],$paused['generation'],'clarification-final-worker','PUBLISHING');
$runs->publish($owner,$paused['run_id'],$paused['generation'],'clarification-final-worker','clarification-evidence','clarification-answer');
$runs->release($owner,$paused['run_id'],$paused['generation'],'clarification-final-worker');
$runs->recordClientDelivery($owner,$paused['run_id'],$paused['generation'],222);
$raw=$db->query("SELECT counters_json FROM mohe_ai_run WHERE run_id=".$db->quote($paused['run_id']))->fetch(PDO::FETCH_ASSOC);
$pausedCounters=json_decode($raw['counters_json'],true)?:[];
$segments=$pausedCounters['execution_segments']??[];
if (($segments[0]['client_elapsed_ms']??null)!==null || ($segments[1]['client_elapsed_ms']??null)!==222) throw new RuntimeException('clarification browser receipt must belong to its latest execution segment');
if (($pausedCounters['attempt_segment_index']['clarification-final-model']??null)!==1) throw new RuntimeException('continuation model attempt must remain attached to the clarified customer operation');
$asyncAfterClarification=$runs->diagnostics()['latency_cohorts']['async']['model_total']??[];
if (($asyncAfterClarification['count']??0)!==3) throw new RuntimeException('model latency must report initial and clarified operations separately, never as one combined Run total');

$cancelled=$runs->create($owner,'cancelled-request',hash('sha256','cancelled question'),$snapshot)['run'];
$cancelRef='request-'.str_repeat('f',48);
$runs->queueExecution($owner,$cancelled['run_id'],$cancelled['generation'],'execute',$cancelRef,hash('sha256','cancelled input'));
$runs->cancel($owner,$cancelled['run_id'],$cancelled['generation']);
if ($runs->discardUnclaimedExecutionInput($owner,$cancelled['run_id'],$cancelled['generation'])!==$cancelRef) throw new RuntimeException('unclaimed cancelled input must be disposable immediately');
echo "PASS async execution acceptance/replay contract\n";
