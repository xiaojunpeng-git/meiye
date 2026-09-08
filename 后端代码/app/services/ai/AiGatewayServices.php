<?php
namespace app\services\ai;

use app\services\ai\config\AiConfigStore;
use app\services\ai\config\AiPrivateStorage;
use app\services\ai\execution\AiRunStore;
use app\services\ai\execution\AiWorkflowPlanner;
use app\services\ai\model\AiModelInputProjector;
use app\services\ai\model\SiliconFlowClient;
use app\services\query\metric\MetricReadViewServices;
use app\services\query\metric\MetricReadViewStore;
use RuntimeException;

/** Conversation bodies exist only in the HTTP execution stack. No body is queued or logged. */
final class AiGatewayServices
{
    private $runs; private $config; private $private; private $instance; private $views; private $model; private $queryTransaction; private $exports;

    /** Optional injected dependencies are for an isolated integration environment, never request parameters. */
    public function __construct(?AiRunStore $runs = null, ?AiConfigStore $config = null, ?AiPrivateStorage $private = null,
        string $instance = '', ?MetricReadViewStore $views = null, ?callable $model = null, ?callable $queryTransaction = null)
    {
        $this->runs=$runs; $this->config=$config; $this->private=$private; $this->instance=$instance; $this->views=$views; $this->model=$model; $this->queryTransaction=$queryTransaction;
    }

    private function initialize(): void
    {
        if ($this->runs) return;
        $runtime=\app\services\ai\execution\AiRuntimeFactory::make();
        foreach (['instance','private','runs','config','views'] as $key) $this->$key=$runtime[$key];
    }

    /** Trusted CLI supervision only. Counts contain no account, Run ID or conversation data. */
    public function supervise(): array
    {
        $this->initialize();
        $monitor=$this->monitor();
        try {
            $state=$this->runs->cleanup();
            $exports=$this->exportRuntime()->cleanup();
            $result=['runtime'=>$state,'exports'=>$exports,'private_objects_removed'=>$this->private->cleanup(),'read_views_removed'=>$this->views->cleanup()];
            $monitor->recordCleanup(empty($exports['retry']));
            return $result;
        } catch (\Throwable $error) {
            try { $monitor->recordCleanup(false); } catch (\Throwable $ignored) {}
            throw $error;
        }
    }

    public function handle(string $operation,array $context,array $input,string $runId='')
    {
        $schemas=[
            'bootstrap'=>['client_session_id'],
            'create'=>['client_request_id','conversation_id','client_session_id','window_token','question','history','output_format'],
            'execute'=>['client_request_id','conversation_id','client_session_id','window_token','question','history','output_format','generation','run_delivery_token'],
            'status'=>['client_session_id','generation','run_delivery_token'], 'cancel'=>['client_session_id','generation','run_delivery_token'],
            'export'=>['client_session_id','generation','run_delivery_token'],
            'clarify'=>['client_session_id','generation','run_delivery_token','clarification_id','choices'],
            'config_get'=>[], 'config_save'=>['version','enabled','model','api_key','external_processing_authorized'], 'config_check'=>['confirm_cost'],
        ];
        if (!isset($schemas[$operation]) || array_diff(array_keys($input),$schemas[$operation])) throw new RuntimeException('AI_INPUT_SCHEMA_INVALID');
        $this->initialize();
        if (!in_array($context['terminal']??'', ['platform','store','merchant'],true) || (int)($context['account_id']??0)<1) throw new RuntimeException('AI_AUTH_REQUIRED');
        if (strpos($operation,'config_')===0) {
            if (empty($context['can_configure'])) throw new RuntimeException('AI_PERMISSION_DENIED');
            if ($operation==='config_get') {
                $diagnostics=$this->runs->diagnostics();
                $diagnostics['monitoring']=$this->monitor()->evaluate($diagnostics);
                return array_merge($this->config->read(),['runtime_status'=>$diagnostics]);
            }
            if ($operation==='config_save') return $this->config->save($input);
            if ($operation==='config_check') return $this->checkConfig($input);
        }
        $configuration=$this->config->read();
        if ($operation==='bootstrap') {
            $session=$this->identifier($input['client_session_id']??null);
            return ['enabled'=>!empty($context['can_use']) && $configuration['enabled'], 'configured'=>$configuration['has_api_key'],
                'identity_key'=>$this->identity($context),'window_token'=>$this->token(['type'=>'window','identity'=>$this->identity($context),'window'=>$session,'expires'=>time()+86400]),
                'server_time'=>time()*1000,'retention_seconds'=>86400,'history_round_limit'=>20,'can_configure'=>!empty($context['can_configure']),
                'capabilities'=>$this->capabilities($context), 'disabled_reason'=>$configuration['enabled']?'':'AI_NOT_CONFIGURED'];
        }
        // Revocation must never prevent this authenticated owner from stopping their old task.
        if ($operation!=='cancel' && empty($context['can_use'])) throw new RuntimeException('AI_PERMISSION_DENIED');
        if ($operation==='create') {
            if (!$configuration['enabled'] || !$configuration['external_processing_authorized']) throw new RuntimeException('AI_NOT_CONFIGURED');
            $session=$this->identifier($input['client_session_id']??null);
            $this->verifyToken($input['window_token']??'', $context,$session,'window');
            $owner=['account_id'=>(int)$context['account_id'],'terminal'=>$context['terminal'],'conversation_id'=>$this->identifier($input['conversation_id']??null),'window_id'=>$session];
            $body=$this->conversation($input);
            $limits=$this->limits();
            $snapshot=['capability_snapshot_ref'=>'metric-v1','capability_snapshot_hash'=>hash('sha256',json_encode($this->capabilities($context))),
                'budget_profile_version'=>$limits['run_budget_ms'].'-v1','authorization_version'=>$this->permissionHash($context),'model_config_version'=>(string)$configuration['version']];
            $created=$this->runs->create($owner,$this->identifier($input['client_request_id']??null),$this->bodyHash($body),$snapshot,$limits['run_budget_ms'],$limits['execution_slots'],$limits['active_run_limit']);
            if (!$created['accepted']) return ['accepted'=>false,'reason'=>$created['reason'],'message'=>[
                'RATE_LIMITED'=>'您近期提问较频繁，请稍后再问。',
                'CIRCUIT_OPEN'=>'近期请求连续未能完成，已暂停新请求两分钟，请稍后重试。',
                'OTHER_CONVERSATION_ACTIVE'=>'您还有一个对话正在执行，请先完成或停止该任务。',
            ][$created['reason']]??'当前使用人数较多，请稍后再问。'];
            return $this->present($context,$owner,$created['run']);
        }
        $session=$this->identifier($input['client_session_id']??null);
        $proof=$this->verifyToken($input['run_delivery_token']??'',$context,$session,'run');
        $generation=filter_var($input['generation']??null,FILTER_VALIDATE_INT);
        if ($generation===false || $generation<1 || ($proof['run']??'')!==$runId || ($proof['generation']??0)!==$generation) throw new RuntimeException('AI_DELIVERY_INVALID');
        $owner=['account_id'=>(int)$context['account_id'],'terminal'=>$context['terminal'],'conversation_id'=>$proof['conversation'],'window_id'=>$session];
        if ($operation==='cancel') {
            $cancelled=$this->runs->cancel($owner,$runId,$generation);
            if ($cancelled['status']==='CANCELLED' && !empty($cancelled['answer_ref'])) {
                // Logical cancellation is already committed; cleanup also retries this notification.
                try { $this->exportRuntime()->cancel($context,$owner,$cancelled); } catch (\Throwable $ignored) {}
            }
            return $this->present($context,$owner,$cancelled);
        }
        if ($operation==='status') return $this->present($context,$owner,$this->runs->get($owner,$runId,$generation));
        if ($operation==='export') {
            $descriptor=$this->exportRuntime()->download($context,$owner,$this->runs->get($owner,$runId,$generation));
            $path=(new \app\services\query\UnifiedQueryExportStorage())->absolutePath($descriptor['storageKey']);
            return download($path,$descriptor['fileName'])->header(['Cache-Control'=>'no-store','X-Content-Type-Options'=>'nosniff']);
        }
        if (!in_array($operation,['execute','clarify'],true)) throw new RuntimeException('AI_OPERATION_INVALID');
        return $this->execute($operation,$context,$owner,$runId,$generation,$input);
    }

    private function execute(string $operation,array $context,array $owner,string $id,int $generation,array $input): array
    {
        $worker=bin2hex(random_bytes(24)); $claimed=false; $paused=false;
        try {
            if ($operation==='execute') {
                $body=$this->conversation($input); $this->runs->assertRequest($owner,$id,$generation,$this->bodyHash($body));
                $current=$this->runs->get($owner,$id,$generation);
                if (in_array($current['status'],['COMPLETED','PARTIAL_SUCCEEDED','CANCELLED','FAILED','WAITING_CLARIFICATION','WAITING_EXPORT'],true)) return $this->present($context,$owner,$current);
                if (!$this->runs->claim($owner,$id,$generation,$worker)) return $this->present($context,$owner,$this->runs->get($owner,$id,$generation));
                $claimed=true;
            }
            $snapshot=$this->runs->snapshot($owner,$id,$generation);
            if (!hash_equals($snapshot['capability_snapshot_hash'],hash('sha256',json_encode($this->capabilities($context))))) throw new RuntimeException('AI_CAPABILITY_CHANGED');
            $configuration=$this->config->read(true);
            if (!$configuration['enabled'] || !$configuration['external_processing_authorized'] || (string)$configuration['version']!==$snapshot['model_config_version']
                || $this->permissionHash($this->fresh($context))!==$snapshot['authorization_version']) throw new RuntimeException('AI_AUTHORIZATION_CHANGED');
            if ($operation==='execute') {
                $this->runs->progress($owner,$id,$generation,$worker,'UNDERSTANDING');
                $projector=new AiModelInputProjector(); $view=$projector->modelView($body);
                // Refuse unknown residual conditions BEFORE an external request, rather than silently deleting them.
                if ($view['current']['unresolved_condition']) throw new RuntimeException('AI_UNSUPPORTED_CONDITION');
                $this->runs->reserve($owner,$id,$generation,$worker,'stage_count');
                $this->runs->reserve($owner,$id,$generation,$worker,'input_tokens',max(1,strlen(json_encode($view))+2048));
                $this->runs->reserve($owner,$id,$generation,$worker,'output_tokens',1200);
                $this->runs->prepareAttempt($owner,$id,$generation,$worker,'understand','model',hash('sha256',json_encode($view)),'siliconflow');
                $this->runs->sendAttempt($owner,$id,$generation,$worker,'understand');
                try {
                    $checkpoint=function () use($owner,$id,$generation,$worker):void { $this->runs->checkpoint($owner,$id,$generation,$worker); };
                    $reply=$this->model ? call_user_func($this->model,$view,$this->capabilities($context)['metric_codes'],$configuration,$checkpoint)
                        : (new SiliconFlowClient())->select($view,$this->capabilities($context)['metric_codes'],$configuration['model'],$configuration['api_key'],20000,$checkpoint);
                    $this->runs->finishAttempt($owner,$id,$generation,$worker,'understand','SUCCEEDED',$reply['usage']['input_tokens']??null,$reply['usage']['output_tokens']??null);
                } catch (\Throwable $e) {
                    $unknown=in_array($e->getMessage(),['AI_MODEL_RESULT_UNKNOWN','AI_CANCELLED'],true);
                    $this->runs->finishAttempt($owner,$id,$generation,$worker,'understand',$unknown?'UNKNOWN':'FAILED'); throw $e;
                }
                $capabilities=$this->capabilities($context);
                $capabilities['current_store_bound']=$context['terminal']==='store' && count($context['store_ids'])===1;
                $createdAt=$this->runs->get($owner,$id,$generation)['created_at'];
                $today=(new \DateTimeImmutable('@'.intdiv($createdAt,1000)))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
                $compiled=(new AiWorkflowPlanner())->compile($view['current'],$reply['selection'],$capabilities,$body['output_format'],$today);
                if ($compiled['kind']==='clarification') {
                    $expires=min(time()+600,intdiv($this->runs->get($owner,$id,$generation)['expires_at'],1000));
                    $ref=$this->private->put('clarification',['owner'=>$owner,'run_id'=>$id,'generation'=>$generation,'envelope'=>$compiled],$expires);
                    $r=$this->runs->pauseForClarification($owner,$id,$generation,$worker,$ref); $paused=true;
                    return $this->present($context,$owner,$r);
                }
            } else {
                $r=$this->runs->get($owner,$id,$generation);
                if ($r['status']!=='WAITING_CLARIFICATION' || ($input['clarification_id']??'')!==$r['clarification_ref']) throw new RuntimeException('AI_CLARIFICATION_INVALID');
                $stored=$this->private->read($r['clarification_ref']); $this->assertBinding($stored,$owner,$id,$generation);
                $compiled=(new AiWorkflowPlanner())->choose($stored['envelope'],is_array($input['choices']??null)?$input['choices']:[]);
                $r=$this->runs->resume($owner,$id,$generation,$worker,$this->limits()['execution_slots']); $claimed=$r['status']==='WORKFLOW_EXECUTING';
                if (!$claimed) return $this->present($context,$owner,$r);
            }
            $plan=$compiled['plan'];
            $this->runs->reserve($owner,$id,$generation,$worker,'skill_execution_count');
            $this->runs->reserve($owner,$id,$generation,$worker,'node_visit_count',3);
            $this->runs->reserve($owner,$id,$generation,$worker,'workflow_transition_count',3);
            $this->runs->progress($owner,$id,$generation,$worker,'QUERYING');
            $this->runs->prepareAttempt($owner,$id,$generation,$worker,'query','tool',$plan['compiled_run_hash'],'unified_metric_query');
            $this->runs->sendAttempt($owner,$id,$generation,$worker,'query');
            try {
                $queryService=$this->queryService($context,function () use($owner,$id,$generation,$worker):void { $this->runs->checkpoint($owner,$id,$generation,$worker); });
                $evidence=$queryService->create([],$plan['query'],intdiv($this->runs->get($owner,$id,$generation)['expires_at'],1000));
                $this->runs->finishAttempt($owner,$id,$generation,$worker,'query','SUCCEEDED');
            } catch (\Throwable $e) { $this->runs->finishAttempt($owner,$id,$generation,$worker,'query','FAILED'); throw $e; }
            $this->runs->checkpoint($owner,$id,$generation,$worker);
            $this->runs->progress($owner,$id,$generation,$worker,'VERIFYING');
            $expected=count($plan['query']['metric_codes'])*($plan['query']['compare_range']===null?1:2);
            if (empty($evidence['ai_query_ready']) || $evidence['result_status']!=='complete' || count($evidence['results'])!==$expected) throw new RuntimeException('AI_EVIDENCE_INCOMPLETE');
            $answer=$this->answer($evidence);
            // Revalidate BOTH identity/report scope and model configuration before atomically making references visible.
            $queryService->replay([],$plan['query'],$evidence['read_consistency_ref']);
            if ($this->permissionHash($this->fresh($context))!==$snapshot['authorization_version'] || (string)$this->config->read()['version']!==$snapshot['model_config_version']) throw new RuntimeException('AI_AUTHORIZATION_CHANGED');
            $this->runs->progress($owner,$id,$generation,$worker,'RENDERING');
            $expires=min($evidence['expires_at'],intdiv($this->runs->get($owner,$id,$generation)['expires_at'],1000));
            $binding=['owner'=>$owner,'run_id'=>$id,'generation'=>$generation];
            $evidenceRef=$this->private->put('evidence',$binding+['view_ref'=>$evidence['read_consistency_ref'],'query'=>$plan['query'],'compiled_run_hash'=>$plan['compiled_run_hash']],$expires);
            $answerRef=$this->private->put('answer',$binding+['answer'=>$answer],$expires);
            if ($plan['output_format']==='screen_and_xlsx') {
                $waiting=$this->exportRuntime()->queue($this->fresh($context),$owner,$this->runs->get($owner,$id,$generation),$worker,$evidenceRef,$answerRef,$evidence);
                $paused=$waiting['status']==='WAITING_EXPORT';
                return $this->present($context,$owner,$waiting);
            }
            return $this->present($context,$owner,$this->runs->publish($owner,$id,$generation,$worker,$evidenceRef,$answerRef));
        } catch (\Throwable $e) {
            if (!$claimed) throw $e;
            $reason=preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D',$e->getMessage())?$e->getMessage():'AI_EXECUTION_FAILED';
            return $this->present($context,$owner,$this->runs->fail($owner,$id,$generation,$worker,$reason));
        } finally {
            if ($claimed && !$paused) $this->runs->release($owner,$id,$generation,$worker);
        }
    }

    private function queryService(array $context,?callable $checkpoint=null): MetricReadViewServices
    {
        return new MetricReadViewServices($this->views,function () use($context): array {
            $c=$this->fresh($context);
            if (empty($c['can_use'])) throw new RuntimeException('AI_PERMISSION_DENIED');
            return ['instance_id'=>$this->instance,'subject_ref'=>$this->identity($c),'terminal'=>$c['terminal'],'tenant_id'=>(string)($c['tenant_id']??0),
                'permission_version'=>$this->permissionHash($c),'report_capability_code'=>$c['report_capability_code'],'scope_provider_code'=>'current_report_scope_v1',
                'scope_mode'=>$c['scope_mode'],'store_ids'=>$c['store_ids']];
        },$this->queryTransaction ?: [new \app\services\query\metric\MetricReadTransaction(20000,$checkpoint),'run']);
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
        return \app\services\ai\execution\AiAuthority::capabilities($exportReady);
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
        foreach (['run_budget_ms'=>180000,'execution_slots'=>4,'active_run_limit'=>8] as $key=>$default) $values[$key]=function_exists('config')?config('mohe_ai.'.$key,$default):$default;
        foreach ($values as $value) if (!is_int($value)) throw new RuntimeException('AI_CAPACITY_PROFILE_INVALID');
        if ($values['run_budget_ms']<180000||$values['run_budget_ms']>300000||$values['execution_slots']<1||$values['execution_slots']>64||$values['active_run_limit']<1||$values['active_run_limit']>128) throw new RuntimeException('AI_CAPACITY_PROFILE_INVALID');
        return $values;
    }
    private function conversation(array $input): array
    {
        $body=(new AiModelInputProjector())->validateConversation($input['question']??null,$input['history']??null);
        $format=$input['output_format']??'screen'; if (!in_array($format,['screen','screen_and_xlsx'],true)) throw new RuntimeException('AI_OUTPUT_FORMAT_INVALID');
        $body['output_format']=$format; return $body;
    }
    private function bodyHash(array $body): string { return hash_hmac('sha256',json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$this->private->signingKey()); }
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
    private function present(array $context,array $owner,array $run): array
    {
        $result=['run_id'=>$run['run_id'],'generation'=>$run['generation'],'status'=>$run['status'],'reason'=>$run['reason'],
            'progress'=>$this->progressText($run),'message'=>$this->progressText($run),'run_delivery_token'=>$this->token(['type'=>'run','identity'=>$this->identity($context),'window'=>$owner['window_id'],
                'conversation'=>$owner['conversation_id'],'run'=>$run['run_id'],'generation'=>$run['generation'],'expires'=>intdiv($run['expires_at'],1000)])];
        if ($run['status']==='WAITING_CLARIFICATION') {
            $stored=$this->private->read($run['clarification_ref']); $this->assertBinding($stored,$owner,$run['run_id'],$run['generation']);
            $result['clarification']=['id'=>$run['clarification_ref'],'question'=>'请一次选清查询条件','fields'=>$stored['envelope']['fields']];
        }
        if (in_array($run['status'],['COMPLETED','PARTIAL_SUCCEEDED'],true)) {
            $stored=$this->private->read($run['evidence_ref']); $this->assertBinding($stored,$owner,$run['run_id'],$run['generation']);
            $this->queryService($context)->replay([],$stored['query'],$stored['view_ref']);
            $answer=$this->private->read($run['answer_ref']); $this->assertBinding($answer,$owner,$run['run_id'],$run['generation']);
            $result['answer']=$answer['answer'];
            if ($run['status']==='COMPLETED' && ($answer['answer']['export_status']??null)==='ready' && !empty($answer['export_task_no'])) {
                $result['answer']['export']=['file_ref'=>$answer['export_task_no'],'filename'=>'经营数据.xlsx'];
            }
            if ($run['status']==='PARTIAL_SUCCEEDED') {
                unset($result['answer']['export']);
                $result['answer']['summary'].=' 数据已核对，但 Excel 文件生成失败。';
            }
        }
        return $result;
    }
    private function assertBinding(array $stored,array $owner,string $id,int $generation): void
    {
        if (($stored['owner']??null)!=$owner || ($stored['run_id']??'')!==$id || ($stored['generation']??0)!==$generation) throw new RuntimeException('AI_EVIDENCE_BINDING_INVALID');
    }
    private function answer(array $view): array
    {
        $dictionary=new \app\services\metric\MetricDictionaryServices();
        $cards=[]; $rows=[]; $shape=$view['query']['query_shape'];
        foreach ($view['results'] as $row) {
            if (!in_array($row['metric_code']??'',['cash_performance','consume_amount'],true)||!in_array($row['period']??'',['current','comparison'],true)) throw new RuntimeException('AI_EVIDENCE_INVALID');
            $tooltip=$dictionary->getTooltip($row['metric_code']);
            if (empty($tooltip['user_ready'])) throw new RuntimeException('AI_METRIC_EXPLANATION_NOT_READY');
            if ($shape==='trend') {
                foreach ($row['rows'] as $point) $rows[]=['label'=>$point['business_date'],'metric'=>$tooltip['name'],'value'=>$this->amount($point['amount_cents'])];
                continue;
            }
            if ($shape==='ranking') {
                foreach ($row['rows'] as $direction=>$points) foreach ($points as $index=>$point) $rows[]=['label'=>$point['store_name']??('门店 ID '.$point['store_id']),
                    'metric'=>$tooltip['name'],'rank'=>($direction==='top'?'前':'后').($index+1),'value'=>$this->amount($point['amount_cents'])];
                continue;
            }
            $display=$this->amount($row['amount_cents']??null);
            $range=$row['period']==='current'?['start'=>$view['query']['start_date'],'end'=>$view['query']['end_date']]:$view['query']['compare_range'];
            $cards[]=['metric_name'=>$tooltip['name'],'display_value'=>$display,'unit'=>'元','tooltip'=>$tooltip,
                'period_label'=>($row['period']==='current'?'查询期间：':'对比期间：').$range['start'].' 至 '.$range['end'],
                'start_date'=>$range['start'],'end_date'=>$range['end'],'data_as_of'=>$view['data_as_of']];
        }
        $answer=['summary'=>'已按您当前报表的数据范围查询。统计时间：'.$view['query']['start_date'].' 至 '.$view['query']['end_date'].'。','cards'=>$cards];
        if ($view['query']['compare_range']) $answer['summary'].='对比时间：'.$view['query']['compare_range']['start'].' 至 '.$view['query']['compare_range']['end'].'。';
        if ($rows) {
            $columns=[['key'=>'label','label'=>$shape==='trend'?'日期':'门店'],['key'=>'metric','label'=>'指标'],['key'=>'value','label'=>'金额（元）']];
            if ($shape==='ranking') $columns[]=['key'=>'rank','label'=>'名次'];
            $answer['table']=['columns'=>$columns,'rows'=>$rows];
        }
        return $answer;
    }
    private function amount($cents): string
    {
        return \app\services\query\metric\MetricMoneyFormatter::integerYuan($cents);
    }
    private function progressText(array $run): string
    {
        if ($run['status']==='CANCELLED') return '已取消';
        if ($run['status']==='COMPLETED') return '查询完成';
        if ($run['status']==='PARTIAL_SUCCEEDED') return '数据已核对，文件未生成';
        if ($run['status']==='WAITING_EXPORT') return '正在生成 Excel 文件';
        if ($run['status']==='FAILED') return [
            'AI_UNSUPPORTED_CONDITION'=>'当前尚不支持这个指标或筛选组合，未删减您的条件。请分开提问或调整条件。',
            'AI_QUERY_SHAPE_NOT_READY'=>'当前暂不支持这种分析方式，请调整问题。',
            'AI_METRIC_NOT_READY'=>'该指标尚未通过统一报表口径核验，暂不能查询。',
            'AI_EXPORT_NOT_READY'=>'当前 Excel 能力尚未启用，本次未生成文件或发布数字。',
            'AI_MODEL_ACCOUNT_UNAVAILABLE'=>'客户 AI 账号暂不可用，请联系管理员检查 AI 配置。',
            'AI_MODEL_RESULT_UNKNOWN'=>'本次 AI 请求结果暂未确认，已停止继续调用，请稍后再问。',
            'AI_AUTHORIZATION_CHANGED'=>'您的权限或 AI 配置已变化，本次查询已停止，请重新提问。',
            'METRIC_QUERY_COVERAGE_UNAVAILABLE'=>'该时段的数据尚未通过核验，请选择其他日期。',
            'CAPACITY_STOPPED'=>'当前系统繁忙，本次任务已停止，请稍后再问。',
            'AI_RUN_DEADLINE'=>'本次查询已到时间上限，请缩小问题范围后重试。',
        ][$run['reason']]??'本次查询未完成，请稍后重试。';
        return ['RECEIVED'=>'已接收问题','UNDERSTANDING'=>'正在理解查询条件','QUERYING'=>'正在查询经营数据','VERIFYING'=>'正在核对数据','RENDERING'=>'正在整理结果','EXPORTING'=>'正在生成文件'][$run['progress_code']]??'正在处理';
    }
    private function checkConfig(array $input): array
    {
        if (($input['confirm_cost']??false)!==true) throw new RuntimeException('AI_ACTIVE_TEST_CONFIRM_REQUIRED');
        $configuration=$this->config->read(true);
        if (!$configuration['external_processing_authorized']) throw new RuntimeException('AI_EXTERNAL_AUTHORIZATION_REQUIRED');
        $deadline=microtime(true)+10;
        $probe=$this->config->beginProbe((int)$configuration['version']);
        try {
            $reply=(new SiliconFlowClient())->select(['current'=>['signals'=>[],'dates'=>[],'unresolved_condition'=>false],'history'=>[]],[],
                $configuration['model'],$configuration['api_key'],10000,static function () use($deadline):void { if (microtime(true)>$deadline) throw new RuntimeException('AI_CHECK_TIMEOUT'); });
            $this->config->finishProbe($probe,'SUCCEEDED',$reply['usage']['input_tokens']??null,$reply['usage']['output_tokens']??null);
        } catch (\Throwable $error) {
            // Unknown wire outcomes retain unknown usage; do not resend or expose provider details.
            $this->config->finishProbe($probe,in_array($error->getMessage(),['AI_MODEL_RESULT_UNKNOWN','AI_CANCELLED','AI_CHECK_TIMEOUT'],true)?'UNKNOWN':'FAILED');
            throw $error;
        }
        return ['status'=>'available','message'=>'本次连接与结构化响应测试通过，不代表账户余额充足。'];
    }
}
