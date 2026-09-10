<?php
namespace app\services\ai\registry;

/** Versioned source-owned declarations, never executable text supplied by a model. */
final class AiBusinessManifest
{
    public static function definitions(): array
    {
        require_once dirname(__DIR__).'/semantic/AiSemanticVocabulary.php';
        $tool=function(string $handler,string $input,string $output,string $permission,string $effect):array {
            return ['version'=>1,'handler'=>$handler,'input_schema'=>$input,'output_schema'=>$output,
                'permission_contract'=>$permission,'side_effect'=>$effect,'timeout_ms'=>10000,
                'idempotency'=>'run_generation_node_attempt','retry'=>'never_automatically'];
        };
        $action=function(string $shape,string $tool):array {
            return ['version'=>1,'query_shape'=>$shape,'tool'=>$tool,'input_schema'=>$shape==='export'?'verified_result':'query_input',
                'output_schema'=>$shape==='export'?'export_result':'business_evidence'];
        };
        $node=function(string $id,string $handler,array $dependencies,string $input,string $output,?string $tool=null,int $timeout=1000):array {
            return ['id'=>$id,'handler'=>$handler,'depends_on'=>$dependencies,'input_schema'=>$input,'output_schema'=>$output,
                'tool'=>$tool,'timeout_ms'=>$timeout,'max_visits'=>1];
        };
        $workflows=[];
        foreach (['summary'=>'performance_summary','trend'=>'performance_trend','ranking'=>'registered_metric_ranking','comparison'=>'performance_comparison'] as $shape=>$actionId) {
            $workflows['wf_performance_'.$shape]=['version'=>2,'scene'=>'registered_metric_analysis',
                'action'=>$actionId,'query_shape'=>$shape,'entry'=>'query','terminal'=>'render','allow_export'=>true,
                'max_path_ms'=>40000,'nodes'=>[
                    $node('query','unified_metric_query',[],'query_input','business_evidence','unified_metric_query',10000),
                    $node('evidence','all_evidence_guard',['query'],'business_evidence','verified_result',null,10000),
                    $node('render','deterministic_answer',['evidence'],'verified_result','answer_result',null,1000),
                ]];
        }
        $workflows['wf_metric_definition']=['version'=>1,'scene'=>null,'action'=>null,'query_shape'=>'definition','entry'=>'catalog','terminal'=>'render','allow_export'=>false,
            'max_path_ms'=>15000,'nodes'=>[
                $node('catalog','metric_catalog_read',[],'definition_input','metadata_evidence','metric_catalog_read',10000),
                $node('evidence','metadata_guard',['catalog'],'metadata_evidence','verified_result',null,1000),
                $node('render','deterministic_definition',['evidence'],'verified_result','answer_result',null,1000),
            ]];
        return ['registry_version'=>'mohe-business-registry-r7-v1',
            'semantic_resource_hash'=>\app\services\ai\semantic\AiSemanticVocabulary::fingerprint(),
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
                'verified_result_export'=>$action('export','verified_export_create'),
            ],
            'scenes'=>[
                // A scene is an interaction boundary, not a report page or a
                // fixed question list.  Registered metric/object contracts decide
                // what is discoverable for the current account at runtime.
                'registered_metric_analysis'=>['version'=>2,'skill_code'=>'skill_registered_metric_analysis','skill_version'=>2,'label'=>'已登记指标分析','goal'=>'基于当前授权范围，通过已登记指标、对象与筛选合同回答经营分析问题',
                    'actions'=>['performance_summary','performance_trend','registered_metric_ranking','performance_comparison','verified_result_export'],
                    'required_facts'=>['registered_metric','complete_period','current_business_scope','complete_filters','object_grain_contract'],
                    'ambiguities'=>['analysis_object','evaluation_metric','period','comparison_period','ranking_contract'],
                    'completion'=>'仅当对象、指标、期间和完整条件均有权威证据时完成；不推断原因、不现场计算、不扩大权限',
                    'counterexamples'=>['按报表页固定候选','未登记指标','省略人员或分类条件','将排行解释为已证明经营原因']],
            ],
            'workflows'=>$workflows,
            'export_node'=>$node('export','verified_export_create',['evidence','render'],'verified_result','export_result','verified_export_create',10000),
        ];
    }
}
