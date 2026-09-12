<?php
namespace app\services\ai;

use app\services\ai\config\AiConfigStore;
use app\services\ai\config\AiPrivateStorage;
use app\services\ai\execution\AiRunStore;
use app\services\ai\execution\AiWorkflowPlanner;
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
                'guidance_schema_version'=>'mohe-clarification-v2','guidance_profile_version'=>'guidance-v2-'.$limits['max_clarification_rounds'],'max_clarification_rounds'=>(string)$limits['max_clarification_rounds'],
                'intent_contract_version'=>AiIntentResultContract::VERSION];
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
        $worker=bin2hex(random_bytes(24)); $claimed=false; $paused=false; $guidanceRequiresSemanticContext=false;
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
                // There is one interpretation path. A frozen configuration
                // without the de-identified natural-language contract cannot
                // fall back to phrase matching or a local legacy parser.
                if (!AiConfigStore::allowsSanitizedQuestion($configuration)) throw new RuntimeException('AI_MODEL_CONFIG_UPGRADE_REQUIRED');
                $projection=['dates'=>[],'date_terms'=>[],'signals'=>[],'semantic_intent'=>['constraints'=>[]],'blocking_reason'=>null,'unresolved_condition'=>false];
                $compiled=$this->understandAnalysis($context,$owner,$id,$generation,$worker,$body,$projection,$configuration);
                $compiled=$this->decorateGuidance($compiled);
                if ($compiled['kind']==='clarification') {
                    $r=$this->issueGuidance($owner,$id,$generation,$worker,$compiled,$compiled,[]); $paused=true;
                    return $this->present($context,$owner,$r);
                }
            } else {
                $stored=$this->private->read($r['clarification_ref']); $this->assertBinding($stored,$owner,$id,$generation);
                // Semantic intent is private state owned by the gateway, not
                // by one particular clarification planner.  A chain can move
                // from a context choice into a dimension choice (or the other
                // way around), so checking planner-specific state here would
                // let the accepted meaning disappear before the final query.
                $guidanceRequiresSemanticContext=array_key_exists('_semantic_context',$stored['envelope']);
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
            try {
                $result=$this->executeRegistered($context,$owner,$id,$generation,$worker,$snapshot,$compiled['plan']);
            } catch (\RuntimeException $error) {
                // These are execution boundaries, not failed language understanding.
                // Offer only server-built ranges and require customer confirmation.
                $today=(new \DateTimeImmutable('@'.intdiv($this->runs->get($owner,$id,$generation)['created_at'],1000)))
                    ->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
                $guidance=in_array($error->getMessage(),['AI_DATA_COVERAGE_INCOMPLETE','AI_DATE_RANGE_TOO_LONG'],true)
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
     * Converts a model-understood named store into a stable scope only after
     * looking it up in the current report-authorized store catalog. The model
     * never receives this catalog and never supplies an ID.
     */
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
        if (isset($envelope['dimension_state'])) return new \app\services\ai\execution\AiDimensionGuidancePlanner();
        if (isset($envelope['skill_state'])) return new \app\services\ai\execution\AiSkillGuidancePlanner();
        return new AiWorkflowPlanner();
    }

    private function understandAnalysis(array $context,array $owner,string $id,int $generation,string $worker,array $body,array $projection,array $configuration): array
    {
        $caps=$this->capabilities($this->fresh($context));$runtimeSkills=$this->registry()->modelSkills('store_operations');$dictionary=new \app\services\metric\MetricDictionaryServices();$summaries=[];
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
            $summaries[]=['metric_code'=>$code,'name'=>$tooltip['name'],'summary'=>implode("\n",$meaning),'object_contracts'=>$objects];
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
        $localCatalogs=[];$privateLabels=[];$privateKinds=[];$privateKindsByReference=[];
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
                $privateLabels[]=$object['label'];$privateKinds[$object['label']][$object['kind']]=true;
            }
        }
        $safe=(new \app\services\ai\model\AiSafeQuestionProjector())->projectConversation(
            $body['question'],$body['history'],$configuration,array_values(array_unique($privateLabels))
        );
        foreach (($safe['reference_values']??$safe['local_conditions']) as $reference=>$value) {
            if(in_array($value,$privateLabels,true)) {
                $kinds=$privateKinds[$value]??[];$descriptor=isset($kinds['position'])&&!isset($kinds['person'])?'岗位':'人员';
                $privateKindsByReference[$reference]=$descriptor==='岗位'?'position':'person';
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
        $createdAt=$this->runs->get($owner,$id,$generation)['created_at'];
        $today=(new \DateTimeImmutable('@'.intdiv($createdAt,1000)))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
        // The reference date is server-owned context, not a guessed reading of
        // customer language.  Relative time is interpreted by the model and
        // then materialized below with this value.
        $safe['outbound']['reference_date']=$today;
        $safe['outbound']['server_resolved_fields']=[];
        // A signed answer reference is useful conversation context, not a
        // shortcut around natural-language understanding.  It is verified and
        // reduced to non-sensitive query meaning before the model sees it.
        $sourceContext=isset($body['context_ref'])?$this->contextService()->restore($context,$owner,$body['context_ref']):null;
        $sourceQuery=$sourceContext['query']??null;
        $safe['outbound']['prior_query']=$sourceQuery===null?null:IntentContextMerger::modelView($sourceQuery);
        // Understanding has no metric catalogue. Binding receives accepted
        // meaning afterwards and may only propose registered execution fields.
        $this->runs->reserve($owner,$id,$generation,$worker,'stage_count');
        $this->runs->reserve($owner,$id,$generation,$worker,'input_tokens',max(1,strlen(json_encode([$safe['outbound'],$runtimeSkills]))+1024));
        $this->runs->reserve($owner,$id,$generation,$worker,'output_tokens',1200);
        $this->runs->prepareAttempt($owner,$id,$generation,$worker,'understand_meaning','model',hash('sha256',json_encode([$safe['outbound'],$runtimeSkills])),'siliconflow');
        $this->runs->sendAttempt($owner,$id,$generation,$worker,'understand_meaning');
        try {
            $checkpoint();
            $meaningReply=$this->model
                ? call_user_func($this->model,$safe['outbound'],[],$configuration,$checkpoint,null,'understanding')
                : (new SiliconFlowClient())->understandMeaning($safe['outbound'],$configuration['model'],$configuration['api_key'],30000,$checkpoint,$runtimeSkills);
            $understanding=\app\services\ai\contract\AiIntentUnderstandingContract::normalize($meaningReply['understanding']??null,$safe['outbound']);
            $this->runs->finishAttempt($owner,$id,$generation,$worker,'understand_meaning','SUCCEEDED',$meaningReply['usage']['input_tokens']??null,$meaningReply['usage']['output_tokens']??null);
        } catch (\Throwable $error) {
            if ($error instanceof AiContractException) $this->recordModelDiagnostic($owner,$id,$generation,$worker,$error);
            $firstState=in_array($error->getMessage(),['AI_MODEL_RESULT_UNKNOWN','AI_CANCELLED','AI_AUTHORIZATION_CHANGED'],true)?'UNKNOWN':'FAILED';
            $this->runs->finishAttempt($owner,$id,$generation,$worker,'understand_meaning',$firstState);
            $diagnostic=$error instanceof AiContractException?$error->diagnostic():[];
            $repairPredicate=$diagnostic['predicate']??null;
            if (!($error instanceof AiContractException) || $error->getMessage()!=='AI_MODEL_INTENT_CONTRACT_INVALID'
                || !\app\services\ai\contract\AiIntentUnderstandingContract::repairable($repairPredicate)) throw $error;
            // There is only one recovery reservation for the whole Run.  If
            // this correction is used here, a later binding defect is reported
            // honestly instead of issuing an unbounded chain of model calls.
            $this->runs->reserve($owner,$id,$generation,$worker,'model_recovery_count');
            $this->runs->reserve($owner,$id,$generation,$worker,'input_tokens',max(1,strlen(json_encode([$safe['outbound'],$runtimeSkills,$repairPredicate]))+1536));
            $this->runs->reserve($owner,$id,$generation,$worker,'output_tokens',1200);
            $this->runs->prepareAttempt($owner,$id,$generation,$worker,'understand_repair','model',hash('sha256',json_encode([$safe['outbound'],$runtimeSkills,$repairPredicate])),'siliconflow');
            $this->runs->sendAttempt($owner,$id,$generation,$worker,'understand_repair');
            try {
                $checkpoint();
                $meaningReply=$this->model
                    ? call_user_func($this->model,$safe['outbound'],[],$configuration,$checkpoint,$repairPredicate,'understanding')
                    : (new SiliconFlowClient())->understandMeaning($safe['outbound'],$configuration['model'],$configuration['api_key'],30000,$checkpoint,$runtimeSkills,$repairPredicate);
                $understanding=\app\services\ai\contract\AiIntentUnderstandingContract::normalize($meaningReply['understanding']??null,$safe['outbound']);
                $this->runs->finishAttempt($owner,$id,$generation,$worker,'understand_repair','SUCCEEDED',$meaningReply['usage']['input_tokens']??null,$meaningReply['usage']['output_tokens']??null);
            } catch (\Throwable $repairError) {
                if ($repairError instanceof AiContractException) $this->recordModelDiagnostic($owner,$id,$generation,$worker,$repairError);
                $this->runs->finishAttempt($owner,$id,$generation,$worker,'understand_repair',in_array($repairError->getMessage(),['AI_MODEL_RESULT_UNKNOWN','AI_CANCELLED','AI_AUTHORIZATION_CHANGED'],true)?'UNKNOWN':'FAILED');
                throw $repairError;
            }
        }
        $this->runs->reserve($owner,$id,$generation,$worker,'stage_count');
        $this->runs->reserve($owner,$id,$generation,$worker,'input_tokens',max(1,strlen(json_encode([$safe['outbound'],$understanding,$summaries,$runtimeSkills]))+2048));
        $this->runs->reserve($owner,$id,$generation,$worker,'output_tokens',1200);
        $this->runs->prepareAttempt($owner,$id,$generation,$worker,'bind_intent','model',hash('sha256',json_encode([$safe['outbound'],$understanding,$summaries])),'siliconflow');
        $this->runs->sendAttempt($owner,$id,$generation,$worker,'bind_intent');
        try {
            $checkpoint();
            $reply=$this->model
                ? call_user_func($this->model,$safe['outbound'],$summaries,$configuration,$checkpoint,null,'binding',$understanding)
                : (new SiliconFlowClient())->understand($safe['outbound'],$summaries,$understanding,$configuration['model'],$configuration['api_key'],30000,$checkpoint,$runtimeSkills);
            $reply['intent']=$this->semanticIntent($reply['intent']??null,$summaries,$safe['outbound'],$understanding);
            $this->runs->finishAttempt($owner,$id,$generation,$worker,'bind_intent','SUCCEEDED',$reply['usage']['input_tokens']??null,$reply['usage']['output_tokens']??null);
        } catch (\Throwable $error) {
            if ($error instanceof AiContractException) $this->recordModelDiagnostic($owner,$id,$generation,$worker,$error);
            $firstState=in_array($error->getMessage(),['AI_MODEL_RESULT_UNKNOWN','AI_CANCELLED','AI_AUTHORIZATION_CHANGED'],true)?'UNKNOWN':'FAILED';
            $this->runs->finishAttempt($owner,$id,$generation,$worker,'bind_intent',$firstState);
            $diagnostic=$error instanceof AiContractException?$error->diagnostic():[];
            $repairPredicate=$diagnostic['predicate']??null;
            // A provider may omit a mandatory JSON field on either a fresh
            // question or a follow-up.  One fenced completion attempt is safe
            // in both cases: it asks the model to provide the field itself,
            // and never supplies a metric, condition, date or other business
            // meaning on the model's behalf.
            $repairable=$error instanceof AiContractException
                && $error->getMessage()==='AI_MODEL_INTENT_CONTRACT_INVALID'
                && AiIntentResultContract::repairableFormat($repairPredicate);
            if (!$repairable) throw $error;
            // A completed response with one omitted mandatory structural field
            // is safe to correct once.  This is a separate fenced attempt,
            // not a replay of an unknown provider outcome.
            $this->runs->reserve($owner,$id,$generation,$worker,'model_recovery_count');
            $this->runs->reserve($owner,$id,$generation,$worker,'input_tokens',max(1,strlen(json_encode([$safe['outbound'],$summaries,$runtimeSkills]))+2304));
            $this->runs->reserve($owner,$id,$generation,$worker,'output_tokens',1200);
            $this->runs->prepareAttempt($owner,$id,$generation,$worker,'bind_repair','model',hash('sha256',json_encode([$safe['outbound'],$understanding,$summaries,$repairPredicate])),'siliconflow');
            $this->runs->sendAttempt($owner,$id,$generation,$worker,'bind_repair');
            try {
                $checkpoint();
                $reply=$this->model
                    ? call_user_func($this->model,$safe['outbound'],$summaries,$configuration,$checkpoint,$repairPredicate,'binding',$understanding)
                    : (new SiliconFlowClient())->understand($safe['outbound'],$summaries,$understanding,$configuration['model'],$configuration['api_key'],30000,$checkpoint,$runtimeSkills,$repairPredicate);
                $reply['intent']=$this->semanticIntent($reply['intent']??null,$summaries,$safe['outbound'],$understanding);
                $this->runs->finishAttempt($owner,$id,$generation,$worker,'bind_repair','SUCCEEDED',$reply['usage']['input_tokens']??null,$reply['usage']['output_tokens']??null);
            } catch (\Throwable $repairError) {
                if ($repairError instanceof AiContractException) $this->recordModelDiagnostic($owner,$id,$generation,$worker,$repairError);
                $this->runs->finishAttempt($owner,$id,$generation,$worker,'bind_repair',in_array($repairError->getMessage(),['AI_MODEL_RESULT_UNKNOWN','AI_CANCELLED','AI_AUTHORIZATION_CHANGED'],true)?'UNKNOWN':'FAILED');
                throw $repairError;
            }
        }
        $checkpoint();$intent=$reply['intent'];
        // The model states only a delta. This named merger is the sole place
        // that may retain verified query meaning across turns.
        $merged=IntentContextMerger::merge($sourceQuery,$intent);
        $intent=$merged['intent'];$inheritedConstraints=$merged['constraints'];
        // A condition can be understood without having a registered Reader
        // representation (for example, a requested calendar restriction).
        // Do not ask an unrelated clarification and then run the old query:
        // report the actual capability boundary before any fallback exists.
        if (AiIntentResultContract::hasUnboundRequirement($understanding)) {
            throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
        }
        // A clarification can defer *how* to present the result, but it may
        // never defer review of a metric selected for this turn. Review the
        // prospective binding before the private fallback replaces pending
        // fields with the signed predecessor, otherwise an exclusion could
        // disappear when the customer confirms the presentation choice.
        $bindingCandidate=$merged['prospective_intent'];
        if (AiIntentResultContract::requiresSemanticBindingReview($understanding,$bindingCandidate)) {
            $reviewDecision=$this->reviewSemanticBinding($owner,$id,$generation,$worker,$safe['outbound'],$summaries,$understanding,$bindingCandidate,$configuration,$checkpoint);
            if ($reviewDecision==='metric_choice') {
                // The candidate was only plausible, not uniquely requested.
                // The registered guidance supplies the choices; the model's
                // candidate never becomes a default answer or hidden filter.
                $intent['metric_codes']=[];
                $intent['needs_metric_choice']=true;
                $intent['requirement_bindings']=array_map(static function(array $row):array {
                    return ['requirement_id'=>$row['requirement_id'],'status'=>'pending','metric_codes'=>[]];
                },$intent['requirement_bindings']);
                $bindingCandidate=$intent;
                $bindingCandidate['_reviewed_ambiguity']=true;
            }
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
            if (!in_array($intent['object_kind'],['unknown',$resultReference['object_kind']],true)) throw new RuntimeException('AI_RESULT_REFERENCE_UNAVAILABLE');
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
        }
        // Merging must not turn a newly understood condition into a previous
        // query value. The pre-merge contract catches a contradictory delta;
        // this final check is a defense in depth for every executable path.
        if (!$merged['pending']) {
            AiIntentResultContract::assertEffectiveRequirementValues($understanding,$intent);
        }
        // Apply a result reference after choosing either the requested delta
        // or its signed fallback.  A clarification must never silently drop
        // the user's reference and expand the follow-up back to every row.
        if ($resultReference!==null) {
            $intent['object_kind']=$resultReference['object_kind'];
            $intent['object_term']='';
            $inheritedConstraints=IntentContextMerger::applyResultReference($inheritedConstraints,$resultReference);
        }
        // On the first turn a named store is not inherited state; it is an
        // understood customer restriction that still has to be resolved in
        // the current, authorized store catalog.  Keeping it as `inherit`
        // used to discard the term and accidentally read every authorized
        // store.  This branches on the semantic carrier, not on a Chinese
        // phrase or an entry point.
        $contextDecisions=$sourceQuery===null
            ? ['store_scope'=>(($intent['object_relation']??'analysis')==='selection' ? 'replace' : 'inherit'),'business_filters'=>'inherit']
            : ['store_scope'=>$intent['context_delta']['store_scope'],'business_filters'=>$intent['context_delta']['business_filters']];
        if ($intent['_object_term_normalized']) {
            try { $this->runs->recordDiagnostic($owner,$id,$generation,$worker,['stage'=>'intent_contract','predicate'=>'object_term_not_verbatim']); } catch (\Throwable $ignored) {}
        }
        $term=$intent['object_term'];
        if ($intent['unresolved_fragments']) throw new RuntimeException('AI_INTENT_UNRESOLVED');
        if ($intent['operation']==='unknown') throw new RuntimeException('AI_INTENT_UNRESOLVED');
        $localTerm=null;
        if (preg_match('/^\[(local_condition_[0-9]+)\]$/D',$term,$match)) {
            $localTerm=($safe['reference_values']??$safe['local_conditions'])[$match[1]]??null;
            unset($safe['local_conditions'][$match[1]]);$term=$localTerm??'';
        }
        // All remaining opaque conditions are meaningful. Never query a reduced request.
        if ($safe['local_conditions']) throw new RuntimeException('AI_LOCAL_CONDITION_REQUIRED');
        if (($intent['object_kind']??null)==='position' || ($localTerm!==null && (($privateKindsByReference[$match[1]??'']??null)==='position'))) $intent['object_kind']='person';
        if ($intent['periods']!==[]) {
            $projection['dates']=[];
            $projection['date_terms']=$this->naturalPeriodTerms($intent['periods'],$today);
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
        $finish=function(array $compiled)use($context,$term,$contextDecisions,$owner,$id,$generation,$worker,$inheritedConstraints,$merged,$metricOptions,$replacementMetricOptions,$operationOptions,$replacementOperationOptions,$currentStoreRequested,$understanding,$safe,$summaries,$bindingCandidate):array {
            $compiled=$this->bindNamedStoreScope($compiled,$context,$term,$contextDecisions,$owner,$id,$generation,$worker);
            $compiled=$this->bindCurrentStoreScope($compiled,$context,$currentStoreRequested);
            if ($merged['replacement_confirmation']) {
                $remaining=array_values(array_filter($merged['pending'],static function(string $field): bool { return $field!=='business_filters'; }));
                $compiled=(new \app\services\ai\execution\AiContextReplacementGuidancePlanner())->start($compiled,$remaining,$inheritedConstraints,$replacementMetricOptions,$merged['prospective_intent'],$replacementOperationOptions,['understanding'=>$understanding]);
            } elseif ($merged['pending']) {
                $compiled=(new \app\services\ai\execution\AiPendingContextGuidancePlanner())->start($compiled,$merged['pending'],$inheritedConstraints,$metricOptions,$merged['prospective_intent'],$operationOptions,['understanding'=>$understanding]);
            } else {
                $compiled=IntentContextMerger::bind($compiled,$inheritedConstraints);
            }
            // The frontend never receives this key: issueGuidance persists it
            // only in the signed private envelope.  Keeping the exact
            // de-identified review input here lets a later controlled choice
            // be checked against the original accepted meaning.
            if (($compiled['kind']??null)==='clarification') {
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
            if (!in_array($shape,['summary','trend','ranking','comparison'],true)) throw new RuntimeException('AI_CONTEXT_DELTA_CONFLICT');
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
            throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
        }
        if (is_array($understanding) && ($understanding['status']??null)==='needs_clarification'
            && !$intent['needs_metric_choice'] && $intent['unresolved_fragments']===[] && !$merged['pending']) {
            throw new RuntimeException('AI_INTENT_UNRESOLVED');
        }
        // Any registered object dimension follows the same controlled path.
        // Object labels come from the runtime Skill; metrics and dimensions
        // come from the lower-layer registry.  No report page/object switch is
        // permitted here.
        if (!in_array($intent['object_kind'],['person','store','unknown'],true)) {
            $dimensionMetrics=\app\services\ai\execution\AiCapabilityGuidanceCatalog::discover($caps,$intent['object_kind'],'ranking');
            if ($localTerm!==null || !$dimensionMetrics) throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
            if ($intent['operation']!=='ranking') throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
            return $finish((new \app\services\ai\execution\AiDimensionGuidancePlanner())->start(
                $intent['object_kind'],$intent,$projection,$dimensionMetrics,$body['output_format'],$today
            ));
        }
        if ($intent['object_kind']==='person') {
            if (!$personMetrics) throw new RuntimeException('AI_PERSONNEL_PERMISSION_REQUIRED');
            // Resolve against a common authorized object catalog. Before selecting a
            // different metric, execution independently rechecks its own report grant.
            if (count($intent['metric_codes'])>1) throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
            $metric=$intent['metric_codes'][0]??null;
            if ($metric!==null && !isset($personMetrics[$metric])) throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
            $intent['metric_codes']=$metric===null?[]:[$metric];
            $catalog=$localCatalogs[$metric??array_key_first($personMetrics)];
            $objectCatalog=new \app\services\query\metric\AnalysisObjectCatalog($catalog['objects'],static function(){return true;});
            $objectTerm=$contextDecisions['store_scope']==='replace' && $contextDecisions['business_filters']==='inherit' ? '' : $term;
            $named=$objectCatalog->resolve($objectTerm,'person',$metric);
            // Exact local names may bind a person; a missing name is never fuzzily
            // replaced with someone else. Otherwise resolve actual position metadata.
            $exactPeople=array_values(array_filter($catalog['objects'],static function($o)use($objectTerm){return $o['kind']==='person' && $o['label']===$objectTerm;}));
            $objects=$exactPeople?$named:$objectCatalog->resolve($objectTerm,'position',$metric);
            $objects=IntentContextMerger::resolveSelection($objects,$catalog['objects'],$inheritedConstraints,'person',$metric);
            return $finish((new \app\services\ai\execution\AiAnalysisGuidancePlanner())->start($intent,$projection,$personMetrics,$objects,$body['output_format'],$today));
        }
        // No staff/customer/object restriction may become an unfiltered store query.
        if ($localTerm!==null || !in_array($intent['object_kind'],['store','unknown'],true)) throw new RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
        // Do not let a local word list select a metric or result shape.  The
        // natural-language model returns a candidate, and the compiler below
        // verifies it against the same registered contracts used by reports.
        $projection['signals']=array_values(array_unique(array_merge($intent['metric_codes'],[$intent['operation']])));
        if ($intent['needs_metric_choice']) $projection['signals'][]='ambiguous_metric';
        $projection['blocking_reason']=null;$projection['unresolved_condition']=false;$projection['semantic_intent']['constraints']=[];
        $caps['current_store_bound']=\app\services\ai\execution\AiAuthority::currentStoreId($context)!==null;
        $compiled=(new AiWorkflowPlanner())->compile($projection,['decision'=>'query','query_shape'=>$intent['operation'],'metric_codes'=>$intent['metric_codes'],'ranking'=>$intent['ranking']],$caps,$body['output_format'],$today);
        return $finish($compiled);
    }

    /** Builds the server-owned response shapes for one registered object. */
    private function registeredOperationOptions(array $capabilities,string $objectKind): array
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$objectKind)) return [];
        $options=[];
        foreach (\app\services\ai\execution\AiCapabilityGuidanceCatalog::discover($capabilities,$objectKind) as $metric=>$candidate) {
            $shapes=array_values(array_intersect(['summary','trend','ranking','comparison'],(array)($candidate['query_shapes']??[])));
            if ($shapes) $options[$metric]=$shapes;
        }
        ksort($options);
        return $options;
    }

    /** Builds only metric choices executable for the object and response shape. */
    private function registeredMetricOptions(array $capabilities,array $summaries,string $objectKind,?string $operation): array
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$objectKind)) return [];
        if ($operation!==null && !in_array($operation,['summary','trend','ranking','comparison'],true)) return [];
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
        return AiIntentResultContract::normalize($intent,$metricCodes,$allowedActions,$safeQuestion,$understanding);
    }

    /** Store only bounded structural metadata; neither question nor model text is retained. */
    private function recordModelDiagnostic(array $owner,string $id,int $generation,string $worker,AiContractException $error): void
    {
        $diagnostic=$error->diagnostic();
        if (!$diagnostic) $diagnostic=['stage'=>'intent_parse','predicate'=>'error_reason:'.strtolower($error->reason())];
        try { $this->runs->recordDiagnostic($owner,$id,$generation,$worker,$diagnostic); } catch (\Throwable $ignored) {}
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

    /**
     * Performs the semantic admission once for every candidate metric binding.
     * It deliberately runs before a pending context field is converted to a
     * private fallback, so all execution paths share the same admission gate.
     */
    private function reviewSemanticBinding(array $owner,string $id,int $generation,string $worker,array $safeQuestion,array $summaries,array $understanding,array $intent,array $configuration,callable $checkpoint,bool $customerConfirmedChoice=false): string
    {
        $this->runs->reserve($owner,$id,$generation,$worker,'stage_count');
        $reviewInput=['question'=>$safeQuestion['question'],'reference_date'=>$safeQuestion['reference_date']];
        $this->runs->reserve($owner,$id,$generation,$worker,'input_tokens',max(1,strlen(json_encode([$reviewInput,$understanding,$intent,$summaries]))+1024));
        $this->runs->reserve($owner,$id,$generation,$worker,'output_tokens',300);
        $this->runs->prepareAttempt($owner,$id,$generation,$worker,'review_binding','model',hash('sha256',json_encode([$reviewInput,$understanding,$intent,$summaries])),'siliconflow');
        $this->runs->sendAttempt($owner,$id,$generation,$worker,'review_binding');
        try {
            $checkpoint();
            $reply=$this->model
                ? call_user_func($this->model,$reviewInput,['candidate_binding'=>$intent,'capabilities'=>$summaries],$configuration,$checkpoint,null,'binding_verification',$understanding)
                : (new SiliconFlowClient())->verifyBinding($safeQuestion,$summaries,$understanding,$intent,$configuration['model'],$configuration['api_key'],30000,$checkpoint,$customerConfirmedChoice);
            $review=AiIntentResultContract::normalizeSemanticReview($reply['review']??null,$understanding);
            // Only the explicitly candidate-blind uniqueness pass may defer a
            // metric to the registered choice UI. A coverage reviewer has a
            // proposed binding and can only accept it or reject a conflicting
            // requirement. Treating an arbitrary metric_choice as a deferment
            // would erase exclusions before the final semantic gate.
            if ($review['decision']==='metric_choice' && !$customerConfirmedChoice
                && (($reply['review_kind']??null)==='candidate_blind_uniqueness')
                && AiIntentResultContract::canDeferMetricChoice($understanding,$intent,($safeQuestion['prior_query']??null)!==null)) {
                $this->runs->finishAttempt($owner,$id,$generation,$worker,'review_binding','SUCCEEDED',$reply['usage']['input_tokens']??null,$reply['usage']['output_tokens']??null);
                return 'metric_choice';
            }
            if ($review['decision']==='metric_choice') {
                $this->runs->recordDiagnostic($owner,$id,$generation,$worker,['stage'=>'binding_review','predicate'=>'unexpected_metric_choice']);
                throw new RuntimeException('AI_BINDING_SEMANTIC_REJECTED');
            }
            if ($review['decision']!=='accept') {
                $this->runs->recordDiagnostic($owner,$id,$generation,$worker,['stage'=>'binding_review','predicate'=>'semantic_requirement_rejected']);
                throw new RuntimeException('AI_BINDING_SEMANTIC_REJECTED');
            }
            $this->runs->finishAttempt($owner,$id,$generation,$worker,'review_binding','SUCCEEDED',$reply['usage']['input_tokens']??null,$reply['usage']['output_tokens']??null);
            return 'accept';
        } catch (\Throwable $error) {
            if ($error instanceof AiContractException) $this->recordModelDiagnostic($owner,$id,$generation,$worker,$error);
            $state=in_array($error->getMessage(),['AI_MODEL_RESULT_UNKNOWN','AI_CANCELLED','AI_AUTHORIZATION_CHANGED'],true)?'UNKNOWN':'FAILED';
            $this->runs->finishAttempt($owner,$id,$generation,$worker,'review_binding',$state);
            throw $error;
        }
    }

    /** Registry selects the frozen graph; the gateway supplies only trusted infrastructure adapters. */
    private function executeRegistered(array $context,array $owner,string $id,int $generation,string $worker,array $snapshot,array $plannerPlan): array
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
                $this->runs->progress($owner,$id,$generation,$worker,'RENDERING'); $answer=(new AiAnswerRenderer())->render($evidence); return ['answer'=>$answer];
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
                $guard(); $this->runs->progress($owner,$id,$generation,$worker,'PUBLISHING');
                [$evidenceRef,$answerRef]=$this->saveResults($owner,$id,$generation,$plan,$evidence,$answer,$trace);
                // ExportRuntime owns the source-bound Task receipt and dispatch UNKNOWN handling.
                $waiting=$this->exportRuntime()->queue($this->fresh($context),$owner,$this->runs->get($owner,$id,$generation),$worker,$evidenceRef,$answerRef,$evidence);
                return ['deferred'=>true];
            },
        ];
        $execution=(new AiRegisteredWorkflowExecutor($this->registry()))->execute($plan,$handlers,$checkpoint);
        if ($waiting!==null) return $waiting;
        $guard();
        // Persisting encrypted evidence and changing a Run to COMPLETED are
        // not rendering.  A distinct server-owned progress state lets a
        // stalled response be attributed to the handoff boundary without
        // retaining customer text or reinterpreting their request.
        $this->runs->progress($owner,$id,$generation,$worker,'PUBLISHING');
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

    private function queryService(array $context,?callable $checkpoint=null): MetricReadViewServices
    {
        return new MetricReadViewServices($this->views,function () use($context): array {
            $c=$this->fresh($context);
            if (empty($c['can_use'])) throw new RuntimeException('AI_PERMISSION_DENIED');
            return ['instance_id'=>$this->instance,'subject_ref'=>$this->identity($c),'terminal'=>$c['terminal'],'tenant_id'=>(string)($c['tenant_id']??0),
                'permission_version'=>$this->permissionHash($c),'report_capability_code'=>$c['report_capability_code'],'scope_provider_code'=>'current_report_scope_v1',
                'scope_mode'=>$c['scope_mode'],'store_ids'=>$c['store_ids'],
                'employee_id'=>(int)($c['employee_id']??0)];
        },$this->queryTransaction ?: [new \app\services\query\metric\MetricReadTransaction(10000,$checkpoint),'run'],null,$this->personnelObjects($context));
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
        if ($run['status']==='FAILED' && $context['terminal']==='platform' && !empty($this->fresh($context)['can_configure'])) {
            $diagnostic=$this->runs->runDiagnostic($owner,$run['run_id'],$run['generation']);
            if ($diagnostic!==null) $result['management_trace']=['model_diagnostic'=>$diagnostic];
        }
        return $result;
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
        if ($run['status']==='FAILED') return [
            'AI_WORKFLOW_DISABLED'=>'此项分析能力已由管理员停用，请联系平台管理员。',
            'AI_PERSONNEL_PERMISSION_REQUIRED'=>'当前账号尚无对应人员指标的可用查询权限，未读取或披露人员信息。',
            'AI_OBJECT_BINDING_UNAVAILABLE'=>'在当前可用范围内还不能确定您指的对象，未删减条件或改查全部。',
            'AI_OBJECT_SCOPE_TOO_LARGE'=>'涉及的人员范围较大，请先缩小到具体门店或岗位后再查询。',
            'AI_LOCAL_CONDITION_REQUIRED'=>'问题中有一项条件还不能在当前可用范围内安全核对；我没有删掉该条件或改查其他数据。请换一种日常说法补充这项条件后重试。',
            'AI_ANALYSIS_COMBINATION_UNAVAILABLE'=>'已识别分析方向，但当前底层尚不能完整执行这些对象、指标与条件的组合；没有替换指标或删减条件。',
            'AI_BINDING_SEMANTIC_REJECTED'=>'已理解您的问题，但系统未能可靠确认所选指标同时符合全部条件；本次未查询或替换为近似数据。请换一种说法后重试。',
            'AI_PROJECT_OBJECT_NOT_READY'=>'项目对象尚未接入项目解析、当前权限、筛选、统一查询与证据合同；已保留您的问题，未查询或替换为其他指标。',
            'AI_PRODUCT_OBJECT_NOT_READY'=>'产品对象尚未接入产品解析、当前权限、筛选、统一查询与证据合同；已保留您的问题，未查询或替换为其他指标。',
            'AI_CATEGORY_OBJECT_NOT_READY'=>'商品分类对象尚未接入当前配置快照、权限、筛选、统一查询与证据合同；已保留您的问题，未查询或替换为其他指标。',
            'AI_PARTNER_OBJECT_NOT_READY'=>'合作方对象尚未接入分类维度、当前权限、筛选、统一查询与证据合同；已保留您的问题，未查询或替换为其他对象。',
            'AI_MEMBER_OBJECT_NOT_READY'=>'会员对象尚未接入当前统一查询与证据合同；已保留您的问题，未查询或替换为其他对象。',
            'AI_INVENTORY_OBJECT_NOT_READY'=>'库存与耗用对象尚未接入门店权限、筛选、统一查询与证据合同；已保留您的问题，未查询或替换为其他指标。',
            'AI_OBJECT_CONTRACT_NOT_READY'=>'当前分析对象尚未接入对象解析、权限、筛选、统一查询与证据合同；未查询或替换条件。',
            'AI_DIMENSION_ACTION_CONTRACT_NOT_READY'=>'当前分析对象的这项评价方式尚未接入统一查询合同；未改用其他指标或删减条件。',
            'AI_INTENT_UNRESOLVED'=>'我还没准确理解这句话最想了解的经营情况。请换一种日常说法补充您想看的内容；已经确认的条件会保留，本次没有查询近似数据。',
            'AI_CAPABILITY_NOT_READY'=>'已识别您的需求，但对应的数据能力或筛选组合尚未接入，暂不能准确提供结果。',
            'AI_CONTEXT_REQUIRED'=>'这句追问缺少可核对的前文条件。请补充您想延续的对象、时间或查看结果，我会按当前权限重新查询。',
            'AI_RESULT_REFERENCE_UNAVAILABLE'=>'无法在原查询结果中安全确认您指的对象，未改用新结果中的同名或同序对象。请重新说明对象。',
            'AI_FOLLOWUP_CONDITION_REQUIRED'=>'我已找到上次查询，但还不能准确确认这次要改变的内容。请补充您要改看的对象、时间或查看方式；已确认条件会保留。',
            'AI_RANK_LIMIT_NOT_READY'=>'当前门店排行最多展示前20或后20项，暂不支持您要求的数量；本次未更改您的条件。',
            'AI_DIMENSION_RANK_LIMIT_NOT_READY'=>'当前分析对象最多支持前20、后20排行，暂不支持您要求的数量；本次未更改您的条件。',
            'AI_FUTURE_ACTUALS_UNAVAILABLE'=>'未来日期尚未发生实际业绩，暂不能提供该日期的实际数据，也未替换成今天或预测值。',
            'AI_DATA_COVERAGE_INCOMPLETE'=>'所选期间早于当前指标的事实数据起点，本次未缩短或替换日期范围，请选择较晚的期间。',
            'AI_DATE_INVALID'=>'日期范围不完整或无效，请重新选择开始和结束日期。',
            'AI_DATE_REVERSED'=>'开始日期不能晚于结束日期，请重新选择；本次未调整您的日期。',
            'AI_DATE_RANGE_TOO_LONG'=>'本次查询的时间跨度超过单次允许范围，请缩短期间后重试；本次未自动裁剪日期。',
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
            'ATTEMPT_UNKNOWN'=>'本次 AI 请求结果暂未确认，已停止继续调用，请稍后再问。',
            'AI_MODEL_RESPONSE_TRUNCATED'=>'本次 AI 理解结果未完整返回，系统已停止查询，请稍后重试。',
            'AI_MODEL_RESPONSE_ENVELOPE_INVALID'=>'本次 AI 返回格式异常，系统未执行查询，请稍后重试。',
            'AI_MODEL_INTENT_CONTRACT_INVALID'=>'本次查询未完成，系统没有读取或计算数据，请稍后重试。',
            'AI_JSON_SIZE_INVALID'=>'本次 AI 返回内容异常，系统未执行查询，请稍后重试。',
            'AI_JSON_OBJECT_REQUIRED'=>'本次 AI 返回内容异常，系统未执行查询，请稍后重试。',
            'AI_AUTHORIZATION_CHANGED'=>'您的权限或 AI 配置已变化，本次查询已停止，请重新提问。',
            'METRIC_QUERY_COVERAGE_UNAVAILABLE'=>'所选期间早于当前指标的事实数据起点，本次未缩短或替换日期范围，请选择较晚的期间。',
            'METRIC_QUERY_RANGE_INVALID'=>'日期范围不完整或无效，请重新选择开始和结束日期。',
            'METRIC_QUERY_RANGE_REVERSED'=>'开始日期不能晚于结束日期，请重新选择；本次未调整您的日期。',
            'METRIC_QUERY_RANGE_TOO_LONG'=>'本次查询的时间跨度超过单次允许范围，请缩短期间后重试；本次未自动裁剪日期。',
            'METRIC_QUERY_FUTURE_UNAVAILABLE'=>'未来日期尚未发生实际数据，本次未替换成今天或预测值。',
            'CAPACITY_STOPPED'=>'当前系统繁忙，本次任务已停止，请稍后再问。',
            'AI_RUN_DEADLINE'=>'本次查询已到时间上限，请缩小问题范围后重试。',
            'AI_EXECUTION_CLOCK_REGRESSED'=>'服务时间校验异常，本次查询已停止，请稍后重试。',
        ][$run['reason']]??'本次查询未完成，请稍后重试。';
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
            $reply=(new SiliconFlowClient())->probe($configuration['model'],$configuration['api_key'],10000,
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
