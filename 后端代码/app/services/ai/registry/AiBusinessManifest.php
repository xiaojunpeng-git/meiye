<?php
namespace app\services\ai\registry;

use app\services\ai\tools\AiToolCatalog;
use app\services\ai\workflows\AiWorkflowCatalog;

/** Versioned source-owned declarations, never executable text supplied by a model. */
final class AiBusinessManifest
{
    public static function definitions(): array
    {
        require_once __DIR__.'/AiSkillDocument.php';
        require_once dirname(__DIR__).'/tools/AiToolCatalog.php';
        require_once dirname(__DIR__).'/workflows/AiWorkflowCatalog.php';
        $storeOperations=AiSkillDocument::storeOperations();
        $intentUnderstanding=AiSkillDocument::intentUnderstanding();
        $tools=AiToolCatalog::definitions();
        $actions=AiWorkflowCatalog::actions();
        $workflows=AiWorkflowCatalog::definitions();
        return ['registry_version'=>'mohe-business-registry-r9-v1',
            'semantic_resource_hash'=>hash('sha256',$intentUnderstanding['source_hash'].$storeOperations['source_hash']),
            'intent_contract'=>\app\services\ai\contract\AiIntentResultContract::manifest(),
            'schemas'=>['query_input','definition_input','business_evidence','metadata_evidence','verified_result','answer_result','export_result'],
            'tools'=>$tools,
            'actions'=>$actions,
            'scenes'=>[
                // The complete published Markdown is model guidance. Capability
                // and permission decisions remain source-owned below.
                'store_operations'=>['version'=>$storeOperations['version'],'skill_code'=>$storeOperations['skill_code'],'skill_version'=>$storeOperations['version'],
                    'skill_source_hash'=>$storeOperations['source_hash'],'skill_source_path'=>$storeOperations['source_path'],
                    'label'=>$storeOperations['label'],'instructions'=>$storeOperations['markdown'],
                    'actions'=>['performance_summary','performance_trend','registered_metric_ranking','performance_comparison','metric_definition_read','verified_result_export']],
            ],
            'workflows'=>$workflows,
            'export_node'=>AiToolCatalog::exportNode(),
        ];
    }
}
