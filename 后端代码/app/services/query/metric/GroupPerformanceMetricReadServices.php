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

    /**
     * Returns the total for one registered analytical object class, such as
     * all project sale lines. The dimension owns any source-type split.
     */
    public function dimensionSummary(string $metricCode, string $dimension, string $tenantId, array $stores, array $range): int
    {
        return $this->registered->dimensionSummary($metricCode, $dimension, $tenantId, $stores, $range);
    }

    /** One locally-bound analytical subject; never an unbounded dimension listing. */
    public function dimensionSelectionTotal(string $metricCode, string $dimension, string $tenantId, array $stores, array $range, int $entityId): int
    {
        return $this->registered->dimensionSelectionTotal($metricCode,$dimension,$tenantId,$stores,$range,$entityId);
    }

    /** @param array{subject:string,aggregation:string,operator:string,amount_cents:int} $condition */
    public function thresholdCount(string $tenantId, array $stores, array $range, string $metricCode, array $condition): int
    {
        return $this->registered->thresholdCount($metricCode, $tenantId, $stores, $range, $condition);
    }

    /** Exact count plus a bounded member-name page from one registered population. */
    public function thresholdMembers(string $tenantId, array $stores, array $range, string $metricCode, array $condition, int $limit = 100): array
    {
        return $this->registered->thresholdMembers($metricCode, $tenantId, $stores, $range, $condition, $limit);
    }

    /** Exact count plus a bounded page from one registered member condition set. */
    public function conditionMembers(string $tenantId, array $stores, array $range, array $conditionSet, int $limit = 100): array
    {
        return $this->registered->conditionMembers($tenantId, $stores, $range, $conditionSet, $limit);
    }

    /** Exact count plus a bounded page for registered fact-dimension objects. */
    public function conditionDimensions(string $tenantId, array $stores, array $range, array $conditionSet, int $limit = 100): array
    {
        return $this->registered->conditionDimensions($tenantId,$stores,$range,$conditionSet,$limit);
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

    /**
     * Decorates identities returned by one registered ranking with another
     * registry-owned fact. The facade keeps the AI layer out of reader
     * strategy selection and does not expose a general entity lookup.
     */
    public function dimensionValues(string $metricCode,string $dimension,string $tenantId,array $stores,array $range,array $entityIds): array
    {
        return $this->registered->dimensionValues($metricCode,$dimension,$tenantId,$stores,$range,$entityIds);
    }

    public function storeNames(array $stores): array
    {
        return $this->registered->storeNames($stores);
    }

}
