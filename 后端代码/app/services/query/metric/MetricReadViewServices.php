<?php

namespace app\services\query\metric;

use app\services\query\UnifiedQueryJson;

/** Report-owned immutable projection. Replays only its exact query, never a fresh read
 * disguised as the old snapshot. Production AI discovery remains separately gated by
 * catalog mapping, report parity and performance acceptance.
 */
final class MetricReadViewServices
{
    const CONTRACT_VERSION = 'group-performance-summary-read-v1';
    const COVERAGE_START = '2026-08-10';
    private $store;
    private $transaction;
    private $authorize;
    private $clock;

    /** Implemented shared contracts, not a grant of entry/report access or instance deployment readiness. */
    public static function metricCapabilities(): array
    {
        return [
            'consume_amount' => ['metric_code' => 'consume_amount', 'name' => '消耗业绩', 'ai_query_ready' => true,
                'metric_version' => 'consumption-completed-service-facts-v1', 'mapping_version' => 'group-consumption-canonical-map-v1',
                'source_metric_code' => 'consumption_performance', 'source_metric_version' => 'group-management-dashboard-facts-v1',
                'query_shapes' => ['summary', 'comparison', 'trend', 'ranking'], 'coverage_start' => self::COVERAGE_START,
                'filter_grain' => 'store', 'business_filters' => [], 'readiness_reasons' => []],
            'cash_performance' => ['metric_code' => 'cash_performance', 'name' => '现金业绩', 'ai_query_ready' => true,
                'metric_version' => 'cash-collected-recharge-inclusive-v2', 'mapping_version' => 'group-cash-canonical-map-v2',
                'source_metric_code' => 'cash_performance', 'source_metric_version' => 'group-management-cash-recharge-v2',
                'query_shapes' => ['summary', 'comparison', 'trend', 'ranking'], 'coverage_start' => self::COVERAGE_START,
                'filter_grain' => 'store', 'business_filters' => [], 'readiness_reasons' => []],
            'actual_performance' => ['metric_code' => 'actual_performance', 'name' => '实际业绩', 'ai_query_ready' => false,
                'metric_version' => null, 'query_shapes' => [], 'readiness_reasons' => ['METRIC_SEMANTICS_CONFLICT'],
                'unavailable_message' => '实际业绩正在统一统计口径，暂不能查询。'],
        ];
    }

    /** authorize must rebuild report permissions from the authenticated principal on EVERY invocation. */
    public function __construct(MetricReadViewStore $store, callable $authorize, ?callable $transaction = null, ?callable $clock = null)
    {
        $this->store = $store;
        $this->authorize = $authorize;
        $this->transaction = $transaction ?: [new MetricReadTransaction(), 'run'];
        $this->clock = $clock ?: static function (): int { return time(); };
    }

    public function create(array $principal, array $query, ?int $expiresAt = null): array
    {
        $normalized = $this->query($query);
        $binding = $this->binding(call_user_func($this->authorize, $principal), $normalized['store_ids']);
        $now = $this->now();
        $expiresAt=$expiresAt??($now+86400);
        if ($expiresAt<=$now || $expiresAt>$now+86400) $this->fail('METRIC_READ_EXPIRY_INVALID');
        $ranges = ['current' => ['start' => $normalized['start_date'], 'end' => $normalized['end_date']]];
        if ($normalized['compare_range'] !== null) $ranges['comparison'] = $normalized['compare_range'];
        $results = call_user_func($this->transaction, function (GroupPerformanceMetricReadServices $reader) use ($normalized, $binding, $ranges): array {
            $results = [];
            $storeNames = $normalized['query_shape'] === 'ranking' ? $reader->storeNames($binding['store_ids']) : [];
            foreach ($ranges as $period => $range) {
                foreach ($normalized['metric_codes'] as $metric) {
                    if (in_array($normalized['query_shape'], ['trend', 'ranking'], true)) {
                        $points = $reader->dailyStoreTotals($binding['tenant_id'], $binding['store_ids'], $range, $metric);
                        $projector = new MetricGroupedProjection();
                        $rows = $normalized['query_shape'] === 'trend' ? $projector->trend($points, $range) : $projector->ranking($points, $binding['store_ids'], $normalized['ranking']);
                        if ($normalized['query_shape'] === 'ranking') {
                            foreach ($rows as &$direction) foreach ($direction as &$row) $row['store_name'] = $storeNames[$row['store_id']];
                            unset($row, $direction);
                        }
                        $results[] = ['period' => $period, 'metric_code' => $metric, 'storage_unit' => 'fen', 'rows' => $rows];
                        continue;
                    }
                    $value = $metric === 'cash_performance'
                        ? $reader->cashTotals($binding['tenant_id'], $binding['store_ids'], $range)['gross_cents']
                        : $reader->performanceTotal($binding['tenant_id'], $binding['store_ids'], $range, 'consumption_performance_recorded');
                    $results[] = ['period' => $period, 'metric_code' => $metric, 'amount_cents' => $value, 'storage_unit' => 'fen'];
                }
            }
            return $results;
        });
        // A revocation during the read cannot mint a usable view.
        if ($this->binding(call_user_func($this->authorize, $principal), $normalized['store_ids']) !== $binding) $this->fail('METRIC_PERMISSION_CHANGED');
        $capabilities = self::metricCapabilities();
        $readiness = []; $metricVersions = []; $allReady = true;
        foreach ($normalized['metric_codes'] as $metric) {
            $readiness[$metric] = $capabilities[$metric];
            if ($capabilities[$metric]['ai_query_ready']) $metricVersions[$metric] = $capabilities[$metric]['metric_version'];
            else $allReady = false;
        }
        $view = [
            'schema_version' => self::CONTRACT_VERSION,
            'read_view_kind' => 'materialized_report_projection',
            'created_at' => $now, 'expires_at' => $expiresAt,
            'binding' => $binding, 'query' => $normalized,
            'query_hash' => hash('sha256', UnifiedQueryJson::encode($normalized)),
            'effective_scope_hash' => hash('sha256', UnifiedQueryJson::encode($binding)),
            'source_kind' => 'unified_facts',
            'source_read_mode' => 'repeatable_read_transaction',
            'coverage_start' => self::COVERAGE_START,
            // Deliberately not a fact commit watermark, nor a claim of aggregate catch-up.
            'aggregation_caught_up' => null,
            'data_as_of' => date('c', $now),
            'source_metric_versions' => array_map(static function (array $capability): string { return $capability['source_metric_version']; }, $readiness),
            'canonical_mapping_version' => 'group-performance-summary-mapping-v1',
            'metric_versions' => $metricVersions,
            'metric_readiness' => $readiness,
            'ai_query_ready' => $allReady,
            'results' => $results, 'result_status' => 'complete',
            'result_hash' => hash('sha256', UnifiedQueryJson::encode(['query' => $normalized, 'binding' => $binding, 'results' => $results, 'version' => self::CONTRACT_VERSION])),
        ];
        $ref = $this->store->put($view);
        return $this->replay($principal, $normalized, $ref);
    }

    public function replay(array $principal, array $query, string $ref): array
    {
        $normalized = $this->query($query);
        $binding = $this->binding(call_user_func($this->authorize, $principal), $normalized['store_ids']);
        $view = $this->store->get($ref);
        if (($view['schema_version'] ?? null) !== self::CONTRACT_VERSION || ($view['binding'] ?? null) !== $binding
            || ($view['query'] ?? null) !== $normalized) $this->fail('METRIC_READ_BINDING_MISMATCH');
        return $view;
    }

    private function binding($binding, array $requested): array
    {
        $fields = ['instance_id', 'subject_ref', 'terminal', 'tenant_id', 'permission_version', 'report_capability_code', 'scope_provider_code', 'scope_mode', 'store_ids'];
        if (!is_array($binding) || array_diff(array_keys($binding), $fields) || array_diff($fields, array_keys($binding))) $this->fail('METRIC_PERMISSION_INVALID');
        foreach (array_diff($fields, ['store_ids']) as $key) {
            if (!is_string($binding[$key]) || $binding[$key] === '' || strlen($binding[$key]) > 256 || preg_match('/[\x00-\x1f\x7f]/', $binding[$key])) $this->fail('METRIC_PERMISSION_INVALID');
        }
        if (!in_array($binding['terminal'], ['platform', 'store', 'merchant'], true)
            || !in_array($binding['scope_mode'], ['stores', 'all', 'agent_limited', 'platform_admin'], true)
            || $binding['report_capability_code'] !== 'group_management_dashboard') $this->fail('METRIC_PERMISSION_GRAIN_UNAVAILABLE');
        $allowed = $this->ids($binding['store_ids']);
        if ($allowed === [] || array_diff($requested, $allowed)) $this->fail('METRIC_PERMISSION_DENIED');
        $binding['store_ids'] = $requested === [] ? $allowed : $requested;
        if ($binding['terminal'] === 'store' && count($binding['store_ids']) !== 1) $this->fail('METRIC_PERMISSION_DENIED');
        return UnifiedQueryJson::decode(UnifiedQueryJson::encode($binding));
    }

    private function query(array $query): array
    {
        if (!array_key_exists('ranking', $query)) $query['ranking'] = null;
        $fields = ['query_shape', 'metric_codes', 'start_date', 'end_date', 'compare_range', 'store_ids', 'business_filters', 'ranking'];
        if (array_diff(array_keys($query), $fields) || array_diff($fields, array_keys($query))) $this->fail('METRIC_QUERY_SCHEMA_INVALID');
        if (!in_array($query['query_shape'], ['summary', 'comparison', 'trend', 'ranking'], true) || $query['business_filters'] !== []) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        if ($query['query_shape'] === 'ranking') {
            $rank = $query['ranking'];
            if (!is_array($rank) || count($rank) !== 2 || !in_array($rank['direction'] ?? null, ['top', 'bottom', 'top_and_bottom'], true)
                || !is_int($rank['limit'] ?? null) || $rank['limit'] < 1 || $rank['limit'] > 20) $this->fail('METRIC_QUERY_SCHEMA_INVALID');
        } elseif ($query['ranking'] !== null) $this->fail('METRIC_QUERY_SCHEMA_INVALID');
        if (!is_array($query['metric_codes']) || $query['metric_codes'] === [] || count($query['metric_codes']) > 2
            || array_keys($query['metric_codes']) !== range(0, count($query['metric_codes']) - 1)) $this->fail('METRIC_QUERY_SCHEMA_INVALID');
        foreach ($query['metric_codes'] as $metric) if (!in_array($metric, ['cash_performance', 'consume_amount'], true)) $this->fail('METRIC_NOT_REGISTERED');
        if (count(array_unique($query['metric_codes'])) !== count($query['metric_codes'])) $this->fail('METRIC_QUERY_SCHEMA_INVALID');
        $this->range(['start' => $query['start_date'], 'end' => $query['end_date']]);
        if ($query['query_shape'] === 'comparison') {
            if (!is_array($query['compare_range'])) $this->fail('METRIC_QUERY_SCHEMA_INVALID');
            $this->range($query['compare_range']);
        } elseif ($query['compare_range'] !== null) $this->fail('METRIC_QUERY_SCHEMA_INVALID');
        $query['store_ids'] = $this->ids($query['store_ids']);
        // Canonical key order for equality after JSON storage (objects are sorted by encoder).
        return UnifiedQueryJson::decode(UnifiedQueryJson::encode($query));
    }

    private function range(array $range): void
    {
        if (count($range) !== 2 || !isset($range['start'], $range['end'])) $this->fail('METRIC_QUERY_RANGE_INVALID');
        $dates = [];
        foreach (['start', 'end'] as $key) {
            if (!is_string($range[$key]) || !preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $range[$key])) $this->fail('METRIC_QUERY_RANGE_INVALID');
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $range[$key], new \DateTimeZone('Asia/Shanghai'));
            if (!$date || $date->format('Y-m-d') !== $range[$key]) $this->fail('METRIC_QUERY_RANGE_INVALID');
            $dates[$key] = $date;
        }
        if ($range['start'] < self::COVERAGE_START || $range['start'] > $range['end'] || $dates['start']->diff($dates['end'])->days > 366) $this->fail('METRIC_QUERY_COVERAGE_UNAVAILABLE');
    }

    private function ids($ids): array
    {
        if (!is_array($ids) || count($ids) > 10000 || ($ids !== [] && array_keys($ids) !== range(0, count($ids) - 1))) $this->fail('METRIC_PERMISSION_INVALID');
        foreach ($ids as $id) if (!is_int($id) || $id <= 0) $this->fail('METRIC_PERMISSION_INVALID');
        if (count(array_unique($ids)) !== count($ids)) $this->fail('METRIC_PERMISSION_INVALID');
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function now(): int
    {
        $now = call_user_func($this->clock);
        if (!is_int($now) || $now < 0 || $now > PHP_INT_MAX - 86400) $this->fail('METRIC_QUERY_CLOCK_INVALID');
        return $now;
    }

    private function fail(string $code): void { throw new MetricQueryContractException($code, '当前指标、条件或权限范围暂不支持此查询。'); }
}
