<?php

namespace app\services\query\metric;

/** Trusted boot-time registration only. Freeze before use; no fallback to coarser permissions. */
final class MetricReportCapabilityRegistry
{
    private $bindings = [];
    private $frozen = false;

    public function register(array $binding): self
    {
        if ($this->frozen) $this->fail('METRIC_REGISTRY_FROZEN');
        $fields = ['metric_code', 'query_shape', 'terminal', 'filter_grain', 'report_capability_code', 'scope_provider_code', 'filter_contract_ref'];
        if (array_diff(array_keys($binding), $fields) || array_diff($fields, array_keys($binding))) {
            $this->fail('METRIC_BINDING_SCHEMA_INVALID');
        }
        foreach ($fields as $field) {
            if (!is_string($binding[$field]) || !preg_match('/^[a-z][a-z0-9_.:-]{0,127}$/D', $binding[$field])) {
                $this->fail('METRIC_BINDING_SCHEMA_INVALID');
            }
        }
        if (!in_array($binding['metric_code'], ['cash_performance', 'consume_amount', 'actual_performance'], true)
            || !in_array($binding['query_shape'], ['summary', 'trend', 'ranking', 'comparison'], true)
            || !in_array($binding['terminal'], ['platform', 'store', 'merchant'], true)
            || !in_array($binding['filter_grain'], ['store', 'person'], true)) {
            $this->fail('METRIC_BINDING_SCHEMA_INVALID');
        }
        $key = $this->key($binding['metric_code'], $binding['query_shape'], $binding['terminal'], $binding['filter_grain']);
        if (isset($this->bindings[$key])) $this->fail('METRIC_BINDING_AMBIGUOUS');
        foreach ($this->bindings as $existing) {
            if ($existing['report_capability_code'] === $binding['report_capability_code']
                && $existing['filter_grain'] !== $binding['filter_grain']) {
                $this->fail('METRIC_PERMISSION_GRAIN_CONFLICT');
            }
        }
        $this->bindings[$key] = $binding;
        return $this;
    }

    public function freeze(): self
    {
        $this->frozen = true;
        return $this;
    }

    public function resolve(string $metric, string $shape, string $terminal, string $grain): array
    {
        if (!$this->frozen) $this->fail('METRIC_REGISTRY_NOT_FROZEN');
        $key = $this->key($metric, $shape, $terminal, $grain);
        if (!isset($this->bindings[$key])) $this->fail('METRIC_CAPABILITY_UNAVAILABLE');
        return $this->bindings[$key];
    }

    private function key(string $metric, string $shape, string $terminal, string $grain): string
    {
        return json_encode([$metric, $shape, $terminal, $grain]);
    }

    private function fail(string $code): void
    {
        throw new MetricQueryContractException($code, '当前查询能力配置未就绪。');
    }
}
