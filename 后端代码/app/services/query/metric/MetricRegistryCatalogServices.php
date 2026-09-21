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
        $capabilities = MetricDefinitionRegistry::capabilities();
        $items = [];
        foreach (MetricDefinitionRegistry::all() as $code => $contract) {
            $definition = $dictionary->getByCode($code);
            if (!is_array($definition) || ($definition['user_ready'] ?? false) !== true) {
                continue;
            }
            $capability = $capabilities[$code] ?? null;
            if (!is_array($capability)) {
                throw new MetricQueryContractException('METRIC_CATALOG_CAPABILITY_MISSING', '指标注册能力不完整。');
            }
            $classification = MetricDefinitionRegistry::classification($code);
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
                // This is a read-only projection of the Reader contract. It
                // cannot grant a metric, object, or query shape by itself.
                'metric_kind' => $classification['metric_kind'],
                'time_semantics' => $classification['time_semantics'],
                'analysis_objects' => self::analysisObjects($capability),
                'overview_sections' => self::overviewSections($capability),
                'ai_query_ready' => ($capability['ai_query_ready'] ?? false) === true,
                'readiness_reasons' => array_values($capability['readiness_reasons'] ?? []),
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

    /** @return array<int,array{kind:string,label:string}> */
    private static function analysisObjects(array $capability): array
    {
        // Only store-grain metrics receive the store base object. Person-grain
        // metrics and every other object must be declared by a dimension
        // contract; a fact column alone never exposes a new analysis object.
        $objects = ($capability['filter_grain'] ?? null) === 'store'
            ? ['store' => '门店']
            : [];
        foreach ((array)($capability['analysis_dimension_contracts'] ?? []) as $contract) {
            if (!is_array($contract)) continue;
            $kind = $contract['object_kind'] ?? null;
            $label = $contract['object_label'] ?? null;
            if (is_string($kind) && is_string($label) && $kind !== '' && $label !== '') {
                $objects[$kind] = $label;
            }
        }
        ksort($objects, SORT_STRING);
        $out = [];
        foreach ($objects as $kind => $label) $out[] = ['kind' => $kind, 'label' => $label];
        return $out;
    }

    /** @return array<int,array{object_kind:string,object_label:string,section:string}> */
    private static function overviewSections(array $capability): array
    {
        $objectLabels = [];
        foreach (self::analysisObjects($capability) as $object) {
            $objectLabels[$object['kind']] = $object['label'];
        }
        $out = [];
        foreach ((array)($capability['overview'] ?? []) as $entry) {
            if (!is_array($entry) || !is_string($entry['object_kind'] ?? null)
                || !is_string($entry['section'] ?? null)) continue;
            $kind = $entry['object_kind'];
            $out[] = [
                'object_kind' => $kind,
                'object_label' => $objectLabels[$kind] ?? $kind,
                'section' => $entry['section'],
            ];
        }
        return $out;
    }
}
