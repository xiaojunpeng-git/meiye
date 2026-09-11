<?php
namespace app\services\ai\registry;

/** Registry is source-owned. Capability arguments are server authority, never HTTP input. */
final class AiBusinessRegistry
{
    private $manifest;
    public function __construct(?array $manifest=null)
    {
        $this->manifest=$manifest??AiBusinessManifest::definitions();
        $this->validate();
    }
    public function version(): string { return $this->manifest['registry_version']; }
    public function fingerprint(): string { return AiRegistryValue::hash($this->manifest); }
    public function workflow(string $code): array
    {
        if (!isset($this->manifest['workflows'][$code])) AiRegistryValue::fail('AI_WORKFLOW_NOT_REGISTERED');
        return $this->manifest['workflows'][$code];
    }
    /**
     * The model receives this bounded projection of the exact Skill that the
     * registry has validated.  It is business guidance only: metrics, objects,
     * permissions and executable workflows remain independently server-gated.
     */
    public function modelSkill(string $sceneCode): array
    {
        $scene=$this->manifest['scenes'][$sceneCode]??null;
        if (!is_array($scene)) AiRegistryValue::fail('AI_SKILL_NOT_REGISTERED');
        return [
            'skill_code'=>$scene['skill_code'], 'skill_version'=>$scene['skill_version'],
            'skill_source_hash'=>$scene['skill_source_hash'], 'label'=>$scene['label'],
            'instructions'=>$scene['instructions'],
        ];
    }

    /** The language layer and the selected business layer are separate Skills. */
    public function modelSkills(string $sceneCode): array
    {
        $intent=AiSkillDocument::intentUnderstanding();
        return [
            'intent_understanding'=>[
                'skill_code'=>$intent['skill_code'],'skill_version'=>$intent['version'],
                'skill_source_hash'=>$intent['source_hash'],'label'=>$intent['label'],'instructions'=>$intent['markdown'],
            ],
            'business'=>$this->modelSkill($sceneCode),
        ];
    }
    public function exportNode(): array { return $this->manifest['export_node']; }
    public function dependencyVersions(string $code,bool $export): array
    {
        $workflow=$this->workflow($code);
        $versions=['workflow'=>[$code=>$workflow['version']],'scene'=>[],'skill'=>[],'skill_source'=>[],'action'=>[],'tool'=>[]];
        if ($workflow['scene']!==null) {
            $scene=$this->manifest['scenes'][$workflow['scene']];
            $versions['scene'][$workflow['scene']]=$scene['version'];
            $versions['skill'][$scene['skill_code']]=$scene['skill_version'];
            $versions['skill_source'][$scene['skill_code']]=$scene['skill_source_hash'];
        }
        if ($workflow['action']!==null) $versions['action'][$workflow['action']]=$this->manifest['actions'][$workflow['action']]['version'];
        $nodes=$workflow['nodes'];
        if ($export) { $nodes[]=$this->exportNode(); $versions['action']['verified_result_export']=$this->manifest['actions']['verified_result_export']['version']; }
        foreach ($nodes as $node) if ($node['tool']!==null) $versions['tool'][$node['tool']]=$this->manifest['tools'][$node['tool']]['version'];
        return $versions;
    }
    public function handlers(): array
    {
        $handlers=[];
        foreach ($this->manifest['workflows'] as $workflow) foreach ($workflow['nodes'] as $node) $handlers[]=$node['handler'];
        $handlers[]=$this->manifest['export_node']['handler'];
        return array_values(array_unique($handlers));
    }

    /** Deliberately separate discoverable business meaning from currently executable contracts. */
    public function snapshot(array $capabilities): array
    {
        $codes=AiRegistryValue::strings($capabilities['metric_codes']??[],32);
        $shapes=AiRegistryValue::strings($capabilities['query_shapes']??[],8);
        $formats=AiRegistryValue::strings($capabilities['output_formats']??['screen'],2);
        if (array_diff($shapes,['summary','trend','ranking','comparison']) || array_diff($formats,['screen','screen_and_xlsx'])) AiRegistryValue::fail('AI_CAPABILITY_INVALID');
        $metrics=[];
        foreach ($codes as $code) {
            $item=$capabilities['metric_readiness'][$code]??null;
            if (!is_array($item)||($item['ai_query_ready']??null)!==true) continue;
            foreach (['metric_version','mapping_version','source_metric_version','coverage_start'] as $key) {
                if (!is_string($item[$key]??null)||$item[$key]===''||strlen($item[$key])>160) AiRegistryValue::fail('AI_METRIC_CONTRACT_INCOMPLETE');
            }
            $person=($item['filter_grain']??null)==='person';
            if ((!$person && (($item['filter_grain']??null)!=='store'||($item['business_filters']??null)!==[]))
                || ($person && ($item['business_filters']??null)!==['selection_ref'])
                ||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$item['coverage_start'])) AiRegistryValue::fail('AI_METRIC_CONTRACT_INCOMPLETE');
            $dimensions=AiRegistryValue::strings($item['analysis_dimensions']??[],16);
            if (!in_array('store',$dimensions,true)) AiRegistryValue::fail('AI_METRIC_CONTRACT_INCOMPLETE');
            $dimensionContracts=$this->dimensionContracts($item['analysis_dimension_contracts']??[]);
            $coverage=\DateTimeImmutable::createFromFormat('!Y-m-d',$item['coverage_start']);
            if (!$coverage||$coverage->format('Y-m-d')!==$item['coverage_start']) AiRegistryValue::fail('AI_METRIC_CONTRACT_INCOMPLETE');
            // This release cannot inherit a new metric merely because a lower catalog gained it.
            $registered=\app\services\query\metric\MetricReadViewServices::metricCapabilities();
            if (!isset($registered[$code]) || !$registered[$code]['ai_query_ready']) continue;
            if (($registered[$code]['filter_grain']??null)!==($item['filter_grain']??null)
                || ($registered[$code]['business_filters']??null)!==($item['business_filters']??null)
                || ($registered[$code]['analysis_dimensions']??null)!==$dimensions
                || $this->dimensionContracts($registered[$code]['analysis_dimension_contracts']??[])!==$dimensionContracts) AiRegistryValue::fail('AI_METRIC_CONTRACT_INCOMPLETE');
            $available=array_values(array_intersect($shapes,AiRegistryValue::strings($item['query_shapes']??[],8)));
            if (!$available) continue;
            sort($available,SORT_STRING);
            $metrics[$code]=['metric_code'=>$code,'name'=>(string)($item['name']??$code),'metric_version'=>$item['metric_version'],
                'mapping_version'=>$item['mapping_version'],'source_metric_version'=>$item['source_metric_version'],
                'query_shapes'=>$available,'coverage_start'=>$item['coverage_start'],'filter_grain'=>$person?'person':'store','business_filters'=>$person?['selection_ref']:[],
                'analysis_dimensions'=>$dimensions,'analysis_dimension_contracts'=>$dimensionContracts];
        }
        ksort($metrics,SORT_STRING);
        $definitions=[];
        foreach (AiRegistryValue::strings($capabilities['definition_metric_codes']??[],32) as $code) {
            $item=$capabilities['metadata_readiness'][$code]??null;
            if (!isset($metrics[$code])||!is_array($item)||($item['user_ready']??null)!==true) continue;
            foreach (['metric_version','description_ref'] as $key) if (!is_string($item[$key]??null)||$item[$key]===''||strlen($item[$key])>160) AiRegistryValue::fail('AI_METADATA_CONTRACT_INCOMPLETE');
            $definitions[$code]=['metric_code'=>$code,'metric_version'=>$item['metric_version'],'description_ref'=>$item['description_ref']];
        }
        ksort($definitions,SORT_STRING); sort($formats,SORT_STRING);
        $workflows=[];
        foreach ($this->manifest['workflows'] as $code=>$workflow) {
            $enabled=$workflow['query_shape']==='definition' ? (bool)$definitions : false;
            foreach ($metrics as $metric) if (in_array($workflow['query_shape'],$metric['query_shapes'],true)) $enabled=true;
            if ($enabled) $workflows[$code]=['version'=>$workflow['version'],'scene'=>$workflow['scene'],'action'=>$workflow['action'],'query_shape'=>$workflow['query_shape']];
        }
        $snapshot=['schema_version'=>'mohe-capability-registry-v1','registry_version'=>$this->version(),'registry_hash'=>$this->fingerprint(),
            'metrics'=>$metrics,'definitions'=>$definitions,'output_formats'=>$formats,'workflows'=>$workflows];
        $snapshot['snapshot_hash']=AiRegistryValue::hash($snapshot);
        return $snapshot;
    }

    /** Bounded server-side discovery. Output is NOT automatically an external-model payload. */
    public function discover(array $snapshot,int $level,array $selectors=[]): array
    {
        $this->assertSnapshot($snapshot);
        AiRegistryValue::exact($selectors,[],['scene_codes','metric_codes','workflow_codes']);
        foreach ($selectors as $values) AiRegistryValue::strings($values,8);
        if ($level===1) {
            if (isset($selectors['metric_codes'])||isset($selectors['workflow_codes'])) AiRegistryValue::fail('AI_DISCOVERY_LEVEL_INVALID');
            $items=[];
            foreach ($this->manifest['scenes'] as $code=>$scene) {
                if (isset($selectors['scene_codes'])&&!in_array($code,$selectors['scene_codes'],true)) continue;
                $legal=[]; foreach ($snapshot['workflows'] as $workflow=>$record) if ($record['scene']===$code) $legal[]=$workflow;
                if ($legal) $items[]=['scene_code'=>$code,'version'=>$scene['version'],'skill_code'=>$scene['skill_code'],'skill_version'=>$scene['skill_version'],
                    'label'=>$scene['label'],'skill_source_hash'=>$scene['skill_source_hash']];
            }
            return ['level'=>1,'registry_version'=>$this->version(),'items'=>$items,'metadata_explanation_available'=>(bool)$snapshot['definitions']];
        }
        if ($level===2) {
            if (isset($selectors['workflow_codes'])) AiRegistryValue::fail('AI_DISCOVERY_LEVEL_INVALID');
            $items=[];
            foreach ($snapshot['metrics'] as $code=>$metric) {
                if (isset($selectors['metric_codes'])&&!in_array($code,$selectors['metric_codes'],true)) continue;
                if (isset($selectors['scene_codes'])) {
                    $shapes=[];
                    foreach ($snapshot['workflows'] as $workflow) if (in_array($workflow['scene'],$selectors['scene_codes'],true)) $shapes[]=$workflow['query_shape'];
                    $metric['query_shapes']=array_values(array_intersect($metric['query_shapes'],$shapes));
                    if (!$metric['query_shapes']) continue;
                }
                $items[]=$metric;
            }
            return ['level'=>2,'snapshot_hash'=>$snapshot['snapshot_hash'],'items'=>$items,'complete_filter_contract'=>'store_scope_only'];
        }
        if ($level!==3) AiRegistryValue::fail('AI_DISCOVERY_LEVEL_INVALID');
        $items=[];
        foreach ($snapshot['workflows'] as $code=>$record) {
            if (isset($selectors['workflow_codes'])&&!in_array($code,$selectors['workflow_codes'],true)) continue;
            if (isset($selectors['scene_codes'])&&!in_array($record['scene'],$selectors['scene_codes'],true)) continue;
            if (isset($selectors['metric_codes'])) {
                $available=$record['query_shape']==='definition' ? array_keys($snapshot['definitions']) : array_keys(array_filter($snapshot['metrics'],function($m)use($record){return in_array($record['query_shape'],$m['query_shapes'],true);}));
                if (array_diff($selectors['metric_codes'],$available)) continue;
            }
            $workflow=$this->workflow($code);
            $scene=$record['scene']===null?null:$this->manifest['scenes'][$record['scene']];
            $items[]=['workflow_code'=>$code,'version'=>$record['version'],'scene_code'=>$record['scene'],'action_code'=>$record['action'],
                'skill_code'=>$scene['skill_code']??null,'skill_version'=>$scene['skill_version']??null,
                'query_shape'=>$record['query_shape'],'input_schema'=>$workflow['nodes'][0]['input_schema'],'max_path_ms'=>$workflow['max_path_ms']];
        }
        return ['level'=>3,'snapshot_hash'=>$snapshot['snapshot_hash'],'items'=>$items];
    }
    public function assertSnapshot(array $snapshot): void
    {
        AiRegistryValue::exact($snapshot,['schema_version','registry_version','registry_hash','metrics','definitions','output_formats','workflows','snapshot_hash']);
        $hash=$snapshot['snapshot_hash']; unset($snapshot['snapshot_hash']);
        if (!is_string($hash)||!hash_equals(AiRegistryValue::hash($snapshot),$hash)||$snapshot['registry_version']!==$this->version()||$snapshot['registry_hash']!==$this->fingerprint()) AiRegistryValue::fail('AI_CAPABILITY_CHANGED');
    }

    /** Runtime capability details that are safe to freeze into a plan snapshot. */
    private function dimensionContracts($contracts): array
    {
        if (!is_array($contracts)||!AiRegistryValue::isList($contracts)||count($contracts)>16) AiRegistryValue::fail('AI_METRIC_CONTRACT_INCOMPLETE');
        $out=[];
        foreach ($contracts as $contract) {
            AiRegistryValue::exact($contract,['dimension','object_kind','object_label','relation_role','action_codes','filter_keys']);
            foreach (['dimension','object_kind','relation_role'] as $key) {
                if (!is_string($contract[$key]??null)||!preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$contract[$key])) AiRegistryValue::fail('AI_METRIC_CONTRACT_INCOMPLETE');
            }
            if (!is_string($contract['object_label']??null)||trim($contract['object_label'])===''||mb_strlen($contract['object_label'],'UTF-8')>80) AiRegistryValue::fail('AI_METRIC_CONTRACT_INCOMPLETE');
            $actions=AiRegistryValue::strings($contract['action_codes']??[],8);
            if (!$actions) AiRegistryValue::fail('AI_METRIC_CONTRACT_INCOMPLETE');
            $filters=AiRegistryValue::strings($contract['filter_keys']??[],8);
            $out[]=['dimension'=>$contract['dimension'],'object_kind'=>$contract['object_kind'],'object_label'=>$contract['object_label'],'relation_role'=>$contract['relation_role'],'action_codes'=>$actions,'filter_keys'=>$filters];
        }
        usort($out,static function(array $left,array $right): int { return [$left['object_kind'],$left['dimension']] <=> [$right['object_kind'],$right['dimension']]; });
        if (count(array_unique(array_map(static function(array $item): string { return $item['object_kind'].'|'.$item['dimension']; },$out)))!==count($out)) AiRegistryValue::fail('AI_METRIC_CONTRACT_INCOMPLETE');
        return $out;
    }
    private function validate(): void
    {
        $m=$this->manifest;
        AiRegistryValue::exact($m,['registry_version','semantic_resource_hash','intent_contract','schemas','tools','actions','scenes','workflows','export_node']);
        $intentContract=$m['intent_contract']??null;
        AiRegistryValue::exact(is_array($intentContract)?$intentContract:[],['code','version','hash']);
        if ($intentContract['code']!=='intent_result'||$intentContract['version']!==\app\services\ai\contract\AiIntentResultContract::VERSION
            ||!preg_match('/^[a-f0-9]{64}$/D',$intentContract['hash'])) AiRegistryValue::fail('AI_REGISTRY_SCHEMA_INVALID');
        if (!is_string($m['semantic_resource_hash']) || !preg_match('/^[a-f0-9]{64}$/D',$m['semantic_resource_hash'])) AiRegistryValue::fail('AI_REGISTRY_VERSION_INVALID');
        if (!is_string($m['registry_version'])||!preg_match('/^[a-z0-9_-]{1,80}$/D',$m['registry_version'])) AiRegistryValue::fail('AI_REGISTRY_INVALID');
        $schemas=AiRegistryValue::strings($m['schemas'],32);
        foreach (['tools'=>16,'actions'=>32,'scenes'=>16,'workflows'=>16] as $kind=>$max) {
            if (!is_array($m[$kind])||!$m[$kind]||count($m[$kind])>$max) AiRegistryValue::fail('AI_REGISTRY_INVALID');
            foreach ($m[$kind] as $code=>$item) if (!is_string($code)||!preg_match('/^[a-z][a-z0-9_]{0,79}$/D',$code)||!is_array($item)||!is_int($item['version']??null)||$item['version']<1) AiRegistryValue::fail('AI_REGISTRY_VERSION_INVALID');
        }
        foreach ($m['tools'] as $code=>$tool) {
            AiRegistryValue::exact($tool,['version','handler','input_schema','output_schema','permission_contract','side_effect','timeout_ms','idempotency','retry']);
            if (!in_array($tool['handler'],['unified_metric_query','metric_catalog_read','verified_export_create'],true)||$tool['handler']!==$code
                ||!in_array($tool['input_schema'],$schemas,true)||!in_array($tool['output_schema'],$schemas,true)
                ||!in_array($tool['side_effect'],['read_only','artifact_only'],true)||$tool['timeout_ms']!==10000
                ||$tool['idempotency']!=='run_generation_node_attempt'||$tool['retry']!=='never_automatically'
                ||!is_string($tool['permission_contract'])||$tool['permission_contract']==='') AiRegistryValue::fail('AI_TOOL_CONTRACT_INVALID');
        }
        foreach ($m['actions'] as $action) {
            AiRegistryValue::exact($action,['version','query_shape','tool','input_schema','output_schema']);
            $tool=$m['tools'][$action['tool']]??null;
            if (!$tool||$tool['input_schema']!==$action['input_schema']||$tool['output_schema']!==$action['output_schema']) AiRegistryValue::fail('AI_REGISTRY_DEPENDENCY_INVALID');
        }
        $skillCodes=[];
        foreach ($m['scenes'] as $scene) {
            AiRegistryValue::exact($scene,['version','skill_code','skill_version','skill_source_hash','skill_source_path','label','instructions','actions']);
            if (!is_string($scene['skill_code'])||!preg_match('/^skill_[a-z0-9_]{1,73}$/D',$scene['skill_code'])||in_array($scene['skill_code'],$skillCodes,true)
                ||!is_int($scene['skill_version'])||$scene['skill_version']<1) AiRegistryValue::fail('AI_REGISTRY_VERSION_INVALID');
            if (!is_string($scene['skill_source_hash']) || !preg_match('/^[a-f0-9]{64}$/D',$scene['skill_source_hash'])
                || !is_string($scene['skill_source_path']) || !preg_match('#^app/services/ai/skills/[a-z_]+/SKILL\.md$#D',$scene['skill_source_path'])
                || !is_string($scene['instructions']) || trim($scene['instructions'])==='' || strlen($scene['instructions'])>32768) AiRegistryValue::fail('AI_REGISTRY_VERSION_INVALID');
            $skillCodes[]=$scene['skill_code'];
            if (array_diff(AiRegistryValue::strings($scene['actions'],8),array_keys($m['actions']))) AiRegistryValue::fail('AI_REGISTRY_DEPENDENCY_INVALID');
        }
        foreach ($m['workflows'] as $workflow) {
            AiRegistryValue::exact($workflow,['version','scene','action','query_shape','entry','terminal','allow_export','max_path_ms','nodes']);
            if (!is_int($workflow['max_path_ms'])||$workflow['max_path_ms']<1||$workflow['max_path_ms']>55000||!is_bool($workflow['allow_export'])) AiRegistryValue::fail('AI_WORKFLOW_BUDGET_INVALID');
            if ($workflow['scene']!==null && (!isset($m['scenes'][$workflow['scene']])||!in_array($workflow['action'],$m['scenes'][$workflow['scene']]['actions'],true))) AiRegistryValue::fail('AI_REGISTRY_DEPENDENCY_INVALID');
            if ($workflow['action']!==null && ($m['actions'][$workflow['action']]['query_shape']??null)!==$workflow['query_shape']) AiRegistryValue::fail('AI_REGISTRY_DEPENDENCY_INVALID');
            if (!is_array($workflow['nodes'])||!AiRegistryValue::isList($workflow['nodes'])||!$workflow['nodes']||count($workflow['nodes'])>12) AiRegistryValue::fail('AI_WORKFLOW_GRAPH_INVALID');
            $seen=[]; $cost=0;
            foreach ($workflow['nodes'] as $node) {
                $this->validateNode($node,$schemas);
                if (isset($seen[$node['id']])||array_diff($node['depends_on'],array_keys($seen))) AiRegistryValue::fail('AI_WORKFLOW_GRAPH_INVALID');
                foreach ($node['depends_on'] as $dependency) if ($seen[$dependency]['output_schema']!==$node['input_schema']) AiRegistryValue::fail('AI_REGISTRY_SCHEMA_INCOMPATIBLE');
                $seen[$node['id']]=$node; $cost+=$node['timeout_ms'];
            }
            if ($workflow['entry']!==$workflow['nodes'][0]['id']||$workflow['terminal']!==end($workflow['nodes'])['id']||$cost>$workflow['max_path_ms']) AiRegistryValue::fail('AI_WORKFLOW_GRAPH_INVALID');
        }
        $this->validateNode($m['export_node'],$schemas);
        if ($m['export_node']['depends_on']!==['evidence','render']||$m['export_node']['tool']!=='verified_export_create') AiRegistryValue::fail('AI_WORKFLOW_GRAPH_INVALID');
    }
    private function validateNode(array $node,array $schemas): void
    {
        AiRegistryValue::exact($node,['id','handler','depends_on','input_schema','output_schema','tool','timeout_ms','max_visits']);
        if (!is_string($node['id'])||!preg_match('/^[a-z][a-z0-9_]{0,39}$/D',$node['id'])||!in_array($node['handler'],['unified_metric_query','all_evidence_guard','deterministic_answer','verified_export_create','metric_catalog_read','metadata_guard','deterministic_definition'],true)
            ||!in_array($node['input_schema'],$schemas,true)||!in_array($node['output_schema'],$schemas,true)||$node['max_visits']!==1
            ||!is_int($node['timeout_ms'])||$node['timeout_ms']<1||$node['timeout_ms']>10000) AiRegistryValue::fail('AI_WORKFLOW_NODE_INVALID');
        AiRegistryValue::strings($node['depends_on'],12);
        if ($node['tool']!==null) {
            $tool=$this->manifest['tools'][$node['tool']]??null;
            if (!$tool||$node['handler']!==$tool['handler']||$node['input_schema']!==$tool['input_schema']||$node['output_schema']!==$tool['output_schema']||$node['timeout_ms']>$tool['timeout_ms']) AiRegistryValue::fail('AI_REGISTRY_DEPENDENCY_INVALID');
        }
    }
}
