<?php

declare(strict_types=1);

namespace app\services\report;

use think\facade\Db;

/**
 * Rebuildable daily read model for the group management dashboard.
 *
 * This is deliberately not a business source of truth. It only summarizes
 * registered actual-performance and consumption metrics. It can be deleted
 * and regenerated without changing orders, facts or dashboard targets.
 */
final class GroupManagementDashboardDailyAggregateServices
{
    public const TABLE = 'cashier_v3_group_dashboard_daily_aggregate';
    public const METRIC_VERSION = 'registered-actual-consume-v1';
    public const AGGREGATE_SCOPE = '仅供无分类筛选的离线对账；完成可靠失效机制前不得用于看板展示。';

    /**
     * Replaces only this read-model's rows in the requested range.
     *
     * @param array<int,int|string> $storeIds Empty means every store in this tenant.
     * @return array<string,int|string|bool>
     */
    public function rebuild(string $tenantId, array $storeIds, string $startDate, string $endDate): array
    {
        $tenantId = $this->tenantId($tenantId);
        $stores = $this->storeIds($storeIds);
        $range = $this->range($startDate, $endDate);
        $now = time();

        Db::startTrans();
        try {
            // Read registered metrics and replace their disposable projection
            // in one transaction. Backdated changes are checked by value in
            // status(); this is not an online invalidation guarantee.
            $sourceRows = $this->sourceGroups($tenantId, $stores, $range);
            $delete = Db::name(self::TABLE)
                ->where('tenant_id', $tenantId)
                ->whereBetween('business_date', [$range['start'], $range['end']]);
            if ($stores !== []) {
                $delete->whereIn('store_id', $stores);
            }
            $deleted = (int)$delete->delete();

            $rows = [];
            foreach ($sourceRows as $source) {
                $rows[] = [
                    'tenant_id' => $tenantId,
                    'store_id' => (int)$source['store_id'],
                    'business_date' => (string)$source['business_date'],
                    'actual_performance_cents' => (int)$source['actual_performance_cents'],
                    'consumption_performance_cents' => (int)$source['consumption_performance_cents'],
                    'metric_version' => self::METRIC_VERSION,
                    // Legacy columns remain for table compatibility; neither
                    // is a valid watermark for derived registered metrics.
                    'source_fact_count' => (int)$source['source_fact_count'],
                    'source_max_recorded_at' => (int)$source['source_max_recorded_at'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            $inserted = 0;
            foreach (array_chunk($rows, 500) as $chunk) {
                $inserted += (int)Db::name(self::TABLE)->insertAll($chunk);
            }
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }

        $status = $this->status($tenantId, $stores, $range['start'], $range['end']);
        $stale = (int)($status['stale_group_count'] ?? 0);
        $sourceMaxRecordedAt = (int)($status['source_max_recorded_at'] ?? 0);
        $aggregateUpdatedAt = (int)($status['aggregate_updated_at'] ?? 0);
        $checkedAt = time();
        $statusCode = $stale === 0 ? 'caught_up' : 'stale';
        $lagSeconds = $stale === 0 || $sourceMaxRecordedAt <= 0
            ? 0
            : max(0, $sourceMaxRecordedAt - $aggregateUpdatedAt);

        return [
            'tenant_id' => $tenantId,
            'start_date' => $range['start'],
            'end_date' => $range['end'],
            'store_scope_count' => count($stores),
            'source_group_count' => count($sourceRows),
            'deleted_rows' => $deleted,
            'inserted_rows' => $inserted,
            'aggregation_caught_up' => (bool)$status['aggregation_caught_up'],
            'status_code' => $statusCode,
            'stale_group_count' => $stale,
            'source_max_recorded_at' => $sourceMaxRecordedAt,
            'aggregate_updated_at' => $aggregateUpdatedAt,
            'lag_seconds' => $lagSeconds,
            'checked_at' => $checkedAt,
            'aggregate_scope' => self::AGGREGATE_SCOPE,
        ];
    }

    /**
     * Returns aggregate rows for subsequent dashboard reads. This method has
     * no fallback to facts: callers can use status() to decide whether a read
     * model is fresh enough for a high-frequency overview.
     *
     * @param array<int,int|string> $storeIds
     * @return array<int,array<string,mixed>>
     */
    public function rows(string $tenantId, array $storeIds, string $startDate, string $endDate): array
    {
        $tenantId = $this->tenantId($tenantId);
        $stores = $this->storeIds($storeIds);
        $range = $this->range($startDate, $endDate);
        $query = Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->whereBetween('business_date', [$range['start'], $range['end']]);
        if ($stores !== []) {
            $query->whereIn('store_id', $stores);
        }
        return $query->where('metric_version', self::METRIC_VERSION)
            ->field('store_id,business_date,actual_performance_cents,consumption_performance_cents,metric_version,source_fact_count,source_max_recorded_at,updated_at')
            ->order('business_date', 'asc')->order('store_id', 'asc')->select()->toArray();
    }

    /**
     * Compare registered amounts and metric version for every store/day.
     * This explicit diagnostic is expensive and is never run on page load.
     *
     * @param array<int,int|string> $storeIds
     * @return array<string,int|bool|string>
     */
    public function status(string $tenantId, array $storeIds, string $startDate, string $endDate): array
    {
        $tenantId = $this->tenantId($tenantId);
        $stores = $this->storeIds($storeIds);
        $range = $this->range($startDate, $endDate);
        $sourceRows = $this->sourceGroups($tenantId, $stores, $range);
        $aggregateRows = $this->aggregateGroups($tenantId, $stores, $range);
        $aggregateByKey = [];
        $aggregateMaxUpdatedAt = 0;
        $aggregateMaxSourceRecordedAt = 0;
        foreach ($aggregateRows as $row) {
            $key = $this->groupKey((int)$row['store_id'], (string)$row['business_date']);
            $aggregateByKey[$key] = $row;
            $aggregateMaxUpdatedAt = max($aggregateMaxUpdatedAt, (int)$row['updated_at']);
            $aggregateMaxSourceRecordedAt = max($aggregateMaxSourceRecordedAt, (int)$row['source_max_recorded_at']);
        }

        $sourceMaxRecordedAt = 0;
        $stale = 0;
        foreach ($sourceRows as $source) {
            $sourceMaxRecordedAt = max($sourceMaxRecordedAt, (int)$source['source_max_recorded_at']);
            $key = $this->groupKey((int)$source['store_id'], (string)$source['business_date']);
            $aggregate = $aggregateByKey[$key] ?? null;
            if ($aggregate === null
                || (string)$aggregate['metric_version'] !== self::METRIC_VERSION
                || (int)$aggregate['actual_performance_cents'] !== (int)$source['actual_performance_cents']
                || (int)$aggregate['consumption_performance_cents'] !== (int)$source['consumption_performance_cents']) {
                $stale++;
            }
            unset($aggregateByKey[$key]);
        }
        // Read-model rows without a remaining effective source group are stale too.
        $stale += count($aggregateByKey);
        $checkedAt = time();
        $statusCode = $stale === 0 ? 'caught_up' : 'stale';
        $lagSeconds = $stale === 0 || $sourceMaxRecordedAt <= 0
            ? 0
            : max(0, $sourceMaxRecordedAt - $aggregateMaxUpdatedAt);

        return [
            'tenant_id' => $tenantId,
            'start_date' => $range['start'],
            'end_date' => $range['end'],
            'source_group_count' => count($sourceRows),
            'aggregate_group_count' => count($aggregateRows),
            'stale_group_count' => $stale,
            'source_max_recorded_at' => $sourceMaxRecordedAt,
            'aggregate_source_max_recorded_at' => $aggregateMaxSourceRecordedAt,
            'aggregate_updated_at' => $aggregateMaxUpdatedAt,
            'aggregation_caught_up' => $stale === 0,
            'status_code' => $statusCode,
            'lag_seconds' => $lagSeconds,
            'checked_at' => $checkedAt,
            'aggregate_scope' => self::AGGREGATE_SCOPE,
            'status_explanation' => '按门店和业务日期比对统一指标的实际业绩、消耗业绩净额及口径版本；仅代表本次离线检查一致，不保证随后没有回溯变更。',
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function sourceGroups(string $tenantId, array $stores, array $range): array
    {
        if ($stores === []) {
            $stores = array_map('intval', Db::name('system_store')->column('id'));
        }
        if ($stores === []) return [];
        $reader = new \app\services\query\metric\RegisteredMetricReadServices();
        $groups = [];
        // Actual performance is registered cash minus refund; consumption is
        // the completed-service amount. Never infer either from retired fact types.
        foreach (['actual_performance' => 'actual_performance_cents', 'consume_amount' => 'consumption_performance_cents'] as $metric => $column) {
            foreach ($reader->dailyStoreTotals($metric, $tenantId, $stores, $range) as $point) {
                $storeId = (int)$point['store_id'];
                $day = (string)$point['business_date'];
                $key = $this->groupKey($storeId, $day);
                if (!isset($groups[$key])) {
                    $groups[$key] = ['store_id' => $storeId, 'business_date' => $day,
                        'actual_performance_cents' => 0, 'consumption_performance_cents' => 0,
                        'source_fact_count' => 0, 'source_max_recorded_at' => 0];
                }
                $groups[$key][$column] = (int)$point['amount_cents'];
            }
        }
        $rows = array_values($groups);
        usort($rows, static fn(array $a, array $b): int => strcmp($a['business_date'], $b['business_date'])
            ?: $a['store_id'] <=> $b['store_id']);
        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    private function aggregateGroups(string $tenantId, array $stores, array $range): array
    {
        $query = Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->whereBetween('business_date', [$range['start'], $range['end']]);
        if ($stores !== []) {
            $query->whereIn('store_id', $stores);
        }
        return $query->field('store_id,business_date,actual_performance_cents,consumption_performance_cents,metric_version,source_fact_count,source_max_recorded_at,updated_at')
            ->select()->toArray();
    }

    /** @return array{start:string,end:string} */
    private function range(string $startDate, string $endDate): array
    {
        if (!$this->validDate($startDate) || !$this->validDate($endDate) || $startDate > $endDate) {
            throw new \InvalidArgumentException('集团看板日聚合日期范围无效');
        }
        return ['start' => $startDate, 'end' => $endDate];
    }

    private function validDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    private function tenantId(string $tenantId): string
    {
        $tenantId = trim($tenantId);
        if ($tenantId === '' || strlen($tenantId) > 32) {
            throw new \InvalidArgumentException('集团看板日聚合租户范围无效');
        }
        return $tenantId;
    }

    /** @param array<int,int|string> $storeIds @return array<int,int> */
    private function storeIds(array $storeIds): array
    {
        $stores = array_values(array_unique(array_filter(array_map('intval', $storeIds), static function (int $id): bool {
            return $id > 0;
        })));
        sort($stores, SORT_NUMERIC);
        return $stores;
    }

    private function groupKey(int $storeId, string $businessDate): string
    {
        return $storeId . ':' . $businessDate;
    }
}
