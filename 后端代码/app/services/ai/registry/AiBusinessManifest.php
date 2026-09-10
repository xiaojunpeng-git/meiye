<?php
namespace app\services\ai\registry;

/** Versioned source-owned declarations, never executable text supplied by a model. */
final class AiBusinessManifest
{
    public static function definitions(): array
    {
        require_once dirname(__DIR__).'/semantic/AiSemanticVocabulary.php';
        require_once __DIR__.'/AiSkillDocument.php';
        $storeOperations=AiSkillDocument::storeOperations();
        $intentUnderstanding=AiSkillDocument::intentUnderstanding();
        $tool=function(string $handler,string $input,string $output,string $permission,string $effect):array {
            return ['version'=>1,'handler'=>$handler,'input_schema'=>$input,'output_schema'=>$output,
                'permission_contract'=>$permission,'side_effect'=>$effect,'timeout_ms'=>10000,
                'idempotency'=>'run_generation_node_attempt','retry'=>'never_automatically'];
        };
        $action=function(string $shape,string $tool):array {
            $input=$shape==='export'?'verified_result':($shape==='definition'?'definition_input':'query_input');
            $output=$shape==='export'?'export_result':($shape==='definition'?'metadata_evidence':'business_evidence');
            return ['version'=>1,'query_shape'=>$shape,'tool'=>$tool,'input_schema'=>$input,'output_schema'=>$output];
        };
        $node=function(string $id,string $handler,array $dependencies,string $input,string $output,?string $tool=null,int $timeout=1000):array {
            return ['id'=>$id,'handler'=>$handler,'depends_on'=>$dependencies,'input_schema'=>$input,'output_schema'=>$output,
                'tool'=>$tool,'timeout_ms'=>$timeout,'max_visits'=>1];
        };
        $workflows=[];
        foreach (['summary'=>'performance_summary','trend'=>'performance_trend','ranking'=>'registered_metric_ranking','comparison'=>'performance_comparison'] as $shape=>$actionId) {
            $workflows['wf_performance_'.$shape]=['version'=>3,'scene'=>'store_operations',
                'action'=>$actionId,'query_shape'=>$shape,'entry'=>'query','terminal'=>'render','allow_export'=>true,
                'max_path_ms'=>40000,'nodes'=>[
                    $node('query','unified_metric_query',[],'query_input','business_evidence','unified_metric_query',10000),
                    $node('evidence','all_evidence_guard',['query'],'business_evidence','verified_result',null,10000),
                    $node('render','deterministic_answer',['evidence'],'verified_result','answer_result',null,1000),
                ]];
        }
        $workflows['wf_metric_definition']=['version'=>2,'scene'=>'store_operations','action'=>'metric_definition_read','query_shape'=>'definition','entry'=>'catalog','terminal'=>'render','allow_export'=>false,
            'max_path_ms'=>15000,'nodes'=>[
                $node('catalog','metric_catalog_read',[],'definition_input','metadata_evidence','metric_catalog_read',10000),
                $node('evidence','metadata_guard',['catalog'],'metadata_evidence','verified_result',null,1000),
                $node('render','deterministic_definition',['evidence'],'verified_result','answer_result',null,1000),
            ]];
        return ['registry_version'=>'mohe-business-registry-r8-v4',
            'semantic_resource_hash'=>hash('sha256',\app\services\ai\semantic\AiSemanticVocabulary::fingerprint().$intentUnderstanding['source_hash']),
            'schemas'=>['query_input','definition_input','business_evidence','metadata_evidence','verified_result','answer_result','export_result'],
            'tools'=>[
                'unified_metric_query'=>$tool('unified_metric_query','query_input','business_evidence','unified_query_current_business_scope','read_only'),
                'metric_catalog_read'=>$tool('metric_catalog_read','definition_input','metadata_evidence','confirmed_metric_metadata_current_scope','read_only'),
                'verified_export_create'=>$tool('verified_export_create','verified_result','export_result','verified_query_and_current_export_scope','artifact_only'),
            ],
            'actions'=>[
                'performance_summary'=>$action('summary','unified_metric_query'),
                'performance_trend'=>$action('trend','unified_metric_query'),
                'registered_metric_ranking'=>$action('ranking','unified_metric_query'),
                'performance_comparison'=>$action('comparison','unified_metric_query'),
                'metric_definition_read'=>$action('definition','metric_catalog_read'),
                'verified_result_export'=>$action('export','verified_export_create'),
            ],
            'scenes'=>[
                // The Markdown document supplies the business Skill boundary;
                // registered tools, workflow graph and permission checks remain
                // source-owned PHP declarations below.
                'store_operations'=>['version'=>$storeOperations['version'],'skill_code'=>$storeOperations['skill_code'],'skill_version'=>$storeOperations['version'],
                    'skill_source_hash'=>$storeOperations['source_hash'],'skill_source_path'=>$storeOperations['source_path'],
                    'label'=>$storeOperations['label'],'goal'=>$storeOperations['goal'],'domains'=>$storeOperations['domains'],
                    'semantic_projection'=>$storeOperations['semantic_projection'],
                    'actions'=>['performance_summary','performance_trend','registered_metric_ranking','performance_comparison','metric_definition_read','verified_result_export'],
                    'required_facts'=>$storeOperations['required_facts'],'ambiguities'=>$storeOperations['ambiguities'],
                    'completion'=>$storeOperations['completion'],'counterexamples'=>$storeOperations['counterexamples']],
            ],
            'workflows'=>$workflows,
            'export_node'=>$node('export','verified_export_create',['evidence','render'],'verified_result','export_result','verified_export_create',10000),
        ];
    }
}
