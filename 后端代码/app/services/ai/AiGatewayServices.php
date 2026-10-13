<?php
namespace app\services\ai;

use app\services\ai\config\AiConfigStore;
use app\services\ai\config\AiPrivateStorage;
use app\services\ai\execution\AiRunStore;
use app\services\ai\feedback\AiFeedbackStore;
use app\services\ai\execution\AiRunBudgetPolicy;
use app\services\ai\execution\AiExecutionEnvelope;
use app\services\ai\execution\AiWorkflowPlanner;
use app\services\ai\execution\AiConditionSetCompiler;
use app\services\ai\execution\AiOverviewMetricResolver;
use app\services\ai\execution\AiRegisteredPlanCompiler;
use app\services\ai\execution\AiRegisteredWorkflowExecutor;
use app\services\ai\registry\AiBusinessRegistry;
use app\services\ai\contract\AiContractException;
use app\services\ai\contract\AiIntentResultContract;
use app\services\ai\contract\AiIntentUnderstandingContract;
use app\services\ai\model\AiModelInputProjector;
use app\services\ai\model\SiliconFlowClient;
use app\services\ai\presentation\AiAnswerRenderer;
use app\services\ai\context\IntentContextMerger;
use app\services\ai\context\ResultReferenceResolver;
use app\services\ai\context\VerifiedQueryContext;
use app\services\query\metric\MetricReadViewServices;
use app\services\query\metric\MetricReadViewStore;
use RuntimeException;

/** Conversation bodies are never logged or queued; async input is encrypted in instance-private storage. */
final class AiGatewayServices
{
    // The profile is the authoritative transport cap.  Individual model
    // stages may request a tighter limit, but never a longer one.
    // Multi-condition questions can require a longer typed model response.
    // Keep the call bounded, but leave enough room for that semantic stage so a
    // valid request is not reported as failed before any Reader is executed.
    private const MODEL_STAGE_LIMIT_MS = 30000;
    private $runs; private $config; private $private; private $instance; private $views; private $model; private $queryTransaction; private $exports; private $feedback;
    private $management; private $managementDocument; private $managementRevision='source';
    /** Optional injected dependencies are for an isolated integration environment, never request parameters. */
    public function __construct(?AiRunStore $runs = null, ?AiConfigStore $config = null, ?AiPrivateStorage $private = null,
        string $instance = '', ?MetricReadViewStore $views = null, ?callable $model = null, ?callable $queryTransaction = null, $management = null, ?AiFeedbackStore $feedback = null)
    {
        $this->runs=$runs; $this->config=$config; $this->private=$private; $this->instance=$instance; $this->views=$views; $this->model=$model; $this->queryTransaction=$queryTransaction;
        $this->management=$management; $this->feedback=$feedback;
    }

    private function initialize(): void
    {
        if ($this->runs) return;
        $runtime=\app\services\ai\execution\AiRuntimeFactory::make();
        foreach (['instance','private','runs','config','views','management','feedback'] as $key) $this->$key=$runtime[$key];
    }

    /** Trusted CLI supervision only. Counts contain no account, Run ID or conversation data. */
    public function supervise(): array
    {
        $this->initialize();
        $monitor=$this->monitor();
        try {
            // A durable queued request is only admitted while this independent
            // supervisor also proves it is alive.  Consumer heartbeats alone
            // cannot repair a failed first enqueue after the client disconnects.
            try { $this->runs->heartbeatExecutionSupervisor($this->supervisorId(),getmypid(),$this->supervisorHost()); } catch (\Throwable $ignored) {}
            $state=$this->runs->cleanup();
            $redelivered=0; $expiredWorkers=0;
            if ($this->asyncExecutionReady()) foreach ($this->runs->pendingExecutionIds() as $runId) {
                try { \app\services\ai\execution\AiRunExecutionQueue::push($this->instance,$runId); ++$redelivered; } catch (\Throwable $ignored) {}
            }
            // Remove stale heartbeats even when no live consumer remains.
            // A missing additive table is a staged-rollout condition, not a
            // reason for routine supervision to fail.
            try { $expiredWorkers=$this->runs->cleanupExpiredExecutionConsumers(); } catch (\Throwable $ignored) {}
            $recovered=\app\services\ai\execution\AiRunExecutionRuntime::recoverStoppedWorkers($this->runs);
            // Deadline expiry and pre-claim validation can terminally retire a
            // Run before a Worker reaches its normal finally block. Clear the
            // encrypted request as soon as its Run has no possible consumer.
            $retiredInputs=0;
            try {
                foreach ($this->runs->takeTerminalUnclaimedExecutionInputRefs() as $ref) {
                    try { $this->private->discard($ref); ++$retiredInputs; } catch (\Throwable $ignored) {}
                }
            } catch (\Throwable $ignored) {}
            $exports=$this->exportRuntime()->cleanup();
            $result=['runtime'=>$state,'execution_redelivered'=>$redelivered,'execution_workers_recovered'=>$recovered,'execution_workers_expired'=>$expiredWorkers,'execution_inputs_retired'=>$retiredInputs,'exports'=>$exports,'private_objects_removed'=>$this->private->cleanup(),'read_views_removed'=>$this->views->cleanup()];
            $monitor->recordCleanup(empty($exports['retry']));
            return $result;
        } catch (\Throwable $error) {
            try { $monitor->recordCleanup(false); } catch (\Throwable $ignored) {}
            throw $error;
        }
    }

    /** Server-owned, aggregate-only health for administrator UI and the resident supervisor. */
    public function monitorStatus(): array
    {
        $this->initialize();
        $stats=$this->runs->diagnostics();
        $stats['task_exports']=\app\services\ai\execution\AiExportRuntime::taskDiagnostics();
        return $this->monitor()->evaluate($stats);
    }

    /** Dedicated queue entry point.  It is intentionally not an HTTP action. */
    public function executeQueued(array $context,array $owner,string $runId,int $generation,string $operation,array $input): array
    {
        $this->initialize();
        if (!in_array($operation,['execute','clarify'],true)) throw new RuntimeException('AI_OPERATION_INVALID');
        return $this->execute($operation,$context,$owner,$runId,$generation,$input);
    }

    public function handle(string $operation,array $context,array $input,string $runId='')
    {
        $schemas=[
            'bootstrap'=>['client_session_id'],
            'create'=>['client_request_id','conversation_id','client_session_id','window_token','question','history','output_format','guidance_schema_version','context_ref','clarification_restart'],
            'execute'=>['client_request_id','conversation_id','client_session_id','window_token','question','history','output_format','generation','run_delivery_token','guidance_schema_version','context_ref','clarification_restart'],
            'status'=>['client_session_id','generation','run_delivery_token'], 'delivery'=>['client_session_id','generation','run_delivery_token','client_elapsed_ms'], 'cancel'=>['client_session_id','generation','run_delivery_token'],
            'export'=>['client_session_id','generation','run_delivery_token'],
            'export_create'=>['client_session_id','generation','run_delivery_token'],
            'export_status'=>['client_session_id','generation','run_delivery_token'],
            'clarify'=>['client_session_id','generation','run_delivery_token','clarification_id','choices','schema_version','step_revision','intent_revision','client_submission_id','revise_clarification_id'],
            'config_get'=>[], 'config_save'=>['version','enabled','model','api_key','external_processing_authorized','external_scope_version'], 'config_check'=>['confirm_cost'],
            'management_get'=>[], 'management_save'=>['expected_revision','document'], 'management_validate'=>['expected_revision'],
            'management_publish'=>['expected_revision'], 'management_rollback'=>['expected_revision','target_version'],
            'management_preview'=>['expected_revision','question'], 'management_rebase'=>['expected_revision'],
            'metric_registry_get'=>[],
            'feedback_save'=>['client_session_id','generation','run_delivery_token','rating','question','displayed_answer'],
            'feedback_list'=>['page','rating','keyword'], 'feedback_detail'=>['id'],
        ];
        if (!isset($schemas[$operation]) || array_diff(array_keys($input),$schemas[$operation])) throw new RuntimeException('AI_INPUT_SCHEMA_INVALID');
        $this->initialize();
        if (!in_array($context['terminal']??'', ['platform','store','merchant'],true) || (int)($context['account_id']??0)<1) throw new RuntimeException('AI_AUTH_REQUIRED');
        if (strpos($operation,'management_')===0) return $this->manage($operation,$context,$input);
        if ($operation==='metric_registry_get') return $this->metricRegistry($context);
        if ($operation==='feedback_list' || $operation==='feedback_detail') {
            // The existing platform AI maintainer boundary protects collected customer answers.
            if ($context['terminal']!=='platform' || empty($this->fresh($context)['can_configure'])) throw new RuntimeException('AI_PERMISSION_DENIED');
            return $operation==='feedback_list' ? $this->feedback->listing($input) : $this->feedback->detail((int)($input['id']??0));
        }
        if (strpos($operation,'config_')===0) {
            if ($context['terminal']!=='platform' || empty($this->fresh($context)['can_configure'])) throw new RuntimeException('AI_PERMISSION_DENIED');
            if ($operation==='config_get') {
                $diagnostics=$this->runs->diagnostics();
                $diagnostics['task_exports']=\app\services\ai\execution\AiExportRuntime::taskDiagnostics();
                $diagnostics['monitoring']=$this->monitor()->evaluate($diagnostics);
                return array_merge($this->config->read(),['runtime_status'=>$diagnostics]);
            }
            if ($operation==='config_save') return $this->config->save($input);
            if ($operation==='config_check') return $this->checkConfig($input);
        }
        $configuration=$this->config->read();
        if ($operation==='bootstrap') {
            $this->loadManagement();
            $session=$this->identifier($input['client_session_id']??null);
            return ['enabled'=>!empty($context['can_use']) && $configuration['enabled'], 'configured'=>$configuration['has_api_key'],
                'identity_key'=>$this->identity($context),'window_token'=>$this->token(['type'=>'window','identity'=>$this->identity($context),'window'=>$session,'expires'=>time()+86400]),
                'server_time'=>time()*1000,'retention_seconds'=>86400,'history_round_limit'=>20,'can_configure'=>!empty($context['can_configure']),
                'guidance_schema_version'=>'mohe-clarification-v2','max_clarification_rounds'=>$this->limits()['max_clarification_rounds'],
                'async_execution'=>$this->asyncExecutionReady(),
                'capabilities'=>$this->capabilities($context), 'disabled_reason'=>$configuration['enabled']?'':'AI_NOT_CONFIGURED'];
        }
        // Revocation must never prevent this authenticated owner from stopping their old task.
        if ($operation!=='cancel' && empty($context['can_use'])) throw new RuntimeException('AI_PERMISSION_DENIED');
        if ($operation==='create') {
            $this->loadManagement();
            if (($input['guidance_schema_version']??'')!=='mohe-clarification-v2') throw new RuntimeException('AI_CLIENT_UPGRADE_REQUIRED');
            if (!$configuration['enabled'] || !$configuration['external_processing_authorized']) throw new RuntimeException('AI_NOT_CONFIGURED');
            $session=$this->identifier($input['client_session_id']??null);
            $this->verifyToken($input['window_token']??'', $context,$session,'window');
            $owner=['account_id'=>(int)$context['account_id'],'terminal'=>$context['terminal'],'conversation_id'=>$this->identifier($input['conversation_id']??null),'window_id'=>$session];
            $body=$this->conversation($input,$context,$owner);
            $limits=$this->limits();
            // A queued Run is executed under a freshly reconstructed trusted
            // principal, not the mutable HTTP request context.  Freeze its
            // admission snapshot from that same reconstruction; otherwise a
            // harmless projection difference can reject every new Run before
            // it reaches the model as AI_CAPABILITY_CHANGED.
            $async=$this->asyncExecutionReady();
            $snapshotContext=$context;
            if ($async) {
                try { $snapshotContext=$this->trustedQueuedContext($context); }
                // A delegated/non-reconstructable live principal is still
                // valid for the existing synchronous compatibility path. Do
                // not acknowledge it as an async task that no Worker can run.
                catch (\Throwable $ignored) { $async=false; }
            }
            $snapshot=['capability_snapshot_ref'=>$this->registryHash($snapshotContext),'capability_snapshot_hash'=>hash('sha256',json_encode($this->capabilities($snapshotContext))),
                'budget_profile_version'=>$limits['run_budget_ms'].'-v1','authorization_version'=>$this->permissionHash($snapshotContext),'model_config_version'=>(string)$configuration['version'],
                'guidance_schema_version'=>'mohe-clarification-v2','guidance_profile_version'=>'guidance-v2-'.$limits['max_clarification_rounds'],'max_clarification_rounds'=>(string)$limits['max_clarification_rounds'],
                'intent_contract_version'=>AiIntentResultContract::VERSION];
            if ($this->management) $snapshot['management_revision']=$this->managementRevision;
            $questionHash=hash_hmac('sha256',$body['question'],$this->private->signingKey());
            $created=$this->runs->create($owner,$this->identifier($input['client_request_id']??null),$this->bodyHash($body),$snapshot,$limits['run_budget_ms'],$limits['execution_slots'],$limits['active_run_limit'],$questionHash);
            if (!$created['accepted']) return ['accepted'=>false,'reason'=>$created['reason'],'failure_guidance'=>$this->failureGuidance($created['reason'],($context['terminal']??null)==='platform'),'message'=>[
                'RATE_LIMITED'=>'您近期提问较频繁，请稍后再问。',
                'OTHER_CONVERSATION_ACTIVE'=>'您还有一个对话正在执行，请先完成或停止该任务。',
            ][$created['reason']]??'当前使用人数较多，请稍后再问。'];
            $executionMode='compatibility';
            if ($async) {
                // A lost create response may be retried after its Worker has
                // already claimed the original Run.  Receipt validation above
                // proves it is the same request; queueExecution then returns
                // the current projection instead of treating that recovery as
                // an invalid second execution.
                $this->queueExecution($context,$owner,$created['run'],'execute',$input,!empty($created['replayed']));
                // Redis acknowledgement is not treated as execution proof.
                // Polling and supervision redeliver a durably queued Run.
                try { \app\services\ai\execution\AiRunExecutionQueue::push($this->instance,$created['run']['run_id']); } catch (\Throwable $ignored) {}
                $executionMode='async';
            }
            return $this->present($context,$owner,$this->runs->get($owner,$created['run']['run_id'],$created['run']['generation']))+['execution_mode'=>$executionMode];
        }
        $session=$this->identifier($input['client_session_id']??null);
        $proof=$this->verifyToken($input['run_delivery_token']??'',$context,$session,'run');
        $generation=filter_var($input['generation']??null,FILTER_VALIDATE_INT);
        if ($generation===false || $generation<1 || ($proof['run']??'')!==$runId || ($proof['generation']??0)!==$generation) throw new RuntimeException('AI_DELIVERY_INVALID');
        $owner=['account_id'=>(int)$context['account_id'],'terminal'=>$context['terminal'],'conversation_id'=>$proof['conversation'],'window_id'=>$session];
        if ($operation==='feedback_save') {
            $source=$this->runs->feedbackSource($owner,$runId,$generation);
            if ($source['question_hash']==='' || !in_array($source['status'],['COMPLETED','PARTIAL_SUCCEEDED','FAILED'],true)) throw new RuntimeException('AI_FEEDBACK_UNAVAILABLE');
            $question=$input['question']??null;
            if (!is_string($question) || $question==='' || strlen($question)>16000
                || !hash_equals($source['question_hash'],hash_hmac('sha256',trim($question),$this->private->signingKey()))) throw new RuntimeException('AI_FEEDBACK_INVALID');
            if (in_array($source['status'],['COMPLETED','PARTIAL_SUCCEEDED'],true)) {
                // Reuse normal delivery's current evidence/permission check. The
                // saved sample is the server-published answer, never browser JSON.
                $this->present($context,$owner,$this->runs->get($owner,$runId,$generation));
                $stored=$this->private->read($source['answer_ref']);$this->assertBinding($stored,$owner,$runId,$generation);
                $answer=$stored['answer'];
                if ($source['status']==='PARTIAL_SUCCEEDED') $answer['summary'].=' 数据已核对，但 Excel 文件生成失败。';
            } else {
                // Failed Runs have no published answer object. Capture only the
                // displayed terminal text from the signed owner of that Run.
                $shown=$input['displayed_answer']??null;
                if (!is_string($shown) || trim($shown)==='' || strlen($shown)>16000) throw new RuntimeException('AI_FEEDBACK_INVALID');
                $projected=$this->present($context,$owner,$this->runs->get($owner,$runId,$generation));
                $answer=['summary'=>$shown,'terminal_status'=>'FAILED','failure_guidance'=>$projected['failure_guidance']??null];
            }
            return $this->feedback->save($owner,$source,(string)($input['rating']??''),trim($question),$answer);
        }
        if ($operation==='cancel') {
            $cancelled=$this->runs->cancel($owner,$runId,$generation);
            // A queued request with no worker claim can never run after this
            // terminal cancellation, so its encrypted envelope is no longer
            // needed. Claimed work keeps its input until the worker stops.
            try { if (($ref=$this->runs->discardUnclaimedExecutionInput($owner,$runId,$generation))!==null) $this->private->discard($ref); } catch (\Throwable $ignored) {}
            if ($cancelled['status']==='CANCELLED' && !empty($cancelled['answer_ref'])) {
                // Logical cancellation is already committed; cleanup also retries this notification.
                try { $this->exportRuntime()->cancel($context,$owner,$cancelled); } catch (\Throwable $ignored) {}
            }
            return $this->present($context,$owner,$cancelled);
        }
        if ($operation==='status') {
            $current=$this->runs->get($owner,$runId,$generation);
            // Do not enqueue every status poll while the customer is simply
            // choosing a clarification.  Only a durable queued envelope (or
            // one whose pre-claim worker reservation has gone stale) needs a
            // new queue message.
            if ($this->asyncExecutionReady() && $this->runs->executionNeedsDispatch($owner,$runId,$generation)) {
                try { \app\services\ai\execution\AiRunExecutionQueue::push($this->instance,$runId); } catch (\Throwable $ignored) {}
            }
            return $this->present($context,$owner,$current);
        }
        if ($operation==='delivery') {
            // This is observability only.  It is accepted only from the signed
            // owner of a completed Run and never changes its answer, status,
            // permissions, workflow, or any business calculation.
            $elapsed=filter_var($input['client_elapsed_ms']??null,FILTER_VALIDATE_INT);
            if ($elapsed===false || $elapsed<0 || $elapsed>300000) throw new RuntimeException('AI_INPUT_SCHEMA_INVALID');
            $this->runs->recordClientDelivery($owner,$runId,$generation,$elapsed);
            return ['accepted'=>true];
        }
        if ($operation==='export') {
            $descriptor=$this->exportRuntime()->download($context,$owner,$this->runs->get($owner,$runId,$generation));
            $path=(new \app\services\query\UnifiedQueryExportStorage())->absolutePath($descriptor['storageKey']);
            return download($path,$descriptor['fileName'])->header(['Cache-Control'=>'no-store','X-Content-Type-Options'=>'nosniff']);
        }
        if ($operation==='export_create' || $operation==='export_status') {
            // The answer Run is already final. File creation uses its verified
            // evidence and a separate task lifecycle, never a second question.
            $answered=$this->runs->get($owner,$runId,$generation);
            return $operation==='export_create'
                ? $this->exportRuntime()->queueCompleted($context,$owner,$answered)
                : $this->exportRuntime()->statusCompleted($context,$owner,$answered);
        }
        if (!in_array($operation,['execute','clarify'],true)) throw new RuntimeException('AI_OPERATION_INVALID');
        if ($this->asyncExecutionReady()) {
            if ($operation==='execute') {
                // Compatibility trigger for an older client.  Creation already
                // persisted the same body, so never replace it with a second
                // client copy or run work inside this HTTP request.
                $body=$this->conversation($input,$context,$owner); $this->runs->assertRequest($owner,$runId,$generation,$this->bodyHash($body));
                try { \app\services\ai\execution\AiRunExecutionQueue::push($this->instance,$runId); } catch (\Throwable $ignored) {}
                return $this->present($context,$owner,$this->runs->get($owner,$runId,$generation));
            }
            $queued=$this->queueExecution($context,$owner,['run_id'=>$runId,'generation'=>$generation], 'clarify',$input);
            try { \app\services\ai\execution\AiRunExecutionQueue::push($this->instance,$runId); } catch (\Throwable $ignored) {}
            return $this->present($context,$owner,$queued);
        }
        // A synchronous compatibility request must use the same operation
        // timing boundary as an asynchronous request.  This is telemetry only:
        // it neither claims work nor changes the workflow/permission path.
        $this->runs->beginCompatibilityExecution($owner,$runId,$generation,$operation);
        return $this->execute($operation,$context,$owner,$runId,$generation,$input);
    }

    /** Persist only encrypted request data plus a minimal trusted principal binding. */
    private function queueExecution(array $context,array $owner,array $run,string $operation,array $input,bool $allowCreateReplay=false): array
    {
        // Callers which resume an existing Run deliberately only need its
        // public identity (run_id + generation).  Retention is server-owned:
        // never depend on an optional caller projection for the expiry used
        // to protect the encrypted request envelope.  In particular, a
        // clarification continuation must be able to be accepted with the
        // same small identity shape as a Worker queue message.
        $runId=$this->identifier($run['run_id']??null);
        $generation=filter_var($run['generation']??null,FILTER_VALIDATE_INT);
        if ($generation===false || $generation<1) throw new RuntimeException('AI_EXECUTION_QUEUE_INVALID');
        $storedRun=$this->runs->get($owner,$runId,$generation);
        $projected=AiExecutionEnvelope::project($operation,$input);
        $hash=AiExecutionEnvelope::hash($operation,$projected,$this->private->signingKey());
        if ($operation==='clarify' && ($input['schema_version']??null)==='mohe-clarification-v2') {
            $submissionId=$this->identifier($input['client_submission_id']??null);
            $state=$this->runs->clarificationSubmissionState($owner,$runId,$generation,$submissionId,$hash);
            // A delayed retry of a previously accepted, rejected, or still
            // running submission must only observe the current Run. It may
            // never replace the durable queue envelope with old choices.
            if ($state!=='new') return $storedRun;
        }
        $expires=intdiv((int)$storedRun['expires_at'],1000);
        $payload=['run_id'=>$runId,'generation'=>$generation,'operation'=>$operation,'owner'=>$owner,
            'binding'=>$this->principalBinding($context),'input'=>$projected];
        $ref=$this->private->put('request',$payload,$expires);
        try {
            $queued=$this->runs->queueExecution($owner,$runId,$generation,$operation,$ref,$hash,$allowCreateReplay);
            // The durable store is the idempotency authority. A duplicate
            // browser retry may have written the same envelope just before
            // learning its earlier acknowledgement; it must be deleted now.
            if (!empty($queued['execution_replayed'])) $this->private->discard($ref);
            return $queued;
        } catch (\Throwable $error) {
            // The Run transaction either owns this reference or rolls back.
            try { $this->private->discard($ref); } catch (\Throwable $ignored) {}
            throw $error;
        }
    }

    private function principalBinding(array $context): array
    {
        $binding=['terminal'=>$context['terminal'],'account_id'=>(int)$context['account_id'],'principal_kind'=>$context['principal_kind']??''];
        if (!in_array($binding['terminal'],['platform','store','merchant'],true) || $binding['account_id']<1 || !is_string($binding['principal_kind'])) throw new RuntimeException('AI_EXECUTION_PRINCIPAL_INVALID');
        foreach (['origin_store_id','employee_id','staff_id'] as $key) if (array_key_exists($key,$context)) $binding[$key]=(int)$context[$key];
        if (array_key_exists('origin_organization_id',$context)) $binding['origin_organization_id']=(string)$context['origin_organization_id'];
        return $binding;
    }

    /**
     * Reconstruct the exact principal form a queued Worker will receive.  The
     * context is deliberately not persisted: it is only used to make create
     * and execute compare the same capability and authorization projection.
     */
    private function trustedQueuedContext(array $context): array
    {
        $trusted=(new \app\services\ai\execution\AiTrustedPrincipalResolver())->worker($this->principalBinding($context));
        if (($trusted['terminal']??null)!==($context['terminal']??null)
            || (int)($trusted['account_id']??0)!==(int)($context['account_id']??0)
            || empty($trusted['can_use'])) throw new RuntimeException('AI_EXECUTION_PRINCIPAL_INVALID');
        return $trusted;
    }

    private function asyncExecutionReady(): bool
    {
        // Isolated contract harnesses intentionally have no framework global;
        // absence means the staged compatibility mode, never implicit async.
        if (!function_exists('config')) return false;
        $settings=(array)config('mohe_ai.execution',[]);
        $stale=(int)($settings['consumer_stale_seconds']??0);
        if (($settings['enabled']??null)!==true || ($settings['compatible_workers_ready']??null)!==true
            || ($settings['monitoring_ready']??null)!==true || $stale<30
            || !\app\services\ai\execution\AiRunExecutionQueue::supported()) return false;
        // Older instances do not have the additive worker table yet. Treat
        // that as staged compatibility mode, never as a bootstrap failure.
        try { return $this->runs->hasLiveExecutionConsumer($stale) && $this->runs->hasLiveExecutionSupervisor($stale); }
        catch (\Throwable $ignored) { return false; }
    }

    private function execute(string $operation,array $context,array $owner,string $id,int $generation,array $input): array
    {
        $worker=bin2hex(random_bytes(24)); $claimed=false; $paused=false; $guidanceRequiresSemanticContext=false;
        try {
            $frozen=$this->runs->snapshot($owner,$id,$generation);
            $this->loadManagement($frozen['management_revision']??'source');
            if ($operation==='execute') {
                $body=$this->conversation($input,$context,$owner); $this->runs->assertRequest($owner,$id,$generation,$this->bodyHash($body));
                $current=$this->runs->get($owner,$id,$generation);
                if (in_array($current['status'],['COMPLETED','PARTIAL_SUCCEEDED','CANCELLED','FAILED','WAITING_CLARIFICATION','WAITING_EXPORT'],true)) return $this->present($context,$owner,$current);
                if (!$this->runs->claim($owner,$id,$generation,$worker)) return $this->present($context,$owner,$this->runs->get($owner,$id,$generation));
                $claimed=true;
            } else {
                $r=$this->runs->get($owner,$id,$generation);
                // Compatibility HTTP execution has the same late-submit
                // boundary as the async queue: show the confirmed terminal
                // reason instead of trying to resume an expired task.
                if (in_array($r['status'],['COMPLETED','PARTIAL_SUCCEEDED','CANCELLED','FAILED'],true)) {
                    return $this->present($context,$owner,$r);
                }
                if ($r['guidance_schema_version']==='mohe-clarification-v2') {
                    if (($input['schema_version']??'')!=='mohe-clarification-v2') throw new RuntimeException('AI_CLIENT_UPGRADE_REQUIRED');
                    $submission=['request_id'=>$this->identifier($input['client_submission_id']??null),'request_hash'=>$this->executionHash('clarify',$input),
                        'clarification_ref'=>$this->identifier($input['clarification_id']??null),'intent_revision'=>$input['intent_revision']??null,'step_revision'=>$input['step_revision']??null];
                } else {
                    if (array_intersect(array_keys($input),['schema_version','step_revision','intent_revision','client_submission_id','revise_clarification_id'])) throw new RuntimeException('AI_CLARIFICATION_INVALID');
                    if (($input['clarification_id']??'')!==$r['clarification_ref']) throw new RuntimeException('AI_CLARIFICATION_INVALID');
                    $submission=null;
                }
                $r=$this->runs->resume($owner,$id,$generation,$worker,$this->limits()['execution_slots'],$submission);
                if (!empty($r['submission_replayed'])) return $this->present($context,$owner,$r);
                $claimed=$r['status']==='WORKFLOW_EXECUTING';
                if (!$claimed) return $this->present($context,$owner,$r);
            }
            $snapshot=$this->runs->snapshot($owner,$id,$generation);
            $currentCapabilities=$this->capabilities($context);
            if (!isset($snapshot['guidance_schema_version'])) unset($currentCapabilities['definition_metric_codes'],$currentCapabilities['metadata_readiness']);
            if (!hash_equals($snapshot['capability_snapshot_hash'],hash('sha256',json_encode($currentCapabilities)))) throw new RuntimeException('AI_CAPABILITY_CHANGED');
            if (isset($snapshot['guidance_schema_version']) && !hash_equals($snapshot['capability_snapshot_ref'],$this->registryHash($context))) throw new RuntimeException('AI_CAPABILITY_CHANGED');
            $configuration=$this->config->read(true);
            if (!$configuration['enabled'] || !$configuration['external_processing_authorized'] || (string)$configuration['version']!==$snapshot['model_config_version']
                || $this->permissionHash($this->fresh($context))!==$snapshot['authorization_version']) throw new RuntimeException('AI_AUTHORIZATION_CHANGED');
            if ($operation==='execute') {
                $this->runs->progress($owner,$id,$generation,$worker,'UNDERSTANDING');
                // Only narrowly provable registered admissions run before
                // natural-language understanding. A model failure never
                // falls back to the old approximate parser.
                if (!AiConfigStore::allowsSanitizedQuestion($configuration)) throw new RuntimeException('AI_MODEL_CONFIG_UPGRADE_REQUIRED');
                if (isset($body['clarification_restart'])) {
                    // An explicit restart gets ordinary fresh admission and
                    // budgets. The old expired Run is never revived or edited.
                    $restart=$body['clarification_restart'];
                    $stored=$this->expiredClarificationSource($context,$owner,$restart);
                    [$compiled,$steps,$guidanceRequiresSemanticContext]=$this->compileGuidanceChoice(
                        $context,$owner,$id,$generation,$worker,$stored,['choices'=>$restart['choices']],$configuration,$body,false);
                } else {
                    $projection=['dates'=>[],'date_terms'=>[],'signals'=>[],'semantic_intent'=>['constraints'=>[]],'blocking_reason'=>null,'unresolved_condition'=>false];
                    $compiled=$this->understandAnalysis($context,$owner,$id,$generation,$worker,$body,$projection,$configuration);
                    $compiled=$this->decorateGuidance($compiled);
                }
                if ($compiled['kind']==='clarification') {
                    // Preserve the compiler's topic-cleared private carrier;
                    // the original HTTP body must not revive discarded context.
                    $compiled['_request_body']=$compiled['_request_body']??$body;
                    $r=$this->issueGuidance($owner,$id,$generation,$worker,$compiled,$stored['origin']??$compiled,$steps??[]); $paused=true;
                    return $this->present($context,$owner,$r);
                }
            } else {
                $stored=$this->private->read($r['clarification_ref']); $this->assertBinding($stored,$owner,$id,$generation);
                // Semantic intent is private state owned by the gateway, not
                // by one particular clarification planner.  A chain can move
                // from a context choice into a dimension choice (or the other
                // way around), so checking planner-specific state here would
                // let the accepted meaning disappear before the final query.
                try {
                    [$compiled,$steps,$guidanceRequiresSemanticContext]=$this->compileGuidanceChoice(
                        $context,$owner,$id,$generation,$worker,$stored+['envelope_ref'=>$r['clarification_ref']],$input,$configuration,$body,true);
                }
                catch (\Throwable $error) {
                    if (!in_array($error->getMessage(),['AI_CLARIFICATION_INVALID','AI_DATE_INVALID'],true)) throw $error;
                    // The durable acknowledgement was accepted before the
                    // worker checked the complete choice set.  Re-open this
                    // exact step, so a client never remains waiting behind a
                    // locally hidden, invalid submission.
                    if ($submission!==null) $this->runs->rejectClarification($owner,$id,$generation,$worker,$submission['request_id']);
                    $r=$this->runs->pauseForClarification($owner,$id,$generation,$worker,$r['clarification_ref'],false); $paused=true;
                    $response=$this->present($context,$owner,$r); $response['message']='所选条件格式不完整，请检查后重新确认。'; return $response;
                }
                if ($submission!==null) $this->runs->acceptClarification($owner,$id,$generation,$worker,$submission['request_id']);
                if ($compiled['kind']==='clarification') {
                    $r=$this->issueGuidance($owner,$id,$generation,$worker,$compiled,$stored['origin']??$stored['envelope'],$steps); $paused=true;
                    return $this->present($context,$owner,$r);
                }
            }
            if ($guidanceRequiresSemanticContext) {
                $checkpoint=function()use($context,$owner,$id,$generation,$worker,$configuration,$snapshot): void {
                    $this->runs->checkpoint($owner,$id,$generation,$worker);
                    $current=$this->config->read();
                    if ($current['version']!==$configuration['version'] || !AiConfigStore::allowsSanitizedQuestion($current)
                        || $this->permissionHash($this->fresh($context))!==$snapshot['authorization_version']) {
                        throw new RuntimeException('AI_AUTHORIZATION_CHANGED');
                    }
                };
                $this->assertGuidanceSemanticContext($compiled,$owner,$id,$generation,$worker,$configuration,$checkpoint);
            }
            if (($compiled['kind']??null)==='capability_unavailable') throw new RuntimeException($compiled['reason']??'AI_OBJECT_CONTRACT_NOT_READY');
            $contextMeaning=$compiled['_context_meaning']??[];
            unset($compiled['_context_meaning']);
            if (!is_array($contextMeaning)) throw new RuntimeException('AI_CONTEXT_DELTA_CONFLICT');
            try {
                if (($compiled['kind']??null)==='verified_result_presentation') {
                    return $this->present($context,$owner,$this->executeVerifiedResultPresentation($context,$owner,$id,$generation,$worker,$snapshot,$compiled,$contextMeaning));
                }
                $result=($compiled['kind']??null)==='member_detail'
                    ?$this->executeMemberDetail($context,$owner,$id,$generation,$worker,$snapshot,$compiled,$contextMeaning)
                    :(isset($compiled['_member_detail_request'])
                        ?$this->executeMemberPopulationDetails($context,$owner,$id,$generation,$worker,$snapshot,$compiled,$contextMeaning)
                        :$this->executeRegistered($context,$owner,$id,$generation,$worker,$snapshot,$compiled['plan'],$contextMeaning));
            } catch (\RuntimeException $error) {
                // Only the execution span limit needs a confirmed smaller range.
                // Historical ingestion dates must not crop the user's interval.
                $today=(new \DateTimeImmutable('@'.intdiv($this->runs->get($owner,$id,$generation)['created_at'],1000)))
                    ->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
                $guidance=$error->getMessage()==='AI_DATE_RANGE_TOO_LONG'
                    ? (new \app\services\ai\execution\AiDateRangeGuidancePlanner())->start($compiled['plan'],$today) : null;
                if ($guidance===null) throw $error;
                $guidance=$this->decorateGuidance($guidance);
                $r=$this->issueGuidance($owner,$id,$generation,$worker,$guidance,$compiled,[]); $paused=true;
                return $this->present($context,$owner,$r);
            }
            $paused=$result['status']==='WAITING_EXPORT';
            return $this->present($context,$owner,$result);
        } catch (\Throwable $e) {
            if (!$claimed) throw $e;
            // Terminal failures must stay payload-free, but a generic customer
            // retry message is not enough to distinguish a contract rejection
            // from a PHP/runtime defect during support. Record only the stable
            // exception category; never persist the exception message because
            // a lower layer may include a name, query value, or provider text.
            $failureKind=$e instanceof \TypeError?'type_error':($e instanceof \Error?'php_error':($e instanceof AiContractException?'contract_error':'runtime_error'));
            // A terminal category alone cannot distinguish a supported, named
            // business boundary from an accidental RuntimeException. Persist a
            // code only when it already follows the application's non-payload
            // machine-code contract; exception prose can contain provider or
            // customer data and must never enter the run diagnostic.
            $terminalPredicate='terminal_'.$failureKind;
            if ($failureKind==='runtime_error' && preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D',$e->getMessage())) {
                $terminalPredicate.='_'.strtolower($e->getMessage());
            }
            // A non-coded RuntimeException still needs a payload-free source
            // coordinate; otherwise every context-merger defect collapses to
            // the same unhelpful diagnostic. File basename and line contain
            // no customer text, model output or business value.
            if ($failureKind==='runtime_error' && $terminalPredicate==='terminal_runtime_error') {
                $file=basename($e->getFile());
                $safeFiles=['AiGatewayServices.php'=>'gateway','AiIntentResultContract.php'=>'intent_contract',
                    'IntentContextMerger.php'=>'context_merger','AiWorkflowPlanner.php'=>'workflow_planner'];
                if (isset($safeFiles[$file])) $terminalPredicate.='_'.$safeFiles[$file].'_line_'.$e->getLine();
            }
            // TypeError text can expose argument values. The application file
            // and line are safe structural coordinates, so keep a bounded
            // location marker solely for a reproducible server-side defect.
            if ($failureKind==='type_error') {
                $file=basename($e->getFile());
                $safeFiles=['AiGatewayServices.php'=>'gateway','AiIntentResultContract.php'=>'intent_contract','AiIntentGroupContract.php'=>'intent_group'];
                if (isset($safeFiles[$file])) {
                    $terminalPredicate.='_'.$safeFiles[$file].'_line_'.$e->getLine();
                } elseif (preg_match('/^[A-Za-z][A-Za-z0-9]{0,48}\.php$/D',$file)) {
                    // Framework/source filenames are structural, unlike the
                    // TypeError message. Keep a bounded origin tag when the
                    // first-party boundary is not one of the known hot paths.
                    $terminalPredicate.='_origin_'.strtolower(substr($file,0,-4));
                }
            }
            // Preserve the contract's payload-free predicate even when it is
            // raised after the model transport completed (for example during
            // semantic reconciliation). A generic terminal category would
            // hide the actionable boundary and make live failures untraceable.
            if ($e instanceof AiContractException) {
                $this->recordModelDiagnostic($owner,$id,$generation,$worker,$e,'terminal_contract');
            } else {
                $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,$terminalPredicate);
            }
            $reason=preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D',$e->getMessage())?$e->getMessage():'AI_EXECUTION_FAILED';
            return $this->present($context,$owner,$this->runs->fail($owner,$id,$generation,$worker,$reason));
        } finally {
            $this->runs->clearSemanticContinuation($id,$worker);
            if ($claimed && !$paused) $this->runs->release($owner,$id,$generation,$worker);
        }
    }

    private function registryHash(array $context): string
    {
        return $this->registry()->snapshot($this->capabilities($context))['snapshot_hash'];
    }

    /**
     * Clarification choices are only allowed to fill the specific missing
     * fields shown by the server.  Keep the accepted understanding private
     * across that interaction so a completed choice can never erase a clear,
     * unsupported customer condition and execute the narrowed preview.
     */
    private function assertGuidanceSemanticContext(array &$compiled,array $owner,string $id,int $generation,string $worker,array $configuration,callable $checkpoint): void
    {
        if (!array_key_exists('_semantic_context',$compiled)) throw new RuntimeException('AI_CLARIFICATION_STALE');
        $semantic=$compiled['_semantic_context']; unset($compiled['_semantic_context']);
        if (!is_array($semantic) || !is_array($semantic['understanding']??null)) throw new RuntimeException('AI_CLARIFICATION_STALE');
        if (AiIntentResultContract::hasUnboundRequirement($semantic['understanding'])) {
            throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
        }
        if (!($semantic['requires_final_metric_review']??false)) return;
        $review=$semantic['binding_review']??null;
        if (!is_array($review) || !is_array($review['safe_question']??null) || !is_array($review['summaries']??null)) {
            throw new RuntimeException('AI_CLARIFICATION_STALE');
        }
        $candidate=$this->finalSemanticBindingCandidate($compiled['plan']??null,$semantic['understanding']);
        // A controlled choice is allowed to supply a registered code, but it
        // is not allowed to reinterpret the requirement which caused the
        // choice. Re-run the independent admission only when the original
        // binding deliberately deferred its metric. A date/direction choice
        // after an already reviewed metric must not add another model round.
        if (AiIntentResultContract::requiresSemanticBindingReview($semantic['understanding'],$candidate)) {
            $this->reviewSemanticBinding(
                $owner,$id,$generation,$worker,$review['safe_question'],$review['summaries'],
                $semantic['understanding'],$candidate,$configuration,$checkpoint,true
            );
        }
    }

    /**
     * Produces only the bounded candidate required by the semantic admission
     * pass. It does not infer customer wording or create a query: all values
     * come from the already server-compiled final plan.
     */
    private function finalSemanticBindingCandidate($plannerPlan,array $understanding): array
    {
        // A definition read has no business-data query by design.  It is still
        // a completed registered binding and therefore needs the same semantic
        // admission when a controlled metric choice resolved the ambiguity.
        if (is_array($plannerPlan) && ($plannerPlan['query_shape']??null)==='definition') {
            $metrics=$plannerPlan['definition_metric_codes']??null;
            if (!is_array($metrics)) throw new RuntimeException('AI_CLARIFICATION_STALE');
            foreach ($metrics as $metric) if (!is_string($metric) || !preg_match('/^[a-z][a-z0-9_]{0,79}$/D',$metric)) {
                throw new RuntimeException('AI_CLARIFICATION_STALE');
            }
            return [
                'object_kind'=>'store','object_term'=>'','operation'=>'definition','metric_codes'=>array_values($metrics),
                'action_codes'=>[],'needs_metric_choice'=>false,'requirement_bindings'=>$this->finalMetricRequirementBindings($metrics,$understanding),
                'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'authorized','unresolved_fragments'=>[],
            ];
        }
        $query=is_array($plannerPlan)?($plannerPlan['query']??null):null;
        if (!is_array($query) || !is_array($query['metric_codes']??null)) throw new RuntimeException('AI_CLARIFICATION_STALE');
        $metrics=array_values($query['metric_codes']);
        foreach ($metrics as $metric) if (!is_string($metric) || !preg_match('/^[a-z][a-z0-9_]{0,79}$/D',$metric)) {
            throw new RuntimeException('AI_CLARIFICATION_STALE');
        }
        return [
            'object_kind'=>(string)($query['business_filters']['object_kind']??'store'),
            'object_term'=>'',
            'operation'=>(string)($query['query_shape']??'unknown'),
            'metric_codes'=>$metrics,
            'action_codes'=>[],
            'needs_metric_choice'=>false,
            'requirement_bindings'=>$this->finalMetricRequirementBindings($metrics,$understanding),
            'ranking'=>is_array($query['ranking']??null)?$query['ranking']:['direction'=>'unspecified','limit'=>null],
            'periods'=>[],
            'scope'=>'authorized',
            'unresolved_fragments'=>[],
        ];
    }

    /** Bind every metric-bearing accepted requirement to one server-compiled result. */
    private function finalMetricRequirementBindings(array $metrics,array $understanding): array
    {
        $bindings=[];
        foreach (AiIntentUnderstandingContract::requirements($understanding) as $id=>$requirement) {
            if (!in_array('metric_codes',(array)($requirement['fields']??[]),true)) continue;
            $bindings[]=['requirement_id'=>$id,'status'=>$metrics===[]?'unavailable':'satisfied','metric_codes'=>$metrics===[]?[]:$metrics];
        }
        return $bindings;
    }

    private function loadManagement(?string $version=null): void
    {
        $this->managementDocument=null; $this->managementRevision='source';
        if (!$this->management) return;
        try {$record=$version===null?$this->management->active():$this->management->version($version);}
        catch (\RuntimeException $error) {
            // A source registry upgrade must not make the customer-facing entry
            // unusable just because an administrator has an older editable
            // document.  New Runs use the current source defaults until the
            // administrator rebases and publishes that document.  A frozen old
            // Run is intentionally not rebound to new executable declarations.
            if ($version===null && $error->getMessage()==='AI_MANAGEMENT_SOURCE_CHANGED') return;
            throw $error;
        }
        $this->managementRevision=$record['version'];
        // Old source runs retain exactly the pre-management registry and guidance.
        if ($record['version']!=='source') $this->managementDocument=$record['document'];
    }
    private function registry(): AiBusinessRegistry
    {
        return $this->managementDocument
            ? \app\services\ai\management\AiManagementPolicy::registry($this->managementDocument)
            : new AiBusinessRegistry();
    }
    private function decorateGuidance(array $plan): array
    {
        if (($plan['fields'][0]['key']??null)==='semantic_reply') return $plan;
        // Dependency order in capability guidance is server-owned; old scene prompt
        // configuration must not reorder object binding after metric execution.
        if (in_array(($plan['schema_version']??null),[
            'mohe-analysis-guidance-v1','mohe-skill-guidance-v1','mohe-store-scope-guidance-v1',
            'mohe-context-replacement-guidance-v1','mohe-context-pending-guidance-v1','mohe-date-range-guidance-v1',
        ],true)) return $plan;
        return $this->managementDocument
            ? \app\services\ai\management\AiManagementPolicy::decorateEnvelope($this->managementDocument,$plan) : $plan;
    }
    private function assertManagedPlan(array $plan): void
    {
        if (!$this->managementDocument) return;
        $shape=$plan['query']['query_shape']??($plan['query_shape']??'');
        $code=$shape==='definition'?'wf_metric_definition':'wf_performance_'.$shape;
        $policy=$this->managementDocument['workflows'][$code]??null;
        if (!$policy || !$policy['enabled']) throw new RuntimeException('AI_WORKFLOW_DISABLED');
        if (($plan['output_format']??'screen')==='screen_and_xlsx' && !$policy['allow_export']) throw new RuntimeException('AI_EXPORT_NOT_READY');
    }

    /** Admin settings only. Preview never calls a model or reads business facts. */
    private function manage(string $operation,array $context,array $input): array
    {
        if ($context['terminal']!=='platform' || empty($this->fresh($context)['can_configure'])) throw new RuntimeException('AI_PERMISSION_DENIED');
        if (!$this->management) throw new RuntimeException('AI_MANAGEMENT_NOT_READY');
        $expected=$input['expected_revision']??null;
        if ($operation!=='management_get' && (!is_int($expected)||$expected<1)) throw new RuntimeException('AI_MANAGEMENT_REVISION_CONFLICT');
        if ($operation==='management_save') {
            if (!is_array($input['document']??null)) throw new RuntimeException('AI_MANAGEMENT_DOCUMENT_INVALID');
            $this->management->saveDraft($expected,$input['document']);
        } elseif ($operation==='management_rebase') $this->management->rebaseDraft($expected);
        elseif ($operation==='management_validate') return $this->management->validateDraft($expected);
        elseif ($operation==='management_publish') $this->management->publish($expected);
        elseif ($operation==='management_rollback') {
            if (!is_string($input['target_version']??null)) throw new RuntimeException('AI_MANAGEMENT_VERSION_NOT_FOUND');
            $this->management->rollback($expected,$input['target_version']);
        } elseif ($operation==='management_preview') {
            $this->management->validateDraft($expected);
            $state=$this->management->read();
            if ($state['revision']!==$expected) throw new RuntimeException('AI_MANAGEMENT_REVISION_CONFLICT');
            $this->managementDocument=$state['draft'];
            $body=(new AiModelInputProjector())->validateConversation($input['question']??null,[]);
            $projection=(new AiModelInputProjector())->project($body['question']);
            try {
                $signals=$projection['signals']; $shape='summary';
                foreach (['trend','ranking','comparison'] as $candidate) if (in_array($candidate,$signals,true)) $shape=$candidate;
                if (array_intersect(['top_5','bottom_5','rank_top','rank_bottom'],$signals)) $shape='ranking';
                $caps=$this->capabilities($context); $caps['current_store_bound']=false;
                $selection=['decision'=>'supported','query_shape'=>$shape,'metric_codes'=>array_values(array_intersect(array_keys(\app\services\query\metric\MetricSemanticCatalog::entries()),$signals))];
                $preview=$this->decorateGuidance((new AiWorkflowPlanner())->compile($projection,$selection,$caps,'screen',(new \DateTimeImmutable('now',new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d')));
                if ($preview['kind']==='plan') {
                    $this->assertManagedPlan($preview['plan']);
                    $compiled=(new AiRegisteredPlanCompiler($this->registry()))->compile($preview['plan'],$caps);
                    $preview['workflow_code']=$compiled['workflow_code']; $preview['nodes']=$compiled['nodes'];
                    $preview['compiled_run_hash']=$compiled['compiled_run_hash'];
                }
                return $preview+['revision'=>$expected,'mode'=>'draft_preview','model_called'=>false,'business_data_read'=>false];
            } catch (\Throwable $error) {
                $reason=preg_match('/^AI_[A-Z0-9_]+$/D',$error->getMessage())?$error->getMessage():'AI_PREVIEW_FAILED';
                return ['kind'=>'unsupported','reason'=>$reason,'message'=>'当前草稿不能合法完成此问题，请检查能力或明确条件。','mode'=>'draft_preview','model_called'=>false,'business_data_read'=>false,'revision'=>$expected];
            }
        }
        $state=$this->management->read();$sourceChanged=false;$activeDocument=null;
        try {$activeDocument=$this->management->version($state['active_version'])['document'];}
        catch (\RuntimeException $error) {
            if($error->getMessage()!=='AI_MANAGEMENT_SOURCE_CHANGED') throw $error;
            $sourceChanged=true;
        }
        return $state+['active_document'=>$activeDocument,'source_changed'=>$sourceChanged,'catalog'=>\app\services\ai\management\AiManagementPolicy::catalog()];
    }

    /** Source-owned metric contracts, exposed only to the platform maintainer. */
    private function metricRegistry(array $context): array
    {
        if ($context['terminal']!=='platform' || empty($this->fresh($context)['can_configure'])) {
            throw new RuntimeException('AI_PERMISSION_DENIED');
        }
        return \app\services\query\metric\MetricRegistryCatalogServices::catalog();
    }

    /**
     * Recover one typed local store reference cited by a current accepted
     * location requirement. This selects no ID or authority; the existing
     * catalogue resolver still rechecks both before a query can execute.
     */
    private function currentNamedStoreReference(array $understanding,array $safe,array $privateKinds): ?string
    {
        $references=[];$currentStores=[];$hasCurrentStoreRequirement=false;
        foreach (AiIntentUnderstandingContract::requirements($understanding) as $requirement) {
            if (!in_array('store_term',(array)($requirement['fields']??[]),true)) continue;
            foreach ((array)($requirement['evidence']??[]) as $evidence) {
                if (($evidence['message_id']??null)!=='current') continue;
                $hasCurrentStoreRequirement=true;
                foreach ((array)($safe['local_conditions']??[]) as $reference=>$unusedValue) {
                    $token='['.$reference.']';
                    if (($privateKinds[$reference]??null)==='store'
                        && strpos((string)($safe['outbound']['question']??''),$token)!==false) {
                        $currentStores[$reference]=true;
                        if (strpos((string)($evidence['quote']??''),$token)!==false) $references[$reference]=true;
                    }
                }
            }
        }
        if (count($references)===1) return '['.array_key_first($references).']';
        // A model may cite only "this store" while the same current question
        // contains one opaque named store. The accepted store_term already
        // proves this turn requests a store restriction; use that sole local
        // reference rather than leaving it unbound. Multiple current stores
        // never reach this fallback, and the authorized catalogue still
        // resolves the identity before any query.
        return $hasCurrentStoreRequirement && count($currentStores)===1
            ? '['.array_key_first($currentStores).']' : null;
    }

    /** Resolve a named location only through the current report-authorized catalogue. */
    private function bindNamedStoreScope(array $compiled,array $context,string $term,array $conditions,array $owner,string $id,int $generation,string $worker): array
    {
        // Replacing a signed store constraint without an authoritative target
        // must never widen the query to the complete authorized range.  An
        // empty term means the model understood a replacement but could not
        // bind its target; the same server-built choice flow is required.
        if ($conditions['store_scope']!=='replace') return $compiled;
        $this->runs->prepareAttempt($owner,$id,$generation,$worker,'analysis_store_scope','tool',hash('sha256',$term),'authorized_store_catalog');
        $this->runs->sendAttempt($owner,$id,$generation,$worker,'analysis_store_scope');
        try {
            $resolution=(new \app\services\query\metric\AnalysisObjectCatalog($this->authorizedStoreObjects($context),static function(): bool { return true; }))
                ->resolve($term,'store');
            $this->runs->finishAttempt($owner,$id,$generation,$worker,'analysis_store_scope','SUCCEEDED');
        } catch (\Throwable $error) {
            $this->runs->finishAttempt($owner,$id,$generation,$worker,'analysis_store_scope','FAILED');
            throw $error;
        }
        if ($resolution['status']==='unavailable') throw new RuntimeException('AI_STORE_SCOPE_UNAVAILABLE');
        if ($resolution['status']!=='resolved') return (new \app\services\ai\execution\AiStoreScopeGuidancePlanner())->start($compiled,$resolution['objects']);
        $reference=$resolution['objects'][0]['ref'];
        if (!preg_match('/^store:([1-9][0-9]*)$/D',$reference,$match)) throw new RuntimeException('AI_STORE_SCOPE_UNAVAILABLE');
        $stores=[(int)$match[1]];
        if (($compiled['kind']??null)==='plan' && is_array($compiled['plan']['query']??null)) {
            $compiled['plan']['query']['store_ids']=$stores;
            return $compiled;
        }
        if (($compiled['kind']??null)==='clarification') {
            $compiled['resolved_store_ids']=$stores;
            return $compiled;
        }
        throw new RuntimeException('AI_STORE_SCOPE_UNAVAILABLE');
    }

    /**
     * Resolves only an explicit “current store” reference against the
     * authenticated, still-authorized origin.  It never infers a store for a
     * question that did not contain that reference and it never widens an
     * already narrowed plan.
     */
    private function bindCurrentStoreScope(array $compiled,array $context,bool $requested): array
    {
        if (!$requested) return $compiled;
        $store=\app\services\ai\execution\AiAuthority::currentStoreId($context);
        if ($store===null) throw new RuntimeException('AI_UNSUPPORTED_CONDITION');
        if (($compiled['kind']??null)==='plan' && is_array($compiled['plan']['query']??null)) {
            $existing=$compiled['plan']['query']['store_ids']??[];
            if (!is_array($existing) || ($existing!==[] && $existing!==[$store])) throw new RuntimeException('AI_UNSUPPORTED_CONDITION');
            $compiled['plan']['query']['store_ids']=[$store];
            return $compiled;
        }
        if (($compiled['kind']??null)==='clarification') {
            $existing=$compiled['resolved_store_ids']??[];
            if (!is_array($existing) || ($existing!==[] && $existing!==[$store])) throw new RuntimeException('AI_UNSUPPORTED_CONDITION');
            $compiled['resolved_store_ids']=[$store];
            return $compiled;
        }
        throw new RuntimeException('AI_UNSUPPORTED_CONDITION');
    }

    /** Reads only trusted names for stores already granted by the current report scope. */
    private function authorizedStoreObjects(array $context): array
    {
        $before=$this->fresh($context);$stores=$before['store_ids']??null;
        if (!is_array($stores)||$stores===[]||count($stores)>10000) throw new RuntimeException('AI_STORE_SCOPE_UNAVAILABLE');
        foreach ($stores as $store) if (!is_int($store)||$store<1) throw new RuntimeException('AI_STORE_SCOPE_UNAVAILABLE');
        $transaction=$this->queryTransaction ?: [new \app\services\query\metric\MetricReadTransaction(5000),'run'];
        $names=call_user_func($transaction,function(\app\services\query\metric\GroupPerformanceMetricReadServices $reader) use($stores): array {
            return $reader->storeNames($stores);
        });
        $after=$this->fresh($context);
        if ($this->permissionHash($before)!==$this->permissionHash($after)) throw new RuntimeException('AI_AUTHORIZATION_CHANGED');
        $objects=[];
        foreach ($stores as $store) {
            $label=$names[$store]??null;
            if (!is_string($label)||trim($label)==='') throw new RuntimeException('AI_STORE_SCOPE_UNAVAILABLE');
            $objects[]=['ref'=>'store:'.$store,'kind'=>'store','label'=>$label,'aliases'=>[],
                'version'=>hash('sha256',$store.':'.$label),'relations'=>[]];
        }
        return $objects;
    }

    /** Display only dry-run-verified options. Free text retains private meaning
     * and continues through ordinary natural-language understanding. */
    private function semanticClarification(array $understanding,array $safe,array $body,array $recommendations=[]): array
    {
        // A calendar word only becomes a business follow-up with verified
        // context. An unrelated question about "today" stays general help.
        $verifiedContinuation=($understanding['topic_relation']??null)==='continuation'
            && (isset($body['context_ref']) || isset($body['_clarification_context']));
        if (!AiIntentResultContract::hasConcreteQueryMeaning($understanding,[],$verifiedContinuation)) throw new RuntimeException('AI_INTENT_UNRESOLVED');
        $options=[];
        $plans=[];
        foreach (array_values($recommendations) as $index=>$candidate) {
            $value='suggestion_'.($index+1);
            $options[]=['value'=>$value,'label'=>$candidate['label'],'recommended'=>true];
            $plans[$value]=$candidate['compiled'];
        }
        // A missing executable suggestion leaves one honest free-text path in
        // the same Run. Never pad the question with unrelated retry actions.
        $options=\app\services\ai\execution\AiSemanticRecommendationPlanner::completeCardOptions($options,
            empty($body['_platform_guidance']));
        if ($options===[]) $options[]=['value'=>'__supplement__','label'=>'补充查询条件'];
        return ['kind'=>'clarification','question'=>'我已理解部分条件，请选择您想查询的内容，或补充描述。',
            'fields'=>[['key'=>'semantic_reply','type'=>'select','label'=>'查询内容','options'=>$options]],
            '_request_body'=>$body,'_semantic_reply_state'=>$this->semanticReplyState($understanding,$safe,$body),
            '_semantic_recommendations'=>$plans];
    }

    /** A selected member cannot use aggregate recommendation plans before
     * identity is resolved. Ask for the missing measurement using only the
     * authorized registry; the private name and stated period stay in the
     * signed same-Run clarification state. A registry label is an example of
     * an available meaning, never an automatic interpretation of broad prose. */
    private function selectedMemberMetricClarification(array $understanding,array $safe,array $body,array $memberMetrics): ?array
    {
        if (empty($body['_platform_guidance']) || $memberMetrics===[]) return null;
        $selected=false;$terms=[];
        foreach ($understanding['requirements'] as $requirement) {
            $values=$requirement['values'];
            if (($values['object_kind']??null)==='member' && ($values['object_relation']??null)==='selection') $selected=true;
            foreach ((array)($values['metric_terms']??[]) as $term) $terms[]=$term;
        }
        if (!$selected || \app\services\query\metric\MetricSemanticCatalog::uniqueCodeForTerms($terms,array_keys($memberMetrics))!==null) return null;
        $guidance=$this->semanticClarification($understanding,$safe,$body);
        $options=[];$replies=[];
        foreach (array_slice(array_values($memberMetrics),0,3) as $metric) {
            $value='member_metric_'.(count($options)+1);
            $options[]=['value'=>$value,'label'=>$metric['name'],'recommended'=>true];
            $replies[$value]=$metric['name'];
        }
        $options[]=['value'=>'__supplement__','label'=>'说明其他统计口径'];
        $guidance['question']='你想查询这位会员的哪项指标？请选择当前可查询的指标，或说明其他统计口径。';
        $guidance['fields'][0]['options']=$options;
        // These labels come from the authorized catalog and resume normal
        // understanding; a click is customer confirmation, not a plan or a
        // guessed interpretation of the original broad wording.
        $guidance['_member_metric_replies']=$replies;
        return $guidance;
    }

    /** A displayed ordinal belongs to a board, not a concatenated array.
     * Only current group wording or a confirmed same-Run reply may select
     * that board. Model-supplied top/bottom alone is never that evidence. */
    private function ordinalGroupClarification(array &$understanding,array $safe,array $body,$sourceContext,array $caps,string $today): ?array
    {
        if (($understanding['topic_relation']??null)==='new_topic'
            || ($sourceContext['query']['query_shape']??null)!=='ranking') return null;
        $selection=$this->ordinalRequirement($understanding);
        if ($selection===null) return null;
        try { ResultReferenceResolver::assertSingleOrdinalGroup($sourceContext['view']); return null; }
        catch (RuntimeException $error) {
            if ($error->getMessage()!=='AI_OBJECT_DETAIL_SELECTION_REQUIRED') throw $error;
        }
        if ($selection['field']==='member_detail') {
            // Stored legacy selectors resume through the generic detail
            // meaning, preserving their exact requested view and ordinal.
            $requirement=&$understanding['requirements'][$selection['index']];
            $requirement['fields']=array_map(static function($field){return $field==='member_detail'?'object_detail':$field;},$requirement['fields']);
            $requirement['values']['object_detail']=$requirement['values']['member_detail'];
            unset($requirement['values']['member_detail']);unset($requirement);
            $selection['field']='object_detail';
        }
        $results=$sourceContext['view']['results']??[];
        $group=$this->statedOrdinalGroup((string)$safe['outbound']['question'],$selection['ordinal']);
        $singleResult=count($results)===1;
        // A bare group reply only has an ordinal when the same paused request
        // already owns it. Ordinary questions cannot acquire one from labels.
        if ($group===null && !empty($body['_clarification_context']['ordinal_group_required'])) {
            $group=$this->ordinalGroupReply((string)$safe['outbound']['question']);
        }
        if ($singleResult && $group!==null) {
            // Detail carries its requested view (including member rights).
            // Selecting a board must not replace that view with a default
            // overview. Mixed business fields still decline this shortcut.
            $compiled=$this->compileOrdinalGroupDetail($understanding,$selection,$group,$sourceContext,$body['output_format']);
            if ($compiled!==null) return $compiled;
            if ($selection['field']==='result_reference'
                || (($understanding['requirements'][$selection['index']]['values'][$selection['field']]['view']??null)==='summary'
                    && in_array($sourceContext['query']['business_filters']['object_kind']??'store',['store','person'],true))) {
                // For a mixed query the confirmed reference is only a filter;
                // its new metrics/dates stay with normal semantic binding.
                $understanding=$this->withOrdinalGroup($understanding,$selection,$group);
            }
            return null;
        }
        $candidates=[];$groups=[];
        if ($singleResult) foreach (['top'=>'最高榜单','bottom'=>'最低榜单'] as $key=>$label) {
            if (empty($results[0]['rows'][$key])) continue;
            try {
                // The ordinary detail compiler rejects additional metrics,
                // periods and filters. Such requests retain all their meaning
                // in the supplement carrier and never get an old-detail plan.
                $compiled=$this->compileOrdinalGroupDetail($understanding,$selection,$key,$sourceContext,$body['output_format']);
                if ($compiled===null || !in_array($compiled['kind']??null,['plan','member_detail'],true)) continue;
                if ($compiled['kind']==='plan') {
                    (new AiRegisteredPlanCompiler($this->registry(),static function()use($today):string{return $today;}))
                        ->compile($compiled['plan'],$caps);
                }
                $candidates[]=['label'=>$label.'第'.$selection['ordinal'].'名','compiled'=>$compiled];
                $groups[$key]='suggestion_'.count($candidates);
            } catch (RuntimeException $error) {
                // Missing rows, unsupported detail or current capability
                // rejection cannot become a suggested executable choice.
                if (!in_array($error->getMessage(),['AI_RESULT_REFERENCE_UNAVAILABLE','AI_OBJECT_DETAIL_NOT_READY'],true)
                    && !($error instanceof \app\services\ai\contract\AiContractException)
                    && !($error instanceof \app\services\query\metric\MetricQueryContractException)) throw $error;
            }
        }
        $guidance=$this->semanticClarification($understanding,$safe,$body,$candidates);
        $ordinalExists=!$singleResult;
        foreach (['top','bottom'] as $key) {
            if (isset($results[0]['rows'][$key][$selection['ordinal']-1])) $ordinalExists=true;
        }
        // A position absent from every board is not a group-choice problem.
        // Explain that actual boundary rather than propose nonexistent rows.
        $guidance['question']=!$ordinalExists
            ?'前面的榜单中没有第'.$selection['ordinal'].'名，请指定要查看的对象或调整名次。'
            :($singleResult?'前面的结果包含最高和最低两个榜单，您要看哪个榜单的第'.$selection['ordinal'].'名？'
                :'前面的结果包含多个榜单，请说明要看哪项指标、哪个榜单的第'.$selection['ordinal'].'名。');
        $guidance['fields'][0]['label']='选择榜单';
        $guidance['_semantic_reply_state']['ordinal_group_required']=true;
        $guidance['_ordinal_group_options']=$groups;
        return $guidance;
    }

    /** Reuse the existing detail resolver inside one confirmed display board.
     * The original signed view reference/query remain unchanged; only the
     * ordinal lookup sees that group's rows, preserving member detail modes. */
    private function compileOrdinalGroupDetail(array $meaning,array $selection,string $group,array $sourceContext,string $format): ?array
    {
        if (count($sourceContext['view']['results']??[])!==1
            || empty($sourceContext['view']['results'][0]['rows'][$group])) throw new RuntimeException('AI_RESULT_REFERENCE_UNAVAILABLE');
        if ($selection['field']==='result_reference') $meaning=$this->withOrdinalGroup($meaning,$selection,$group);
        else $sourceContext['view']['results'][0]['rows']=[$group=>$sourceContext['view']['results'][0]['rows'][$group]];
        return $this->compileObjectDetailContinuation($meaning,$sourceContext,$format);
    }

    /** One typed single-object ordinal can be completed without changing
     * other accepted fields. Independent/multiple selections stay unmerged. */
    private function ordinalRequirement(array $understanding): ?array
    {
        $found=null;
        foreach ((array)($understanding['requirements']??[]) as $index=>$requirement) {
            foreach (['result_reference','object_detail','member_detail'] as $field) {
                $value=$requirement['values'][$field]??null;
                if (!is_array($value) || !is_int($value['ordinal']??null)
                    || ($field!=='result_reference' && ($value['target']??null)!=='single')) continue;
                if ($found!==null) return null;
                $found=['index'=>$index,'field'=>$field,'ordinal'=>$value['ordinal']];
            }
        }
        return $found;
    }

    /** Replace only the selected board carrier; new measurements, dates and
     * independent requirement IDs keep their existing binding obligations. */
    private function withOrdinalGroup(array $meaning,array $selection,string $group): array
    {
        $requirement=&$meaning['requirements'][$selection['index']];
        $requirement['fields']=array_values(array_unique(array_map(static function($field)use($selection){
            return $field===$selection['field']?'result_reference':$field;
        },$requirement['fields'])));
        unset($requirement['values'][$selection['field']]);
        $requirement['values']['result_reference']=['group'=>$group,'ordinal'=>$selection['ordinal']];
        return $meaning;
    }

    /** These are structural board labels only, not a business intent parser.
     * Require adjacency to the ordinal so a new personnel top-N condition
     * cannot select an unrelated predecessor's store board by accident. */
    private function statedOrdinalGroup(string $question,int $ordinal): ?string
    {
        $groups=[];
        foreach (['top'=>'最高|最多|最好|最大|前[0-9一二两三四五六七八九十百几]+|top',
            'bottom'=>'最低|最少|最差|最小|倒数|后[0-9一二两三四五六七八九十百几]+|bottom'] as $group=>$words) {
            if (!preg_match_all('/(?:'.$words.')(?:的)?(?:榜单|榜|名|位|组)?(?:中|里|里面)?(?:的)?\s*第([0-9]+|[一二三四五六七八九十])(?:名|位|个)/iu',$question,$matches)) continue;
            foreach ($matches[1] as $position) {
                $number=['一'=>1,'二'=>2,'三'=>3,'四'=>4,'五'=>5,'六'=>6,'七'=>7,'八'=>8,'九'=>9,'十'=>10][$position]??(int)$position;
                if ($number===$ordinal) { $groups[]=$group;break; }
            }
        }
        return count($groups)===1?$groups[0]:null;
    }

    /** A free-text answer may confirm the same displayed group as a click.
     * Extra words must return to normal understanding, preserving new intent. */
    private function ordinalGroupReply(string $reply): ?string
    {
        $reply=preg_replace('/[\s，,。？！?!]/u','',$reply);
        foreach (['top'=>'最高|最多|最好|最大|前[0-9一二两三四五六七八九十百几]+|top',
            'bottom'=>'最低|最少|最差|最小|后[0-9一二两三四五六七八九十百几]+|bottom'] as $group=>$words) {
            if (preg_match('/^(?:'.$words.')(?:的)?(?:榜单|榜|名|位|组)?$/iuD',$reply)) return $group;
        }
        return null;
    }

    /** Complete the paused ordinal slot from a closed group-only reply. All
     * original business fields keep original evidence and ordinary binding;
     * this typed merge cannot swallow a new metric, period or object phrase. */
    private function confirmedOrdinalGroupUnderstanding(array $body,array $outbound): ?array
    {
        $previous=$body['_clarification_context']??null;
        if (empty($previous['ordinal_group_required'])) return null;
        $group=$this->ordinalGroupReply((string)$outbound['question']);
        $selection=$this->ordinalRequirement($previous['understanding']);
        if ($group===null || $selection===null) return null;
        $meaning=$previous['understanding'];
        if ($selection['field']==='result_reference') $meaning=$this->withOrdinalGroup($meaning,$selection,$group);
        $meaning['status']='understood';$meaning['topic_relation']='continuation';
        foreach ($meaning['requirements'] as $index=>&$requirement) {
            $requirement['evidence']=[['message_id'=>'original','quote'=>$previous['safe_question']]];
            if ($index===$selection['index']) $requirement['evidence'][]=['message_id'=>'current','quote'=>$outbound['question']];
        }
        unset($requirement);
        return AiIntentUnderstandingContract::normalize($meaning,$outbound);
    }

    /** A model-suggested first view does not make an object-only question
     * executable. Only the customer's accepted measurement/result request or
     * signed predecessor can supply that missing meaning. */
    private function needsStoreGoalGuidance(array $understanding,?array $sourceQuery,array $caps): bool
    {
        if ($sourceQuery!==null || ($understanding['topic_relation']??null)==='continuation') return false;
        $store=false;$hasResultRequest=false;
        foreach ($understanding['requirements'] as $requirement) {
            $values=$requirement['values'];
            if (($values['object_kind']??null)==='store') $store=true;
            // A metric definition is a metadata question, not a dated fact
            // query. Its registered definition selector owns clarification.
            if (($values['operation']??null)==='definition') return false;
            if (!empty($values['metric_terms'])) {
                $hasResultRequest=$hasResultRequest || \app\services\query\metric\MetricSemanticCatalog::uniqueCodeForTerms(
                    $values['metric_terms'],$caps['metric_codes']??[])!==null;
            }
            // A presentation form such as summary or ranking still leaves
            // the requested measurement unknown. Only a bound condition or
            // explicit object detail supplies a result without a metric term.
            if (isset($values['aggregate_condition']) || isset($values['condition_update'])
                || isset($values['object_detail'])) $hasResultRequest=true;
        }
        return $store && !$hasResultRequest;
    }

    /** Preflight shares the production compiler and performs no fact query.
     * Current-store scope is supplied by authenticated server context, never
     * inferred from the model or a customer-visible store name. A single short
     * model call ranks relevant choices; never three bindings. */
    private function verifiedSemanticClarification(array $owner,string $id,int $generation,string $worker,array $understanding,array $safe,array $body,array $caps,string $today,array $configuration,callable $checkpoint,?array $sourceQuery=null,?int $currentStoreId=null): array
    {
        // A collection predecessor has no single query to reuse. Until the
        // complete collection can be preserved, do not publish alternatives
        // that silently lose its object sets or shared conditions.
        if (isset($body['context_ref']) && (($understanding['topic_relation']??null)===null
            || (($understanding['topic_relation']??null)==='continuation' && $sourceQuery===null))) {
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'recommendation_context_not_single_query');
            return $this->semanticClarification($understanding,$safe,$body);
        }
        $planner=new \app\services\ai\execution\AiSemanticRecommendationPlanner();$labels=[];
        $objectVocabulary=$this->analysisObjectVocabulary($caps,null);
        foreach ($objectVocabulary as $object) {
            if (!isset($labels[$object['object_kind']])) $labels[$object['object_kind']]=$object['object_label'];
        }
        // The registry's unqualified store default is safe only when the
        // customer did not name another analytical subject. This is a
        // fail-closed check against source-owned object labels, not a parser:
        // it never assigns a kind or manufactures a query from keywords.
        if ($sourceQuery===null) {
            $typedObject=false;
            foreach ($understanding['requirements'] as $requirement) {
                if (isset($requirement['values']['object_kind'])) {$typedObject=true;break;}
            }
            if (!$typedObject) {
                $question=(string)($safe['outbound']['question']??'');
                foreach ($objectVocabulary as $object) {
                    if (mb_strpos($question,$object['object_label'],0,'UTF-8')===false) continue;
                    $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'recommendation_subject_unresolved');
                    return $this->semanticClarification($understanding,$safe,$body);
                }
            }
        }
        $compiler=new AiRegisteredPlanCompiler($this->registry(),static function()use($today):string{return $today;});
        $pool=$planner->candidates($understanding,$caps,$today,$body['output_format'],
            static function(array $plan)use($compiler,$caps):void{$compiler->compile($plan,$caps);},$labels,$sourceQuery,$currentStoreId,
            !empty($body['_platform_guidance']));
        if ($pool===[]) {
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'recommendation_pool_empty');
            return $this->semanticClarification($understanding,$safe,$body);
        }
        if (count($pool)<3 && empty($body['_platform_guidance'])) {
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'recommendation_pool_insufficient');
            return $this->semanticClarification($understanding,$safe,$body);
        }
        // Every member of a small pool is already registered and preflighted;
        // a ranking model cannot add meaning when there are at most three.
        if (count($pool)<=3) return $this->semanticClarification($understanding,$safe,$body,$pool);
        $metadata=[];
        foreach ($pool as $key=>$candidate) $metadata[]=['id'=>$key,'label'=>$candidate['label'],
            'meaning'=>$candidate['meaning'],'object_kind'=>$candidate['object_kind'],'query_shape'=>$candidate['query_shape']];
        $this->runs->reserve($owner,$id,$generation,$worker,'stage_count');
        $this->runs->reserve($owner,$id,$generation,$worker,'input_tokens',$this->inputTokenReservation([$safe['outbound'],$understanding,$metadata],256));
        $this->runs->reserve($owner,$id,$generation,$worker,'output_tokens',160);
        $code='select_recommendations';
        $this->runs->prepareAttempt($owner,$id,$generation,$worker,$code,'model',hash('sha256',json_encode([$safe['outbound'],$understanding,$metadata])),'siliconflow');
        $this->runs->sendAttempt($owner,$id,$generation,$worker,$code);
        try {
            $checkpoint();
            // Recommendations share the ordinary model window. The frozen
            // profile and remaining Run budget still cap it; this neither
            // grants another attempt nor extends the total execution budget.
            $timeout=$this->model===null?$this->modelCallTimeout($owner,$id,$generation,$worker,self::MODEL_STAGE_LIMIT_MS):null;
            $reply=$this->model
                ?call_user_func($this->model,$safe['outbound'],$metadata,$configuration,$checkpoint,null,'recommendations',$understanding)
                :(new SiliconFlowClient($configuration['provider']))->selectRecommendations($safe['outbound'],$understanding,$metadata,$configuration['model'],$configuration['api_key'],$timeout,$checkpoint);
            $recommendations=($reply['selections']??null)===[]?[]:$planner->select($pool,$reply['selections']??null);
            $this->runs->finishAttempt($owner,$id,$generation,$worker,$code,'SUCCEEDED',$reply['usage']['input_tokens']??null,$reply['usage']['output_tokens']??null);
        } catch (\Throwable $error) {
            if ($error instanceof AiContractException) $this->recordModelDiagnostic($owner,$id,$generation,$worker,$error,$code);
            $diagnostic=$error instanceof AiContractException?$error->diagnostic():[];
            $reason=$error->getMessage();
            $this->runs->finishAttempt($owner,$id,$generation,$worker,$code,
                in_array($reason,['AI_MODEL_RESULT_UNKNOWN','AI_CANCELLED','AI_AUTHORIZATION_CHANGED'],true)?'UNKNOWN':'FAILED');
            // An unresolved provider call cannot publish a clarification: its
            // UNKNOWN receipt and usage must remain intact. Preserve transport
            // and control failures instead of masking them as missing meaning
            // or letting pauseForClarification replace them with a guard error.
            // A received but unusable recommendation can still offer free text.
            if (($diagnostic['stage']??null)==='transport'
                || in_array($reason,['AI_MODEL_RESULT_UNKNOWN','AI_CANCELLED','AI_AUTHORIZATION_CHANGED','AI_CAPABILITY_CHANGED'],true)
                || (!($error instanceof AiContractException) && $error->getMessage()!=='AI_RECOMMENDATION_INVALID')) throw $error;
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'recommendation_selection_failed');
            $recommendations=[];
        }
        return $this->semanticClarification($understanding,$safe,$body,$recommendations);
    }

    /** The encrypted envelope binds choices to this Run/revision. HTTP clients
     * supply one option ID only; injected plans and extra keys are rejected. */
    private function semanticRecommendationChoice(array $envelope,array $input): ?array
    {
        if (($envelope['fields'][0]['key']??null)!=='semantic_reply') return null;
        $choices=$input['choices']??null;
        if (!is_array($choices)) throw new RuntimeException('AI_CLARIFICATION_INVALID');
        $value=$choices['semantic_reply']??null;
        if ($value==='__supplement__') {
            // The exact group-only reply confirms an already compiled option;
            // mixed prose stays on the ordinary supplement understanding path.
            if (!empty($envelope['_semantic_reply_state']['ordinal_group_required'])) {
                // Keep the existing length/UTF-8/control/revision boundary;
                // matching a known label cannot bypass supplement validation.
                $reply=$this->semanticClarificationReply($envelope,$input);
                $group=$this->ordinalGroupReply($reply);
                $choice=$group!==null?($envelope['_ordinal_group_options'][$group]??null):null;
                if ($choice!==null) return $envelope['_semantic_recommendations'][$choice]??null;
            }
            return null;
        }
        // A selected-member metric is a catalog-owned reply, not a plan:
        // identity still needs local resolution before any Reader call.
        if (is_string($value) && isset($envelope['_member_metric_replies'][$value])) return null;
        if (array_keys($choices)!==['semantic_reply'] || !is_string($value)
            || isset($input['revise_clarification_id']) || !isset($envelope['_semantic_recommendations'][$value])) throw new RuntimeException('AI_CLARIFICATION_INVALID');
        return $envelope['_semantic_recommendations'][$value];
    }

    /**
     * Prove one self-contained typed condition without overruling the model's
     * advisory status. Every accepted field must be accounted for; unknown
     * fields, result groups, private selections and unresolved continuations
     * retain their established binding/guidance path. No slot is supplied by
     * recommendation defaults or by a sentence-specific parser.
     */
    private function completeTypedConditionProof(array $understanding,array $safe,array $body,array $projection,array $caps,array $summaries,string $today,$sourceContext): ?array
    {
        if (($understanding['status']??null)!=='needs_clarification'
            || $sourceContext!==null
            || isset($body['context_ref']) || isset($body['_clarification_context'])
            || ($safe['outbound']['prior_query']??null)!==null
            || isset($understanding['groups']) || isset($understanding['request_kind'])
            || !empty($safe['local_conditions']) || !empty($projection['date_grouping_ambiguous'])) return null;
        $allowed=['metric_codes','object_kind','object_relation','operation','periods','scope','aggregate_condition'];
        $valueKeys=['metric_terms','object_kind','object_relation','operation','periods','scope','aggregate_condition'];
        $values=[];$hasCondition=false;
        $metricCodes=array_values(array_filter(array_column($summaries,'metric_code'),'is_string'));
        foreach (AiIntentUnderstandingContract::requirements($understanding) as $requirement) {
            $fields=(array)($requirement['fields']??[]);$row=(array)($requirement['values']??[]);
            if ($fields===[] || array_diff($fields,$allowed) || array_diff(array_keys($row),$valueKeys)) return null;
            $evidence=(array)($requirement['evidence']??[]);
            if ($evidence===[]) return null;
            foreach ($evidence as $item) if (($item['message_id']??null)!=='current') return null;
            foreach ($fields as $field) {
                $key=$field==='metric_codes'?'metric_terms':$field;
                if (!array_key_exists($key,$row)) return null;
            }
            foreach ($row as $field=>$value) {
                // The strict condition contract below combines compatible
                // predicate fragments and accounts for their metric owners.
                // Every other typed slot must have one unambiguous value.
                if ($field==='metric_terms') {
                    if (!is_array($value) || $value===[]) return null;
                    foreach ($value as $term) if (!is_string($term)
                        || \app\services\query\metric\MetricSemanticCatalog::uniqueCodeForTerms([$term],$metricCodes)===null) return null;
                } elseif ($field==='aggregate_condition') {
                    if (!is_array($value) || empty($value['conditions'])) return null;
                    foreach ($value['conditions'] as $condition) if (!is_string($condition['metric_term']??null)
                        || \app\services\query\metric\MetricSemanticCatalog::uniqueCodeForTerms([$condition['metric_term']],$metricCodes)===null) return null;
                    $hasCondition=true;
                } else {
                    if (array_key_exists($field,$values) && $values[$field]!==$value) return null;
                    $values[$field]=$value;
                }
            }
        }
        if (!$hasCondition || !isset($values['object_kind'],$values['object_relation'],$values['operation'],$values['periods'])
            || $values['object_kind']==='unknown' || $values['object_relation']!=='analysis'
            || !in_array($values['operation'],['condition_count','condition_list'],true)
            || count($values['periods'])!==1
            || !in_array($values['scope']??'unspecified',['authorized','unspecified'],true)) return null;
        $intent=$this->registeredConditionIntent($understanding,$summaries,$safe['outbound']);
        if ($intent===null) return null;
        AiIntentResultContract::assertEffectiveRequirementValues($understanding,$intent,$today);
        $projection['dates']=[];
        $projection['date_terms']=$this->naturalPeriodTerms($intent['periods'],$today);
        $projection['require_period_clarification']=true;
        // Once complete meaning is bound, capability and compiler failures
        // are real failures, not evidence that the customer must rephrase.
        // The normal executor still refreshes authority and remaining budget.
        $compiled=$this->compileConditionPlan($intent,$projection,$caps,$body['output_format'],$today);
        if (($compiled['kind']??null)!=='plan') return null;
        (new AiRegisteredPlanCompiler($this->registry(),static function()use($today):string{return $today;}))
            ->compile($compiled['plan'],$caps);
        return ['intent'=>$intent,'compiled'=>$compiled];
    }

    /** One condition-plan builder serves normal binding and local preflight.
     * Unit scaling and current-snapshot dates remain registry-owned; callers
     * must settle semantic coverage and private scope before executing it. */
    private function compileConditionPlan(array $intent,array $projection,array $caps,string $format,string $today): array
    {
        $conditionCompiler=new AiConditionSetCompiler();
        $conditionSet=$conditionCompiler->compile($intent['aggregate_condition'],$caps,$intent['operation']);
        $hasScopedPeriod=false;
        foreach ($conditionSet['conditions'] as $condition) $hasScopedPeriod=$hasScopedPeriod||isset($condition['period']);
        if ($hasScopedPeriod) {
            // Each predicate owns its date; the plan's singleton range is
            // solely the frozen reference day for relative/calendar periods.
            // A separate whole-query date would assign two meanings to a leaf.
            if ($intent['periods']!==[]) throw new AiContractException('AI_UNSUPPORTED_CONDITION');
            $projection['dates']=[];
            $projection['date_terms']=[['code'=>'TODAY']];
            $projection['date_grouping_ambiguous']=false;
        }
        if (($projection['date_terms']??[])===[] && $conditionCompiler->usesCurrentSnapshot($conditionSet,$caps)) {
            $projection['date_terms']=[['code'=>'TODAY']];
        }
        $projection['signals']=array_values(array_unique(array_merge($intent['metric_codes'],[$intent['operation']])));
        $projection['blocking_reason']=null;$projection['unresolved_condition']=false;$projection['semantic_intent']['constraints']=[];
        return (new AiWorkflowPlanner())->compile($projection,[
            'decision'=>'query','query_shape'=>$intent['operation'],'metric_codes'=>$intent['metric_codes'],
            'ranking'=>$intent['ranking'],'aggregate_condition'=>$intent['aggregate_condition'],
            'condition_set'=>$conditionSet,'object_kind'=>$intent['object_kind']
        ],$caps,$format,$today);
    }

    /** After natural-language supplementation, an exact registered metric
     * plus an explicitly understood subject/form needs no second model to
     * restate the same slots. This reuses candidate preflight, not a keyword
     * parser; private selections, compound conditions and ambiguity fail
     * admission and retain the ordinary binding/review path. */
    private function compileAcceptedSimpleSupplement(array $understanding,array $body,array $caps,string $today,?array $sourceQuery,?int $currentStoreId=null): ?array
    {
        if (!isset($body['_clarification_context']) || ($understanding['status']??null)!=='understood'
            || ($sourceQuery===null && isset($body['context_ref']))) return null;
        $values=[];
        foreach ($understanding['requirements'] as $requirement) {
            foreach ($requirement['values'] as $key=>$value) {
                if (isset($values[$key]) && $values[$key]!==$value) return null;
                $values[$key]=$value;
            }
        }
        if (!isset($values['object_kind'],$values['operation']) || ($values['object_relation']??'analysis')!=='analysis'
            || count($values['metric_terms']??[])!==1
            || \app\services\query\metric\MetricSemanticCatalog::uniqueCodeForTerms($values['metric_terms'],$caps['metric_codes']??[])===null) return null;
        $labels=[];
        foreach ($this->analysisObjectVocabulary($caps,null) as $object) {
            if (!isset($labels[$object['object_kind']])) $labels[$object['object_kind']]=$object['object_label'];
        }
        $compiler=new AiRegisteredPlanCompiler($this->registry(),static function()use($today):string{return $today;});
        $pool=(new \app\services\ai\execution\AiSemanticRecommendationPlanner())->candidates(
            $understanding,$caps,$today,$body['output_format'],
            static function(array $plan)use($compiler,$caps):void{$compiler->compile($plan,$caps);},$labels,$sourceQuery,$currentStoreId);
        return count($pool)===1?array_values($pool)[0]['compiled']:null;
    }

    /** Encrypted carrier only: it neither expands authority nor stores results. */
    private function semanticReplyState(array $understanding,array $safe,array $body): array
    {
        $previous=$body['_clarification_context']??null;
        $text=$safe['outbound']['question'];$original=$body['question'];
        if (is_array($previous)) {
            // Evidence messages forbid control characters. A second answer
            // must remain valid model input while keeping earlier meaning.
            $text=$previous['safe_question'].'；补充：'.$text;
            $original=$previous['original_question']."\n".$original;
        }
        return ['safe_question'=>$text,'understanding'=>$understanding,'original_question'=>$original,
            'reference_values'=>$safe['reference_values']??[]];
    }

    /** Preserve server-accepted slots, not concatenated keywords. Current
     * typed replacements win; a new topic and empty meaning inherit nothing.
     * This carrier grants no execution: normal binding/review still follows. */
    private function mergeClarificationMeaning(array $meaning,array $body,array $safeQuestion): array
    {
        $previous=$body['_clarification_context']??null;
        if (!is_array($previous) || $meaning['requirements']===[]
            || ($meaning['topic_relation']??null)==='new_topic') return $meaning;
        $present=[];$next=1;$originalPeriods=[];
        foreach ($meaning['requirements'] as $requirement) {
            $next=max($next,(int)substr($requirement['id'],1)+1);
            foreach ($requirement['fields'] as $field) $present[$field]=true;
        }
        foreach ($previous['understanding']['requirements'] as $requirement) {
            foreach ((array)($requirement['values']['periods']??[]) as $period) $originalPeriods[]=$period;
        }
        $originalPeriods=array_values(array_unique($originalPeriods,SORT_REGULAR));
        foreach ($meaning['requirements'] as &$requirement) {
            $sources=array_column($requirement['evidence'],'message_id');
            if (isset($requirement['values']['periods']) && $originalPeriods!==[]
                && in_array('original',$sources,true) && !in_array('current',$sources,true)) {
                $requirement['values']['periods']=$originalPeriods;
            }
        }
        unset($requirement);
        foreach ($previous['understanding']['requirements'] as $requirement) {
            $fields=[];$values=[];
            foreach ($requirement['fields'] as $field) {
                if (isset($present[$field]) || $field==='unbound') continue;
                // An explicit current scope/reference replaces the original
                // scope carrier as a unit; never restore its removed filter.
                if (in_array($field,['scope','store_term','result_reference'],true)
                    && (isset($present['scope']) || isset($present['store_term']) || isset($present['result_reference']))) continue;
                // Confirming the paused board replaces its legacy detail
                // selector; restoring that selector would create two targets.
                if (!empty($previous['ordinal_group_required']) && isset($present['result_reference'])
                    && in_array($field,['object_detail','member_detail'],true)) continue;
                if (($requirement['values'][$field]??null)==='unknown') continue;
                $keys=$field==='metric_codes'?['metric_terms','metric_exclusions']:[$field];
                foreach ($keys as $key) if (array_key_exists($key,$requirement['values'])) $values[$key]=$requirement['values'][$key];
                $fields[]=$field;$present[$field]=true;
            }
            if ($fields===[]) continue;
            $meaning['requirements'][]=['id'=>'r'.$next++,'meaning'=>'保留原问题已明确的条件',
                'fields'=>$fields,'values'=>$values,
                'evidence'=>[['message_id'=>'original','quote'=>$previous['safe_question']]]];
        }
        return \app\services\ai\contract\AiIntentUnderstandingContract::normalize($meaning,$safeQuestion);
    }

    /** Accept one server-proposed reply or bounded free text with the same
     * revision/idempotency checks as all other clarification submissions. */
    private function semanticClarificationReply(array $envelope,array $input): ?string
    {
        $choices=$input['choices']??null;
        if (!is_array($choices)) throw new RuntimeException('AI_CLARIFICATION_INVALID');
        $fields=$envelope['fields']??[];
        if (count($fields)!==1) return null;
        $key=$fields[0]['key'];$value=$choices[$key]??null;
        $semantic=$key==='semantic_reply';
        if (!$semantic && $value!=='__supplement__') return null;
        if (!isset($envelope['_semantic_reply_state'],$envelope['_request_body'])
            || isset($input['revise_clarification_id'])) throw new RuntimeException('AI_CLARIFICATION_INVALID');
        if ($value==='__supplement__') {
            $keys=array_keys($choices);sort($keys);$expected=[$key,'supplement_text'];sort($expected);
            $reply=$choices['supplement_text']??null;
            if ($keys!==$expected || !is_string($reply) || trim($reply)==='' || mb_strlen($reply,'UTF-8')>1000
                || preg_match('//u',$reply)!==1 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$reply)) throw new RuntimeException('AI_CLARIFICATION_INVALID');
            return trim($reply);
        }
        if ($semantic && is_string($value) && isset($envelope['_member_metric_replies'][$value])) {
            if (array_keys($choices)!==[$key]) throw new RuntimeException('AI_CLARIFICATION_INVALID');
            // Resume natural-language understanding with the exact confirmed
            // registered label; the browser cannot supply an executable code.
            return $envelope['_member_metric_replies'][$value];
        }
        if (array_keys($choices)!==[$key]) throw new RuntimeException('AI_CLARIFICATION_INVALID');
        // Recommended choices use the validated carrier above. Only free text
        // reaches this method; old string labels cannot restart model binding.
        throw new RuntimeException('AI_CLARIFICATION_INVALID');
    }

    /** Share ordinary clarification compilation with an explicit restart.
     * All interpretation, private identity masking and Reader guards remain
     * on the same path; a browser cannot submit an executable business plan. */
    private function compileGuidanceChoice(array $context,array $owner,string $id,int $generation,string $worker,
        array $stored,array $input,array $configuration,?array &$body,bool $continuation): array
    {
        $requiresSemanticContext=array_key_exists('_semantic_context',$stored['envelope']);
        $selected=$this->semanticRecommendationChoice($stored['envelope'],$input);
        $reply=$selected===null?$this->semanticClarificationReply($stored['envelope'],$input):null;
        $identity=$reply!==null?$this->confirmedMemberIdentityChoice($context,$stored['envelope'],$reply):null;
        if ($identity!==null) {
            // A complete phone answers a server-issued identity slot only;
            // no language model may reinterpret already accepted conditions.
            $compiled=$identity;$steps=[];$requiresSemanticContext=false;
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,
                $compiled['kind']==='plan'?'member_identity_locally_confirmed':'member_identity_not_found');
        } elseif ($selected!==null) {
            if (!empty($stored['envelope']['_semantic_reply_state']['ordinal_group_required'])) {
                // A suggested ordinal is still tied to the original signed
                // view. Recheck its expiry/current authority before executing
                // the frozen choice, just as a natural-language continuation.
                $reference=$stored['envelope']['_request_body']['context_ref']??null;
                if (!is_string($reference)) throw new RuntimeException('AI_CONTEXT_REQUIRED');
                $this->restoreContext($context,$owner,$reference);
            }
            // The option was compiled before the card was issued.
            // Current capability/config/authority snapshots were
            // checked above; execution still recompiles and guards
            // every Reader call. No model reinterprets the label.
            $compiled=$this->decorateGuidance($selected);$steps=[];$requiresSemanticContext=false;
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'verified_recommendation_selected');
        } elseif ($reply!==null) {
            if ($continuation) $this->runs->scopeSemanticContinuation($owner,$id,$generation,$worker);
            // Interpret the reply against server-owned original meaning;
            // a fresh recovery Run uses the same private merge boundary.
            $body=$stored['envelope']['_request_body'];
            $body['_clarification_context']=$stored['envelope']['_semantic_reply_state'];
            $body['question']=$reply;
            $projection=['dates'=>[],'date_terms'=>[],'signals'=>[],'semantic_intent'=>['constraints'=>[]],'blocking_reason'=>null,'unresolved_condition'=>false];
            $compiled=$this->decorateGuidance($this->understandAnalysis($context,$owner,$id,$generation,$worker,$body,$projection,$configuration));
            $requiresSemanticContext=false;
            $steps=[];
        } else {
            [$compiled,$steps]=$this->advanceGuidance($stored,$stored['envelope_ref'],$input);
        }
        if (($compiled['kind']??null)==='clarification') {
            $compiled['_request_body']=$compiled['_request_body']??$body??($stored['envelope']['_request_body']??null);
            if (!isset($compiled['_semantic_reply_state']) && isset($stored['envelope']['_semantic_reply_state'])) {
                $compiled['_semantic_reply_state']=$stored['envelope']['_semantic_reply_state'];
            }
        }
        if (isset($stored['envelope']['_member_detail_request'])) {
            $compiled['_member_detail_request']=$stored['envelope']['_member_detail_request'];
        }
        if (isset($stored['envelope']['_ranking_detail_request'])) {
            $compiled=$this->attachPopulationDetail($compiled,$stored['envelope']['_ranking_detail_request']);
        }
        return [$compiled,$steps,$requiresSemanticContext];
    }

    /** Resolve only a full-phone reply to our encrypted identity request.
     * The frozen plan already owns metric/date/store meaning. Current local
     * member scope, followed by the ordinary compiler and Reader guards,
     * owns identity and facts. Other prose still uses semantic understanding. */
    private function confirmedMemberIdentityChoice(array $context,array $envelope,string $reply): ?array
    {
        $pending=$envelope['_member_identity_request']??null;
        if (!is_array($pending) || preg_match('/^1[3-9][0-9]{9}$/D',$reply)!==1) return null;
        $compiled=$pending['compiled'];$query=$compiled['plan']['query'];$metric=$query['metric_codes'][0];
        $available=\app\services\ai\execution\AiCapabilityGuidanceCatalog::discover($this->capabilities($context),'member','summary');
        if (!isset($available[$metric])) throw new RuntimeException('AI_CAPABILITY_CHANGED');
        $matches=$this->memberObjects($context)->exactCandidates($pending['name'],[$metric],$reply);
        if (count($matches)>1) throw new RuntimeException('AI_OBJECT_BINDING_UNAVAILABLE');
        if ($matches===[]) {
            // Keep every accepted slot and ask for a corrected identifier;
            // zero exact matches is never an empty consumption result.
            $envelope['question']='未找到与该姓名和手机号同时匹配的会员。请核对完整手机号。';
            return $envelope;
        }
        $compiled['plan']['query']['business_filters']['selection_ref']=$matches[0]['ref'];
        return $compiled;
    }

    /** Authenticate an expired, retained source under the current principal.
     * Reuse immutable private guidance only while its capability, permission
     * and model snapshots still match; no raw transcript or ID leaves here. */
    private function expiredClarificationSource(array $context,array $owner,$source): array
    {
        if (($context['terminal']??null)!=='platform' || !is_array($source)
            || array_diff(array_keys($source),['run_id','generation','client_session_id','run_delivery_token','clarification_id','choices'])
            || !is_array($source['choices']??null) || $source['choices']===[]) throw new RuntimeException('AI_CLARIFICATION_STALE');
        $id=$this->identifier($source['run_id']??null);$session=$this->identifier($source['client_session_id']??null);
        $generation=$source['generation']??null;
        $proof=$this->verifyToken($source['run_delivery_token']??'',$context,$session,'run');
        if (!is_int($generation) || $generation<1 || ($proof['run']??null)!==$id || ($proof['generation']??null)!==$generation
            || ($proof['conversation']??null)!==$owner['conversation_id']) throw new RuntimeException('AI_DELIVERY_INVALID');
        $sourceOwner=$owner;$sourceOwner['window_id']=$session;
        $run=$this->runs->get($sourceOwner,$id,$generation);
        if ($run['status']!=='FAILED' || $run['reason']!=='CLARIFICATION_EXPIRED'
            || ($source['clarification_id']??null)!==$run['clarification_ref']) throw new RuntimeException('AI_CLARIFICATION_STALE');
        $snapshot=$this->runs->snapshot($sourceOwner,$id,$generation);$configuration=$this->config->read();$fresh=$this->fresh($context);
        if (empty($fresh['can_use']) || ($snapshot['authorization_version']??null)!==$this->permissionHash($fresh)
            || ($snapshot['model_config_version']??null)!==(string)$configuration['version']) throw new RuntimeException('AI_AUTHORIZATION_CHANGED');
        if (($snapshot['capability_snapshot_ref']??null)!==$this->registryHash($context)
            || ($snapshot['capability_snapshot_hash']??null)!==hash('sha256',json_encode($this->capabilities($context)))) throw new RuntimeException('AI_CAPABILITY_CHANGED');
        $stored=$this->private->read($run['clarification_ref']);$this->assertBinding($stored,$sourceOwner,$id,$generation);
        return $stored+['envelope_ref'=>$run['clarification_ref']];
    }

    private function issueGuidance(array $owner,string $id,int $generation,string $worker,array $envelope,array $origin,array $steps): array
    {
        $run=$this->runs->get($owner,$id,$generation);
        if ($run['clarification_count'] >= $run['max_clarification_rounds']) throw new RuntimeException('AI_CLARIFICATION_EXHAUSTED');
        if ($run['guidance_schema_version']==='mohe-clarification-v1') {
            $envelope['fields']=array_merge($envelope['fields'],$envelope['pending_fields']??[]); unset($envelope['pending_fields']);
        }
        $ref=$this->private->put('clarification',['owner'=>$owner,'run_id'=>$id,'generation'=>$generation,'envelope'=>$envelope,'origin'=>$origin,'accepted_steps'=>$steps],intdiv($run['expires_at'],1000));
        return $this->runs->pauseForClarification($owner,$id,$generation,$worker,$ref);
    }

    /** Revisions replay immutable semantic state, not a narrowed downstream candidate list. */
    private function advanceGuidance(array $stored,string $ref,array $input): array
    {
        $planner=$this->guidancePlanner($stored['envelope']); $choices=$input['choices']??null;
        if (!is_array($choices)) throw new RuntimeException('AI_CLARIFICATION_INVALID');
        $semantic=$stored['envelope']['_semantic_context']??null;
        if ($semantic!==null && !is_array($semantic)) throw new RuntimeException('AI_CLARIFICATION_STALE');
        $steps=$stored['accepted_steps']??[];
        if (!isset($input['revise_clarification_id'])) {
            $next=$this->decorateGuidance($planner->choose($stored['envelope'],$choices));
            $next=$this->bindGuidanceConstraints($next,$stored['envelope']['inherited_query_constraints']??($stored['origin']['inherited_query_constraints']??null));
            $next=$this->carryGuidanceSemanticContext($next,$semantic);
            $steps[]=['id'=>$ref,'envelope'=>$stored['envelope'],'choices'=>$choices];
            return [$next,$steps];
        }
        $target=$this->identifier($input['revise_clarification_id']); $found=false;
        // The original compiled plan precedes the first clarification and
        // cannot replay a prior choice. Rebuild from the saved first envelope,
        // which is the exact server-issued state for the first accepted step.
        $draft=($steps[0]['envelope']??null);
        if (!is_array($draft)) $draft=$stored['origin']??$stored['envelope'];
        $rebuilt=[];
        foreach ($steps as $step) {
            if ($step['id']===$target) $found=true;
            if (($draft['kind']??'')!=='clarification') break;
            $expected=array_column($draft['fields'],'key'); $previous=array_keys($step['choices']); sort($expected); sort($previous);
            if ($expected!==$previous) continue;
            $selection=$step['id']===$target?$choices:$step['choices'];
            // A revision replays a chain which may have crossed multiple
            // guidance types (for example store choice -> date choice). Use
            // the planner carried by each historical envelope, rather than
            // the planner of the currently displayed final step.
            try { $next=$this->decorateGuidance($this->guidancePlanner($draft)->choose($draft,$selection)); }
            catch (\Throwable $error) {
                if ($step['id']===$target || !$found) throw $error;
                break; // Invalid dependent binding must be re-confirmed, not silently retained.
            }
            $rebuilt[]=['id'=>$step['id'],'envelope'=>$draft,'choices'=>$selection];
            $draft=$this->bindGuidanceConstraints($next,$draft['inherited_query_constraints']??($stored['origin']['inherited_query_constraints']??null));
            $draft=$this->carryGuidanceSemanticContext($draft,$semantic);
        }
        if (!$found) throw new RuntimeException('AI_CLARIFICATION_STALE');
        return [$draft,$rebuilt];
    }

    /** Server-only semantic state must survive every planner boundary. */
    private function carryGuidanceSemanticContext(array $envelope,?array $semantic): array
    {
        if ($semantic!==null) $envelope['_semantic_context']=$semantic;
        return $envelope;
    }

    /** A server-owned pending-context choice may explicitly alter only its own
     * inherited constraint.  The marker is never included in an executable plan. */
    private function bindGuidanceConstraints(array $envelope,?array $fallback): array
    {
        $constraints=array_key_exists('inherited_query_constraints',$envelope)
            ? $envelope['inherited_query_constraints'] : $fallback;
        unset($envelope['inherited_query_constraints']);
        return IntentContextMerger::bind($envelope,$constraints);
    }

    /** Selects only the server-owned planner encoded by a clarification state. */
    private function guidancePlanner(array $envelope)
    {
        if (isset($envelope['store_scope_state'])) return new \app\services\ai\execution\AiStoreScopeGuidancePlanner();
        if (isset($envelope['context_replacement_state'])) return new \app\services\ai\execution\AiContextReplacementGuidancePlanner();
        if (isset($envelope['pending_context_state'])) return new \app\services\ai\execution\AiPendingContextGuidancePlanner();
        if (isset($envelope['date_range_guidance_state'])) return new \app\services\ai\execution\AiDateRangeGuidancePlanner();
        if (isset($envelope['analysis_state'])) return new \app\services\ai\execution\AiAnalysisGuidancePlanner();
        if (isset($envelope['collection_ranking_state'])) return new \app\services\ai\execution\AiRankingCollectionGuidancePlanner();
        if (isset($envelope['dimension_state'])) return new \app\services\ai\execution\AiDimensionGuidancePlanner();
        if (isset($envelope['skill_state'])) return new \app\services\ai\execution\AiSkillGuidancePlanner();
        return new AiWorkflowPlanner();
    }

    private function understandAnalysis(array $context,array $owner,string $id,int $generation,string $worker,array $body,array $projection,array $configuration): array
    {
        $freshContext=$this->fresh($context);
        if ($this->permissionHash($freshContext)!==$this->permissionHash($context)) throw new RuntimeException('AI_AUTHORIZATION_CHANGED');
        // Workspace guidance is terminal-scoped; the approved cashier drawer
        // continues to receive its original card options.
        $body['_platform_guidance']=($context['terminal']??null)==='platform';
        $projection['no_filler_guidance']=$body['_platform_guidance'];
        $caps=$this->capabilities($freshContext);
        $checkpoint=function()use($context,$owner,$id,$generation,$worker,$configuration):void {
            $this->runs->checkpoint($owner,$id,$generation,$worker);
            $current=$this->config->read();
            // Losing AI use permission may leave the data-scope hash intact.
            // Check it explicitly before any initial or recovery model send.
            $fresh=$this->fresh($context);
            if ($current['version']!==$configuration['version'] || !AiConfigStore::allowsSanitizedQuestion($current)
                || empty($fresh['can_use']) || $this->permissionHash($fresh)!==$this->permissionHash($context)) throw new RuntimeException('AI_AUTHORIZATION_CHANGED');
        };
        $createdAt=$this->runs->get($owner,$id,$generation)['created_at'];
        $today=(new \DateTimeImmutable('@'.intdiv($createdAt,1000)))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
        // An authentic expired predecessor supplies no executable facts. The
        // current question is understood on its own; an incomplete follow-up
        // must ask for context rather than reconstructing old conditions from
        // unsigned browser history. Invalid or foreign references still fail.
        $sourceContext=null;
        if (isset($body['context_ref'])) {
            if ($this->contextService()->isExpiredOwnReference($context,$owner,$body['context_ref'])) {
                $body['history']=[];
                $this->runs->markTopicReset($owner,$id,$generation,$worker);
            } else {
                $sourceContext=$this->restoreContext($context,$owner,$body['context_ref']);
            }
        }
        // A prior source cohort still matters when a *complete* unrelated
        // store summary is admitted below. This flag does not interpret a
        // source follow-up or rebuild any of its signed filters.
        $priorSourceQuery=is_array($sourceContext['query']??null)?$sourceContext['query']:null;
        $priorSource=$priorSourceQuery!==null && \app\services\query\metric\FirstCourseSourceFilter::valid(
            (array)($priorSourceQuery['business_filters']??[]),
            (array)($priorSourceQuery['metric_codes']??[]),
            (array)($priorSourceQuery['condition_set']??[])
        );
        // Only a *complete* categorical source question uses this closed
        // grammar. A verified predecessor may supply its signed period and
        // store scope when both questions concern the same source cohort;
        // open follow-ups such as "明细呢" use typed semantic understanding.
        $sourceRequest=isset($body['_clarification_context'])?null:
            (new \app\services\ai\semantic\AiFirstCourseSourceAdmission())->match((string)$body['question']);
        if ($sourceRequest!==null) {
            $code='member_first_course_count';
            $ready=$caps['metric_readiness'][$code]??null;
            if (!is_array($ready)||empty($ready['ai_query_ready'])
                ||!in_array('condition_count',(array)($ready['query_shapes']??[]),true)) {
                throw new RuntimeException('AI_CAPABILITY_NOT_READY');
            }
            $source=(new \app\services\query\metric\FirstCourseSourceCatalog())->resolve($sourceRequest['source_term']);
            $filter=['object_kind'=>'member','source_primary_id'=>(int)$source['source_primary_id']];
            if ((int)($source['source_secondary_id']??0)>0) $filter['source_secondary_id']=(int)$source['source_secondary_id'];
            $shape=$sourceRequest['form']==='list'?'condition_list':'condition_count';
            $period=$sourceRequest['period'];
            $range=$period!==null?(new AiWorkflowPlanner())->normalizePeriod($period,$today)
                :($priorSource?['start'=>$priorSourceQuery['start_date'],'end'=>$priorSourceQuery['end_date']]
                    :(new AiWorkflowPlanner())->normalizePeriod(['code'=>'TODAY'],$today));
            $binding=['subject'=>'member','relation'=>'all','result_form'=>$shape==='condition_list'?'list':'count',
                'conditions'=>[['metric_code'=>$code,'operator'=>'gt','quantity'=>'0','unit'=>'count']]];
            $set=(new AiConditionSetCompiler())->compile($binding,$caps,$shape);
            $query=['query_shape'=>$shape,'metric_codes'=>[$code],
                'start_date'=>$range['start'],'end_date'=>$range['end'],'compare_range'=>null,
                'store_ids'=>$priorSource?(array)$priorSourceQuery['store_ids']:[],
                'business_filters'=>$filter,'ranking'=>null,
                'aggregate_condition'=>$binding,'condition_set'=>$set];
            $compiled=['kind'=>'plan','plan'=>['workflow_code'=>'wf_performance_'.$shape,
                'query'=>$query,'output_format'=>$body['output_format']],
                '_context_meaning'=>['presentation_origin'=>'customer_or_verified_context']];
            if ($sourceRequest['detail']) $compiled['_member_detail_request']=['view'=>'summary','target'=>'set','ordinal'=>null];
            if ($sourceContext!==null && !$priorSource) $this->runs->markTopicReset($owner,$id,$generation,$worker);
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'registered_first_course_source_admitted');
            return $compiled;
        }
        $priorQueries=isset($sourceContext['items'])?array_column($sourceContext['items'],'query'):
            (isset($sourceContext['query'])?[$sourceContext['query']]:[]);
        $independent=true;
        foreach ($priorQueries as $priorQuery) {
            if (!is_array($priorQuery) || !empty($priorQuery['store_ids']) || !empty($priorQuery['business_filters'])
                || !empty($priorQuery['aggregate_condition']) || !empty($priorQuery['condition_set'])) {
                $independent=false;break;
            }
        }
        $admission=new \app\services\ai\semantic\AiDeterministicSummaryAdmission();
        // A complete, grammar-closed metric/date question after a source
        // cohort is a new topic. The admission below rejects any residual
        // pointer such as "这家"; therefore the old categorical source must
        // not force an unrelated self-contained question through context.
        if (!isset($body['_clarification_context']) && ($independent || $priorSource)) {
            $match=$admission->match(
                (string)$body['question'],\app\services\query\metric\MetricSemanticCatalog::entries()
            );
            if ($match!==null) {
                $metric=$match['metric_code'];
                $contract=\app\services\query\metric\MetricDefinitionRegistry::capabilities()[$metric]??null;
                // Only a registered store summary may enter this narrow path.
                // Known but unavailable metrics stay a capability result; the
                // model must not choose a different readable measurement.
                if (is_array($contract) && ($contract['filter_grain']??null)==='store') {
                    if (!in_array($metric,$caps['metric_codes'],true)) throw new RuntimeException('AI_CAPABILITY_NOT_READY');
                    $checkpoint();
                    $projection['signals']=[$metric,'summary'];
                    $projection['date_terms']=[$match['date_term']];
                    $caps['current_store_bound']=\app\services\ai\execution\AiAuthority::currentStoreId($context)!==null;
                    $compiled=(new AiWorkflowPlanner())->compile($projection,[
                        'decision'=>'query','query_shape'=>'summary','metric_codes'=>[$metric],'object_kind'=>'store'
                    ],$caps,$body['output_format'],$today);
                    if (($compiled['kind']??null)==='plan') {
                        $compiled['_context_meaning']=['presentation_origin'=>'customer_or_verified_context'];
                        if ($sourceContext!==null) $this->runs->markTopicReset($owner,$id,$generation,$worker);
                        $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'deterministic_registered_summary_admitted');
                    }
                    return $compiled;
                }
            }
            // Complete two-period comparisons use either the exact named
            // registered metrics or the broad store overview profile. Extra
            // objects, filters and unresolved words remain model work.
            $registeredComparison=$this->compileRegisteredComparison(
                (string)$body['question'],$caps,$body['output_format'],$today
            );
            if ($registeredComparison!==null) {
                $checkpoint();
                if ($sourceContext!==null) $this->runs->markTopicReset($owner,$id,$generation,$worker);
                $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'deterministic_registered_comparison_admitted');
                return $registeredComparison;
            }
        }
        // A complete calendar-only continuation has no new metric or object
        // to bind. Reuse the already replayed signed query and change exactly
        // its period; the normal executor still rechecks every fact and grant.
        // Comparisons and collections retain their existing contextual path.
        $periodTerm=isset($sourceContext['query'])?$admission->periodOnly((string)$body['question']):null;
        if ($periodTerm!==null) {
            $query=$sourceContext['query'];
            $shape=$query['query_shape']??null;
            // "Change the period" cannot identify which predicate to revise
            // after different conditions carried different signed periods.
            $hasScopedPeriod=false;
            foreach ((array)($query['condition_set']['conditions']??[]) as $condition) {
                $hasScopedPeriod=$hasScopedPeriod||isset($condition['period']);
            }
            if (in_array($shape,['summary','breakdown','trend','ranking','threshold_count','condition_count','condition_list'],true)
                && !$hasScopedPeriod && ($query['compare_range']??null)===null) {
                $checkpoint();
                $range=(new AiWorkflowPlanner())->normalizePeriod($periodTerm,$today);
                $query['start_date']=$range['start'];$query['end_date']=$range['end'];
                $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'verified_period_only_summary_reused');
                return ['kind'=>'plan','plan'=>['workflow_code'=>'wf_performance_'.$shape,
                    'query'=>$query,'output_format'=>$body['output_format']],
                    '_context_meaning'=>(array)($sourceContext['meaning']??[])];
            }
        }
        $runtimeSkills=$this->registry()->modelSkills('store_operations');$dictionary=new \app\services\metric\MetricDictionaryServices();$summaries=[];
        $objectVocabulary=$this->analysisObjectVocabulary($caps);
        // The model transport has a vocabulary budget, while exact admission
        // must inspect every active registry alias before deciding that a
        // question is closed. Keeping these projections separate prevents a
        // newly registered natural alias from evicting its canonical label or
        // silently losing deterministic coverage.
        $exactObjectVocabulary=$this->analysisObjectVocabulary($caps,null);
        // Closed extrema do not need two language-model rounds.
        // Admission is deliberately stricter than understanding: every metric
        // and object must be an exact active-registry phrase, one common date
        // carrier must already be structurally valid, and no business residue
        // may remain. Open or ambiguous language continues through the model.
        $exactRankingCollectionReason=null;
        // A fully closed ranking with its own period names metric, object and
        // rank too. The exact residue gate rejects previous-population
        // references, so it starts a new topic without old scope or period.
        $exactRankingCollection=$this->compileExactRegisteredRankingCollection(
            (string)$body['question'],$exactObjectVocabulary,$caps,$body['output_format'],$today,
            $exactRankingCollectionReason,$sourceContext,$context
        );
        if ($exactRankingCollection!==null) {
            $checkpoint();
            // A closed sentence can still continue the verified store/date
            // analysis. The admission itself proves whether a compatible
            // signed metric was reused; only an independent plan resets it.
            if ($sourceContext!==null && empty($exactRankingCollection['_context_reused'])) {
                $this->runs->markTopicReset($owner,$id,$generation,$worker);
            }
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'deterministic_registered_ranking_collection_admitted',[],'exact_ranking_admission');
            return $exactRankingCollection;
        }
        if ($exactRankingCollectionReason!==null && $exactRankingCollectionReason!=='semantic_no_match') {
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,
                'deterministic_registered_ranking_collection_rejected',['field'=>$exactRankingCollectionReason],'exact_ranking_admission');
        }
        foreach ($caps['metric_codes'] as $code) {
            $tooltip=$dictionary->getTooltip($code);if (($tooltip['user_ready']??false)!==true) continue;
            $contract=$caps['metric_readiness'][$code];$objectContracts=[];
            $baseKind=$contract['filter_grain'];$objectContracts[$baseKind]=[];
            foreach ((array)($contract['analysis_dimension_contracts']??[]) as $dimension) {
                if (!is_array($dimension) || !is_string($dimension['object_kind']??null) || !is_array($dimension['action_codes']??null)) continue;
                $kind=$dimension['object_kind'];
                foreach ($dimension['action_codes'] as $action) if (is_string($action)) $objectContracts[$kind][$action]=true;
            }
            $objects=[];
            foreach($objectContracts as $kind=>$actions){$actions=array_keys($actions);sort($actions);$objects[]=['object_kind'=>$kind,'action_codes'=>$actions];}
            // The model receives the registered semantic boundary, not just a
            // short display title. This lets natural-language understanding
            // distinguish business facts without a phrase catalogue in code.
            $meaning=[];
            foreach (['summary','include','exclude','timing','note'] as $key) {
                if (is_string($tooltip[$key]??null) && $tooltip[$key]!=='') $meaning[]=$tooltip[$key];
            }
            // Overview group names come from the metric registry. Exposing
            // them as descriptions lets natural-language binding understand
            // one or several visible overview groups without a phrase branch.
            $overviewSections=AiOverviewMetricResolver::sectionNamesForMetric($contract);
            if ($overviewSections!==[]) $meaning[]='经营概览分组：'.implode('、',$overviewSections);
            $summaries[]=['metric_code'=>$code,'name'=>$tooltip['name'],'summary'=>implode("\n",$meaning),'object_contracts'=>$objects,
                'query_shapes'=>array_values((array)($contract['query_shapes']??[])),
                'default_selection_ref'=>$contract['analysis_default_selection_ref']??null,
                // The registry may disclose one default ranking perspective
                // for a compatible broad first answer. It is deliberately
                // scoped to object kinds, never inferred from question words.
                'default_rank_object_kinds'=>array_values((array)($contract['analysis_default_rank_object_kinds']??[])),
                // The same source-owned policy can provide one concise first
                // column for a broad per-object breakdown. Explicit metrics
                // still bypass this default completely.
                'default_breakdown_object_kinds'=>array_values((array)($contract['analysis_default_breakdown_object_kinds']??[]))];
        }
        $measurementVocabulary=$this->analysisMeasurementVocabulary($caps);
        // The prompt-facing candidates and the later controlled choices are both
        // projected from the same registered provider contracts.  A dictionary
        // definition alone therefore never becomes an executable AI choice.
        $personMetrics=\app\services\ai\execution\AiCapabilityGuidanceCatalog::discover($caps,'person');
        $memberMetrics=\app\services\ai\execution\AiCapabilityGuidanceCatalog::discover($caps,'member','summary');
        $localCatalogs=[];$memberLabels=[];$privateLabels=[];$privateKinds=[];$privateKindsByReference=[];
        if ($personMetrics) {
            // Pre-plan metadata discovery is a bounded, server-owned catalog read,
            // not a model-authored business query or an extra executable workflow.
            $this->runs->prepareAttempt($owner,$id,$generation,$worker,'analysis_objects','tool',hash('sha256',json_encode(array_keys($personMetrics))),'metric_catalog_read');
            $this->runs->sendAttempt($owner,$id,$generation,$worker,'analysis_objects');
            try {
                $transaction=$this->queryTransaction ?: [new \app\services\query\metric\MetricReadTransaction(5000,$checkpoint),'run'];
                $localCatalogs=call_user_func($transaction,function()use($personMetrics,$context,$checkpoint){
                    $out=[];foreach(array_keys($personMetrics) as $metric){$checkpoint();$out[$metric]=$this->personnelObjects($context)->catalog($metric);}return $out;
                });
                $checkpoint();$this->runs->finishAttempt($owner,$id,$generation,$worker,'analysis_objects','SUCCEEDED');
            } catch (\Throwable $error) {$this->runs->finishAttempt($owner,$id,$generation,$worker,'analysis_objects','FAILED');throw $error;}
            foreach($localCatalogs as $catalog) foreach($catalog['objects'] as $object) {
                if (!in_array($object['kind'],['person','position'],true)) continue;
                foreach(array_merge([$object['label']],(array)($object['aliases']??[])) as $label) {
                    $privateLabels[]=$label;$privateKinds[$label][$object['kind']]=true;
                }
            }
        }
        // Member names are not read as a directory.  We only inspect bounded
        // exact fragments which already occur in this local conversation, then
        // mask a current authorized match before the external model sees it.
        if ($memberMetrics && (($context['member_data_authorized']??false)===true)) {
            $questions=[];
            // Keep the same bounded recency horizon as the de-identified
            // conversation projection. Older browser history cannot quietly
            // turn a single request into an unbounded local member lookup.
            // Verified query context replaces browser history in the outgoing
            // model request below. Do not scan discarded historical text for
            // member names; the current question still receives full masking.
            if ($sourceContext===null) {
                foreach (array_slice((array)($body['history']??[]),-6) as $round) if (is_array($round) && is_string($round['question']??null)) $questions[]=$round['question'];
            }
            $questions[]=$body['question'];
            if (isset($body['_clarification_context']['original_question'])) $questions[]=$body['_clarification_context']['original_question'];
            // Mask distinct authorized labels before the model call. Identity
            // binding waits until the model has confirmed member intent, so
            // many members sharing one name cannot fail the understanding step.
            $this->runs->prepareAttempt($owner,$id,$generation,$worker,'analysis_members','tool',
                hash('sha256',json_encode([count($questions),array_keys($memberMetrics)])),'member_catalog_read');
            $this->runs->sendAttempt($owner,$id,$generation,$worker,'analysis_members');
            try {
                $checkpoint();
                $memberLabels=$this->memberObjects($context)->maskingLabelsConversation($questions);
                $checkpoint();
                $this->runs->finishAttempt($owner,$id,$generation,$worker,'analysis_members','SUCCEEDED');
            } catch (\Throwable $error) {
                $this->runs->finishAttempt($owner,$id,$generation,$worker,'analysis_members','FAILED');
                throw $error;
            }
            foreach ($memberLabels as $label) {
                $privateLabels[]=$label;$privateKinds[$label]['member']=true;
            }
        }
        // Resolve overlapping local identities only when a private person or
        // member label actually occurs in this question. Complete authorized
        // store names take part in the same longest-first mask; ordinary
        // anonymous rankings incur no extra catalogue read.
        $identityOverlap=false;
        foreach ($privateKinds as $label=>$kinds) {
            if ((isset($kinds['member'])||isset($kinds['person']))
                && strpos($body['question'],(string)$label)!==false) {$identityOverlap=true;break;}
        }
        if ($identityOverlap) foreach ($this->authorizedStoreObjects($context) as $storeObject) {
            $label=$storeObject['label'];
            $privateLabels[]=$label;$privateKinds[$label]['store']=true;
        }
        $protectedSemanticTerms=[];$registeredCodes=array_keys((array)($caps['metric_readiness']??[]));
        // Registered analytical object labels are public capability words,
        // not proof that a same-named member was selected. Protecting them
        // from private-label masking prevents a generic “members” question
        // from becoming a single-member query. A real private name remains
        // masked and still requires the normal selection/authority checks.
        foreach ($objectVocabulary as $objectVocabularyItem) {
            $label=is_array($objectVocabularyItem)?($objectVocabularyItem['object_label']??null):null;
            if (is_string($label)&&$label!=='') $protectedSemanticTerms[]=$label;
        }
        // The same active registry that later binds a metric also protects a
        // complete registered metric title during local-object de-identifying.
        // This prevents a shorter private alias inside that title from
        // destroying its exact semantic identity before natural-language
        // understanding begins; it is data-driven for every registered metric.
        foreach (array_merge([(string)$body['question']],array_map(static function($round):string {
            return is_array($round)&&is_string($round['question']??null)?$round['question']:'';
        },(array)$body['history'])) as $wording) {
            // A shorter private nickname can overlap a public calendar word
            // (for example, two characters crossing "today" and the next
            // noun). Shield the whole recognised date span before masking.
            // Exact private-label collisions remain masked, not disclosed.
            foreach (\app\services\ai\semantic\AiSemanticIntentParser::calendarEvidence($wording)['matched_texts'] as $dateText) {
                if (!in_array($dateText,$privateLabels,true)) $protectedSemanticTerms[]=$dateText;
            }
            foreach (\app\services\query\metric\MetricSemanticCatalog::registeredTermsInText($wording,$registeredCodes) as $match) {
                if (is_string($match['term']??null)) $protectedSemanticTerms[]=$match['term'];
            }
            // A private label may coincide with the public comparative adverb
            // “严格”. Shield only the complete numeric comparator, never the
            // standalone label, so de-identification cannot turn “strictly
            // greater than” into an unresolved person reference.
            foreach (\app\services\ai\semantic\AiConditionResponseFormResolver::protectedStrictComparisonTerms($wording) as $operator) {
                $protectedSemanticTerms[]=$operator;
            }
        }
        $safe=(new \app\services\ai\model\AiSafeQuestionProjector())->projectConversation(
            $body['question'],$body['history'],$configuration,array_values(array_unique($privateLabels)),
            array_values(array_unique($protectedSemanticTerms)),
            (array)($body['_clarification_context']['reference_values']??[])
        );
        foreach (($safe['reference_values']??$safe['local_conditions']) as $reference=>$value) {
            if(in_array($value,$privateLabels,true)) {
                $kinds=$privateKinds[$value]??[];
                $kindCount=count($kinds);
                $descriptor=$kindCount===1&&isset($kinds['store'])?'门店':(isset($kinds['position'])&&!isset($kinds['person'])&&!isset($kinds['member'])?'岗位'
                    :(isset($kinds['member'])&&!isset($kinds['person'])&&!isset($kinds['position'])?'会员'
                    :($kindCount>1?'对象':'人员')));
                $privateKindsByReference[$reference]=$descriptor==='门店'?'store':($descriptor==='岗位'?'position':($descriptor==='会员'?'member':($descriptor==='对象'?'object':'person')));
                $safe['outbound']['question']=str_replace('['.$reference.']',$descriptor.' ['.$reference.']',$safe['outbound']['question']);
            }
        }
        // Evidence excerpts always refer to the exact de-identified text sent
        // to the model.  The descriptor above is part of that text, so keep
        // the server-owned evidence projection in lockstep with it.
        foreach ((array)($safe['outbound']['evidence_messages']??[]) as $index=>$message) {
            if (($message['id']??null)==='current') $safe['outbound']['evidence_messages'][$index]['text']=$safe['outbound']['question'];
        }
        $safe['outbound']['has_unresolved_conditions']=(bool)$safe['local_conditions'];
        // The reference date is server-owned context, not a guessed reading of
        // customer language.  Relative time is interpreted by the model and
        // then materialized below with this value.
        $safe['outbound']['reference_date']=$today;
        $safe['outbound']['server_resolved_fields']=[];
        // A signed answer reference is useful conversation context, not a
        // shortcut around natural-language understanding.  It is verified and
        // reduced to non-sensitive query meaning before the model sees it.
        $sourceCollection=is_array($sourceContext['items']??null)?$sourceContext['items']:[];
        // A collection has several peer answers. Its first item is not a
        // default referent for an unqualified follow-up; only collection
        // operations that explicitly apply to every item may inspect it.
        $sourceQuery=$sourceContext['query']??(count($sourceCollection)===1?($sourceCollection[0]['query']??null):null);
        if ($sourceContext!==null) {
            // The signed context is restored and replayed under current
            // authority before this point.  It is therefore the only prior
            // query carrier needed for this follow-up; do not resend the
            // browser's stale local-question excerpt to the model.
            $safe['outbound']=(new \app\services\ai\model\AiSafeQuestionProjector())->forVerifiedContext($safe['outbound']);
        }
        $safe['outbound']['prior_query']=$sourceQuery===null?null:IntentContextMerger::modelView(
            $sourceQuery,(array)($sourceContext['meaning']??[])
        );
        if (isset($body['_clarification_context'])) {
            // Only server-stored, already de-identified meaning crosses this
            // boundary. Raw names remain in the encrypted reference map.
            $previous=$body['_clarification_context'];
            $safe['outbound']['clarification_context']=['question'=>$previous['safe_question'],
                'understanding'=>$previous['understanding']];
            $safe['outbound']['evidence_messages'][]=['id'=>'original','text'=>$previous['safe_question']];
        }
        // A singular follow-up may refer to the sole displayed result. Only
        // its position leaves the server; ties must never become a guessed ID.
        if ($sourceQuery!==null) {
            $safe['outbound']['prior_query']['sole_result_reference']=ResultReferenceResolver::soleReference(
                $sourceQuery,(array)($sourceContext['view']??[])
            );
        }
        // A closed plural back-reference plus one registered measurement is
        // fully determined by the signed result set. Resolve it locally so a
        // model omission cannot reopen store selection on the next turn. Any
        // extra condition or unfamiliar wording still takes the model path.
        $localStoreSet=$this->localVerifiedStoreSetValuesContinuation(
            $sourceContext,$safe['outbound'],$caps,$body['output_format'],$today
        );
        if ($localStoreSet!==null && !isset($body['_clarification_context'])) {
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'verified_store_set_values_local_reuse');
            return $localStoreSet;
        }
        // A locally closed registered metric view is a bounded continuation
        // candidate, not a self-contained topic replacement. Keep this
        // structural fact beside the signed predecessor so later model
        // understanding cannot accidentally erase the only context it may
        // legitimately reuse. The check reads registry signals only and does
        // not choose any metric, object, period or ranking policy.
        $closedMetricOnlyProjection=$this->hasClosedRegisteredMetricOnlyProjection([
            'question'=>(string)$body['question'],
        ]);
        // A signed predecessor has already passed authority, metric and query
        // validation.  For the narrow case where the current sanitized turn
        // is *only* one unambiguous calendar expression, reuse that query
        // without asking the model to restate an otherwise empty business
        // intent.  This is a grammar-bound date delta, not a phrase-to-metric
        // shortcut: any object, metric, ranking, condition or unresolved
        // content leaves the normal natural-language understanding path.
        // A multi-answer collection intentionally has no single sourceQuery;
        // its verified peer queries still allow a shared date/rank edit.
        $localPeriodUnderstanding=$sourceQuery===null && $sourceCollection===[]?null
            :$this->localVerifiedPeriodOnlyUnderstanding($safe['outbound'],$safe,$today);
        // Row-count/direction follow-ups have the same stability property as
        // calendar-only edits: when the shared structural parser proves that
        // the complete current turn contains only a ranking presentation
        // change, the signed predecessor already owns the metric, object,
        // period and authority. Keep this separate from natural-language
        // business understanding so provider variation cannot reject a plain
        // "top three" continuation or change any executable business field.
        $localRankingUnderstanding=$sourceQuery===null && $sourceCollection===[]?null
            :$this->localVerifiedRankingOnlyUnderstanding($safe['outbound'],$safe,$sourceQuery,$sourceCollection);
        $localOrdinalDetailUnderstanding=$sourceContext===null?null
            :$this->localVerifiedOrdinalDetailUnderstanding($safe['outbound'],$safe,$sourceContext);
        $localMetricUnderstanding=$sourceQuery===null || $sourceCollection!==[]?null
            :$this->localVerifiedMetricOnlyUnderstanding($safe['outbound'],$safe,$sourceQuery,$closedMetricOnlyProjection,$caps);
        $localConditionUnderstanding=$sourceQuery===null?null:$this->localVerifiedConditionDeltaUnderstanding($safe['outbound'],$safe,$today);
        // The existing closed count grammar owns the complete current period,
        // measurement and predicate. An unrestricted predecessor contributes
        // no implicit store/cohort filter, so it cannot force this independent
        // question through another model call. Restricted scopes and paused
        // clarification replies still require the normal relation decision.
        $localCountUnderstanding=($sourceContext===null || ($independent && $sourceQuery!==null))
            ?$this->localClosedMemberMoneyCountUnderstanding($safe['outbound'],$safe,$today,$caps):null;
        // A reply belongs to a paused semantic request, not merely the last
        // verified result. Narrow fast paths cannot ignore its preserved fields.
        if (isset($body['_clarification_context'])) {
            $localPeriodUnderstanding=$localRankingUnderstanding=$localOrdinalDetailUnderstanding=null;
            $localConditionUnderstanding=$localCountUnderstanding=$localMetricUnderstanding=null;
            $localOrdinalDetailUnderstanding=$this->confirmedOrdinalGroupUnderstanding($body,$safe['outbound']);
        }
        // Understanding has no metric catalogue. Binding receives accepted
        // meaning afterwards and may only propose registered execution fields.
        if ($localPeriodUnderstanding!==null || $localRankingUnderstanding!==null
            || $localOrdinalDetailUnderstanding!==null
            || $localConditionUnderstanding!==null || $localCountUnderstanding!==null || $localMetricUnderstanding!==null) {
            // Keep this observable without inventing a model attempt: the
            // date-only grammar gate is a server-owned context optimization.
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,
                $localPeriodUnderstanding!==null?'context_period_only_local_reuse':($localRankingUnderstanding!==null
                    ?'context_ranking_only_local_reuse':($localOrdinalDetailUnderstanding!==null
                        ?'context_ordinal_detail_local_reuse':($localConditionUnderstanding!==null
                        ?'context_condition_delta_local_reuse':($localMetricUnderstanding!==null
                            ?'context_metric_only_local_reuse':'registered_member_count_local_understanding')))));
            $understanding=$localPeriodUnderstanding??$localRankingUnderstanding??$localOrdinalDetailUnderstanding
                ??$localConditionUnderstanding??$localCountUnderstanding??$localMetricUnderstanding;
        } else {
            $this->runs->reserve($owner,$id,$generation,$worker,'stage_count');
            $this->runs->reserve($owner,$id,$generation,$worker,'input_tokens',$this->inputTokenReservation([$safe['outbound'],$runtimeSkills,$objectVocabulary,$measurementVocabulary],1024));
            $this->runs->reserve($owner,$id,$generation,$worker,'output_tokens',1200);
            $this->runs->prepareAttempt($owner,$id,$generation,$worker,'understand_meaning','model',hash('sha256',json_encode([$safe['outbound'],$runtimeSkills,$objectVocabulary,$measurementVocabulary])),'siliconflow');
            $this->runs->sendAttempt($owner,$id,$generation,$worker,'understand_meaning');
            try {
            $checkpoint();
            $modelTimeout=$this->model===null ? $this->modelCallTimeout($owner,$id,$generation,$worker,self::MODEL_STAGE_LIMIT_MS) : null;
            $meaningReply=$this->model
                ? call_user_func($this->model,$safe['outbound'],[],$configuration,$checkpoint,null,'understanding')
                : (new SiliconFlowClient($configuration['provider']))->understandMeaning($safe['outbound'],$configuration['model'],$configuration['api_key'],$modelTimeout,$checkpoint,$runtimeSkills,null,$objectVocabulary,$measurementVocabulary);
            $understanding=\app\services\ai\contract\AiIntentUnderstandingContract::normalize($meaningReply['understanding']??null,$safe['outbound']);
            $this->runs->finishAttempt($owner,$id,$generation,$worker,'understand_meaning','SUCCEEDED',$meaningReply['usage']['input_tokens']??null,$meaningReply['usage']['output_tokens']??null);
            } catch (\Throwable $error) {
            if ($error instanceof AiContractException) $this->recordModelDiagnostic($owner,$id,$generation,$worker,$error,'understand_meaning');
            $diagnostic=$error instanceof AiContractException?$error->diagnostic():[];
            $repairPredicate=$diagnostic['predicate']??null;
            // A timeout supplies no accepted meaning. Preserve UNKNOWN and
            // unknown token usage even if a separately reserved read-only
            // recovery later succeeds; it must never masquerade as no cost.
            $firstState=in_array($error->getMessage(),
                ['AI_MODEL_RESULT_UNKNOWN','AI_CANCELLED','AI_AUTHORIZATION_CHANGED'],true)?'UNKNOWN':'FAILED';
            $this->runs->finishAttempt($owner,$id,$generation,$worker,'understand_meaning',$firstState);
            if ($repairPredicate==='binding_requirement_unavailable') {
                throw new RuntimeException('AI_CAPABILITY_NOT_READY');
            }
            // Only the platform's first language stage may recover the
            // observed no-response timeout. No credentials, provider switch,
            // binding replay or inferred business condition is involved.
            // Cancellation, authority drift and other transport failures
            // remain terminal rather than being treated as missing meaning.
            $transportRecovery=($owner['terminal']??null)==='platform'
                && $error instanceof AiContractException && $error->getMessage()==='AI_MODEL_RESULT_UNKNOWN'
                && ($diagnostic['stage']??null)==='transport' && $repairPredicate==='timeout'
                && ($diagnostic['transport_errno']??null)===28 && ($diagnostic['http_status']??null)===0;
            if (!$transportRecovery && (!($error instanceof AiContractException) || $error->getMessage()!=='AI_MODEL_INTENT_CONTRACT_INVALID'
                || !\app\services\ai\contract\AiIntentUnderstandingContract::repairable($repairPredicate))) throw $error;
            $recoveryAttempt=$transportRecovery?'understand_transport_retry':'understand_repair';
            // A transport retry changes no semantic instruction. Its accepted
            // result still passes the same understanding and binding checks.
            if ($transportRecovery) $repairPredicate=null;
            // There is only one recovery reservation for the whole Run.  If
            // this correction is used here, a later binding defect is reported
            // honestly instead of issuing an unbounded chain of model calls.
            try {
                $this->runs->reserve($owner,$id,$generation,$worker,'model_recovery_count');
            } catch (\RuntimeException $reservationError) {
                // A previous clarification may have consumed the shared
                // recovery. Keep the actual outage as the terminal reason.
                if ($transportRecovery && $reservationError->getMessage()==='AI_COUNTER_EXHAUSTED') throw $error;
                throw $reservationError;
            }
            $this->runs->reserve($owner,$id,$generation,$worker,'input_tokens',$this->inputTokenReservation([$safe['outbound'],$runtimeSkills,$objectVocabulary,$measurementVocabulary,$repairPredicate],1536));
            $this->runs->reserve($owner,$id,$generation,$worker,'output_tokens',1200);
            // Identical transport recovery has the original request hash;
            // only a protocol correction adds its new repair instruction.
            $recoveryPayload=[$safe['outbound'],$runtimeSkills,$objectVocabulary,$measurementVocabulary];
            if (!$transportRecovery) $recoveryPayload[]=$repairPredicate;
            $this->runs->prepareAttempt($owner,$id,$generation,$worker,$recoveryAttempt,'model',hash('sha256',json_encode($recoveryPayload)),'siliconflow');
            $this->runs->sendAttempt($owner,$id,$generation,$worker,$recoveryAttempt);
            try {
                $checkpoint();
                $modelTimeout=$this->model===null ? $this->modelCallTimeout($owner,$id,$generation,$worker,self::MODEL_STAGE_LIMIT_MS) : null;
                $meaningReply=$this->model
                    ? call_user_func($this->model,$safe['outbound'],[],$configuration,$checkpoint,$repairPredicate,'understanding')
                    : (new SiliconFlowClient($configuration['provider']))->understandMeaning($safe['outbound'],$configuration['model'],$configuration['api_key'],$modelTimeout,$checkpoint,$runtimeSkills,$repairPredicate,$objectVocabulary,$measurementVocabulary);
                $understanding=\app\services\ai\contract\AiIntentUnderstandingContract::normalize($meaningReply['understanding']??null,$safe['outbound']);
                $this->runs->finishAttempt($owner,$id,$generation,$worker,$recoveryAttempt,'SUCCEEDED',$meaningReply['usage']['input_tokens']??null,$meaningReply['usage']['output_tokens']??null);
            } catch (\Throwable $repairError) {
                if ($repairError instanceof AiContractException) $this->recordModelDiagnostic($owner,$id,$generation,$worker,$repairError,$recoveryAttempt);
                $this->runs->finishAttempt($owner,$id,$generation,$worker,$recoveryAttempt,in_array($repairError->getMessage(),['AI_MODEL_RESULT_UNKNOWN','AI_CANCELLED','AI_AUTHORIZATION_CHANGED'],true)?'UNKNOWN':'FAILED');
                // Some providers can repeat the same unsafe reduction even
                // after being told that a complete dated business question
                // is not a pure calendar continuation. After both bounded
                // understanding attempts fail with that exact structural
                // predicate, preserve the complete current message as an
                // open observation requirement. The registry still owns the
                // available overview, and normal binding/review must succeed;
                // this fallback neither copies the prior query nor chooses a
                // metric, object identity, result or customer-specific rule.
                $repairDiagnostic=$repairError instanceof AiContractException?$repairError->diagnostic():[];
                $understanding=!$transportRecovery && ($repairDiagnostic['predicate']??null)==='period_only_business_residue'
                    ?$this->registeredOpenOverviewUnderstanding($safe['outbound'],$today,$caps):null;
                if ($understanding!==null) {
                    $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'registered_open_overview_understanding_recovered');
                    // Continue through the ordinary binding and semantic
                    // review path below; the failed provider attempt remains
                    // recorded honestly in Run diagnostics.
                } else {
                throw $repairError;
                }
            }
        }
        }
        // An empty accepted understanding explicitly means no query meaning
        // was found. Do not let registry reconciliation or a second binding
        // model invent business conditions from those words. Stop before
        // binding, retaining the original question for plain-language help.
        if (($understanding['status']??null)==='needs_clarification'
            && ($understanding['requirements']??[])===[]) {
            // Even an out-of-scope new topic can have a verified relation;
            // record that relation before ending this turn without a query.
            if (($sourceContext!==null || isset($body['_clarification_context']))
                && ($understanding['topic_relation']??null)==='new_topic') {
                $this->runs->markTopicReset($owner,$id,$generation,$worker);
            }
            throw new RuntimeException('AI_INTENT_UNRESOLVED');
        }
        if ($sourceContext===null && $this->unanchoredCalendarQuestion((string)$body['question'],$understanding)) {
            // A bare period after a topic reset names no business measure.
            // The model may suggest a default metric, but there is no customer
            // authority to turn “上周呢” into an actual-performance query.
            throw new RuntimeException('AI_INTENT_UNRESOLVED');
        }
        $understanding=$this->mergeClarificationMeaning($understanding,$body,$safe['outbound']);
        $understanding=$this->resolveExactStatedPeriods($understanding,$safe['outbound'],$today);
        // One relation decision gates every later merger and result-reference
        // path. Contextual business questions never infer this boundary from
        // a closed grammar alone.
        $newTopic=($sourceContext!==null || isset($body['_clarification_context']))
            && ($understanding['topic_relation']??null)==='new_topic';
        if ($newTopic) {
            $this->runs->markTopicReset($owner,$id,$generation,$worker);
            $sourceContext=null;$sourceCollection=[];$sourceQuery=null;
            $safe['outbound']['prior_query']=null;
            $closedMetricOnlyProjection=false;
            // A paused new topic must not carry the discarded signed query
            // into its later supplement merely because the browser sent it.
            unset($body['context_ref']);$body['history']=[];
        }
        // Resolve missing board ownership before any local detail or model
        // binding can turn a guessed top/bottom into an executable identity.
        $ordinalGuidance=$this->ordinalGroupClarification($understanding,$safe,$body,$sourceContext,$caps,$today);
        if ($ordinalGuidance!==null) return $ordinalGuidance;
        // A missing selected-member metric is a specific question. Keep the
        // member reference and time in the private reply state; never relabel
        // a broad word as the sole available metric merely to execute.
        $memberMetricGuidance=$this->selectedMemberMetricClarification($understanding,$safe,$body,$memberMetrics);
        if ($memberMetricGuidance!==null) return $memberMetricGuidance;
        if (($context['terminal']??null)==='platform'
            && $this->needsStoreGoalGuidance($understanding,$sourceQuery,$caps)) {
            return $this->verifiedSemanticClarification($owner,$id,$generation,$worker,$understanding,$safe,$body,$caps,$today,$configuration,$checkpoint,$sourceQuery,
                \app\services\ai\execution\AiAuthority::currentStoreId($freshContext));
        }
        // A provider's advisory status can contradict its complete typed
        // condition. Only a current, fully covered registered plan may cross
        // this gate; preserve the original status for audit and guidance.
        $completeTypedCondition=$this->completeTypedConditionProof(
            $understanding,$safe,$body,$projection,$caps,$summaries,$today,$sourceContext
        );
        if ($completeTypedCondition!==null) {
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'complete_typed_condition_compiled');
        }
        if (($understanding['status']??null)==='needs_clarification' && $completeTypedCondition===null) {
            // Incomplete meaning is a waiting task, not a failed execution.
            // Topic reset and clear calendar conditions are settled first;
            // no extra binding call is spent on genuinely missing meaning.
            return $this->verifiedSemanticClarification($owner,$id,$generation,$worker,$understanding,$safe,$body,$caps,$today,$configuration,$checkpoint,$sourceQuery,
                \app\services\ai\execution\AiAuthority::currentStoreId($freshContext));
        }
        $simpleSupplement=$this->compileAcceptedSimpleSupplement($understanding,$body,$caps,$today,$sourceQuery,
            \app\services\ai\execution\AiAuthority::currentStoreId($freshContext));
        if ($simpleSupplement!==null) {
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'verified_supplement_local_binding');
            return $simpleSupplement;
        }
        // One current opaque store followed by its immediate deictic is one
        // location, not two filters. Keep distinct or historical locations
        // untouched so no customer condition can disappear silently.
        $understanding=\app\services\ai\contract\AiIntentUnderstandingContract::reconcileCurrentStoreRequirements(
            $understanding,$safe['outbound'],$privateKindsByReference
        );
        $understanding=\app\services\ai\contract\AiIntentUnderstandingContract::reconcileSoleStoreReference(
            $understanding,$safe['outbound'],$privateKindsByReference
        );
        // Object detail is not another ranking or metric-binding request. The
        // model owns the natural-language meaning while the server resolves a
        // stable person, store or member only from the replayed verified view.
        // Admitting this before binding also removes an unnecessary model call.
        // Keep only typed, non-sensitive shape metadata so a refused follow-up
        // can be diagnosed without retaining model prose, names or identities.
        foreach ((array)($understanding['requirements']??[]) as $requirement) {
            $detail=$requirement['values']['object_detail']??null;
            if (!is_array($detail)) continue;
            $shape=$sourceQuery['query_shape']??'missing';
            if (!is_string($shape) || !preg_match('/^[a-z_]{1,32}$/D',$shape)) $shape='other';
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,
                'object_detail_'.$detail['target'].'_'.$detail['view'].'_from_'.$shape,[],'object_detail_probe');
        }
        // A count view intentionally has no identities. When the accepted
        // continuation asks to inspect that population, reuse its signed
        // predicates to obtain a bounded list before any object selection.
        // This is the same typed condition merger used by amount/date/form
        // edits, not a phrase-specific detail shortcut.
        $conditionDetailIntent=null;
        if (($sourceQuery['query_shape']??null)==='condition_count') {
            foreach ((array)($understanding['requirements']??[]) as $requirement) {
                if (!in_array('object_detail',(array)($requirement['fields']??[]),true)) continue;
                $conditionDetailIntent=AiIntentResultContract::inheritedConditionContinuationContextIntent(
                    $understanding,$sourceQuery,(array)($sourceContext['meaning']??[])
                );
                break;
            }
        }
        $objectDetailRejection=null;
        $objectDetail=$conditionDetailIntent===null
            ?$this->compileObjectDetailContinuation($understanding,$sourceContext,$body['output_format'],$objectDetailRejection):null;
        if ($objectDetail!==null) {
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'verified_object_detail_continuation_admitted');
            return $objectDetail;
        }
        if ($objectDetailRejection!==null) {
            // The rejected carrier is a fixed contract field name. Keep the
            // reason per Run without retaining model text or prior result data.
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,
                'object_detail_reject_extra_field',$objectDetailRejection,'object_detail_reject_probe');
        }
        $populationPresentation=$this->compileVerifiedPopulationPresentation($understanding,$sourceContext);
        if ($populationPresentation!==null) {
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'verified_population_presentation_admitted');
            return $populationPresentation;
        }
        $storeSetValues=$this->compileVerifiedStoreSetValuesContinuation(
            $understanding,$sourceContext,$safe['outbound'],$caps,$body['output_format'],$today
        );
        if ($storeSetValues!==null) {
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'verified_store_set_values_continuation_admitted');
            return $storeSetValues;
        }
        // A self-contained member population request may ask for both the
        // ranked/filtered set and its details. Keep the presentation request
        // beside the plan while binding only the population query; otherwise
        // the binding model must account for a non-metric field and can drop
        // it during format repair. The typed understanding, rather than a
        // phrase matcher, proves the object, response form and set target.
        if ($conditionDetailIntent===null) {
            [$understanding,$requestedMemberDetail]=$this->separatePopulationDetail($understanding);
        } else {
            // The inherited count has no identities. Its typed continuation
            // compiles a fresh authorised list, but the requested detail must
            // survive beside that plan or the user sees only the filter list.
            $requestedMemberDetail=$this->inheritedCountDetailRequest($understanding);
        }
        // Some providers omit the new request-kind marker only when a signed
        // predecessor is present. Recover it solely when two independent
        // structural checks agree: the model accepted only a broad observation
        // plus period/scope, and the local parser proves the complete current
        // turn is one calendar-bound ambiguous observation with no condition.
        // This prevents a prior single metric from forcing an otherwise clear
        // new overview back through binding and review, without matching a
        // customer phrase or selecting any metric in PHP.
        if ($sourceQuery!==null && !isset($understanding['request_kind'])
            && $this->allowsOpenOverviewRecovery($understanding,$caps)) {
            $typedOverview=$this->registeredOpenOverviewUnderstanding($safe['outbound'],$today,$caps);
            if ($typedOverview!==null) {
                $understanding=$typedOverview;
                $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'registered_open_overview_type_recovered');
            }
        }
        // The answer-row object is resolved before the measurement. This is
        // essential when a measurement title itself contains an object noun:
        // that noun describes what is counted, not necessarily which rows the
        // customer wants back. Candidate metrics are then restricted by the
        // source-owned object contract rather than by a sentence-specific rule.
        $understanding=$this->reconcileExactRegisteredAnalyticalObject(
            $understanding,$safe['outbound'],$objectVocabulary
        );
        $understanding=\app\services\ai\contract\AiIntentUnderstandingContract::reconcileSelectedPositionAsPersonnel(
            $understanding,$safe['outbound'],$privateKindsByReference
        );
        $objectCompatibleSummaries=$this->bindingSummariesForUnderstanding($summaries,$understanding);
        $understanding=$this->reconcileExactRegisteredMeasurement(
            $understanding,$safe['outbound'],$objectCompatibleSummaries,$objectVocabulary
        );
        // A model-understood, fully typed store overview or two-period
        // comparison can be compiled from the registry without asking later
        // models to choose the same metric profile. The admission helper
        // rejects named metrics, filters, rankings and unsafe context.
        $registeredOverview=$this->compileRegisteredOpenOverview(
            $understanding,$safe['outbound'],$sourceQuery,$caps,$body['output_format'],$today
        );
        if ($registeredOverview!==null) {
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,
                ($understanding['request_kind']??null)==='overview_comparison'
                    ?'registered_overview_comparison_admitted':'registered_open_overview_admitted');
            return $registeredOverview;
        }
        $understanding=$this->reconcileStatedRegisteredMeasurements(
            $understanding,$safe['outbound'],$caps
        );
        // A unique role plus a person-grain registered measurement determines
        // the rankable row only when the competing noun occurs solely inside
        // that metric title; independently named objects remain untouched.
        $understanding=\app\services\ai\contract\AiIntentUnderstandingContract::reconcileRankedRoleMetricObject(
            $understanding,$safe['outbound'],$privateKindsByReference,array_keys($personMetrics),
            array_keys((array)($caps['metric_readiness']??[])),$objectVocabulary
        );
        // The exhaustive registry pass above can add the same surface title
        // once for each base-grain owner (for example store/project and
        // person). Re-run the object-scoped exact reconciliation after that
        // addition so one already resolved analytical object owns one metric
        // requirement. Independent titles, exclusions and unknown conditions
        // remain separate because the reconciler admits only one exact owner.
        $understanding=$this->reconcileExactRegisteredMeasurement(
            $understanding,$safe['outbound'],$objectCompatibleSummaries,$objectVocabulary
        );
        // Resolve “respectively” only after the exhaustive metric pass has
        // removed a provider's empty/unregistered metric echo and rebuilt the
        // exact current measurements. Running this earlier let optional group
        // metadata or a fourth generic placeholder bypass the aggregate-grain
        // decision and inherit the preceding breakdown on a real model run.
        $understanding=$this->reconcileCoordinatedMeasurementDistribution(
            $understanding,$safe['outbound'],$objectVocabulary,array_keys((array)($caps['metric_readiness']??[]))
        );
        // The model may explain a short metric view change by copying the
        // predecessor's ranking/object fields into the *current* semantic
        // requirement. That makes the binding contract compare an inherited
        // value as though the customer stated it again and can terminate an
        // otherwise valid follow-up before the context merger runs. Only the
        // independent registry projection may close this boundary: when the
        // complete current turn is proven to contain one metric signal and no
        // other executable meaning, retain the model-authored metric meaning
        // and remove its unsupported copied fields. The signed predecessor,
        // not this helper, remains the authority for date, object and ranking.
        $understanding=$this->reconcileClosedMetricOnlyUnderstanding(
            $understanding,$closedMetricOnlyProjection,$sourceQuery
        );
        if ($sourceCollection!==[] || $closedMetricOnlyProjection) {
            try {$this->runs->recordDiagnostic($owner,$id,$generation,$worker,[
                'stage'=>'analysis_binding','predicate'=>'collection_context_probe',
                'selected_metric_count'=>min(64,count($sourceCollection)),
                'initial_observation'=>$closedMetricOnlyProjection,
            ],'collection_context_probe');} catch (\Throwable $ignored) {}
        }
        if ($sourceCollection!==[] && $closedMetricOnlyProjection) {
            $collectionMetricPlan=$this->compileCollectionMetricContinuation(
                $understanding,$sourceCollection,$body['output_format'],$safe['outbound'],$caps,
                (array)($sourceContext['meaning']??[]),$owner,$id,$generation,$worker
            );
            if ($collectionMetricPlan!==null) return $collectionMetricPlan;
        }
        foreach (\app\services\query\metric\MetricSemanticCatalog::registeredTermsInText(
            (string)($safe['outbound']['question']??''),array_keys((array)($caps['metric_readiness']??[]))
        ) as $statedMeasurement) {
            if (($statedMeasurement['ai_query_ready']??true)!==true) {
                // This is a source-registered capability fact, not a phrase
                // fallback. Stop before binding so a mixed request can never
                // execute only its readable half or turn an unavailable
                // metric into a model-format failure.
                throw new RuntimeException('AI_CAPABILITY_NOT_READY');
            }
        }
        // The local closed-calendar path remains the fastest proof. A model
        // may also understand a compositional week/quarter ellipsis that the
        // local date interpreter intentionally does not parse. Reuse signed
        // context only after the understanding contract accepted one period,
        // its evidence covers the whole current turn, and the independent
        // structural guard finds no second business request. A bare typed
        // period without this proof must still pass ordinary binding.
        $closedPeriodOnlyContinuation=$localPeriodUnderstanding!==null;
        if (!$closedPeriodOnlyContinuation && !isset($body['_clarification_context'])
            && ($sourceQuery!==null || $sourceCollection!==[])) {
            $periodRequirements=array_values(AiIntentUnderstandingContract::requirements($understanding));
            if (count($periodRequirements)===1 && ($periodRequirements[0]['fields']??null)===['periods']) {
                $periodRequirement=$periodRequirements[0];
                $wholeTurn=(string)($safe['outbound']['question']??'');
                $fullEvidence=false;
                foreach ((array)($periodRequirement['evidence']??[]) as $evidence) {
                    if (($evidence['message_id']??null)==='current' && ($evidence['start']??null)===0
                        && ($evidence['quote']??null)===$wholeTurn) {$fullEvidence=true;break;}
                }
                $closedPeriodOnlyContinuation=$fullEvidence
                    &&\app\services\ai\semantic\AiSemanticIntentParser::modelOnlyCalendarContinuation(
                        $wholeTurn,(array)($periodRequirement['values']['periods']??[]));
            }
        }
        $collectionPeriodIntent=$sourceCollection===[]||!$closedPeriodOnlyContinuation?null
            :AiIntentResultContract::inheritedPeriodOnlyContextIntent(
                $understanding,$sourceCollection[0]['query'],(array)($sourceContext['meaning']??[])
            );
        if ($collectionPeriodIntent!==null) {
            return $this->compileCollectionPeriodContinuation(
                $understanding,$sourceCollection,(array)($sourceContext['meaning']??[]),$body['output_format'],$today
            );
        }
        $collectionRankingIntent=$sourceCollection===[]||$localRankingUnderstanding===null?null
            :AiIntentResultContract::inheritedRankingOnlyContextIntent(
                $understanding,$sourceCollection[0]['query'],(array)($sourceContext['meaning']??[])
            );
        if ($collectionRankingIntent!==null) {
            return $this->compileCollectionRankingContinuation(
                $understanding,$sourceCollection,(array)($sourceContext['meaning']??[]),$body['output_format']
            );
        }
        // Keep the verified query available to IntentContextMerger. The
        // model's typed context_delta replaces or clears only fields changed
        // by this turn, so a new analytical object can retain an applicable
        // period without carrying incompatible object filters.
        // Understanding is the only natural-language authority here. Once it
        // has independently established an analytical object, the binding
        // model should not be distracted by metrics that the registry says
        // cannot be read for that object. This narrows a capability catalogue,
        // not the customer's meaning: an unknown or unsupported object keeps
        // the complete catalogue so the normal capability boundary can report
        // the gap without silently changing the subject.
        $bindingSummaries=$this->bindingSummariesForUnderstanding($summaries,$understanding);
        // query_shapes is server-only admission metadata used by the filter
        // above. Keep the long-standing model-facing capability schema stable.
        foreach ($bindingSummaries as &$bindingSummary) unset($bindingSummary['query_shapes']);
        unset($bindingSummary);
        // Both answer forms were already accepted by the language stage.
        // Exact current-turn registry owners can bind this narrow collection
        // without another model round; the common child compilers still
        // validate every capability before either result is published.
        $exactStoreItems=$sourceContext===null && ($body['output_format']??null)==='screen'
            ?\app\services\ai\contract\AiIntentGroupContract::exactStoreViewCollection(
                $understanding,$safe['outbound'],array_column($bindingSummaries,'metric_code')
            ):null;
        if ($exactStoreItems!==null) {
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'registered_store_collection_binding_compiled');
            return $this->compileRegisteredStoreCollection(
                $exactStoreItems,$understanding,$projection,$caps,$body,$today,
                $safe['outbound'],$sourceContext,$checkpoint
            );
        }
        $reusedConditionIntent=$conditionDetailIntent??($sourceQuery===null?null:
            AiIntentResultContract::inheritedConditionContinuationContextIntent(
                $understanding,$sourceQuery,(array)($sourceContext['meaning']??[])
            ));
        $conditionUpdateBindingReused=$reusedConditionIntent!==null;
        // The same complete-turn proof guards the single-query shortcut.
        // A current metric, object, condition or broader goal must not inherit
        // the predecessor merely because its wording also contains a date.
        $reusedPeriodIntent=$sourceQuery===null||!$closedPeriodOnlyContinuation?null:AiIntentResultContract::inheritedPeriodOnlyContextIntent(
            $understanding,$sourceQuery,(array)($sourceContext['meaning']??[])
        );
        $reusedRankingIntent=$sourceQuery===null||$localRankingUnderstanding===null?null:AiIntentResultContract::inheritedRankingOnlyContextIntent(
            $understanding,$sourceQuery,(array)($sourceContext['meaning']??[])
        );
        $contextBindingReused=$reusedConditionIntent!==null
            ||$reusedPeriodIntent!==null
            ||$reusedRankingIntent!==null;
        $registeredConditionFailure=null;
        $registeredConditionContractFailure=null;
        $registeredConditionIntent=$completeTypedCondition['intent']??$this->registeredConditionIntent(
            $understanding,$summaries,$safe['outbound'],$registeredConditionFailure,$registeredConditionContractFailure
        );
        $registeredCoordinatedFailure=null;
        $registeredCoordinatedIntent=AiIntentResultContract::exactCoordinatedIntent(
            $understanding,$safe['outbound'],array_column($bindingSummaries,'metric_code'),$sourceQuery!==null,
            $registeredCoordinatedFailure
        );
        $registeredSingleRankingIntent=AiIntentResultContract::exactSingleRankingIntent(
            $understanding,$safe['outbound'],array_column($bindingSummaries,'metric_code')
        );
        $registeredBreakdownContinuationFailure=null;
        $registeredBreakdownContinuationIntent=$sourceQuery===null?null:
            AiIntentResultContract::registeredBreakdownContinuationIntent(
                $understanding,$safe['outbound'],$bindingSummaries,$sourceQuery,
                $registeredBreakdownContinuationFailure
            );
        if ($registeredBreakdownContinuationIntent===null && $registeredBreakdownContinuationFailure!==null) {
            try {$this->runs->recordDiagnostic($owner,$id,$generation,$worker,[
                'stage'=>'analysis_binding','predicate'=>'registered_breakdown_continuation_miss_'.$registeredBreakdownContinuationFailure,
            ],'registered_breakdown_continuation_probe');} catch (\Throwable $ignored) {}
        }
        if ($registeredCoordinatedIntent===null && $registeredCoordinatedFailure!==null) {
            try {$this->runs->recordDiagnostic($owner,$id,$generation,$worker,[
                'stage'=>'analysis_binding','predicate'=>'registered_coordinated_miss_'.$registeredCoordinatedFailure,
            ],'registered_coordinated_probe');} catch (\Throwable $ignored) {}
        }
        if ($registeredConditionIntent===null && is_array($registeredConditionFailure)) {
            // Observability is best effort and must never turn a valid
            // customer question into a technical failure.
            try {
                $this->runs->recordDiagnostic($owner,$id,$generation,$worker,$registeredConditionFailure,'registered_condition_compile');
            } catch (\Throwable $ignored) {}
        }
        if (is_array($registeredConditionContractFailure)) {
            try {
                $this->runs->recordDiagnostic($owner,$id,$generation,$worker,
                    $registeredConditionContractFailure,'registered_condition_contract');
            } catch (\Throwable $ignored) {}
        }
        // These collection-only carriers are read after every binding path,
        // including the deterministic period/condition reuse shortcuts. Keep
        // their neutral state outside the model-only branch so a short context
        // continuation cannot hit an undefined-variable RuntimeException.
        $bindingRankRecovery=false;$groupedItems=null;$groupedUnderstanding=null;
        if ($reusedConditionIntent!==null) {
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'context_condition_continuation_binding_reused');
            $reply=['intent'=>$reusedConditionIntent,'usage'=>[]];
        } elseif ($reusedPeriodIntent!==null) {
            // There is no new metric, object, range, ranking, scope or
            // exclusion to bind.  Keep the path visible in payload-free Run
            // diagnostics; it is a protocol optimization, never a business
            // classification or a hidden fallback.
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'context_period_binding_reused');
            $reply=['intent'=>$reusedPeriodIntent,'usage'=>[]];
        } elseif ($reusedRankingIntent!==null) {
            // The accepted local grammar changes only ranking presentation.
            // Reusing the signed binding here avoids a second model decision
            // over the already verified metric, object, date and scope.
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'context_ranking_binding_reused');
            $reply=['intent'=>$reusedRankingIntent,'usage'=>[]];
        } elseif ($registeredBreakdownContinuationIntent!==null) {
            // Understanding owns the new plural analytical object; the
            // registry owns its sole broad first-answer perspective. Reusing
            // the signed date and authority here avoids a redundant binding
            // call and cannot retain the predecessor's analytical filter.
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'registered_breakdown_continuation_compiled');
            $reply=['intent'=>$registeredBreakdownContinuationIntent,'usage'=>[]];
            $contextBindingReused=true;
        } elseif ($registeredConditionIntent!==null) {
            // The understanding model already owns every customer semantic
            // choice. When all condition terms have one unique active
            // registry owner, a second model call can only repeat structural
            // bookkeeping and may omit a binding row. Compile that bounded
            // carrier locally; ambiguous or unavailable terms never enter
            // this branch and retain the normal model/clarification path.
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'registered_condition_binding_compiled');
            $reply=['intent'=>$registeredConditionIntent,'usage'=>[]];
        } elseif ($registeredCoordinatedIntent!==null) {
            // The language model has already fixed the complete semantic
            // request. Exact registry ownership now proves every metric and
            // audit row, so another model round cannot add business meaning.
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'registered_coordinated_binding_compiled');
            $reply=['intent'=>$registeredCoordinatedIntent,'usage'=>[]];
        } elseif ($registeredSingleRankingIntent!==null) {
            // Only the model-accepted rank/object/period and one exact active
            // registry metric enter this fast path. The common compiler still
            // resolves private store/role filters and checks data authority.
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'registered_single_ranking_binding_compiled');
            $reply=['intent'=>$registeredSingleRankingIntent,'usage'=>[]];
        } else {
        // Keep a payload-free structural trace when a verified condition-set
        // continuation cannot use either bounded inheritance shortcut.  This
        // records only contract field names from the current understanding;
        // it never stores the customer wording, metric values or result data.
        // The per-attempt slot survives a later binding diagnostic and makes
        // provider shape drift observable without broad log collection.
        if ($sourceQuery!==null && in_array($sourceQuery['query_shape']??null,['condition_count','condition_list'],true)) {
            $requirements=\app\services\ai\contract\AiIntentUnderstandingContract::requirements($understanding);
            $fieldSet=[];
            foreach ($requirements as $requirement) foreach ((array)($requirement['fields']??[]) as $field) {
                if (is_string($field) && preg_match('/^[a-z_]{1,32}$/D',$field)) $fieldSet[$field]=true;
            }
            $fields=array_keys($fieldSet);sort($fields,SORT_STRING);
            $predicate='condition_reuse_miss'.($fields===[]?'_no_fields':'_fields_'.implode('_',$fields));
            if (strlen($predicate)>96) $predicate='condition_reuse_miss_fields_multiple';
            try {
                $this->runs->recordDiagnostic($owner,$id,$generation,$worker,[
                    'stage'=>'analysis_binding','predicate'=>$predicate,
                    'metric_requirement_count'=>min(12,count($requirements)),
                ],'condition_update_probe');
            } catch (\Throwable $ignored) {}
        }
        $this->runs->reserve($owner,$id,$generation,$worker,'stage_count');
        $this->runs->reserve($owner,$id,$generation,$worker,'input_tokens',$this->inputTokenReservation([$safe['outbound'],$understanding,$bindingSummaries,$runtimeSkills],2048));
        $bindingGroupCount=count(\app\services\ai\contract\AiIntentUnderstandingContract::queryGroups($understanding));
        $this->runs->reserve($owner,$id,$generation,$worker,'output_tokens',$bindingGroupCount>1 ? 1800 : 1200);
        $this->runs->prepareAttempt($owner,$id,$generation,$worker,'bind_intent','model',hash('sha256',json_encode([$safe['outbound'],$understanding,$bindingSummaries])),'siliconflow');
        $this->runs->sendAttempt($owner,$id,$generation,$worker,'bind_intent');
        try {
            $checkpoint();
            // A transport result that times out is not provably absent at the
            // provider. Give this one binding request the normal bounded
            // model window rather than sending the same request a second time.
            $modelTimeout=$this->model===null ? $this->modelCallTimeout($owner,$id,$generation,$worker,self::MODEL_STAGE_LIMIT_MS) : null;
            $reply=$this->model
                ? call_user_func($this->model,$safe['outbound'],$bindingSummaries,$configuration,$checkpoint,null,'binding',$understanding)
                : (new SiliconFlowClient($configuration['provider']))->understand($safe['outbound'],$bindingSummaries,$understanding,$configuration['model'],$configuration['api_key'],$modelTimeout,$checkpoint,$runtimeSkills);
            // External and injected model adapters must have identical
            // recovery semantics. A candidate response from the provider is
            // already marked below; an injected adapter can still return the
            // raw candidate, so classify it with the same shared predicate.
            if (!array_key_exists('rank_metric_candidates',$reply) && !array_key_exists('items',$reply)) {
                try {
                    $reply['intent']=$this->semanticIntent($reply['intent']??null,$bindingSummaries,$safe['outbound'],$understanding);
                } catch (AiContractException $bindingError) {
                    $codes=[];foreach($bindingSummaries as $summary) if(is_string($summary['metric_code']??null)) $codes[]=$summary['metric_code'];
                    $candidates=SiliconFlowClient::rankMetricCandidates($reply['intent']??null,$codes);
                    if ($candidates===null) throw $bindingError;
                    $reply['rank_metric_candidates']=$candidates;
                }
            }
            if (array_key_exists('items',$reply)) {
                // Independent groups are a self-contained current request.
                // They must replace a completed single-result topic rather
                // than inherit its object, metric or ranking. A pure-date
                // continuation remains one group and therefore still follows
                // the verified-context path below.
                $sourceQuery=null;
                $groupedItems=\app\services\ai\contract\AiIntentGroupContract::normalize(
                    ['items'=>$reply['items']],array_column($bindingSummaries,'metric_code'),[],$safe['outbound'],$understanding
                );
                if (!\app\services\ai\contract\AiIntentGroupContract::isExecutableRankingCollection($groupedItems)
                    && !\app\services\ai\contract\AiIntentGroupContract::isExecutableStoreCollection($groupedItems)) {
                    throw new AiContractException('AI_MODEL_INTENT_CONTRACT_INVALID',[
                        'stage'=>'intent_group_contract','predicate'=>'collection_plan_shape',
                    ]);
                }
                $groupedUnderstanding=$understanding;
                $first=$groupedItems[0]??null;
                if (!is_array($first)||!is_array($first['intent']??null)||!is_array($first['requirement_ids']??null)) {
                    throw new RuntimeException('AI_MODEL_INPUT_INVALID');
                }
                $understanding=\app\services\ai\contract\AiIntentGroupContract::subset($understanding,$first['requirement_ids']);
                $reply['intent']=$first['intent'];
                $this->runs->finishAttempt($owner,$id,$generation,$worker,'bind_intent','SUCCEEDED',$reply['usage']['input_tokens']??null,$reply['usage']['output_tokens']??null);
            } elseif (array_key_exists('rank_metric_candidates',$reply)) {
                // The initial binding did not form an executable ranking, but
                // it did return registered candidates. Record that response
                // honestly, then let one named recovery ask the model to
                // choose its professional first reading.
                $bindingRankRecovery=true;
                $this->runs->finishAttempt($owner,$id,$generation,$worker,'bind_intent','FAILED',$reply['usage']['input_tokens']??null,$reply['usage']['output_tokens']??null);
                $reply=$this->resolveRankMetricBinding($owner,$id,$generation,$worker,$safe['outbound'],$bindingSummaries,$understanding,$configuration,$checkpoint,$reply);
            } else {
                $this->runs->finishAttempt($owner,$id,$generation,$worker,'bind_intent','SUCCEEDED',$reply['usage']['input_tokens']??null,$reply['usage']['output_tokens']??null);
            }
        } catch (\Throwable $error) {
            // The rank-recovery helper owns and finalizes its independently
            // recorded attempt. The original bind attempt is already terminal.
            if ($bindingRankRecovery) throw $error;
            if ($error instanceof AiContractException) $this->recordModelDiagnostic($owner,$id,$generation,$worker,$error,'bind_intent');
            $firstState=in_array($error->getMessage(),['AI_MODEL_RESULT_UNKNOWN','AI_CANCELLED','AI_AUTHORIZATION_CHANGED'],true)?'UNKNOWN':'FAILED';
            $this->runs->finishAttempt($owner,$id,$generation,$worker,'bind_intent',$firstState);
            $diagnostic=$error instanceof AiContractException?$error->diagnostic():[];
            $repairPredicate=$diagnostic['predicate']??null;
            // A provider may omit a mandatory JSON field on either a fresh
            // question or a follow-up.  One fenced completion attempt is safe
            // in both cases: it asks the model to provide the field itself,
            // and never supplies a metric, condition, date or other business
            // meaning on the model's behalf.
            $groupedBindingExpected=count(AiIntentUnderstandingContract::queryGroups($understanding))>1;
            $repairable=$error instanceof AiContractException
                && in_array($error->getMessage(),['AI_MODEL_INTENT_CONTRACT_INVALID','AI_MODEL_METRIC_UNKNOWN'],true)
                && (AiIntentResultContract::repairableFormat($repairPredicate)
                    || ($groupedBindingExpected && \app\services\ai\contract\AiIntentGroupContract::repairableFormat($repairPredicate)));
            if (!$repairable) throw $error;
            // A completed response with one omitted mandatory structural field
            // is safe to correct once.  This is a separate fenced attempt,
            // not a replay of an unknown provider outcome.
            $this->runs->reserve($owner,$id,$generation,$worker,'model_recovery_count');
            $this->runs->reserve($owner,$id,$generation,$worker,'input_tokens',$this->inputTokenReservation([$safe['outbound'],$bindingSummaries,$runtimeSkills],2304));
            $this->runs->reserve($owner,$id,$generation,$worker,'output_tokens',$groupedBindingExpected ? 1800 : 1200);
            $this->runs->prepareAttempt($owner,$id,$generation,$worker,'bind_repair','model',hash('sha256',json_encode([$safe['outbound'],$understanding,$bindingSummaries,$repairPredicate])),'siliconflow');
            $this->runs->sendAttempt($owner,$id,$generation,$worker,'bind_repair');
            try {
                $checkpoint();
                $modelTimeout=$this->model===null ? $this->modelCallTimeout($owner,$id,$generation,$worker,self::MODEL_STAGE_LIMIT_MS) : null;
                $reply=$this->model
                    ? call_user_func($this->model,$safe['outbound'],$bindingSummaries,$configuration,$checkpoint,$repairPredicate,'binding',$understanding)
                    : (new SiliconFlowClient($configuration['provider']))->understand($safe['outbound'],$bindingSummaries,$understanding,$configuration['model'],$configuration['api_key'],$modelTimeout,$checkpoint,$runtimeSkills,$repairPredicate);
                if ($groupedBindingExpected) {
                    if (!array_key_exists('items',$reply)) throw new RuntimeException('AI_MODEL_INPUT_INVALID');
                    // Same rule as the primary binding path: a repaired
                    // grouped carrier is a current self-contained topic, not
                    // a delta to the preceding single-result query.
                    $sourceQuery=null;
                    $groupedItems=\app\services\ai\contract\AiIntentGroupContract::normalize(
                        ['items'=>$reply['items']],array_column($bindingSummaries,'metric_code'),[],$safe['outbound'],$understanding
                    );
                    if (!\app\services\ai\contract\AiIntentGroupContract::isExecutableRankingCollection($groupedItems)
                        && !\app\services\ai\contract\AiIntentGroupContract::isExecutableStoreCollection($groupedItems)) {
                        throw new AiContractException('AI_MODEL_INTENT_CONTRACT_INVALID',[
                            'stage'=>'intent_group_contract','predicate'=>'collection_plan_shape',
                        ]);
                    }
                    $groupedUnderstanding=$understanding;
                    $first=$groupedItems[0]??null;
                    if (!is_array($first)||!is_array($first['intent']??null)||!is_array($first['requirement_ids']??null)) {
                        throw new RuntimeException('AI_MODEL_INPUT_INVALID');
                    }
                    $understanding=\app\services\ai\contract\AiIntentGroupContract::subset($understanding,$first['requirement_ids']);
                    $reply['intent']=$first['intent'];
                } else {
                    $reply['intent']=$this->semanticIntent($reply['intent']??null,$bindingSummaries,$safe['outbound'],$understanding);
                }
                $this->runs->finishAttempt($owner,$id,$generation,$worker,'bind_repair','SUCCEEDED',$reply['usage']['input_tokens']??null,$reply['usage']['output_tokens']??null);
            } catch (\Throwable $repairError) {
                if ($repairError instanceof AiContractException) $this->recordModelDiagnostic($owner,$id,$generation,$worker,$repairError,'bind_repair');
                $this->runs->finishAttempt($owner,$id,$generation,$worker,'bind_repair',in_array($repairError->getMessage(),['AI_MODEL_RESULT_UNKNOWN','AI_CANCELLED','AI_AUTHORIZATION_CHANGED'],true)?'UNKNOWN':'FAILED');
                if ($repairError instanceof AiContractException
                    && (($repairError->diagnostic()['predicate']??null)==='binding_requirement_unavailable')) {
                    throw new RuntimeException('AI_CAPABILITY_NOT_READY');
                }
                throw $repairError;
            }
        }
        }
        // If the understanding model quoted an exact registered metric term,
        // let the active registry boundary settle it without another model
        // guess. This is intentionally limited to a unique exact owner; an
        // unknown or ambiguous phrase still reaches controlled clarification.
        // A verified condition update names its target metric only to select
        // one signed predicate; it is not a request to replace the query's
        // complete metric set. Running the generic exact-metric rebinder here
        // would discard the untouched predicates that the bounded reuse path
        // has just proved and preserved.
        if (!$conditionUpdateBindingReused) {
            $reply['intent']=$this->applyUniqueRegisteredMetricTermBinding(
                $reply['intent'],$understanding,$bindingSummaries,$sourceQuery,$safe['outbound']
            );
        }
        // A closed metric-only continuation may pass through one or more
        // bounded model repairs below. The structural proof was established
        // next to the signed predecessor above, before the model could label
        // the requested metric as a new analytical topic.
        $reply['intent']=$this->preserveVerifiedMetricOnlyContext(
            $reply['intent'],$understanding,$sourceQuery,$closedMetricOnlyProjection
        );
        $reply['intent']=$this->replaceInheritedSystemCohortForCurrentLocalSelection(
            $reply['intent'],$sourceQuery,$safe['outbound'],$privateKindsByReference
        );
        $reply['intent']=self::normalizeAggregateStoreContextDelta(
            $reply['intent'],$understanding,$sourceQuery
        );
        $checkpoint();$intent=$reply['intent'];
        if ($groupedItems!==null
            && \app\services\ai\contract\AiIntentGroupContract::isExecutableStoreCollection($groupedItems)) {
            return $this->compileRegisteredStoreCollection(
                $groupedItems,$groupedUnderstanding,$projection,$caps,$body,$today,
                $safe['outbound'],$sourceContext,$checkpoint
            );
        }
        // The model states only a delta. This named merger is the sole place
        // that may retain verified query meaning across turns.
        try {
            $merged=IntentContextMerger::merge($sourceQuery,$intent);
        } catch (RuntimeException $error) {
            if ($error->getMessage()==='AI_CONTEXT_DELTA_CONFLICT') {
                // The Run store accepts only its bounded diagnostic schema;
                // recording customer-derived fields here would silently fail.
                $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,
                    'context_merge_conflict',[],'context_merge_probe');
            }
            throw $error;
        }
        $intent=$merged['intent'];$inheritedConstraints=$merged['constraints'];
        // Context changes are semantic, but a provider can occasionally
        // produce a self-contradictory delta: it swaps the answer form while
        // carrying an old analytical dimension that the current accepted
        // understanding never selected. Do not "fix" it in PHP. One fenced
        // binding retry asks the model to publish a coherent delta; the
        // normal contract, authority and Reader checks still decide whether
        // that new candidate may execute.
        if (AiIntentResultContract::requiresContextRebinding($sourceQuery,$understanding,$reply['intent'],$intent)) {
            $reply=$this->repairBindingCandidate($owner,$id,$generation,$worker,$safe['outbound'],$bindingSummaries,$understanding,$configuration,$checkpoint,$runtimeSkills,'context_analytical_dimension_carryover');
            $reply['intent']=self::normalizeAggregateStoreContextDelta(
                $reply['intent'],$understanding,$sourceQuery
            );
            $intent=$reply['intent'];
            $merged=IntentContextMerger::merge($sourceQuery,$intent);
            $intent=$merged['intent'];$inheritedConstraints=$merged['constraints'];
        }
        // A new analytical object after an unrestricted aggregate has no
        // prior object filter to remove. It must still use the new object's
        // registered choices, but asking the customer to confirm a removal
        // that cannot change their data adds friction without safety value.
        $objectReplacementWithoutFilterConfirmation=$sourceQuery!==null
            && ($intent['context_delta']['object']??null)==='replace'
            && !$merged['replacement_confirmation'];
        // A condition can be understood without having a registered Reader
        // representation (for example, a requested calendar restriction).
        // Do not ask an unrelated clarification and then run the old query:
        // report the actual capability boundary before any fallback exists.
        if (AiIntentResultContract::hasUnboundRequirement($understanding)) {
            $unboundCount=0;$unboundOnly=0;$unboundMetric=0;$unboundCondition=0;$unboundObject=0;$unboundPeriod=0;
            foreach (AiIntentUnderstandingContract::requirements($understanding) as $requirement) {
                $fields=(array)($requirement['fields']??[]);
                if (!in_array('unbound',$fields,true)) continue;
                $unboundCount++;
                if (count($fields)===1) $unboundOnly++;
                if (in_array('metric_codes',$fields,true)) $unboundMetric++;
                if (in_array('aggregate_condition',$fields,true)) $unboundCondition++;
                if (in_array('object_kind',$fields,true)) $unboundObject++;
                if (in_array('periods',$fields,true)) $unboundPeriod++;
            }
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,
                'unbound_gate_u'.min(9,$unboundCount).'_n'.min(9,$unboundOnly)
                .'_m'.min(9,$unboundMetric).'_c'.min(9,$unboundCondition)
                .'_o'.min(9,$unboundObject).'_p'.min(9,$unboundPeriod),[],
                'unbound_gate_probe');
            throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
        }
        // A clean, understood summary with no candidate is a completed model
        // shape, so the ordinary JSON-format repair path cannot see it.
        // Give the binding model one bounded chance to reconcile that shape
        // with the registry. The predicate contains no customer phrase or
        // metric choice: the model may select a faithful observation group,
        // request a real choice, or keep the capability gap intact.
        if (AiIntentResultContract::requiresEmptyRegisteredBindingRecovery($understanding,$intent)) {
            $reply=$this->repairBindingCandidate(
                $owner,$id,$generation,$worker,$safe['outbound'],$bindingSummaries,$understanding,
                $configuration,$checkpoint,$runtimeSkills,'empty_registered_binding'
            );
            $merged=IntentContextMerger::merge($sourceQuery,$reply['intent']);
            $intent=$merged['intent'];$inheritedConstraints=$merged['constraints'];
            $objectReplacementWithoutFilterConfirmation=$sourceQuery!==null
                && ($intent['context_delta']['object']??null)==='replace'
                && !$merged['replacement_confirmation'];
        }
        // A clarification can defer *how* to present the result, but it may
        // never defer review of a metric selected for this turn. Review the
        // prospective binding before the private fallback replaces pending
        // fields with the signed predecessor, otherwise an exclusion could
        // disappear when the customer confirms the presentation choice.
        $bindingCandidate=$merged['prospective_intent'];
        // Context reuse has already bound the accepted current meaning to a
        // verified predecessor.  Overview recovery is only for an unbound
        // open observation; running it here would reinterpret a period-only
        // employee/member/product continuation as a new store overview.
        $openOverviewRecovery=$this->requiresOpenOverviewRecovery(
            $understanding,$intent,$caps,$contextBindingReused
        );
        if ($openOverviewRecovery) {
            // The semantic pass admitted a broad operating goal, but binding
            // returned a selector before proposing the registered overview.
            // Give the model one fenced correction before any clarification is
            // issued.  PHP neither reads question words nor chooses metrics.
            $reply=$this->repairBindingCandidate(
                $owner,$id,$generation,$worker,$safe['outbound'],$bindingSummaries,$understanding,
                $configuration,$checkpoint,$runtimeSkills,'open_overview_candidate'
            );
            $merged=IntentContextMerger::merge($sourceQuery,$reply['intent']);
            $intent=$merged['intent'];$inheritedConstraints=$merged['constraints'];
            $bindingCandidate=$merged['prospective_intent'];
        }
        // A broad ranking has one source-owned first-answer perspective in
        // the metric registry. Apply that policy at the common post-merge
        // boundary so direct binding, empty binding and multi-candidate
        // recovery cannot disagree. Exact registered customer terms still
        // win, and exclusions/conditions/unbound goals are rejected by the
        // shared structural gate before a default can be considered.
        $questionExactMetric=\app\services\query\metric\MetricSemanticCatalog::uniqueTermInText(
            (string)($safe['outbound']['question']??''),array_column($bindingSummaries,'metric_code')
        );
        $registeredBreakdownDefaultApplied=false;
        if (!$contextBindingReused && $groupedItems===null && !$conditionUpdateBindingReused
            && $questionExactMetric===null) {
            $rankDefault=$this->applyRegisteredRankDefaultPolicy(
                $bindingCandidate,$understanding,$bindingSummaries
            );
            if ($rankDefault!==$bindingCandidate) {
                $bindingCandidate=$rankDefault;
                $merged=IntentContextMerger::merge($sourceQuery,$bindingCandidate);
                $intent=$merged['intent'];$inheritedConstraints=$merged['constraints'];
                $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'registered_rank_default_applied',[],
                    'registered_rank_default');
            }
            // Several explicit metrics must not be mistaken for no metric
            // merely because the unique-term lookup above returned null.
            $explicitBreakdownTerms=\app\services\query\metric\MetricSemanticCatalog::registeredNonOverlappingTermsInText(
                (string)($safe['outbound']['question']??''),array_column($bindingSummaries,'metric_code')
            );
            $breakdownDefault=$explicitBreakdownTerms!==[]?$bindingCandidate:$this->applyRegisteredBreakdownDefaultPolicy(
                $bindingCandidate,$understanding,$bindingSummaries
            );
            if ($breakdownDefault!==$bindingCandidate) {
                $bindingCandidate=$breakdownDefault;
                $merged=IntentContextMerger::merge($sourceQuery,$bindingCandidate);
                $intent=$merged['intent'];$inheritedConstraints=$merged['constraints'];
                $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'registered_breakdown_default_applied',[],
                    'registered_breakdown_default');
            }
            $registeredBreakdownDefaultApplied=$explicitBreakdownTerms===[]
                &&$this->isRegisteredBreakdownDefaultBinding($bindingCandidate,$understanding,$bindingSummaries);
        }
        $exactRegistryBinding=AiIntentResultContract::isUniqueExactMetricBinding(
            $bindingCandidate,$understanding,$safe['outbound'],array_column($bindingSummaries,'metric_code')
        );
        // Keep only structural counts for real-run diagnosis. This records no
        // question, metric code, identity or result, but makes it possible to
        // distinguish "no exact registry owner" from malformed model audit
        // rows when a needless selector appears in a live conversation.
        try {
            $metricRequirementCount=0;
            $exactEvidenceOverlapCount=0;
            $metricTermCount=0;$resolvedExactTermCount=0;$literalSubtermCount=0;$otherTermCount=0;$exclusionCount=0;
            $probeExact=\app\services\query\metric\MetricSemanticCatalog::uniqueTermInText(
                (string)($safe['outbound']['question']??''),array_column($bindingSummaries,'metric_code')
            );
            foreach (AiIntentUnderstandingContract::requirements($understanding) as $requirement) {
                if (!in_array('metric_codes',(array)($requirement['fields']??[]),true)) continue;
                $metricRequirementCount++;
                if (!empty($requirement['values']['metric_exclusions'])) $exclusionCount++;
                foreach ((array)($requirement['values']['metric_terms']??[]) as $term) {
                    if (!is_string($term) || $term==='') continue;
                    $metricTermCount++;
                    $resolvedProbe=is_array($probeExact)
                        ?\app\services\query\metric\MetricSemanticCatalog::uniqueCodeForTerms([$term],array_column($bindingSummaries,'metric_code')):null;
                    if (is_array($probeExact) && $resolvedProbe===$probeExact['metric_code']) $resolvedExactTermCount++;
                    elseif (is_array($probeExact) && mb_strpos($probeExact['term'],$term,0,'UTF-8')!==false) $literalSubtermCount++;
                    else $otherTermCount++;
                }
                foreach ((array)($requirement['evidence']??[]) as $evidence) {
                    $quote=$evidence['quote']??null;
                    if (is_array($probeExact) && is_string($quote) && $quote!==''
                        && (mb_strpos($quote,$probeExact['term'],0,'UTF-8')!==false
                            || mb_strpos($probeExact['term'],$quote,0,'UTF-8')!==false)) {
                        $exactEvidenceOverlapCount++;
                        break;
                    }
                }
            }
            $this->runs->recordDiagnostic($owner,$id,$generation,$worker,[
                'stage'=>'analysis_binding',
                'predicate'=>($exactRegistryBinding?'exact_registry_binding':'exact_registry_binding_miss')
                    .':r'.$metricRequirementCount.':o'.$exactEvidenceOverlapCount.':t'.$metricTermCount
                    .':m'.$resolvedExactTermCount.':s'.$literalSubtermCount.':x'.$otherTermCount.':e'.$exclusionCount,
                'needs_metric_choice'=>(bool)($bindingCandidate['needs_metric_choice']??false),
                'selected_metric_count'=>count((array)($bindingCandidate['metric_codes']??[])),
                'metric_requirement_count'=>$metricRequirementCount,
                'binding_row_count'=>count((array)($bindingCandidate['requirement_bindings']??[])),
            ],'exact_binding_probe');
        } catch (\Throwable $ignored) {}
        if ($exactRegistryBinding) {
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'exact_registry_metric_binding_admitted');
        }
        // Preserve only a coarse, non-business branch marker. It lets a
        // failed real run distinguish a missing signed predecessor from an
        // invalid metric-only projection without recording question text,
        // customer identity, metric values, or query results.
        if ($closedMetricOnlyProjection) {
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,
                $sourceQuery===null ? 'closed_metric_context_missing' : 'closed_metric_context_available'
            );
        }
        // A collection item has already passed the typed group contract against
        // its own requirement subset. Running the single-query reviewer only
        // for item zero made equivalent project/card/product requests depend
        // on their order and could convert just the first item to a selector.
        // Keep the one bounded group binding as the symmetric semantic gate;
        // each item is still compiled and registry-validated independently.
        // A verified inherited intent (including a pure date follow-up) has
        // already fixed its object, metric set and authority-bearing query.
        // It must not touch the fresh-binding-only collection variable or ask
        // the model to re-decide a completed answer. Check that boundary
        // first: the variable exists only when the fresh binding path ran.
        // A registered condition carrier has already proved every ordered
        // metric owner (including contained short audit echoes) against the
        // active registry. Re-asking a model to review the same bookkeeping
        // can only reintroduce ambiguity; all object, capability, authority,
        // condition-compiler and Reader gates still run below.
        if (!$contextBindingReused && $groupedItems===null && !$conditionUpdateBindingReused
            && $registeredConditionIntent===null && !$exactRegistryBinding
            && !$registeredBreakdownDefaultApplied
            && AiIntentResultContract::requiresSemanticBindingReview($understanding,$bindingCandidate)) {
            try {
                $reviewDecision=$this->reviewSemanticBinding($owner,$id,$generation,$worker,$safe['outbound'],$bindingSummaries,$understanding,$bindingCandidate,$configuration,$checkpoint);
            } catch (RuntimeException $error) {
                // A rejected review is normally terminal: it has stopped an
                // unsafe substitution before any data read.  One exception is
                // a structurally provable context failure: the model declared
                // that it inherited the old metric even though the accepted
                // current-turn meaning contains a metric requirement. Ask the
                // model once to rebind that already accepted meaning. PHP
                // neither classifies the wording nor picks the replacement.
                if ($error->getMessage()!=='AI_BINDING_SEMANTIC_REJECTED') throw $error;
                $repairPredicate=AiIntentResultContract::requiresMetricContextRebinding($sourceQuery,$understanding,$reply['intent'])
                    ? 'context_metric_carryover_rejected'
                    : (AiIntentResultContract::requiresCurrentMetricRebinding($understanding)
                        ? 'current_metric_binding_rejected' : null);
                if ($repairPredicate===null) throw $error;
                $reply=$this->repairBindingCandidate($owner,$id,$generation,$worker,$safe['outbound'],$bindingSummaries,$understanding,$configuration,$checkpoint,$runtimeSkills,$repairPredicate);
                $merged=IntentContextMerger::merge($sourceQuery,$reply['intent']);
                $intent=$merged['intent'];$inheritedConstraints=$merged['constraints'];
                $bindingCandidate=$merged['prospective_intent'];
                if (!AiIntentResultContract::requiresSemanticBindingReview($understanding,$bindingCandidate)) {
                    throw new RuntimeException('AI_CONTEXT_DELTA_CONFLICT');
                }
                // A second rejection is final. The global recovery budget
                // prevents this semantic correction from becoming a hidden
                // retry loop or a substitute for customer clarification.
                $reviewDecision=$this->reviewSemanticBinding($owner,$id,$generation,$worker,$safe['outbound'],$bindingSummaries,$understanding,$bindingCandidate,$configuration,$checkpoint,false,'review_binding_repaired');
            }
            if ($reviewDecision==='metric_choice') {
                if ($this->allowsOpenOverviewRecovery($understanding,$caps)) {
                    // The reviewer has proved that a broad observation has
                    // several valid measurements. Let the model correct that
                    // one binding into a registered overview before exposing
                    // a selector. The repair is bounded and the Reader later
                    // replaces its provisional angles with the source-owned
                    // profile, so PHP never chooses the business metrics.
                    $reply=$this->repairBindingCandidate(
                        $owner,$id,$generation,$worker,$safe['outbound'],$bindingSummaries,$understanding,
                        $configuration,$checkpoint,$runtimeSkills,'open_overview_candidate'
                    );
                    $merged=IntentContextMerger::merge($sourceQuery,$reply['intent']);
                    $intent=$merged['intent'];$inheritedConstraints=$merged['constraints'];
                    $bindingCandidate=$merged['prospective_intent'];
                } else {
                    // The candidate was only plausible, not uniquely requested.
                    // The registered guidance supplies the choices; the model's
                    // candidate never becomes a default answer or hidden filter.
                    $intent['metric_codes']=[];
                    $intent['needs_metric_choice']=true;
                    // The rejected/ambiguous professional recommendation is no
                    // longer a recommendation once control returns to the
                    // customer. Preserve the understood goal, but never let its
                    // presentation label leak into the selector or a later plan.
                    $intent['recommended_initial_answer']=false;
                    $intent['initial_observation']=false;
                    $intent['requirement_bindings']=array_map(static function(array $row):array {
                        return ['requirement_id'=>$row['requirement_id'],'status'=>'pending','metric_codes'=>[]];
                    },$intent['requirement_bindings']);
                    $bindingCandidate=$intent;
                    $bindingCandidate['_reviewed_ambiguity']=true;
                }
            } elseif (is_string($reviewDecision) && strpos($reviewDecision,'model_metric_rebind:')===0) {
                // The independent candidate-blind reviewer, not PHP, selected
                // the one registered fact that meets the current requirement.
                // This merely carries that model decision into the bounded
                // intent envelope and releases a prior *registered default*
                // cohort when its own metric contract no longer applies.
                $metric=substr($reviewDecision,21);
                $bindingCandidate=$this->applyReviewedMetricBinding($bindingCandidate,$metric,$sourceQuery,$bindingSummaries);
                $merged=IntentContextMerger::merge($sourceQuery,$bindingCandidate);
                $intent=$merged['intent'];$inheritedConstraints=$merged['constraints'];
            }
        }
        // This is deliberately the last context-delta normalizer before
        // compilation. Earlier fenced repairs can replace `$reply['intent']`
        // after the first normalization; for a closed registered metric-only
        // turn, that would otherwise turn a harmless view change into a fresh
        // date/ranking selector. The helper is typed and registry-backed: it
        // cannot apply to a question that also carries a date, condition,
        // object, or ranking instruction.
        if ($closedMetricOnlyProjection && $sourceQuery!==null) {
            $reply['intent']=$this->preserveVerifiedMetricOnlyContext(
                $bindingCandidate,$understanding,$sourceQuery,true
            );
            $merged=IntentContextMerger::merge($sourceQuery,$reply['intent']);
            $intent=$merged['intent'];$inheritedConstraints=$merged['constraints'];
            $bindingCandidate=$merged['prospective_intent'];
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'closed_metric_context_reconciled');
        }
        // A model may express "the second one" but never resolves it to an
        // identity. Only this server code resolves the ordinal against the
        // original read-view snapshot and binds its stable key after replaying
        // current authority. A new query can therefore never substitute the
        // second row of a freshly reordered result.
        $resultReference=null;
        if ($intent['result_reference']!==null) {
            if ($sourceContext===null) throw new RuntimeException('AI_RESULT_REFERENCE_UNAVAILABLE');
            $resultReference=ResultReferenceResolver::resolve($sourceContext['query'],$sourceContext['view'],$intent['result_reference']);
            // A referenced store limits where to query, while the requested
            // analytical object may be its people/products/projects. Other
            // cross-object references have no registered scope relationship.
            if ($resultReference['object_kind']!=='store'
                && !in_array($intent['object_kind'],['unknown',$resultReference['object_kind']],true)) throw new RuntimeException('AI_RESULT_REFERENCE_UNAVAILABLE');
        }
        // Pending is a model-owned statement that more information is needed,
        // not an execution failure. Empty fields below deliberately reach the
        // existing controlled clarification compiler (metric/date/ranking)
        // whenever their surrounding query meaning is already known.
        $intent['_context_pending']=$merged['pending'];
        // A protocol-level pending marker is not permission to replace a
        // missing value with an empty one.  Compile a private preview from the
        // signed predecessor and require a server-owned choice before it can
        // reach execution.  An explicit object replacement keeps its own
        // confirmation flow because its prospective object is the new value.
        // A confirmed new object needs its own capability-driven guidance
        // (for example, selecting a permitted personnel range).  Build that
        // prospective guidance first and place the replacement confirmation
        // in front of it. Other incomplete fields use the signed preview.
        // A replacement can stay on its prospective path only when it is the
        // sole unresolved decision. If another semantic field is pending,
        // compile the signed preview until that field is explicitly resolved.
        $semanticPending=array_values(array_diff($merged['pending'],['business_filters']));
        if ($merged['pending'] && (!$merged['replacement_confirmation'] || $semanticPending)) {
            $intent=$merged['fallback_intent'];
            $intent['_context_pending']=$merged['pending'];
            $inheritedConstraints=$merged['fallback_constraints'];
            // The signed preview only supplies a valid shell for the missing
            // response-form question. It must retain its old analytical
            // object until the pending planner reapplies the already
            // understood replacement object and exposes that object's own
            // registered choices.
            if ($objectReplacementWithoutFilterConfirmation && $semanticPending) {
                $intent['object_kind']=$sourceQuery['business_filters']['object_kind']??'store';
                $intent['object_term']='';
            }
        }
        // Merging must not turn a newly understood condition into a previous
        // query value. The pre-merge contract catches a contradictory delta;
        // this final check is a defense in depth for every executable path.
        if (!$merged['pending'] && !$conditionUpdateBindingReused) {
            try {
                AiIntentResultContract::assertEffectiveRequirementValues($understanding,$intent,$safe['outbound']['reference_date']??null);
            } catch (AiContractException $error) {
                // This is an executable-shape rejection after a model response
                // was accepted at the transport boundary. Keep its structural
                // predicate in the Run telemetry (never the question, model
                // text, identity or result) so a real failed session can be
                // diagnosed without weakening the final contract.
                $this->recordModelDiagnostic($owner,$id,$generation,$worker,$error,'effective_intent');
                throw $error;
            }
        }
        // Apply a result reference after choosing either the requested delta
        // or its signed fallback.  A clarification must never silently drop
        // the user's reference and expand the follow-up back to every row.
        if ($resultReference!==null) {
            if ($resultReference['object_kind']!=='store' || in_array($intent['object_kind'],['unknown','store'],true)) {
                $intent['object_kind']=$resultReference['object_kind'];
                $intent['object_term']='';
            }
            $inheritedConstraints=IntentContextMerger::applyResultReference($inheritedConstraints,$resultReference);
        }
        // On the first turn a named store is not inherited state; it is an
        // understood customer restriction that still has to be resolved in
        // the current, authorized store catalog.  Keeping it as `inherit`
        // used to discard the term and accidentally read every authorized
        // store.  This branches on the semantic carrier, not on a Chinese
        // phrase or an entry point.
        $storeTerm=AiIntentResultContract::understoodStoreTerm($understanding);
        $contextDecisions=$sourceQuery===null
            // An object selection is not automatically a store selection.
            // A role, person, member or product term may be a precise
            // analytical subject while the authorized store range remains
            // unchanged. Only a model-understood store subject enters the
            // authorized store-name resolver.
            ? ['store_scope'=>(($intent['object_relation']??'analysis')==='selection' && ($intent['object_kind']??null)==='store' ? 'replace' : 'inherit'),'business_filters'=>'inherit']
            : ['store_scope'=>$intent['context_delta']['store_scope'],'business_filters'=>$intent['context_delta']['business_filters']];
        if ($storeTerm!==null) {
            $contextDecisions['store_scope']='replace';
            // As with a selected person below, the model need not reproduce
            // opaque-token punctuation. Bind only one current store reference
            // actually cited by the accepted store requirement. Unrelated
            // references, multiple stores and historical evidence stay strict.
            if (!preg_match('/^\[local_condition_[0-9]+\]$/D',$storeTerm)) {
                $storeTerm=$this->currentNamedStoreReference($understanding,$safe,$privateKindsByReference)??$storeTerm;
            }
            if (preg_match('/^\[(local_condition_[0-9]+)\]$/D',$storeTerm,$storeMatch)) {
                $storeReference=$storeMatch[1];
                if (($privateKindsByReference[$storeReference]??null)!=='store') {
                    // The model may put the current role token in store_term
                    // while the same turn has exactly one real store token.
                    // Rebind only from that typed, current store requirement;
                    // the displaced role stays unresolved for personnel
                    // selection. Ambiguous or historical stores still fail.
                    $currentStore=$this->currentNamedStoreReference($understanding,$safe,$privateKindsByReference);
                    if ($currentStore===null || !preg_match('/^\[(local_condition_[0-9]+)\]$/D',$currentStore,$storeMatch)) {
                        throw new RuntimeException('AI_STORE_SCOPE_UNAVAILABLE');
                    }
                    $storeReference=$storeMatch[1];
                    if (($privateKindsByReference[$storeReference]??null)!=='store') throw new RuntimeException('AI_STORE_SCOPE_UNAVAILABLE');
                    $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'current_typed_store_reference_recovered');
                }
                $storeTerm=($safe['reference_values']??[])[$storeReference]??null;
                if (!is_string($storeTerm)||$storeTerm==='') throw new RuntimeException('AI_STORE_SCOPE_UNAVAILABLE');
                unset($safe['local_conditions'][$storeReference]);
            }
        }
        if ($intent['_object_term_normalized']) {
            try { $this->runs->recordDiagnostic($owner,$id,$generation,$worker,['stage'=>'intent_contract','predicate'=>'object_term_not_verbatim']); } catch (\Throwable $ignored) {}
        }
        $term=$intent['object_term'];
        // A metric-only supplement may leave object_term empty even though
        // the signed clarification has one selected member. Restore only that
        // exact encrypted reference; a different topic, ambiguous member
        // references or an unselected member dimension cannot use this path.
        $previous=$body['_clarification_context']??null;
        if ($term==='' && ($intent['object_kind']??null)==='member'
            && ($intent['object_relation']??null)==='selection' && is_array($previous)
            && ($understanding['topic_relation']??null)!=='new_topic') {
            $previousSelected=false;
            foreach ((array)($previous['understanding']['requirements']??[]) as $requirement) {
                $values=(array)($requirement['values']??[]);
                if (($values['object_kind']??null)==='member'
                    && ($values['object_relation']??null)==='selection') $previousSelected=true;
            }
            if ($previousSelected) {
                $eligible=[];
                foreach ((array)($safe['reference_values']??[]) as $reference=>$value) {
                    if (!in_array($value,$memberLabels,true)
                        || strpos((string)($previous['safe_question']??''),'['.$reference.']')===false) continue;
                    $eligible[]=$reference;
                }
                if (count($eligible)===1) $term=$intent['object_term']='['.$eligible[0].']';
            }
        }
        // A protected local catalogue label can denote an analytical class
        // (for example, a registered position) rather than one selected
        // private identity. If the language model independently bound that
        // exact opaque reference to the same analytical object kind, it is no
        // longer unresolved. Consume only that proven token. Named people,
        // members, stores and every selection relation remain on the strict
        // local-resolution path below.
        if (($intent['object_relation']??null)==='analysis' && ($intent['unresolved_fragments']??[])!==[]) {
            $remaining=[];$resolvedAnalyticalReferences=0;
            foreach ($intent['unresolved_fragments'] as $fragment) {
                if (is_string($fragment)
                    && preg_match('/^\[(local_condition_[0-9]+)\]$/D',$fragment,$referenceMatch)
                    && ($privateKindsByReference[$referenceMatch[1]]??null)===($intent['object_kind']??null)
                    && strpos((string)($safe['outbound']['question']??''),$fragment)!==false) {
                    unset($safe['local_conditions'][$referenceMatch[1]]);
                    $resolvedAnalyticalReferences++;
                    continue;
                }
                $remaining[]=$fragment;
            }
            if ($resolvedAnalyticalReferences>0) {
                $intent['unresolved_fragments']=$remaining;
                $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'local_analytical_object_reference_bound',[
                    'reference_count'=>$resolvedAnalyticalReferences,
                ]);
            }
        }
        // The binding audit already distinguishes a clear requirement whose
        // Reader is unavailable from language that was not understood.  Do
        // not let a provider also echo that same phrase in
        // unresolved_fragments and turn a truthful capability boundary into
        // “I did not understand”. No metric is selected or substituted here.
        foreach ((array)($intent['requirement_bindings']??[]) as $binding) {
            if (is_array($binding) && ($binding['status']??null)==='unavailable') {
                throw new RuntimeException('AI_CAPABILITY_NOT_READY');
            }
        }
        // Only a full phone supplied in this turn can narrow one already
        // masked member name. Consume its opaque model fragment locally before
        // generic ambiguity handling; every other fragment remains blocking.
        $memberPhone=null;$memberPhoneName=null;
        if (($intent['object_kind']??null)==='member') {
            $current=(string)($body['question']??'');
            $original=(string)($body['_clarification_context']['original_question']??'');
            if (preg_match('/(?<![0-9])1[3-9][0-9]{9}(?![0-9])/u',$current,$phoneMatch)) {
                $names=array_values(array_filter($memberLabels,static function(string $name)use($current,$original):bool {
                    return mb_strpos($current,$name,0,'UTF-8')!==false || mb_strpos($original,$name,0,'UTF-8')!==false;
                }));
                if (count($names)===1) {
                    $memberPhone=$phoneMatch[0];$memberPhoneName=$names[0];$phoneReferences=[];
                    foreach ((array)($safe['local_conditions']??[]) as $reference=>$value) if ($value===$memberPhone) {
                        $phoneReferences[]='['.$reference.']';unset($safe['local_conditions'][$reference]);
                    }
                    $intent['unresolved_fragments']=array_values(array_filter($intent['unresolved_fragments'],
                        static fn($fragment):bool=>!in_array($fragment,$phoneReferences,true)));
                }
            }
        }
        // A partially understood request must not be described as total
        // incomprehension. Accepted typed meaning owns that distinction;
        // neither branch may discard the unresolved customer condition.
        if ($intent['unresolved_fragments'] || $intent['operation']==='unknown') {
            return $this->semanticClarification($understanding,$safe,$body);
        }
        $localTerm=null;$localReference=null;
        if (preg_match('/^\[(local_condition_[0-9]+)\]$/D',$term,$match)) {
            $localReference=$match[1];
            $localTerm=($safe['reference_values']??$safe['local_conditions'])[$localReference]??null;
            unset($safe['local_conditions'][$localReference]);$term=$localTerm??'';
            // A reference which occurs only in an earlier question is not a
            // current member/product/project restriction.  When a complete
            // new turn has already replaced the analytical object, discard
            // that stale selected-person carrier instead of rejecting the new
            // registered dimension.  A reference stated in the current turn
            // remains strict and can never be silently removed.
            $currentToken='['.$match[1].']';
            $currentQuestion=(string)($safe['outbound']['question']??'');
            $previous=$body['_clarification_context']??null;
            $sameRunMemberSelection=false;
            if (($intent['object_kind']??null)==='member' && is_array($previous)
                && ($understanding['topic_relation']??null)!=='new_topic'
                && strpos((string)($previous['safe_question']??''),$currentToken)!==false) {
                foreach ((array)($previous['understanding']['requirements']??[]) as $requirement) {
                    $values=(array)($requirement['values']??[]);
                    if (($values['object_kind']??null)==='member'
                        && ($values['object_relation']??null)==='selection') $sameRunMemberSelection=true;
                }
            }
            // A metric-only reply in this signed Run still refers to its
            // selected member. Ordinary new turns must drop stale names, but
            // this encrypted clarification state explicitly preserves that
            // private selection until identity and metric are both resolved.
            if (strpos($currentQuestion,$currentToken)===false
                && !in_array($intent['object_kind']??null,['person','position'],true)
                && !$sameRunMemberSelection) {
                $localTerm=null;$term='';$intent['object_term']='';$intent['object_relation']='analysis';
            }
        }
        // The semantic model chooses whether the subject is a person, member
        // or something else; it must not also be required to reproduce the
        // exact opaque token formatting.  If the current question contains
        // exactly one locally masked object compatible with that chosen kind,
        // bind it here.  Multiple matches, credentials and incompatible kinds
        // remain unresolved, so this cannot become a name/phrase shortcut or
        // silently discard another private condition.
        if ($localTerm===null) {
            $current=(string)($safe['outbound']['question']??'');$kind=(string)($intent['object_kind']??'unknown');$matches=[];
            foreach ((array)($safe['local_conditions']??[]) as $reference=>$value) {
                if (!is_string($reference)||!is_string($value)||strpos($current,'['.$reference.']')===false) continue;
                $privateKind=$privateKindsByReference[$reference]??null;
                $compatible=$privateKind==='object' && in_array($kind,['person','member'],true);
                $compatible=$compatible || $privateKind===$kind
                    || ($kind==='person'&&$privateKind==='position')
                    || ($kind==='position'&&in_array($privateKind,['person','position'],true));
                if ($compatible) $matches[$reference]=$value;
            }
            if (count($matches)===1) {
                $localReference=array_key_first($matches);$localTerm=$matches[$localReference];$term=$localTerm;
                unset($safe['local_conditions'][$localReference]);
                $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'local_object_reference_bound');
            }
        }
        if ($memberPhoneName!==null) {
            $localTerm=$memberPhoneName;$term=$localTerm;
            foreach ((array)($safe['local_conditions']??[]) as $reference=>$value) {
                if ($value===$localTerm) unset($safe['local_conditions'][$reference]);
            }
        }
        // All remaining opaque conditions are meaningful. Never query a reduced request.
        if ($safe['local_conditions']) {
            // Keep only protocol kinds in diagnostics: an unresolved private
            // condition must be traceable without logging its name or token.
            $remainingKinds=[];
            foreach ($safe['local_conditions'] as $reference=>$unusedValue) {
                $remainingKinds[]=$privateKindsByReference[$reference]??'unknown';
            }
            sort($remainingKinds);
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,
                'local_condition_unbound:'.($intent['object_kind']??'unknown').':'.($intent['object_relation']??'unknown').':named'.($storeTerm===null?'0':'1').':ref'.(isset($storeReference)?'1':'0'),
                ['field'=>count(array_unique($remainingKinds))===1?$remainingKinds[0]:'mixed'], 'local_condition_probe');
            throw new RuntimeException('AI_LOCAL_CONDITION_REQUIRED');
        }
        if (($intent['object_kind']??null)==='position' || ($localTerm!==null && (($privateKindsByReference[$localReference??'']??null)==='position'))) $intent['object_kind']='person';
        if ($intent['periods']!==[]) {
            $projection['dates']=[];
            $projection['date_terms']=$this->naturalPeriodTerms($intent['periods'],$today);
        }
        // A broad platform question without a metric and period has no
        // customer-confirmed time meaning. Keep the missing slot through the
        // existing guidance state; a precise named metric can still use the
        // published current-day default, and signed context remains intact.
        if (($context['terminal']??null)==='platform' && $sourceQuery===null
            && $intent['periods']===[] && ($projection['date_terms']??[])===[]
            && ($intent['metric_codes']===[] || !empty($intent['needs_metric_choice']))) {
            $projection['require_period_clarification']=true;
        }
        if ($intent['_scope_supplied'] && $intent['scope']==='current_store') {
            if (\app\services\ai\execution\AiAuthority::currentStoreId($context)===null) throw new RuntimeException('AI_UNSUPPORTED_CONDITION');
            $projection['signals'][]='current_store';
        }
        // A current-store phrase is an explicit customer condition.  The
        // authenticated origin may resolve that phrase, but it must be bound
        // into the eventual query rather than merely accepted as a signal.
        $currentStoreRequested=$intent['_scope_supplied'] && $intent['scope']==='current_store';
        // Scope changes are shared by every subject, not just store summaries.
        // The source selection has already been recovered before this finishing
        // step; an unresolved new store must still ask, never widen the query.
        $operationIsPending=in_array('operation',$merged['pending'],true);
        $metricOptions=$this->registeredMetricOptions(
            $caps,$summaries,$intent['object_kind']??'unknown',$operationIsPending?null:($intent['operation']??null)
        );
        $replacementMetricOptions=$this->registeredMetricOptions(
            $caps,$summaries,$merged['prospective_intent']['object_kind']??'unknown',
            $operationIsPending?null:($merged['prospective_intent']['operation']??null)
        );
        $operationOptions=$this->registeredOperationOptions($caps,$intent['object_kind']??'unknown');
        $replacementOperationOptions=$this->registeredOperationOptions($caps,$merged['prospective_intent']['object_kind']??'unknown');
        $finish=function(array $compiled)use($context,$term,$storeTerm,$contextDecisions,$owner,$id,$generation,$worker,$inheritedConstraints,$merged,$metricOptions,$replacementMetricOptions,$operationOptions,$replacementOperationOptions,$currentStoreRequested,$understanding,$safe,$summaries,$bindingCandidate,$objectReplacementWithoutFilterConfirmation,$requestedMemberDetail,$body):array {
            $compiled=$this->bindNamedStoreScope($compiled,$context,$storeTerm??$term,$contextDecisions,$owner,$id,$generation,$worker);
            $compiled=$this->bindCurrentStoreScope($compiled,$context,$currentStoreRequested);
            if ($merged['replacement_confirmation']) {
                $remaining=array_values(array_filter($merged['pending'],static function(string $field): bool { return $field!=='business_filters'; }));
                $compiled=(new \app\services\ai\execution\AiContextReplacementGuidancePlanner())->start($compiled,$remaining,$inheritedConstraints,$replacementMetricOptions,$merged['prospective_intent'],$replacementOperationOptions,['understanding'=>$understanding]);
            } elseif ($merged['pending']) {
                $pendingMetricOptions=$metricOptions;$pendingOperationOptions=$operationOptions;
                if ($objectReplacementWithoutFilterConfirmation) {
                    $compiled=(new \app\services\ai\execution\AiPendingContextGuidancePlanner())->applyConfirmedIntent($compiled,$merged['prospective_intent']);
                    $pendingMetricOptions=$replacementMetricOptions;
                    $pendingOperationOptions=$replacementOperationOptions;
                }
                $compiled=(new \app\services\ai\execution\AiPendingContextGuidancePlanner())->start($compiled,$merged['pending'],$inheritedConstraints,$pendingMetricOptions,$merged['prospective_intent'],$pendingOperationOptions,['understanding'=>$understanding]);
            } else {
                try {
                    $compiled=IntentContextMerger::bind($compiled,$inheritedConstraints);
                } catch (RuntimeException $error) {
                    if ($error->getMessage()==='AI_CONTEXT_DELTA_CONFLICT') {
                        $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,
                            'context_bind_conflict',[],'context_bind_probe');
                    }
                    throw $error;
                }
            }
            // The frontend never receives this key: issueGuidance persists it
            // only in the signed private envelope.  Keeping the exact
            // de-identified review input here lets a later controlled choice
            // be checked against the original accepted meaning.
            if (($compiled['kind']??null)==='clarification') {
                $compiled['_semantic_reply_state']=$this->semanticReplyState($understanding,$safe,$body);
                $compiled['_request_body']=$body;
                // A candidate-blind review has already established that one
                // fresh, exclusion-free measurement admits several meanings.
                // The controlled customer selection resolves that ambiguity;
                // it must not consume a fourth model stage or be overruled by
                // another interpretation of the original broad wording.
                $needsFinalMetricReview=($bindingCandidate['needs_metric_choice'] && empty($bindingCandidate['_reviewed_ambiguity']))
                    || in_array('metric_codes',$merged['pending'],true);
                $compiled['_semantic_context']=['understanding'=>$understanding,'requires_final_metric_review'=>$needsFinalMetricReview];
                if ($needsFinalMetricReview) {
                    $compiled['_semantic_context']['binding_review']=[
                        // SiliconFlowClient validates the exact sanitized-question
                        // contract before it sends a review. Persist the complete
                        // de-identified projection, not a lookalike subset.
                        'safe_question'=>$safe['outbound'],
                        'summaries'=>$summaries,
                    ];
                }
            }
            if (($compiled['kind']??null)==='plan') {
                // This is presentation provenance, not a reconstructed
                // customer condition. It lets a later model distinguish a
                // platform-suggested first view from a customer-selected
                // metric without receiving any prior answer or result data.
                $compiled['_context_meaning']=['presentation_origin'=>
                    !empty($bindingCandidate['initial_observation'])?'platform_observation':
                    (!empty($bindingCandidate['recommended_initial_answer'])?'platform_recommendation':'customer_or_verified_context')];
            }
            // Presentation follows the authorised filter query; it is not a
            // new metric and must not disappear while the filters are bound.
            if ($requestedMemberDetail!==null) $compiled=$this->attachPopulationDetail($compiled,$requestedMemberDetail);
            return $compiled;
        };
        // The model Skill identifies the semantic subject from the complete
        // sentence. Local aliases are used for privacy/catalog projection only;
        // they must not override the subject merely because a short word also
        // appears inside a scope phrase such as “本店”.
        // The model owns natural-language interpretation. The legacy projector
        // may still contribute trusted follow-up state, but cannot overrule the
        // model with a phrase match, blocker or inferred object.
        $projection['blocking_reason']=null;$projection['unresolved_condition']=false;
        $projection['semantic_intent']['constraints']=[];
        // A previous personnel/dimension answer can have originated from a
        // clarification envelope rather than an executable plan.  When the
        // current turn replaces that object and still needs another answer,
        // rebase from the signed source query itself. Reusing the unfinished
        // personnel envelope would make a later store metric selection reopen
        // the previous personnel selector.
        if ($sourceQuery!==null && $merged['replacement_confirmation'] && $semanticPending) {
            $shape=$sourceQuery['query_shape']??null;
            if (!in_array($shape,['summary','breakdown','trend','ranking','comparison','threshold_count'],true)) throw new RuntimeException('AI_CONTEXT_DELTA_CONFLICT');
            return $finish(['kind'=>'plan','plan'=>['workflow_code'=>'wf_performance_'.$shape,
                'query'=>$sourceQuery,'output_format'=>$body['output_format']]]);
        }
        // Understanding is deliberately broader than today's registered
        // Reader.  When the model has preserved a clear business goal but no
        // registered metric can faithfully bind it, stop as a capability gap
        // instead of presenting unrelated metric choices or claiming that
        // the customer failed to explain the request.
        if (is_array($understanding) && ($understanding['status']??null)==='understood'
            && $intent['object_kind']!=='person' && $intent['metric_codes']===[] && !$intent['needs_metric_choice']) {
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,
                'analysis_no_registered_metric',[],'analysis_metric_probe');
            throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
        }
        if (is_array($understanding) && ($understanding['status']??null)==='needs_clarification'
            && $completeTypedCondition===null
            && !$intent['needs_metric_choice'] && $intent['unresolved_fragments']===[] && !$merged['pending']) {
            return $this->semanticClarification($understanding,$safe,$body);
        }
        // Binding repair can introduce a model-selected metric after the
        // first candidate check, whether or not it marks that suggestion as
        // initial_observation. Gate the final intent before any plan: a model
        // cannot supply an unstated measurement and time on the customer's
        // behalf. A stated period or exact metric keeps the direct-answer path.
        $customerPeriod=false;
        foreach ($understanding['requirements'] as $requirement) {
            if (!empty($requirement['values']['periods'])) $customerPeriod=true;
        }
        if (($context['terminal']??null)==='platform' && $sourceQuery===null
            && ($intent['object_kind']??null)==='store' && ($intent['operation']??null)==='summary'
            && !$customerPeriod
            && \app\services\query\metric\MetricSemanticCatalog::uniqueTermInText(
                (string)($safe['outbound']['question']??''),$caps['metric_codes']??[])===null) {
            return $this->verifiedSemanticClarification($owner,$id,$generation,$worker,$understanding,$safe,$body,$caps,$today,$configuration,$checkpoint,$sourceQuery,
                \app\services\ai\execution\AiAuthority::currentStoreId($freshContext));
        }
        // `initial_observation` is semantic admission only.  Its provisional
        // model candidates are deliberately replaced here by the complete
        // source-owned profile for the understood object.  Registry metadata,
        // rather than a phrase, metric-name, or frontend list, decides which
        // facts are included; the normal compiler and Reader still enforce
        // readiness, object grain, authority and the execution budget.
        $intent=$this->resolveOverviewMetrics($intent,$caps);
        // A named member is a local private selection, not an aggregate
        // member dimension.  The language model chooses the semantic object
        // and registered measurement; this block only proves that its opaque
        // reference resolves uniquely in the current authorized catalogue.
        if ($intent['object_kind']==='member' && $localTerm!==null) {
            if (!$memberMetrics || $intent['operation']!=='summary' || $intent['needs_metric_choice'] || count($intent['metric_codes'])!==1) {
                $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,
                    'analysis_member_selection_shape_unavailable',[],'member_shape_probe');
                throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
            }
            $metric=$intent['metric_codes'][0];
            if (!isset($memberMetrics[$metric])) {
                $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,
                    'analysis_member_metric_unavailable',[],'member_metric_probe');
                throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
            }
            $period=(new \app\services\ai\execution\AiWorkflowPlanner())->normalizePeriod(($projection['date_terms']??[['code'=>'TODAY']])[0],$today);
            // A null selection is a private, non-executable identity slot.
            // It is replaced only after one authorized exact match is proved.
            $query=['query_shape'=>'summary','metric_codes'=>[$metric],
                'start_date'=>$period['start'],'end_date'=>$period['end'],
                'compare_range'=>null,'store_ids'=>[],'business_filters'=>['object_kind'=>'member','selection_ref'=>null],
                'ranking'=>null,'aggregate_condition'=>null];
            // A full phone, when supplied, is matched only in the authorized
            // local relation. The projector masks it before any model request.
            $matches=$this->memberObjects($context)->exactCandidates($localTerm,[$metric],$memberPhone);
            // A full phone that still matches multiple active members is a
            // data-quality conflict, not another question the user can solve.
            if ($memberPhone!==null && count($matches)>1) throw new RuntimeException('AI_OBJECT_BINDING_UNAVAILABLE');
            if (($context['terminal']??null)==='platform'
                && (count($matches)>1 || ($memberPhone!==null && $matches===[]))) {
                // The same durable Run requests only the missing identity;
                // never list lookalike members or submit a guessed reference.
                $guidance=$this->semanticClarification($understanding,$safe,$body);
                $guidance['question']=$matches===[]
                    ?'未找到与该姓名和手机号同时匹配的会员。请核对完整手机号。'
                    :'有多位同名会员。请补充该会员的完整手机号后继续查询。';
                $guidance['fields'][0]['options']=[['value'=>'__supplement__','label'=>'补充会员完整手机号']];
                if ($merged['pending']===[] && !$merged['replacement_confirmation']) {
                    // Freeze only a fully resolved business request. Applying
                    // the same finishing path preserves explicit store scope
                    // and inherited constraints; unresolved requests still
                    // follow normal understanding after the identity reply.
                    $pending=$finish(['kind'=>'plan','plan'=>['workflow_code'=>'wf_performance_summary','query'=>$query,'output_format'=>$body['output_format']]]);
                    if (($pending['kind']??null)==='plan') $guidance['_member_identity_request']=['name'=>$localTerm,'compiled'=>$pending];
                }
                return $guidance;
            }
            if (count($matches)!==1) {
                $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,
                    'analysis_member_object_unavailable',[],'member_object_probe');
                throw new RuntimeException('AI_OBJECT_BINDING_UNAVAILABLE');
            }
            $query['business_filters']['selection_ref']=$matches[0]['ref'];
            return $finish(['kind'=>'plan','plan'=>['workflow_code'=>'wf_performance_summary','query'=>$query,'output_format'=>$body['output_format']]]);
        }
        // Population conditions are object contracts, not a personnel-only
        // special case.  Admission is still fail-closed: the selected object,
        // every metric and the requested count/list shape must all be published
        // by the current registry before the typed condition reaches a Reader.
        if (in_array($intent['operation'],['condition_count','condition_list'],true)) {
            $subject=$intent['object_kind'];
            $conditionMetrics=\app\services\ai\execution\AiCapabilityGuidanceCatalog::discover($caps,$subject,$intent['operation']);
            if ($localTerm!==null||!$conditionMetrics
                ||$intent['needs_metric_choice']||count($intent['metric_codes'])<1
                ||count($intent['metric_codes'])>\app\services\ai\execution\AiOverviewMetricResolver::MAX_METRICS
                ||!is_array($intent['aggregate_condition']??null)||($intent['aggregate_condition']['subject']??null)!==$subject) {
                $shapeField=$localTerm!==null?'object_selection'
                    :(!$conditionMetrics?'registry_contract'
                    :($intent['needs_metric_choice']?'metric_choice'
                    :((count($intent['metric_codes'])<1
                        ||count($intent['metric_codes'])>\app\services\ai\execution\AiOverviewMetricResolver::MAX_METRICS)?'metric_count'
                    :(!is_array($intent['aggregate_condition']??null)?'aggregate_condition':'condition_subject'))));
                $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'analysis_object_condition_shape_unavailable',[
                    'field'=>$shapeField,
                    'operation'=>$intent['operation'],
                    'needs_metric_choice'=>(bool)$intent['needs_metric_choice'],
                    'selected_metric_count'=>count($intent['metric_codes']),
                    'condition_mismatch'=>$shapeField==='condition_subject'?'subject':($shapeField==='aggregate_condition'?'semantic_shape':'unknown'),
                ],'condition_shape_probe');
                throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
            }
            foreach ($intent['metric_codes'] as $selectedMetric) if (!isset($conditionMetrics[$selectedMetric])) {
                $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,
                    'analysis_object_condition_metric_unavailable',[],'condition_metric_probe');
                throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
            }
            // A model may correctly bind every condition yet omit an explicit
            // calendar carrier such as “本月”. Reuse only the existing local
            // grammar's single unambiguous period in that case; otherwise
            // the planner's neutral TODAY default would silently narrow the
            // customer's requested range and produce a wrong count.
            if ($intent['periods']===[] && ($projection['date_terms']??[])===[]) {
                $literalCalendar=(new AiModelInputProjector())->project((string)($safe['outbound']['question']??''));
                if (count((array)($literalCalendar['date_terms']??[]))===1
                    && empty($literalCalendar['date_grouping_ambiguous'])) {
                    $projection['date_terms']=$literalCalendar['date_terms'];
                }
            }
            $caps['current_store_bound']=\app\services\ai\execution\AiAuthority::currentStoreId($context)!==null;
            if ($completeTypedCondition!==null) {
                // Reuse the proven plan only if the ordinary binding pipeline
                // retained every semantic slot. A later rewrite must not be
                // hidden by executing a stale preflight result.
                foreach (['object_kind','object_relation','operation','metric_codes','periods','scope','aggregate_condition','ranking'] as $field) {
                    if (($intent[$field]??null)!==($completeTypedCondition['intent'][$field]??null)) {
                        throw new AiContractException('AI_MODEL_INTENT_CONTRACT_INVALID',[
                            'stage'=>'intent_contract','predicate'=>'complete_condition_changed','field'=>$field,
                        ]);
                    }
                }
                $conditionPlan=$completeTypedCondition['compiled'];
            } else {
                $conditionPlan=$this->compileConditionPlan($intent,$projection,$caps,$body['output_format'],$today);
            }
            return $finish($conditionPlan);
        }
        // "Each/every" is a result grain, not a store-specific phrase rule.
        // Once the model has preserved operation=breakdown, every registered
        // analytical object follows this one capability-driven compiler path.
        // The registry still decides which metric/object combinations exist;
        // the gateway neither chooses a fact column nor turns the list into a
        // ranking merely because values can be ordered.
        if ($intent['operation']==='breakdown') {
            $objectKind=$intent['object_kind'];
            $breakdownMetrics=\app\services\ai\execution\AiCapabilityGuidanceCatalog::discover($caps,$objectKind,'breakdown');
            if ($localTerm!==null||in_array($objectKind,['unknown','business_date'],true)||!$breakdownMetrics
                ||count($intent['metric_codes'])>4) {
                $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'analysis_breakdown_shape_unavailable',[
                    'object_kind'=>(string)$objectKind,'selected_metric_count'=>count($intent['metric_codes']),
                ],'breakdown_shape_probe');
                throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
            }
            foreach ($intent['metric_codes'] as $selectedMetric) if (!isset($breakdownMetrics[$selectedMetric])) {
                $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'analysis_breakdown_metric_unavailable',[], 'breakdown_metric_probe');
                throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
            }
            $projection['signals']=array_values(array_unique(array_merge($intent['metric_codes'],['breakdown'])));
            if ($intent['needs_metric_choice']) $projection['signals'][]='ambiguous_metric';
            $projection['blocking_reason']=null;$projection['unresolved_condition']=false;$projection['semantic_intent']['constraints']=[];
            $caps['current_store_bound']=\app\services\ai\execution\AiAuthority::currentStoreId($context)!==null;
            return $finish((new AiWorkflowPlanner())->compile($projection,[
                'decision'=>'query','query_shape'=>'breakdown','metric_codes'=>$intent['metric_codes'],
                'ranking'=>$intent['ranking'],'object_kind'=>$objectKind,
            ],$caps,$body['output_format'],$today));
        }
        // A business-date extremum reuses the registered daily trend source.
        // The model must explicitly bind business_date + ranking; this branch
        // only converts that typed meaning into the common ranking plan and
        // never scans customer wording or selects a metric on its behalf.
        if ($intent['object_kind']==='business_date') {
            $dateMetrics=\app\services\ai\execution\AiCapabilityGuidanceCatalog::discover($caps,'store','trend');
            if ($localTerm!==null || $intent['operation']!=='ranking' || !$dateMetrics) {
                $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'analysis_business_date_contract_unavailable');
                throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
            }
            $first=(new \app\services\ai\execution\AiDimensionGuidancePlanner())->start(
                'business_date',$intent,$projection,$dateMetrics,$body['output_format'],$today
            );
            if ($groupedItems!==null) {
                return $this->compileDimensionRankingCollection(
                    $groupedItems,$groupedUnderstanding,$intent,$first,$projection,$caps,$body,$today,
                    $safe['outbound'],$bindingSummaries,$configuration,$checkpoint,$owner,$id,$generation,$worker,
                    $sourceQuery,$localTerm,$currentStoreRequested
                );
            }
            return $finish($first);
        }
        // Any registered object dimension follows the same controlled path.
        // Object labels come from the runtime Skill; metrics and dimensions
        // come from the lower-layer registry.  No report page/object switch is
        // permitted here.
        if (!in_array($intent['object_kind'],['person','store','unknown'],true)) {
            $dimensionOperation=$intent['operation']==='threshold_count'?'threshold_count':$intent['operation'];
            $dimensionMetrics=\app\services\ai\execution\AiCapabilityGuidanceCatalog::discover($caps,$intent['object_kind'],$dimensionOperation);
            if ($localTerm!==null || !$dimensionMetrics) {
                $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,
                    $localTerm!==null?'analysis_dimension_private_selection_unavailable':'analysis_dimension_contract_unavailable');
                throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
            }
            if ($intent['operation']==='threshold_count' && $intent['object_kind']==='member') {
                // The normal compiler below verifies both the registered
                // member dimension and the typed threshold contract.
            } elseif (!in_array($intent['operation'],['summary','ranking'],true)) {
                $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'analysis_dimension_shape_unavailable');
                throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
            }
            // A short object-only continuation may legitimately retain the
            // preceding metric. If that signed metric cannot serve the newly
            // accepted registered dimension, do not execute it, silently
            // substitute another one, or misreport a system mismatch as a
            // failed customer query. The existing dimension planner presents
            // only the new dimension's registry-backed metrics. Current-turn
            // metric selections remain strict and are never discarded here.
            $selectedMetrics=(array)($intent['metric_codes']??[]);
            $inheritsMetric=(($intent['context_delta']['metric_codes']??null)==='inherit');
            if ($inheritsMetric && count($selectedMetrics)===1 && !isset($dimensionMetrics[$selectedMetrics[0]])) {
                $intent['metric_codes']=[];
                $intent['needs_metric_choice']=true;
                $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'analysis_dimension_inherited_metric_incompatible');
            }
            if ($intent['operation']==='ranking') {
                $first=(new \app\services\ai\execution\AiDimensionGuidancePlanner())->start(
                    $intent['object_kind'],$intent,$projection,$dimensionMetrics,$body['output_format'],$today
                );
                if ($groupedItems!==null) {
                    return $this->compileDimensionRankingCollection(
                        $groupedItems,$groupedUnderstanding,$intent,$first,$projection,$caps,$body,$today,
                        $safe['outbound'],$bindingSummaries,$configuration,$checkpoint,$owner,$id,$generation,$worker,
                        $sourceQuery,$localTerm,$currentStoreRequested
                    );
                }
                return $finish($first);
            }
        }
        if ($intent['object_kind']==='person') {
            if (!$personMetrics) throw new RuntimeException('AI_PERSONNEL_PERMISSION_REQUIRED');
            // Resolve against a common authorized object catalog. Before selecting a
            // different metric, execution independently rechecks its own report grant.
            if (count($intent['metric_codes'])>4 || (count($intent['metric_codes'])>1 && ($intent['operation']!=='summary' || $intent['needs_metric_choice']))) {
                $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'analysis_person_multiple_metrics');
                throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
            }
            $metric=$intent['metric_codes'][0]??null;
            foreach ($intent['metric_codes'] as $selectedMetric) if (!isset($personMetrics[$selectedMetric])) {
                $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'analysis_person_metric_unavailable');
                throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
            }
            $intent['metric_codes']=array_values($intent['metric_codes']);
            $catalog=$localCatalogs[$metric??array_key_first($personMetrics)];
            $objectCatalog=new \app\services\query\metric\AnalysisObjectCatalog($catalog['objects'],static function(){return true;});
            // A named store and a selected person/position are independent
            // filters. Clearing the person term merely because the store
            // changed erased an explicitly named technician role on a fresh
            // question. Only an absent current local selection may defer to
            // the verified inherited person filter or registered cohort.
            $objectTerm=$contextDecisions['store_scope']==='replace'
                && $contextDecisions['business_filters']==='inherit' && $localTerm===null ? '' : $term;
            $named=$objectCatalog->resolve($objectTerm,'person',$metric);
            // Exact local names may bind a person; a missing name is never fuzzily
            // replaced with someone else. Otherwise resolve actual position metadata.
            $exactPeople=array_values(array_filter($catalog['objects'],static function($o)use($objectTerm){
                return $o['kind']==='person'
                    && in_array(trim($objectTerm),array_merge([$o['label']],$o['aliases']),true);
            }));
            $objects=$exactPeople?$named:$objectCatalog->resolve($objectTerm,'position',$metric);
            $objects=IntentContextMerger::resolveSelection($objects,$catalog['objects'],$inheritedConstraints,'person',$metric);
            $objects=$this->attachRegisteredDefaultAnalysisObject(
                $objects,$catalog['objects'],$personMetrics[$metric]??null,$metric,$objectTerm
            );
            try {
                return $finish((new \app\services\ai\execution\AiAnalysisGuidancePlanner())->start($intent,$projection,$personMetrics,$objects,$body['output_format'],$today));
            } catch (\RuntimeException $error) {
                // This is an internal, payload-free diagnosis only.  The model
                // remains responsible for natural-language understanding; the
                // server records which registered-plan boundary rejected the
                // already-model-produced candidate, then keeps the existing
                // user-facing capability boundary rather than inventing a
                // phrase-specific fallback.
                $predicates=[
                    'AI_ANALYSIS_PERSON_OBJECT_UNAVAILABLE'=>'analysis_person_object_shape',
                    'AI_ANALYSIS_PERSON_OPERATION_UNAVAILABLE'=>'analysis_person_operation_shape',
                    'AI_ANALYSIS_PERSON_MULTIPLE_METRICS'=>'analysis_person_multiple_metrics',
                    'AI_ANALYSIS_PERSON_PERIOD_COMBINATION_UNAVAILABLE'=>'analysis_person_period_combination',
                ];
                if (!isset($predicates[$error->getMessage()])) throw $error;
                $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,$predicates[$error->getMessage()],
                    ['operation'=>(string)($intent['operation']??'unknown')]);
                throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
            }
        }
        // No staff/customer/object restriction may become an unfiltered store query.
        $registeredObjectSummary=$intent['operation']==='summary' && $localTerm===null
            && !in_array($intent['object_kind'],['person','store','unknown','member'],true);
        if ($localTerm!==null || (!in_array($intent['object_kind'],['store','unknown','member'],true) && !$registeredObjectSummary)) {
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'analysis_object_contract_unavailable');
            throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
        }
        // Do not let a local word list select a metric or result shape.  The
        // natural-language model returns a candidate, and the compiler below
        // verifies it against the same registered contracts used by reports.
        $projection['signals']=array_values(array_unique(array_merge($intent['metric_codes'],[$intent['operation']])));
        if ($intent['needs_metric_choice']) $projection['signals'][]='ambiguous_metric';
        $projection['blocking_reason']=null;$projection['unresolved_condition']=false;$projection['semantic_intent']['constraints']=[];
        $caps['current_store_bound']=\app\services\ai\execution\AiAuthority::currentStoreId($context)!==null;
        $compiled=(new AiWorkflowPlanner())->compile($projection,['decision'=>'query','query_shape'=>$intent['operation'],'metric_codes'=>$intent['metric_codes'],'ranking'=>$intent['ranking'],
            'aggregate_condition'=>$intent['aggregate_condition']??null,'object_kind'=>$intent['object_kind'],
            // Model suggestions are only display candidates for a genuine
            // ambiguity; the planner intersects them with executable metrics.
            'metric_choice_candidates'=>$intent['needs_metric_choice']?($intent['metric_choice_candidates']??[]):[]],$caps,$body['output_format'],$today);
        return $finish($compiled);
    }

    /**
     * Reattaches a metric-owned cohort after customer-selectable people and
     * positions have been resolved. Cohorts stay out of the selector itself;
     * they may answer only a broad, object-free question and only when the
     * already-bound metric registers that exact reference as its default.
     */
    private function attachRegisteredDefaultAnalysisObject(array $resolved,array $catalog,?array $candidate,?string $metric,string $objectTerm): array
    {
        if (($resolved['status']??null)==='resolved' || $objectTerm!=='' || $metric===null || $candidate===null) return $resolved;
        $defaultRef=$candidate['default_selection_ref']??null;
        $matches=array_values(array_filter($catalog,static function(array $object)use($defaultRef,$metric):bool {
            return is_string($defaultRef) && $defaultRef!==''
                && ($object['ref']??null)===$defaultRef
                && in_array($metric,(array)($object['relations']??[]),true);
        }));
        if (count($matches)===1) $resolved['objects']=array_merge($resolved['objects'],$matches);
        return $resolved;
    }

    /**
     * A server-owned cohort is a default population, not a customer-selected
     * identity. When the current message contains one exact locally masked
     * person/position reference, that explicit selection replaces the cohort
     * before context merging. Named people, positions and roles inherited from
     * an earlier customer choice are never changed by this normalization.
     */
    private function replaceInheritedSystemCohortForCurrentLocalSelection(array $intent,?array $source,array $safeQuestion,array $privateKinds): array
    {
        $priorRef=$source['business_filters']['selection_ref']??null;
        $kind=$intent['object_kind']??null;$question=(string)($safeQuestion['question']??'');$matches=[];
        foreach ($privateKinds as $reference=>$privateKind) {
            if (!is_string($reference) || strpos($question,'['.$reference.']')===false) continue;
            if (($kind==='person' && in_array($privateKind,['person','position'],true))
                || ($kind==='position' && in_array($privateKind,['person','position'],true))) $matches[]=$reference;
        }
        if (!is_string($priorRef) || strpos($priorRef,'cohort:')!==0
            || ($intent['context_delta']['business_filters']??null)!=='inherit'
            || !in_array($kind,['person','position'],true) || count($matches)!==1) return $intent;
        $intent['context_delta']['business_filters']='replace';
        return $intent;
    }

    /**
     * A date-only continuation reuses every signed query in a verified
     * collection. The model has already established that the current turn
     * contains only one period requirement; this helper changes only the
     * Reader date range and never sends a first item back through binding.
     */
    private function compileCollectionPeriodContinuation(array $understanding,array $items,array $meaning,string $format,string $today): array
    {
        if ($format!=='screen' || count($items)<2 || count($items)>4) throw new RuntimeException('AI_CONTEXT_DELTA_CONFLICT');
        $plans=[];$seen=[];
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['id']??null) || !preg_match('/^q[1-4]$/D',$item['id'])
                || isset($seen[$item['id']]) || !is_array($item['query']??null)) throw new RuntimeException('AI_CONTEXT_DELTA_CONFLICT');
            $intent=AiIntentResultContract::inheritedPeriodOnlyContextIntent($understanding,$item['query'],$meaning);
            if (!is_array($intent) || !is_array($intent['periods']??null) || count($intent['periods'])!==1
                || ($item['query']['compare_range']??null)!==null) throw new RuntimeException('AI_CONTEXT_DELTA_CONFLICT');
            $terms=$this->naturalPeriodTerms($intent['periods'],$today);
            if (count($terms)!==1) throw new RuntimeException('AI_CONTEXT_DELTA_CONFLICT');
            $range=(new AiWorkflowPlanner())->normalizePeriod($terms[0],$today);
            $query=$item['query'];$query['start_date']=$range['start'];$query['end_date']=$range['end'];
            $kind=$query['business_filters']['object_kind']??'store';
            $label=is_string($item['label']??null)?$item['label']:null;
            if ($label===null || $label==='' || !is_string($kind)) throw new RuntimeException('AI_CONTEXT_DELTA_CONFLICT');
            $seen[$item['id']]=true;
            $plans[]=['id'=>$item['id'],'label'=>$label,'plan'=>[
                'workflow_code'=>'wf_performance_'.$query['query_shape'],'query'=>$query,'output_format'=>'screen',
            ]];
        }
        return ['kind'=>'plan','plan'=>['items'=>$plans],
            '_context_meaning'=>$meaning];
    }

    /**
     * Applies one model-understood ranking presentation change to every item
     * in a signed collection. Each item retains its own object, metric, date,
     * filters and authority envelope; only direction/limit are replaced.
     */
    private function compileCollectionRankingContinuation(array $understanding,array $items,array $meaning,string $format): array
    {
        if ($format!=='screen' || count($items)<2 || count($items)>4) throw new RuntimeException('AI_CONTEXT_DELTA_CONFLICT');
        $plans=[];$seen=[];
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['id']??null) || !preg_match('/^q[1-4]$/D',$item['id'])
                ||isset($seen[$item['id']]) || !is_array($item['query']??null)) throw new RuntimeException('AI_CONTEXT_DELTA_CONFLICT');
            $intent=AiIntentResultContract::inheritedRankingOnlyContextIntent($understanding,$item['query'],$meaning);
            if (!is_array($intent) || ($item['query']['query_shape']??null)!=='ranking') throw new RuntimeException('AI_CONTEXT_DELTA_CONFLICT');
            $query=$item['query'];$query['ranking']=$intent['ranking'];
            $kind=$query['business_filters']['object_kind']??'store';
            $label=is_string($item['label']??null)?$item['label']:null;
            if ($label===null || $label==='' || !is_string($kind)) throw new RuntimeException('AI_CONTEXT_DELTA_CONFLICT');
            $seen[$item['id']]=true;
            $plans[]=['id'=>$item['id'],'label'=>$label,'plan'=>[
                'workflow_code'=>'wf_performance_'.$query['query_shape'],'query'=>$query,'output_format'=>'screen',
            ]];
        }
        return ['kind'=>'plan','plan'=>['items'=>$plans],'_context_meaning'=>$meaning];
    }

    /**
     * Applies one exact, registry-owned metric view change to every signed
     * item in a verified ranking collection. The closed-metric projection has
     * already proved that the current turn changes no date, object, ranking,
     * scope or condition. Each object's active dimension contract must expose
     * the same metric before any cloned plan is returned, so an incompatible
     * item fails atomically instead of collapsing the collection to item one.
     */
    private function compileCollectionMetricContinuation(
        array $understanding,array $items,string $format,array $safeQuestion,array $capabilities,
        array $meaning,array $owner,string $id,int $generation,string $worker
    ): ?array {
        $probe=function(string $field)use($owner,$id,$generation,$worker):void {
            try {$this->runs->recordDiagnostic($owner,$id,$generation,$worker,
                ['stage'=>'analysis_binding','predicate'=>'collection_metric_continuation_skipped','field'=>$field],'collection_metric_probe');}
            catch (\Throwable $ignored) {}
        };
        if ($format!=='screen' || count($items)<2 || count($items)>4) {$probe('item_count');return null;}
        $requirements=AiIntentUnderstandingContract::requirements($understanding);
        if ($requirements===[]) {$probe('requirements');return null;}
        foreach ($requirements as $requirement) {
            $fields=(array)($requirement['fields']??[]);
            // The caller's closed registry projection proves that the current
            // sentence contains only a metric view signal. A model may still
            // copy object/ranking fields from the preceding collection into
            // its audit carrier; those cannot authorize any change here and
            // are ignored. Exclusions, conditions and unbound meaning remain
            // strict blockers because they would change the signed query.
            if (!empty($requirement['values']['metric_exclusions'])
                || in_array('unbound',$fields,true)
                || in_array('aggregate_condition',$fields,true)
                || in_array('condition_update',$fields,true)) {$probe('requirements');return null;}
        }
        $metricCodes=[];
        foreach ((array)($capabilities['metric_readiness']??[]) as $code=>$contract) {
            if (is_string($code) && is_array($contract) && ($contract['ai_query_ready']??false)===true) $metricCodes[]=$code;
        }
        $exact=\app\services\query\metric\MetricSemanticCatalog::uniqueTermInText(
            (string)($safeQuestion['question']??''),$metricCodes
        );
        if (!is_array($exact) || !is_string($exact['metric_code']??null)) {$probe('metric');return null;}
        $metric=$exact['metric_code'];$plans=[];$seen=[];
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['id']??null) || !preg_match('/^q[1-4]$/D',$item['id'])
                || isset($seen[$item['id']]) || !is_array($item['query']??null)) {
                try {$this->runs->recordDiagnostic($owner,$id,$generation,$worker,
                    ['stage'=>'analysis_binding','predicate'=>'collection_metric_continuation_unavailable','field'=>'item'],'collection_metric_probe');}
                catch (\Throwable $ignored) {}
                throw new RuntimeException('AI_CONTEXT_DELTA_CONFLICT');
            }
            $query=$item['query'];$kind=$query['business_filters']['object_kind']??null;
            if (!is_string($kind) || !isset(\app\services\ai\execution\AiCapabilityGuidanceCatalog::discover($capabilities,$kind,'ranking')[$metric])) {
                try {$this->runs->recordDiagnostic($owner,$id,$generation,$worker,
                    ['stage'=>'analysis_binding','predicate'=>'collection_metric_continuation_unavailable','field'=>'metric'],'collection_metric_probe');}
                catch (\Throwable $ignored) {}
                throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
            }
            $label=is_string($item['label']??null)?$item['label']:null;
            if ($label===null || $label==='' || ($query['query_shape']??null)!=='ranking') {
                try {$this->runs->recordDiagnostic($owner,$id,$generation,$worker,
                    ['stage'=>'analysis_binding','predicate'=>'collection_metric_continuation_unavailable','field'=>'query_shape'],'collection_metric_probe');}
                catch (\Throwable $ignored) {}
                throw new RuntimeException('AI_CONTEXT_DELTA_CONFLICT');
            }
            $query['metric_codes']=[$metric];
            // Ranking presentation columns are derived from the primary
            // metric and analytical object by AiRegisteredPlanCompiler. A
            // verified metric-only continuation changes that primary metric,
            // so the predecessor's derived list is no longer valid input.
            // Clear it here and let the registry rebuild the columns; keeping
            // stale columns would correctly trip the compiler's anti-tamper
            // check before any Reader call.
            $query['ranking_presentation_metrics']=[];
            $seen[$item['id']]=true;
            $plans[]=['id'=>$item['id'],'label'=>$label,'plan'=>[
                'workflow_code'=>'wf_performance_ranking','query'=>$query,'output_format'=>'screen',
            ]];
        }
        // Preserve only the verified presentation provenance carried by the
        // source collection. Accepted semantic requirements are not context
        // metadata and would make the execution envelope reject the plan.
        return ['kind'=>'plan','plan'=>['items'=>$plans],'_context_meaning'=>$meaning];
    }

    /**
     * Compile a mixed store answer only after the language contract has kept
     * its independent subjects separate. Every selected metric must have an
     * exact current-question registry owner and every child must independently
     * pass the ordinary workflow and capability compiler before any read.
     * This does not infer a metric, split prose, inherit an earlier selection,
     * or return a partial answer when one child is unsupported.
     */
    private function compileRegisteredStoreCollection(
        array $items,array $understanding,array $projection,array $caps,array $body,string $today,
        array $safeQuestion,?array $sourceContext,callable $checkpoint
    ): array {
        if ($sourceContext!==null || ($body['output_format']??null)!=='screen'
            || AiIntentResultContract::hasUnboundRequirement($understanding)) {
            throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
        }
        $available=array_values((array)($caps['metric_codes']??[]));
        $matches=\app\services\query\metric\MetricSemanticCatalog::registeredNonOverlappingTermsInText(
            (string)($safeQuestion['question']??''),$available
        );
        $literalCodes=array_fill_keys(array_column($matches,'metric_code'),true);
        $plans=[];
        foreach ($items as $item) {
            $checkpoint();
            $intent=$item['intent'];$shape=$intent['operation'];$codes=$intent['metric_codes'];
            foreach ($codes as $code) if (!isset($literalCodes[$code])) {
                throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
            }
            $subset=\app\services\ai\contract\AiIntentGroupContract::subset($understanding,$item['requirement_ids']);
            $owned=[];
            foreach (AiIntentUnderstandingContract::requirements($subset) as $requirement) {
                if (!in_array('metric_codes',(array)($requirement['fields']??[]),true)) continue;
                if (!empty($requirement['values']['metric_exclusions'])) throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
                foreach ((array)($requirement['values']['metric_terms']??[]) as $term) {
                    $owner=\app\services\query\metric\MetricSemanticCatalog::uniqueCodeForTerms([$term],$available);
                    if ($owner===null || !in_array($owner,$codes,true)) throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
                    $owned[$owner]=true;
                }
            }
            foreach ($codes as $code) if (!isset($owned[$code])) throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
            $shapeMetrics=\app\services\ai\execution\AiCapabilityGuidanceCatalog::discover($caps,'store',$shape);
            foreach ($codes as $code) if (!isset($shapeMetrics[$code])) throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
            $child=$projection;
            $child['signals']=array_merge($codes,[$shape]);
            $child['date_terms']=$this->naturalPeriodTerms($intent['periods'],$today);
            $child['date_grouping_ambiguous']=false;
            $child['blocking_reason']=null;$child['unresolved_condition']=false;
            $child['semantic_intent']['constraints']=[];
            $compiled=(new AiWorkflowPlanner())->compile($child,[
                'decision'=>'query','query_shape'=>$shape,'metric_codes'=>$codes,
                'ranking'=>$intent['ranking'],'object_kind'=>'store',
            ],$caps,'screen',$today);
            if (($compiled['kind']??null)!=='plan') throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
            $label=\app\services\query\metric\MetricDefinitionRegistry::overviewObjectLabel('store');
            $plans[]=['id'=>$item['id'],'label'=>$label.($shape==='ranking'?'排行':'明细'),'plan'=>$compiled['plan']];
        }
        return ['kind'=>'plan','plan'=>['items'=>$plans],
            '_context_meaning'=>['presentation_origin'=>'customer_or_verified_context']];
    }

    /**
     * Builds a bounded set of independent ranking plans from one accepted
     * grouped understanding. It deliberately accepts only the shape that
     * can use the existing dimension planner unchanged: fresh screen-only
     * rankings with no selected identity or scope mutation. Other compound
     * requests stop before any Reader call rather than returning a subset.
     */
    private function compileDimensionRankingCollection(
        array $items,array $groupedUnderstanding,array $firstIntent,array $firstCompiled,array $projection,array $caps,array $body,string $today,
        array $safeQuestion,array $bindingSummaries,array $configuration,callable $checkpoint,array $owner,string $id,int $generation,string $worker,
        $sourceQuery,$localTerm,bool $currentStoreRequested
    ): array {
        if ($sourceQuery!==null || $localTerm!==null || $currentStoreRequested || ($body['output_format']??null)!=='screen'
            || count($items)<2 || count($items)>4) {
            $field=$sourceQuery!==null?'source_query':($localTerm!==null?'local_term':($currentStoreRequested?'current_store'
                :(($body['output_format']??null)!=='screen'?'output_format':'item_count')));
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'collection_plan_shape_unavailable',['field'=>$field]);
            throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
        }
        $plans=[];$guidance=[];$firstPeriods=$firstIntent['periods']??[];
        foreach ($items as $index=>$item) {
            if (!is_array($item) || !is_string($item['id']??null) || !preg_match('/^q[1-4]$/D',$item['id'])
                || !is_array($item['requirement_ids']??null) || !is_array($item['intent']??null)) {
                $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'collection_item_shape_unavailable',['field'=>'item']);
                throw new RuntimeException('AI_MODEL_INPUT_INVALID');
            }
            $understanding=\app\services\ai\contract\AiIntentGroupContract::subset($groupedUnderstanding,$item['requirement_ids']);
            // Use the same per-item contract result for every collection
            // member. `firstIntent` exists only because the ordinary planner
            // needs one representative before collection compilation; it must
            // never overwrite the first member after the group was validated.
            $intent=$item['intent'];
            $intentField=($intent['operation']??null)!=='ranking'?'operation'
                :(!empty($intent['needs_metric_choice'])?'metric_choice'
                :(in_array($intent['object_kind']??null,['person','store','unknown'],true)?'object_kind'
                :(($intent['object_term']??'')!==''?'object_selection'
                :(($intent['unresolved_fragments']??[])!==[]?'unresolved_fragments'
                :(($intent['scope']??'unspecified')!=='unspecified'?'scope'
                :(($intent['aggregate_condition']??null)!==null?'aggregate_condition'
                :(($intent['periods']??[])!==$firstPeriods?'periods'
                :(count((array)($intent['metric_codes']??[]))!==1?'metric_count':null))))))));
            if ($intentField!==null) {
                $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'collection_item_intent_unavailable',['field'=>$intentField]);
                throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
            }
            // The group envelope has already passed a typed, per-item binding
            // contract against its own accepted requirement subset. The first
            // item also crosses the ordinary independent semantic review gate
            // before reaching this helper. Replaying that model review for
            // every sibling turns one customer request into six or more model
            // stages when a format repair was needed, breaching the fixed run
            // budget and causing a generic failure. Keep siblings at the same
            // registry and contract boundary rather than silently exceeding
            // that operational limit.
            $metrics=$intent['object_kind']==='business_date'
                ? \app\services\ai\execution\AiCapabilityGuidanceCatalog::discover($caps,'store','trend')
                : \app\services\ai\execution\AiCapabilityGuidanceCatalog::discover($caps,$intent['object_kind'],'ranking');
            if (!$metrics) {
                $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'collection_dimension_unavailable',['field'=>'metric']);
                throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
            }
            $compiled=$index===0?$firstCompiled:(new \app\services\ai\execution\AiDimensionGuidancePlanner())->start(
                $intent['object_kind'],$intent,$projection,$metrics,'screen',$today
            );
            if (($compiled['kind']??null)!=='plan' || !is_array($compiled['plan']??null)) {
                $label=$this->analysisObjectLabel($intent['object_kind']);
                if (($compiled['kind']??null)==='clarification' && is_string($label) && $label!==''
                    && array_column((array)($compiled['fields']??[]),'key')===['start_date','end_date']
                    && is_array($compiled['dimension_state']??null)) {
                    $guidance[]=['id'=>$item['id'],'label'=>$label.'排行','dimension_state'=>$compiled['dimension_state']];
                    continue;
                }
                $field=$index===0?'first_plan':'sibling_plan';
                $guideFields=(array)($compiled['fields']??[]);
                $guideKey=is_array($guideFields[0]??null)?($guideFields[0]['key']??null):null;
                if (is_string($guideKey) && in_array($guideKey,['dimension_metric','start_date','dimension_direction','dimension_limit'],true)) {
                    $field=$guideKey;
                }
                $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'collection_item_plan_unavailable',['field'=>$field]);
                throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
            }
            $label=$this->analysisObjectLabel($intent['object_kind']);
            if (!is_string($label) || $label==='') {
                $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'collection_label_unavailable',['field'=>'object_kind']);
                throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
            }
            $plans[]=['id'=>$item['id'],'label'=>$label.'排行','plan'=>$compiled['plan']];
        }
        if ($guidance!==[]) {
            if ($plans!==[] || count($guidance)!==count($items)) {
                $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'collection_item_plan_unavailable',['field'=>'mixed_guidance']);
                throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
            }
            return (new \app\services\ai\execution\AiRankingCollectionGuidancePlanner())->start($guidance);
        }
        if (count($plans)!==count($items)) {
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'collection_plan_count_unavailable',['field'=>'item_count']);
            throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
        }
        return ['kind'=>'plan','plan'=>['items'=>$plans]];
    }

    /** Shared protocol dimensions have labels even when they are not entity registries. */
    private function analysisObjectLabel(string $objectKind): ?string
    {
        return $objectKind==='business_date'
            ? '日期'
            : \app\services\query\metric\MetricDefinitionRegistry::overviewObjectLabel($objectKind);
    }

    /**
     * Compile one or more registry-closed extrema through the existing Reader
     * plans. This method selects no business default: metric, object, period
     * and direction all come from exact admitted carriers.
     */
    private function compileExactRegisteredRankingCollection(
        string $question,array $objectVocabulary,array $capabilities,string $format,string $today,
        ?string &$reason=null,?array $sourceContext=null,array $context=[]
    ): ?array {
        // The ordinary model path intentionally starts with a neutral empty
        // projection. This closed admission may read only the shared calendar
        // grammar locally; its own residue gate below still owns the complete
        // sentence and rejects every unexplained business instruction.
        $admission=new \app\services\ai\semantic\AiExactRankingCollectionAdmission();
        // Date parsing and closed-ranking admission must see the same
        // sentence. A leading conversational connector is grammar only; if
        // it remains before date parsing, “那这个月” falsely loses its one
        // calendar carrier and needlessly falls through to the model.
        $normalizedQuestion=$admission->normalizeLeadingDiscourseConnector($question);
        $population=$admission->authorizedPopulation($normalizedQuestion,$objectVocabulary);
        $normalizedQuestion=$population['question'];
        $scopedStoreId=null;
        // This is a narrow fast path for a complete ranking inside one named
        // store. Exact current catalogue membership is required before the
        // store phrase is removed; the remaining entire sentence must still
        // pass the existing metric/object/date/direction residue proof.
        if (!$population['all_authorized'] && preg_match('/(?:这个|这家)门店/u',$normalizedQuestion)===1) {
            $scope=$admission->namedStoreScope($normalizedQuestion,$this->authorizedStoreObjects($context));
            if ($scope!==null) {
                $normalizedQuestion=$scope['question'];
                $scopedStoreId=$scope['store_id'];
            }
        }
        $dateProjection=(new \app\services\ai\semantic\AiSemanticIntentParser())->parse($normalizedQuestion);
        $dateTermCount=count((array)($dateProjection['date_terms']??[]));
        // Without a stated period, an otherwise closed ranking can still
        // naturally inherit the previous reporting window. Only an explicit
        // current period proves this independent-topic shortcut is safe.
        if ($sourceContext!==null && $dateTermCount===0) {
            $reason='context_requires_understanding';return null;
        }
        if ($format!=='screen' || $dateTermCount>1
            || !empty($dateProjection['date_grouping_ambiguous'])) {$reason='date_or_format';return null;}
        $defaultRankObjects=[];
        foreach ((array)($capabilities['metric_readiness']??[]) as $code=>$contract) {
            if (!is_string($code) || !is_array($contract)) continue;
            $defaultRankObjects[$code]=array_values((array)($contract['analysis_default_rank_object_kinds']??[]));
        }
        // A ranked member set followed by its details is one authorised
        // population workflow, not an invitation for the model to invent a
        // second metric. Admit it only through the same registry defaults and
        // exact residue boundary as other closed extrema, then let the normal
        // population executor read details for those verified member IDs.
        $memberPopulationDetail=$admission->matchPopulationDetail(
            $normalizedQuestion,$objectVocabulary,array_values((array)($capabilities['metric_codes']??[])),
            $defaultRankObjects,$dateProjection['semantic_intent']['rank_limit']??null
        );
        // An admitted sentence is self-contained: its plan uses only current
        // wording and registry, never any predecessor's scope or period.
        $memberDetailRequest=$memberPopulationDetail['detail']??null;
        $items=$memberPopulationDetail!==null?[$memberPopulationDetail['ranking']]:$admission->match(
            $normalizedQuestion,$objectVocabulary,array_values((array)($capabilities['metric_codes']??[])),$defaultRankObjects
        );
        if ($items===null) {$reason='semantic_no_match';return null;}
        $reusedContext=false;
        $priorQuery=is_array($sourceContext['query']??null)?$sourceContext['query']:null;
        if ($priorQuery!==null && !$population['all_authorized'] && $scopedStoreId===null
            && (array)($priorQuery['store_ids']??[])!==[]) {
            // A broad ranking does not authorize dropping a previously
            // selected store. The model must decide whether this is an
            // explicit scope change before any query can expand that range.
            $reason='context_scope_requires_understanding';return null;
        }
        if (count($items)===1 && $priorQuery!==null && !$population['all_authorized']
            && $scopedStoreId===null
            && \app\services\query\metric\MetricSemanticCatalog::uniqueTermInText(
                $normalizedQuestion,array_values((array)($capabilities['metric_codes']??[]))
            )===null) {
            $priorMetrics=(array)($priorQuery['metric_codes']??[]);
            $priorFilters=(array)($priorQuery['business_filters']??[]);
            $priorKind=(string)($priorFilters['object_kind']??'store');
            $nextKind=$items[0]['object_kind'];
            // Reuse only one verified store-level measurement between store
            // and business-date rankings. Conditions, private selections and
            // other subject families need full language understanding.
            if (count($priorMetrics)===1 && is_string($priorMetrics[0])
                && in_array($priorKind,['store','business_date'],true)
                && in_array($nextKind,['store','business_date'],true)
                && ($priorFilters===[] || $priorFilters===['object_kind'=>$priorKind])
                && empty($priorQuery['aggregate_condition']) && empty($priorQuery['condition_set'])
                && empty($priorQuery['compare_range'])) {
                $compatible=$nextKind==='business_date'
                    ? \app\services\ai\execution\AiCapabilityGuidanceCatalog::discover($capabilities,'store','trend')
                    : \app\services\ai\execution\AiCapabilityGuidanceCatalog::discover($capabilities,$nextKind,'ranking');
                if (isset($compatible[$priorMetrics[0]])) {
                    $items[0]['metric_code']=$priorMetrics[0];
                    $reusedContext=true;
                }
            }
        }
        $plans=[];
        foreach ($items as $index=>$item) {
            $objectKind=$item['object_kind'];
            $candidates=$objectKind==='business_date'
                ? \app\services\ai\execution\AiCapabilityGuidanceCatalog::discover($capabilities,'store','trend')
                : \app\services\ai\execution\AiCapabilityGuidanceCatalog::discover($capabilities,$objectKind,'ranking');
            if (!isset($candidates[$item['metric_code']])) {$reason='metric_capability';return null;}
            $intent=['operation'=>'ranking','metric_codes'=>[$item['metric_code']],'action_codes'=>[],
                'needs_metric_choice'=>false,'ranking'=>['direction'=>$item['direction'],'limit'=>$item['limit']]];
            try {
                $compiled=(new \app\services\ai\execution\AiDimensionGuidancePlanner())->start(
                    $objectKind,$intent,$dateProjection,$candidates,'screen',$today
                );
            } catch (\Throwable $ignored) {$reason='plan_compile';return null;}
            if (($compiled['kind']??null)!=='plan' || !is_array($compiled['plan']??null)) {$reason='plan_shape';return null;}
            // The Reader rechecks the authorized store ID against current
            // permissions; the lexical fast path never widens an old scope.
            if ($scopedStoreId!==null) $compiled['plan']['query']['store_ids']=[$scopedStoreId];
            $metricContract=$capabilities['metric_readiness'][$item['metric_code']]??null;
            if (is_array($metricContract)
                &&($metricContract['filter_grain']??null)===$objectKind
                &&($metricContract['business_filters']??null)===[]) {
                // Ranking a metric at its own registered fact grain needs no
                // analytical-dimension filter. Keeping a redundant
                // object_kind filter would falsely route the base grain
                // through the optional-dimension compiler and reject an
                // otherwise registered query. This rule is contract-driven
                // for every future base object, not a store phrase exception.
                $compiled['plan']['query']['business_filters']=[];
            } elseif (is_array($metricContract) && ($metricContract['business_filters']??null)===['selection_ref']
                && is_string($metricContract['analysis_default_selection_ref']??null)) {
                // A closed broad ranking still needs the source-owned fact
                // cohort; an analytical noun alone grants no personnel scope.
                $compiled['plan']['query']['business_filters']=['object_kind'=>$objectKind,
                    'selection_ref'=>$metricContract['analysis_default_selection_ref']];
            }
            if ($objectKind==='person') {
                // An exact personnel-role word is a cohort constraint, not a
                // synonym for every person. Resolve it from the same live,
                // permission-bound role catalogue used by the ordinary model
                // path. Remove a proved measurement span first: “销售人业绩”
                // names a metric, not necessarily a salesperson cohort.
                $roleMatches=[];
                $metricTerm=\app\services\query\metric\MetricSemanticCatalog::uniqueTermInText(
                    $normalizedQuestion,[$item['metric_code']]
                );
                $roleText=$metricTerm!==null
                    ?str_replace($metricTerm['term'],' ',$normalizedQuestion):$normalizedQuestion;
                foreach (\app\services\query\metric\MetricDefinitionRegistry::personnelRoleAliases() as $ref=>$aliases) {
                    foreach ($aliases as $alias) {
                        if (mb_strpos($roleText,$alias)!==false) $roleMatches[$ref]=true;
                    }
                }
                if (count($roleMatches)>1) {$reason='ambiguous_personnel_role';return null;}
                if ($roleMatches!==[]) {
                    $roleRef=(string)array_key_first($roleMatches);
                    $roleAvailable=false;
                    foreach ($this->personnelObjects($context)->catalog($item['metric_code'])['objects'] as $object) {
                        if (($object['ref']??null)===$roleRef && ($object['kind']??null)==='position') $roleAvailable=true;
                    }
                    if (!$roleAvailable) {$reason='personnel_role_unavailable';return null;}
                    $compiled['plan']['query']['business_filters']=['object_kind'=>'person',
                        'selection_ref'=>$roleRef];
                }
            }
            $label=$this->analysisObjectLabel($objectKind);
            if (!is_string($label) || $label==='') {$reason='object_label';return null;}
            $plans[]=['id'=>'q'.($index+1),'label'=>$label.'排行','plan'=>$compiled['plan']];
        }
        $reason=null;
        $plan=count($plans)===1?$plans[0]['plan']:['items'=>$plans];
        $compiled=['kind'=>'plan','plan'=>$plan,
            '_context_meaning'=>$reusedContext?(array)($sourceContext['meaning']??[]):
                ['presentation_origin'=>'customer_or_verified_context']];
        if ($reusedContext) $compiled['_context_reused']=true;
        if (is_array($memberDetailRequest)) {
            $kind=$memberPopulationDetail['ranking']['object_kind'];
            if ($kind!=='member') $memberDetailRequest['_ranking_object_kind']=$kind;
            $compiled=$this->attachPopulationDetail($compiled,$memberDetailRequest);
        }
        return $compiled;
    }

    private function resolveOverviewMetrics(array $intent,array $capabilities): array
    {
        if (empty($intent['initial_observation']) || ($intent['operation']??null)!=='summary'
            || !empty($intent['needs_metric_choice'])) return $intent;
        $records=AiOverviewMetricResolver::resolve($capabilities,(string)($intent['object_kind']??''));
        // An overview profile is optional.  Store/project/product profiles can
        // expand into several registry-owned facts, including when a named
        // object narrows their scope.  A person or member without such a
        // profile continues through its normal selected-object guidance;
        // absence of an overview declaration is not itself a failed query.
        if (count($records)<2) return $intent;
        $intent['metric_codes']=array_column($records,'metric_code');
        return $intent;
    }

    /** Admit only a structurally complete store comparison; named measurements
     * and broad overview profiles both come from the frozen metric registry. */
    private function compileRegisteredComparison(
        string $question,array $capabilities,string $format,string $today
    ): ?array {
        // This parser provides only semantic slots for admission. It cannot
        // supply a metric, formula or authority; those remain registry-owned.
        $projection=(new AiModelInputProjector())->project($question);
        $signals=(array)($projection['signals']??[]);
        $dateTerms=(array)($projection['date_terms']??[]);
        $availableCodes=array_keys((array)($capabilities['metric_readiness']??[]));
        $allowed=array_merge($availableCodes,['ambiguous_metric','comparison','THIS_MONTH','LAST_MONTH','TODAY','YESTERDAY','DAY_BEFORE_YESTERDAY']);
        if (!in_array('comparison',$signals,true)||array_diff($signals,$allowed)!==[]||count($dateTerms)!==2
            ||!empty($projection['date_grouping_ambiguous'])||!empty($projection['unresolved_condition'])
            ||!empty($projection['semantic_intent']['constraints'])) return null;
        $stated=\app\services\query\metric\MetricSemanticCatalog::registeredTermsInText($question,$availableCodes);
        if (in_array('ambiguous_metric',$signals,true)) {
            // Only an explicit broad operating expression may expand into a
            // profile; an ambiguous but narrower “业绩” does not silently
            // become a full经营概览, and named metrics stay on their own path.
            if ($stated!==[]||preg_match('/经营(?:情况|概览|得?怎么样|如何)/u',$question)!==1) return null;
            $metrics=array_column(AiOverviewMetricResolver::resolve($capabilities,'store'),'metric_code');
        } else {
            $metrics=array_values(array_intersect($signals,$availableCodes));
            $statedCodes=array_values(array_unique(array_column($stated,'metric_code')));
            if ($metrics===[]||array_diff($metrics,$statedCodes)!==[]||array_diff($statedCodes,$metrics)!==[]) return null;
        }
        // A named comparison may contain one metric; only a broad overview
        // needs multiple registry measurements to justify profile expansion.
        $minimum=in_array('ambiguous_metric',$signals,true)?2:1;
        if (count($metrics)<$minimum||count($metrics)>AiOverviewMetricResolver::MAX_METRICS) return null;
        foreach ($metrics as $metric) {
            if (!in_array($metric,$capabilities['metric_codes']??[],true)
                ||($capabilities['metric_readiness'][$metric]['filter_grain']??null)!=='store'
                ||!in_array('comparison',(array)($capabilities['metric_readiness'][$metric]['query_shapes']??[]),true)) return null;
        }
        $planner=new AiWorkflowPlanner();$periods=[];
        foreach ($dateTerms as $term) {
            $range=$planner->normalizePeriod($term,$today);
            $periods[]=['code'=>'EXPLICIT','start'=>$range['start'],'end'=>$range['end']];
        }
        $projection['signals']=array_merge($metrics,['comparison']);
        $projection['date_terms']=$periods;
        $compiled=$planner->compile($projection,[
            'decision'=>'query','query_shape'=>'comparison','metric_codes'=>$metrics,'object_kind'=>'store',
        ],$capabilities,$format,$today);
        if (($compiled['kind']??null)!=='plan') return null;
        $compiled['_context_meaning']=['presentation_origin'=>'customer_or_verified_context'];
        return $compiled;
    }

    /**
     * Compiles a complete model-understood store overview from the source
     * registry. One-period summaries and two-period comparisons share this
     * admission: explicit metrics, grouping, selection, ranking, conditions,
     * exclusions and authority-bearing predecessors stay on their own path.
     */
    private function compileRegisteredOpenOverview(
        array $understanding,array $safeQuestion,?array $sourceQuery,array $capabilities,string $format,string $today
    ): ?array {
        $requestKind=$understanding['request_kind']??null;
        $shape=$requestKind==='open_overview'?'summary':($requestKind==='overview_comparison'?'comparison':null);
        if (($understanding['status']??null)!=='understood' || $shape===null
            ||array_key_exists('groups',$understanding)) return null;
        if ($sourceQuery!==null && (!empty($sourceQuery['store_ids'])
            ||!empty($sourceQuery['business_filters'])
            ||!empty($sourceQuery['aggregate_condition'])
            ||!empty($sourceQuery['condition_set'])
            ||!empty($sourceQuery['compare_range'])
            ||!empty($sourceQuery['ranking']))) return null;

        $current=(string)($safeQuestion['question']??'');
        if ($current==='') return null;
        $allowed=['metric_codes','object_kind','object_relation','operation','periods','scope'];
        $valuesByField=[];
        foreach (AiIntentUnderstandingContract::requirements($understanding) as $requirement) {
            $fields=(array)($requirement['fields']??[]);$values=(array)($requirement['values']??[]);
            if ($fields===[] || array_diff($fields,$allowed)!==[] || !empty($values['metric_exclusions'])) return null;
            // Full current-turn grounding prevents a marker copied from prior
            // context from clearing a verified object or business filter.
            foreach ((array)($requirement['evidence']??[]) as $evidence) {
                if (($evidence['message_id']??null)!=='current'
                    ||!is_string($evidence['quote']??null)
                    ||mb_strpos($current,$evidence['quote'],0,'UTF-8')===false) return null;
            }
            foreach ($fields as $field) {
                // metric_codes names the later binding slot; the first-stage
                // carrier intentionally contains customer terms, never codes.
                $valueKey=$field==='metric_codes'?'metric_terms':$field;
                if (!array_key_exists($valueKey,$values)) {
                    // The comparison marker is a complete broad-overview
                    // semantic commitment. Some models express the same
                    // comparison as separate grounded time/operation clauses
                    // without repeating its broad metric carrier.
                    if ($shape==='comparison' && $field==='metric_codes') continue;
                    return null;
                }
                $valuesByField[$field][]=$values[$valueKey];
            }
        }
        if ($shape==='summary' && (count($valuesByField['metric_codes']??[])!==1
            ||count($valuesByField['object_kind']??[])!==1
            ||count($valuesByField['object_relation']??[])!==1
            ||count($valuesByField['operation']??[])!==1)) return null;
        // A typed comparison may be split into several grounded clauses;
        // still reject any explicit object/operation that conflicts with the
        // marker and never guess a missing second period.
        foreach (['object_kind'=>'store','object_relation'=>'analysis','operation'=>$shape] as $field=>$expected) {
            foreach ((array)($valuesByField[$field]??[]) as $value) if ($value!==$expected) return null;
        }
        $periods=[];
        foreach ((array)($valuesByField['periods']??[]) as $group) {
            if (!is_array($group)) return null;
            foreach ($group as $period) $periods[]=$period;
        }
        if (count($periods)!==($shape==='comparison'?2:1)) return null;
        foreach ((array)($valuesByField['scope']??[]) as $scope) {
            if (!in_array($scope,['current_store','unspecified'],true)) return null;
        }
        $terms=[];
        foreach ((array)($valuesByField['metric_codes']??[]) as $group) {
            if (!is_array($group)) return null;
            foreach ($group as $term) {
                if (!is_string($term)||$term===''||mb_strpos($current,$term,0,'UTF-8')===false) return null;
                $terms[]=$term;
            }
        }
        if ($shape==='summary' && count($terms)!==1) return null;
        // A source-registered measurement is a specific metric request, even
        // when the model mislabeled it as an overview. Never broaden it into
        // the profile merely to save a model call.
        $availableCodes=array_keys((array)($capabilities['metric_readiness']??[]));
        if (\app\services\query\metric\MetricSemanticCatalog::registeredTermsInText($current,$availableCodes)!==[]) return null;

        $records=AiOverviewMetricResolver::resolve($capabilities,'store');
        if (count($records)<2 || count($records)>AiOverviewMetricResolver::MAX_METRICS) return null;
        $metrics=array_column($records,'metric_code');
        if (count(array_unique($metrics))!==count($metrics)) return null;
        foreach ($metrics as $metric) {
            $contract=$capabilities['metric_readiness'][$metric]??null;
            if (!in_array($metric,$capabilities['metric_codes']??[],true)
                ||!is_array($contract)||!in_array($shape,(array)($contract['query_shapes']??[]),true)) return null;
        }

        $planner=new AiWorkflowPlanner();
        $dateTerms=[];
        foreach ($periods as $period) {
            $range=$planner->normalizeNaturalPeriod($period,$today);
            $dateTerms[]=['code'=>'EXPLICIT','start'=>$range['start'],'end'=>$range['end']];
        }
        $projection=['signals'=>array_merge($metrics,[$shape]),'date_terms'=>$dateTerms,
            'date_grouping_ambiguous'=>false,'unresolved_condition'=>false,'semantic_intent'=>['constraints'=>[]]];
        $compiled=$planner->compile($projection,[
            'decision'=>'query','query_shape'=>$shape,'metric_codes'=>$metrics,'object_kind'=>'store',
        ],$capabilities,$format,$today);
        if (($compiled['kind']??null)!=='plan') return null;
        $compiled['_context_meaning']=['presentation_origin'=>'customer_or_verified_context'];
        return $compiled;
    }

    /**
     * Decide only whether the already-understood shape may receive the one
     * model-owned overview recovery.  Natural-language classification remains
     * in the understanding/review passes; registry metadata still owns which
     * metrics appear in that overview.
     */
    private function requiresOpenOverviewRecovery(array $understanding,array $intent,array $capabilities,bool $contextBindingReused=false): bool
    {
        if ($contextBindingReused) return false;
        if (!$this->allowsOpenOverviewRecovery($understanding,$capabilities)) return false;
        $registeredMetrics=\app\services\ai\execution\AiCapabilityGuidanceCatalog::discover($capabilities,'store');
        $selectedMetrics=array_values(array_filter((array)($intent['metric_codes']??[]),static function($code){
            return is_string($code) && preg_match('/^[a-z][a-z0-9_]{1,63}$/D',$code);
        }));
        // A bound, executable metric is already an intentional answer angle.
        // Missing or non-executable candidates are not: they may be repaired
        // from the accepted semantic shape before a selector is shown.
        return array_intersect($selectedMetrics,array_keys($registeredMetrics))===[];
    }

    /** Checks whether the accepted semantics, not a candidate label, permit an overview. */
    private function allowsOpenOverviewRecovery(array $understanding,array $capabilities): bool
    {
        if (($understanding['status']??null)!=='understood') return false;
        foreach (AiIntentUnderstandingContract::requirements($understanding) as $requirement) {
            $fields=(array)($requirement['fields']??[]);
            $values=(array)($requirement['values']??[]);
            // The accepted customer meaning may contain only an observation
            // request and its time/context. A name, object selection,
            // comparison, ranking, condition, exclusion or other requirement
            // has to keep its controlled clarification instead. Binding may
            // misclassify such an observation as needing a selector, so its
            // internal selection flag and inferred condition are deliberately
            // not admission criteria here. This remains structural: no
            // customer words, metric labels or codes are read here.
            if (array_diff($fields,['metric_codes','object_kind','object_relation','operation','periods','scope'])
                || in_array('unbound',$fields,true)
                || in_array('aggregate_condition',$fields,true)
                || in_array('result_reference',$fields,true)
                || !empty($values['metric_exclusions'])) return false;
            // Providers may already publish some of the complete overview
            // carriers. Admit only the neutral store-summary values; a selected
            // object or another response form must retain ordinary review.
            if (array_key_exists('object_kind',$values) && $values['object_kind']!=='store') return false;
            if (array_key_exists('object_relation',$values) && $values['object_relation']!=='analysis') return false;
            if (array_key_exists('operation',$values) && $values['operation']!=='summary') return false;
            if (array_key_exists('scope',$values) && !in_array($values['scope'],['current_store','authorized','unspecified'],true)) return false;
        }
        return count(\app\services\ai\execution\AiOverviewMetricResolver::resolve($capabilities,'store'))>=2;
    }

    /** Builds the server-owned response shapes for one registered object. */
    private function registeredOperationOptions(array $capabilities,string $objectKind): array
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$objectKind)) return [];
        $options=[];
        foreach (\app\services\ai\execution\AiCapabilityGuidanceCatalog::discover($capabilities,$objectKind) as $metric=>$candidate) {
            $shapes=array_values(array_intersect(['summary','breakdown','trend','ranking','comparison'],(array)($candidate['query_shapes']??[])));
            if ($shapes) $options[$metric]=$shapes;
        }
        ksort($options);
        return $options;
    }

    /** Builds only metric choices executable for the object and response shape. */
    private function registeredMetricOptions(array $capabilities,array $summaries,string $objectKind,?string $operation): array
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$objectKind)) return [];
        if ($operation!==null && !in_array($operation,['summary','breakdown','trend','ranking','comparison'],true)) return [];
        $labels=[];
        foreach ($summaries as $summary) {
            if (is_string($summary['metric_code']??null) && is_string($summary['name']??null)) {
                $labels[$summary['metric_code']]=$summary['name'];
            }
        }
        $options=[];
        foreach (\app\services\ai\execution\AiCapabilityGuidanceCatalog::discover($capabilities,$objectKind,$operation) as $metric=>$candidate) {
            if (!isset($labels[$metric]) || !($candidate['query_shapes']??[])) continue;
            $options[]=['value'=>'metric:'.$metric,'label'=>$labels[$metric]];
        }
        return $options;
    }

    /**
     * Builds a period-only semantic record only for a syntactically closed
     * continuation of a verified query. It deliberately knows no business
     * vocabulary, metric, object or ranking: those always remain model-owned.
     */
    private function localVerifiedPeriodOnlyUnderstanding(array $outbound,array $safe,string $today): ?array
    {
        if (!empty($safe['local_conditions'] ?? []) || !is_string($outbound['question'] ?? null)) return null;
        $projection=(new \app\services\ai\semantic\AiSemanticIntentParser())->parse($outbound['question']);
        $dateTerms=(array)($projection['date_terms']??[]);
        $dateSignals=(array)($projection['signals']??[]);
        $allowedSignals=['TODAY'=>true,'YESTERDAY'=>true,'DAY_BEFORE_YESTERDAY'=>true,'THIS_MONTH'=>true,'LAST_MONTH'=>true];
        if (count($dateTerms)!==1 || ($projection['date_grouping_ambiguous']??true)
            || ($projection['unresolved_condition']??true)
            || (array)($projection['semantic_intent']['constraints']??[])!==[]
            || array_diff($dateSignals,array_keys($allowedSignals))!==[]) return null;
        $range=(new \app\services\ai\execution\AiWorkflowPlanner())->normalizePeriod($dateTerms[0],$today);
        $candidate=[
            'goal'=>'change verified query period',
            'requirements'=>[[
                'id'=>'r1','meaning'=>'change verified query period','fields'=>['periods'],
                'values'=>['periods'=>[['kind'=>'date_range','start'=>$range['start'],'end'=>$range['end']]]],
                // The complete current message is the evidence boundary. A
                // short date token can never authorize replay of a query if
                // another current business instruction accompanies it.
                'evidence'=>[['message_id'=>'current','quote'=>$outbound['question']]],
            ]],
            'status'=>'understood',
        ];
        return \app\services\ai\contract\AiIntentUnderstandingContract::normalize($candidate,$outbound);
    }

    /**
     * Builds a ranking-presentation-only semantic record for a verified
     * ranking. The central parser must close the whole sentence with one
     * direction and one explicit row limit; a metric, date, object, condition
     * or unresolved fragment keeps the request on the ordinary model path.
     * This therefore generalizes natural forms such as "top three" without
     * mapping a business phrase to a metric or analytical object.
     */
    private function localVerifiedRankingOnlyUnderstanding(array $outbound,array $safe,?array $sourceQuery,array $sourceCollection=[]): ?array
    {
        if (!empty($safe['local_conditions']??[]) || !is_string($outbound['question']??null)) return null;
        $projection=(new \app\services\ai\semantic\AiSemanticIntentParser())->parse($outbound['question']);
        $signals=(array)($projection['signals']??[]);
        $allowed=['ranking'=>true,'rank_top'=>true,'rank_bottom'=>true,'top_5'=>true,'bottom_5'=>true];
        $limit=$projection['semantic_intent']['rank_limit']??null;
        $top=in_array('rank_top',$signals,true)||in_array('top_5',$signals,true);
        $bottom=in_array('rank_bottom',$signals,true)||in_array('bottom_5',$signals,true);
        // A classifier such as “家” explicitly names a store population.
        // It may reuse a signed store ranking, but must not silently turn a
        // person or product ranking into stores by copying the old metric.
        $objectHint=$projection['semantic_intent']['rank_object_hint']??null;
        if ($objectHint!==null) {
            $queries=$sourceCollection!==[]?$sourceCollection:[['query'=>$sourceQuery]];
            foreach ($queries as $query) {
                $signedQuery=$query['query']??null;
                if (!is_array($signedQuery)
                    || (($signedQuery['business_filters']['object_kind']??'store')!==$objectHint)) return null;
            }
        }
        // In a verified ranking context, one closed ordinal shorthand such
        // as “前三名” already carries direction plus row count. It need not
        // contain a second literal “排行”; the strict signal allow-list below
        // still rejects any new metric, object, period or filter instruction.
        if ($top===$bottom || !is_int($limit) || $limit<1 || $limit>50
            || ($projection['date_terms']??[])!==[] || ($projection['date_grouping_ambiguous']??true)
            || ($projection['unresolved_condition']??true)
            || (array)($projection['semantic_intent']['constraints']??[])!==[]
            || array_diff($signals,array_keys($allowed))!==[]) return null;
        $candidate=[
            'goal'=>'change verified ranking presentation',
            'requirements'=>[[
                'id'=>'r1','meaning'=>'change verified ranking presentation','fields'=>['operation','ranking'],
                'values'=>['operation'=>'ranking','ranking'=>['direction'=>$top?'top':'bottom','limit'=>$limit]],
                'evidence'=>[['message_id'=>'current','quote'=>$outbound['question']]],
            ]],
            'status'=>'understood',
        ];
        return \app\services\ai\contract\AiIntentUnderstandingContract::normalize($candidate,$outbound);
    }

    /** A closed ordinal-detail follow-up needs no fresh language model call:
     * its only new input is a display position. Identity is resolved solely
     * from the permission-replayed signed ranking view; mixed business words,
     * private references and out-of-range positions stay on ordinary
     * understanding. A missing board remains accepted ordinal meaning so
     * the shared clarification gate can ask without a model guessing it. */
    private function localVerifiedOrdinalDetailUnderstanding(array $outbound,array $safe,array $sourceContext): ?array
    {
        if (!empty($safe['local_conditions']??[]) || !is_string($outbound['question']??null)
            ||($sourceContext['query']['query_shape']??null)!=='ranking'
            ||!is_array($sourceContext['view']??null) || !empty($sourceContext['items']??[])) return null;
        $closed=preg_replace('/[\s，,。？！?!]/u','',$outbound['question']);
        if (!is_string($closed) || !preg_match('/^第([一二三四五六七八九十]|[1-9][0-9]?)(?:名|位|个)(?:的)?(?:具体)?(?:看(?:看)?|详情|详细(?:情况)?|展开(?:看看)?)$/u',$closed,$match)) return null;
        $chinese=['一'=>1,'二'=>2,'三'=>3,'四'=>4,'五'=>5,'六'=>6,'七'=>7,'八'=>8,'九'=>9,'十'=>10];
        $ordinal=$chinese[$match[1]]??(ctype_digit($match[1])?(int)$match[1]:0);
        if ($ordinal<1 || $ordinal>50) return null;
        $detail=['view'=>'summary','target'=>'single','ordinal'=>$ordinal];
        try {
            (new \app\services\ai\context\ObjectDetailContinuationResolver())->resolve(
                $sourceContext['query'],$sourceContext['view'],$detail
            );
        } catch (\RuntimeException $error) {
            if ($error->getMessage()!=='AI_OBJECT_DETAIL_SELECTION_REQUIRED') return null;
        }
        return \app\services\ai\contract\AiIntentUnderstandingContract::normalize([
            'goal'=>'inspect a verified ranking position','status'=>'understood','requirements'=>[[
                'id'=>'r1','meaning'=>'inspect the stated displayed position','fields'=>['object_detail'],
                'values'=>['object_detail'=>$detail],
                'evidence'=>[['message_id'=>'current','quote'=>$outbound['question']]],
            ]],
        ],$outbound);
    }

    /**
     * Admit only a complete current-turn member count with one explicit money
     * predicate and one exact registry-owned measurement. The shared calendar
     * parser owns dates; the registry owns metric meaning and condition
     * readiness; the normal condition compiler and Reader still enforce scope,
     * permissions and fact grain. Unparsed words or a second condition return
     * to model understanding rather than silently narrowing the request.
     */
    private function localClosedMemberMoneyCountUnderstanding(array $outbound,array $safe,string $today,array $caps): ?array
    {
        $question=$outbound['question']??null;
        if (!is_string($question) || $question==='' || !empty($safe['local_conditions']??[])) return null;
        $matches=\app\services\query\metric\MetricSemanticCatalog::registeredNonOverlappingTermsInText(
            $question,array_keys((array)($caps['metric_readiness']??[]))
        );
        if (count($matches)!==1) return null;
        $match=$matches[0];$code=$match['metric_code']??null;$term=$match['term']??null;
        $readiness=is_string($code)?($caps['metric_readiness'][$code]??null):null;
        if (!is_string($code) || !is_string($term) || !is_array($readiness)
            || ($readiness['condition_unit']??null)!=='yuan' || !isset(
            \app\services\ai\execution\AiCapabilityGuidanceCatalog::discover($caps,'member','condition_count')[$code]
        )) return null;
        $predicate=\app\services\ai\semantic\AiConditionResponseFormResolver::parseClosedMemberMoneyCount($question,$term);
        $calendar=\app\services\ai\semantic\AiSemanticIntentParser::calendarEvidence($question);
        // Require one explicit, complete period here: otherwise "spent N"
        // could mean today or lifetime, and the model must resolve that scope.
        if ($predicate===null || ($calendar['complete']??false)!==true
            || count($calendar['periods']??[])!==1) return null;
        $projection=(new \app\services\ai\semantic\AiSemanticIntentParser())->parse($question);
        if (count((array)($projection['date_terms']??[]))!==1
            || ($projection['date_grouping_ambiguous']??true)) return null;
        $range=(new \app\services\ai\execution\AiWorkflowPlanner())->normalizePeriod($projection['date_terms'][0],$today);
        $predicate['metric_term']=$term;
        return \app\services\ai\contract\AiIntentUnderstandingContract::normalize(
            (($outbound['prior_query']??null)!==null?['topic_relation'=>'new_topic']:[])+[
            'goal'=>'count members meeting one registered monetary condition',
            'requirements'=>[[
                'id'=>'r1','meaning'=>'registered monetary condition and member count',
                'fields'=>['metric_codes','object_kind','operation','periods','aggregate_condition'],
                'values'=>['metric_terms'=>[$term],'object_kind'=>'member','operation'=>'condition_count',
                    'periods'=>[['kind'=>'date_range','start'=>$range['start'],'end'=>$range['end']]],
                    'aggregate_condition'=>['subject'=>'member','relation'=>'all','result_form'=>'count',
                        'conditions'=>[$predicate]]],
                'evidence'=>[['message_id'=>'current','quote'=>$question]],
            ]],
            'status'=>'understood',
        ],$outbound);
    }

    /**
     * Admit a complete numeric edit to one signed condition without a model
     * round trip. The closed grammar carries no metric or member identity: it
     * only changes the comparator/quantity of the unique same-unit predicate,
     * plus an explicit date or count-form delta. Any extra business wording,
     * second condition, unknown unit or unparsed date falls back to the full
     * language path; this is never a phrase-to-metric shortcut.
     */
    private function localVerifiedConditionDeltaUnderstanding(array $outbound,array $safe,string $today): ?array
    {
        $question=$outbound['question']??null;
        $prior=$outbound['prior_query']??null;
        $condition=is_array($prior)?($prior['aggregate_condition']??null):null;
        if (!is_string($question) || $question==='' || !empty($safe['local_conditions']??[])
            || !in_array($prior['operation']??null,['condition_count','condition_list'],true)
            || !is_array($condition) || count((array)($condition['conditions']??[]))!==1) return null;
        $sourceCondition=$condition['conditions'][0]??null;
        if (!is_array($sourceCondition) || !in_array($sourceCondition['unit']??null,['yuan','count','day'],true)) return null;
        $projection=(new \app\services\ai\semantic\AiSemanticIntentParser())->parse($question);
        $dateTerms=(array)($projection['date_terms']??[]);
        if (count($dateTerms)>1 || ($projection['date_grouping_ambiguous']??true)) return null;
        $remainder=$question;$period=null;
        if ($dateTerms!==[]) {
            $term=$dateTerms[0];
            $calendar=\app\services\ai\semantic\AiSemanticIntentParser::calendarEvidence($question);
            if (($calendar['complete']??false)!==true || count($calendar['periods']??[])!==1
                || (($term['start']??null)!==null && ($term['start']??null)!==($term['end']??null))) return null;
            // The shared calendar parser also owns relative words such as
            // "本月" whose date term has a code but no literal start/end.
            // Use its proven remainder, never a second date-word matcher.
            $remainder=preg_replace('/(?:仍看|还是看|只看|仅看)/u','',$calendar['remainder']);
            if (!is_string($remainder)) return null;
            $quote=is_string($term['start']??null)?$term['start']:$question;
            $period=(new \app\services\ai\execution\AiWorkflowPlanner())->normalizePeriod($term,$today);
        }
        $remainder=preg_replace('/[\s，,。？?！!、]+/u','',$remainder);
        if (!is_string($remainder)) return null;
        $hasCount=(bool)preg_match('/(?:人数是多少|有多少个人|有多少人|多少个人|多少人|几个人)$/u',$remainder,$countMatch);
        if ($hasCount) $remainder=mb_substr($remainder,0,mb_strlen($remainder,'UTF-8')-mb_strlen($countMatch[0],'UTF-8'),'UTF-8');
        // An explicit "same condition, new period" reuses the signed
        // predicate verbatim. In particular, a month change cannot turn a
        // strict greater-than comparison into equality or a fresh cohort.
        if ($period!==null && in_array($remainder,['条件不变','其他条件不变'],true)) {
            $requirements=[['id'=>'r1','meaning'=>'change verified query period','fields'=>['periods'],
                'values'=>['periods'=>[['kind'=>'date_range','start'=>$period['start'],'end'=>$period['end']]]],
                'evidence'=>[['message_id'=>'current','quote'=>$quote]]]];
            if ($hasCount && $prior['operation']==='condition_list') $requirements[]=[
                'id'=>'r2','meaning'=>'count the verified condition set','fields'=>['operation'],
                'values'=>['operation'=>'condition_count'],
                'evidence'=>[['message_id'=>'current','quote'=>$countMatch[0]]]];
            return \app\services\ai\contract\AiIntentUnderstandingContract::normalize([
                'goal'=>'change verified condition period','requirements'=>$requirements,'status'=>'understood',
            ],$outbound);
        }
        if (!preg_match('/^((?:把|将)?(?:条件|金额|数值)(?:改|调整)(?:成|为)?(大于|超过|小于|低于|等于|不少于|不超过|至少|至多)((?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?)(元|次|天))$/u',$remainder,$match)
            || mb_strpos($question,$match[1],0,'UTF-8')===false) return null;
        $operator=['大于'=>'gt','超过'=>'gt','小于'=>'lt','低于'=>'lt','等于'=>'eq',
            '不少于'=>'gte','至少'=>'gte','不超过'=>'lte','至多'=>'lte'][$match[2]];
        $unit=['元'=>'yuan','次'=>'count','天'=>'day'][$match[4]];
        if ($unit!==$sourceCondition['unit']) return null;
        $requirements=[['id'=>'r1','meaning'=>'change one verified condition','fields'=>['condition_update'],
            'values'=>['condition_update'=>['target_term'=>$match[1],'operator'=>$operator,'quantity'=>$match[3],'unit'=>$unit]],
            'evidence'=>[['message_id'=>'current','quote'=>$match[1]]]]];
        if ($period!==null) $requirements[]=['id'=>'r2','meaning'=>'change verified query period','fields'=>['periods'],
            'values'=>['periods'=>[['kind'=>'date_range','start'=>$period['start'],'end'=>$period['end']]]],
            'evidence'=>[['message_id'=>'current','quote'=>$quote]]];
        if ($hasCount && $prior['operation']==='condition_list') $requirements[]=[
            'id'=>'r'.(count($requirements)+1),'meaning'=>'count the verified condition set','fields'=>['operation'],
            'values'=>['operation'=>'condition_count'],'evidence'=>[['message_id'=>'current','quote'=>$countMatch[0]]]];
        return \app\services\ai\contract\AiIntentUnderstandingContract::normalize([
            'goal'=>'update verified condition','requirements'=>$requirements,'status'=>'understood',
        ],$outbound);
    }

    /**
     * A bound, current-turn metric with no other stated semantic field is a
     * metric view change of the signed result, not permission to reset its
     * period, object or ranking presentation. This reads typed requirement
     * fields only; it neither interprets prose nor chooses the replacement.
     */
    private function preserveVerifiedMetricOnlyContext(array $intent,array $understanding,?array $sourceQuery,bool $closedMetricOnlyProjection=false): array
    {
        if ($sourceQuery===null || ($intent['needs_metric_choice']??true) || (array)($intent['metric_codes']??[])===[]) return $intent;
        $current=[];
        foreach (\app\services\ai\contract\AiIntentUnderstandingContract::requirements($understanding) as $requirement) {
            foreach ((array)($requirement['evidence']??[]) as $evidence) {
                if (($evidence['message_id']??null)!=='current') continue;
                foreach ((array)($requirement['fields']??[]) as $field) if (is_string($field)) $current[$field]=true;
                break;
            }
        }
        if (array_keys($current)!==['metric_codes'] && !$closedMetricOnlyProjection) return $intent;
        $intent['context_delta']=array_fill_keys(AiIntentResultContract::DELTA_FIELDS,'inherit');
        $intent['context_delta']['metric_codes']='replace';
        return $intent;
    }

    /**
     * Removes model-inferred context fields from a registry-proven, single
     * metric continuation before the binding contract validates it. This does
     * not choose a metric or parse customer wording: it preserves the accepted
     * metric carrier and current evidence exactly, and applies only when the
     * separate semantic projection has already proved there is no date,
     * object, condition, ranking or response-form instruction in this turn.
     */
    private function reconcileClosedMetricOnlyUnderstanding(array $understanding,bool $closedMetricOnlyProjection,?array $sourceQuery): array
    {
        // Independent result groups own their object/operation carriers. A
        // single-view cleanup cannot discard those referenced requirements.
        if (!$closedMetricOnlyProjection || $sourceQuery===null || ($understanding['status']??null)!=='understood'
            || isset($understanding['groups'])) return $understanding;
        $metricRequirements=[];
        foreach ((array)($understanding['requirements']??[]) as $requirement) {
            if (!is_array($requirement) || !in_array('metric_codes',(array)($requirement['fields']??[]),true)) continue;
            $hasCurrentEvidence=false;
            foreach ((array)($requirement['evidence']??[]) as $evidence) {
                if (is_array($evidence) && ($evidence['message_id']??null)==='current') {$hasCurrentEvidence=true;break;}
            }
            if (!$hasCurrentEvidence) continue;
            $values=[];
            foreach (['metric_terms','metric_exclusions'] as $key) {
                if (array_key_exists($key,(array)($requirement['values']??[]))) $values[$key]=$requirement['values'][$key];
            }
            if ($values===[]) continue;
            $requirement['fields']=['metric_codes'];
            $requirement['values']=$values;
            $metricRequirements[]=$requirement;
        }
        // Several independent current measurements are not a single view
        // change. Leave them to the ordinary model-owned multi-result path.
        if (count($metricRequirements)!==1) return $understanding;
        // Requirement cleanup must not discard the accepted topic relation
        // or overview policy and reopen inheritance under a different meaning.
        return array_replace($understanding,[
            'requirements'=>$metricRequirements,
            'status'=>'understood',
        ]);
    }

    /**
     * A closed single-measurement edit can reuse a verified ordinary query.
     * The existing structural projection excludes time, objects, predicates
     * and result references; registry evidence identifies the stated term.
     * Only meaning is projected here. Binding still validates compatibility
     * and current permissions, and condition queries cannot change predicates
     * implicitly by replacing their measurement.
     */
    private function localVerifiedMetricOnlyUnderstanding(array $outbound,array $safe,array $sourceQuery,bool $closed,array $caps): ?array
    {
        if (!$closed || !empty($safe['local_conditions']) || ($outbound['prior_query']??null)===null
            || !in_array($sourceQuery['query_shape']??null,['summary','breakdown','trend','ranking'],true)
            || !empty($sourceQuery['aggregate_condition']) || !empty($sourceQuery['condition_set'])) return null;
        // The longest registered phrase is not proof of a single metric.
        // Count every non-overlapping registry term, including an unavailable
        // metric, so this shortcut cannot silently discard a second request.
        $matches=\app\services\query\metric\MetricSemanticCatalog::registeredNonOverlappingTermsInText(
            (string)$outbound['question'],array_keys(\app\services\query\metric\MetricDefinitionRegistry::all())
        );
        $byCode=[];
        foreach ($matches as $match) $byCode[$match['metric_code']]=$match;
        if (count($byCode)!==1) return null;
        $match=reset($byCode);
        if (!array_key_exists($match['metric_code'],(array)($caps['metric_readiness']??[]))) return null;
        return AiIntentUnderstandingContract::normalize([
            'goal'=>'change the measurement of the verified query',
            'topic_relation'=>'continuation','status'=>'understood','requirements'=>[[
                'id'=>'r1','meaning'=>'use the stated registered measurement','fields'=>['metric_codes'],
                'values'=>['metric_terms'=>[$match['term']]],
                'evidence'=>[['message_id'=>'current','quote'=>$match['term']]],
            ]],
        ],$outbound);
    }

    /**
     * Separates an omitted-context metric view from a new topic using the
     * registry-driven local semantic projection. It does not choose a metric:
     * it merely proves that this turn contains one or more registered metric
     * signals and no date, ranking, condition or other executable meaning.
     */
    private function hasClosedRegisteredMetricOnlyProjection(array $outbound): bool
    {
        if (!is_string($outbound['question']??null)) return false;
        $question=$outbound['question'];
        $parser=new \app\services\ai\semantic\AiSemanticIntentParser();
        $projection=$parser->parse($question);
        $signals=(array)($projection['signals']??[]);
        // Capability readiness is intentionally scoped to the store-grain
        // inventory at this stage; personnel/object-grain registrations are
        // admitted later with their own authority checks. Use the source
        // registry only to recognise a semantic signal, never to execute it.
        $registered=array_keys(\app\services\query\metric\MetricDefinitionRegistry::all());
        $legacyClosed=$signals!==[] && array_diff($signals,$registered)===[]
            &&($projection['date_terms']??[])===[]
            &&!($projection['date_grouping_ambiguous']??false)
            &&!($projection['unresolved_condition']??true)
            &&(array)($projection['semantic_intent']['constraints']??[])===[];
        if ($legacyClosed) return true;
        // Newly registered customer aliases should not require a matching
        // hard-coded legacy parser token. Remove only exact registry-owned
        // measurement titles, then ask the same structural parser whether any
        // date, object, ranking, condition or other business meaning remains.
        // A self-contained request such as "which product sells best" keeps
        // its object/ranking residue and therefore cannot enter this shortcut.
        $matches=\app\services\query\metric\MetricSemanticCatalog::registeredTermsInText($question,$registered);
        $codes=[];$terms=[];
        foreach ($matches as $match) {
            if (is_string($match['metric_code']??null)) $codes[$match['metric_code']]=true;
            if (is_string($match['term']??null) && $match['term']!=='') $terms[$match['term']]=true;
        }
        if (count($codes)!==1 || $terms===[]) return false;
        $residual=$question;
        $orderedTerms=array_keys($terms);
        usort($orderedTerms,static function(string $a,string $b):int{return mb_strlen($b,'UTF-8')<=>mb_strlen($a,'UTF-8');});
        foreach ($orderedTerms as $term) $residual=str_replace($term,' ',$residual);
        $residualProjection=$parser->parse($residual);
        return ($residualProjection['signals']??[])===[]
            &&($residualProjection['date_terms']??[])===[]
            &&!($residualProjection['date_grouping_ambiguous']??false)
            &&!($residualProjection['unresolved_condition']??true)
            &&(array)($residualProjection['semantic_intent']['constraints']??[])===[];
    }

    /**
     * Recovers only the semantic carrier for a registry-backed open operating
     * observation after two model responses incorrectly reduce it to a date.
     * Admission is structural: exactly one calendar period, one broad metric
     * signal, no concrete local condition, and a published store overview.
     * The parser's sole generic "unparsed business condition" may corroborate
     * the model-owned broad observation but can never admit one by itself. The
     * complete de-identified current message remains the audited term; PHP
     * does not extract or map a customer phrase to any executable metric.
     */
    private function registeredOpenOverviewUnderstanding(array $safeQuestion,string $today,array $capabilities): ?array
    {
        $question=$safeQuestion['question']??null;
        if (!is_string($question)||$question===''||($safeQuestion['prior_query']??null)===null) return null;
        $projection=(new \app\services\ai\semantic\AiSemanticIntentParser())->parse($question);
        $signals=array_values(array_diff((array)($projection['signals']??[]),[
            'TODAY','YESTERDAY','DAY_BEFORE_YESTERDAY','THIS_MONTH','LAST_MONTH',
        ]));
        $constraints=(array)($projection['semantic_intent']['constraints']??[]);
        // The legacy parser intentionally cannot execute a broad business
        // phrase, so it reports one generic unparsed condition. That residue
        // is acceptable only as corroboration after the model has independently
        // admitted the broad observation shape; any concrete local condition,
        // extra constraint or different blocking reason remains ineligible.
        $genericOpenResidue=($projection['unresolved_condition']??false)===true
            &&($projection['blocking_reason']??null)==='AI_INTENT_UNRESOLVED'
            &&$constraints===[['type'=>'unparsed_business_condition','status'=>'unresolved']];
        if ($signals!==['ambiguous_metric']
            ||count((array)($projection['date_terms']??[]))!==1
            ||($projection['date_grouping_ambiguous']??true)
            ||(!(($projection['unresolved_condition']??true)===false&&$constraints===[])&&!$genericOpenResidue)
            ||count(AiOverviewMetricResolver::resolve($capabilities,'store'))<2) return null;
        $range=(new AiWorkflowPlanner())->normalizePeriod($projection['date_terms'][0],$today);
        return AiIntentUnderstandingContract::normalize([
            'goal'=>'open registered operating observation',
            'request_kind'=>'open_overview',
            'requirements'=>[
                ['id'=>'r1','meaning'=>'open store operating observation',
                    'fields'=>['metric_codes','object_kind','object_relation','operation','periods'],
                    'values'=>['metric_terms'=>[$question],'object_kind'=>'store','object_relation'=>'analysis','operation'=>'summary',
                        'periods'=>[['kind'=>'date_range','start'=>$range['start'],'end'=>$range['end']]]],
                    'evidence'=>[['message_id'=>'current','quote'=>$question]]],
            ],
            'status'=>'understood',
        ],$safeQuestion);
    }

    /**
     * Both terminals resolve explicit calendar wording against the run's server
     * date. A single period or one accepted comparison's two ordered windows
     * may correct model date values. Independent carriers, historical evidence
     * and every non-date meaning remain outside this correction boundary.
     */
    private function resolveExactStatedPeriods(array $understanding,array $safeQuestion,string $today): array
    {
        // A clear calendar condition is normalizable even while the metric
        // remains ambiguous. This does not complete or execute that request.
        if (!in_array($understanding['status']??null,['understood','needs_clarification'],true)) return $understanding;
        $projection=(new \app\services\ai\semantic\AiSemanticIntentParser())->parse((string)($safeQuestion['question']??''));
        $terms=(array)($projection['date_terms']??[]);
        if (($projection['date_grouping_ambiguous']??true) || !in_array(count($terms),[1,2],true)) return $understanding;
        $planner=new AiWorkflowPlanner();
        if (count($terms)===1) {
            $range=$planner->normalizePeriod($terms[0],$today);
            return AiIntentUnderstandingContract::withResolvedSinglePeriod($understanding,[
                ['kind'=>'date_range','start'=>$range['start'],'end'=>$range['end']],
            ]);
        }
        // The shared calendar preserves wording order. It may replace two
        // dates only when one current comparison owns them together; array
        // order between independent questions is never evidence of ownership.
        if (!empty($understanding['groups'])) return $understanding;
        $periodOwner=null;$comparison=false;
        foreach ((array)($understanding['requirements']??[]) as $index=>$requirement) {
            $fields=(array)($requirement['fields']??[]);
            if (!array_intersect($fields,['periods','operation'])) continue;
            if (empty($requirement['evidence'])) return $understanding;
            foreach ($requirement['evidence'] as $evidence) {
                if (($evidence['message_id']??null)!=='current') return $understanding;
            }
            if (in_array('operation',$fields,true)) {
                if (($requirement['values']['operation']??null)!=='comparison') return $understanding;
                $comparison=true;
            }
            if (in_array('periods',$fields,true)) {
                if ($periodOwner!==null || count((array)($requirement['values']['periods']??[]))!==2) return $understanding;
                $periodOwner=$index;
            }
        }
        if (!$comparison || $periodOwner===null) return $understanding;
        $periods=[];
        foreach ($terms as $term) {
            $range=$planner->normalizePeriod($term,$today);
            $periods[]=['kind'=>'date_range','start'=>$range['start'],'end'=>$range['end']];
        }
        $understanding['requirements'][$periodOwner]['values']['periods']=$periods;
        return $understanding;
    }

    /**
     * Recover an exact, uniquely registered measurement when the language
     * model accidentally classifies that complete metric phrase as an
     * analytical object. This is registry projection, not a handwritten
     * question rule: explicit object words remain authoritative and an
     * ambiguous metric phrase is never corrected here.
     */
    private function reconcileExactRegisteredMeasurement(array $understanding,array $safeQuestion,array $summaries,array $objectVocabulary): array
    {
        if (($understanding['status']??null)!=='understood') return $understanding;
        $question=$safeQuestion['question']??null;
        if (!is_string($question) || $question==='') return $understanding;
        // A condition set owns several independent measurements and their
        // evidence. Finding only one exact registry term does not prove the
        // other natural-language measurements mean that same metric. Leave
        // this carrier to condition binding; never replace its full evidence
        // with the one recognized word or collapse its predicate audit.
        foreach ((array)($understanding['requirements']??[]) as $requirement) {
            if (isset($requirement['values']['aggregate_condition'])) return $understanding;
        }
        $allowed=[];
        foreach ($summaries as $summary) if (is_array($summary) && is_string($summary['metric_code']??null)) {
            $allowed[]=$summary['metric_code'];
        }
        $match=\app\services\query\metric\MetricSemanticCatalog::uniqueTermInText($question,$allowed);
        if ($match===null) return $understanding;
        $compatibleKinds=[];
        foreach ($summaries as $summary) {
            if (($summary['metric_code']??null)!==$match['metric_code']) continue;
            foreach ((array)($summary['object_contracts']??[]) as $contract) {
                if (is_array($contract) && is_string($contract['object_kind']??null)) $compatibleKinds[$contract['object_kind']]=true;
            }
        }
        if ($compatibleKinds===[]) return $understanding;
        $originalRequirements=(array)($understanding['requirements']??[]);
        $metricIndexes=[];
        foreach ($originalRequirements as $index=>$requirement) {
            if (is_array($requirement) && in_array('metric_codes',(array)($requirement['fields']??[]),true)) $metricIndexes[]=$index;
        }
        if ($metricIndexes!==[]) {
            // A single model-owned umbrella term is not a second measurement
            // when the current message proves exactly one registered metric.
            // Canonicalize that carrier before binding so the strict contract
            // sees one requirement. Multiple metric rows, exclusions or a
            // separately resolvable code remain deliberate ambiguity and are
            // never collapsed by this registry boundary.
            if (count($metricIndexes)>1) {
                // Equal metric words do not make independent results one
                // query. Merge duplicate carriers only when every carrier
                // belongs to exactly the same declared result groups.
                $metricGroups=null;
                foreach ($metricIndexes as $index) {
                    $owned=[];
                    foreach ((array)($understanding['groups']??[]) as $group) {
                        if (in_array($originalRequirements[$index]['id'],(array)$group['requirement_ids'],true)) $owned[]=$group['id'];
                    }
                    sort($owned);
                    if ($metricGroups!==null && $owned!==$metricGroups) return $understanding;
                    $metricGroups=$owned;
                }
                // Some models duplicate one explicitly stated measurement
                // across an object, ranking and metric requirement. Collapse
                // only the duplicated metric field when every carrier quotes
                // the same exact registry title and every supplied metric term
                // resolves to that title (or is its literal sub-phrase). An
                // independent, excluded or unknown measurement therefore
                // remains untouched and cannot be lost through this cleanup.
                foreach ($metricIndexes as $index) {
                    $requirement=$originalRequirements[$index];
                    if (!empty($requirement['values']['metric_exclusions'])) return $understanding;
                    $overlaps=false;
                    foreach ((array)($requirement['evidence']??[]) as $evidence) {
                        $quote=$evidence['quote']??null;
                        if (is_string($quote) && $quote!==''
                            && (mb_strpos($quote,$match['term'],0,'UTF-8')!==false
                                || mb_strpos($match['term'],$quote,0,'UTF-8')!==false)) {
                            $overlaps=true;
                            break;
                        }
                    }
                    if (!$overlaps) return $understanding;
                    foreach ((array)($requirement['values']['metric_terms']??[]) as $term) {
                        if (!is_string($term) || $term==='') return $understanding;
                        $resolved=\app\services\query\metric\MetricSemanticCatalog::uniqueCodeForTerms([$term],$allowed);
                        if ($resolved!==$match['metric_code']
                            && mb_strpos($match['term'],$term,0,'UTF-8')===false) return $understanding;
                    }
                }
                $requirements=$originalRequirements;$ownerIndex=$metricIndexes[0];
                $requirements[$ownerIndex]['values']['metric_terms']=[$match['term']];
                $requirements[$ownerIndex]['evidence']=[['message_id'=>'current','quote'=>$match['term']]];
                foreach (array_slice($metricIndexes,1) as $index) {
                    $fields=array_values(array_diff((array)$requirements[$index]['fields'],['metric_codes']));
                    unset($requirements[$index]['values']['metric_terms'],$requirements[$index]['values']['metric_exclusions']);
                    if ($fields===[]) unset($requirements[$index]);
                    else $requirements[$index]['fields']=$fields;
                }
                if (isset($understanding['groups'])) {
                    // The retained metric has the same owners as every
                    // removed duplicate, so pruning only stale IDs preserves
                    // each independent group's complete measurement meaning.
                    $retainedIds=array_column($requirements,'id');
                    foreach ($understanding['groups'] as &$group) {
                        $group['requirement_ids']=array_values(array_intersect($group['requirement_ids'],$retainedIds));
                    }
                    unset($group);
                }
                return \app\services\ai\contract\AiIntentUnderstandingContract::normalize(array_replace($understanding,[
                    'requirements'=>array_values($requirements),'status'=>'understood',
                    ]),$safeQuestion);
            }
            $index=$metricIndexes[0];$requirement=$originalRequirements[$index];
            if (!empty($requirement['values']['metric_exclusions'])) return $understanding;
            $terms=$requirement['values']['metric_terms']??[];
            if (!is_array($terms) || $terms===[]
                || \app\services\query\metric\MetricSemanticCatalog::uniqueCodeForTerms($terms,$allowed)!==null) return $understanding;
            $requirements=$originalRequirements;
            $requirements[$index]['values']['metric_terms']=[$match['term']];
            $requirements[$index]['evidence']=[['message_id'=>'current','quote'=>$match['term']]];
            return \app\services\ai\contract\AiIntentUnderstandingContract::normalize(array_replace($understanding,[
                'requirements'=>$requirements,'status'=>'understood',
                ]),$safeQuestion);
        }
        // With several accepted result groups and no metric carrier, a global
        // lexical match cannot identify which group owns the omitted metric.
        // Refuse an incomplete group contract rather than attaching a new ID
        // arbitrarily or silently returning only part of the stated request.
        if (!empty($understanding['groups'])) throw new AiContractException('AI_MODEL_INTENT_CONTRACT_INVALID',[
            'stage'=>'intent_understanding_contract','predicate'=>'groups',
        ]);
        $labelsByKind=[];
        foreach ($objectVocabulary as $item) if (is_array($item) && is_string($item['object_kind']??null)
            && is_string($item['object_label']??null) && $item['object_label']!=='') {
            $labelsByKind[$item['object_kind']][]=$item['object_label'];
        }
        $requirements=[];
        foreach ($originalRequirements as $requirement) {
            if (!is_array($requirement) || !in_array('object_kind',(array)($requirement['fields']??[]),true)) {
                $requirements[]=$requirement;
                continue;
            }
            $kind=$requirement['values']['object_kind']??null;
            if (!is_string($kind) || isset($compatibleKinds[$kind])) {
                $requirements[]=$requirement;
                continue;
            }
            $explicit=false;
            foreach ((array)($labelsByKind[$kind]??[]) as $label) {
                if (mb_strpos($question,$label,0,'UTF-8')!==false) {$explicit=true;break;}
            }
            // An explicitly stated incompatible object is a real customer
            // condition and must reach the normal capability boundary.
            if ($explicit) return $understanding;
            $fields=array_values(array_diff((array)($requirement['fields']??[]),['object_kind','object_relation']));
            if ($fields===[]) continue;
            $requirement['fields']=$fields;
            unset($requirement['values']['object_kind'],$requirement['values']['object_relation']);
            $requirements[]=$requirement;
        }
        if (count($requirements)>=12) return $understanding;
        $used=[];$next=1;
        foreach ($requirements as $requirement) if (is_array($requirement) && is_string($requirement['id']??null)) {
            $used[$requirement['id']]=true;
        }
        while (isset($used['r'.$next]) && $next<1000) $next++;
        if ($next>999) return $understanding;
        $requirements[]=[
            'id'=>'r'.$next,
            'meaning'=>'使用“'.$match['term'].'”作为衡量指标',
            'fields'=>['metric_codes'],
            'values'=>['metric_terms'=>[$match['term']]],
            'evidence'=>[['message_id'=>'current','quote'=>$match['term']]],
        ];
        return \app\services\ai\contract\AiIntentUnderstandingContract::normalize(array_replace($understanding,[
            'requirements'=>$requirements,'status'=>'understood',
            ]),$safeQuestion);
    }

    /**
     * Preserve every exact registered measurement stated in a combined
     * request, including a measurement whose Reader is deliberately not yet
     * ready. This never chooses a code for execution: it only adds the missing
     * accountability requirement so the binding stage must mark it satisfied
     * or unavailable instead of silently dropping it as an unresolved phrase.
     */
    private function reconcileStatedRegisteredMeasurements(array $understanding,array $safeQuestion,array $capabilities): array
    {
        if (($understanding['status']??null)!=='understood') return $understanding;
        $question=$safeQuestion['question']??null;
        if (!is_string($question) || $question==='') return $understanding;
        $allowed=array_keys((array)($capabilities['metric_readiness']??[]));
        $stated=\app\services\query\metric\MetricSemanticCatalog::registeredNonOverlappingTermsInText($question,$allowed);
        $statedCodes=array_values(array_unique(array_filter(array_column($stated,'metric_code'),'is_string')));
        $requirements=(array)($understanding['requirements']??[]);
        if (count($statedCodes)>1) {
            foreach ($requirements as $index=>$requirement) {
                if (!is_array($requirement) || !in_array('metric_codes',(array)($requirement['fields']??[]),true)) continue;
                $values=(array)($requirement['values']??[]);
                if (!empty($values['metric_exclusions']) || isset($values['aggregate_condition'])) continue;
                $owned=[];
                foreach ((array)($values['metric_terms']??[]) as $term) {
                    if (!is_string($term) || trim($term)==='') continue;
                    $code=\app\services\query\metric\MetricSemanticCatalog::uniqueCodeForTerms([trim($term)],$allowed);
                    if ($code!==null && in_array($code,$statedCodes,true)) $owned[$code]=true;
                }
                if ($owned!==[]) continue;
                // A deferred empty or unregistered model echo is not a fourth
                // customer metric. Keep its independently accepted object,
                // time and operation semantics, while exact registry-backed
                // requirements below own every metric audit row. Exclusions
                // and conditions are never removed by this cleanup.
                $requirement['fields']=array_values(array_diff((array)$requirement['fields'],['metric_codes']));
                unset($requirement['values']['metric_terms']);
                if ($requirement['fields']===[]) unset($requirements[$index]);
                else $requirements[$index]=$requirement;
            }
            $requirements=array_values($requirements);
        }
        $accounted=[];$used=[];$accountedCodes=[];
        foreach ($requirements as $requirement) {
            if (!is_array($requirement)) continue;
            if (is_string($requirement['id']??null)) $used[$requirement['id']]=true;
            if (!in_array('metric_codes',(array)($requirement['fields']??[]),true)) continue;
            foreach ((array)($requirement['values']['metric_terms']??[]) as $term) if (is_string($term) && $term!=='') {
                $accounted[$term]=true;
                // Registered aliases are one measurement, not extra customer
                // requirements. Retain every independently understood clause;
                // only avoid adding a duplicate audit row for the same owner.
                $code=\app\services\query\metric\MetricSemanticCatalog::uniqueCodeForTerms([$term],$allowed);
                if ($code!==null) $accountedCodes[$code]=true;
            }
        }
        $addedRequirement=false;
        // A complete registered phrase owns its shorter embedded aliases.
        // In “总余额” the embedded “余额” is not an independent condition.
        foreach (\app\services\query\metric\MetricSemanticCatalog::registeredNonOverlappingTermsInText($question,$allowed) as $match) {
            if (isset($accounted[$match['term']])||isset($accountedCodes[$match['metric_code']])) continue;
            if (count($requirements)>=12) return $understanding;
            $next=1;while(isset($used['r'.$next])&&$next<1000)$next++;
            if ($next>999) return $understanding;
            $id='r'.$next;$used[$id]=true;$accounted[$match['term']]=true;
            $addedRequirement=true;
            $requirements[]=['id'=>$id,'meaning'=>'使用“'.$match['term'].'”作为衡量指标','fields'=>['metric_codes'],
                'values'=>['metric_terms'=>[$match['term']]],'evidence'=>[['message_id'=>'current','quote'=>$match['term']]]];
        }
        if ($requirements===(array)($understanding['requirements']??[])) return $understanding;
        if (!empty($understanding['groups'])) {
            // A new measurement has no proven group owner. The existing
            // contract failure preserves that uncertainty instead of omitting
            // a stated metric or assigning it to every independent result.
            if ($addedRequirement) throw new AiContractException('AI_MODEL_INTENT_CONTRACT_INVALID',[
                'stage'=>'intent_understanding_contract','predicate'=>'groups',
            ]);
            $retainedIds=array_column($requirements,'id'); $metricIds=[];
            foreach ($requirements as $requirement) {
                if (in_array('metric_codes',(array)$requirement['fields'],true)) $metricIds[]=$requirement['id'];
            }
            $groups=$understanding['groups'];
            foreach ($groups as &$group) {
                $originalIds=$group['requirement_ids'];
                $group['requirement_ids']=array_values(array_intersect($originalIds,$retainedIds));
                // Removing a redundant unregistered echo is safe only when
                // its original group still owns an explicit metric carrier.
                if ($group['requirement_ids']===[] || (array_diff($originalIds,$retainedIds)!==[]
                    && array_intersect($group['requirement_ids'],$metricIds)===[])) return $understanding;
            }
            unset($group);
            $understanding['groups']=$groups;
        }
        return \app\services\ai\contract\AiIntentUnderstandingContract::normalize(array_replace($understanding,[
            'requirements'=>$requirements,'status'=>'understood',
            ]),$safeQuestion);
    }

    /**
     * Reconcile one exact analytical-object label published by the active
     * capability registry. This is not a synonym table: only a unique exact
     * label in the current de-identified question can correct an omitted or
     * generic object kind. Opaque local identities stay on their separate
     * selection path and multiple object labels remain model-owned.
     */
    private function reconcileExactRegisteredAnalyticalObject(array $understanding,array $safeQuestion,array $objectVocabulary): array
    {
        if (($understanding['status']??null)!=='understood') return $understanding;
        $question=$safeQuestion['question']??null;
        if (!is_string($question) || $question==='' || preg_match('/\[local_condition_[0-9]+\]/D',$question)) return $understanding;
        $owners=[];$longest=0;
        foreach ($objectVocabulary as $item) {
            $kind=$item['object_kind']??null;$label=$item['object_label']??null;
            if (!is_string($kind) || !is_string($label) || $label==='' || mb_strpos($question,$label,0,'UTF-8')===false) continue;
            // Ignore an object word only when every occurrence is embedded in
            // a longer registered measurement term. For example, the noun in
            // “完成服务项目数量” is measurement language; a separate “项目”
            // elsewhere in the same question remains an explicit row object.
            if ($this->objectLabelOccursOnlyInsideMeasurement($question,$label)) continue;
            $length=mb_strlen($label,'UTF-8');
            if ($length>$longest) {$owners=[];$longest=$length;}
            if ($length===$longest) $owners[$kind][$label]=true;
        }
        if (count($owners)!==1) return $understanding;
        $kind=array_key_first($owners);$label=array_key_first($owners[$kind]);
        $requirements=(array)($understanding['requirements']??[]);$objectIndexes=[];
        foreach ($requirements as $index=>$requirement) if (is_array($requirement)
            && in_array('object_kind',(array)($requirement['fields']??[]),true)) $objectIndexes[]=$index;
        if ($objectIndexes===[]) {
            // An omitted object has no proven owner among independent result
            // groups. Let the existing contract repair recover that ownership;
            // silently appending an ungrouped carrier would drop the groups.
            if (isset($understanding['groups'])) throw new AiContractException('AI_MODEL_INTENT_CONTRACT_INVALID',[
                'stage'=>'intent_understanding_contract','predicate'=>'groups',
            ]);
            if (count($requirements)>=12) return $understanding;
            $used=[];$next=1;
            foreach ($requirements as $requirement) if (is_array($requirement) && is_string($requirement['id']??null)) $used[$requirement['id']]=true;
            while (isset($used['r'.$next]) && $next<1000) $next++;
            if ($next>999) return $understanding;
            $requirements[]=['id'=>'r'.$next,'meaning'=>'分析“'.$label.'”对象','fields'=>['object_kind','object_relation'],
                'values'=>['object_kind'=>$kind,'object_relation'=>'analysis'],
                'evidence'=>[['message_id'=>'current','quote'=>$label]]];
        } else {
            // One current sentence can be split into separate object and
            // measurement requirements by the language model. When the
            // registry proves that the sentence contains exactly one explicit
            // object kind, reconcile every duplicated carrier to that same
            // kind. Do not manufacture an analytical relation when the model
            // has not stated one: the same registered noun can be the suffix
            // of a concrete selection such as a store name. An explicit
            // selection therefore stays on the identity-binding path, while
            // an explicit analysis relation remains analytical. This does not
            // collapse genuine multi-object questions: those expose more than
            // one registered label above and never reach this branch.
            foreach ($objectIndexes as $index) {
                $requirement=$requirements[$index];
                $relation=$requirement['values']['object_relation']??null;
                if ($relation==='selection') return $understanding;
                $requirement['values']['object_kind']=$kind;
                if ($relation==='analysis') {
                    $requirement['fields']=array_values(array_unique(array_merge(
                        (array)$requirement['fields'],['object_kind','object_relation']
                    )));
                }
                // Preserve the original current-message evidence: this
                // requirement may also carry a metric, period or ranking whose
                // exact wording is outside the shorter object label. Replacing
                // the evidence with only that label would make an otherwise
                // valid combined request fail its metric audit.
                $requirements[$index]=$requirement;
            }
        }
        return \app\services\ai\contract\AiIntentUnderstandingContract::normalize(array_replace($understanding,[
            'requirements'=>$requirements,'status'=>'understood',
            ]),$safeQuestion);
    }

    /**
     * True only when all occurrences of an object label are contained in a
     * longer registered metric term. The registry supplies the vocabulary;
     * this method contains no business phrase or object-specific branch.
     */
    private function objectLabelOccursOnlyInsideMeasurement(string $question,string $label): bool
    {
        $measurementSpans=[];
        foreach (\app\services\query\metric\MetricSemanticCatalog::entries() as $entry) {
            foreach ((array)($entry['terms']??[]) as $term) {
                if (!is_string($term) || mb_strlen($term,'UTF-8')<=mb_strlen($label,'UTF-8')) continue;
                $offset=0;
                while (($start=mb_strpos($question,$term,$offset,'UTF-8'))!==false) {
                    $measurementSpans[]=[$start,$start+mb_strlen($term,'UTF-8')];
                    $offset=$start+1;
                }
            }
        }
        $found=false;$offset=0;$labelLength=mb_strlen($label,'UTF-8');
        while (($start=mb_strpos($question,$label,$offset,'UTF-8'))!==false) {
            $found=true;$end=$start+$labelLength;$contained=false;
            foreach ($measurementSpans as $span) {
                if ($start>=$span[0] && $end<=$span[1]) {$contained=true;break;}
            }
            if (!$contained) return false;
            $offset=$start+1;
        }
        return $found;
    }

    /**
     * Resolve the grammatical target of a distributive expression after the
     * language model has already accepted the object and measurements. Two or
     * more exact registered measurements followed by “separately” describe
     * one aggregate answer per measurement unless the sentence explicitly
     * distributes over the object (each/every/different object). The rule is
     * registry- and position-driven; it never maps a complete sentence to an
     * intent, chooses a metric, or touches ranking/comparison/conditions.
     */
    private function reconcileCoordinatedMeasurementDistribution(array $understanding,array $safeQuestion,array $objectVocabulary,array $metricCodes): array
    {
        if (($understanding['status']??null)!=='understood') return $understanding;
        $question=$safeQuestion['question']??null;
        if (!is_string($question) || $question==='' || preg_match('/\[local_condition_[0-9]+\]/D',$question)) return $understanding;
        $matches=\app\services\query\metric\MetricSemanticCatalog::registeredNonOverlappingTermsInText($question,$metricCodes);
        $codes=array_values(array_unique(array_column($matches,'metric_code')));
        if (count($codes)<2) return $understanding;
        $lastMetricEnd=0;
        foreach ($matches as $match) {
            $term=$match['term']??null;
            if (!is_string($term) || $term==='') return $understanding;
            $position=mb_strpos($question,$term,0,'UTF-8');
            if ($position===false) return $understanding;
            $lastMetricEnd=max($lastMetricEnd,$position+mb_strlen($term,'UTF-8'));
        }
        $separately=mb_strpos($question,'分别',0,'UTF-8');
        if ($separately===false || $separately<$lastMetricEnd) return $understanding;

        $kinds=[];$labels=[];$typedKinds=[];$registryOwnedObject=false;
        foreach ($objectVocabulary as $item) {
            $kind=$item['object_kind']??null;$label=$item['object_label']??null;
            if (!is_string($kind)||!is_string($label)||$label===''||mb_strpos($question,$label,0,'UTF-8')===false) continue;
            if ($this->objectLabelOccursOnlyInsideMeasurement($question,$label)) continue;
            $kinds[$kind]=true;$labels[$label]=true;
        }
        // A registry title may legitimately include the analytical noun (for
        // example “门店现金业绩”). If the accepted semantic contract already
        // identifies one analytical object, use its published labels only to
        // resolve which noun the trailing distributive applies to. This does
        // not infer an object from a substring or override a selected target.
        if ($kinds===[]) {
            foreach ((array)($understanding['requirements']??[]) as $requirement) {
                $values=(array)($requirement['values']??[]);
                if (($values['object_relation']??'analysis')!=='analysis') continue;
                $kind=$values['object_kind']??null;
                if (is_string($kind)&&$kind!==''&&$kind!=='unknown') $typedKinds[$kind]=true;
            }
            if (count($typedKinds)===1) {
                $typedKind=array_key_first($typedKinds);
                foreach ($objectVocabulary as $item) {
                    $label=$item['object_label']??null;
                    if (($item['object_kind']??null)!==$typedKind || !is_string($label) || $label===''
                        || mb_strpos($question,$label,0,'UTF-8')===false) continue;
                    $kinds[$typedKind]=true;$labels[$label]=true;
                }
            }
        }
        // A complete coordinated request may be split by the model into one
        // requirement per measurement and omit a redundant aggregate object
        // on a continuation. Recover that object only when every exact metric
        // independently publishes the same overview owner and the owner's
        // published label is present in the current question. This is a
        // registry intersection, not a sentence or customer-specific mapping.
        if ($kinds===[] && $typedKinds===[]) {
            $contracts=\app\services\query\metric\MetricDefinitionRegistry::capabilities();$common=null;
            foreach ($codes as $code) {
                $contract=$contracts[$code]??null;
                if (!is_array($contract) || !in_array('summary',(array)($contract['query_shapes']??[]),true)) {
                    $common=[];break;
                }
                $owners=[];
                foreach ((array)($contract['overview']??[]) as $item) {
                    $kind=$item['object_kind']??null;
                    if (is_string($kind)&&$kind!==''&&$kind!=='unknown') $owners[$kind]=true;
                }
                $owners=array_keys($owners);
                $common=$common===null?$owners:array_values(array_intersect($common,$owners));
            }
            if (is_array($common) && count($common)===1) {
                $registeredKind=$common[0];
                $registeredLabels=(array)(\app\services\query\metric\MetricDefinitionRegistry::analysisObjectAliases()[$registeredKind]??[]);
                foreach ($objectVocabulary as $item) {
                    $label=$item['object_label']??null;
                    if (($item['object_kind']??null)===$registeredKind && is_string($label) && $label!=='') $registeredLabels[]=$label;
                }
                foreach (array_values(array_unique($registeredLabels)) as $label) {
                    if (!is_string($label) || $label==='' || mb_strpos($question,$label,0,'UTF-8')===false) continue;
                    $kinds[$registeredKind]=true;$labels[$label]=true;$registryOwnedObject=true;
                }
            }
        }
        if (count($kinds)!==1 || $labels===[]) return $understanding;
        foreach (array_keys($labels) as $label) {
            // These are grammatical quantifiers, not business synonyms. They
            // must immediately govern the registered object label to request
            // one result row per object.
            if (preg_match('/(?:各(?:个|家|位|名|项)?|每(?:个|家|位|名|项)?|逐(?:个|家|位|名|项)?|不同)\s*'.preg_quote($label,'/').'/u',$question)) {
                return $understanding;
            }
        }
        $requirements=(array)($understanding['requirements']??[]);$changed=false;$hasObject=false;$hasOperation=false;
        if (!empty($understanding['groups'])) {
            // Some providers incorrectly turn a one-subject multi-metric
            // summary into one group per metric. Collapse only the shape that
            // the registry and current wording independently prove is one
            // aggregate subject: every group must own exactly one distinct,
            // current, registered metric requirement and may share only the
            // period. Any object, filter, ranking, condition or response-form
            // carrier keeps the groups intact for the ordinary multi-query
            // compiler, so genuine multi-subject requests are never merged.
            $metricRequirementIds=[];$metricTerms=[];$sharedOperationIds=[];
            $allowedGroupedFields=['metric_codes'=>true,'periods'=>true,'operation'=>true];
            foreach ($requirements as $requirement) {
                if (!is_array($requirement) || array_diff((array)($requirement['fields']??[]),array_keys($allowedGroupedFields))) {
                    return $understanding;
                }
                // A shared aggregate/breakdown echo can survive removal of
                // an invented metric term. Preserve its topic metadata, but
                // let this proven coordinated-summary rule correct the common
                // response form. Independent group operations remain separate.
                if (in_array('operation',(array)($requirement['fields']??[]),true)) {
                    if (!in_array($requirement['values']['operation']??null,['summary','breakdown'],true)
                        || !is_string($requirement['id']??null)) return $understanding;
                    $sharedOperationIds[]=$requirement['id'];
                }
                if (!in_array('metric_codes',(array)($requirement['fields']??[]),true)) continue;
                $terms=(array)($requirement['values']['metric_terms']??[]);
                if (!is_string($requirement['id']??null) || count($terms)!==1 || !is_string($terms[0]) || $terms[0]==='') {
                    return $understanding;
                }
                $metricRequirementIds[$requirement['id']]=true;$metricTerms[$terms[0]]=true;
            }
            $groupedMetricIds=[];
            foreach ((array)$understanding['groups'] as $group) {
                if (!is_array($group) || array_diff($sharedOperationIds,(array)($group['requirement_ids']??[]))!==[]) return $understanding;
                $owned=array_values(array_intersect((array)($group['requirement_ids']??[]),array_keys($metricRequirementIds)));
                if (count($owned)!==1) return $understanding;
                $groupedMetricIds[$owned[0]]=true;
            }
            $matchedTerms=[];
            foreach ($matches as $match) if (is_string($match['term']??null)) $matchedTerms[$match['term']]=true;
            if (count($metricRequirementIds)!==count($codes)
                || count($groupedMetricIds)!==count($metricRequirementIds)
                || array_diff_key($metricRequirementIds,$groupedMetricIds)!==[]
                || array_diff_key($metricTerms,$matchedTerms)!==[]) return $understanding;
            unset($understanding['groups']);$changed=true;
        }
        foreach ($requirements as &$requirement) {
            if (!is_array($requirement)) continue;
            $values=(array)($requirement['values']??[]);
            if (isset($values['object_relation']) && $values['object_relation']!=='analysis') return $understanding;
            if (in_array('object_kind',(array)($requirement['fields']??[]),true)) $hasObject=true;
            if (!in_array('operation',(array)($requirement['fields']??[]),true)) continue;
            $hasOperation=true;
            if (($values['operation']??null)==='breakdown') {
                $requirement['values']['operation']='summary';$changed=true;
            } elseif (($values['operation']??null)!=='summary') return $understanding;
        }
        unset($requirement);
        $objectNeedsCarrier=!$hasObject && ($registryOwnedObject || count($kinds)===1);
        if (!$hasOperation || $objectNeedsCarrier) {
            if (count($requirements)>=12) return $understanding;
            $used=[];$next=1;
            foreach ($requirements as $requirement) if (is_array($requirement)&&is_string($requirement['id']??null)) $used[$requirement['id']]=true;
            while (isset($used['r'.$next])&&$next<1000) $next++;
            if ($next>999) return $understanding;
            $fields=[];$values=[];
            if (!$hasOperation) {$fields[]='operation';$values['operation']='summary';}
            if ($objectNeedsCarrier) {
                $fields[]='object_kind';$fields[]='object_relation';
                $values['object_kind']=array_key_first($kinds);$values['object_relation']='analysis';
            }
            $requirements[]=['id'=>'r'.$next,'meaning'=>'按已登记经营主体汇总多个指标','fields'=>$fields,
                'values'=>$values,'evidence'=>[['message_id'=>'current','quote'=>$question]]];
            $changed=true;
        }
        if (!$changed) return $understanding;
        return AiIntentUnderstandingContract::normalize(array_replace($understanding,[
            'requirements'=>$requirements,'status'=>'understood',
            ]),$safeQuestion);
    }

    private function bindingSummariesForUnderstanding(array $summaries,array $understanding): array
    {
        $objectKinds=[];
        foreach (AiIntentUnderstandingContract::requirements($understanding) as $requirement) {
            if (!in_array('object_kind',(array)($requirement['fields']??[]),true)) continue;
            $kind=$requirement['values']['object_kind']??null;
            if (is_string($kind) && $kind!=='') $objectKinds[$kind]=true;
        }
        $objectKinds=array_keys($objectKinds);
        sort($objectKinds,SORT_STRING);
        // A store-level question can use every store-capable metric. For one
        // independently understood analytical dimension, however, bind only
        // candidates whose lower-layer contract explicitly declares that
        // dimension. The generic dimension Reader now owns those registered
        // dimensions; this projection must not keep a historical person-only
        // exception or let an incompatible metric enter a later follow-up.
        $matching=$summaries;
        if (count($objectKinds)===1 && $objectKinds[0]!=='store') {
            $objectKind=$objectKinds[0];$matching=[];
            foreach ($summaries as $summary) {
                foreach ((array)($summary['object_contracts']??[]) as $contract) {
                    if (($contract['object_kind']??null)===$objectKind) {
                        $matching[]=$summary;
                        break;
                    }
                }
            }
            if (!$matching) $matching=$summaries;
        }
        $operations=[];
        foreach (AiIntentUnderstandingContract::requirements($understanding) as $requirement) {
            if (!in_array('operation',(array)($requirement['fields']??[]),true)) continue;
            $operation=$requirement['values']['operation']??null;
            if (is_string($operation) && $operation!=='') $operations[$operation]=true;
        }
        // A validated generic condition already carries its result form. Use
        // that protocol mapping as the candidate-shape boundary even when a
        // legacy or repaired understanding omitted the redundant operation
        // field. This interprets no customer phrase and chooses no metric.
        if ($operations===[]) {
            foreach (AiIntentUnderstandingContract::requirements($understanding) as $requirement) {
                $condition=$requirement['values']['aggregate_condition']??null;
                if (!is_array($condition) || !isset($condition['conditions'])
                    || !in_array($condition['result_form']??null,['count','list'],true)) continue;
                $operations[$condition['result_form']==='count'?'condition_count':'condition_list']=true;
            }
        }
        if (count($operations)===1) {
            $operation=array_key_first($operations);
            $shapeMatching=array_values(array_filter($matching,static function(array $summary)use($operation):bool {
                return in_array($operation,(array)($summary['query_shapes']??[]),true);
            }));
            if ($shapeMatching) $matching=$shapeMatching;
        }
        // No match means the source registry has not registered this object
        // relation. Preserve the complete catalogue so the normal controlled
        // capability boundary can explain the gap; never turn it into a PHP
        // phrase-to-metric fallback.
        return $matching?:$summaries;
    }

    /**
     * Projects only source-registered business object names into the language
     * stage. The projection contains no metric, formula, identity, result or
     * authority data. New dimension registrations therefore become
     * understandable without adding question-specific PHP branches.
     *
     * @return array<int,array{object_kind:string,object_label:string}>
     */
    private function analysisObjectVocabulary(array $capabilities,?int $maxItems=16): array
    {
        // business_date is a shared fact dimension declared by the unified
        // data architecture. Publishing its customer labels lets the model
        // preserve “哪天/哪一日” as an analytical dimension without teaching
        // the gateway a question template or granting any metric capability.
        $items=[
            "business_date\0日期"=>['object_kind'=>'business_date','object_label'=>'日期'],
            "business_date\0哪天"=>['object_kind'=>'business_date','object_label'=>'哪天'],
            "business_date\0哪一天"=>['object_kind'=>'business_date','object_label'=>'哪一天'],
            "business_date\0哪一日"=>['object_kind'=>'business_date','object_label'=>'哪一日'],
        ];
        $registeredAliases=\app\services\query\metric\MetricDefinitionRegistry::analysisObjectAliases();
        $baseKinds=[];
        foreach ((array)($capabilities['metric_readiness']??[]) as $contract) {
            if (!is_array($contract) || ($contract['ai_query_ready']??false)!==true) continue;
            // A metric's registered fact grain is also a valid analytical
            // object even when no extra dimension contract is needed to read
            // it. Publish only a grain that owns source-registry aliases and
            // an analytical query shape. This grants neither a Reader nor a
            // filter; it prevents a base object such as store from vanishing
            // while optional dimensions remain visible to the language stage.
            $grain=$contract['filter_grain']??null;
            if (is_string($grain)&&isset($registeredAliases[$grain])
                &&array_intersect((array)($contract['query_shapes']??[]),['summary','breakdown','trend','ranking','comparison'])) {
                $baseKinds[$grain]=true;
            }
            foreach ((array)($contract['analysis_dimension_contracts']??[]) as $dimension) {
                $kind=$dimension['object_kind']??null;$label=$dimension['object_label']??null;
                if (!is_string($kind) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$kind)
                    || !is_string($label) || trim($label)==='' || mb_strlen($label,'UTF-8')>64) continue;
                $items[$kind."\0".$label]=['object_kind'=>$kind,'object_label'=>$label];
            }
        }
        // Aliases describe the same registered object kind; they grant no
        // metric or execution capability. Only aliases whose canonical kind
        // is present in the active capability set are projected.
        $activeKinds=$baseKinds;
        foreach ($items as $item) $activeKinds[$item['object_kind']]=true;
        foreach ($registeredAliases as $kind=>$aliases) {
            if (!isset($activeKinds[$kind])) continue;
            foreach ($aliases as $label) {
                if (!is_string($label) || trim($label)==='' || mb_strlen($label,'UTF-8')>64) continue;
                $items[$kind."\0".$label]=['object_kind'=>$kind,'object_label'=>$label];
            }
        }
        ksort($items,SORT_STRING);
        // The model transport accepts at most 16 vocabulary rows. Preserve
        // one source-owned label for every active kind before spending the
        // remaining budget on aliases; an alphabetical prefix would otherwise
        // drop a later kind such as store and recreate the semantic gap this
        // projection closes. This is a transport budget only and grants no
        // priority, metric or execution capability to any object kind.
        $primary=[];$aliases=[];$seenKinds=[];$canonicalLabels=[];
        foreach ($registeredAliases as $kind=>$labels) {
            $label=$labels[0]??null;
            if (is_string($label) && $label!=='') $canonicalLabels[$kind]=$label;
        }
        // Prefer one canonical registered label for each base object. Aliases
        // remain available in the exact projection below and fill spare model
        // vocabulary slots, but cannot replace the model-facing object name.
        foreach ($items as $item) {
            if (($canonicalLabels[$item['object_kind']]??null)!==$item['object_label']) continue;
            $primary[]=$item;$seenKinds[$item['object_kind']]=true;
        }
        foreach ($items as $item) {
            if (($canonicalLabels[$item['object_kind']]??null)===$item['object_label']) continue;
            if (!isset($seenKinds[$item['object_kind']])) {
                $primary[]=$item;$seenKinds[$item['object_kind']]=true;
            } else {
                $aliases[]=$item;
            }
        }
        $vocabulary=array_merge($primary,$aliases);
        return $maxItems===null?$vocabulary:array_slice($vocabulary,0,$maxItems);
    }

    /**
     * Projects the registered customer language of query-ready measurements
     * without exposing their executable codes. Understanding can therefore
     * tell a measurement phrase from an object phrase, while binding remains
     * registry-controlled and future registrations need no question branch.
     *
     * @return array<int,array{measurement_label:string,customer_terms:array<int,string>,meaning:string,analytical_object_kinds:array<int,string>}>
     */
    private function analysisMeasurementVocabulary(array $capabilities): array
    {
        // Understanding needs the language of registered-but-not-yet-readable
        // measurements too. Otherwise a clear request such as “超过 90 天没
        // 来” is misreported as incomprehensible before the binding stage can
        // truthfully classify it as unavailable. This vocabulary grants no
        // executable code; the later binding boundary still receives only
        // metric_codes whose Reader contract is ready.
        $registered=array_fill_keys(array_keys((array)($capabilities['metric_readiness']??[])),true);
        $items=[];
        foreach (\app\services\query\metric\MetricSemanticCatalog::entries() as $code=>$entry) {
            if (!isset($registered[$code])) continue;
            $label=trim((string)($entry['name']??''));
            $meaning=trim((string)($entry['summary']??''));
            $terms=[];
            foreach ((array)($entry['terms']??[]) as $term) if (is_string($term) && trim($term)!=='') $terms[trim($term)]=true;
            $contract=$capabilities['metric_readiness'][$code]??null;$objectKinds=[];
            if (is_array($contract) && is_string($contract['filter_grain']??null)) $objectKinds[$contract['filter_grain']]=true;
            foreach ((array)($contract['analysis_dimension_contracts']??[]) as $dimension) {
                if (is_array($dimension) && is_string($dimension['object_kind']??null)) $objectKinds[$dimension['object_kind']]=true;
            }
            // A shared section label can intentionally describe several
            // metrics. It is published as language guidance, not as a
            // one-label-to-one-metric shortcut; binding still accounts for
            // every metric selected for the customer's complete request.
            $overviewSections=is_array($contract)
                ? AiOverviewMetricResolver::sectionNamesForMetric($contract)
                : [];
            foreach ($overviewSections as $section) $terms[$section]=true;
            if ($overviewSections!==[]) $meaning.=' 经营概览分组：'.implode('、',$overviewSections).'。';
            if ($label==='' || $meaning==='' || $terms===[] || $objectKinds===[]) continue;
            $items[]=['measurement_label'=>$label,'customer_terms'=>array_slice(array_keys($terms),0,16),'meaning'=>$meaning,
                'analytical_object_kinds'=>array_slice(array_keys($objectKinds),0,8)];
        }
        return array_slice($items,0,32);
    }

    /**
     * Copies a model-selected registered code, never a phrase-derived PHP
     * choice. A system-owned default cohort may be replaced only by another
     * registered default; a named person, position or customer-selected role
     * remains protected by the regular context confirmation path.
     */
    private function applyReviewedMetricBinding(array $intent,string $metric,?array $sourceQuery,array $summaries): array
    {
        $defaults=[];
        foreach ($summaries as $summary) if (is_string($summary['metric_code']??null)) {
            $default=$summary['default_selection_ref']??null;
            $defaults[$summary['metric_code']]=is_string($default)&&$default!==''?$default:null;
        }
        if (!array_key_exists($metric,$defaults)) throw new RuntimeException('AI_BINDING_SEMANTIC_REJECTED');
        $intent['metric_codes']=[$metric];
        $intent['needs_metric_choice']=false;
        $intent['recommended_initial_answer']=false;
        $intent['initial_observation']=false;
        foreach ((array)($intent['requirement_bindings']??[]) as $index=>$binding) {
            if (!is_array($binding) || !is_string($binding['requirement_id']??null)) continue;
            $intent['requirement_bindings'][$index]=['requirement_id'=>$binding['requirement_id'],'status'=>'satisfied','metric_codes'=>[$metric]];
        }
        if ($sourceQuery===null) return $intent;
        $intent['context_delta']['metric_codes']='replace';
        $priorCodes=(array)($sourceQuery['metric_codes']??[]);
        $priorMetric=count($priorCodes)===1&&is_string($priorCodes[0])?$priorCodes[0]:null;
        $priorRef=$sourceQuery['business_filters']['selection_ref']??null;
        if (is_string($priorMetric) && is_string($priorRef) && isset($defaults[$priorMetric])
            && $defaults[$priorMetric]!==null && $priorRef===$defaults[$priorMetric]
            && $defaults[$metric]!==$defaults[$priorMetric]) {
            $intent['context_delta']['business_filters']='replace';
        }
        return $intent;
    }

    /**
     * Use only exact metric words already preserved by the understanding
     * contract. The lookup is registry-driven and constrained to the current
     * object's query-ready capabilities, so adding a new metric changes this
     * behavior through registration metadata rather than a question branch.
     */
    private function applyUniqueRegisteredMetricTermBinding(array $intent,array $understanding,array $summaries,?array $sourceQuery,array $safeQuestion): array
    {
        // A complete accepted condition set owns a positional metric for
        // every predicate. The single-exact-title recovery below must never
        // collapse that set to one attractive registry term and leave an
        // inconsistent query for the plan compiler to reject.
        if (in_array($intent['operation']??null,['condition_count','condition_list'],true)
            && is_array($intent['aggregate_condition']['conditions']??null)
            && count($intent['aggregate_condition']['conditions'])>1) return $intent;
        $requirements=[];
        foreach ((array)($understanding['requirements']??[]) as $requirement) {
            if (is_array($requirement) && in_array('metric_codes',(array)($requirement['fields']??[]),true)) $requirements[]=$requirement;
        }
        $values=$requirements===[]?[]:(array)($requirements[0]['values']??[]);
        foreach ($requirements as $requirement) if (!empty($requirement['values']['metric_exclusions'])) return $intent;
        $allowed=[];
        foreach ($summaries as $summary) {
            if (is_array($summary) && is_string($summary['metric_code']??null)) $allowed[]=$summary['metric_code'];
        }
        // The registry may own an exact current-question term even when the
        // model described the request only as a generic "performance" goal.
        // A unique exact match is safe to use without that model carrier;
        // fuzzy terms, exclusions and multiple candidates still remain under
        // semantic clarification and can never be supplied by this boundary.
        $exact=\app\services\query\metric\MetricSemanticCatalog::uniqueTermInText(
            (string)($safeQuestion['question']??''),$allowed
        );
        if ($requirements===[] && $exact===null) return $intent;
        $terms=$values['metric_terms']??[];
        if (!is_array($terms)) $terms=[];
        // A model may split one compound registered title into two metric
        // requirements (for example, two overlapping fragments). Converge
        // them only when every preserved term is contained in that one exact
        // title. A second independent or excluded measurement remains a
        // genuine multi-metric request and never enters this shortcut.
        if (count($requirements)>1) {
            if ($exact===null) return $intent;
            foreach ($requirements as $requirement) {
                $fragments=$requirement['values']['metric_terms']??null;
                if (!is_array($fragments) || $fragments===[]) return $intent;
                foreach ($fragments as $fragment) if (!is_string($fragment) || $fragment===''
                    || mb_strpos($exact['term'],$fragment,0,'UTF-8')===false) return $intent;
            }
        }
        $metric=$terms===[]?null:\app\services\query\metric\MetricSemanticCatalog::uniqueCodeForTerms($terms,$allowed);
        // The language pass can legitimately preserve a shorter umbrella term
        // (for example, a generic performance noun) even when the customer's
        // full current sentence contains one longer, exact registered
        // measurement name.  Prefer that unique registry-owned phrase over a
        // needless selector.  This never supplies a synonym, fuzzy match or
        // metric from PHP: ambiguous or unknown text still stays pending.
        if ($metric===null) $metric=is_array($exact)?($exact['metric_code']??null):null;
        if ($metric===null) return $intent;
        // A provider can return a complete-looking inherited or professional
        // metric even though the current customer sentence contains one
        // longer, exact, registry-owned measurement title. The old early
        // return accepted that stale code before this exact-term boundary was
        // consulted, which made a clear topic switch reopen selectors. Prefer
        // only this unique exact registered owner; exclusions, multiple
        // requirements and ambiguous terms still use semantic clarification.
        if (($intent['needs_metric_choice']??false)!==true
            && ($intent['metric_codes']??null)===[$metric]
            && ($sourceQuery===null || ($intent['context_delta']['metric_codes']??null)==='replace')) return $intent;
        // applyReviewedMetricBinding also repairs older reviewer candidates
        // whose binding rows all carried the selected code. For this direct
        // exact-term path, restore every non-metric row verbatim so an object,
        // period or ranking requirement cannot acquire metric authority.
        $originalBindings=(array)($intent['requirement_bindings']??[]);
        $metricRequirementId=$requirements[0]['id']??null;
        $metricRequirementIds=[];
        foreach ($requirements as $requirement) if (is_string($requirement['id']??null)) $metricRequirementIds[$requirement['id']]=true;
        $intent=$this->applyReviewedMetricBinding($intent,$metric,$sourceQuery,$summaries);
        if (is_string($metricRequirementId)) {
            foreach ($originalBindings as $index=>$binding) {
                if (!is_array($binding) || isset($metricRequirementIds[$binding['requirement_id']??''])) continue;
                $intent['requirement_bindings'][$index]=$binding;
            }
        }
        $statedFields=[];
        foreach ((array)($understanding['requirements']??[]) as $requirement) {
            if (!is_array($requirement)) continue;
            foreach ((array)($requirement['fields']??[]) as $field) if (is_string($field)) $statedFields[$field]=true;
        }
        if ($sourceQuery!==null && array_keys($statedFields)===['metric_codes']) {
            // A measurement-only continuation cannot authorize removing or
            // replacing the verified person/object cohort merely because the
            // binding model associates the new metric with a default role.
            // Retain every unrelated context field and replace only the one
            // field the accepted understanding says the customer changed.
            $intent['context_delta']=array_fill_keys(AiIntentResultContract::DELTA_FIELDS,'inherit');
            $intent['context_delta']['metric_codes']='replace';
        }
        if ($sourceQuery!==null
            && is_string($sourceQuery['start_date']??null)
            && is_string($sourceQuery['end_date']??null)
            && !isset($statedFields['periods'])
            && ($intent['needs_metric_choice']??true)===false
            && ($intent['metric_codes']??[])!==[]
            && in_array($intent['context_delta']['periods']??null,['clear','pending'],true)) {
            // Exact registry-term consolidation runs after the intent contract.
            // It can turn a model's provisional choice into an executable
            // answer, so retain the verified date here as well; otherwise the
            // earlier contract-level context default cannot see this final
            // executable shape and the UI asks a redundant date question.
            $intent['context_delta']['periods']='inherit';
        }
        return $intent;
    }

    /**
     * The vendor (or an injected test adapter) may recognize natural language,
     * but it only returns a bounded, declarative result shape.  This server
     * boundary deliberately does not interpret individual Chinese phrases.
     */
    private function semanticIntent($intent,array $capabilities,array $safeQuestion,array $understanding): array
    {
        $allowedActions=[];foreach($capabilities as $capability) foreach((array)($capability['object_contracts']??[]) as $contract) foreach((array)($contract['action_codes']??[]) as $action) if(is_string($action))$allowedActions[$action]=true;
        $allowedActions=array_keys($allowedActions);sort($allowedActions,SORT_STRING);
        $metricCodes=[];foreach($capabilities as $capability) if(is_string($capability['metric_code']??null)) $metricCodes[]=$capability['metric_code'];
        sort($metricCodes,SORT_STRING);
        $intent=$this->prepareUniqueExactMetricCandidate($intent,$understanding,$safeQuestion,$metricCodes);
        return AiIntentResultContract::normalize($intent,$metricCodes,$allowedActions,$safeQuestion,$understanding);
    }

    /**
     * Compile an accepted generic condition without another language-model
     * decision only when the strict intent contract can prove every ordered
     * term against one active registry metric. The contract also rechecks the
     * subject, response form, periods, relation, operators, quantities and
     * requirement ownership, so this is not a phrase shortcut or fallback.
     */
    private function registeredConditionIntent(array $understanding,array $capabilities,array $safeQuestion,?array &$failure=null,?array &$contractFailure=null): ?array
    {
        $hasCondition=false;
        foreach (AiIntentUnderstandingContract::requirements($understanding) as $requirement) {
            if (is_array($requirement['values']['aggregate_condition']??null)) {
                $hasCondition=true;
                break;
            }
        }
        if (!$hasCondition) return null;
        $seed=[
            'action_codes'=>[],'metric_codes'=>[],'needs_metric_choice'=>false,
            'object_kind'=>'unknown','object_term'=>'','object_relation'=>'analysis',
            'operation'=>'unknown','requirement_bindings'=>[],'unresolved_fragments'=>[],
            'ranking'=>['direction'=>'unspecified','limit'=>null],
            'periods'=>[],'scope'=>'unspecified',
            'initial_observation'=>false,'recommended_initial_answer'=>false,
        ];
        if (is_array($safeQuestion['prior_query']??null)) {
            // A complete current condition replaces the previous analytical
            // topic while retaining only authority/scope that the customer
            // did not change. Explicit deltas are required by the context
            // contract; omission must never be interpreted as inheritance.
            $seed['context_delta']=[
                'metric_codes'=>'replace','object'=>'replace','business_filters'=>'replace',
                'store_scope'=>'inherit','periods'=>'replace','operation'=>'replace',
                'ranking_direction'=>'clear','ranking_limit'=>'clear','scope'=>'inherit',
                'aggregate_condition'=>'replace',
            ];
        }
        try {
            $intent=$this->semanticIntent($seed,$capabilities,$safeQuestion,$understanding);
        } catch (AiContractException $error) {
            // Persist only the contract's bounded stage/predicate metadata;
            // customer wording, thresholds, metric codes and model output are
            // intentionally excluded from Run diagnostics.
            $failure=$error->diagnostic();
            // Keep the original bounded refusal in a separate Run slot: the
            // structural counter below cannot identify which guard rejected it.
            $contractFailure=$failure;
            $conditionCount=0;
            $conditionItems=[];
            foreach (AiIntentUnderstandingContract::requirements($understanding) as $requirement) {
                $items=$requirement['values']['aggregate_condition']['conditions']??null;
                if (is_array($items)) {$conditionCount+=count($items);foreach($items as $item)$conditionItems[]=$item;}
            }
            $matches=\app\services\query\metric\MetricSemanticCatalog::registeredNonOverlappingTermsInText(
                (string)($safeQuestion['question']??''),array_column($capabilities,'metric_code')
            );
            $metricCodes=array_values(array_filter(array_column($capabilities,'metric_code'),'is_string'));
            $projectionFailure=null;
            $prepared=AiIntentResultContract::canonicalizeUniqueExactMetricBinding(
                $seed,$understanding,$safeQuestion,$metricCodes,$projectionFailure
            );
            $contracts=\app\services\query\metric\MetricDefinitionRegistry::capabilities();$unitCompatible=0;$subjectCompatible=0;
            foreach ($conditionItems as $item) foreach ($matches as $match) {
                $contract=$contracts[$match['metric_code']??'']??null;
                if (!is_array($contract)||($contract['condition_unit']??null)!==($item['unit']??null)) continue;
                $unitCompatible++;
                $subject=$item['subject']??null;
                if ($subject===null) foreach (AiIntentUnderstandingContract::requirements($understanding) as $requirement) {
                    if (in_array($item,(array)($requirement['values']['aggregate_condition']['conditions']??[]),true)) {
                        $subject=$requirement['values']['aggregate_condition']['subject']??null;break;
                    }
                }
                if (is_string($subject)&&in_array($subject,(array)($contract['condition_subjects']??[]),true)) $subjectCompatible++;
            }
            $metricRequirementRows=0;$ownedConditionRows=0;$requirementTerms=0;$resolvedRequirementTerms=0;$conditionOwnedRequirementTerms=0;
            $conditionOwnerCodes=array_fill_keys(array_values(array_filter(array_column($matches,'metric_code'),'is_string')),true);
            foreach (AiIntentUnderstandingContract::requirements($understanding) as $requirement) {
                if (!in_array('metric_codes',(array)($requirement['fields']??[]),true)) continue;
                $metricRequirementRows++;
                if (!empty($requirement['values']['aggregate_condition']['conditions'])) $ownedConditionRows++;
                foreach ((array)($requirement['values']['metric_terms']??[]) as $term) {
                    if (!is_string($term)||$term==='') continue;
                    $requirementTerms++;
                    $code=\app\services\query\metric\MetricSemanticCatalog::uniqueCodeForTerms([$term],$metricCodes);
                    if ($code===null) {
                        $exact=\app\services\query\metric\MetricSemanticCatalog::uniqueTermInText($term,$metricCodes);
                        $code=is_array($exact)&&is_string($exact['metric_code']??null)?$exact['metric_code']:null;
                    }
                    if ($code!==null) $resolvedRequirementTerms++;
                    if ($code!==null&&isset($conditionOwnerCodes[$code])) $conditionOwnedRequirementTerms++;
                }
            }
            // Encode bounded shape counters in the payload-free predicate so
            // the existing diagnostic contract remains closed: no wording,
            // thresholds, metric identifiers or returned values are stored.
            $preparedOperation=in_array($prepared['operation']??null,['condition_count','condition_list'],true)?1:0;
            $failure['predicate']='rcc_c'.min(9,$conditionCount).'_t'.min(9,count($matches))
                .'_u'.min(9,$unitCompatible).'_s'.min(9,$subjectCompatible)
                .'_o'.$preparedOperation.'_m'.min(9,count((array)($prepared['metric_codes']??[])))
                .'_c'.min(9,count((array)($prepared['aggregate_condition']['conditions']??[])))
                .'_r'.min(9,count((array)($prepared['requirement_bindings']??[])))
                .'_q'.min(9,$metricRequirementRows).'_a'.min(9,$ownedConditionRows)
                .'_t'.min(9,$requirementTerms).'_v'.min(9,$resolvedRequirementTerms).'_w'.min(9,$conditionOwnedRequirementTerms)
                .'_f_'.(is_string($projectionFailure)&&preg_match('/^[a-z_]{1,32}$/D',$projectionFailure)?$projectionFailure:'unknown');
            return null;
        }
        if (!in_array($intent['operation']??null,['condition_count','condition_list'],true)
            || !is_array($intent['aggregate_condition']??null)
            || ($intent['metric_codes']??[])===[]
            || !empty($intent['needs_metric_choice'])
            || !empty($intent['unresolved_fragments'])) return null;
        return $intent;
    }

    /**
     * Canonicalizes only a unique exact registry title before the strict
     * intent contract. This keeps provider JSON bookkeeping errors (unknown
     * codes or missing/duplicated requirement rows) from hiding a measurement
     * the customer stated verbatim. Ambiguous phrases, exclusions and
     * genuinely independent metric requirements remain model-owned.
     */
    private function prepareUniqueExactMetricCandidate($intent,array $understanding,array $safeQuestion,array $metricCodes)
    {
        return AiIntentResultContract::canonicalizeUniqueExactMetricBinding(
            $intent,$understanding,$safeQuestion,$metricCodes
        );
    }

    /**
     * A verified per-store breakdown filter describes result grain, not an
     * authorization restriction. When a self-contained current requirement
     * explicitly changes that same store subject to an aggregate summary,
     * clear only the inherited analytical filter while retaining signed store
     * IDs and every other authority boundary. This is driven by typed accepted
     * semantics and never by a customer phrase or metric name.
     */
    private static function normalizeAggregateStoreContextDelta($intent,array $understanding,?array $sourceQuery)
    {
        if (!is_array($intent) || $sourceQuery===null
            || ($sourceQuery['business_filters']??null)!==['object_kind'=>'store']
            || ($intent['object_kind']??null)!=='store'
            || ($intent['object_relation']??null)!=='analysis'
            || ($intent['object_term']??null)!==''
            || ($intent['operation']??null)!=='summary'
            || !is_array($intent['context_delta']??null)) return $intent;
        $object=false;$operation=false;$metric=false;
        foreach (AiIntentUnderstandingContract::requirements($understanding) as $requirement) {
            $values=(array)($requirement['values']??[]);
            if (($values['object_kind']??null)==='store' && ($values['object_relation']??null)==='analysis') $object=true;
            if (($values['operation']??null)==='summary') $operation=true;
            if (in_array('metric_codes',(array)($requirement['fields']??[]),true)) $metric=true;
        }
        if (!$object || !$operation || !$metric) return $intent;
        $intent['context_delta']['business_filters']='clear';
        // Aggregation changes presentation grain only. A previously selected
        // authorized store range remains the query boundary.
        $intent['context_delta']['store_scope']='inherit';
        return $intent;
    }

    /**
     * The transport timeout is calculated at the send boundary, from the
     * frozen Run budget and the live database deadline.  This avoids treating
     * a configuration default as permission to outlive a partially consumed
     * Run, while keeping the model client unaware of Run persistence.
     */
    private function modelCallTimeout(array $owner,string $id,int $generation,string $worker,int $stageLimitMs): int
    {
        if ($stageLimitMs<1) throw new RuntimeException('AI_MODEL_TIMEOUT_INVALID');
        $run=$this->runs->checkpoint($owner,$id,$generation,$worker);
        $snapshot=$this->runs->snapshot($owner,$id,$generation);
        $version=$snapshot['budget_profile_version']??null;
        if (!is_string($version) || !preg_match('/^([0-9]{6})-v1$/D',$version,$matches)) {
            throw new RuntimeException('AI_SNAPSHOT_CORRUPT');
        }
        $profile=AiRunBudgetPolicy::defaults();
        $profile['run_execution_budget_ms']=(int)$matches[1];
        $now=(int)floor(microtime(true)*1000);
        $remaining=max(0,(int)$run['deadline_at']-$now);
        $state=AiRunBudgetPolicy::create($profile,$now);
        $state['remaining_execution_ms']=min($profile['run_execution_budget_ms'],$remaining);
        $state['execution_deadline_ms']=$now+$state['remaining_execution_ms'];
        return AiRunBudgetPolicy::callTimeout($state,'model',$stageLimitMs,$now);
    }

    /**
     * Repairs one model-authored context shape, not customer meaning.  This
     * shares the global one-recovery budget with all other provider repairs,
     * so an unstable response cannot create an unbounded second dialogue or
     * hide latency behind automatic retries.
     */
    private function repairBindingCandidate(array $owner,string $id,int $generation,string $worker,array $safeQuestion,array $summaries,array $understanding,array $configuration,callable $checkpoint,array $runtimeSkills,string $predicate): array
    {
        if (!AiIntentResultContract::repairableFormat($predicate)) throw new RuntimeException('AI_MODEL_INPUT_INVALID');
        $this->runs->reserve($owner,$id,$generation,$worker,'model_recovery_count');
        $this->runs->reserve($owner,$id,$generation,$worker,'input_tokens',$this->inputTokenReservation([$safeQuestion,$understanding,$summaries,$runtimeSkills,$predicate],2304));
        $this->runs->reserve($owner,$id,$generation,$worker,'output_tokens',1200);
        $this->runs->prepareAttempt($owner,$id,$generation,$worker,'bind_candidate_repair','model',hash('sha256',json_encode([$safeQuestion,$understanding,$summaries,$predicate])),'siliconflow');
        $this->runs->sendAttempt($owner,$id,$generation,$worker,'bind_candidate_repair');
        try {
            $checkpoint();
            $modelTimeout=$this->model===null ? $this->modelCallTimeout($owner,$id,$generation,$worker,self::MODEL_STAGE_LIMIT_MS) : null;
            $reply=$this->model
                ? call_user_func($this->model,$safeQuestion,$summaries,$configuration,$checkpoint,$predicate,'binding',$understanding)
                : (new SiliconFlowClient($configuration['provider']))->understand($safeQuestion,$summaries,$understanding,$configuration['model'],$configuration['api_key'],$modelTimeout,$checkpoint,$runtimeSkills,$predicate);
            $reply['intent']=$this->semanticIntent($reply['intent']??null,$summaries,$safeQuestion,$understanding);
            $this->runs->finishAttempt($owner,$id,$generation,$worker,'bind_candidate_repair','SUCCEEDED',$reply['usage']['input_tokens']??null,$reply['usage']['output_tokens']??null);
            return $reply;
        } catch (\Throwable $error) {
            if ($error instanceof AiContractException) $this->recordModelDiagnostic($owner,$id,$generation,$worker,$error,'bind_candidate_repair');
            $state=in_array($error->getMessage(),['AI_MODEL_RESULT_UNKNOWN','AI_CANCELLED','AI_AUTHORIZATION_CHANGED'],true)?'UNKNOWN':'FAILED';
            try { $this->runs->finishAttempt($owner,$id,$generation,$worker,'bind_candidate_repair',$state); } catch (\Throwable $ignored) {}
            throw $error;
        }
    }

    /**
     * A ranking needs one comparable measurement.  The binding model may
     * nevertheless identify several faithful registered candidates for a
     * broad request such as "whose performance is best".  Let the model make
     * that professional first-answer choice in one separately auditable call;
     * PHP only verifies that its answer is one of the already supplied codes.
     */
    private function resolveRankMetricBinding(array $owner,string $id,int $generation,string $worker,array $safeQuestion,array $summaries,array $understanding,array $configuration,callable $checkpoint,array $reply): array
    {
        $candidates=$reply['rank_metric_candidates']??null;
        $known=[];foreach($summaries as $summary) if(is_string($summary['metric_code']??null)) $known[$summary['metric_code']]=true;
        if (!is_array($candidates) || count($candidates)<2 || count($candidates)>4
            || count(array_unique($candidates,SORT_REGULAR))!==count($candidates)) throw new AiContractException('AI_MODEL_INPUT_INVALID');
        foreach($candidates as $code) if(!is_string($code)||!isset($known[$code])) throw new AiContractException('AI_MODEL_INPUT_INVALID');
        $candidates=array_values($candidates);
        $this->runs->reserve($owner,$id,$generation,$worker,'model_recovery_count');
        $this->runs->reserve($owner,$id,$generation,$worker,'input_tokens',$this->inputTokenReservation([$safeQuestion,$understanding,$summaries,$candidates],1024));
        $this->runs->reserve($owner,$id,$generation,$worker,'output_tokens',300);
        $this->runs->prepareAttempt($owner,$id,$generation,$worker,'bind_rank_metric_choice','model',hash('sha256',json_encode([$safeQuestion,$understanding,$candidates])),'siliconflow');
        $this->runs->sendAttempt($owner,$id,$generation,$worker,'bind_rank_metric_choice');
        try {
            $checkpoint();
            if ($this->model) {
                $selectionReply=call_user_func($this->model,$safeQuestion,['rank_metric_candidates'=>$candidates,'capabilities'=>$summaries],$configuration,$checkpoint,null,'rank_metric_selection',$understanding);
                $selection=$selectionReply['selection']??null;
                $usage=$selectionReply['usage']??[];
            } else {
                $modelTimeout=$this->modelCallTimeout($owner,$id,$generation,$worker,self::MODEL_STAGE_LIMIT_MS);
                $selection=(new SiliconFlowClient($configuration['provider']))->selectRankMetric($safeQuestion,$understanding,$summaries,$candidates,$configuration['model'],$configuration['api_key'],$modelTimeout,$checkpoint);
                $usage=$selection['usage']??[];
            }
            if (!is_array($selection) || !in_array($selection['decision']??null,['select','clarify'],true)
                || (($selection['decision']??null)==='select' && (!is_string($selection['metric_code']??null) || !in_array($selection['metric_code'],$candidates,true)))
                || (($selection['decision']??null)==='clarify' && ($selection['metric_code']??null)!==null)) {
                throw new AiContractException('AI_MODEL_INTENT_CONTRACT_INVALID',['stage'=>'intent_contract','predicate'=>'rank_metric_resolution']);
            }
            if ($selection['decision']==='clarify') {
                // A model may honestly see several registered candidates for
                // a broad ranking. Before showing a selector, apply the one
                // registry-declared first-answer perspective only when the
                // accepted shape carries no condition, exclusion or selected
                // object that a default could silently replace. The normal
                // independent semantic review below remains mandatory.
                $defaultMetric=$this->registeredRankDefault($reply['intent']??[],$understanding,$summaries,$candidates);
                if ($defaultMetric!==null) {
                    $selection=['decision'=>'select','metric_code'=>$defaultMetric];
                    $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'registered_rank_default_applied',[],
                        'registered_rank_default');
                }
            }
            $reply['intent']=($selection['decision']==='select')
                ? SiliconFlowClient::applyRankMetricResolution($reply['intent']??[],$selection,$understanding)
                : SiliconFlowClient::applyRankMetricClarification($reply['intent']??[],$understanding);
            $reply['intent']=$this->semanticIntent($reply['intent'],$summaries,$safeQuestion,$understanding);
            unset($reply['rank_metric_candidates']);
            $this->runs->finishAttempt($owner,$id,$generation,$worker,'bind_rank_metric_choice','SUCCEEDED',$usage['input_tokens']??null,$usage['output_tokens']??null);
            return $reply;
        } catch (\Throwable $error) {
            if ($error instanceof AiContractException) $this->recordModelDiagnostic($owner,$id,$generation,$worker,$error,'bind_rank_metric_choice');
            $state=in_array($error->getMessage(),['AI_MODEL_RESULT_UNKNOWN','AI_CANCELLED','AI_AUTHORIZATION_CHANGED'],true)?'UNKNOWN':'FAILED';
            try { $this->runs->finishAttempt($owner,$id,$generation,$worker,'bind_rank_metric_choice',$state); } catch (\Throwable $ignored) {}
            throw $error;
        }
    }

    /**
     * Returns one source-owned ranking default only for a model-proposed
     * candidate set that is safe to present as a first answer. This does not
     * interpret customer text or manufacture a metric: the model has already
     * fixed the analytical object, operation and compatible candidates, while
     * the registry owns the optional product default and the later reviewer
     * can still reject a semantic mismatch.
     */
    private function registeredRankDefault(array $intent,array $understanding,array $summaries,array $candidateCodes): ?string
    {
        if (!AiIntentResultContract::canUseRegisteredRankDefault($understanding,$intent)) return null;
        $objectKind=$intent['object_kind']??null;
        if (!is_string($objectKind)) return null;
        $defaults=[];
        foreach ($summaries as $summary) {
            $code=$summary['metric_code']??null;
            if (!is_string($code) || !in_array($code,$candidateCodes,true)) continue;
            $kinds=$summary['default_rank_object_kinds']??[];
            if (is_array($kinds) && in_array($objectKind,$kinds,true)) $defaults[$code]=true;
        }
        return count($defaults)===1 ? array_key_first($defaults) : null;
    }

    /**
     * Converges every broad-ranking entry path on the registry-owned default.
     * Exact registered customer wording narrows the candidate boundary first;
     * PHP never maps a free-form phrase or invents a metric. Unknown goals
     * retain the normal unbound/clarification outcome.
     */
    private function applyRegisteredRankDefaultPolicy(array $intent,array $understanding,array $summaries): array
    {
        if (!AiIntentResultContract::canUseRegisteredRankDefault($understanding,$intent)) return $intent;
        $allowed=array_values(array_filter(array_column($summaries,'metric_code'),'is_string'));
        $terms=[];
        foreach (\app\services\ai\contract\AiIntentUnderstandingContract::requirements($understanding) as $requirement) {
            if (!in_array('metric_codes',(array)($requirement['fields']??[]),true)) continue;
            // An already bound customer measurement must survive even when
            // its ordinary-language wording has no exact dictionary alias.
            // Registry recommendations may only replace an explicitly
            // recommended perspective, never an unmarked quantity request.
            if (!empty($intent['metric_codes']) && empty($intent['recommended_initial_answer'])) return $intent;
            foreach ((array)($requirement['values']['metric_terms']??[]) as $term) if (is_string($term)) $terms[]=$term;
        }
        $exact=\app\services\query\metric\MetricSemanticCatalog::uniqueCodeForTerms($terms,$allowed);
        $candidateCodes=$exact===null?$allowed:[$exact];
        $default=$this->registeredRankDefault($intent,$understanding,$summaries,$candidateCodes);
        if ($default===null) return $intent;
        $current=array_values((array)($intent['metric_codes']??[]));
        if ($current===[$default] && !empty($intent['recommended_initial_answer'])
            && empty($intent['needs_metric_choice'])) return $intent;
        return SiliconFlowClient::applyRankMetricResolution(
            $intent,['decision'=>'select','metric_code'=>$default],$understanding
        );
    }

    /**
     * Select one registered first-answer column for a broad per-object
     * breakdown. The understanding model has already fixed the object and
     * response form; the registry, rather than a phrase branch, owns the
     * professional default. Zero or several declarations remain a normal
     * customer choice so configuration mistakes fail closed.
     */
    private function applyRegisteredBreakdownDefaultPolicy(array $intent,array $understanding,array $summaries): array
    {
        $default=$this->registeredBreakdownDefaultCode($intent,$understanding,$summaries);
        if ($default===null) return $intent;
        if (($intent['metric_codes']??null)===[$default]
            && empty($intent['needs_metric_choice'])
            && !empty($intent['recommended_initial_answer'])) return $intent;
        return SiliconFlowClient::applyRankMetricResolution(
            $intent,['decision'=>'select','metric_code'=>$default],$understanding
        );
    }

    /** A source-owned broad default is already semantically admitted by its
     * narrow structural gate and visible metric label; it needs no second
     * model opinion that can turn the same policy into a random failure. */
    private function isRegisteredBreakdownDefaultBinding(array $intent,array $understanding,array $summaries): bool
    {
        $default=$this->registeredBreakdownDefaultCode($intent,$understanding,$summaries);
        return $default!==null && ($intent['metric_codes']??null)===[$default]
            && !empty($intent['recommended_initial_answer']) && empty($intent['needs_metric_choice']);
    }

    /** Return the sole registry-owned default for this accepted object. */
    private function registeredBreakdownDefaultCode(array $intent,array $understanding,array $summaries): ?string
    {
        if (!AiIntentResultContract::canUseRegisteredBreakdownDefault($understanding,$intent)) return null;
        $objectKind=$intent['object_kind']??null;
        if (!is_string($objectKind)) return null;
        $defaults=[];
        foreach ($summaries as $summary) {
            $code=$summary['metric_code']??null;
            $kinds=$summary['default_breakdown_object_kinds']??[];
            if (is_string($code)&&is_array($kinds)&&in_array($objectKind,$kinds,true)) $defaults[$code]=true;
        }
        return count($defaults)===1?array_key_first($defaults):null;
    }

    /**
     * Reservations are token budgets, while PHP string length is bytes.
     * Counting UTF-8 bytes directly makes Chinese source Skills consume about
     * three times their budget before any provider request is sent.
     */
    private function inputTokenReservation(array $payload,int $margin): int
    {
        $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $margin<1) throw new RuntimeException('AI_MODEL_INPUT_INVALID');
        return max(1,mb_strlen($json,'UTF-8'),(int)ceil(strlen($json)/4))+$margin;
    }

    /** Store only bounded structural metadata; neither question nor model text is retained. */
    private function recordModelDiagnostic(array $owner,string $id,int $generation,string $worker,AiContractException $error,string $attemptCode=''): void
    {
        $diagnostic=$error->diagnostic();
        if (!$diagnostic) $diagnostic=['stage'=>'intent_parse','predicate'=>'error_reason:'.strtolower($error->reason())];
        try { $this->runs->recordDiagnostic($owner,$id,$generation,$worker,$diagnostic,$attemptCode); } catch (\Throwable $ignored) {}
    }

    /** Records a payload-free execution boundary for support and acceptance. */
    private function recordRuntimeDiagnostic(array $owner,string $id,int $generation,string $worker,string $predicate,array $details=[],string $attemptCode=''): void
    {
        try { $this->runs->recordDiagnostic($owner,$id,$generation,$worker,
            array_merge(['stage'=>'analysis_binding','predicate'=>$predicate],$details),$attemptCode); } catch (\Throwable $ignored) {}
    }

    /** A bounded semantic date value from the model becomes a trusted explicit
     * range before any workflow is compiled.  No customer phrase is matched here.
     */
    private function naturalPeriodTerms(array $periods,string $today): array
    {
        $out=[];foreach ($periods as $period) $out=array_merge($out,$this->naturalPeriodTermsOne($period,$today));return $out;
    }

    private function naturalPeriodTermsOne(array $period,string $today): array
    {
        $range=(new AiWorkflowPlanner())->normalizeNaturalPeriod($period,$today);
        return [['code'=>'EXPLICIT','start'=>$range['start'],'end'=>$range['end']]];
    }

    /** A time-only utterance needs a signed business predecessor; model defaults cannot create one. */
    private function unanchoredCalendarQuestion(string $question,array $understanding): bool
    {
        $periods=[];
        foreach ((array)($understanding['requirements']??[]) as $requirement) {
            foreach ((array)($requirement['values']['periods']??[]) as $period) {
                if (is_array($period)) $periods[json_encode($period)]=$period;
            }
        }
        return count($periods)===1 && \app\services\ai\semantic\AiSemanticIntentParser::modelOnlyCalendarContinuation(
            $question,array_values($periods));
    }

    /**
     * Performs the semantic admission once for every candidate metric binding.
     * It deliberately runs before a pending context field is converted to a
     * private fallback, so all execution paths share the same admission gate.
     */
    private function reviewSemanticBinding(array $owner,string $id,int $generation,string $worker,array $safeQuestion,array $summaries,array $understanding,array $intent,array $configuration,callable $checkpoint,bool $customerConfirmedChoice=false,string $attemptCode='review_binding'): string
    {
        $this->runs->reserve($owner,$id,$generation,$worker,'stage_count');
        $reviewInput=['question'=>$safeQuestion['question'],'reference_date'=>$safeQuestion['reference_date']];
        $this->runs->reserve($owner,$id,$generation,$worker,'input_tokens',$this->inputTokenReservation([$reviewInput,$understanding,$intent,$summaries],1024));
        $this->runs->reserve($owner,$id,$generation,$worker,'output_tokens',300);
        $this->runs->prepareAttempt($owner,$id,$generation,$worker,$attemptCode,'model',hash('sha256',json_encode([$reviewInput,$understanding,$intent,$summaries])),'siliconflow');
        $this->runs->sendAttempt($owner,$id,$generation,$worker,$attemptCode);
        try {
            $checkpoint();
            $modelTimeout=$this->model===null ? $this->modelCallTimeout($owner,$id,$generation,$worker,self::MODEL_STAGE_LIMIT_MS) : null;
            $reply=$this->model
                ? call_user_func($this->model,$reviewInput,['candidate_binding'=>$intent,'capabilities'=>$summaries],$configuration,$checkpoint,null,'binding_verification',$understanding)
                : (new SiliconFlowClient($configuration['provider']))->verifyBinding($safeQuestion,$summaries,$understanding,$intent,$configuration['model'],$configuration['api_key'],$modelTimeout,$checkpoint,$customerConfirmedChoice);
            $review=AiIntentResultContract::normalizeSemanticReview($reply['review']??null,$understanding);
            // Only the explicitly candidate-blind uniqueness pass may defer a
            // metric to the registered choice UI. A coverage reviewer has a
            // proposed binding and can only accept it or reject a conflicting
            // requirement. Treating an arbitrary metric_choice as a deferment
            // would erase exclusions before the final semantic gate.
            if ($review['decision']==='reject' && ($reply['review_kind']??null)==='candidate_blind_uniqueness'
                && is_string($reply['model_metric_code']??null)
                && in_array($reply['model_metric_code'],array_column($summaries,'metric_code'),true)) {
                $this->runs->finishAttempt($owner,$id,$generation,$worker,$attemptCode,'SUCCEEDED',$reply['usage']['input_tokens']??null,$reply['usage']['output_tokens']??null);
                return 'model_metric_rebind:'.$reply['model_metric_code'];
            }
            if ($review['decision']==='metric_choice' && !$customerConfirmedChoice
                && (($reply['review_kind']??null)==='candidate_blind_uniqueness')
                && AiIntentResultContract::canUseCandidateBlindMetricReview($understanding,$intent)) {
                $this->runs->finishAttempt($owner,$id,$generation,$worker,$attemptCode,'SUCCEEDED',$reply['usage']['input_tokens']??null,$reply['usage']['output_tokens']??null);
                return 'metric_choice';
            }
            if ($review['decision']==='metric_choice') {
                $this->runs->recordDiagnostic($owner,$id,$generation,$worker,['stage'=>'binding_review','predicate'=>'unexpected_metric_choice'],$attemptCode);
                throw new RuntimeException('AI_BINDING_SEMANTIC_REJECTED');
            }
            if ($review['decision']!=='accept') {
                $this->runs->recordDiagnostic($owner,$id,$generation,$worker,['stage'=>'binding_review','predicate'=>'semantic_requirement_rejected'],$attemptCode);
                throw new RuntimeException('AI_BINDING_SEMANTIC_REJECTED');
            }
            $this->runs->finishAttempt($owner,$id,$generation,$worker,$attemptCode,'SUCCEEDED',$reply['usage']['input_tokens']??null,$reply['usage']['output_tokens']??null);
            return 'accept';
        } catch (\Throwable $error) {
            if ($error instanceof AiContractException) $this->recordModelDiagnostic($owner,$id,$generation,$worker,$error,$attemptCode);
            $state=in_array($error->getMessage(),['AI_MODEL_RESULT_UNKNOWN','AI_CANCELLED','AI_AUTHORIZATION_CHANGED'],true)?'UNKNOWN':'FAILED';
            $this->runs->finishAttempt($owner,$id,$generation,$worker,$attemptCode,$state);
            throw $error;
        }
    }

    /**
     * Admits only a pure detail continuation. Mixed requests establish their
     * authorised population through the condition-list path before assets.
     */
    private function compileObjectDetailContinuation(array $understanding,$sourceContext,string $outputFormat,?array &$rejection=null): ?array
    {
        if (($understanding['status']??null)!=='understood' || ($understanding['topic_relation']??null)==='new_topic') return null;
        $detail=null;$reference=null;$statedObjectKind=null;
        foreach ((array)($understanding['requirements']??[]) as $requirement) {
            $fields=(array)($requirement['fields']??[]);
            if (in_array('result_reference',$fields,true)) {
                if ($reference!==null) return null;
                $reference=$requirement['values']['result_reference']??null;
            }
            if (in_array('object_detail',$fields,true)||in_array('member_detail',$fields,true)) {
                if ($detail!==null) throw new RuntimeException('AI_INTENT_UNRESOLVED');
                // member_detail remains accepted only as a compatibility input
                // for stored/test envelopes; new model instructions emit the
                // generic object_detail carrier.
                $detail=$requirement['values']['object_detail']??$requirement['values']['member_detail']??null;
            }
        }
        if (!is_array($detail) && !is_array($reference)) return null;
        // A bare displayed position requests that frozen object's detail.
        // Additional detail instructions or independently grouped questions
        // stay on normal binding; no ordinal is inferred or flattened here.
        if ($reference!==null && ($detail!==null || !empty($understanding['groups']))) return null;
        foreach ((array)($understanding['requirements']??[]) as $requirement) {
            $fields=(array)($requirement['fields']??[]);
            // A model can echo the predecessor's unchanged ranking beside a
            // pure detail request. The signed query, not that echo, remains
            // authoritative; discard only an exactly matching carrier. A
            // new rank or any metric/period/filter still enters normal binding.
            if ($reference===null && is_array($sourceContext['query']??null)
                &&($sourceContext['query']['query_shape']??null)==='ranking') {
                // A model may describe expanding the signed ranked set as a
                // summary or breakdown. Those are presentation words here,
                // not permission to recompute a different population. A new
                // metric, period, filter or changed rank still fails below.
                if (in_array('operation',$fields,true)
                    && (($requirement['values']['operation']??null)==='ranking'
                        || (($detail['target']??null)==='set'
                            && in_array($requirement['values']['operation']??null,['summary','breakdown'],true)))) {
                    $fields=array_values(array_diff($fields,['operation']));
                }
                if (in_array('ranking',$fields,true)
                    &&($requirement['values']['ranking']??null)===($sourceContext['query']['ranking']??null)) {
                    $fields=array_values(array_diff($fields,['ranking']));
                }
                // "Authorized" is only a redundant echo when the signed
                // predecessor already used the complete authorised store
                // range. A selected store must never be widened by this
                // presentation shortcut.
                if (in_array('scope',$fields,true)
                    &&($requirement['values']['scope']??null)==='authorized'
                    &&($sourceContext['query']['store_ids']??null)===[]
                    &&!isset($sourceContext['query']['business_filters']['selection_ref'])) {
                    $fields=array_values(array_diff($fields,['scope']));
                }
            }
            // A pure continuation may restate only its referenced object. Any
            // metric, period, condition, ranking or operation must go through
            // the normal registered query planner first.
            $allowed=['object_detail','member_detail','object_kind','object_relation'];
            if ($reference!==null) $allowed[]='result_reference';
            $extra=array_values(array_diff($fields,$allowed));
            if ($extra!==[]) {
                $field=$extra[0];
                $rejection=['field'=>is_string($field)&&preg_match('/^[a-z_]{1,32}$/D',$field)?$field:'other'];
                return null;
            }
            if (in_array('object_kind',$fields,true)) {
                $kind=$requirement['values']['object_kind']??null;
                if (!is_string($kind)||($statedObjectKind!==null&&$statedObjectKind!==$kind)) return null;
                $statedObjectKind=$kind;
            }
        }
        if (!is_array($sourceContext)||!is_array($sourceContext['query']??null)
            ||!is_array($sourceContext['view']??null)) throw new RuntimeException('AI_CONTEXT_REQUIRED');
        if ($reference!==null) {
            // group+ordinal is resolved by the existing immutable-view guard.
            // In particular, bottom #2 cannot become top #2 or a new TOP 1.
            $selection=ResultReferenceResolver::resolve($sourceContext['query'],$sourceContext['view'],$reference);
            $resolved=['object_kind'=>$selection['object_kind']];
            if ($selection['object_kind']==='person') $resolved['selection_ref']=$selection['business_filters']['selection_ref'];
            else $resolved['store_id']=$selection['store_ids'][0];
        } else {
            $resolved=(new \app\services\ai\context\ObjectDetailContinuationResolver())->resolve(
                $sourceContext['query'],$sourceContext['view'],$detail
            );
        }
        // An explicitly stated object may narrow what the customer means but
        // may never overwrite the object proven by the preceding result.
        if ($statedObjectKind!==null&&$statedObjectKind!==($resolved['object_kind']??null)) {
            throw new RuntimeException('AI_RESULT_REFERENCE_UNAVAILABLE');
        }
        if (($resolved['object_kind']??null)==='member') {
            unset($resolved['object_kind']);
            return ['kind'=>'member_detail','member_detail'=>$resolved+[
                'source_query'=>$sourceContext['query'],
                'source_view_ref'=>$sourceContext['view']['read_consistency_ref']??null,
                'source_expires_at'=>$sourceContext['view']['expires_at']??null,
            ],'_context_meaning'=>(array)($sourceContext['meaning']??[])];
        }
        if (isset($resolved['objects'])) {
            // A repeated request for an already materialized detail set must
            // replay it, not re-run TOP N and silently replace its identities.
            $details=$sourceContext['view']['results'][0]['ranking_detail_metrics']??$sourceContext['view']['results'][0]['ranking_presentation_metrics']??[];
            if (!$details) throw new RuntimeException('AI_OBJECT_DETAIL_NOT_READY');
            return ['kind'=>'verified_result_presentation','query'=>$sourceContext['query'],
                'view_ref'=>$sourceContext['view']['read_consistency_ref']??null,
                '_context_meaning'=>(array)($sourceContext['meaning']??[])];
        }
        // Person/store detail currently means a context-relevant verified
        // overview: reuse the exact preceding metric and period, replace only
        // ranking with one server-resolved object selection, and run through
        // the ordinary registry compiler and permission checks.
        $query=$sourceContext['query'];
        $query['ranking']=null;$query['ranking_presentation_metrics']=[];
        $profile=\app\services\ai\execution\AiRankingPresentationMetricResolver::detail(
            MetricReadViewServices::metricCapabilities(),$query['metric_codes'][0],$resolved['object_kind']);
        if ($profile) $query['metric_codes']=$profile;
        if ($resolved['object_kind']==='person') {
            $query['query_shape']='summary';
            $query['business_filters']=['object_kind'=>'person','selection_ref'=>$resolved['selection_ref']];
        } elseif ($resolved['object_kind']==='store') {
            // A one-row store breakdown keeps the verified store name visible
            // in the answer. A plain summary would show only the amount and
            // leave the customer unable to verify which prior store it used.
            $query['query_shape']='breakdown';$query['store_ids']=[$resolved['store_id']];
            $query['business_filters']=['object_kind'=>'store'];
        } else {
            throw new RuntimeException('AI_OBJECT_DETAIL_NOT_READY');
        }
        return ['kind'=>'plan','plan'=>['workflow_code'=>'wf_performance_'.$query['query_shape'],'query'=>$query,
            'output_format'=>$outputFormat], '_context_meaning'=>(array)($sourceContext['meaning']??[])];
    }

    /**
     * Requery values for the exact stores displayed by a signed ranking when
     * the current meaning requests a per-store view with a new metric or time.
     * Only the language model identifies the set reference; the immutable
     * read view supplies identities, and the normal compiler and Reader still
     * enforce metric capability, dates and current store authority.
     */
    private function compileVerifiedStoreSetValuesContinuation(
        array $understanding,$sourceContext,array $safeQuestion,array $capabilities,string $outputFormat,string $today
    ): ?array {
        if (($understanding['status']??null)!=='understood' || !is_array($sourceContext)
            || !is_array($sourceContext['query']??null) || !is_array($sourceContext['view']??null)) return null;
        $source=$sourceContext['query'];
        $anchor=$sourceContext['meaning']['result_set']??null;
        $ranked=($source['query_shape']??null)==='ranking';
        $anchored=($source['query_shape']??null)==='breakdown' && is_array($anchor)
            && ($anchor['object_kind']??null)==='store' && ($anchor['ids']??null)===($source['store_ids']??null);
        if ((!$ranked && !$anchored)
            || ($source['business_filters']['object_kind']??'store')!=='store'
            || !empty($source['aggregate_condition']) || !empty($source['condition_set'])
            || !empty($sourceContext['items']??[])) return null;
        $detail=null;$metricTerms=null;$periods=null;$operation=null;$storeTerm=null;
        foreach (AiIntentUnderstandingContract::requirements($understanding) as $requirement) {
            $fields=(array)($requirement['fields']??[]);$values=(array)($requirement['values']??[]);
            if (array_diff($fields,['object_detail','object_kind','object_relation','metric_codes','periods','operation','store_term'])!==[]
                || !empty($values['metric_exclusions'])) return null;
            if (in_array('object_detail',$fields,true)) {
                if ($detail!==null || !is_array($values['object_detail']??null)) return null;
                $detail=$values['object_detail'];
            }
            if (in_array('object_kind',$fields,true) && ($values['object_kind']??null)!=='store') return null;
            if (in_array('object_relation',$fields,true)
                && !in_array($values['object_relation']??null,['analysis','selection'],true)) return null;
            if (in_array('metric_codes',$fields,true)) {
                if ($metricTerms!==null || !is_array($values['metric_terms']??null)) return null;
                $metricTerms=$values['metric_terms'];
            }
            if (in_array('periods',$fields,true)) {
                if ($periods!==null || !is_array($values['periods']??null)
                    || count($values['periods'])!==1) return null;
                $periods=$values['periods'];
            }
            if (in_array('operation',$fields,true)) {
                if ($operation!==null || ($values['operation']??null)!=='breakdown') return null;
                $operation='breakdown';
            }
            if (in_array('store_term',$fields,true)) {
                if ($storeTerm!==null || !is_string($values['store_term']??null)) return null;
                $storeTerm=$values['store_term'];
            }
        }
        // Some language-model answers correctly carry the new breakdown,
        // measurement and date but omit object_detail for a demonstrative
        // plural reference. Admit that omission only when a closed, generic
        // reference grammar proves the whole current utterance addresses the
        // previous set; it never chooses a store or supplies a metric/date.
        $statedSetCount=null;
        if ($detail===null) {
            $statedSetCount=$this->closedStoreSetReference(
                (string)($safeQuestion['question']??''),$metricTerms,$periods,$storeTerm
            );
            if ($statedSetCount===false) return null;
            $detail=['view'=>'summary','target'=>'set','ordinal'=>null];
        } elseif ($storeTerm!==null) return null;
        $detailKeys=is_array($detail)?array_keys($detail):[];sort($detailKeys,SORT_STRING);
        if ($detailKeys!==['ordinal','target','view']
            || ($detail['view']??null)!=='summary' || ($detail['target']??null)!=='set'
            || ($detail['ordinal']??null)!==null
            || $operation!=='breakdown' || ($metricTerms===null && $periods===null)) return null;
        // Independent, existing registry/calendar projections are omission
        // guards only. They cannot supply a missing model requirement or pick
        // an identity; an incomplete current turn stays on normal binding.
        $statedMetrics=\app\services\query\metric\MetricSemanticCatalog::registeredNonOverlappingTermsInText(
            (string)($safeQuestion['question']??''),(array)($capabilities['metric_codes']??[])
        );
        $calendar=\app\services\ai\semantic\AiSemanticIntentParser::calendarEvidence(
            (string)($safeQuestion['question']??'')
        );
        if (($calendar['complete']??false)!==true
            || count((array)($calendar['periods']??[]))!==($periods===null?0:1)
            || count($statedMetrics)!==($metricTerms===null?0:1)) return null;
        $metric=$source['metric_codes'][0]??null;
        if ($metricTerms!==null) {
            foreach ($metricTerms as $term) if (!is_string($term)
                || mb_strpos((string)($safeQuestion['question']??''),$term,0,'UTF-8')===false) return null;
            $metric=\app\services\query\metric\MetricSemanticCatalog::uniqueCodeForTerms(
                $metricTerms,(array)($capabilities['metric_codes']??[])
            );
            if ($metric!==($statedMetrics[0]['metric_code']??null)) return null;
        }
        if (!is_string($metric) || !in_array($metric,(array)($capabilities['metric_codes']??[]),true)) return null;
        if ($anchored) {
            // A repeated follow-up uses the signed identity set from the
            // preceding successful requery, never a fresh TOP N or visible
            // page rows that may omit a zero-valued store.
            $ids=$anchor['ids'];
        } else {
            $resolved=(new \app\services\ai\context\ObjectDetailContinuationResolver())->resolve(
                $source,$sourceContext['view'],$detail
            );
            if (($resolved['object_kind']??null)!=='store' || !is_array($resolved['objects']??null)) return null;
            $ids=array_column($resolved['objects'],'id');
        }
        if ($ids===[] || count($ids)>20 || count(array_unique($ids))!==count($ids)
            || ($statedSetCount!==null && count($ids)!==$statedSetCount)
            || array_diff($ids,(array)($capabilities['store_ids']??[]))!==[]
            || (!empty($source['store_ids']) && array_diff($ids,$source['store_ids'])!==[])) return null;
        $query=$source;
        $query['query_shape']='breakdown';$query['metric_codes']=[$metric];$query['store_ids']=$ids;
        $query['business_filters']=['object_kind'=>'store'];$query['ranking']=null;
        $query['ranking_presentation_metrics']=[];$query['aggregate_condition']=null;
        $query['compare_range']=null;unset($query['condition_set']);
        if ($periods!==null) {
            $range=(new AiWorkflowPlanner())->normalizeNaturalPeriod($periods[0],$today);
            $query['start_date']=$range['start'];$query['end_date']=$range['end'];
        }
        $meaning=(array)($sourceContext['meaning']??[]);
        $meaning['result_set']=['object_kind'=>'store','ids'=>$ids];
        return ['kind'=>'plan','plan'=>['workflow_code'=>'wf_performance_breakdown',
            'query'=>$query,'output_format'=>$outputFormat],
            '_context_meaning'=>$meaning];
    }

    /**
     * Admit only an exact result-set reference with a single registered metric
     * and at most one complete calendar expression. This is a bounded local
     * projection of the same compiler used after model understanding; it never
     * chooses an object, drops a condition or changes the Reader's authority.
     */
    private function localVerifiedStoreSetValuesContinuation(
        $sourceContext,array $safeQuestion,array $capabilities,string $outputFormat,string $today
    ): ?array {
        if (!is_array($sourceContext) || !empty($safeQuestion['has_unresolved_conditions'])
            || !is_string($safeQuestion['question']??null)) return null;
        $question=$safeQuestion['question'];
        $metrics=\app\services\query\metric\MetricSemanticCatalog::registeredNonOverlappingTermsInText(
            $question,(array)($capabilities['metric_codes']??[])
        );
        $calendar=\app\services\ai\semantic\AiSemanticIntentParser::calendarEvidence($question);
        if (count($metrics)!==1 || ($metrics[0]['ai_query_ready']??false)!==true
            || ($calendar['complete']??false)!==true || count((array)($calendar['periods']??[]))>1) return null;
        $periods=(array)$calendar['periods'];
        if ($this->closedStoreSetReference($question,[$metrics[0]['term']],$periods?:null,null)===false) return null;
        // A later, verified non-store answer replaces the old topic. A closed
        // "these stores" question has no referent in that answer; fail fast
        // instead of asking the model to resurrect an older private set.
        $priorKind=$sourceContext['query']['business_filters']['object_kind']??null;
        if (($sourceContext['meaning']['result_set']['object_kind']??null)!=='store'
            && is_string($priorKind) && $priorKind!=='store') {
            throw new RuntimeException('AI_STORE_SET_REFERENCE_UNAVAILABLE');
        }
        // Calendar evidence uses local code terms; the shared planner converts
        // them to an explicit interval before they enter the typed contract.
        if ($periods!==[]) {
            try {$range=(new AiWorkflowPlanner())->normalizePeriod($periods[0],$today);}
            catch (\Throwable $error) {return null;}
            $periods=[['kind'=>'date_range','start'=>$range['start'],'end'=>$range['end']]];
        }
        $requirements=[
            ['id'=>'r1','fields'=>['object_detail'],'values'=>[
                'object_detail'=>['view'=>'summary','target'=>'set','ordinal'=>null]]],
            ['id'=>'r2','fields'=>['metric_codes','operation'],'values'=>[
                'metric_terms'=>[$metrics[0]['term']],'operation'=>'breakdown']],
        ];
        if ($periods!==[]) $requirements[]=['id'=>'r3','fields'=>['periods'],'values'=>['periods'=>$periods]];
        return $this->compileVerifiedStoreSetValuesContinuation(
            ['status'=>'understood','requirements'=>$requirements],$sourceContext,$safeQuestion,
            $capabilities,$outputFormat,$today
        );
    }

    /**
     * Recognises only a complete plural back-reference, not a report intent.
     * Return false when any additional wording remains outside the already
     * typed metric, period and harmless question grammar; an integer denotes
     * a stated set size, while null means an unnumbered but explicit plural.
     */
    private function closedStoreSetReference(string $question,?array $metricTerms,?array $periods,?string $storeTerm)
    {
        $reference='/((?:这|那)(?<count>[0-9]+|[一二两三四五六七八九十]+|几|些)家(?:门店)?|(?:这些|那些|上述|前述)(?:门店|店))/u';
        if (preg_match_all($reference,$question,$matches,PREG_SET_ORDER|PREG_OFFSET_CAPTURE)!==1) return false;
        $match=$matches[0];$stated=$match['count'][0]??'';
        // The model may mislabel the demonstrative itself as a store name.
        // It is safe to discard only an exact copy of that current reference;
        // a different or named store still requires ordinary resolution.
        if ($storeTerm!==null && $storeTerm!==$match[0][0]) return false;
        $count=null;
        if ($stated!=='' && $stated!=='几' && $stated!=='些') {
            $digits=['一'=>1,'二'=>2,'两'=>2,'三'=>3,'四'=>4,'五'=>5,'六'=>6,'七'=>7,'八'=>8,'九'=>9,'十'=>10];
            if (ctype_digit($stated)) $count=(int)$stated;
            elseif (isset($digits[$stated])) $count=$digits[$stated];
            elseif (preg_match('/^([一二三四五六七八九])?十([一二三四五六七八九])?$/uD',$stated,$parts)) {
                $count=($digits[$parts[1]??'']??1)*10+($digits[$parts[2]??'']??0);
            }
            if ($count===null || $count<1 || $count>20) return false;
        }
        $remaining=substr_replace($question,'',$match[0][1],strlen($match[0][0]));
        $calendar=\app\services\ai\semantic\AiSemanticIntentParser::calendarEvidence($remaining);
        if (($calendar['complete']??false)!==true
            || count((array)($calendar['periods']??[]))!==($periods===null?0:1)) return false;
        $remaining=str_replace('【日期】','',(string)$calendar['remainder']);
        foreach ((array)$metricTerms as $term) {
            if (!is_string($term) || mb_strpos($remaining,$term,0,'UTF-8')===false) return false;
            $remaining=str_replace($term,'',$remaining);
        }
        // Keep this deliberately small. A new named object, exclusion,
        // ranking or unparsed business condition must take the normal path.
        $remaining=preg_replace('/(?:请|帮我|把|给我|查|看|一下|的|分别|各自|每家|每个|各|是多少|多少|数值|金额|[\\s，,。？?])/u','',$remaining);
        return $remaining===''?$count:false;
    }

    /**
     * Separates typed set presentation from its population query.
     * Both condition lists and rankings must first establish an authorised,
     * bounded set; only then may execution read those exact members' details.
     * Ranked object profiles are applied by the server; singular/ambiguous targets remain on the normal
     * detail path, where their own registered capability decides support.
     *
     * @return array{0:array,1:?array}
     */
    private function separatePopulationDetail(array $understanding): array
    {
        $objectKinds=[];$operations=[];$hasMemberConditionList=false;$detail=null;
        foreach ((array)($understanding['requirements']??[]) as $requirement) {
            $fields=(array)($requirement['fields']??[]);$values=(array)($requirement['values']??[]);
            if (in_array('object_kind',$fields,true)&&is_string($values['object_kind']??null)) {
                $objectKinds[$values['object_kind']]=true;
            }
            if (in_array('operation',$fields,true)&&is_string($values['operation']??null)) {
                $operations[$values['operation']]=true;
            }
            if (in_array('aggregate_condition',(array)($requirement['fields']??[]),true)
                &&($values['aggregate_condition']['subject']??null)==='member'
                &&($values['aggregate_condition']['result_form']??null)==='list') $hasMemberConditionList=true;
            if (!array_intersect(['object_detail','member_detail'],$fields)) continue;
            $candidate=$values['object_detail']??$values['member_detail']??null;
            if (!is_array($candidate)||$detail!==null) return [$understanding,null];
            $detail=$candidate;
        }
        $memberPopulation=isset($objectKinds['member'])&&count($objectKinds)===1
            &&(isset($operations['ranking'])||isset($operations['condition_list'])||$hasMemberConditionList);
        $kind=count($objectKinds)===1?array_key_first($objectKinds):null;
        $rankingDetail=isset($operations['ranking']) && is_string($kind) && $kind!=='member'
            && ($detail['view']??null)==='summary';
        if ((!$memberPopulation && !$rankingDetail)||!is_array($detail)||($detail['target']??null)!=='set'
            ||!in_array($detail['view']??null,['summary','rights'],true)
            ||($detail['ordinal']??null)!==null) return [$understanding,null];
        foreach ($understanding['requirements'] as &$requirement) {
            if (!array_intersect(['object_detail','member_detail'],(array)($requirement['fields']??[]))) continue;
            $requirement['fields']=array_values(array_diff($requirement['fields'],['object_detail','member_detail']));
            unset($requirement['values']['object_detail'],$requirement['values']['member_detail']);
            if (($requirement['values']??[])===[]) unset($requirement['values']);
        }
        unset($requirement);
        $understanding['requirements']=array_values(array_filter($understanding['requirements'],static function(array $requirement): bool {
            return ($requirement['fields']??[])!==[];
        }));
        if ($rankingDetail) $detail['_ranking_object_kind']=$kind;
        return [$understanding,$detail];
    }

    /** The contract already proved this is a member count-to-list follow-up;
     * preserve only its typed display request, never a model-supplied member. */
    private function inheritedCountDetailRequest(array $understanding): ?array
    {
        foreach ((array)($understanding['requirements']??[]) as $requirement) {
            if (!in_array('object_detail',(array)($requirement['fields']??[]),true)) continue;
            $candidate=$requirement['values']['object_detail']??null;
            return is_array($candidate) && ($candidate['view']??null)==='summary'
                && in_array($candidate['target']??null,['single','set'],true)
                && ($candidate['ordinal']??null)===null ? $candidate : null;
        }
        return null;
    }

    /** Details are a presentation of the verified ranked population, not a
     * second intent for the model to bind. Keep unsupported profiles closed;
     * member assets retain their separately authorised existing workflow. */
    private function attachPopulationDetail(array $compiled,array $detail): array
    {
        if (!isset($detail['_ranking_object_kind'])) {
            $compiled['_member_detail_request']=$detail;
            return $compiled;
        }
        if (($compiled['kind']??null)==='clarification') {
            $compiled['_ranking_detail_request']=$detail;
            return $compiled;
        }
        $query=$compiled['plan']['query']??null;
        if (!is_array($query) || ($query['query_shape']??null)!=='ranking') throw new RuntimeException('AI_OBJECT_DETAIL_NOT_READY');
        $codes=\app\services\ai\execution\AiRankingPresentationMetricResolver::detail(
            \app\services\query\metric\MetricReadViewServices::metricCapabilities(),
            $query['metric_codes'][0],$detail['_ranking_object_kind']
        );
        if (!$codes) throw new RuntimeException('AI_OBJECT_DETAIL_NOT_READY');
        $compiled['plan']['query']['ranking_presentation_metrics']=$codes;
        return $compiled;
    }

    /** A typed request for the existing list changes presentation only. No
     * lexical matching, new filter, metric, period or object may enter here. */
    private function compileVerifiedPopulationPresentation(array $meaning,$sourceContext): ?array
    {
        if (($meaning['status']??null)!=='understood'||($meaning['topic_relation']??null)!=='continuation'
            ||!is_array($sourceContext['query']??null)||!is_array($sourceContext['view']??null)) return null;
        $query=$sourceContext['query'];$view=$sourceContext['view'];
        if (!in_array($query['query_shape']??null,['breakdown','ranking','condition_list'],true)) return null;
        $kind=$query['condition_set']['subject']??$query['business_filters']['object_kind']??$view['results'][0]['object_kind']??null;
        $operation=false;
        foreach (($meaning['requirements']??[]) as $requirement) {
            $fields=$requirement['fields']??[];$values=$requirement['values']??[];
            if (array_diff($fields,['operation','object_kind','object_relation'])!==[]) return null;
            if (in_array('operation',$fields,true)) {
                if (($values['operation']??null)!=='condition_list') return null;
                $operation=true;
            }
            if (in_array('object_kind',$fields,true)&&($values['object_kind']??null)!==$kind) return null;
            if (in_array('object_relation',$fields,true)&&($values['object_relation']??null)!=='analysis') return null;
        }
        if (!$operation) return null;
        // Re-publish only the immutable authorised view. The customer asks
        // to see its list, not to recompute a fresh condition population.
        return ['kind'=>'verified_result_presentation','query'=>$query,
            'view_ref'=>$view['read_consistency_ref']??null,'_context_meaning'=>(array)($sourceContext['meaning']??[])];
    }

    /** Re-present a signed result set without querying or re-ranking facts.
     * The normal Reader rechecks current authority and snapshot expiration. */
    private function executeVerifiedResultPresentation(array $context,array $owner,string $id,int $generation,string $worker,array $snapshot,array $compiled,array $meaning): array
    {
        // A signed result is not permanent permission: recheck both before
        // replay and before publication, just as for a fresh detail query.
        $guard=function()use($context,$owner,$id,$generation,$worker,$snapshot):void {
            $this->runs->checkpoint($owner,$id,$generation,$worker);
            if ($this->permissionHash($this->fresh($context))!==$snapshot['authorization_version']
                || (string)$this->config->read()['version']!==$snapshot['model_config_version']) throw new RuntimeException('AI_AUTHORIZATION_CHANGED');
        };
        $guard();
        $this->runs->progress($owner,$id,$generation,$worker,'QUERYING');
        $shape=$compiled['query']['query_shape']??null;
        $view=$shape==='ranking'
            ?$this->queryService($context)->rankingDetails($context,$compiled['query'],$compiled['view_ref'])
            :$this->queryService($context)->replay($context,$compiled['query'],$compiled['view_ref']);
        $compiled['query']=$view['query'];$compiled['view_ref']=$view['read_consistency_ref'];
        $this->runs->progress($owner,$id,$generation,$worker,'VERIFYING');
        $this->runs->progress($owner,$id,$generation,$worker,'RENDERING');
        $answer=(new AiAnswerRenderer())->render($view);
        $guard();
        $this->queryService($context)->replay($context,$compiled['query'],$compiled['view_ref']);
        $this->runs->progress($owner,$id,$generation,$worker,'PUBLISHING');
        $run=$this->runs->get($owner,$id,$generation);
        $expires=min($view['expires_at'],intdiv($run['expires_at'],1000));
        $binding=['owner'=>$owner,'run_id'=>$id,'generation'=>$generation];
        $evidence=$binding+['query'=>$compiled['query'],'view_ref'=>$compiled['view_ref'],'context_meaning'=>$meaning,
            'compiled_run_hash'=>hash('sha256',$compiled['view_ref']),
            'execution_trace'=>[['code'=>'verified_result_presentation','status'=>'SUCCEEDED']],
            'workflow_code'=>'wf_performance_'.$shape,'management_version'=>$this->managementRevision];
        return $this->runs->publish($owner,$id,$generation,$worker,
            $this->private->put('evidence',$evidence,$expires),$this->private->put('answer',$binding+['answer'=>$answer],$expires));
    }

    /**
     * Reads assets for a bounded verified member set. Every identity passes a
     * live store-relation check. Once authorised, rights span all that member's
     * stores, as confirmed by the product owner on 2026-09-24; this never grants
     * access to another member outside the original population.
     */
    private function executeMemberDetail(array $context,array $owner,string $id,int $generation,string $worker,array $snapshot,array $compiled,array $contextMeaning=[]): array
    {
        $spec=$compiled['member_detail']??null;
        if (!is_array($spec)||!is_array($spec['source_query']??null)
            ||!is_string($spec['source_view_ref']??null)||!is_int($spec['source_expires_at']??null)) {
            throw new RuntimeException('AI_CONTEXT_REQUIRED');
        }
        $guard=function()use($context,$owner,$id,$generation,$worker,$snapshot): array {
            $this->runs->checkpoint($owner,$id,$generation,$worker);
            $fresh=$this->fresh($context);
            if ($this->permissionHash($fresh)!==$snapshot['authorization_version']
                ||(string)$this->config->read()['version']!==$snapshot['model_config_version']) {
                throw new RuntimeException('AI_AUTHORIZATION_CHANGED');
            }
            return $fresh;
        };
        $fresh=$guard();
        $this->runs->progress($owner,$id,$generation,$worker,'QUERYING');
        $members=$spec['members']??[$spec];
        // Full card-by-card rights can multiply rows without a fixed bound;
        // only the six-value summary may use the derived larger cohort limit.
        if (($spec['view']??null)==='rights'
            &&count($members)>\app\services\query\metric\RegisteredMetricReadServices::AI_DETAIL_LIMIT) {
            throw new RuntimeException('AI_DETAIL_SCOPE_TOO_LARGE');
        }
        $attemptHash=hash('sha256',json_encode([$members,$spec['view'],$spec['source_view_ref'],
            $spec['population_snapshot']['sha256']??null]));
        $this->runs->prepareAttempt($owner,$id,$generation,$worker,'member_detail_read','tool',$attemptHash,'cashier_v3_member_detail');
        if (!$this->runs->sendAttempt($owner,$id,$generation,$worker,'member_detail_read')) throw new RuntimeException('AI_ATTEMPT_CONFLICT');
        try {
            // MemberAnalysisObjectServices performs its own live authority
            // refresh immediately before resolving the signed selection. Use
            // the original trusted context, which owns that refresh callback;
            // the returned permission snapshot intentionally contains no
            // executable callback and must never be treated as a new context.
            $store=(int)($fresh['origin_store_id']??0);
            if ($store<1||!in_array($store,(array)($fresh['store_ids']??[]),true)) $store=(int)($fresh['store_ids'][0]??0);
            if ($store<1) throw new RuntimeException('AI_MEMBER_PERMISSION_REQUIRED');
            $operator=new \app\services\cashier\v3\CashierV3OperatorScope(
                $store,(int)$fresh['account_id'],(string)($fresh['origin_organization_id']??''),(string)($fresh['tenant_id']??'0')
            );
            $dataScope=new \app\services\cashier\v3\CashierV3DataScopeContext(
                (int)$fresh['account_id'],(int)($fresh['employee_id']??0),$store,(string)($fresh['tenant_id']??'0'),
                (string)($fresh['origin_organization_id']??''),array_values((array)$fresh['store_ids']),
                \app\services\cashier\v3\CashierV3DataScopeContext::MODE_STORES,[],false,'',
                $this->permissionHash($fresh),[],[],true
            );
            // Resolve every signed member against one current permission-scoped
            // relation, then read balances in one bounded batch. The cashier
            // projection still validates each member/card and the run guard
            // still prevents an expired authorization from publishing a set.
            $streamedSnapshot=null;
            if (isset($spec['population_snapshot'])) {
                if (($spec['view']??null)!=='summary') throw new RuntimeException('AI_DETAIL_SCOPE_TOO_LARGE');
                $streamedSnapshot=$this->freezeMemberRightsSummary($context,$spec['population_snapshot'],
                    $operator,$dataScope,$guard);
                $details=[];
                foreach ((new \app\services\ai\presentation\AiMemberRightsDetailStore())->rows($streamedSnapshot) as $record) {
                    $details[]=['detail'=>$record['detail'],'label'=>$record['label'],
                        'selection_ref'=>$record['selection_ref']];
                    if (count($details)===20) break;
                }
            } else {
                $references=array_column($members,'selection_ref');
                $selections=$this->memberObjects($context)->selections($references);
                $memberIds=[];
                foreach ($references as $reference) $memberIds[]=(int)$selections[$reference]['member_id'];
                $detailReader=new \app\services\cashier\v3\member\CashierV3MemberDetailQueryServices();
                // Non-streamed member references keep their original signed
                // full-set guard and authoritatively read every requested row.
                $rights=(string)$spec['view']==='summary'
                    ?$detailReader->readAiRightsSummarySet($memberIds,$operator,$dataScope,$guard)
                    :$detailReader->readAiRightsSet($memberIds,$operator,$dataScope,$guard);
                $details=[];
                foreach ($members as $member) {
                    $selection=$selections[$member['selection_ref']];
                    $detail=$rights[(int)$selection['member_id']]??null;
                    if (!is_array($detail)) throw new RuntimeException('AI_EVIDENCE_INVALID');
                    if ((int)($detail['member']['memberId']??0)!==(int)$selection['member_id']) throw new RuntimeException('AI_EVIDENCE_INVALID');
                    $expected=$spec['expected_remaining_project_amount_cents'][(int)$selection['member_id']]??null;
                    if ($expected!==null) {
                        $shown=$detail['summary']['remainingProjectAmount']??null;
                        if (!is_string($shown)||!preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D',$shown)
                            ||(int)bcmul($shown,'100',0)!==$expected) throw new RuntimeException('AI_RIGHTS_CHANGED');
                    }
                    $details[]=['detail'=>$detail,'label'=>(string)$selection['label'],'selection_ref'=>$member['selection_ref']];
                }
            }
            $this->runs->finishAttempt($owner,$id,$generation,$worker,'member_detail_read','SUCCEEDED');
        } catch (\Throwable $error) {
            $this->runs->finishAttempt($owner,$id,$generation,$worker,'member_detail_read','FAILED');
            throw $error;
        }
        $guard();$this->runs->progress($owner,$id,$generation,$worker,'VERIFYING');
        $this->runs->progress($owner,$id,$generation,$worker,'RENDERING');
        $renderer=new \app\services\ai\presentation\AiMemberDetailAnswerRenderer();
        $answer=isset($spec['members'])?$renderer->renderSet($details,(string)$spec['view'],$streamedSnapshot['count']??null)
            :$renderer->render($details[0]['detail'],$details[0]['label'],(string)$spec['view']);
        // Export captures the full authorised rights tables before the screen
        // preview is capped; neither the browser nor a later query supplies rows.
        $rightsAnswer=$answer;
        if (isset($spec['population_answer'])) {
            // Keep the verified membership evidence visible alongside assets;
            // the user must be able to see which metrics selected this set.
            $parts=$answer['sections']??[['title'=>'会员权益汇总','answer'=>$answer]];
            $sections=[['id'=>'q1','title'=>'会员筛选依据','answer'=>$spec['population_answer']]];
            foreach ($parts as $part) $sections[]=['id'=>'q'.(count($sections)+1),'title'=>$part['title'],'answer'=>$part['answer']];
            $answer=['summary'=>$spec['population_answer']['summary'].' '.$answer['summary'],'cards'=>[],'sections'=>$sections];
        }
        // Member assets have their own immutable rights snapshot for Excel;
        // the response may show only a screen page, never trim that snapshot.
        $answer=AiAnswerRenderer::limitTables($answer);
        if ($streamedSnapshot!==null && $streamedSnapshot['count']>20) {
            // The preview is intentionally 20 rows, so limitTables() cannot
            // infer that a complete file is still required from array size.
            $answer['auto_export_required']=true;
            $note='页面仅展示前20条；完整明细另行生成 Excel。';
            if (strpos((string)$answer['summary'],$note)===false) $answer['summary'].=' '.$note;
        }
        $guard();$this->runs->progress($owner,$id,$generation,$worker,'PUBLISHING');
        $run=$this->runs->get($owner,$id,$generation);
        $expires=min($spec['source_expires_at'],intdiv($run['expires_at'],1000));
        $binding=['owner'=>$owner,'run_id'=>$id,'generation'=>$generation];
        // Retain the signed source query/view as the conversation context. A
        // later follow-up therefore replays the original filtered population
        // and never treats this presentation object as a new source of truth.
        $evidence=$binding+['query'=>$spec['source_query'],'view_ref'=>$spec['source_view_ref'],
            // Freeze exact values before publishing. The separate XLSX task
            // projects these authorised assets, not the context filter list.
            'member_rights_export'=>$streamedSnapshot!==null
                ?\app\services\ai\presentation\AiMemberRightsExportProjection::captureStreamed($streamedSnapshot,$rightsAnswer)
                :\app\services\ai\presentation\AiMemberRightsExportProjection::capture(
                    $details,(string)$spec['view'],$spec['population_export_rows']??[],$rightsAnswer),
            'context_meaning'=>$contextMeaning,'compiled_run_hash'=>$attemptHash,
            'execution_trace'=>[['code'=>'member_detail_read','status'=>'SUCCEEDED']],
            'workflow_code'=>'wf_member_detail_read','management_version'=>$this->managementRevision];
        $evidenceRef=$this->private->put('evidence',$evidence,$expires);
        $answerRef=$this->private->put('answer',$binding+['answer'=>$answer],$expires);
        return $this->runs->publish($owner,$id,$generation,$worker,$evidenceRef,$answerRef);
    }

    /** Freeze all scoped rights in bounded batches before publishing a 20-row preview. */
    private function freezeMemberRightsSummary(array $context,array $population,
        \app\services\cashier\v3\CashierV3OperatorScope $operator,
        \app\services\cashier\v3\CashierV3DataScopeContext $scope,callable $guard): array
    {
        $source=(new \app\services\query\metric\AiMemberRightsPopulationStore())->rows($population);
        $reader=new \app\services\cashier\v3\member\CashierV3MemberDetailQueryServices();
        $objects=$this->memberObjects($context);
        $records=(static function()use($source,$reader,$objects,$operator,$scope,$guard):\Generator {
            $batch=[];
            foreach ($source as $row) {
                $batch[]=$row;
                if (count($batch)<100) continue;
                yield from self::rightsSummaryBatch($batch,$reader,$objects,$operator,$scope,$guard);
                $batch=[];
            }
            if ($batch!==[]) yield from self::rightsSummaryBatch($batch,$reader,$objects,$operator,$scope,$guard);
        })();
        return (new \app\services\ai\presentation\AiMemberRightsDetailStore())->put($records,(int)$population['count']);
    }

    /** A changed right or revoked identity invalidates the whole frozen result. */
    private static function rightsSummaryBatch(array $batch,$reader,$objects,$operator,$scope,callable $guard): \Generator
    {
        $guard();$refs=array_map(static fn(array $row):string=>'member:'.$row['member_id'],$batch);
        $selections=$objects->selections($refs);
        $ids=array_column($batch,'member_id');
        $details=$reader->readAiRightsSummarySet($ids,$operator,$scope,$guard);
        foreach ($batch as $row) {
            $ref='member:'.$row['member_id'];$selection=$selections[$ref]??null;
            $detail=$details[$row['member_id']]??null;
            if (!is_array($selection)||!is_array($detail)
                ||$selection['label']!==$row['member_name']) throw new RuntimeException('AI_EVIDENCE_INVALID');
            $shown=$detail['summary']['remainingProjectAmount']??null;
            if (!is_string($shown)||!preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D',$shown)
                ||(int)bcmul($shown,'100',0)!==$row['amount_cents']) throw new RuntimeException('AI_RIGHTS_CHANGED');
            yield ['detail'=>$detail,'label'=>$row['member_name'],'selection_ref'=>$ref,
                'selected_amount_cents'=>$row['amount_cents']];
        }
    }

    /** Rank or filter first, then read details from that exact signed population. */
    private function executeMemberPopulationDetails(array $context,array $owner,string $id,int $generation,string $worker,array $snapshot,array $compiled,array $contextMeaning): array
    {
        $plan=$compiled['plan'];
        $query=$plan['query']??[];$shape=$query['query_shape']??null;
        $memberPopulation=$shape==='condition_list'&&($query['condition_set']['subject']??null)==='member';
        $memberPopulation=$memberPopulation||($shape==='ranking'
            &&($query['business_filters']['object_kind']??null)==='member');
        if (isset($plan['items'])||!$memberPopulation) throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
        // Population execution never starts the file worker. Clients request
        // the separate export only after the asset answer has been published.
        $plan['output_format']='screen';
        $result=$this->executeRegisteredSingle($context,$owner,$id,$generation,$worker,$snapshot,$plan,$contextMeaning,false);
        $view=$result['evidence'];
        // An empty population has no private assets to read; retain its exact
        // empty answer instead of pretending that a member could be selected.
        $memberResolver=new \app\services\ai\context\MemberDetailContinuationResolver();
        if ($memberResolver->isEmptyPopulation($view)) {
            [$evidenceRef,$answerRef]=$this->saveResults($owner,$id,$generation,$result['plan'],$view,$result['answer'],$result['trace'],$contextMeaning);
            return $this->runs->publish($owner,$id,$generation,$worker,$evidenceRef,$answerRef);
        }
        $request=$compiled['_member_detail_request'];$request['target']='set';$request['ordinal']=null;
        $resolved=$memberResolver->resolve($view['query'],$view,$request);
        $expectedAmounts=[];
        if (($view['results'][0]['condition_set']['conditions'][0]['metric_code']??null)==='member_remaining_project_amount') {
            foreach ($view['results'][0]['rows']??[] as $row) {
                if (!is_int($row['member_id']??null)
                    ||!is_int($row['metrics']['member_remaining_project_amount']??null)) throw new RuntimeException('AI_EVIDENCE_INVALID');
                $expectedAmounts[$row['member_id']]=$row['metrics']['member_remaining_project_amount'];
            }
        }
        return $this->executeMemberDetail($context,$owner,$id,$generation,$worker,$snapshot,
            ['member_detail'=>$resolved+['source_query'=>$view['query'],'source_view_ref'=>$view['read_consistency_ref'],
                'source_expires_at'=>$view['expires_at'],'population_answer'=>$result['answer'],
                'expected_remaining_project_amount_cents'=>$expectedAmounts,
                // Reuse the exact signed selection metrics, without re-query
                // or rounding, so the combined answer's file stays complete.
                'population_export_rows'=>\app\services\query\metric\MetricReadViewExportProvider::project($view)]],$contextMeaning);
    }

    private function executeRegistered(array $context,array $owner,string $id,int $generation,string $worker,array $snapshot,array $plannerPlan,array $contextMeaning=[]): array
    {
        if (isset($plannerPlan['items'])) return $this->executeRegisteredCollection($context,$owner,$id,$generation,$worker,$snapshot,$plannerPlan,$contextMeaning);
        return $this->executeRegisteredSingle($context,$owner,$id,$generation,$worker,$snapshot,$plannerPlan,$contextMeaning);
    }

    /** Executes one registry plan. Collection execution reuses this exact path and publishes only once. */
    private function executeRegisteredSingle(array $context,array $owner,string $id,int $generation,string $worker,array $snapshot,array $plannerPlan,array $contextMeaning=[],bool $publish=true,string $attemptPrefix=''): array
    {
        $run=$this->runs->checkpoint($owner,$id,$generation,$worker);
        $today=(new \DateTimeImmutable('@'.intdiv($run['created_at'],1000)))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
        $budget=(int)explode('-',$snapshot['budget_profile_version'])[0];
        $this->assertManagedPlan($plannerPlan);
        $plan=(new AiRegisteredPlanCompiler($this->registry(),static function()use($today):string{return $today;}))->compile($plannerPlan,$this->capabilities($context),[
            'run_budget_ms'=>$budget,'remaining_execution_ms'=>min($budget,$run['deadline_at']-(int)floor(microtime(true)*1000))]);
        $evidence=null; $answer=null; $waiting=null; $trace=[];
        $guard=function() use($context,$owner,$id,$generation,$worker,$snapshot,&$waiting): void {
            if ($waiting!==null) return; // queue handoff already committed; no execution after release.
            $this->runs->checkpoint($owner,$id,$generation,$worker);
            $fresh=$this->fresh($context);
            if ($this->permissionHash($fresh)!==$snapshot['authorization_version'] || (string)$this->config->read()['version']!==$snapshot['model_config_version']) throw new RuntimeException('AI_AUTHORIZATION_CHANGED');
            if (isset($snapshot['guidance_schema_version']) && $this->registryHash($fresh)!==$snapshot['capability_snapshot_ref']) throw new RuntimeException('AI_CAPABILITY_CHANGED');
        };
        $checkpoint=function($event,$node,$nodeTrace) use($guard,$owner,$id,$generation,$worker,&$trace): void {
            $guard(); $trace=$nodeTrace;
            if ($event==='before_node') foreach ($node['charges'] as $counter=>$amount) {
                // Actual Tool sends are reserved atomically by prepareAttempt below.
                if (($counter!=='tool_call_count' || $node['handler']==='verified_export_create') && $amount>0) $this->runs->reserve($owner,$id,$generation,$worker,$counter,$amount);
            }
        };
        $tool=function(string $code,string $target,callable $action) use($owner,$id,$generation,$worker,$plan,$attemptPrefix) {
            $attemptCode=$attemptPrefix===''?$code:$attemptPrefix.'_'.$code;
            $this->runs->prepareAttempt($owner,$id,$generation,$worker,$attemptCode,'tool',$plan['compiled_run_hash'],$target);
            if (!$this->runs->sendAttempt($owner,$id,$generation,$worker,$attemptCode)) throw new RuntimeException('AI_ATTEMPT_CONFLICT');
            try { $value=$action(); $this->runs->finishAttempt($owner,$id,$generation,$worker,$attemptCode,'SUCCEEDED'); return $value; }
            catch (\Throwable $error) { $this->runs->finishAttempt($owner,$id,$generation,$worker,$attemptCode,'FAILED'); throw $error; }
        };
        $handlers=[
            'unified_metric_query'=>function($input,$node,$compiled,$heartbeat) use($context,$owner,$id,$generation,$worker,&$evidence,$tool) {
                $this->runs->progress($owner,$id,$generation,$worker,'QUERYING');
                try {
                    $evidence=$tool('query','unified_metric_query',function() use($context,$owner,$id,$generation,$input,$heartbeat) {
                        return $this->queryService($context,$heartbeat)->create([],$input['query'],intdiv($this->runs->get($owner,$id,$generation)['expires_at'],1000));
                    });
                } catch (\Throwable $error) {
                    if ($error instanceof \app\services\query\metric\MetricQueryContractException) {
                        // Full-detail safety ceilings are not a transient
                        // system failure. Never publish a partial workbook or
                        // ask the customer to retry the same oversized scope.
                        if ($error->getErrorCode()==='METRIC_GROUP_OUTPUT_TOO_LARGE') {
                            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'query_metric_group_output_too_large',[],'query_runtime_probe');
                            throw new RuntimeException('AI_DETAIL_SCOPE_TOO_LARGE');
                        }
                        $code=strtolower($error->getErrorCode());
                        $predicate=preg_match('/^[a-z0-9_]{1,64}$/D',$code)?'query_'.$code:'query_contract';
                    } elseif (preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D',$error->getMessage())) {
                        $predicate='query_'.strtolower($error->getMessage());
                    } elseif ($error instanceof \TypeError) $predicate='query_type_error';
                    elseif ($error instanceof \Error) $predicate='query_php_error';
                    else {
                        // Keep the failure diagnosable without retaining SQL,
                        // bind values, customer wording or exception messages.
                        $kind=strtolower((new \ReflectionClass($error))->getShortName());
                        if (!preg_match('/^[a-z][a-z0-9]{0,31}$/D',$kind)) $kind='other';
                        $state=preg_match('/SQLSTATE\[([A-Z0-9]{5})\]/',$error->getMessage(),$match)
                            ?strtolower($match[1]):'none';
                        $driverCode=$error instanceof \PDOException
                            && is_array($error->errorInfo??null)
                            && is_int($error->errorInfo[1]??null)
                            ?(string)$error->errorInfo[1]:'none';
                        // ThinkPHP may rethrow a PDOException without errorInfo;
                        // retain only the numeric database code, never SQL text.
                        if ($driverCode==='none' && preg_match('/(?:General error|Error):\s*([0-9]{3,5})\b/',$error->getMessage(),$driverMatch)) {
                            $driverCode=$driverMatch[1];
                        }
                        $predicate='query_unexpected_'.$kind.'_'.$state.'_'.$driverCode;
                    }
                    // Persist the payload-free query boundary as a separate
                    // attempt diagnostic. The terminal run reason is purposely
                    // generic for users, so without this record a fast contract
                    // failure is overwritten and cannot be distinguished from
                    // a database or PHP runtime failure during acceptance.
                    $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,$predicate,[],'query_runtime_probe');
                    throw new RuntimeException('AI_QUERY_FAILED');
                }
                return $evidence;
            },
            'all_evidence_guard'=>function($input,$node,$compiled,$heartbeat) use($context,$owner,$id,$generation,$worker,&$evidence) {
                $this->runs->progress($owner,$id,$generation,$worker,'VERIFYING');
                try {
                    // A condition set evaluates several registered metrics over
                    // one authorised population and therefore returns one
                    // aggregate result per period, not one result per metric.
                    $conditionPopulation=in_array($input['query']['query_shape']??null,['condition_count','condition_list'],true);
                    $expected=($conditionPopulation?1:count($input['query']['metric_codes']))*($input['query']['compare_range']===null?1:2);
                    if (!$evidence || empty($evidence['ai_query_ready']) || $evidence['result_status']!=='complete' || count($evidence['results'])!==$expected) throw new RuntimeException('AI_EVIDENCE_INCOMPLETE');
                    $this->queryService($context,$heartbeat)->replay([],$input['query'],$evidence['read_consistency_ref']);
                } catch (\Throwable $error) {
                    $known=['AI_EVIDENCE_INCOMPLETE','AI_AUTHORIZATION_CHANGED','AI_CAPABILITY_CHANGED'];
                    $predicate=in_array($error->getMessage(),$known,true)
                        ? 'evidence_guard_'.strtolower($error->getMessage()) : 'evidence_guard_unexpected';
                    $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,$predicate);
                    throw new RuntimeException('AI_EVIDENCE_GUARD_FAILED');
                }
                return ['verified'=>true];
            },
            'deterministic_answer'=>function() use($owner,$id,$generation,$worker,&$evidence,&$answer) {
                $this->runs->progress($owner,$id,$generation,$worker,'RENDERING');
                try {
                    $answer=(new AiAnswerRenderer())->render($evidence);
                } catch (\Throwable $error) {
                    // Rendering happens after the Reader has returned verified facts.
                    // Keep support telemetry structural: never retain a row, an object
                    // name, a value, or the provider/model message with the run.
                    $known=['AI_EVIDENCE_INVALID','AI_METRIC_EXPLANATION_NOT_READY','AI_EVIDENCE_VALUE_INVALID'];
                    $predicate=in_array($error->getMessage(),$known,true)
                        ? 'answer_render_'.strtolower($error->getMessage()) : 'answer_render_unexpected';
                    $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,$predicate);
                    throw new RuntimeException('AI_ANSWER_RENDER_FAILED');
                }
                return ['answer'=>$answer];
            },
            'metric_catalog_read'=>function($input) use($context,$tool,&$evidence) {
                $evidence=$tool('catalog','metric_catalog_read',function() use($input,$context) { return $this->definitionEvidence($context,$input['definition_metric_codes']); });
                return $evidence;
            },
            'metadata_guard'=>function() use($context,&$evidence) { $this->verifyDefinition($context,$evidence); return ['verified'=>true]; },
            'deterministic_definition'=>function() use(&$evidence,&$answer) {
                $parts=[]; foreach ($evidence['definitions'] as $definition) {
                    $text=[]; foreach (['summary','include','exclude','timing','note'] as $key) if ($definition[$key]!=='') $text[]=$definition[$key];
                    $parts[]=$definition['name'].'：'.implode(' ',$text);
                }
                $answer=['summary'=>implode("\n\n",$parts),'cards'=>[]]; return ['answer'=>$answer];
            },
            'verified_export_create'=>function() use($context,$owner,$id,$generation,$worker,$plan,$guard,&$evidence,&$answer,&$waiting,&$trace,$contextMeaning) {
                $guard(); $this->runs->progress($owner,$id,$generation,$worker,'PUBLISHING');
                try {
                    [$evidenceRef,$answerRef]=$this->saveResults($owner,$id,$generation,$plan,$evidence,$answer,$trace,$contextMeaning);
                } catch (\Throwable $error) {
                    $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,'export_result_handoff_failed');
                    throw new RuntimeException('AI_EXPORT_HANDOFF_FAILED');
                }
                try {
                    // ExportRuntime owns the source-bound Task receipt and dispatch UNKNOWN handling.
                    $waiting=$this->exportRuntime()->queue($this->fresh($context),$owner,$this->runs->get($owner,$id,$generation),$worker,$evidenceRef,$answerRef,$evidence);
                } catch (\Throwable $error) {
                    $known=['AI_EXPORT_NOT_READY','AI_EXPORT_CAPACITY_REJECTED','AI_EXPORT_DISPATCH_UNKNOWN','AI_AUTHORIZATION_CHANGED','AI_CAPABILITY_CHANGED'];
                    if (in_array($error->getMessage(),$known,true)
                        || preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D',$error->getMessage())) $predicate='export_queue_'.strtolower($error->getMessage());
                    elseif ($error instanceof \TypeError) $predicate='export_queue_type_error';
                    elseif ($error instanceof \InvalidArgumentException) $predicate='export_queue_input_contract';
                    elseif ($error instanceof \LogicException) $predicate='export_queue_runtime_contract';
                    elseif ($error instanceof \ErrorException) $predicate='export_queue_php_notice';
                    else {
                        $class=(new \ReflectionClass($error))->getShortName();
                        $predicate=preg_match('/^[A-Za-z]{1,48}$/D',$class)
                            ? 'export_queue_'.strtolower($class) : 'export_queue_unexpected';
                    }
                    $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,$predicate);
                    throw new RuntimeException('AI_EXPORT_QUEUE_FAILED');
                }
                return ['deferred'=>true];
            },
        ];
        try {
            $execution=(new AiRegisteredWorkflowExecutor($this->registry()))->execute($plan,$handlers,$checkpoint);
        } catch (\Throwable $error) {
            // The export handler records a more specific, payload-free
            // diagnostic before returning one of these boundary codes.  Do
            // not overwrite it with a generic graph-level label.
            if (in_array($error->getMessage(),['AI_QUERY_FAILED','AI_DETAIL_SCOPE_TOO_LARGE','AI_EXPORT_HANDOFF_FAILED','AI_EXPORT_QUEUE_FAILED'],true)) throw $error;
            // The graph is server-owned.  Record a bounded execution-code
            // diagnostic, never an exception message that may contain data.
            $known=['AI_EVIDENCE_INCOMPLETE','AI_NODE_OUTPUT_INVALID','AI_WORKFLOW_BUDGET_EXHAUSTED','AI_NODE_TIMEOUT','AI_EXECUTION_CLOCK_REGRESSED','AI_EXECUTION_CLOCK_INVALID','AI_WORKFLOW_DEPENDENCY_UNSATISFIED','AI_ANSWER_RENDER_FAILED','AI_EVIDENCE_GUARD_FAILED'];
            if (in_array($error->getMessage(),$known,true)) $predicate='workflow_'.strtolower($error->getMessage());
            elseif (preg_match('/^AI_WORKFLOW_NODE_([A-Z0-9_]{1,39})_FAILED$/D',$error->getMessage(),$matches)) $predicate='workflow_node_'.strtolower($matches[1]).'_failed';
            elseif ($error instanceof \app\services\query\metric\MetricQueryContractException) {
                $code=strtolower($error->getErrorCode());
                $predicate=preg_match('/^[a-z0-9_]{1,64}$/D',$code)?'workflow_query_'.$code:'workflow_query_contract';
            }
            elseif (preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D',$error->getMessage())) $predicate='workflow_'.strtolower($error->getMessage());
            elseif ($error instanceof \TypeError) $predicate='workflow_type_error';
            elseif ($error instanceof \Error) $predicate='workflow_php_error';
            else $predicate='workflow_unexpected';
            $this->recordRuntimeDiagnostic($owner,$id,$generation,$worker,$predicate);
            throw new RuntimeException('AI_WORKFLOW_EXECUTION_FAILED');
        }
        if ($waiting!==null) return $waiting;
        $guard();
        if (!$publish) return ['plan'=>$plan,'evidence'=>$evidence,'answer'=>$answer,'trace'=>$execution['trace']];
        // Persisting encrypted evidence and changing a Run to COMPLETED are
        // not rendering.  A distinct server-owned progress state lets a
        // stalled response be attributed to the handoff boundary without
        // retaining customer text or reinterpreting their request.
        $this->runs->progress($owner,$id,$generation,$worker,'PUBLISHING');
        [$evidenceRef,$answerRef]=$this->saveResults($owner,$id,$generation,$plan,$evidence,$answer,$execution['trace'],$contextMeaning);
        return $this->runs->publish($owner,$id,$generation,$worker,$evidenceRef,$answerRef);
    }

    /**
     * A collection is a bounded ordered set of independently compiled Reader
     * requests. It deliberately has one Run, one authority snapshot and one
     * terminal publication; it is not a child-run or retry framework.
     */
    private function executeRegisteredCollection(array $context,array $owner,string $id,int $generation,string $worker,array $snapshot,array $collection,array $contextMeaning=[]): array
    {
        $items=$collection['items']??null;
        if (!is_array($items)||count($items)<2||count($items)>4||array_keys($items)!==range(0,count($items)-1)) throw new RuntimeException('AI_PLAN_COLLECTION_INVALID');
        $run=$this->runs->checkpoint($owner,$id,$generation,$worker);
        $today=(new \DateTimeImmutable('@'.intdiv($run['created_at'],1000)))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
        $budget=(int)explode('-',$snapshot['budget_profile_version'])[0];
        $remaining=min($budget,$run['deadline_at']-(int)floor(microtime(true)*1000));
        $compiler=new AiRegisteredPlanCompiler($this->registry(),static function()use($today):string{return $today;});
        $capabilities=$this->capabilities($context);$reserved=0;
        $completed=[];$seen=[];$prepared=[];
        foreach($items as $item) {
            if (!is_array($item)||array_keys($item)!==['id','label','plan']||!is_string($item['id']??null)
                ||!preg_match('/^q[1-4]$/D',$item['id'])||isset($seen[$item['id']])||!is_string($item['label']??null)
                ||$item['label']===''||mb_strlen($item['label'],'UTF-8')>64||!is_array($item['plan']??null)
                ||($item['plan']['output_format']??null)!=='screen') throw new RuntimeException('AI_PLAN_COLLECTION_INVALID');
            $seen[$item['id']]=true;
            // Every child is compiled before any reader is called. Its
            // graph-level max_path_ms includes a per-graph scheduling cushion;
            // for sequential collection admission the provable cumulative
            // bound is the sum of registered node deadlines. The executor
            // continues to enforce each child's full graph deadline as an
            // independent guard. This prevents a harmless per-child cushion
            // from being multiplied into a false collection rejection.
            $compiled=$compiler->compile($item['plan'],$capabilities,[
                'run_budget_ms'=>$budget,'remaining_execution_ms'=>$remaining-$reserved,
            ]);
            $nodeDeadlineMs=array_sum(array_column($compiled['nodes'],'timeout_ms'));
            if ($nodeDeadlineMs<1) throw new RuntimeException('AI_PLAN_COLLECTION_INVALID');
            $reserved+=$nodeDeadlineMs;
            if ($reserved+5000>$remaining) throw new RuntimeException('AI_WORKFLOW_BUDGET_EXHAUSTED');
            $prepared[]=$item;
        }
        foreach($prepared as $item) {
            $completed[]=['id'=>$item['id'],'label'=>$item['label'],'result'=>$this->executeRegisteredSingle(
                $context,$owner,$id,$generation,$worker,$snapshot,$item['plan'],[],false,$item['id'])];
        }
        $this->runs->checkpoint($owner,$id,$generation,$worker);
        $this->runs->progress($owner,$id,$generation,$worker,'PUBLISHING');
        [$evidenceRef,$answerRef]=$this->saveCollectionResults($owner,$id,$generation,$completed,$contextMeaning);
        return $this->runs->publish($owner,$id,$generation,$worker,$evidenceRef,$answerRef);
    }

    private function saveCollectionResults(array $owner,string $id,int $generation,array $completed,array $contextMeaning=[]): array
    {
        $binding=['owner'=>$owner,'run_id'=>$id,'generation'=>$generation];$expires=null;$sources=[];$sections=[];
        foreach($completed as $item) {
            $result=$item['result'];$evidence=$result['evidence'];$answer=$result['answer'];
            if (!is_array($evidence)||!is_array($answer)||!is_int($evidence['expires_at']??null)) throw new RuntimeException('AI_EVIDENCE_INVALID');
            $expires=$expires===null?$evidence['expires_at']:min($expires,$evidence['expires_at']);
            $sources[]=['id'=>$item['id'],'label'=>$item['label'],'query'=>$result['plan']['query'],'view_ref'=>$evidence['read_consistency_ref'],'trace'=>$result['trace']];
            $sections[]=['id'=>$item['id'],'title'=>$item['label'],'answer'=>$answer];
        }
        if ($expires===null) throw new RuntimeException('AI_EVIDENCE_INVALID');
        $summary=implode("\n\n",array_map(static function(array $section):string{return (string)($section['answer']['summary']??'');},$sections));
        // A collection keeps every source view separately signed. This only
        // marks the combined screen answer for an independent, source-bound
        // Excel task; no section is queried again during file generation.
        $answer=AiAnswerRenderer::limitTables(['summary'=>$summary,'cards'=>[],'sections'=>$sections]);
        return [$this->private->put('evidence',$binding+['items'=>$sources,'context_meaning'=>$contextMeaning,'collection_version'=>1],$expires),
            $this->private->put('answer',$binding+['answer'=>$answer],$expires)];
    }

    private function saveResults(array $owner,string $id,int $generation,array $plan,array $evidence,array $answer,array $trace,array $contextMeaning=[]): array
    {
        $expires=min($evidence['expires_at'],intdiv($this->runs->get($owner,$id,$generation)['expires_at'],1000));
        $binding=['owner'=>$owner,'run_id'=>$id,'generation'=>$generation];
        $source=$plan['query']===null?['definition'=>$evidence]:['view_ref'=>$evidence['read_consistency_ref'],'query'=>$plan['query'],'context_meaning'=>$contextMeaning];
        return [$this->private->put('evidence',$binding+$source+['compiled_run_hash'=>$plan['compiled_run_hash'],'execution_trace'=>$trace,
            'workflow_code'=>$plan['workflow_code'],'management_version'=>$this->managementRevision],$expires),
            $this->private->put('answer',$binding+['answer'=>$answer],$expires)];
    }

    private function definitionEvidence(array $context,array $codes): array
    {
        $fresh=$this->fresh($context); $binding=\app\services\ai\execution\AiAuthority::reportBinding($fresh,$this->instance,$this->private->signingKey());
        if (!in_array($fresh['scope_mode'],['all','stores','self_participant'],true) || !$fresh['store_ids']) throw new RuntimeException('AI_PERMISSION_DENIED');
        $capabilities=$this->capabilities($fresh); $dictionary=new \app\services\metric\MetricDictionaryServices(); $definitions=[]; $readiness=[];
        if (!$codes) throw new RuntimeException('AI_METRIC_EXPLANATION_NOT_READY');
        foreach ($codes as $code) {
            if (!in_array($code,$capabilities['definition_metric_codes'],true)) throw new RuntimeException('AI_METRIC_EXPLANATION_NOT_READY');
            $tooltip=$dictionary->getTooltip($code); unset($tooltip['updated_at']);
            $definitions[]=$tooltip; $readiness[$code]=$capabilities['metadata_readiness'][$code];
        }
        return ['definitions'=>$definitions,'definition_metric_codes'=>$codes,'metadata_readiness'=>$readiness,'permission_version'=>$binding['permission_version'],'expires_at'=>time()+86400];
    }

    private function verifyDefinition(array $context,array $evidence): void
    {
        $current=$this->definitionEvidence($context,$evidence['definition_metric_codes']);
        if ($evidence['expires_at']<=time() || $current['permission_version']!==$evidence['permission_version']
            || $current['metadata_readiness']!==$evidence['metadata_readiness'] || $current['definitions']!==$evidence['definitions']) throw new RuntimeException('AI_AUTHORIZATION_CHANGED');
    }

    /**
     * A completed Run has already passed the Reader's create-and-replay guard
     * before its answer was published. Delivering that immutable answer must
     * verify the signed source snapshot against the account's *current*
     * authority, but must not turn a transient second Reader replay into an
     * apparent failed query after the fact.
     *
     * Follow-up context deliberately still uses MetricReadViewServices::replay
     * below: it is a new operation and must rebuild current authority. This
     * narrow check only protects delivery of the already-completed Run.
     */
    private function verifyQueryEvidenceForDelivery(array $context,array $evidence): void
    {
        if (isset($evidence['items'])) {
            if (!is_array($evidence['items']) || count($evidence['items'])<2 || count($evidence['items'])>4
                || array_keys($evidence['items'])!==range(0,count($evidence['items'])-1)) {
                throw new RuntimeException('AI_EVIDENCE_BINDING_INVALID');
            }
            foreach ($evidence['items'] as $item) {
                if (!is_array($item) || !is_array($item['query']??null) || !is_string($item['view_ref']??null)) {
                    throw new RuntimeException('AI_EVIDENCE_BINDING_INVALID');
                }
                $this->verifyQueryEvidenceForDelivery($context,['query'=>$item['query'],'view_ref'=>$item['view_ref']]);
            }
            return;
        }
        if (!is_array($evidence['query']??null) || !is_string($evidence['view_ref']??null)) {
            throw new RuntimeException('AI_EVIDENCE_BINDING_INVALID');
        }
        $fresh=$this->fresh($context);
        $binding=\app\services\ai\execution\AiAuthority::reportBinding($fresh,$this->instance,$this->private->signingKey());
        $view=$this->views->get($evidence['view_ref']);
        $snapshotBinding=$view['binding']??null;
        $requestedStores=$evidence['query']['store_ids']??null;
        if (!is_array($snapshotBinding) || !is_array($requestedStores) || !is_array($view['query']??null)) {
            throw new RuntimeException('AI_EVIDENCE_BINDING_INVALID');
        }
        foreach (['query_shape','metric_codes','start_date','end_date','store_ids','business_filters','ranking'] as $key) {
            if (($view['query'][$key]??null)!==($evidence['query'][$key]??null)) {
                throw new RuntimeException('AI_EVIDENCE_BINDING_INVALID');
            }
        }
        $range=function($value): ?array {
            if ($value===null) return null;
            if (!is_array($value)) return null;
            $start=$value['start']??$value['start_date']??null;
            $end=$value['end']??$value['end_date']??null;
            return is_string($start)&&is_string($end)?['start'=>$start,'end'=>$end]:null;
        };
        if ($range($view['query']['compare_range']??null)!==$range($evidence['query']['compare_range']??null)) {
            throw new RuntimeException('AI_EVIDENCE_BINDING_INVALID');
        }
        // MetricReadViewServices narrows the source binding to the query's
        // requested stores. An empty stored selection means the full current
        // authorized range; an explicit selection means that exact subset.
        // Check the same authority dimensions without invoking a second
        // Reader replay while rendering an answer that was already published.
        $expectedStores=$requestedStores===[]?$binding['store_ids']:$requestedStores;
        $sameAuthority=true;
        foreach (['instance_id','subject_ref','terminal','tenant_id','permission_version','report_capability_code','scope_provider_code','scope_mode','store_report_authorized','employee_id'] as $key) {
            if (!array_key_exists($key,$snapshotBinding) || !array_key_exists($key,$binding) || $snapshotBinding[$key]!==$binding[$key]) {
                $sameAuthority=false; break;
            }
        }
        if (!$sameAuthority || ($snapshotBinding['store_ids']??null)!==$expectedStores
            || array_diff($expectedStores,$binding['store_ids'])!==[]) {
            throw new RuntimeException('AI_AUTHORIZATION_CHANGED');
        }
    }

    private function queryService(array $context,?callable $checkpoint=null): MetricReadViewServices
    {
        return new MetricReadViewServices($this->views,function () use($context): array {
            $c=$this->fresh($context);
            if (empty($c['can_use'])) throw new RuntimeException('AI_PERMISSION_DENIED');
            // Query, screen replay and export replay must build the exact same
            // authority binding.  A locally reassembled subset can be equal in
            // meaning yet differ in a newly added permission field, which would
            // make an already verified screen result impossible to export.
            return \app\services\ai\execution\AiAuthority::reportBinding($c,$this->instance,$this->private->signingKey());
        },$this->queryTransaction ?: [new \app\services\query\metric\MetricReadTransaction(10000,$checkpoint),'run'],null,$this->personnelObjects($context),$this->memberObjects($context));
    }
    private function personnelObjects(array $context): \app\services\query\metric\PersonnelAnalysisObjectServices
    {
        return new \app\services\query\metric\PersonnelAnalysisObjectServices(static function(string $table){return \think\facade\Db::name($table);},function(string $metric)use($context):array {
            $fresh=$this->fresh($context);
            if (empty($fresh['can_use']) || !AiConfigStore::allowsSanitizedQuestion($this->config->read())) {
                throw new RuntimeException('AI_PERSONNEL_PERMISSION_REQUIRED');
            }
            $employee=($fresh['scope_mode']??null)==='self_participant'?(int)($fresh['employee_id']??0):0;
            if (($fresh['scope_mode']??null)==='self_participant' && $employee<1) throw new RuntimeException('AI_PERSONNEL_PERMISSION_REQUIRED');
            return ['personnel_authorized'=>true,'store_ids'=>$fresh['store_ids'],'employee_id'=>$employee,'permission_version'=>$this->permissionHash($fresh)];
        });
    }
    private function memberObjects(array $context): \app\services\query\metric\MemberAnalysisObjectServices
    {
        return new \app\services\query\metric\MemberAnalysisObjectServices(static function(string $table){return \think\facade\Db::name($table);},function()use($context):array {
            $fresh=$this->fresh($context);
            if (empty($fresh['can_use']) || ($fresh['member_data_authorized']??false)!==true
                || !AiConfigStore::allowsSanitizedQuestion($this->config->read())) {
                throw new RuntimeException('AI_MEMBER_PERMISSION_REQUIRED');
            }
            return ['member_authorized'=>true,'store_ids'=>$fresh['store_ids'],'scope_mode'=>$fresh['scope_mode']??null,
                'permission_version'=>$this->permissionHash($fresh)];
        });
    }
    private function fresh(array $context): array
    {
        if (!isset($context['_refresh']) || !is_callable($context['_refresh'])) throw new RuntimeException('AI_SCOPE_REFRESH_UNAVAILABLE');
        $fresh=call_user_func($context['_refresh']);
        if (($fresh['account_id']??null)!==$context['account_id'] || ($fresh['terminal']??null)!==$context['terminal']) throw new RuntimeException('AI_AUTHORIZATION_CHANGED');
        return $fresh;
    }
    private function capabilities(array $context=[]): array
    {
        $exportReady=$context && function_exists('config') && config('mohe_ai.export.compatible_workers_ready',false)===true && $this->exportRuntime()->ready($context);
        // A personnel analysis capability is derived from the person's own
        // resolved data scope. It is never inferred from an entry point or a
        // report-menu flag. Resolvers set personnel_data_authorized only after
        // they have established an effective, non-empty staff/store scope.
        $context['analysis_personnel_ready']=$context
            && (($context['personnel_data_authorized']??true)===true)
            && AiConfigStore::allowsSanitizedQuestion($this->config->read());
        return \app\services\ai\execution\AiAuthority::capabilities($exportReady,$context);
    }
    private function exportRuntime(): \app\services\ai\execution\AiExportRuntime
    {
        if (!$this->exports) $this->exports=new \app\services\ai\execution\AiExportRuntime(['runs'=>$this->runs,'config'=>$this->config,'private'=>$this->private,'instance'=>$this->instance,'views'=>$this->views]);
        return $this->exports;
    }
    private function monitor(): \app\services\ai\execution\AiRuntimeMonitor
    {
        $profile=function_exists('config')?(array)config('mohe_ai.monitoring',[]):[];
        return new \app\services\ai\execution\AiRuntimeMonitor($profile,$this->private->monitoringDirectory());
    }
    private function limits(): array
    {
        $values=[];
        foreach (['run_budget_ms'=>180000,'execution_slots'=>4,'active_run_limit'=>8,'max_clarification_rounds'=>3] as $key=>$default) $values[$key]=function_exists('config')?config('mohe_ai.'.$key,$default):$default;
        if ($this->managementDocument) $values['max_clarification_rounds']=$this->managementDocument['guidance']['max_rounds'];
        foreach ($values as $value) if (!is_int($value)) throw new RuntimeException('AI_CAPACITY_PROFILE_INVALID');
        if ($values['run_budget_ms']<180000||$values['run_budget_ms']>300000||$values['execution_slots']<1||$values['execution_slots']>64||$values['active_run_limit']<1||$values['active_run_limit']>128) throw new RuntimeException('AI_CAPACITY_PROFILE_INVALID');
        if (!in_array($values['max_clarification_rounds'],[3,4,5],true)) throw new RuntimeException('AI_GUIDANCE_PROFILE_INVALID');
        return $values;
    }
    private function conversation(array $input,?array $context=null,?array $owner=null): array
    {
        $body=(new AiModelInputProjector())->validateConversation($input['question']??null,$input['history']??null);
        $format=$input['output_format']??'screen'; if (!in_array($format,['screen','screen_and_xlsx'],true)) throw new RuntimeException('AI_OUTPUT_FORMAT_INVALID');
        if (isset($input['context_ref'])) {
            if (!is_string($input['context_ref']) || strlen($input['context_ref'])>2048 || substr_count($input['context_ref'],'.')!==1) throw new RuntimeException('AI_CONTEXT_REQUIRED');
            $body['context_ref']=$input['context_ref'];
        }
        $body['output_format']=$format;
        if (array_key_exists('clarification_restart',$input)) {
            if ($context===null || $owner===null) throw new RuntimeException('AI_CLARIFICATION_STALE');
            $this->expiredClarificationSource($context,$owner,$input['clarification_restart']);
            // Only the signed source and new choices cross the queue. Private
            // meaning stays server-owned and participates in admission hashing.
            $body['clarification_restart']=$input['clarification_restart'];
        }
        return $body;
    }
    private function bodyHash(array $body): string
    {
        $canonical=function($value) use(&$canonical) {
            if (!is_array($value)) return $value;
            if ($value!==[] && array_keys($value)!==range(0,count($value)-1)) ksort($value,SORT_STRING);
            foreach ($value as $key=>$item) $value[$key]=$canonical($item);
            return $value;
        };
        return hash_hmac('sha256',json_encode($canonical($body),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$this->private->signingKey());
    }
    private function executionHash(string $operation,array $input): string
    {
        return AiExecutionEnvelope::hash($operation,$input,$this->private->signingKey());
    }
    private function supervisorHost(): string
    {
        $host=(string)php_uname('n');
        return preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D',$host)?$host:'host-'.substr(hash('sha256',$host),0,32);
    }
    private function supervisorId(): string
    {
        return 'supervisor-'.getmypid().'-'.substr(hash('sha256',$this->instance.':'.$this->supervisorHost()),0,24);
    }
    private function identity(array $c): string { return \app\services\ai\execution\AiAuthority::identity($c,$this->instance,$this->private->signingKey()); }
    private function permissionHash(array $c): string { return \app\services\ai\execution\AiAuthority::permissionHash($c); }
    private function identifier($value): string { if (!is_string($value)||!preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D',$value)) throw new RuntimeException('AI_REFERENCE_INVALID'); return $value; }
    private function token(array $payload): string { $body=rtrim(strtr(base64_encode(json_encode($payload)),'+/','-_'),'='); return $body.'.'.hash_hmac('sha256',$body,$this->private->signingKey()); }
    private function verifyToken($token,array $context,string $session,string $type): array
    {
        if (!is_string($token)||strlen($token)>2048||substr_count($token,'.')!==1) throw new RuntimeException('AI_DELIVERY_INVALID');
        [$body,$mac]=explode('.',$token,2);
        if (!hash_equals(hash_hmac('sha256',$body,$this->private->signingKey()),$mac)) throw new RuntimeException('AI_DELIVERY_INVALID');
        $data=json_decode(base64_decode(strtr($body,'-_','+/')),true);
        if (!is_array($data)||($data['identity']??'')!==$this->identity($context)||($data['window']??'')!==$session||($data['type']??'')!==$type||($data['expires']??0)<=time()) throw new RuntimeException('AI_DELIVERY_INVALID');
        return $data;
    }
    /**
     * Keeps trusted-query lifecycle out of the HTTP facade while reusing the
     * existing signing, evidence-binding and Reader replay primitives.
     */
    private function contextService(): VerifiedQueryContext
    {
        return new VerifiedQueryContext(
            $this->private,
            function(string $reference,array $context,string $window,string $type): array { return $this->verifyToken($reference,$context,$window,$type); },
            function(array $claims): string { return $this->token($claims); },
            function(array $context): string { return $this->identity($context); },
            function(array $stored,array $owner,string $run,int $generation): void { $this->assertBinding($stored,$owner,$run,$generation); },
            function(array $context,array $query,string $viewRef): array { return $this->queryService($context)->replay([],$query,$viewRef); }
        );
    }

    /**
     * A signed prior answer is never a reason to weaken the current Reader
     * contract.  If that old query can no longer be replayed safely, surface
     * a recoverable context boundary rather than misreporting it as a model
     * or data-query failure. Current permission changes remain explicit.
     */
    private function restoreContext(array $context,array $owner,string $reference): array
    {
        try {
            return $this->contextService()->restore($context,$owner,$reference);
        } catch (\app\services\query\metric\MetricQueryContractException $error) {
            if (in_array($error->getErrorCode(),[
                'METRIC_PERMISSION_CHANGED','METRIC_PERMISSION_DENIED','METRIC_PERMISSION_GRAIN_UNAVAILABLE',
            ],true)) throw new RuntimeException('AI_AUTHORIZATION_CHANGED');
            throw new RuntimeException('AI_CONTEXT_REQUIRED');
        }
    }
    private function present(array $context,array $owner,array $run): array
    {
        $result=['run_id'=>$run['run_id'],'generation'=>$run['generation'],'version'=>$run['version'],'status'=>$run['status'],'reason'=>$run['reason'],
            'feedback_available'=>$run['feedback_available']??false,
            'progress'=>$this->progressText($run),'message'=>$this->progressText($run),'run_delivery_token'=>$this->token(['type'=>'run','identity'=>$this->identity($context),'window'=>$owner['window_id'],
                'conversation'=>$owner['conversation_id'],'run'=>$run['run_id'],'generation'=>$run['generation'],'expires'=>intdiv($run['expires_at'],1000)]),
            'execution_mode'=>$run['execution_mode']??'compatibility',
            'topic_boundary'=>$run['topic_boundary']??null,
            'server_time'=>(int)floor(microtime(true)*1000),
            'clarification_deadline_at'=>$run['clarification_deadline_at']??null];
        if ($run['status']==='WAITING_CLARIFICATION' || ($context['terminal']==='platform' && $run['status']==='FAILED' && $run['reason']==='CLARIFICATION_EXPIRED')) {
            $stored=$this->private->read($run['clarification_ref']); $this->assertBinding($stored,$owner,$run['run_id'],$run['generation']);
            $result['clarification']=['id'=>$run['clarification_ref'],'question'=>$stored['envelope']['question']??'请确认这一项查询条件','fields'=>$stored['envelope']['fields']];
            // This flag contains no business data; it only tells a polling
            // client that its already-accepted submission must be shown again.
            $result['clarification_rejected']=!empty($run['clarification_rejected']);
            if ($run['guidance_schema_version']==='mohe-clarification-v2') {
                $result['clarification']+=['schema_version'=>'mohe-clarification-v2','step_revision'=>1,'intent_revision'=>$run['clarification_count'],
                    'round_no'=>$run['clarification_count'],'max_clarification_rounds'=>$run['max_clarification_rounds'],
                    'confirmed_summary'=>$stored['envelope']['confirmed_summary']??[],
                    'revisable_steps'=>array_map(static function($step) { return ['id'=>$step['id'],'question'=>$step['envelope']['question']??'已确认条件','fields'=>$step['envelope']['fields'],'choices'=>$step['choices']]; },$stored['accepted_steps']??[])];
            }
        }
        if (in_array($run['status'],['COMPLETED','PARTIAL_SUCCEEDED'],true)) {
            $stored=$this->private->read($run['evidence_ref']); $this->assertBinding($stored,$owner,$run['run_id'],$run['generation']);
            if (isset($stored['definition'])) $this->verifyDefinition($context,$stored['definition']);
            else $this->verifyQueryEvidenceForDelivery($context,$stored);
            $answer=$this->private->read($run['answer_ref']); $this->assertBinding($answer,$owner,$run['run_id'],$run['generation']);
            $result['answer']=$answer['answer'];
            if ($context['terminal']==='platform' && !empty($this->fresh($context)['can_configure'])) {
                $result['management_trace']=['workflow_code'=>$stored['workflow_code']??null,'management_version'=>$stored['management_version']??'source',
                    'nodes'=>$stored['execution_trace']??[]];
            }
            $contextReference=$this->contextService()->issue($context,$owner,$run,$stored);
            if ($contextReference!==null) $result['answer']['context_ref']=$contextReference;
            if ($run['status']==='COMPLETED' && ($answer['answer']['export_status']??null)==='ready' && !empty($answer['export_task_no'])) {
                $result['answer']['export']=['file_ref'=>$answer['export_task_no'],'filename'=>'经营数据.xlsx'];
            }
            if ($run['status']==='PARTIAL_SUCCEEDED') {
                unset($result['answer']['export']);
                $result['answer']['summary'].=' 数据已核对，但 Excel 文件生成失败。';
            }
        }
        if ($run['status']==='FAILED') {
            $result['failure_guidance']=$this->failureGuidance($run['reason'],($context['terminal']??null)==='platform');
            if (($context['terminal']??null)==='platform') {
                $result['message']=$result['progress']=$result['failure_guidance']['question'];
                if ($run['reason']==='AI_MODEL_CIRCUIT_OPEN') {
                    $result['retry_after_ms']=$this->runs->modelCircuitRetryAfterMs($owner,$run['run_id'],$run['generation']);
                    $result['failure_guidance']['retry_after_ms']=$result['retry_after_ms'];
                }
            }
            if ($context['terminal']==='platform' && $run['reason']==='CLARIFICATION_EXPIRED') {
                $result['message']=$result['progress']='补充等待已超时，尚未执行查询。已确认条件已保留，可继续原查询。';
                $result['failure_guidance']=['kind'=>'clarification_expired','question'=>$result['message']];
            }
            // A failure code does not prove a topic change: a continuation
            // may be unresolved or interrupted by transport. Preserve only
            // the server-confirmed boundary already carried by publicRun().
            // Failed turns never become verified query contexts themselves.
            // A revoked account can still read its own failed task. The
            // optional administrator diagnostic must not hide that response.
            try {
                if ($context['terminal']==='platform' && !empty($this->fresh($context)['can_configure'])) {
                    $diagnostic=$this->runs->runDiagnostic($owner,$run['run_id'],$run['generation']);
                    if ($diagnostic!==null) $result['management_trace']=['model_diagnostic'=>$diagnostic];
                }
            } catch (\Throwable $ignored) {}
        }
        return $result;
    }
    /** A failed Run stays failed. No query meaning and a pre-understanding
     * capability drift need plain capability help, not a retry card. Other
     * failures offer customer actions, never an executable substitute plan. */
    private function failureGuidance(?string $reason,bool $platform=false): array
    {
        // Platform failures describe the actual boundary. They are not
        // missing business conditions and cannot be repaired by a fixed menu.
        // Keep the legacy drawer contract below until its UI is approved.
        if ($platform) {
            if ($reason==='AI_INTENT_UNRESOLVED') return ['kind'=>'capability_help',
                'question'=>'暂未确定这次要查询的业务内容。您可以补充要查询的对象或指标；我可以查询系统内的经营、会员和员工业绩数据。'];
            $messages=[
                'AI_MODEL_CIRCUIT_OPEN'=>'AI 理解服务连续响应异常，已暂时暂停调用；明确指标和时间的查询仍可尝试。',
                'CIRCUIT_OPEN'=>'查询服务暂时暂停受理，请稍后重试。',
                'RATE_LIMITED'=>'您近期提问较频繁，请稍后再问。',
                'OTHER_CONVERSATION_ACTIVE'=>'您还有一个对话正在执行，请先完成或停止该任务。',
                'CAPACITY_REJECTED'=>'当前查询任务较多，请稍后重试。',
                'CAPACITY_STOPPED'=>'当前查询服务繁忙，请稍后重试。',
                'AI_MODEL_RESULT_UNKNOWN'=>'AI 理解服务未及时返回结果，请稍后重试。',
                'AI_MODEL_REQUEST_FAILED'=>'AI 理解服务响应异常，请稍后重试。',
                'AI_MODEL_ACCOUNT_UNAVAILABLE'=>'AI 服务配置暂不可用，请管理员检查连接配置。',
                'AI_MODEL_CONFIG_INVALID'=>'AI 服务配置不完整，请管理员检查连接配置。',
                'AI_CAPABILITY_CHANGED'=>'查询能力已更新，本次未能继续。请重新提交原问题。',
                'AI_AUTHORIZATION_CHANGED'=>'当前权限已变化，请刷新页面后重新查询。',
                'AI_PERMISSION_DENIED'=>'当前账号没有这项查询权限。',
                'METRIC_PERMISSION_DENIED'=>'当前账号没有这项数据的查看权限。',
                'METRIC_PERMISSION_CHANGED'=>'数据权限已变化，请刷新页面后重新查询。',
                'METRIC_PERMISSION_GRAIN_UNAVAILABLE'=>'当前权限范围不支持这项明细查询。',
                'AI_CAPABILITY_NOT_READY'=>'当前系统尚不能执行这项查询。',
                'AI_ANALYSIS_COMBINATION_UNAVAILABLE'=>'当前系统尚不能完整执行这些对象、指标和条件的组合。',
                'AI_PERSONNEL_PERMISSION_REQUIRED'=>'当前账号没有员工业绩查询权限。',
                'AI_NODE_TIMEOUT'=>'数据查询未能在规定时间内完成，请稍后重试。',
                'DEADLINE_EXCEEDED'=>'本次处理超时，尚未获得可交付的结果，请稍后重试。',
                'AI_ATTEMPT_UNRESOLVED'=>'本次处理结果暂未确认，未交付查询结果，请稍后重试。',
                'AI_BINDING_SEMANTIC_REJECTED'=>'本次未能准确对应您的查询条件，请重新提交原问题。',
                'AI_CONTEXT_REQUIRED'=>'前面的查询条件已无法继续使用，请补充本次要查询的对象、指标和时间。',
                'AI_RESULT_REFERENCE_UNAVAILABLE'=>'前面的结果中没有可确认的对应对象，请指定对象或重新查询排名。',
            ];
            // This kind selects a terminal notification, not a network-error
            // classification. The exact reason retains permission, capability
            // and transport distinctions for diagnostics and client recovery.
            return ['kind'=>'technical_error','question'=>$messages[$reason]??'本次暂未完成查询，请稍后重试。'];
        }
        if ($reason==='AI_INTENT_UNRESOLVED') return ['kind'=>'capability_help',
            'question'=>'我主要帮您查询门店经营数据，比如今天的现金业绩、消耗业绩。您可以直接用平常的话提问。'];
        if ($reason==='AI_CAPABILITY_CHANGED') return ['kind'=>'capability_help',
            'question'=>'这次暂时没能完成查询。我主要帮您查询门店经营数据，比如今天的现金业绩、消耗业绩。您可以直接再问一次。'];
        // UNKNOWN is a transport outcome, not a missing customer condition.
        // Keep the merchant's familiar three actions while naming the real
        // failure so the customer can retry the original question unchanged.
        $question=$reason==='AI_MODEL_RESULT_UNKNOWN'
            ?'AI 服务未及时返回可靠结果，请稍后按原问题重试。'
            :(in_array($reason,['AI_AUTHORIZATION_CHANGED','AI_PERSONNEL_PERMISSION_REQUIRED','AI_WORKFLOW_DISABLED','AI_MODEL_ACCOUNT_UNAVAILABLE'],true)
            ?'当前账号暂时无法完成这次查询。请选择一种方式继续。'
            :'这次没能给出可靠结果。请选择一种方式继续。');
        return ['kind'=>'recovery','question'=>$question,'options'=>[
            ['value'=>'retry_original','label'=>'按原问题再查一次'],
            ['value'=>'edit_original','label'=>'修改原问题后查询'],
            ['value'=>'new_conversation','label'=>'开启新对话'],
        ],'supplement_label'=>'我来补充描述'];
    }
    private function assertBinding(array $stored,array $owner,string $id,int $generation): void
    {
        if (($stored['owner']??null)!=$owner || ($stored['run_id']??'')!==$id || ($stored['generation']??0)!==$generation) throw new RuntimeException('AI_EVIDENCE_BINDING_INVALID');
    }
    private function progressText(array $run): string
    {
        if ($run['status']==='CANCELLED') return '已取消';
        if ($run['status']==='COMPLETED') return '查询完成';
        if ($run['status']==='PARTIAL_SUCCEEDED') return '数据已核对，文件未生成';
        if ($run['status']==='WAITING_EXPORT') return '正在生成 Excel 文件';
        if ($run['status']==='FAILED') return $this->failureGuidance($run['reason'])['question'];
        if ($run['status']==='WAITING_CLARIFICATION') return '请确认当前这一步，已确认条件会保留。';
        return ['RECEIVED'=>'已接收问题','UNDERSTANDING'=>'正在理解查询条件','QUERYING'=>'正在查询经营数据','VERIFYING'=>'正在核对数据','RENDERING'=>'正在整理结果','PUBLISHING'=>'正在保存并交付结果','EXPORTING'=>'正在生成文件'][$run['progress_code']]??'正在处理';
    }
    private function checkConfig(array $input): array
    {
        if (($input['confirm_cost']??false)!==true) throw new RuntimeException('AI_ACTIVE_TEST_CONFIRM_REQUIRED');
        $configuration=$this->config->read(true);
        if (!$configuration['external_processing_authorized']) throw new RuntimeException('AI_EXTERNAL_AUTHORIZATION_REQUIRED');
        $deadline=microtime(true)+10;
        $probe=$this->config->beginProbe((int)$configuration['version']);
        try {
            $reply=(new SiliconFlowClient($configuration['provider']))->probe($configuration['model'],$configuration['api_key'],10000,
                static function () use($deadline):void { if (microtime(true)>$deadline) throw new RuntimeException('AI_CHECK_TIMEOUT'); });
            $this->config->finishProbe($probe,'SUCCEEDED',$reply['usage']['input_tokens']??null,$reply['usage']['output_tokens']??null);
        } catch (\Throwable $error) {
            // Unknown wire outcomes retain unknown usage; do not resend or expose provider details.
            $this->config->finishProbe($probe,in_array($error->getMessage(),['AI_MODEL_RESULT_UNKNOWN','AI_CANCELLED','AI_CHECK_TIMEOUT'],true)?'UNKNOWN':'FAILED');
            throw $error;
        }
        return ['status'=>'available','message'=>'本次连接与结构化响应测试通过，不代表账户余额充足。'];
    }
}
