<?php
namespace app\services\query\metric;

use app\services\metric\MetricDictionaryServices;

/**
 * Read-only product projection of the source-owned metric registry.
 *
 * This is intentionally not an editor: a platform maintainer may inspect the
 * registered contract, but cannot change a formula, reader strategy or data
 * permission from the administration UI.
 */
final class MetricRegistryCatalogServices
{
    public static function catalog(): array
    {
        $dictionary = new MetricDictionaryServices();
        $items = [];
        foreach (MetricDefinitionRegistry::all() as $code => $contract) {
            $definition = $dictionary->getByCode($code);
            if (!is_array($definition) || ($definition['user_ready'] ?? false) !== true) {
                continue;
            }
            $items[] = [
                'metric_code' => $code,
                'name' => (string)$definition['name'],
                'summary' => (string)($definition['summary'] ?? ''),
                'include' => (string)($definition['include'] ?? ''),
                'exclude' => (string)($definition['exclude'] ?? ''),
                'timing' => (string)($definition['timing'] ?? ''),
                'note' => (string)($definition['note'] ?? ''),
                'storage_unit' => (string)$contract['storage_unit'],
                'query_shapes' => array_values($contract['query_shapes']),
                'filter_grain' => (string)$contract['filter_grain'],
                'business_filters' => array_values($contract['business_filters']),
                'metric_version' => (string)$contract['metric_version'],
                'derived' => isset($contract['derivation']),
                'category_supported' => isset($contract['category_reader']),
            ];
        }
        usort($items, static function (array $left, array $right): int {
            return strcmp($left['name'], $right['name']) ?: strcmp($left['metric_code'], $right['metric_code']);
        });
        return [
            'registry_version' => MetricDefinitionRegistry::VERSION,
            'coverage_start' => MetricDefinitionRegistry::COVERAGE_START,
            'items' => $items,
        ];
    }
}
