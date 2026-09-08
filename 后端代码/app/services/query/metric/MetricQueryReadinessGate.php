<?php

namespace app\services\query\metric;

/** Contract assessment, not an authorization issuer or a business query executor. */
final class MetricQueryReadinessGate
{
    private $catalog;
    private $registry;

    public function __construct(MetricQueryCatalog $catalog, MetricReportCapabilityRegistry $registry)
    {
        $this->catalog = $catalog;
        $this->registry = $registry;
    }

    /**
     * request: exact metric/shape/terminal only.
     * trustedAuthorization: resolved by server permission/filter contracts, NEVER the HTTP payload.
     * In particular filter_grain is server-derived, not selected by the model.
     */
    public function inspect(array $request, array $trustedAuthorization): array
    {
        $this->exactStrings($request, ['metric_code', 'query_shape', 'terminal']);
        $this->exactStrings($trustedAuthorization, [
            'instance_id', 'subject_ref', 'terminal', 'permission_version', 'effective_scope_ref',
            'filter_grain', 'report_capability_code', 'scope_provider_code', 'filter_contract_ref',
        ]);
        if ($request['terminal'] !== $trustedAuthorization['terminal']) $this->fail('METRIC_PERMISSION_DENIED');
        $metric = $this->catalog->get($request['metric_code']);
        $binding = $this->registry->resolve($request['metric_code'], $request['query_shape'], $request['terminal'], $trustedAuthorization['filter_grain']);
        foreach (['report_capability_code', 'scope_provider_code', 'filter_contract_ref'] as $field) {
            if ($binding[$field] !== $trustedAuthorization[$field]) $this->fail('METRIC_PERMISSION_DENIED');
        }
        // No input flag can turn incomplete providers/snapshots/parity into production readiness.
        return [
            'metric' => $metric,
            'binding' => $binding,
            'ai_query_ready' => false,
            'readiness_reasons' => array_values(array_unique(array_merge($metric['readiness_reasons'], [
                'PRODUCTION_QUERY_PROVIDER_UNAVAILABLE', 'REPLAYABLE_READ_UNVERIFIED', 'REPORT_EXPORT_PARITY_UNVERIFIED',
            ]))),
        ];
    }

    public function assertQueryable(array $request, array $trustedAuthorization): void
    {
        $this->inspect($request, $trustedAuthorization);
        $this->fail('METRIC_QUERY_NOT_READY');
    }

    private function exactStrings(array $input, array $fields): void
    {
        if (array_diff(array_keys($input), $fields) || array_diff($fields, array_keys($input))) $this->fail('METRIC_QUERY_SCHEMA_INVALID');
        foreach ($fields as $field) {
            if (!is_string($input[$field]) || trim($input[$field]) === '' || strlen($input[$field]) > 256
                || preg_match('/[\x00-\x1f\x7f]/', $input[$field])) $this->fail('METRIC_QUERY_SCHEMA_INVALID');
        }
    }

    private function fail(string $code): void
    {
        throw new MetricQueryContractException($code, '当前查询能力尚未开放或无权使用。');
    }
}
