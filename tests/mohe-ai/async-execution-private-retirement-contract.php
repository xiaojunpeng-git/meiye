<?php
declare(strict_types=1);

require_once __DIR__.'/../../后端代码/app/services/ai/execution/AiRunStore.php';
require_once __DIR__.'/../../后端代码/app/services/ai/execution/AiExecutionEnvelope.php';
require_once __DIR__.'/../../后端代码/app/services/ai/config/AiPrivateStorage.php';
require_once __DIR__.'/../../后端代码/app/services/ai/AiGatewayServices.php';

use app\services\ai\AiGatewayServices;
use app\services\ai\config\AiPrivateStorage;
use app\services\ai\execution\AiRunStore;

$db=new PDO('sqlite::memory:');
foreach ([
    'CREATE TABLE mohe_ai_mutex(instance_id TEXT PRIMARY KEY,quarantined_slots INTEGER NOT NULL DEFAULT 0)',
    'CREATE TABLE mohe_ai_receipt(instance_id TEXT,receipt_key TEXT,request_hash TEXT,window_id TEXT,run_id TEXT,reason TEXT,expires_at INTEGER,PRIMARY KEY(instance_id,receipt_key))',
    'CREATE TABLE mohe_ai_attempt(instance_id TEXT,run_id TEXT,attempt_code TEXT,kind TEXT,target_code TEXT,payload_hash TEXT,state TEXT,input_tokens INTEGER,output_tokens INTEGER,created_at INTEGER,expires_at INTEGER,PRIMARY KEY(instance_id,run_id,attempt_code))',
    'CREATE TABLE mohe_ai_execution_worker(instance_id TEXT,worker_id TEXT,host_name TEXT,process_id INTEGER,heartbeat_at INTEGER,expires_at INTEGER,PRIMARY KEY(instance_id,worker_id))',
    'CREATE TABLE mohe_ai_run(instance_id TEXT,run_id TEXT,account_id INTEGER,terminal TEXT,conversation_id TEXT,window_id TEXT,generation INTEGER,status TEXT,reason TEXT,progress_code TEXT,clarification_ref TEXT,version INTEGER,created_at INTEGER,expires_at INTEGER,deadline_at INTEGER,last_clock_at INTEGER,remaining_ms INTEGER,pause_at INTEGER,clarification_count INTEGER,slot_held INTEGER,worker_token TEXT,evidence_ref TEXT,answer_ref TEXT,snapshot_json TEXT,counters_json TEXT,PRIMARY KEY(instance_id,run_id))',
] as $sql) $db->exec($sql);

$clock=(int)floor(microtime(true)*1000);
$runs=new AiRunStore($db,'','retirement-fixture',static function () use (&$clock): int { return $clock; });
$directory=sys_get_temp_dir().'/mohe-ai-retirement-'.bin2hex(random_bytes(8));
$private=new AiPrivateStorage($directory);
$owner=['account_id'=>9,'terminal'=>'merchant','conversation_id'=>'conversation','window_id'=>'window'];
$snapshot=['capability_snapshot_ref'=>'cap','capability_snapshot_hash'=>str_repeat('a',64),'budget_profile_version'=>'budget','authorization_version'=>'auth','model_config_version'=>'model'];
$run=$runs->create($owner,'request',hash('sha256','question'),$snapshot)['run'];
$gateway=new AiGatewayServices($runs,null,$private,'retirement-fixture');
$method=(new ReflectionClass($gateway))->getMethod('queueExecution');
$input=['question'=>'今天业绩怎么样','history'=>[],'output_format'=>'screen','guidance_schema_version'=>'mohe-clarification-v2','context_ref'=>'context'];
$context=['terminal'=>'merchant','account_id'=>9,'principal_kind'=>'staff'];

try {
    $method->invoke($gateway,$context,$owner,$run,'execute',$input);
    if (count(glob($directory.'/request-*')?:[])!==1) throw new RuntimeException('initial durable request was not stored exactly once');
    // A page refresh can repeat the receipt-validated create request after a
    // worker has already claimed it. The gateway must pass the replay flag all
    // the way into the durable store, observe the original Run, and retire the
    // newly written encrypted envelope instead of treating recovery as an
    // invalid second execution or retaining that duplicate for 24 hours.
    $runs->queuedExecution($run['run_id']);
    $runs->startQueuedExecutionWorker($run['run_id'],'fixture-worker',1,'fixture-host');
    if (!$runs->claim($owner,$run['run_id'],$run['generation'],'claimed-worker-token')) throw new RuntimeException('fixture worker claim failed');
    $method->invoke($gateway,$context,$owner,$run,'execute',$input,true);
    if (count(glob($directory.'/request-*')?:[])!==1) throw new RuntimeException('claimed create replay leaked an orphaned encrypted request');

    // HTTP clarification only carries the stable Run identity.  Its encrypted
    // continuation must take expiry from the server record, rather than
    // assuming a browser/queue identity projection also carries expires_at.
    $runs->pauseForClarification($owner,$run['run_id'],$run['generation'],'claimed-worker-token','clarification-fixture');
    $clarificationInput=[
        'clarification_id'=>'clarification-fixture',
        'choices'=>['start_date'=>'2026-09-01','end_date'=>'2026-09-08'],
        'schema_version'=>'mohe-clarification-v2',
        'step_revision'=>1,
        'intent_revision'=>1,
        'client_submission_id'=>'clarification-submission',
    ];
    $method->invoke($gateway,$context,$owner,['run_id'=>$run['run_id'],'generation'=>$run['generation']],'clarify',$clarificationInput);
    if (count(glob($directory.'/request-*')?:[])!==2) throw new RuntimeException('clarification identity projection did not persist a continuation request');
    $queued=$runs->queuedExecution($run['run_id']);
    if (($queued['operation']??null)!=='clarify') throw new RuntimeException('clarification continuation was not durably queued');
} finally {
    foreach (glob($directory.'/request-*')?:[] as $file) @unlink($file);
    @unlink($directory.'/master.key'); @rmdir($directory);
}

echo "PASS async acknowledgement replay and clarification continuation retain only durable encrypted requests\n";
