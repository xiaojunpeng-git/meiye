<?php
declare(strict_types=1);

namespace app\services\product\inventory\query;

use app\services\product\inventory\InventoryMovementAnalyticsServices;
use app\services\query\UnifiedQueryException;

abstract class InventoryStatisticsUnifiedQueryProvider extends InventoryOperationalUnifiedQueryProvider
{
    /** 入出库使用周期事实聚合，临期与库龄仍使用截止日批次快照，二者不混算。 */
    protected function sourceRows(array $context, array $payload = []): array
    {
        $pageCode = $this->pageCode();
        $storeId = (int)$context['store_id'];
        $canViewCost = in_array(InventoryBatchStockQueryContract::PERMISSION_COST, (array)$context['permissions'], true);
        $locationIds = array_values(array_unique(array_map('intval', (array)(($context['scope_dimensions'] ?? [])['location_id'] ?? []))));
        if (!$locationIds) throw new UnifiedQueryException('UNIFIED_QUERY_SCOPE_INVALID', '库存统计缺少服务端仓库范围。', []);
        if (in_array($pageCode, ['inventory_statistics_inbound', 'inventory_statistics_outbound'], true)) {
            $kind = $pageCode === 'inventory_statistics_inbound' ? 'inbound' : 'outbound';
            [$from, $to] = $this->movementPeriod($payload, (string)$context['query_cutoff_date']);
            if ($from > $to) return [];
            // 日期必须先进入事实 SQL 的 where，再以业务类型、商品与 SKU 汇总；权限、导出与分页共用这一批周期行。
            $rows = (new InventoryMovementAnalyticsServices())->listForLocations($locationIds, $kind, $from, $to, $canViewCost, true)['list'];
            foreach ($rows as &$row) {
                $row['record_id'] = $pageCode . ':' . hash('sha256', json_encode([
                    $row['source_type'], $row['product_id'], $row['sku_id'], $row['stock_unit'],
                    $row['quantity_scale'], $row['product_name'], $row['sku_name'],
                ], JSON_UNESCAPED_UNICODE));
                $row['tenant_id'] = (string)$context['tenant_id'];
                $row['store_id'] = $storeId;
            }
            unset($row);
            return $this->boundedStatistics($rows);
        }

        $scope = new InventoryBatchStockDataScope((string)$context['tenant_id'], $locationIds, (array)$context['permissions']);
        $rows = (new InventoryBatchStockQueryProvider())->sourceRows([
            'queryCutoffDate' => (string)$context['query_cutoff_date'], 'locationIds' => $locationIds, 'includeZero' => false,
        ], $scope);
        $cutoff = new \DateTimeImmutable((string)$context['query_cutoff_date']);
        foreach ($rows as &$row) {
            $row['record_id'] = $pageCode . ':' . (string)$row['batch_balance_id'];
            $row['inventory_amount'] = $row['inventory_amount'] ?? null;
            $received = $this->dateOrNull($row['received_date'] ?? null);
            $expires = $this->dateOrNull($row['expire_date'] ?? null);
            $row['remaining_shelf_life_days'] = $expires ? (int)$cutoff->diff($expires)->format('%r%a') : null;
            $row['remaining_shelf_life_band'] = $this->expiryBand($row['remaining_shelf_life_days']);
            $row['inventory_age_days'] = $received ? (int)$received->diff($cutoff)->format('%r%a') : null;
            $row['inventory_age_band'] = $this->ageBand($row['inventory_age_days']);
        }
        unset($row);
        return $this->boundedStatistics($rows);
    }

    /** 周期控件的起止日会规范成 >= 和 <= 两条 AND 条件；先下推事实范围，其他日期谓词不能在聚合后重解释。 */
    private function movementPeriod(array $payload, string $cutoff): array
    {
        $from = '2000-01-01';
        $to = $cutoff;
        foreach (['filters', 'quickFilters', 'keywordFilters'] as $key) {
            foreach ((array)($payload[$key] ?? []) as $filter) {
                if ((string)($filter['field_key'] ?? '') === 'business_date') {
                    throw new UnifiedQueryException('UNIFIED_QUERY_STATISTICS_PERIOD_INVALID', '请使用业务日期周期筛选库存统计。', []);
                }
            }
        }
        foreach ((array)($payload['topFilterConditions'] ?? []) as $filter) {
            if ((string)($filter['field_key'] ?? '') !== 'business_date') continue;
            $value = $filter['value'] ?? null;
            $operator = (string)($filter['operator'] ?? '');
            if ($operator === 'between' && is_array($value) && count($value) === 2) {
                $from = max($from, (string)$value[0]);
                $to = min($to, (string)$value[1]);
            } elseif ($operator === 'greater_or_equal' && is_string($value)) {
                $from = max($from, $value);
            } elseif ($operator === 'less_or_equal' && is_string($value)) {
                $to = min($to, $value);
            } else {
                throw new UnifiedQueryException('UNIFIED_QUERY_STATISTICS_PERIOD_INVALID', '请使用业务日期周期筛选库存统计。', []);
            }
        }
        return [$from, $to];
    }

    private function boundedStatistics(array $rows): array
    {
        if (count($rows) > 10000) throw new UnifiedQueryException('UNIFIED_QUERY_SOURCE_WINDOW_TOO_LARGE', '当前统计数据量较大，请缩小查询范围后再试。', []);
        return $rows;
    }

    private function dateOrNull($value): ?\DateTimeImmutable
    {
        $value = trim((string)$value);
        return $value === '' ? null : new \DateTimeImmutable($value);
    }

    private function expiryBand(?int $days): string
    {
        if ($days === null) return '到期日未知';
        if ($days < 0) return '已过期';
        foreach ([30 => '0-30天', 45 => '31-45天', 60 => '46-60天', 90 => '61-90天', 180 => '91-180天', 365 => '1年内', 730 => '1-2年', 1095 => '2-3年'] as $max => $label) if ($days <= $max) return $label;
        return '3年以上';
    }

    private function ageBand(?int $days): string
    {
        if ($days === null) return '入库日期未知';
        if ($days < 0) return '未来入库';
        if ($days <= 30) return '0-30天';
        if ($days <= 90) return '31-90天';
        if ($days <= 180) return '91-180天';
        if ($days <= 365) return '半年-1年';
        return '1年以上';
    }
}
