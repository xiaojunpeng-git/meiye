<?php
namespace app\services\ai\execution;

use app\services\query as Q;
use app\services\query\metric as M;
use think\facade\Db;

/** Explicitly gated independent export runtime, reusing the report Task/compiler/Worker/Writer. */
final class AiExportRuntime
{
    private $runtime; private $tasks; private $worker; private $resolver; private $readViews; private $binding;
    private $settings; private $principalResolver; private $dispatch;

    /** Optional resolver/dispatch hooks are trusted test composition only; HTTP never accepts them. */
    public function __construct(?array $runtime=null,?callable $principalResolver=null,?callable $dispatch=null)
    {
        $this->runtime=$runtime??AiRuntimeFactory::make();
        $this->principalResolver=$principalResolver; $this->dispatch=$dispatch;
        $this->settings=(array)config('mohe_ai.export',[]);
        $definitions=(new \app\services\metric\MetricDictionaryServices())->getDefinitions();
        $pages=Q\UnifiedQueryPageRegistry::fromRegistrars($definitions,[new M\MetricReadViewExportRegistrar()]);
        $access=new Q\UnifiedQueryAccessPolicy(); $validator=new Q\StructuredExpressionValidator($pages);
        $references=new Q\UnifiedQueryFieldReferenceServices();
        $custom=new Q\UnifiedQueryCustomFieldServices($pages,$validator,$access,$references);
        $execution=new Q\UnifiedQueryExecutionServices($pages,$validator,new Q\StructuredExpressionEvaluator());
        $preferences=new Q\UnifiedQueryPreferenceServices($pages,$custom,$execution,$access,$references);
        $aliases=new Q\UnifiedQueryFieldAliasServices($pages,$custom,$access);
        $this->tasks=new Q\UnifiedQueryExportTaskServices($pages,$custom,$aliases,$references,$execution,$preferences,$access);
        $this->tasks->setAiFence(function(array $binding,string $phase,callable $action) {
            $run=$this->validateBinding($binding,$phase);
            return $this->runtime['runs']->exportFence($this->owner($binding),$binding['run_id'],$binding['generation'],$binding['worker_token'],$phase,
                function () use($phase,$action) {
                    if ($phase==='create') {
                        $count=Db::name(Q\UnifiedQueryExportTaskServices::TABLE)->where('source_type','AI')->whereIn('status',['pending','running'])->count();
                        if ($count>=$this->settings['reserved_slots']) throw new \RuntimeException('AI_EXPORT_CAPACITY_REJECTED');
                    }
                    return $action();
                },($binding['mode']??'')==='independent_v1');
        });
        $factory=new Q\UnifiedQueryContextFactory($pages);
        $this->resolver=new AiExportWorkerContextResolver($factory,function(array $task):array {
            if (($task['source_type']??null)!=='AI') throw new \RuntimeException('EXPORT_PARTITION_MISMATCH');
            $binding=Q\UnifiedQueryJson::decode((string)$task['ai_binding']);
            $this->validateBinding($binding,'status');
            $run=$this->runtime['runs']->get($this->owner($binding),$binding['run_id'],$binding['generation']);
            if (($binding['mode']??'')==='independent_v1') {
                if ($run['status']!=='COMPLETED' || $run['evidence_ref']!==$binding['evidence_ref']
                    || $run['answer_ref']!==$binding['canonical_result_ref']
                    || $this->taskNo($binding)!==($task['task_no']??null)) throw new \RuntimeException('AI_EXPORT_TASK_BINDING_INVALID');
            } else {
                $handoff=$this->runtime['private']->read($run['answer_ref']);
                if (($handoff['export_task_no']??null)!==($task['task_no']??null)) throw new \RuntimeException('AI_EXPORT_TASK_BINDING_INVALID');
            }
            $this->binding=$binding; return $binding;
        },$this->principalResolver);
        $this->readViews=new M\MetricReadViewServices($this->runtime['views'],function():array {
            return AiAuthority::reportBinding($this->current(),$this->runtime['instance'],$this->runtime['private']->signingKey());
        },null,null,new M\PersonnelAnalysisObjectServices(static function(string $table){return Db::name($table);},function(string $metric):array {
            $context=$this->current();
            if (empty($context['can_use'])
                || !\app\services\ai\config\AiConfigStore::allowsSanitizedQuestion($this->runtime['config']->read())) {
                throw new \RuntimeException('AI_PERSONNEL_PERMISSION_REQUIRED');
            }
            return ['personnel_authorized'=>true,'store_ids'=>$context['store_ids'],'employee_id'=>0,'permission_version'=>AiAuthority::permissionHash($context)];
        }));
        $providers=new Q\UnifiedQueryProviderRegistry($pages);
        $providers->register(new M\MetricReadViewExportProvider($this->readViews,function(array $context,string $ref):array {
            if (!$this->binding || $ref!==$this->binding['read_consistency_ref']) throw new \RuntimeException('AI_EXPORT_SOURCE_MISMATCH');
            $evidence=$this->evidence($this->binding); return ['principal'=>[],'query'=>$evidence['query']];
        })); $providers->freeze();
        $resolvers=new Q\UnifiedQueryWorkerContextResolverRegistry($pages); $resolvers->register($this->resolver); $resolvers->freeze();
        $this->worker=new Q\UnifiedQueryExportWorkerServices($this->tasks,$providers,$resolvers,'AI_EXPORT');
    }

    public function ready(array $context=[]): bool
    {
        try {
            return ($this->settings['compatible_workers_ready']??null)===true && ($this->settings['reserved_slots_verified']??null)===true
                && ($this->settings['monitoring_ready']??null)===true
                && AiRuntimeMonitor::profileReady((array)config('mohe_ai.monitoring',[]))
                && is_int($this->settings['reserved_slots']??null) && $this->settings['reserved_slots']>0 && $this->settings['reserved_slots']<=16
                && is_int($this->settings['queue_wait_budget_ms']??null) && $this->settings['queue_wait_budget_ms']>0 && $this->settings['queue_wait_budget_ms']<=10000
                && is_int($this->settings['publication_reserve_ms']??null) && $this->settings['publication_reserve_ms']>=1000 && $this->settings['publication_reserve_ms']<=10000
                && ($this->dispatch || AiExportQueue::supported())
                && (!$context || !empty($context['export_principal_ready'])) && $this->tasks->hasSourceContract();
        } catch (\Throwable $e) { return false; }
    }

    /** Count retained AI file-task outcomes only; never read bindings, questions or result files for monitoring. */
    public static function taskDiagnostics(): array
    {
        try {
            $query=Db::name(Q\UnifiedQueryExportTaskServices::TABLE)->where('source_type','AI')
                ->where('created_at','>=',time()-Q\UnifiedQueryExportWorkerServices::RETENTION_SECONDS)->where('expires_at','>',time())
                ->whereIn('status',['succeeded','failed']);
            $rows=(clone $query)->field('status,COUNT(*) AS n')->group('status')->select()->toArray();
            $counts=['status'=>'ok','succeeded'=>0,'failed'=>0,'eligible'=>0,'failure_rate'=>null,'consecutive_failed'=>0];
            foreach ($rows as $row) $counts[$row['status']]=(int)$row['n'];
            $counts['eligible']=$counts['succeeded']+$counts['failed'];
            if ($counts['eligible']>0) $counts['failure_rate']=$counts['failed']/$counts['eligible'];
            // A bounded suffix is sufficient for the registered consecutive-failure threshold.
            $limit=max(1,min(100,(int)(config('mohe_ai.monitoring.export_consecutive_failures')??5)));
            $latest=(clone $query)->field('status')->order('completed_at','desc')->order('id','desc')->limit($limit)->select()->toArray();
            foreach ($latest as $row) { if ($row['status']!=='failed') break; ++$counts['consecutive_failed']; }
            return $counts;
        } catch (\Throwable $ignored) {
            // A broken task source is an alarm, never an empty healthy sample.
            return ['status'=>'unavailable'];
        }
    }

    public function queue(array $context,array $owner,array $run,string $workerToken,string $evidenceRef,string $answerRef,array $view): array
    {
        if (!$this->ready($context)) throw new \RuntimeException('AI_EXPORT_NOT_READY');
        $now=time(); $expiry=min(intdiv($run['expires_at'],1000),(int)$view['expires_at']);
        $binding=['instance_fingerprint'=>$this->runtime['instance'],'terminal'=>$owner['terminal'],'account_id'=>$owner['account_id'],
            'conversation_id'=>$owner['conversation_id'],'window_id'=>$owner['window_id'],'run_id'=>$run['run_id'],'generation'=>$run['generation'],
            'worker_token'=>$workerToken,'evidence_ref'=>$evidenceRef,'canonical_result_ref'=>$answerRef,'read_consistency_ref'=>$view['read_consistency_ref'],
            'created_at'=>$now,'expires_at'=>$expiry,'source_expires_at'=>[intdiv($run['expires_at'],1000),(int)$view['expires_at']],
            'execution_deadline_at'=>intdiv($run['deadline_at']-$this->settings['publication_reserve_ms'],1000),
            'queue_deadline_ms'=>min($run['deadline_at']-$this->settings['publication_reserve_ms'],(int)floor(microtime(true)*1000)+$this->settings['queue_wait_budget_ms']),
            'result_hash'=>$view['result_hash'],'permission_hash'=>AiAuthority::permissionHash($context),
            'model_config_version'=>(string)$this->runtime['config']->read()['version']];
        foreach (['principal_kind','origin_store_id','origin_organization_id','employee_id','staff_id'] as $key) if (array_key_exists($key,$context)) $binding[$key]=$context[$key];
        $binding['signature']=$this->signature($binding); $this->binding=$binding;
        $this->validateBinding($binding,'create');
        $this->replay(); $this->assertObjectOwner($this->runtime['private']->read($answerRef),$binding);
        $seed=['source_type'=>'AI','account_id'=>$owner['account_id'],'operator_id'=>$owner['account_id'],'tenant_id'=>(string)($context['tenant_id']??0),
            'query_cutoff_date'=>date('Y-m-d',strtotime($view['data_as_of'])),'data_as_of'=>strtotime($view['data_as_of'])];
        // The same resolver builds both creation and worker contexts, without a fake request/token.
        try {
            $factoryContext=$this->creationContext($binding,$seed);
        } catch (\Throwable $error) {
            // This is a server-side context construction fault.  Do not pass
            // its message into a task, a run record, or a customer response.
            throw new \RuntimeException('AI_EXPORT_CONTEXT_BUILD_FAILED',0,$error);
        }
        try {
            $task=$this->tasks->createAi($factoryContext,['page_code'=>M\MetricReadViewExportProvider::PAGE_CODE,'scope'=>'query',
                'fields'=>array_keys(M\MetricReadViewExportRegistrar::fields()),'includeSummary'=>false,
                'query'=>['visibleFields'=>array_keys(M\MetricReadViewExportRegistrar::fields()),'export'=>['scope'=>'query']]],$binding);
        } catch (\RuntimeException $e) {
            // This exception is raised under the Run lock BEFORE action/create executes.
            // No Task/queue/file operation exists, so only the file portion failed.
            if ($e->getMessage()!=='AI_EXPORT_CAPACITY_REJECTED') throw $e;
            $this->replay(); $this->assertCurrentVersions($binding);
            $answer=$this->runtime['private']->read($answerRef); $this->assertObjectOwner($answer,$binding);
            $answer['answer']['export_status']='failed';
            $ref=$this->runtime['private']->put('answer',$answer,$expiry);
            return $this->runtime['runs']->publish($owner,$run['run_id'],$run['generation'],$workerToken,$evidenceRef,$ref,'data_only_export_failed');
        } catch (\Throwable $error) {
            // createAi is a closed server contract.  Preserve only this
            // boundary code so the gateway can distinguish it from a model
            // or Reader failure without retaining business payloads.
            throw new \RuntimeException('AI_EXPORT_TASK_CREATE_FAILED',0,$error);
        }
        $taskNo=$task['taskId'];
        $handoff=$this->runtime['private']->put('answer',['owner'=>$owner,'run_id'=>$run['run_id'],'generation'=>$run['generation'],
            'export_task_no'=>$taskNo,'export_binding'=>$binding],$expiry);
        $waiting=$this->runtime['runs']->waitForExport($owner,$run['run_id'],$run['generation'],$workerToken,$handoff);
        try { if ($this->dispatch) call_user_func($this->dispatch,$taskNo); else AiExportQueue::push($this->runtime['instance'],$taskNo); }
        catch (\Throwable $e) { $this->tasks->cancelAiTask((string)$seed['tenant_id'],$taskNo); throw new \RuntimeException('AI_EXPORT_DISPATCH_UNKNOWN'); }
        return $waiting;
    }

    /** Start a file task from an already delivered answer; this never changes the answer Run. */
    public function queueCompleted(array $context,array $owner,array $run): array
    {
        if ($run['status']!=='COMPLETED' || !$this->ready($context)) throw new \RuntimeException('AI_EXPORT_NOT_READY');
        $evidence=$this->runtime['private']->read($run['evidence_ref']);
        if (($evidence['owner']??null)!==$owner || ($evidence['run_id']??null)!==$run['run_id']
            || !isset($evidence['query'],$evidence['view_ref'])) throw new \RuntimeException('AI_EXPORT_SOURCE_MISMATCH');
        // Both private objects must belong to this completed Run. The file
        // task may only reuse its immutable, already verified answer source.
        $this->assertObjectOwner($this->runtime['private']->read($run['answer_ref']),[
            'terminal'=>$owner['terminal'],'account_id'=>$owner['account_id'],'conversation_id'=>$owner['conversation_id'],
            'window_id'=>$owner['window_id'],'run_id'=>$run['run_id'],'generation'=>$run['generation']]);
        $now=time(); $expiry=intdiv($run['expires_at'],1000);
        $binding=['mode'=>'independent_v1','instance_fingerprint'=>$this->runtime['instance'],
            'terminal'=>$owner['terminal'],'account_id'=>$owner['account_id'],'conversation_id'=>$owner['conversation_id'],
            'window_id'=>$owner['window_id'],'run_id'=>$run['run_id'],'generation'=>$run['generation'],
            'worker_token'=>'','evidence_ref'=>$run['evidence_ref'],'canonical_result_ref'=>$run['answer_ref'],
            'read_consistency_ref'=>$evidence['view_ref'],'created_at'=>$now,'expires_at'=>$expiry,
            'source_expires_at'=>[$expiry],'execution_deadline_at'=>min($expiry,$now+180),
            'queue_deadline_ms'=>(int)floor(microtime(true)*1000)+$this->settings['queue_wait_budget_ms'],
            'result_hash'=>'','permission_hash'=>AiAuthority::permissionHash($context),
            'model_config_version'=>(string)$this->runtime['config']->read()['version']];
        foreach (['principal_kind','origin_store_id','origin_organization_id','employee_id','staff_id'] as $key)
            if (array_key_exists($key,$context)) $binding[$key]=$context[$key];
        $this->binding=$binding;
        $view=$this->readViews->replay([],$evidence['query'],$evidence['view_ref']);
        $binding['result_hash']=$view['result_hash'];
        $binding['expires_at']=min($expiry,(int)$view['expires_at']);
        $binding['source_expires_at']=[$expiry,(int)$view['expires_at']];
        $binding['execution_deadline_at']=min($binding['expires_at'],$now+180);
        $binding['signature']=$this->signature($binding); $this->binding=$binding;
        $this->validateBinding($binding,'create'); $this->assertCurrentVersions($binding);
        $seed=['source_type'=>'AI','account_id'=>$owner['account_id'],'operator_id'=>$owner['account_id'],
            'tenant_id'=>(string)($context['tenant_id']??0),
            'query_cutoff_date'=>date('Y-m-d',strtotime($view['data_as_of'])),'data_as_of'=>strtotime($view['data_as_of'])];
        $factoryContext=$this->creationContext($binding,$seed);
        $task=$this->tasks->createAi($factoryContext,['page_code'=>M\MetricReadViewExportProvider::PAGE_CODE,'scope'=>'query',
            'fields'=>array_keys(M\MetricReadViewExportRegistrar::fields()),'includeSummary'=>false,
            'query'=>['visibleFields'=>array_keys(M\MetricReadViewExportRegistrar::fields()),'export'=>['scope'=>'query']]],$binding);
        if ($task['status']==='pending') {
            // Duplicate queue messages are harmless: the shared Task lease
            // and source-bound worker accept a pending task only once.
            try { if ($this->dispatch) call_user_func($this->dispatch,$task['taskId']);
                else AiExportQueue::push($this->runtime['instance'],$task['taskId']); }
            catch (\Throwable $ignored) { /* Status polling can dispatch the same durable task again. */ }
        }
        return $this->statusCompleted($context,$owner,$run);
    }

    /** Expose only task progress for this signed answer; no query or business rows leave the server. */
    public function statusCompleted(array $context,array $owner,array $run): array
    {
        if ($run['status']!=='COMPLETED') throw new \RuntimeException('AI_EXPORT_NOT_PUBLISHED');
        $task=Db::name(Q\UnifiedQueryExportTaskServices::TABLE)->where('task_no',$this->taskNo([
            'instance_fingerprint'=>$this->runtime['instance'],'run_id'=>$run['run_id'],'generation'=>$run['generation']]))->find();
        if (!$task) return ['status'=>'not_requested'];
        $binding=Q\UnifiedQueryJson::decode((string)$task['ai_binding']);
        if (($binding['mode']??'')!=='independent_v1' || $this->owner($binding)!==$owner
            || ($context['account_id']??null)!==$owner['account_id']) throw new \RuntimeException('AI_EXPORT_OWNER_MISMATCH');
        $this->binding=$binding; $this->validateBinding($binding,'status');
        return ['status'=>$task['status'],'created_at'=>(int)$task['created_at'],
            'completed_at'=>(int)$task['completed_at'],'filename'=>(string)$task['file_name']];
    }

    /** File identity is stable for one verified answer and never includes customer text. */
    private function taskNo(array $binding): string
    { return 'uqe_'.substr(hash('sha256',$binding['instance_fingerprint'].'|'.$binding['run_id'].'|'.$binding['generation']),0,32); }

    public static function process(string $taskNo): bool
    {
        try { return (new self())->execute($taskNo); }
        catch (\Throwable $e) { return true; /* No raw error/ownership to generic queue logger; supervisor handles the deadline. */ }
    }

    private function execute(string $taskNo): bool
    {
        if (!$this->ready() || !preg_match('/^uqe_[a-f0-9]{32}$/D',$taskNo)) throw new \RuntimeException('AI_EXPORT_NOT_READY');
        // Fixed-size non-identifying lock namespace: no per-task filename or retention exception.
        $lock=$this->lock('task-shard-'.(hexdec(substr(hash('sha256',$taskNo),0,4))%4096));
        if (!$lock) return false; // Existing Job runner limits scheduling retries to 3; no Tool was sent.
        $slot=null; $binding=null; $newToken=null; $resumed=false; $trusted=false;
        try {
            $task=Db::name(Q\UnifiedQueryExportTaskServices::TABLE)->where('task_no',$taskNo)->find();
            if (!$task || ($task['source_type']??null)!=='AI') throw new \RuntimeException('EXPORT_PARTITION_MISMATCH');
            if ($task['status']!=='pending') return true; // Never recover unknown/running or duplicate completed jobs.
            $binding=Q\UnifiedQueryJson::decode($task['ai_binding']); $this->binding=$binding;
            $this->validateBinding($binding,'claim');
            $trusted=true;
            $queueExpired=(int)floor(microtime(true)*1000)>=$binding['queue_deadline_ms'];
            if (!$queueExpired) for ($i=0;$i<$this->settings['reserved_slots'];$i++) { $slot=$this->lock('slot-'.$i); if ($slot) break; }
            if ($queueExpired || !$slot) {
                // Holding the task shard and observing pending proves no file operation began.
                // Confirm cancellation before preserving the separately validated screen evidence.
                if (!$this->tasks->cancelAiTask((string)$task['tenant_id'],$taskNo)) throw new \RuntimeException('AI_EXPORT_CANCELLATION_UNKNOWN');
                $result=['status'=>'failed','exportFailureClass'=>'FILE_GENERATION_FAILED'];
            } else $result=$this->worker->processOne($taskNo);
            // A return from the synchronous shared Worker is actual process completion, not lease inference.
            $this->validateBinding($binding,'status');
            $view=$this->replay();
            if ($result['status']==='succeeded') $this->verifyFile($this->worker->absolutePath($result['storageKey']),$view);
            elseif (($result['exportFailureClass']??'')!=='FILE_GENERATION_FAILED') throw new \RuntimeException('AI_EXPORT_UNSAFE_FAILURE');
            // The independent task is already represented by the shared
            // export receipt.  Its completion never reopens the answer Run.
            if (($binding['mode']??'')==='independent_v1') return true;
            $newToken=bin2hex(random_bytes(24));
            $run=$this->runtime['runs']->resumeAfterExport($this->owner($binding),$binding['run_id'],$binding['generation'],$binding['worker_token'],$newToken,(int)config('mohe_ai.execution_slots',4));
            if ($run['status']!=='WORKFLOW_EXECUTING') return true;
            $resumed=true;
            $this->replay(); $this->assertCurrentVersions($binding);
            $answer=$this->runtime['private']->read($binding['canonical_result_ref']);
            $this->assertObjectOwner($answer,$binding);
            $answer['export_task_no']=$taskNo; $answer['export_binding']=$binding;
            $answer['answer']['export_status']=$result['status']==='succeeded'?'ready':'failed';
            $ref=$this->runtime['private']->put('answer',$answer,$binding['expires_at']);
            $this->runtime['runs']->publish($this->owner($binding),$binding['run_id'],$binding['generation'],$newToken,$binding['evidence_ref'],$ref,
                $result['status']==='succeeded'?'complete':'data_only_export_failed');
        } catch (\Throwable $e) {
            if ($trusted && $binding) {
                if (($binding['mode']??'')==='independent_v1') {
                    try {
                        $this->tasks->invalidateAiCompleted((string)$task['tenant_id'],$taskNo);
                        $this->tasks->cancelAiTask((string)$task['tenant_id'],$taskNo);
                    } catch (\Throwable $ignored) {}
                    return true;
                }
                $reason=preg_match('/^(AI_EXPORT_[A-Z_]+|METRIC_PERMISSION_[A-Z_]+|AI_AUTHORIZATION_CHANGED|AI_CAPABILITY_CHANGED)$/D',$e->getMessage())?$e->getMessage():'AI_EXPORT_UNSAFE_FAILURE';
                try { $this->runtime['runs']->fail($this->owner($binding),$binding['run_id'],$binding['generation'],$newToken??$binding['worker_token'],$reason); } catch (\Throwable $ignored) {}
                try { $this->tasks->cancelAiTask((string)($task['tenant_id']??''),$taskNo); } catch (\Throwable $ignored) {}
            }
            // No raw exception is propagated into the queue framework's generic logger.
        } finally {
            if ($resumed) { try { $this->runtime['runs']->release($this->owner($binding),$binding['run_id'],$binding['generation'],$newToken); } catch (\Throwable $ignored) {} }
            if ($slot) { flock($slot,LOCK_UN); fclose($slot); }
            flock($lock,LOCK_UN); fclose($lock);
        }
        return true;
    }

    public function download(array $context,array $owner,array $run): array
    {
        $answer=$this->runtime['private']->read($run['answer_ref']);
        $task=null; $binding=$answer['export_binding']??[];
        if (!$binding && $run['status']==='COMPLETED') {
            $task=Db::name(Q\UnifiedQueryExportTaskServices::TABLE)->where('task_no',$this->taskNo([
                'instance_fingerprint'=>$this->runtime['instance'],'run_id'=>$run['run_id'],'generation'=>$run['generation']]))->find();
            if (!$task || ($task['source_type']??null)!=='AI') throw new \RuntimeException('AI_EXPORT_NOT_READY');
            $binding=Q\UnifiedQueryJson::decode((string)$task['ai_binding']);
        }
        if ($owner!==$this->owner($binding) || ($context['account_id']??null)!==$owner['account_id'] || ($context['terminal']??null)!==$owner['terminal']) throw new \RuntimeException('AI_EXPORT_OWNER_MISMATCH');
        $this->binding=$binding; $this->validateBinding($binding,'download'); $view=$this->replay();
        $task=$task?:Db::name(Q\UnifiedQueryExportTaskServices::TABLE)->where('task_no',$answer['export_task_no'])->find();
        $queryContext=$this->resolver->resolve($task);
        $descriptor=$this->tasks->resolveDownloadDescriptor($queryContext,$task['task_no']);
        $this->verifyFile($this->worker->absolutePath($descriptor['storageKey']),$view);
        return $descriptor;
    }

    /** Owner control remains usable after report/entry revocation; it never returns business data. */
    public function cancel(array $context,array $owner,array $run): bool
    {
        if (($context['account_id']??null)!==$owner['account_id'] || ($context['terminal']??null)!==$owner['terminal']) throw new \RuntimeException('AI_EXPORT_OWNER_MISMATCH');
        if (empty($run['answer_ref'])) return false;
        $object=$this->runtime['private']->read($run['answer_ref']);
        if (!isset($object['export_binding'],$object['export_task_no'])) return false;
        $binding=$object['export_binding'];
        if ($this->owner($binding)!==$owner) throw new \RuntimeException('AI_EXPORT_OWNER_MISMATCH');
        $this->validateBinding($binding,'cancel');
        $task=Db::name(Q\UnifiedQueryExportTaskServices::TABLE)->where('task_no',$object['export_task_no'])->find();
        if (!$task || ($task['source_type']??null)!=='AI') return false;
        return $this->tasks->cancelAiTask((string)$task['tenant_id'],$object['export_task_no']);
    }

    /** Independent supervisor tick; no publication/retry or business payload logging. */
    public function cleanup(): array
    {
        if (!$this->tasks->hasSourceContract()) return ['schema_ready'=>false];
        $rows=Db::name(Q\UnifiedQueryExportTaskServices::TABLE)->where('source_type','AI')->whereIn('status',['pending','running'])->order('id','asc')->limit(200)->select()->toArray();
        $stopped=0;
        foreach ($rows as $task) {
            try {
                $binding=Q\UnifiedQueryJson::decode($task['ai_binding']); $run=$this->validateBinding($binding,'status');
                if ($task['status']==='pending' && ($binding['mode']??'')==='independent_v1'
                    && $binding['queue_deadline_ms']>(int)floor(microtime(true)*1000) && $this->ready()) {
                    // A lost queue message can be retried by supervision; the
                    // Task lease prevents duplicate file generation.
                    AiExportQueue::push($this->runtime['instance'],$task['task_no']); continue;
                }
                if ($task['status']==='pending' && $run['status']==='WAITING_EXPORT' && $binding['execution_deadline_at']>time()
                    && $binding['queue_deadline_ms']<=(int)floor(microtime(true)*1000) && $this->ready()) {
                    $this->execute($task['task_no']); continue; // Safe data-only continuation after confirmed not-started cancellation.
                }
                $expired=$binding['execution_deadline_at']<=time() || ($task['status']==='pending' && $binding['queue_deadline_ms']<=(int)floor(microtime(true)*1000));
                if (in_array($run['status'],['FAILED','CANCELLED'],true) || $expired) {
                    // File expiry must not turn a delivered answer into failure.
                    if ($expired && ($binding['mode']??'')!=='independent_v1') $this->runtime['runs']->fail($this->owner($binding),$binding['run_id'],$binding['generation'],$binding['worker_token'],'AI_EXPORT_DEADLINE');
                    if ($this->tasks->cancelAiTask((string)$task['tenant_id'],$task['task_no'])) ++$stopped;
                }
            } catch (\Throwable $ignored) { /* Invalid/expired ownership is never reconstructed; TTL erasure below owns it. */ }
        }
        $result=$this->worker->cleanupExpired(200); $result['stopped_tasks']=$stopped;
        if (!$this->dispatch) $result['queue']=AiExportQueue::cleanup($this->runtime['instance']);
        return $result;
    }

    private function creationContext(array $binding,array $seed): array
    {
        $definitions=(new \app\services\metric\MetricDictionaryServices())->getDefinitions();
        $pages=Q\UnifiedQueryPageRegistry::fromRegistrars($definitions,[new M\MetricReadViewExportRegistrar()]);
        $resolver=new AiExportWorkerContextResolver(new Q\UnifiedQueryContextFactory($pages),function()use($binding){return $binding;},$this->principalResolver);
        return $resolver->resolve($seed);
    }
    private function owner(array $binding): array
    { return ['account_id'=>$binding['account_id']??null,'terminal'=>$binding['terminal']??null,'conversation_id'=>$binding['conversation_id']??null,'window_id'=>$binding['window_id']??null]; }
    private function signature(array $binding): string
    { unset($binding['signature']); return hash_hmac('sha256',Q\UnifiedQueryJson::encode($binding),$this->runtime['private']->signingKey()); }
    private function validateBinding(array $binding,string $phase): array
    {
        if (!is_string($binding['signature']??null) || !hash_equals($this->signature($binding),$binding['signature'])
            || ($binding['instance_fingerprint']??null)!==$this->runtime['instance']) throw new \RuntimeException('AI_EXPORT_SIGNATURE_INVALID');
        $run=$this->runtime['runs']->get($this->owner($binding),$binding['run_id'],$binding['generation']);
        $independent=($binding['mode']??'')==='independent_v1';
        if ($binding['expires_at']>intdiv($run['expires_at'],1000) || $binding['expires_at']<=time()
            || (!$independent && $binding['execution_deadline_at']>intdiv($run['deadline_at'],1000))) throw new \RuntimeException('AI_EXPORT_EXPIRED');
        if ($independent && ($run['status']!=='COMPLETED' || $run['evidence_ref']!==$binding['evidence_ref']
            || $run['answer_ref']!==$binding['canonical_result_ref'])) throw new \RuntimeException('AI_EXPORT_TASK_BINDING_INVALID');
        if (!in_array($phase,['download','status','cancel'],true) && $binding['execution_deadline_at']<=time()) throw new \RuntimeException('AI_EXPORT_DEADLINE');
        $this->binding=$binding; $this->evidence($binding);
        if (!in_array($phase,['cancel','status'],true)) $this->assertCurrentVersions($binding);
        return $run;
    }
    private function current(): array { return $this->principalResolver ? call_user_func($this->principalResolver,$this->binding) : (new AiTrustedPrincipalResolver())->worker($this->binding); }
    private function assertCurrentVersions(array $binding): void
    {
        $current=$this->current();
        $snapshot=$this->runtime['runs']->snapshot($this->owner($binding),$binding['run_id'],$binding['generation']);
        if (AiAuthority::permissionHash($current)!==$binding['permission_hash'] || $snapshot['authorization_version']!==$binding['permission_hash']
            || $snapshot['model_config_version']!==$binding['model_config_version'] || (string)$this->runtime['config']->read()['version']!==$binding['model_config_version']) throw new \RuntimeException('AI_AUTHORIZATION_CHANGED');
        $current['analysis_personnel_ready']=\app\services\ai\config\AiConfigStore::allowsSanitizedQuestion($this->runtime['config']->read());
        if (!$this->ready($current) || !hash_equals($snapshot['capability_snapshot_hash'],AiAuthority::capabilityHash(true,!isset($snapshot['guidance_schema_version']),$current))) throw new \RuntimeException('AI_CAPABILITY_CHANGED');
    }
    private function assertObjectOwner(array $object,array $binding): void
    { if (($object['owner']??null)!=$this->owner($binding) || ($object['run_id']??null)!==$binding['run_id'] || ($object['generation']??null)!==$binding['generation']) throw new \RuntimeException('AI_EVIDENCE_BINDING_INVALID'); }
    private function evidence(array $binding): array
    {
        $evidence=$this->runtime['private']->read($binding['evidence_ref']); $this->assertObjectOwner($evidence,$binding);
        if (($evidence['view_ref']??null)!==$binding['read_consistency_ref']) throw new \RuntimeException('AI_EXPORT_SOURCE_MISMATCH');
        return $evidence;
    }
    private function replay(): array
    {
        $view=$this->readViews->replay([],$this->evidence($this->binding)['query'],$this->binding['read_consistency_ref']);
        if ($view['result_hash']!==$this->binding['result_hash'] || $view['expires_at']<$this->binding['expires_at']) throw new \RuntimeException('AI_EXPORT_SOURCE_MISMATCH');
        return $view;
    }
    private function lock(string $name)
    {
        $directory=$this->lockDirectory();
        if (is_link($directory)) throw new \RuntimeException('AI_EXPORT_LOCK_INVALID');
        if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) throw new \RuntimeException('AI_EXPORT_LOCK_UNAVAILABLE');
        $path=$directory.'/'.$name;
        if (is_link($path)) throw new \RuntimeException('AI_EXPORT_LOCK_INVALID');
        $handle=fopen($path,'c+b'); if (!$handle) throw new \RuntimeException('AI_EXPORT_LOCK_UNAVAILABLE');
        if (!flock($handle,LOCK_EX|LOCK_NB)) { fclose($handle); return null; }
        return $handle;
    }
    private function lockDirectory(): string
    { return rtrim(app()->getRuntimePath(),DIRECTORY_SEPARATOR).'/private/mohe-ai-export-locks/'.hash('sha256',$this->runtime['instance']); }
    private function verifyFile(string $path,array $view): void
    {
        $book=\PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        try {
            $sheet=$book->getActiveSheet(); $expected=M\MetricReadViewExportProvider::project($view); $fields=M\MetricReadViewExportRegistrar::fields();
            if ($sheet->getHighestDataRow()!==count($expected)+1 || $sheet->getHighestDataColumn()!==\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($fields))) throw new \RuntimeException('AI_EXPORT_CONTENT_MISMATCH');
            $column=1; foreach ($fields as $key=>$label) {
                $letter=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column++);
                if ($sheet->getCell($letter.'1')->getValue()!==$label) throw new \RuntimeException('AI_EXPORT_CONTENT_MISMATCH');
                foreach ($expected as $index=>$row) {
                    $cell=$sheet->getCell($letter.($index+2)); $actual=$cell->getValue(); $wanted=$row[$key];
                    $numericValue = $key === 'metric_value' && !is_string($actual);
                    $sameValue = $numericValue
                        ? (($row['unit'] ?? null) === '元'
                            ? number_format((float)$actual, 2, '.', '') === $wanted
                            : (string)(int)$actual === (string)$wanted)
                        : (string)$actual === (string)$wanted;
                    if ($cell->getDataType()==='f' || !$sameValue) throw new \RuntimeException('AI_EXPORT_CONTENT_MISMATCH');
                }
            }
        } finally { $book->disconnectWorksheets(); }
    }
}
