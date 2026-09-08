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
        foreach (['summary'=>'performance_summary','trend'=>'performance_trend','ranking'=>'store_performance_ranking','comparison'=>'performance_comparison'] as $shape=>$actionId) {
            $workflows['wf_performance_'.$shape]=['version'=>1,'scene'=>$shape==='ranking'?'store_performance_compare':'business_performance_overview',
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
        return ['registry_version'=>'mohe-business-registry-r5-v2',
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
                'store_performance_ranking'=>$action('ranking','unified_metric_query'),
                'performance_comparison'=>$action('comparison','unified_metric_query'),
                'verified_result_export'=>$action('export','verified_export_create'),
            ],
            'scenes'=>[
                'business_performance_overview'=>['version'=>1,'skill_code'=>'skill_business_performance_overview','skill_version'=>1,'label'=>'经营结果了解','goal'=>'了解明确期间的收款或服务结果、变化及两期情况',
                    'actions'=>['performance_summary','performance_trend','performance_comparison','verified_result_export'],
                    'required_facts'=>['confirmed_metric','complete_period','current_business_scope','complete_filters'],
                    'ambiguities'=>['performance_metric','period','comparison_period'],
                    'completion'=>'所有请求的指标、期间和完整条件均有权威证据；不推断原因或临时计算增幅',
                    'counterexamples'=>['未登记利润','省略人员或分类条件','将两期金额解释为已证明经营原因']],
                'store_performance_compare'=>['version'=>1,'skill_code'=>'skill_store_performance_compare','skill_version'=>1,'label'=>'门店表现比较','goal'=>'按明确标准了解门店表现与相对位置',
                    'actions'=>['store_performance_ranking','verified_result_export'],
                    'required_facts'=>['confirmed_metric','complete_period','current_business_scope','ranking_contract'],
                    'ambiguities'=>['evaluation_metric','direction','count'],
                    'completion'=>'按同一已登记排行合同覆盖合法参评门店；排行不等于经营健康诊断',
                    'counterexamples'=>['自动判断经营好坏','改变用户请求的门店数量','以排名查询扩大门店权限']],
            ],
            'workflows'=>$workflows,
            'export_node'=>$node('export','verified_export_create',['evidence','render'],'verified_result','export_result','verified_export_create',10000),
        ];
    }
}
