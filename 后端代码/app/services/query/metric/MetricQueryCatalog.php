<?php

namespace app\services\query\metric;

use app\services\query\UnifiedQueryException;
use app\services\query\UnifiedQueryJson;

/** Shared report/AI metadata only. No query provider or production readiness is implied. */
final class MetricQueryCatalog
{
    const TOOLTIP_HASH_SCHEMA_VERSION = 'mohe-tooltip-content-hash-v1';
    private $entries;

    private function __construct(array $entries)
    {
        $this->entries = $entries;
    }

    /** The callback is a trusted server-side dictionary getByCode adapter, never request input. */
    public static function fromDictionary(callable $lookup): self
    {
        $entries = [];
        foreach (['cash_performance', 'consume_amount', 'actual_performance'] as $code) {
            $definition = $lookup($code);
            if (!is_array($definition) || ($definition['code'] ?? null) !== $code) {
                throw new MetricQueryContractException('METRIC_DICTIONARY_INVALID', '指标目录配置不完整。');
            }
            $ready = ($definition['user_ready'] ?? false) === true;
            $entry = ['metric_code' => $code, 'user_ready' => $ready];
            foreach (['name', 'summary', 'include', 'exclude', 'timing', 'note'] as $field) {
                if (!is_string($definition[$field] ?? '')) {
                    throw new MetricQueryContractException('METRIC_DICTIONARY_INVALID', '指标说明配置不合法。');
                }
                $entry[$field] = $ready ? ($definition[$field] ?? '') : '';
            }
            if (!$ready) $entry['summary'] = '口径说明待产品确认';
            // Explicit white-list projection and versioned canonical envelope.
            // Stable content identity is NOT a metric version or a read snapshot.
            $content = ['code' => $code, 'user_ready' => $ready];
            foreach (['name', 'summary', 'include', 'exclude', 'timing', 'note'] as $field) {
                $content[$field] = $entry[$field];
            }
            try {
                $encoded = UnifiedQueryJson::encode([
                    'hash_schema_version' => self::TOOLTIP_HASH_SCHEMA_VERSION,
                    'content' => $content,
                ]);
            } catch (UnifiedQueryException $exception) {
                throw new MetricQueryContractException('METRIC_DICTIONARY_ENCODING_INVALID', '指标说明编码不合法。');
            }
            $entry['tooltip_content_hash'] = hash('sha256', $encoded);
            $entry['metric_version'] = null;
            $entry['version_ready'] = false;
            $entry['ai_query_ready'] = false;
            $entry['readiness_reasons'] = $code === 'actual_performance'
                ? ['METRIC_SEMANTICS_CONFLICT']
                : ($code === 'consume_amount' ? ['METRIC_MAPPING_UNVERIFIED'] : ['SOURCE_PARITY_UNVERIFIED']);
            if (!$ready) $entry['readiness_reasons'][] = 'METRIC_USER_NOT_READY';
            $entries[$code] = $entry;
        }
        return new self($entries);
    }

    public function get(string $code): array
    {
        if (!isset($this->entries[$code])) {
            throw new MetricQueryContractException('METRIC_NOT_REGISTERED', '当前指标尚未开放。');
        }
        return $this->entries[$code];
    }

    /** Explicit shared implementation catalog; instance engine/coverage and current report access
     * are still checked at execution. This never promotes legacy cash/actual implementations.
     */
    public static function fromSharedQuery(callable $lookup): self
    {
        $catalog = self::fromDictionary($lookup);
        foreach (MetricReadViewServices::metricCapabilities() as $code => $capability) {
            if (!isset($catalog->entries[$code])) continue;
            $entry = $catalog->entries[$code];
            $ready = $entry['user_ready'] && $capability['ai_query_ready'];
            $entry['ai_query_ready'] = $ready;
            $entry['version_ready'] = $ready;
            $entry['metric_version'] = $ready ? $capability['metric_version'] : null;
            $entry['query_shapes'] = $ready ? $capability['query_shapes'] : [];
            $entry['readiness_reasons'] = $capability['readiness_reasons'];
            if (!$entry['user_ready']) $entry['readiness_reasons'][] = 'METRIC_USER_NOT_READY';
            if ($ready) {
                $entry['canonical_mapping_version'] = $capability['mapping_version'];
                $entry['coverage_start'] = $capability['coverage_start'];
                $entry['filter_grain'] = $capability['filter_grain'];
            }
            $catalog->entries[$code] = $entry;
        }
        return $catalog;
    }

    public function all(): array
    {
        return array_values($this->entries);
    }
}
