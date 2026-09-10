<?php

namespace app\services\query\metric;

/**
 * 兼容旧调用方的门面。所有规范指标均由 RegisteredMetricReadServices 根据
 * MetricDefinitionRegistry 分派；这里不再维护指标代码到取数实现的映射。
 */
final class GroupPerformanceMetricReadServices
{
    private $registered;

    public function __construct(?callable $queryFactory = null, ?callable $normalScope = null, ?callable $normalServiceScope = null)
    {
        $this->registered = new RegisteredMetricReadServices($queryFactory, $normalScope, $normalServiceScope);
    }

    public function metricTotal(string $tenantId, array $stores, array $range, string $metricCode): int
    {
        return $this->registered->summary($metricCode, $tenantId, $stores, $range);
    }

    /** Existing callers receive signed refund cents here until they migrate to refund_performance. */
    public function cashTotals(string $tenantId, array $stores, array $range): array
    {
        return [
            'gross_cents' => $this->metricTotal($tenantId, $stores, $range, 'cash_performance'),
            'refund_cents' => -$this->metricTotal($tenantId, $stores, $range, 'refund_performance'),
        ];
    }

    public function dailyStoreTotals(string $tenantId, array $stores, array $range, string $metricCode): array
    {
        return $this->registered->dailyStoreTotals($metricCode, $tenantId, $stores, $range);
    }

    public function personnelTotals(string $tenantId, array $stores, array $range, string $metricCode, array $pairs): array
    {
        return $this->registered->personnelTotals($metricCode, $tenantId, $stores, $range, $pairs);
    }

    /**
     * Keeps the immutable read transaction on the same registry-owned reader
     * when a registered object dimension (member, operator, project, …) is
     * requested. The facade owns no metric mapping of its own.
     *
     * @return array<int,array{entity_id:int,entity_name:string,metric_value:int}>
     */
    public function dimensionRanking(string $metricCode, string $dimension, string $tenantId, array $stores, array $range, int $limit = 20, string $order = 'desc'): array
    {
        return $this->registered->dimensionRanking($metricCode, $dimension, $tenantId, $stores, $range, $limit, $order);
    }

    public function storeNames(array $stores): array
    {
        return $this->registered->storeNames($stores);
    }

}
