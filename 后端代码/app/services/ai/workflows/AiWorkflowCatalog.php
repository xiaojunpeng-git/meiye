<?php
namespace app\services\ai\workflows;

use app\services\ai\tools\AiToolCatalog;

/** Registered workflow graphs. They compose tool codes; they never read Skill prose. */
final class AiWorkflowCatalog
{
    public static function actions(): array
    {
        return [
            'performance_summary' => self::action('summary', 'unified_metric_query'),
            'performance_trend' => self::action('trend', 'unified_metric_query'),
            'registered_metric_ranking' => self::action('ranking', 'unified_metric_query'),
            'performance_comparison' => self::action('comparison', 'unified_metric_query'),
            'metric_definition_read' => self::action('definition', 'metric_catalog_read'),
            'verified_result_export' => self::action('export', 'verified_export_create'),
        ];
    }

    public static function definitions(): array
    {
        $workflows = [];
        foreach (['summary' => 'performance_summary', 'trend' => 'performance_trend', 'ranking' => 'registered_metric_ranking', 'comparison' => 'performance_comparison'] as $shape => $action) {
            $workflows['wf_performance_' . $shape] = ['version' => 3, 'scene' => 'store_operations',
                'action' => $action, 'query_shape' => $shape, 'entry' => 'query', 'terminal' => 'render', 'allow_export' => true,
                'max_path_ms' => 40000, 'nodes' => [
                    AiToolCatalog::node('query', 'unified_metric_query', [], 'query_input', 'business_evidence', 'unified_metric_query', 10000),
                    AiToolCatalog::node('evidence', 'all_evidence_guard', ['query'], 'business_evidence', 'verified_result', null, 10000),
                    AiToolCatalog::node('render', 'deterministic_answer', ['evidence'], 'verified_result', 'answer_result'),
                ]];
        }
        $workflows['wf_metric_definition'] = ['version' => 2, 'scene' => 'store_operations', 'action' => 'metric_definition_read',
            'query_shape' => 'definition', 'entry' => 'catalog', 'terminal' => 'render', 'allow_export' => false, 'max_path_ms' => 15000,
            'nodes' => [
                AiToolCatalog::node('catalog', 'metric_catalog_read', [], 'definition_input', 'metadata_evidence', 'metric_catalog_read', 10000),
                AiToolCatalog::node('evidence', 'metadata_guard', ['catalog'], 'metadata_evidence', 'verified_result', null),
                AiToolCatalog::node('render', 'deterministic_definition', ['evidence'], 'verified_result', 'answer_result'),
            ]];
        return $workflows;
    }

    private static function action(string $shape, string $tool): array
    {
        $input = $shape === 'export' ? 'verified_result' : ($shape === 'definition' ? 'definition_input' : 'query_input');
        $output = $shape === 'export' ? 'export_result' : ($shape === 'definition' ? 'metadata_evidence' : 'business_evidence');
        return ['version' => 1, 'query_shape' => $shape, 'tool' => $tool, 'input_schema' => $input, 'output_schema' => $output];
    }
}
