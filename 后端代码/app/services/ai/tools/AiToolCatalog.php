<?php
namespace app\services\ai\tools;

/**
 * Source-owned internal tool contracts.
 *
 * These are not MCP endpoints: they are PHP runtime handlers behind the
 * business registry.  A future external MCP integration belongs in its own
 * adapter boundary and must not be added to this catalog as an HTTP shortcut.
 */
final class AiToolCatalog
{
    public static function definitions(): array
    {
        return [
            'unified_metric_query' => self::tool('unified_metric_query', 'query_input', 'business_evidence', 'unified_query_current_business_scope', 'read_only'),
            'metric_catalog_read' => self::tool('metric_catalog_read', 'definition_input', 'metadata_evidence', 'confirmed_metric_metadata_current_scope', 'read_only'),
            'verified_export_create' => self::tool('verified_export_create', 'verified_result', 'export_result', 'verified_query_and_current_export_scope', 'artifact_only'),
        ];
    }

    public static function exportNode(): array
    {
        return self::node('export', 'verified_export_create', ['evidence', 'render'], 'verified_result', 'export_result', 'verified_export_create', 10000);
    }

    public static function node(string $id, string $handler, array $dependencies, string $input, string $output, ?string $tool = null, int $timeout = 1000): array
    {
        return ['id' => $id, 'handler' => $handler, 'depends_on' => $dependencies, 'input_schema' => $input, 'output_schema' => $output,
            'tool' => $tool, 'timeout_ms' => $timeout, 'max_visits' => 1];
    }

    private static function tool(string $handler, string $input, string $output, string $permission, string $effect): array
    {
        return ['version' => 1, 'handler' => $handler, 'input_schema' => $input, 'output_schema' => $output,
            'permission_contract' => $permission, 'side_effect' => $effect, 'timeout_ms' => 10000,
            'idempotency' => 'run_generation_node_attempt', 'retry' => 'never_automatically'];
    }
}
