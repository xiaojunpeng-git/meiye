<?php
namespace app\services\ai\execution;

use app\services\ai\registry\AiBusinessRegistry;
use app\services\ai\registry\AiRegistryValue;
use app\services\query\metric\MetricQueryDatePolicy;
use app\services\query\metric\MetricQueryContractException;

/** Compiles a business-slot plan into a closed, version-frozen executable graph. */
final class AiRegisteredPlanCompiler
{
    private $registry;
    private $today;
    public function __construct(?AiBusinessRegistry $registry=null,?callable $today=null) { $this->registry=$registry??new AiBusinessRegistry(); $this->today=$today; }

    public function compile(array $plan,array $capabilities,array $options=[]): array
    {
        AiRegistryValue::exact($options,[],['run_budget_ms','remaining_execution_ms','finalization_reserve_ms']);
        $run=$options['run_budget_ms']??180000;
        $remaining=$options['remaining_execution_ms']??$run;
        $reserve=$options['finalization_reserve_ms']??5000;
        if (!is_int($run)||$run<180000||$run>300000||!is_int($remaining)||$remaining<1||$remaining>$run||!is_int($reserve)||$reserve<5000||$reserve>=$remaining) AiRegistryValue::fail('AI_WORKFLOW_BUDGET_INVALID');
        $definition=($plan['query_shape']??null)==='definition';
        $common=['schema_version','workflow_code','workflow_version','nodes','max_visits','max_tool_calls','compiled_run_hash'];
        AiRegistryValue::exact($plan,$definition?['query_shape','definition_metric_codes']:['query','output_format'],array_merge($common,$definition?['output_format']:[]));
        if (isset($plan['schema_version'])&&$plan['schema_version']!=='mohe-executable-workflow-v1') AiRegistryValue::fail('AI_PLAN_SCHEMA_INVALID');
        $format=$plan['output_format']??'screen';
        if (!in_array($format,['screen','screen_and_xlsx'],true)) AiRegistryValue::fail('AI_OUTPUT_FORMAT_INVALID');
        $snapshot=$this->registry->snapshot($capabilities);
        if (!in_array($format,$snapshot['output_formats'],true)) AiRegistryValue::fail('AI_EXPORT_NOT_READY');
        if ($definition) {
            if ($format!=='screen') AiRegistryValue::fail('AI_EXPORT_NOT_READY');
            $metrics=AiRegistryValue::strings($plan['definition_metric_codes'],2);
            if (!$metrics||array_diff($metrics,array_keys($snapshot['definitions']))) AiRegistryValue::fail('AI_METADATA_NOT_READY');
            $query=null; $workflowCode='wf_metric_definition';
            $contracts=array_intersect_key($snapshot['definitions'],array_flip($metrics));
        } else {
            if (!is_array($plan['query'])) AiRegistryValue::fail('AI_PLAN_SCHEMA_INVALID');
            $query=$this->query($plan['query'],$snapshot,$capabilities);
            $metrics=$query['metric_codes']; $workflowCode='wf_performance_'.$query['query_shape'];
            $contracts=array_intersect_key($snapshot['metrics'],array_flip($metrics));
        }
        // The third discovery gate is part of compilation, not a documentation-only catalog.
        $candidates=$this->registry->discover($snapshot,3,['workflow_codes'=>[$workflowCode],'metric_codes'=>$metrics]);
        if (count($candidates['items'])!==1||$candidates['items'][0]['workflow_code']!==$workflowCode) AiRegistryValue::fail('AI_WORKFLOW_NOT_READY');
        if (isset($plan['workflow_code'])&&!in_array($plan['workflow_code'],[$workflowCode,'wf_performance_snapshot'],true)) AiRegistryValue::fail('AI_WORKFLOW_NOT_REGISTERED');
        if ($definition && ($plan['workflow_code']??$workflowCode)!==$workflowCode) AiRegistryValue::fail('AI_WORKFLOW_NOT_REGISTERED');
        if (isset($plan['workflow_version'])&&$plan['workflow_version']!==$snapshot['workflows'][$workflowCode]['version']) AiRegistryValue::fail('AI_REGISTRY_VERSION_INVALID');
        if (isset($plan['nodes'])) {
            $legacy=$definition?['metric_catalog_read','metadata_guard','deterministic_definition']:['query_metric_summary','all_evidence_guard','deterministic_answer'];
            if ($plan['nodes']!==$legacy) AiRegistryValue::fail('AI_WORKFLOW_GRAPH_INVALID');
        }
        foreach (['max_visits'=>12,'max_tool_calls'=>8] as $key=>$max) if (isset($plan[$key])&&(!is_int($plan[$key])||$plan[$key]<1||$plan[$key]>$max)) AiRegistryValue::fail('AI_WORKFLOW_BUDGET_INVALID');
        // The old hash is not accepted as proof of compilation; always rebuild from registered nodes.
        $workflow=$this->registry->workflow($workflowCode);
        $nodes=$this->nodes($workflowCode,$format);
        $cost=array_sum(array_column($nodes,'timeout_ms'));
        $pathLimit=$workflow['max_path_ms']+($format==='screen_and_xlsx'?10000:0);
        if ($cost>$pathLimit||$pathLimit+$reserve>$remaining) AiRegistryValue::fail('AI_WORKFLOW_BUDGET_EXHAUSTED');
        $counters=['node_visit_count'=>count($nodes),'skill_execution_count'=>$workflow['scene']===null?0:1,'tool_call_count'=>0,'workflow_transition_count'=>count($nodes),'supplement_count'=>0];
        foreach ($nodes as $node) $counters['tool_call_count']+=$node['charges']['tool_call_count'];
        $compiled=['schema_version'=>'mohe-compiled-registry-v1','registry_version'=>$this->registry->version(),'registry_hash'=>$this->registry->fingerprint(),
            'capability_snapshot'=>$snapshot,'workflow_code'=>$workflowCode,'workflow_version'=>$workflow['version'],'scene_code'=>$workflow['scene'],
            'dependency_versions'=>$this->registry->dependencyVersions($workflowCode,$format==='screen_and_xlsx'),
            'query'=>$query,'definition_metric_codes'=>$definition?$metrics:[],'metric_contracts'=>$contracts,'output_format'=>$format,
            'nodes'=>$nodes,'max_visits'=>count($nodes),'max_tool_calls'=>$counters['tool_call_count'],
            'budget'=>['run_budget_ms'=>$run,'remaining_execution_ms'=>$remaining,'finalization_reserve_ms'=>$reserve,
                'worst_path_ms'=>$pathLimit,'counters'=>$counters],
            'supplement_policy'=>'no_registered_supplement_branch'];
        $compiled['compiled_run_hash']=AiRegistryValue::hash($compiled);
        return $compiled;
    }

    /** Executor rechecks structure/version, not merely a user-recomputable checksum. */
    public function assertCompiled(array $compiled): void
    {
        AiRegistryValue::exact($compiled,['schema_version','registry_version','registry_hash','capability_snapshot','workflow_code','workflow_version','scene_code','dependency_versions',
            'query','definition_metric_codes','metric_contracts','output_format','nodes','max_visits','max_tool_calls','budget','supplement_policy','compiled_run_hash']);
        $hash=$compiled['compiled_run_hash']; $body=$compiled; unset($body['compiled_run_hash']);
        if (!is_string($hash)||!hash_equals(AiRegistryValue::hash($body),$hash)||$compiled['schema_version']!=='mohe-compiled-registry-v1'
            ||$compiled['registry_hash']!==$this->registry->fingerprint()||$compiled['registry_version']!==$this->registry->version()) AiRegistryValue::fail('AI_COMPILED_PLAN_INVALID');
        $this->registry->assertSnapshot($compiled['capability_snapshot']);
        $workflow=$this->registry->workflow($compiled['workflow_code']);
        if ($compiled['nodes']!==$this->nodes($compiled['workflow_code'],$compiled['output_format'])||$compiled['workflow_version']!==$workflow['version']
            ||$compiled['dependency_versions']!==$this->registry->dependencyVersions($compiled['workflow_code'],$compiled['output_format']==='screen_and_xlsx')
            ||$compiled['scene_code']!==$workflow['scene']||$compiled['supplement_policy']!=='no_registered_supplement_branch') AiRegistryValue::fail('AI_COMPILED_PLAN_INVALID');
        $snapshot=$compiled['capability_snapshot'];
        $cap=['metric_codes'=>array_keys($snapshot['metrics']),'metric_readiness'=>[], 'query_shapes'=>['summary','trend','ranking','comparison','threshold_count','condition_count','condition_list'],
            'output_formats'=>$snapshot['output_formats'],'definition_metric_codes'=>array_keys($snapshot['definitions']),'metadata_readiness'=>[]];
        foreach ($snapshot['metrics'] as $code=>$metric) $cap['metric_readiness'][$code]=$metric+['ai_query_ready'=>true];
        foreach ($snapshot['definitions'] as $code=>$definition) $cap['metadata_readiness'][$code]=$definition+['user_ready'=>true];
        $plan=$compiled['query']===null?['query_shape'=>'definition','definition_metric_codes'=>$compiled['definition_metric_codes'],'output_format'=>$compiled['output_format']]:['query'=>$compiled['query'],'output_format'=>$compiled['output_format']];
        if ($compiled['query']!==null) $cap['store_ids']=$compiled['query']['store_ids']; // Scope is checked again by the authoritative query callback.
        $options=array_intersect_key($compiled['budget'],array_flip(['run_budget_ms','remaining_execution_ms','finalization_reserve_ms']));
        $rebuilt=$this->compile($plan,$cap,$options);
        if ($rebuilt!==$compiled) AiRegistryValue::fail('AI_COMPILED_PLAN_INVALID');
    }
    private function nodes(string $code,string $format): array
    {
        $workflow=$this->registry->workflow($code); $nodes=$workflow['nodes'];
        if ($format==='screen_and_xlsx') {
            if (!$workflow['allow_export']) AiRegistryValue::fail('AI_EXPORT_NOT_READY');
            $nodes[]=$this->registry->exportNode();
        } elseif ($format!=='screen') AiRegistryValue::fail('AI_OUTPUT_FORMAT_INVALID');
        foreach ($nodes as $index=>&$node) $node['charges']=['node_visit_count'=>1,'workflow_transition_count'=>1,
            'skill_execution_count'=>$index===0&&$workflow['scene']!==null?1:0,'tool_call_count'=>$node['tool']===null?0:1];
        unset($node); return $nodes;
    }
    private function query(array $query,array $snapshot,array $capabilities): array
    {
        if (!array_key_exists('aggregate_condition',$query)) $query['aggregate_condition']=null;
        // This field carries display-only facts for a ranking. It is derived
        // again below from the frozen registry, so a planner cannot smuggle a
        // second sorting metric or a caller-selected formula into a query.
        if (!array_key_exists('ranking_presentation_metrics',$query)) $query['ranking_presentation_metrics']=[];
        $conditionPopulation=in_array($query['query_shape']??null,['condition_count','condition_list'],true);
        AiRegistryValue::exact($query,['query_shape','metric_codes','start_date','end_date','compare_range','store_ids','business_filters','ranking','aggregate_condition','ranking_presentation_metrics'],
            $conditionPopulation?['condition_set']:[]);
        if (!in_array($query['query_shape'],['summary','trend','ranking','comparison','threshold_count','condition_count','condition_list'],true)) AiRegistryValue::fail('AI_QUERY_SHAPE_INVALID');
        // This is a bounded Reader batch, not a semantic requirement that a
        // customer must ask for four metrics.  A broad operating question can
        // be answered through several independently registered observations
        // in one consistent read, while every explicit query still uses only
        // the metrics its accepted meaning binds.
        $metrics=AiRegistryValue::strings($query['metric_codes'],AiOverviewMetricResolver::MAX_METRICS);
        if (!$metrics||array_diff($metrics,array_keys($snapshot['metrics']))) AiRegistryValue::fail('AI_METRIC_NOT_READY');
        $objectKind=$query['business_filters']['object_kind']??null;
        $person=$objectKind==='person';
        $conditionPopulation=in_array($query['query_shape'],['condition_count','condition_list'],true);
        $memberSelection=$objectKind==='member'&&isset($query['business_filters']['selection_ref']);
        if ($query['business_filters']!==[]) {
            if (!$person && count($metrics)!==1 && $query['query_shape']!=='summary' && !$conditionPopulation) AiRegistryValue::fail('AI_UNSUPPORTED_CONDITION');
            if ($person && (count($query['business_filters'])!==2 || !is_string($query['business_filters']['selection_ref']??null)
                || (!$conditionPopulation && !preg_match('/^((position|person):[1-9][0-9]*|role:craftsman|role:salesperson|cohort:metric_fact_participants)$/D',$query['business_filters']['selection_ref']))
                || ($conditionPopulation && $query['business_filters']['selection_ref']!=='cohort:active_personnel'))) AiRegistryValue::fail('AI_UNSUPPORTED_CONDITION');
            if ($memberSelection && (count($query['business_filters'])!==2 || !is_string($query['business_filters']['selection_ref'])
                || !preg_match('/^member:[1-9][0-9]*$/D',$query['business_filters']['selection_ref']))) AiRegistryValue::fail('AI_UNSUPPORTED_CONDITION');
            if (!$person&&!$memberSelection&&(!is_string($objectKind)||$query['business_filters']!==['object_kind'=>$objectKind])) AiRegistryValue::fail('AI_UNSUPPORTED_CONDITION');
        }
        // A comparison reads the same bounded registered store metrics for
        // both periods under one permission snapshot. Ranking and trends keep
        // their separate result-shape limits; object filters cannot inherit
        // this store-only expansion.
        if (count($metrics)>2 && !in_array($query['query_shape'],['summary','comparison','condition_count','condition_list'],true)) {
            AiRegistryValue::fail('AI_UNSUPPORTED_CONDITION');
        }
        foreach ($metrics as $metric) {
            $contract=$snapshot['metrics'][$metric];
            if ($person && $contract['filter_grain']!=='person') AiRegistryValue::fail('AI_UNSUPPORTED_CONDITION');
            if (!$person && $query['business_filters']!==[]) {
                $storeCondition=$conditionPopulation && $objectKind==='store'
                    && $query['business_filters']===['object_kind'=>'store']
                    && $contract['filter_grain']==='store'
                    && in_array('store',(array)($contract['condition_subjects']??[]),true);
                if (!$storeCondition) {
                    $expected=$memberSelection?['selection_ref']:[];
                    $matches=array_values(array_filter((array)($contract['analysis_dimension_contracts']??[]),static function($dimension)use($objectKind,$expected):bool {
                        return is_array($dimension) && ($dimension['object_kind']??null)===$objectKind && ($dimension['filter_keys']??null)===$expected;
                    }));
                    if (count($matches)!==1 || !in_array($query['query_shape'],['summary','ranking','threshold_count','condition_count','condition_list'],true)) AiRegistryValue::fail('AI_UNSUPPORTED_CONDITION');
                }
            }
            if (!$person && $query['business_filters']===[] && $contract['filter_grain']!=='store') AiRegistryValue::fail('AI_UNSUPPORTED_CONDITION');
        }
        if (!is_array($query['store_ids'])||!AiRegistryValue::isList($query['store_ids'])||count($query['store_ids'])>1000) AiRegistryValue::fail('AI_SCOPE_INVALID');
        foreach ($query['store_ids'] as $id) if (!is_int($id)||$id<1) AiRegistryValue::fail('AI_SCOPE_INVALID');
        if (count(array_unique($query['store_ids']))!==count($query['store_ids'])||array_diff($query['store_ids'],$capabilities['store_ids']??[])) AiRegistryValue::fail('AI_SCOPE_INVALID');
        $this->range($query['start_date'],$query['end_date']);
        if ($query['query_shape']==='comparison') {
            if (!is_array($query['compare_range'])) AiRegistryValue::fail('AI_DATE_INVALID');
            AiRegistryValue::exact($query['compare_range'],['start','end']);
            $this->range($query['compare_range']['start'],$query['compare_range']['end']);
        } elseif ($query['compare_range']!==null) AiRegistryValue::fail('AI_QUERY_SHAPE_INVALID');
        if ($query['query_shape']==='ranking') {
            if (!is_array($query['ranking'])) AiRegistryValue::fail('AI_QUERY_SHAPE_INVALID');
            AiRegistryValue::exact($query['ranking'],['direction','limit']);
            if (!in_array($query['ranking']['direction'],['top','bottom','top_and_bottom'],true)
                || !is_int($query['ranking']['limit'])||$query['ranking']['limit']<1||$query['ranking']['limit']>20) AiRegistryValue::fail('AI_UNSUPPORTED_CONDITION');
        } elseif ($query['ranking']!==null) AiRegistryValue::fail('AI_QUERY_SHAPE_INVALID');
        // `metric_codes` is intentionally a list in the query schema, while
        // a ranking has exactly one primary sort metric. Pass that scalar to
        // the presentation resolver; the remaining display columns are
        // registry-derived and cannot change rank order or query population.
        $expectedPresentation=$query['query_shape']==='ranking'
            ? AiRankingPresentationMetricResolver::resolve($snapshot['metrics'],$metrics[0],(string)($objectKind??'')) : [];
        if ($query['ranking_presentation_metrics']!==[] && $query['ranking_presentation_metrics']!==$expectedPresentation) {
            AiRegistryValue::fail('AI_UNSUPPORTED_CONDITION');
        }
        // Persist the registry-derived list in the signed query. A pure date
        // follow-up therefore keeps the same visible evidence columns without
        // asking the model to rediscover or rewrite the previous first answer.
        $query['ranking_presentation_metrics']=$expectedPresentation;
        if ($query['query_shape']==='threshold_count') {
            $condition=$query['aggregate_condition'];$keys=is_array($condition)?array_keys($condition):[];sort($keys,SORT_STRING);
            if ($objectKind!=='member'||$query['business_filters']!==['object_kind'=>'member']
                ||$keys!==['aggregation','amount_cents','operator','subject']||($condition['subject']??null)!=='member'
                ||($condition['aggregation']??null)!=='period_total'||!in_array($condition['operator']??null,['gte','gt','lte','lt','eq'],true)
                ||!is_int($condition['amount_cents']??null)||$condition['amount_cents']<1||$condition['amount_cents']>100000000000) AiRegistryValue::fail('AI_UNSUPPORTED_CONDITION');
            $threshold=$snapshot['metrics'][$metrics[0]]['threshold_count']??null;
            if (!is_array($threshold)||($threshold['subject_dimension']??null)!=='member'||($threshold['aggregation']??null)!=='period_total'
                ||!in_array($condition['operator'],$threshold['operators']??[],true)) AiRegistryValue::fail('AI_UNSUPPORTED_CONDITION');
        } elseif (!$conditionPopulation && $query['aggregate_condition']!==null) AiRegistryValue::fail('AI_QUERY_SHAPE_INVALID');
        if ($conditionPopulation) {
            $set=$query['condition_set'];$keys=is_array($set)?array_keys($set):[];sort($keys,SORT_STRING);
            $conditionSubject=$set['subject']??null;
            $expectedFilters=$conditionSubject==='person'
                ? ['object_kind'=>'person','selection_ref'=>'cohort:active_personnel']
                : ['object_kind'=>$conditionSubject];
            if (!in_array($conditionSubject,['person','member','store','order','sale_line','card','project','product'],true)||$query['business_filters']!==$expectedFilters
                ||$keys!==['conditions','relation','subject']||$conditionSubject!==$objectKind
                ||!in_array($set['relation']??null,['all','any'],true)||!is_array($set['conditions']??null)
                ||count($set['conditions'])<1||count($set['conditions'])>4||array_keys($set['conditions'])!==range(0,count($set['conditions'])-1)) AiRegistryValue::fail('AI_UNSUPPORTED_CONDITION');
            $metrics=[];
            foreach ($set['conditions'] as $condition) {
                $keys=is_array($condition)?array_keys($condition):[];sort($keys,SORT_STRING);
                if ($keys!==['metric_code','operator','value']||!is_string($condition['metric_code']??null)||!in_array($condition['operator']??null,['gte','gt','lte','lt','eq'],true)
                    ||!is_int($condition['value']??null)||abs($condition['value'])>100000000000) AiRegistryValue::fail('AI_UNSUPPORTED_CONDITION');
                $metrics[]=$condition['metric_code'];
            }
            if (count(array_unique($metrics))!==count($metrics)||$metrics!==$query['metric_codes']) AiRegistryValue::fail('AI_UNSUPPORTED_CONDITION');
            if (!is_array($query['aggregate_condition'])) AiRegistryValue::fail('AI_UNSUPPORTED_CONDITION');
            try {
                $canonical=(new AiConditionSetCompiler())->compile($query['aggregate_condition'],$capabilities,$query['query_shape']);
            } catch (\Throwable $error) { AiRegistryValue::fail('AI_UNSUPPORTED_CONDITION'); }
            if ($canonical!==$set) AiRegistryValue::fail('AI_UNSUPPORTED_CONDITION');
        } elseif (array_key_exists('condition_set',$query)) AiRegistryValue::fail('AI_QUERY_SHAPE_INVALID');
        if ($conditionPopulation && (!in_array($objectKind,['person','member','store','order','sale_line','card','project','product'],true)||$query['ranking']!==null||$query['compare_range']!==null)) AiRegistryValue::fail('AI_UNSUPPORTED_CONDITION');
        if ($memberSelection && ($query['query_shape']!=='summary'||count($metrics)!==1)) AiRegistryValue::fail('AI_UNSUPPORTED_CONDITION');
        foreach ($metrics as $metric) {
            $contract=$snapshot['metrics'][$metric];
            if (!in_array($query['query_shape'],$contract['query_shapes'],true)) AiRegistryValue::fail('AI_QUERY_SHAPE_INVALID');
            $this->range($query['start_date'],$query['end_date'],$contract['coverage_start']);
            if ($query['compare_range']!==null) $this->range($query['compare_range']['start'],$query['compare_range']['end'],$contract['coverage_start']);
        }
        return $query;
    }
    private function range($start,$end,?string $coverageStart=null): void
    {
        try { MetricQueryDatePolicy::assertExecutable(['start'=>$start,'end'=>$end],$coverageStart,$this->today?call_user_func($this->today):null); }
        catch (MetricQueryContractException $error) { AiRegistryValue::fail(MetricQueryDatePolicy::aiReason($error->getErrorCode())); }
    }
}
