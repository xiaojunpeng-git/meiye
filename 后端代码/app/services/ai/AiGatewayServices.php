<?php
namespace app\services\ai;

use app\services\ai\config\AiConfigStore;
use app\services\ai\config\AiPrivateStorage;
use app\services\ai\execution\AiRunStore;
use app\services\ai\execution\AiWorkflowPlanner;
use app\services\ai\execution\AiRegisteredPlanCompiler;
use app\services\ai\execution\AiRegisteredWorkflowExecutor;
use app\services\ai\registry\AiBusinessRegistry;
use app\services\ai\model\AiModelInputProjector;
use app\services\ai\model\SiliconFlowClient;
use app\services\query\metric\MetricReadViewServices;
use app\services\query\metric\MetricReadViewStore;
use RuntimeException;

/** Conversation bodies exist only in the HTTP execution stack. No body is queued or logged. */
final class AiGatewayServices
{
    private $runs; private $config; private $private; private $instance; private $views; private $model; private $queryTransaction; private $exports;
    private $management; private $managementDocument; private $managementRevision='source';

    /** Optional injected dependencies are for an isolated integration environment, never request parameters. */
    public function __construct(?AiRunStore $runs = null, ?AiConfigStore $config = null, ?AiPrivateStorage $private = null,
        string $instance = '', ?MetricReadViewStore $views = null, ?callable $model = null, ?callable $queryTransaction = null, $management = null)
    {
        $this->runs=$runs; $this->config=$config; $this->private=$private; $this->instance=$instance; $this->views=$views; $this->model=$model; $this->queryTransaction=$queryTransaction;
        $this->management=$management;
    }

    private function initialize(): void
    {
        if ($this->runs) return;
        $runtime=\app\services\ai\execution\AiRuntimeFactory::make();
        foreach (['instance','private','runs','config','views','management'] as $key) $this->$key=$runtime[$key];
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
            'create'=>['client_request_id','conversation_id','client_session_id','window_token','question','history','output_format','guidance_schema_version','context_ref'],
            'execute'=>['client_request_id','conversation_id','client_session_id','window_token','question','history','output_format','generation','run_delivery_token','guidance_schema_version','context_ref'],
            'status'=>['client_session_id','generation','run_delivery_token'], 'cancel'=>['client_session_id','generation','run_delivery_token'],
            'export'=>['client_session_id','generation','run_delivery_token'],
            'clarify'=>['client_session_id','generation','run_delivery_token','clarification_id','choices','schema_version','step_revision','intent_revision','client_submission_id','revise_clarification_id'],
            'config_get'=>[], 'config_save'=>['version','enabled','model','api_key','external_processing_authorized','external_scope_version'], 'config_check'=>['confirm_cost'],
            'management_get'=>[], 'management_save'=>['expected_revision','document'], 'management_validate'=>['expected_revision'],
            'management_publish'=>['expected_revision'], 'management_rollback'=>['expected_revision','target_version'],
            'management_preview'=>['expected_revision','question'], 'management_rebase'=>['expected_revision'],
            'metric_registry_get'=>[],
        ];
        if (!isset($schemas[$operation]) || array_diff(array_keys($input),$schemas[$operation])) throw new RuntimeException('AI_INPUT_SCHEMA_INVALID');
        $this->initialize();
        if (!in_array($context['terminal']??'', ['platform','store','merchant'],true) || (int)($context['account_id']??0)<1) throw new RuntimeException('AI_AUTH_REQUIRED');
        if (strpos($operation,'management_')===0) return $this->manage($operation,$context,$input);
        if ($operation==='metric_registry_get') return $this->metricRegistry($context);
        if (strpos($operation,'config_')===0) {
            if ($context['terminal']!=='platform' || empty($this->fresh($context)['can_configure'])) throw new RuntimeException('AI_PERMISSION_DENIED');
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
            $this->loadManagement();
            $session=$this->identifier($input['client_session_id']??null);
            return ['enabled'=>!empty($context['can_use']) && $configuration['enabled'], 'configured'=>$configuration['has_api_key'],
                'identity_key'=>$this->identity($context),'window_token'=>$this->token(['type'=>'window','identity'=>$this->identity($context),'window'=>$session,'expires'=>time()+86400]),
                'server_time'=>time()*1000,'retention_seconds'=>86400,'history_round_limit'=>20,'can_configure'=>!empty($context['can_configure']),
                'guidance_schema_version'=>'mohe-clarification-v2','max_clarification_rounds'=>$this->limits()['max_clarification_rounds'],
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
            $body=$this->conversation($input);
            $limits=$this->limits();
            $snapshot=['capability_snapshot_ref'=>$this->registryHash($context),'capability_snapshot_hash'=>hash('sha256',json_encode($this->capabilities($context))),
                'budget_profile_version'=>$limits['run_budget_ms'].'-v1','authorization_version'=>$this->permissionHash($context),'model_config_version'=>(string)$configuration['version'],
                'guidance_schema_version'=>'mohe-clarification-v2','guidance_profile_version'=>'guidance-v2-'.$limits['max_clarification_rounds'],'max_clarification_rounds'=>(string)$limits['max_clarification_rounds']];
            if ($this->management) $snapshot['management_revision']=$this->managementRevision;
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
            $frozen=$this->runs->snapshot($owner,$id,$generation);
            $this->loadManagement($frozen['management_revision']??'source');
            if ($operation==='execute') {
                $body=$this->conversation($input); $this->runs->assertRequest($owner,$id,$generation,$this->bodyHash($body));
                $current=$this->runs->get($owner,$id,$generation);
                if (in_array($current['status'],['COMPLETED','PARTIAL_SUCCEEDED','CANCELLED','FAILED','WAITING_CLARIFICATION','WAITING_EXPORT'],true)) return $this->present($context,$owner,$current);
                if (!$this->runs->claim($owner,$id,$generation,$worker)) return $this->present($context,$owner,$this->runs->get($owner,$id,$generation));
                $claimed=true;
            } else {
                $r=$this->runs->get($owner,$id,$generation);
                if ($r['guidance_schema_version']==='mohe-clarification-v2') {
                    if (($input['schema_version']??'')!=='mohe-clarification-v2') throw new RuntimeException('AI_CLIENT_UPGRADE_REQUIRED');
                    $submission=['request_id'=>$this->identifier($input['client_submission_id']??null),'request_hash'=>$this->bodyHash($input),
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
                $projector=new AiModelInputProjector(); $view=$projector->modelView($body);
                if (($view['current']['semantic_intent']['followup']??'none')==='requested' && isset($body['context_ref'])) {
                    $view['current']=$this->inheritContext($context,$owner,$body['context_ref'],$view['current']);
                }
                if (isset($view['current']['verified_source_query'])) {
                    $names=[];$dictionary=new \app\services\metric\MetricDictionaryServices();
                    foreach($currentCapabilities['metric_codes'] as $code) {
                        $tooltip=$dictionary->getTooltip($code);
                        if(($tooltip['user_ready']??false)===true)$names[$code]=$tooltip['name'];
                    }
                    $createdAt=$this->runs->get($owner,$id,$generation)['created_at'];
                    $today=(new \DateTimeImmutable('@'.intdiv($createdAt,1000)))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
                    $compiled=(new \app\services\ai\execution\AiFollowupQueryPlanner())->compile($view['current']['verified_source_query'],$body['question'],$names,$body['output_format'],$today);
                } elseif (AiConfigStore::allowsSanitizedQuestion($configuration) && in_array($view['current']['blocking_reason']??null,['AI_INTENT_UNRESOLVED','AI_CAPABILITY_NOT_READY'],true)) {
                    $compiled=$this->understandAnalysis($context,$owner,$id,$generation,$worker,$body,$view['current'],$configuration);
                } else {
                // Unknown meaningful constraints are retained as blockers, never deleted to force a match.
                if (!empty($view['current']['blocking_reason'])) throw new RuntimeException($view['current']['blocking_reason']);
                $discovered=$this->discoverIntent($context,$view['current']);
                $this->runs->reserve($owner,$id,$generation,$worker,'stage_count');
                $runtimeSkill=$this->registry()->modelSkill('store_operations');
                $this->runs->reserve($owner,$id,$generation,$worker,'input_tokens',max(1,strlen(json_encode([$view,$runtimeSkill]))+2048));
                $this->runs->reserve($owner,$id,$generation,$worker,'output_tokens',1200);
                $this->runs->prepareAttempt($owner,$id,$generation,$worker,'understand','model',hash('sha256',json_encode($view)),'siliconflow');
                $this->runs->sendAttempt($owner,$id,$generation,$worker,'understand');
                try {
                    $checkpoint=function () use($owner,$id,$generation,$worker):void { $this->runs->checkpoint($owner,$id,$generation,$worker); };
                    $reply=$this->model ? call_user_func($this->model,$view,$discovered,$configuration,$checkpoint)
                        : (new SiliconFlowClient())->select($view,$discovered,$configuration['model'],$configuration['api_key'],20000,$checkpoint,$runtimeSkill);
                    $this->runs->finishAttempt($owner,$id,$generation,$worker,'understand','SUCCEEDED',$reply['usage']['input_tokens']??null,$reply['usage']['output_tokens']??null);
                } catch (\Throwable $e) {
                    $unknown=in_array($e->getMessage(),['AI_MODEL_RESULT_UNKNOWN','AI_CANCELLED'],true);
                    $this->runs->finishAttempt($owner,$id,$generation,$worker,'understand',$unknown?'UNKNOWN':'FAILED');
                    $reply=in_array($e->getMessage(),['AI_MODEL_METRIC_UNKNOWN','AI_MODEL_RESPONSE_INVALID'],true)
                        ? $this->registeredSelectionFallback($view['current'],$discovered) : null;
                    if ($reply===null) throw $e;
                }
                $capabilities=$this->capabilities($context);
                $capabilities['current_store_bound']=$context['terminal']==='store' && count($context['store_ids'])===1;
                $createdAt=$this->runs->get($owner,$id,$generation)['created_at'];
                $today=(new \DateTimeImmutable('@'.intdiv($createdAt,1000)))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
                $compiled=(new AiWorkflowPlanner())->compile($view['current'],$reply['selection'],$capabilities,$body['output_format'],$today);
                }
                $compiled=$this->decorateGuidance($compiled);
                if ($compiled['kind']==='clarification') {
                    $r=$this->issueGuidance($owner,$id,$generation,$worker,$compiled,$compiled,[]); $paused=true;
                    return $this->present($context,$owner,$r);
                }
            } else {
                $stored=$this->private->read($r['clarification_ref']); $this->assertBinding($stored,$owner,$id,$generation);
                try { [$compiled,$steps]=$this->advanceGuidance($stored,$r['clarification_ref'],$input); }
                catch (\Throwable $error) {
                    if (!in_array($error->getMessage(),['AI_CLARIFICATION_INVALID','AI_DATE_INVALID'],true)) throw $error;
                    $r=$this->runs->pauseForClarification($owner,$id,$generation,$worker,$r['clarification_ref'],false); $paused=true;
                    $response=$this->present($context,$owner,$r); $response['message']='所选条件格式不完整，请检查后重新确认。'; return $response;
                }
                if ($submission!==null) $this->runs->acceptClarification($owner,$id,$generation,$worker,$submission['request_id']);
                if ($compiled['kind']==='clarification') {
                    $r=$this->issueGuidance($owner,$id,$generation,$worker,$compiled,$stored['origin']??$stored['envelope'],$steps); $paused=true;
                    return $this->present($context,$owner,$r);
                }
            }
            if (($compiled['kind']??null)==='capability_unavailable') throw new RuntimeException($compiled['reason']??'AI_OBJECT_CONTRACT_NOT_READY');
            $result=$this->executeRegistered($context,$owner,$id,$generation,$worker,$snapshot,$compiled['plan']);
            $paused=$result['status']==='WAITING_EXPORT';
            return $this->present($context,$owner,$result);
        } catch (\Throwable $e) {
            if (!$claimed) throw $e;
            $reason=preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D',$e->getMessage())?$e->getMessage():'AI_EXECUTION_FAILED';
            return $this->present($context,$owner,$this->runs->fail($owner,$id,$generation,$worker,$reason));
        } finally {
            if ($claimed && !$paused) $this->runs->release($owner,$id,$generation,$worker);
        }
    }

    private function registryHash(array $context): string
    {
        return $this->registry()->snapshot($this->capabilities($context))['snapshot_hash'];
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
        // Dependency order in capability guidance is server-owned; old scene prompt
        // configuration must not reorder object binding after metric execution.
        if (in_array(($plan['schema_version']??null),['mohe-analysis-guidance-v1','mohe-skill-guidance-v1'],true)) return $plan;
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

    /** Signed evidence supplies prior conditions, never prior figures or additional data authority. */
    private function inheritContext(array $context,array $owner,string $reference,array $intent): array
    {
        $untrusted=json_decode(base64_decode(strtr(explode('.',$reference)[0],'-_','+/')),true);
        if (!is_array($untrusted) || !is_string($untrusted['window']??null)) throw new RuntimeException('AI_CONTEXT_REQUIRED');
        $proof=$this->verifyToken($reference,$context,$untrusted['window'],'context');
        if (($proof['conversation']??'')!==$owner['conversation_id'] || !is_string($proof['evidence']??null)) throw new RuntimeException('AI_CONTEXT_REQUIRED');
        $stored=$this->private->read($proof['evidence']); $originalOwner=$owner;$originalOwner['window_id']=$proof['window'];
        $this->assertBinding($stored,$originalOwner,$proof['run'],$proof['generation']);
        if (!isset($stored['query'],$stored['view_ref'])) throw new RuntimeException('AI_CONTEXT_REQUIRED');
        $query=$stored['query'];
        $this->queryService($context)->replay([],$query,$stored['view_ref']);
        if ($query['business_filters']!==[] || $query['store_ids']!==[]) {
            $intent['verified_source_query']=$query;
            return $intent;
        }
        // Explicit current conditions always win. Missing slots inherit only this validated source.
        if (!array_intersect(array_merge(array_keys(\app\services\query\metric\MetricSemanticCatalog::entries()),['ambiguous_metric']),$intent['signals'])) $intent['signals']=array_merge($intent['signals'],$query['metric_codes']);
        if (!array_intersect(['summary','trend','comparison','ranking','definition'],$intent['signals'])) {
            $intent['signals'][]=$query['query_shape'];
            if ($query['query_shape']==='ranking') {
                $direction=$query['ranking']['direction'];
                if (in_array($direction,['top','top_and_bottom'],true)) $intent['signals'][]='top_5';
                if (in_array($direction,['bottom','top_and_bottom'],true)) $intent['signals'][]='bottom_5';
            }
        }
        if (!$intent['date_terms']) {
            $intent['date_terms']=[['code'=>'EXPLICIT','start'=>$query['start_date'],'end'=>$query['end_date']]];
            if ($query['compare_range']!==null) $intent['date_terms'][]=['code'=>'EXPLICIT','start'=>$query['compare_range']['start'],'end'=>$query['compare_range']['end']];
        }
        $intent['signals']=array_values(array_unique($intent['signals']));
        return $intent;
    }

    private function discoverIntent(array $context,array $intent): array
    {
        $registry=$this->registry(); $snapshot=$registry->snapshot($this->capabilities($context));
        $goal=$intent['semantic_intent']['goal']??'business_results';
        if ($goal==='metric_definition') return array_keys($snapshot['definitions']);
        // Discover from the shared provider contracts, not a guessed scene/report.
        // Existing compiler/permissions remain the final executable boundary.
        $requested=array_values(array_intersect(array_keys($snapshot['metrics']),$intent['signals']));
        $shape='summary';
        foreach (['trend','ranking','comparison'] as $candidate) if (in_array($candidate,$intent['signals'],true)) $shape=$candidate;
        if (array_intersect(['top_5','bottom_5'],$intent['signals'])) $shape='ranking';
        $catalog=\app\services\query\metric\AnalysisCapabilityCatalogFactory::make();
        $result=$catalog->discover(['metric_codes'=>$requested && !in_array('ambiguous_metric',$intent['signals'],true)?$requested:[],
            'object_kind'=>'store','relation_role'=>'store_total','operation'=>$shape,'filter_keys'=>[]],
            static function(array $binding) use($snapshot,$shape): bool {
                $metric=$snapshot['metrics'][$binding['metric_code']]??null;
                return $metric && in_array($shape,$metric['query_shapes'],true) && $metric['metric_version']===$binding['contract_version'];
            });
        if (!$result['complete_request_supported']) throw new RuntimeException('AI_CAPABILITY_NOT_READY');
        return array_column(array_column($result['items'],'binding'),'metric_code');
    }

    /**
     * A vendor formatting/allowlist mistake must not make a fully explicit local
     * intent unusable. This recovery may only echo locally parsed, registered
     * values; it never guesses a missing metric, date, filter or query shape.
     */
    private function registeredSelectionFallback(array $intent,array $candidates): ?array
    {
        $signals=$intent['signals']??[];
        if (!is_array($signals) || !empty($intent['blocking_reason']) || !empty($intent['unresolved_condition'])
            || in_array('ambiguous_metric',$signals,true)) return null;
        $registered=array_keys(\app\services\query\metric\MetricSemanticCatalog::entries());
        $requested=array_values(array_intersect($registered,$signals));
        if (!$requested || array_diff($requested,$candidates)) return null;
        $shape='summary';
        foreach(['trend','ranking','comparison'] as $candidate) if(in_array($candidate,$signals,true)) $shape=$candidate;
        if(array_intersect(['top_5','bottom_5','rank_top','rank_bottom'],$signals)) $shape='ranking';
        if(in_array('definition',$signals,true)) $shape='definition';
        $date='UNSPECIFIED';
        foreach(['TODAY','YESTERDAY','THIS_MONTH','LAST_MONTH'] as $candidate) if(in_array($candidate,$signals,true)) $date=$candidate;
        if(!empty($intent['dates']) || !empty($intent['date_terms'])) $date='EXPLICIT';
        return ['selection'=>['metric_codes'=>$requested,'query_shape'=>$shape,'date_code'=>$date,'decision'=>'query'],
            'usage'=>['input_tokens'=>null,'output_tokens'=>null],'recovered_from_model_contract_error'=>true];
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
        $planner=isset($stored['envelope']['analysis_state'])?new \app\services\ai\execution\AiAnalysisGuidancePlanner()
            :(isset($stored['envelope']['skill_state'])?new \app\services\ai\execution\AiSkillGuidancePlanner():new AiWorkflowPlanner()); $choices=$input['choices']??null;
        if (!is_array($choices)) throw new RuntimeException('AI_CLARIFICATION_INVALID');
        $steps=$stored['accepted_steps']??[];
        if (!isset($input['revise_clarification_id'])) {
            $next=$this->decorateGuidance($planner->choose($stored['envelope'],$choices));
            $steps[]=['id'=>$ref,'envelope'=>$stored['envelope'],'choices'=>$choices];
            return [$next,$steps];
        }
        $target=$this->identifier($input['revise_clarification_id']); $found=false;
        $draft=$stored['origin']??$stored['envelope']; $rebuilt=[];
        foreach ($steps as $step) {
            if ($step['id']===$target) $found=true;
            if (($draft['kind']??'')!=='clarification') break;
            $expected=array_column($draft['fields'],'key'); $previous=array_keys($step['choices']); sort($expected); sort($previous);
            if ($expected!==$previous) continue;
            $selection=$step['id']===$target?$choices:$step['choices'];
            try { $next=$this->decorateGuidance($planner->choose($draft,$selection)); }
            catch (\Throwable $error) {
                if ($step['id']===$target || !$found) throw $error;
                break; // Invalid dependent binding must be re-confirmed, not silently retained.
            }
            $rebuilt[]=['id'=>$step['id'],'envelope'=>$draft,'choices'=>$selection]; $draft=$next;
        }
        if (!$found) throw new RuntimeException('AI_CLARIFICATION_STALE');
        return [$draft,$rebuilt];
    }

    private function understandAnalysis(array $context,array $owner,string $id,int $generation,string $worker,array $body,array $projection,array $configuration): array
    {
        $caps=$this->capabilities($this->fresh($context));$runtimeSkill=$this->registry()->modelSkill('store_operations');$dictionary=new \app\services\metric\MetricDictionaryServices();$summaries=[];
        foreach ($caps['metric_codes'] as $code) {
            $tooltip=$dictionary->getTooltip($code);if (($tooltip['user_ready']??false)!==true) continue;
            $kind=$caps['metric_readiness'][$code]['filter_grain'];
            $summaries[]=['metric_code'=>$code,'name'=>$tooltip['name'],'summary'=>$tooltip['summary'],'object_kind'=>$kind];
        }
        // The prompt-facing candidates and the later controlled choices are both
        // projected from the same registered provider contracts.  A dictionary
        // definition alone therefore never becomes an executable AI choice.
        $personMetrics=\app\services\ai\execution\AiCapabilityGuidanceCatalog::discover($caps,'person');
        $checkpoint=function()use($context,$owner,$id,$generation,$worker,$configuration):void {
            $this->runs->checkpoint($owner,$id,$generation,$worker);
            $current=$this->config->read();
            if ($current['version']!==$configuration['version'] || !AiConfigStore::allowsSanitizedQuestion($current)
                || $this->permissionHash($this->fresh($context))!==$this->permissionHash($context)) throw new RuntimeException('AI_AUTHORIZATION_CHANGED');
        };
        $localCatalogs=[];$privateLabels=[];
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
            foreach($localCatalogs as $catalog) foreach($catalog['objects'] as $object) if ($object['kind']==='person') $privateLabels[]=$object['label'];
        }
        $safe=(new \app\services\ai\model\AiSafeQuestionProjector())->project($body['question'],$configuration,array_values(array_unique($privateLabels)),$runtimeSkill['semantic_projection']);
        foreach ($safe['local_conditions'] as $reference=>$value) {
            if (in_array($value,$projection['dates']??[],true)) {
                $safe['outbound']['question']=str_replace('['.$reference.']','指定期间',$safe['outbound']['question']);
                unset($safe['local_conditions'][$reference]);
            } elseif(in_array($value,$privateLabels,true)) {
                $safe['outbound']['question']=str_replace('['.$reference.']','人员 ['.$reference.']',$safe['outbound']['question']);
            }
        }
        $safe['outbound']['has_unresolved_conditions']=(bool)$safe['local_conditions'];
        $this->runs->reserve($owner,$id,$generation,$worker,'stage_count');
        $this->runs->reserve($owner,$id,$generation,$worker,'input_tokens',max(1,strlen(json_encode([$safe['outbound'],$summaries,$runtimeSkill]))+2048));
        $this->runs->reserve($owner,$id,$generation,$worker,'output_tokens',1200);
        $this->runs->prepareAttempt($owner,$id,$generation,$worker,'understand','model',hash('sha256',json_encode([$safe['outbound'],$summaries])),'siliconflow');
        $this->runs->sendAttempt($owner,$id,$generation,$worker,'understand');
        try {
            $checkpoint();
            $reply=$this->model?call_user_func($this->model,$safe['outbound'],$summaries,$configuration,$checkpoint)
                :(new SiliconFlowClient())->understand($safe['outbound'],$summaries,$configuration['model'],$configuration['api_key'],20000,$checkpoint,$runtimeSkill);
            $this->runs->finishAttempt($owner,$id,$generation,$worker,'understand','SUCCEEDED',$reply['usage']['input_tokens']??null,$reply['usage']['output_tokens']??null);
        } catch (\Throwable $error) {
            $this->runs->finishAttempt($owner,$id,$generation,$worker,'understand',in_array($error->getMessage(),['AI_MODEL_RESULT_UNKNOWN','AI_CANCELLED','AI_AUTHORIZATION_CHANGED'],true)?'UNKNOWN':'FAILED');throw $error;
        }
        $checkpoint();$intent=$reply['intent'];$term=$intent['object_term'];
        $localTerm=null;
        if (preg_match('/^\[(local_condition_[0-9]+)\]$/D',$term,$match)) {
            $localTerm=$safe['local_conditions'][$match[1]]??null;unset($safe['local_conditions'][$match[1]]);$term=$localTerm??'';
        }
        // All remaining opaque conditions are meaningful. Never query a reduced request.
        if ($safe['local_conditions']) throw new RuntimeException('AI_LOCAL_CONDITION_REQUIRED');
        // The model contributes intent understanding, but a recognized public
        // object is source-bound by the published Skill.  A missing query
        // contract gets a bounded guide; it can never fall through to a broad
        // store query or a similarly named registered metric.
        $recognized=array_values(array_filter($safe['recognized_terms'],static function(array $term):bool {
            return ($term['kind']??null)==='object' && in_array($term['code']??'',['project','product','category','partner','member','inventory'],true);
        }));
        if ($recognized) return (new \app\services\ai\execution\AiSkillGuidancePlanner())->start($runtimeSkill['semantic_projection'],$recognized);
        $createdAt=$this->runs->get($owner,$id,$generation)['created_at'];
        $today=(new \DateTimeImmutable('@'.intdiv($createdAt,1000)))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
        $constraintTypes=array_column($projection['semantic_intent']['constraints']??[],'type');
        if ($intent['object_kind']==='unknown' && in_array('person_filter',$constraintTypes,true)) $intent['object_kind']='person';
        if ($intent['object_kind']==='person') {
            $remaining=(new AiModelInputProjector())->project(\app\services\query\metric\MetricSemanticCatalog::stripTerms($body['question'],array_keys($personMetrics)));
            foreach ($remaining['semantic_intent']['constraints']??[] as $constraint) {
                if (!in_array($constraint['type'],['person_filter','unparsed_business_condition'],true)) throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
            }
            if (array_intersect($projection['signals'],['definition','comparison','trend'])) throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
            if (!$personMetrics) throw new RuntimeException('AI_PERSONNEL_PERMISSION_REQUIRED');
            // Resolve against a common authorized object catalog. Before selecting a
            // different metric, execution independently rechecks its own report grant.
            $explicitRegistered=array_values(array_intersect($projection['signals'],array_keys(\app\services\query\metric\MetricSemanticCatalog::entries())));
            $explicitMetrics=array_values(array_intersect($explicitRegistered,array_keys($personMetrics)));
            if ($explicitRegistered && !$explicitMetrics) throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
            if(count($explicitMetrics)>1) throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
            // An unambiguous dictionary hit is a stronger contract than a model
            // guess. The model may discover the object, but cannot replace an
            // explicitly named registered metric.
            $metric=$explicitMetrics[0]??($intent['metric_codes'][0]??null);
            if ($metric!==null && !isset($personMetrics[$metric])) throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
            $intent['metric_codes']=$metric===null?[]:[$metric];
            if ($intent['operation']==='unknown') $intent['operation']=preg_match('/最好|最差|最高|最低|是谁|哪一位|哪个人/u',$body['question'])?'ranking':'summary';
            $catalog=$localCatalogs[$metric??array_key_first($personMetrics)];
            $objectCatalog=new \app\services\query\metric\AnalysisObjectCatalog($catalog['objects'],static function(){return true;});
            $named=$objectCatalog->resolve($term,'person',$metric);
            // Exact local names may bind a person; a missing name is never fuzzily
            // replaced with someone else. Otherwise resolve actual position metadata.
            $exactPeople=array_values(array_filter($catalog['objects'],static function($o)use($term){return $o['kind']==='person' && $o['label']===$term;}));
            $objects=$exactPeople?$named:$objectCatalog->resolve($term,'position',$metric);
            // Only explicitly requested criteria can skip the metric question.
            if (preg_match('/最好|最差|最高|最低/u',$body['question']) && !$explicitMetrics) $intent['needs_metric_choice']=true;
            $projection['analysis_singular_person']=(bool)preg_match('/是谁|哪一位|哪个人/u',$body['question']);
            return (new \app\services\ai\execution\AiAnalysisGuidancePlanner())->start($intent,$projection,$personMetrics,$objects,$body['output_format'],$today);
        }
        // No staff/customer/object restriction may become an unfiltered store query.
        if ($localTerm!==null || !in_array($intent['object_kind'],['store','unknown'],true) || !in_array($intent['object_term'],['','门店'],true)
            || ($projection['semantic_intent']['constraints']??[])!==[['type'=>'unparsed_business_condition','status'=>'unresolved']]) throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
        $explicit=array_values(array_intersect($projection['signals'],array_keys(\app\services\query\metric\MetricSemanticCatalog::entries())));
        $selected=$intent['metric_codes'];sort($explicit);sort($selected);
        if ($explicit && $explicit!==$selected) throw new RuntimeException('AI_MODEL_SELECTION_MISMATCH');
        foreach(['trend','comparison','definition','ranking'] as $shape) if(in_array($shape,$projection['signals'],true) && $shape!==$intent['operation']) throw new RuntimeException('AI_MODEL_SELECTION_MISMATCH');
        $projection['signals']=array_values(array_unique(array_merge($projection['signals'],$intent['metric_codes'],[$intent['operation']])));
        if ($intent['needs_metric_choice']) $projection['signals'][]='ambiguous_metric';
        $projection['blocking_reason']=null;$projection['unresolved_condition']=false;
        $caps['current_store_bound']=$context['terminal']==='store' && count($context['store_ids'])===1;
        return (new AiWorkflowPlanner())->compile($projection,['decision'=>'query','query_shape'=>$intent['operation'],'metric_codes'=>$intent['metric_codes']],$caps,$body['output_format'],$today);
    }

    /** Registry selects the frozen graph; the gateway supplies only trusted infrastructure adapters. */
    private function executeRegistered(array $context,array $owner,string $id,int $generation,string $worker,array $snapshot,array $plannerPlan): array
    {
        $run=$this->runs->checkpoint($owner,$id,$generation,$worker);
        $today=(new \DateTimeImmutable('@'.intdiv($run['created_at'],1000)))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
        if (isset($plannerPlan['query']) && ($plannerPlan['query']['end_date']>$today || (($plannerPlan['query']['compare_range']['end']??$today)>$today))) throw new RuntimeException('AI_FUTURE_ACTUALS_UNAVAILABLE');
        $budget=(int)explode('-',$snapshot['budget_profile_version'])[0];
        $this->assertManagedPlan($plannerPlan);
        $plan=(new AiRegisteredPlanCompiler($this->registry()))->compile($plannerPlan,$this->capabilities($context),[
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
        $tool=function(string $code,string $target,callable $action) use($owner,$id,$generation,$worker,$plan) {
            $this->runs->prepareAttempt($owner,$id,$generation,$worker,$code,'tool',$plan['compiled_run_hash'],$target);
            if (!$this->runs->sendAttempt($owner,$id,$generation,$worker,$code)) throw new RuntimeException('AI_ATTEMPT_CONFLICT');
            try { $value=$action(); $this->runs->finishAttempt($owner,$id,$generation,$worker,$code,'SUCCEEDED'); return $value; }
            catch (\Throwable $error) { $this->runs->finishAttempt($owner,$id,$generation,$worker,$code,'FAILED'); throw $error; }
        };
        $handlers=[
            'unified_metric_query'=>function($input,$node,$compiled,$heartbeat) use($context,$owner,$id,$generation,$worker,&$evidence,$tool) {
                $this->runs->progress($owner,$id,$generation,$worker,'QUERYING');
                $evidence=$tool('query','unified_metric_query',function() use($context,$owner,$id,$generation,$input,$heartbeat) {
                    return $this->queryService($context,$heartbeat)->create([],$input['query'],intdiv($this->runs->get($owner,$id,$generation)['expires_at'],1000));
                }); return $evidence;
            },
            'all_evidence_guard'=>function($input,$node,$compiled,$heartbeat) use($context,$owner,$id,$generation,$worker,&$evidence) {
                $this->runs->progress($owner,$id,$generation,$worker,'VERIFYING');
                $expected=count($input['query']['metric_codes'])*($input['query']['compare_range']===null?1:2);
                if (!$evidence || empty($evidence['ai_query_ready']) || $evidence['result_status']!=='complete' || count($evidence['results'])!==$expected) throw new RuntimeException('AI_EVIDENCE_INCOMPLETE');
                $this->queryService($context,$heartbeat)->replay([],$input['query'],$evidence['read_consistency_ref']);
                return ['verified'=>true];
            },
            'deterministic_answer'=>function() use($owner,$id,$generation,$worker,&$evidence,&$answer) {
                $this->runs->progress($owner,$id,$generation,$worker,'RENDERING'); $answer=$this->answer($evidence); return ['answer'=>$answer];
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
            'verified_export_create'=>function() use($context,$owner,$id,$generation,$worker,$plan,$guard,&$evidence,&$answer,&$waiting,&$trace) {
                $guard(); [$evidenceRef,$answerRef]=$this->saveResults($owner,$id,$generation,$plan,$evidence,$answer,$trace);
                // ExportRuntime owns the source-bound Task receipt and dispatch UNKNOWN handling.
                $waiting=$this->exportRuntime()->queue($this->fresh($context),$owner,$this->runs->get($owner,$id,$generation),$worker,$evidenceRef,$answerRef,$evidence);
                return ['deferred'=>true];
            },
        ];
        $execution=(new AiRegisteredWorkflowExecutor($this->registry()))->execute($plan,$handlers,$checkpoint);
        if ($waiting!==null) return $waiting;
        $guard();
        [$evidenceRef,$answerRef]=$this->saveResults($owner,$id,$generation,$plan,$evidence,$answer,$execution['trace']);
        return $this->runs->publish($owner,$id,$generation,$worker,$evidenceRef,$answerRef);
    }

    private function saveResults(array $owner,string $id,int $generation,array $plan,array $evidence,array $answer,array $trace): array
    {
        $expires=min($evidence['expires_at'],intdiv($this->runs->get($owner,$id,$generation)['expires_at'],1000));
        $binding=['owner'=>$owner,'run_id'=>$id,'generation'=>$generation];
        $source=$plan['query']===null?['definition'=>$evidence]:['view_ref'=>$evidence['read_consistency_ref'],'query'=>$plan['query']];
        return [$this->private->put('evidence',$binding+$source+['compiled_run_hash'=>$plan['compiled_run_hash'],'execution_trace'=>$trace,
            'workflow_code'=>$plan['workflow_code'],'management_version'=>$this->managementRevision],$expires),
            $this->private->put('answer',$binding+['answer'=>$answer],$expires)];
    }

    private function definitionEvidence(array $context,array $codes): array
    {
        $fresh=$this->fresh($context); $binding=\app\services\ai\execution\AiAuthority::reportBinding($fresh,$this->instance,$this->private->signingKey());
        if (!in_array($fresh['scope_mode'],['all','stores'],true) || ($fresh['scope_mode']==='stores' && !$fresh['store_ids'])) throw new RuntimeException('AI_PERMISSION_DENIED');
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

    private function queryService(array $context,?callable $checkpoint=null): MetricReadViewServices
    {
        return new MetricReadViewServices($this->views,function () use($context): array {
            $c=$this->fresh($context);
            if (empty($c['can_use'])) throw new RuntimeException('AI_PERMISSION_DENIED');
            return ['instance_id'=>$this->instance,'subject_ref'=>$this->identity($c),'terminal'=>$c['terminal'],'tenant_id'=>(string)($c['tenant_id']??0),
                'permission_version'=>$this->permissionHash($c),'report_capability_code'=>$c['report_capability_code'],'scope_provider_code'=>'current_report_scope_v1',
                'scope_mode'=>$c['scope_mode'],'store_ids'=>$c['store_ids']];
        },$this->queryTransaction ?: [new \app\services\query\metric\MetricReadTransaction(10000,$checkpoint),'run'],null,$this->personnelObjects($context));
    }
    private function personnelObjects(array $context): \app\services\query\metric\PersonnelAnalysisObjectServices
    {
        return new \app\services\query\metric\PersonnelAnalysisObjectServices(static function(string $table){return \think\facade\Db::name($table);},function(string $metric)use($context):array {
            $fresh=$this->fresh($context);
            if (empty($fresh['can_use']) || ($fresh['analysis_personnel_grants'][$metric]??false)!==true
                || !AiConfigStore::allowsSanitizedQuestion($this->config->read())) throw new RuntimeException('AI_PERSONNEL_PERMISSION_REQUIRED');
            return ['personnel_authorized'=>true,'store_ids'=>$fresh['store_ids'],'employee_id'=>0,'permission_version'=>$this->permissionHash($fresh)];
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
        $context['analysis_personnel_ready']=$context && AiConfigStore::allowsSanitizedQuestion($this->config->read());
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
    private function conversation(array $input): array
    {
        $body=(new AiModelInputProjector())->validateConversation($input['question']??null,$input['history']??null);
        $format=$input['output_format']??'screen'; if (!in_array($format,['screen','screen_and_xlsx'],true)) throw new RuntimeException('AI_OUTPUT_FORMAT_INVALID');
        if (isset($input['context_ref'])) {
            if (!is_string($input['context_ref']) || strlen($input['context_ref'])>2048 || substr_count($input['context_ref'],'.')!==1) throw new RuntimeException('AI_CONTEXT_REQUIRED');
            $body['context_ref']=$input['context_ref'];
        }
        $body['output_format']=$format; return $body;
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
        $result=['run_id'=>$run['run_id'],'generation'=>$run['generation'],'version'=>$run['version'],'status'=>$run['status'],'reason'=>$run['reason'],
            'progress'=>$this->progressText($run),'message'=>$this->progressText($run),'run_delivery_token'=>$this->token(['type'=>'run','identity'=>$this->identity($context),'window'=>$owner['window_id'],
                'conversation'=>$owner['conversation_id'],'run'=>$run['run_id'],'generation'=>$run['generation'],'expires'=>intdiv($run['expires_at'],1000)])];
        if ($run['status']==='WAITING_CLARIFICATION') {
            $stored=$this->private->read($run['clarification_ref']); $this->assertBinding($stored,$owner,$run['run_id'],$run['generation']);
            $result['clarification']=['id'=>$run['clarification_ref'],'question'=>$stored['envelope']['question']??'请确认这一项查询条件','fields'=>$stored['envelope']['fields']];
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
            else $this->queryService($context)->replay([],$stored['query'],$stored['view_ref']);
            $answer=$this->private->read($run['answer_ref']); $this->assertBinding($answer,$owner,$run['run_id'],$run['generation']);
            $result['answer']=$answer['answer'];
            if ($context['terminal']==='platform' && !empty($this->fresh($context)['can_configure'])) {
                $result['management_trace']=['workflow_code'=>$stored['workflow_code']??null,'management_version'=>$stored['management_version']??'source',
                    'nodes'=>$stored['execution_trace']??[]];
            }
            if (isset($stored['query'])) $result['answer']['context_ref']=$this->token(['type'=>'context','identity'=>$this->identity($context),'window'=>$owner['window_id'],
                'conversation'=>$owner['conversation_id'],'run'=>$run['run_id'],'generation'=>$run['generation'],'evidence'=>$run['evidence_ref'],'expires'=>intdiv($run['expires_at'],1000)]);
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
            $registered=MetricReadViewServices::metricCapabilities();
            if (!isset($registered[$row['metric_code']??'']) || !$registered[$row['metric_code']]['ai_query_ready']||!in_array($row['period']??'',['current','comparison'],true)) throw new RuntimeException('AI_EVIDENCE_INVALID');
            $tooltip=$dictionary->getTooltip($row['metric_code']);
            if (empty($tooltip['user_ready'])) throw new RuntimeException('AI_METRIC_EXPLANATION_NOT_READY');
            $storageUnit=(string)($row['storage_unit']??'fen');
            $unit=$storageUnit==='count'?'个':'元';
            if ($shape==='trend') {
                foreach ($row['rows'] as $point) $rows[]=['label'=>$point['business_date'],'metric'=>$tooltip['name'],'value'=>$this->metricValue($point['amount_cents'],$storageUnit),'unit'=>$unit];
                continue;
            }
            if ($shape==='ranking') {
                foreach ($row['rows'] as $direction=>$points) foreach ($points as $index=>$point) $rows[]=['label'=>$point['employee_name']??($point['store_name']??('门店 ID '.$point['store_id'])),
                    'metric'=>$tooltip['name'],'rank'=>($direction==='top'?'前':'后').($index+1),'value'=>$this->metricValue($point['amount_cents'],$storageUnit),'unit'=>$unit];
                continue;
            }
            $display=$this->metricValue($storageUnit==='count'?($row['count']??null):($row['amount_cents']??null),$storageUnit);
            $range=$row['period']==='current'?['start'=>$view['query']['start_date'],'end'=>$view['query']['end_date']]:$view['query']['compare_range'];
            $cards[]=['metric_name'=>$tooltip['name'],'display_value'=>$display,'unit'=>$unit,'tooltip'=>$tooltip,
                'period_label'=>($row['period']==='current'?'查询期间：':'对比期间：').$range['start'].' 至 '.$range['end'],
                'start_date'=>$range['start'],'end_date'=>$range['end'],'data_as_of'=>$view['data_as_of']];
        }
        $answer=['summary'=>'已按您当前报表的数据范围查询。统计时间：'.$view['query']['start_date'].' 至 '.$view['query']['end_date'].'。','cards'=>$cards];
        $person=($view['query']['business_filters']['object_kind']??null)==='person';
        if ($person) {
            $answer['summary'].=' 人员范围：'.$view['personnel_selection_label'].'（按当前任职筛选）。';
            $criteria=[];foreach($view['query']['metric_codes'] as $code)$criteria[]=$dictionary->getTooltip($code)['name'];
            $answer['summary'].=' 评价指标：'.implode('、',$criteria).'。';
            if ($shape==='ranking') $answer['summary'].=$rows?'仅按所选指标排序，不代表综合评价；相同金额按稳定人员顺序展示。':'本期间没有符合条件的人员业绩事实，不能据此评定谁表现最好。';
        }
        if ($view['query']['compare_range']) $answer['summary'].='对比时间：'.$view['query']['compare_range']['start'].' 至 '.$view['query']['compare_range']['end'].'。';
        if ($rows) {
            $columns=[['key'=>'label','label'=>$shape==='trend'?'日期':($person?'人员':'门店')],['key'=>'metric','label'=>'指标'],['key'=>'value','label'=>'数值'],['key'=>'unit','label'=>'单位']];
            if ($shape==='ranking') $columns[]=['key'=>'rank','label'=>'名次'];
            $answer['table']=['columns'=>$columns,'rows'=>$rows];
        }
        return $answer;
    }
    private function amount($cents): string
    {
        return \app\services\query\metric\MetricMoneyFormatter::integerYuan($cents);
    }
    private function metricValue($value,string $storageUnit): string
    {
        if ($storageUnit==='fen') return $this->amount($value);
        if ($storageUnit==='count' && is_int($value)) return (string)$value;
        throw new RuntimeException('AI_EVIDENCE_VALUE_INVALID');
    }
    private function progressText(array $run): string
    {
        if ($run['status']==='CANCELLED') return '已取消';
        if ($run['status']==='COMPLETED') return '查询完成';
        if ($run['status']==='PARTIAL_SUCCEEDED') return '数据已核对，文件未生成';
        if ($run['status']==='WAITING_EXPORT') return '正在生成 Excel 文件';
        if ($run['status']==='FAILED') return [
            'AI_WORKFLOW_DISABLED'=>'此项分析能力已由管理员停用，请联系平台管理员。',
            'AI_PERSONNEL_PERMISSION_REQUIRED'=>'当前账号尚无对应人员指标的可用查询权限，未读取或披露人员信息。',
            'AI_OBJECT_BINDING_UNAVAILABLE'=>'在当前可用范围内还不能确定您指的对象，未删减条件或改查全部。',
            'AI_OBJECT_SCOPE_TOO_LARGE'=>'涉及的人员范围较大，请先缩小到具体门店或岗位后再查询。',
            'AI_LOCAL_CONDITION_REQUIRED'=>'问题中还有未能准确绑定的条件，未将这些内容发给模型，也未删掉条件查询。请明确这些条件后重试。',
            'AI_ANALYSIS_COMBINATION_UNAVAILABLE'=>'已识别分析方向，但当前底层尚不能完整执行这些对象、指标与条件的组合；没有替换指标或删减条件。',
            'AI_PROJECT_OBJECT_NOT_READY'=>'项目对象尚未接入项目解析、当前权限、筛选、统一查询与证据合同；已保留您的问题，未查询或替换为其他指标。',
            'AI_PRODUCT_OBJECT_NOT_READY'=>'产品对象尚未接入产品解析、当前权限、筛选、统一查询与证据合同；已保留您的问题，未查询或替换为其他指标。',
            'AI_CATEGORY_OBJECT_NOT_READY'=>'商品分类对象尚未接入当前配置快照、权限、筛选、统一查询与证据合同；已保留您的问题，未查询或替换为其他指标。',
            'AI_PARTNER_OBJECT_NOT_READY'=>'合作方对象尚未接入分类维度、当前权限、筛选、统一查询与证据合同；已保留您的问题，未查询或替换为其他对象。',
            'AI_MEMBER_OBJECT_NOT_READY'=>'会员对象尚未接入隐私权限、筛选、统一查询与证据合同；已保留您的问题，未查询或替换为其他对象。',
            'AI_INVENTORY_OBJECT_NOT_READY'=>'库存与耗用对象尚未接入门店权限、筛选、统一查询与证据合同；已保留您的问题，未查询或替换为其他指标。',
            'AI_OBJECT_CONTRACT_NOT_READY'=>'当前分析对象尚未接入对象解析、权限、筛选、统一查询与证据合同；未查询或替换条件。',
            'AI_INTENT_UNRESOLVED'=>'还不能准确确定您的完整需求。请明确想了解什么、涉及对象和时间；本次未删减条件或查询数据。',
            'AI_CAPABILITY_NOT_READY'=>'已识别您的需求，但对应的数据能力或筛选组合尚未接入，暂不能准确提供结果。',
            'AI_CONTEXT_REQUIRED'=>'本次缺少可验证的前文条件，请明确要查询的指标和时间。',
            'AI_FOLLOWUP_CONDITION_REQUIRED'=>'已找到上次查询，但本次修改的条件还不能准确确认。请明确要修改的指标、时间或人员范围；未删减您的条件。',
            'AI_RANK_LIMIT_NOT_READY'=>'当前支持门店前五、后五排行，暂不支持您要求的数量；本次未更改您的条件。',
            'AI_FUTURE_ACTUALS_UNAVAILABLE'=>'未来日期尚未发生实际业绩，暂不能提供该日期的实际数据，也未替换成今天或预测值。',
            'AI_DATA_COVERAGE_INCOMPLETE'=>'您要求的完整期间尚未全部通过数据核验，本次未缩短日期范围，请选择其他期间。',
            'AI_DATE_INVALID'=>'日期范围不完整或无效，请重新选择开始和结束日期。',
            'AI_CLARIFICATION_EXHAUSTED'=>'已达到本次引导上限，仍有条件未确定。请把问题拆小后重新提问。',
            'AI_CLARIFICATION_INVALID_LIMIT'=>'条件连续未能通过检查，本次已停止。请重新提问并选择完整条件。',
            'AI_CLARIFICATION_EXPIRED'=>'本次选择等待已超时，请重新提问。',
            'CLARIFICATION_EXPIRED'=>'本次选择等待已超时，请重新提问。',
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
            'AI_EXECUTION_CLOCK_REGRESSED'=>'服务时间校验异常，本次查询已停止，请稍后重试。',
        ][$run['reason']]??'本次查询未完成，请稍后重试。';
        if ($run['status']==='WAITING_CLARIFICATION') return '请确认当前这一步，已确认条件会保留。';
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
