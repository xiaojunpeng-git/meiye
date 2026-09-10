<?php

declare(strict_types=1);

namespace app\services\report;

use think\facade\Db;

/**
 * Platform/mobile read contract for the group management dashboard.
 *
 * The dashboard never derives metrics in a browser. Payment and performance
 * facts keep signed reversals, while category rows use frozen sale/card-item
 * allocations so cards do not use a card shell category.
 */
final class GroupManagementDashboardServices
{
    public const DASHBOARD_CODE = 'group_management_dashboard';
    public const METRIC_VERSION = 'group-management-cash-recharge-v2';
    public const COVERAGE_START = '2026-08-10';

    private $organizationDimensions;
    private $targets;
    private $dailyAggregate;

    public function __construct(
        ?StoreUnifiedReportOrganizationDimensionServices $organizationDimensions = null,
        ?GroupManagementDashboardTargetServices $targets = null,
        ?GroupManagementDashboardDailyAggregateServices $dailyAggregate = null
    ) {
        $this->organizationDimensions = $organizationDimensions ?: new StoreUnifiedReportOrganizationDimensionServices();
        $this->targets = $targets ?: new GroupManagementDashboardTargetServices();
        $this->dailyAggregate = $dailyAggregate ?: new GroupManagementDashboardDailyAggregateServices();
    }

    /** @return array<string,mixed> */
    public function dashboard(array $context, array $input): array
    {
        $scope = $this->scope($context, $input);
        $range = $this->range($input);
        $categoryTree = $this->categories();
        $selectedCategory = max(0, (int)($input['category_id'] ?? 0));
        $categoryIds = $selectedCategory > 0 ? $this->descendants($selectedCategory, $categoryTree['children']) : [];
        if ($selectedCategory > 0 && $categoryIds === []) throw new \InvalidArgumentException('商品分类不存在或已停用');
        // The daily aggregate has no category grain. It is only used for an
        // unfiltered overview, trend and organization total; category paths
        // continue to use the frozen line facts below.
        $aggregateEligible = $categoryIds === [];
        $aggregateRange = $this->aggregateRange($range);
        $aggregateStatus = $aggregateEligible
            ? $this->dailyAggregate->status($scope['tenant_id'], $scope['store_ids'], $aggregateRange['start'], $aggregateRange['end'])
            : null;
        $aggregateReady = $aggregateStatus !== null
            && (bool)$aggregateStatus['aggregation_caught_up']
            && $this->aggregateMatchesFacts($scope['tenant_id'], $scope['store_ids'], $range);
        $aggregationHealth = $aggregateStatus === null
            ? [
                'status_code' => 'not_applicable',
                'aggregation_caught_up' => false,
                'stale_group_count' => 0,
                'lag_seconds' => 0,
                'checked_at' => time(),
                'scope' => 'category_filtered',
            ]
            : [
                'status_code' => $aggregateReady ? 'caught_up' : 'stale',
                'aggregation_caught_up' => $aggregateReady,
                'stale_group_count' => (int)($aggregateStatus['stale_group_count'] ?? 0),
                'source_max_recorded_at' => (int)($aggregateStatus['source_max_recorded_at'] ?? 0),
                'aggregate_updated_at' => (int)($aggregateStatus['aggregate_updated_at'] ?? 0),
                'lag_seconds' => (int)($aggregateStatus['lag_seconds'] ?? 0),
                'checked_at' => (int)($aggregateStatus['checked_at'] ?? time()),
                'scope' => 'unfiltered_overview',
            ];
        // Product dashboard summary requests do not need row-level cash,
        // service, trend or ranking projections. For an unfiltered scope,
        // aggregate the two required facts in SQL so a high-volume month does
        // not materialize every payment allocation in the PHP worker.
        if (!empty($input['summary_only']) && $categoryIds === []) {
            $reader = new \app\services\query\metric\RegisteredMetricReadServices();
            $cash = $reader->summary('cash_performance', $scope['tenant_id'], $scope['store_ids'], $range);
            $refund = $reader->summary('refund_performance', $scope['tenant_id'], $scope['store_ids'], $range);
            $actual = $reader->summary('actual_performance', $scope['tenant_id'], $scope['store_ids'], $range);
            $consumption = $reader->summary('consume_amount', $scope['tenant_id'], $scope['store_ids'], $range);
            return [
                'cards' => [
                    $this->registeredMetric('cash_performance', 'cash_performance', $cash),
                    $this->registeredMetric('refund_performance', 'refund_performance', $refund),
                    $this->registeredMetric('actual_performance', 'actual_performance', $actual),
                    $this->registeredMetric('consumption_performance', 'consume_amount', $consumption),
                    $this->metric('consumption_count', '消耗数量', 0, 'count', '商品看板摘要不展示消耗数量。'),
                    $this->metric('consumption_unit_price', '消耗单价', null, 'money', '商品看板摘要不展示消耗单价。'),
                ],
                'metric_version' => self::METRIC_VERSION,
                'aggregation_caught_up' => $aggregateStatus === null
                    ? false
                    : (bool)$aggregateStatus['aggregation_caught_up'],
            ];
        }
        $cashRows = $this->cashRows($scope['tenant_id'], $scope['store_ids'], $range, $categoryIds);
        $serviceRows = $this->serviceRows($scope['tenant_id'], $scope['store_ids'], $range, $categoryIds);
        if ($categoryIds === []) {
            $reader = new \app\services\query\metric\RegisteredMetricReadServices();
            $cash = $reader->summary('cash_performance', $scope['tenant_id'], $scope['store_ids'], $range);
            $refund = $reader->summary('refund_performance', $scope['tenant_id'], $scope['store_ids'], $range);
            $actual = $reader->summary('actual_performance', $scope['tenant_id'], $scope['store_ids'], $range);
            $consumption = $reader->summary('consume_amount', $scope['tenant_id'], $scope['store_ids'], $range);
            $serviceCount = $reader->summary('completed_service_item_count', $scope['tenant_id'], $scope['store_ids'], $range);
        } else {
            $reader = new \app\services\query\metric\RegisteredMetricReadServices();
            $cash = $reader->categorySummary('cash_performance', $scope['tenant_id'], $scope['store_ids'], $range, $categoryIds);
            $refund = $reader->categorySummary('refund_performance', $scope['tenant_id'], $scope['store_ids'], $range, $categoryIds);
            $actual = $reader->categorySummary('actual_performance', $scope['tenant_id'], $scope['store_ids'], $range, $categoryIds);
            $consumption = $reader->categorySummary('consume_amount', $scope['tenant_id'], $scope['store_ids'], $range, $categoryIds);
            $serviceCount = $reader->categorySummary('completed_service_item_count', $scope['tenant_id'], $scope['store_ids'], $range, $categoryIds);
        }
        $month = substr($range['end'], 0, 7);
        $year = (int)substr($range['end'], 0, 4);
        $cards = [
            $this->registeredMetric('cash_performance', 'cash_performance', $cash),
            $this->registeredMetric('refund_performance', 'refund_performance', $refund),
            $this->registeredMetric('actual_performance', 'actual_performance', $actual),
            $this->registeredMetric('consumption_performance', 'consume_amount', $consumption),
            $this->registeredMetric('consumption_count', 'completed_service_item_count', $serviceCount),
            $this->metric('consumption_unit_price', '消耗单价', $serviceCount > 0 ? (int)round($consumption / $serviceCount) : null, 'money', '消耗业绩除以消耗数量；分母为零显示 -。'),
        ];
        if (!empty($input['summary_only'])) {
            return [
                'cards' => $cards,
                'metric_version' => self::METRIC_VERSION,
                'aggregation_caught_up' => $aggregateStatus === null
                    ? false
                    : (bool)$aggregateStatus['aggregation_caught_up'],
            ];
        }
        $goalMonths = $this->monthsForRange($range);
        $targets = $this->targets->totals(['tenant_id' => $scope['tenant_id'], 'store_ids' => $scope['store_ids']], $year, $goalMonths);
        $monthTargets = $this->targets->totals(['tenant_id' => $scope['tenant_id'], 'store_ids' => $scope['store_ids']], $year, [(int)substr($range['end'], 5, 2)]);
        $categoryCards = (new \app\services\query\metric\RegisteredMetricReadServices())->categoryDashboardCards(
            'cash_performance', $scope['tenant_id'], $scope['store_ids'], $range, $categoryTree['roots'], $categoryTree['children'], $categoryIds
        );
        $currentTarget = array_sum($monthTargets);
        $yearTarget = array_sum($targets);
        $monthActual = $this->actualCashTotal($scope['tenant_id'], $scope['store_ids'], ['start' => $month . '-01', 'end' => $range['end']], $categoryIds);
        $yearActual = $this->actualCashTotal($scope['tenant_id'], $scope['store_ids'], ['start' => sprintf('%04d-01-01', $year), 'end' => $range['end']], $categoryIds);

        return [
            'dashboard_code' => self::DASHBOARD_CODE,
            'title' => '集团管理看板',
            'scope' => ['store_ids' => $scope['store_ids'], 'range' => $range, 'selected_category_id' => $selectedCategory],
            'filter_schema' => $this->filterSchema($categoryTree['roots']),
            'cards' => $cards,
            'trend' => $this->trend($scope['tenant_id'], $scope['store_ids'], $range, $categoryIds, $aggregateReady),
            'categories' => $categoryCards,
            'sources' => $this->sources($scope['store_ids'], $range, $cashRows, $serviceRows),
            'goals' => [
                'month' => $this->goalProjection('本月目标进度', $currentTarget, $monthActual, '所选月份内门店手动目标之和与同范围实际业绩。'),
                'year' => $this->goalProjection('本年目标进度', $yearTarget, $yearActual, '所选年度内门店手动目标之和与同范围实际业绩。'),
                'source_explanation' => '目标只在集团管理看板按门店月度手动保存；分公司、城市经理和集团目标由门店目标自动汇总。该设置不读取、不修改，也不影响任何既有目标或其他功能。',
            ],
            'rankings' => $this->rankings($scope['tenant_id'], $scope['store_ids'], ['start' => $month . '-01', 'end' => $range['end']], $monthTargets, $aggregateReady, $categoryIds),
            'alerts' => $this->alerts($scope['tenant_id'], $scope['store_ids'], ['start' => $month . '-01', 'end' => $range['end']], $monthTargets, $aggregateReady, $categoryIds),
            'field_explanations' => $this->fieldExplanations(),
            'target_management' => $this->targets->yearTargets(['tenant_id' => $scope['tenant_id'], 'store_ids' => $scope['store_ids']], $year),
            'metric_version' => self::METRIC_VERSION,
            'data_as_of' => date('Y-m-d H:i:s'),
            'aggregation_caught_up' => $aggregateStatus === null ? false : (bool)$aggregateStatus['aggregation_caught_up'],
            'aggregation_status' => $aggregateStatus === null
                ? '已选择商品分类，分类指标按冻结明细事实实时读取；日聚合只覆盖未筛选分类的集团总计、趋势和组织总计。'
                : ($aggregateReady ? (string)$aggregateStatus['status_explanation'] : '日聚合正在追平，当前结果暂由统一事实层实时读取，聚合追平后将自动切换。'),
            'aggregation_health' => $aggregationHealth,
            'coverage_start' => self::COVERAGE_START,
        ];
    }

    /** @return array<string,mixed> */
    public function targetSave(array $context, array $payload): array
    {
        return $this->targets->save($context, $payload);
    }

    /** @return array<string,mixed> */
    public function drilldown(array $context, array $input): array
    {
        $scope = $this->scope($context, $input);
        $range = $this->range($input);
        $categoryIds = [];
        $categoryId = max(0, (int)($input['category_id'] ?? 0));
        if ($categoryId > 0) $categoryIds = $this->descendants($categoryId, $this->categories()['children']);
        $metric = trim((string)($input['metric_code'] ?? 'cash_performance'));
        if (!in_array($metric, ['cash_performance', 'actual_performance', 'consumption_performance', 'refund_performance'], true)) {
            throw new \InvalidArgumentException('下钻指标无效');
        }
        $canonical = $metric === 'consumption_performance' ? 'consume_amount' : $metric;
        $rows = (new \app\services\query\metric\RegisteredMetricReadServices())
            ->categoryRows($canonical, $scope['tenant_id'], $scope['store_ids'], $range, $categoryIds);
        return [
            'metric_code' => $metric,
            'records' => $rows,
            'source_explanation' => $this->fieldExplanations()[$metric] ?? '',
            'drilldown_conditions' => ['start_date' => $range['start'], 'end_date' => $range['end'], 'store_ids' => $scope['store_ids'], 'category_ids' => $categoryIds],
            'metric_version' => self::METRIC_VERSION,
            'data_as_of' => date('Y-m-d H:i:s'),
        ];
    }

    private function scope(array $context, array $input): array
    {
        $tenant = trim((string)($context['tenant_id'] ?? '0'));
        if ($tenant === '') throw new \InvalidArgumentException('看板租户范围缺失');
        $allowed = array_values(array_unique(array_filter(array_map('intval', (array)($context['store_ids'] ?? [])))));
        $requested = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($input['store_ids'] ?? ''))))));
        if ($requested !== []) $allowed = array_values(array_intersect($allowed, $requested));
        if ($allowed === []) throw new \InvalidArgumentException('当前账号没有可查看的门店范围');
        sort($allowed);
        return ['tenant_id' => $tenant, 'store_ids' => $allowed];
    }

    /** @return array{start:string,end:string} */
    private function range(array $input): array
    {
        $start = trim((string)($input['start_date'] ?? '')) ?: date('Y-m-d');
        $end = trim((string)($input['end_date'] ?? '')) ?: $start;
        if (!$this->validDate($start) || !$this->validDate($end) || $start > $end || strtotime($end) - strtotime($start) > 366 * 86400) {
            throw new \InvalidArgumentException('统计日期范围无效，请选择不超过 366 天的开始和结束日期。');
        }
        return compact('start', 'end');
    }

    private function validDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    /** @return array{roots:array<int,array<string,mixed>>,children:array<int,array<int,int>>} */
    private function categories(): array
    {
        // 商品分类表同时保存平台配置和按门店同步的副本。看板仅使用平台端
        // 当前启用的分类正本；否则同一分类会按每个门店重复展示。
        $rows = Db::name('store_product_category')->where('type', 0)->where('relation_id', 0)
            ->where('is_show', 1)->field('id,pid,cate_name')->order('pid', 'asc')->order('id', 'asc')->select()->toArray();
        $ids = [];
        $children = [];
        foreach ($rows as $row) {
            $id = (int)$row['id']; $pid = (int)$row['pid'];
            $ids[$id] = ['id' => $id, 'pid' => $pid, 'name' => (string)$row['cate_name']];
            $children[$pid][] = $id;
        }
        $roots = [];
        foreach ($ids as $id => $row) if (!isset($ids[(int)$row['pid']]) || (int)$row['pid'] <= 0) $roots[] = $row;
        return ['roots' => $roots, 'children' => $children];
    }

    /** @return array<int,int> */
    private function descendants(int $root, array $children): array
    {
        $result = []; $queue = [$root];
        while ($queue !== []) {
            $id = (int)array_shift($queue);
            if ($id <= 0 || isset($result[$id])) continue;
            $result[$id] = $id;
            foreach ((array)($children[$id] ?? []) as $child) $queue[] = (int)$child;
        }
        return array_values($result);
    }

    /** Registered signed net-cash rows at frozen category grain. @return array<int,array<string,mixed>> */
    private function cashRows(string $tenantId, array $stores, array $range, array $categoryIds): array
    {
        return (new \app\services\query\metric\RegisteredMetricReadServices())
            ->categoryRows('actual_performance', $tenantId, $stores, $range, $categoryIds);
    }

    /** @return array<int,array<string,mixed>> */
    private function serviceRows(string $tenantId, array $stores, array $range, array $categoryIds): array
    {
        return (new \app\services\query\metric\RegisteredMetricReadServices())
            ->categoryRows('completed_service_item_count', $tenantId, $stores, $range, $categoryIds);
    }

    private function performanceTotal(string $tenantId, array $stores, array $range, array $categoryIds): int
    {
        $reader = new \app\services\query\metric\RegisteredMetricReadServices();
        return $categoryIds === []
            ? $reader->summary('consume_amount', $tenantId, $stores, $range)
            : $reader->categorySummary('consume_amount', $tenantId, $stores, $range, $categoryIds);
    }

    private function actualCashTotal(string $tenantId, array $stores, array $range, array $categoryIds): int
    {
        $reader = new \app\services\query\metric\RegisteredMetricReadServices();
        return $categoryIds === []
            ? $reader->summary('actual_performance', $tenantId, $stores, $range)
            : $reader->categorySummary('actual_performance', $tenantId, $stores, $range, $categoryIds);
    }

    /** @return array<string,int> */
    private function dailyCashPerformance(string $tenantId, array $stores, array $range, array $categoryIds): array
    {
        $out = [];
        $reader = new \app\services\query\metric\RegisteredMetricReadServices();
        $rows = $categoryIds === []
            ? $reader->dailyTotals('actual_performance', $tenantId, $stores, $range)
            : $reader->categoryDailyTotals('actual_performance', $tenantId, $stores, $range, $categoryIds);
        foreach ($rows as $row) $out[(string)$row['business_date']] = (int)$row['metric_value'];
        return $out;
    }

    /** @return array<string,mixed> */
    private function trend(string $tenantId, array $stores, array $range, array $categoryIds, bool $aggregateEligible): array
    {
        $periodStart = substr($range['start'], 0, 7) . '-01';
        $periodEnd = date('Y-m-t', strtotime($range['end']));
        // A year query is identified by its Jan 1 start. The caller may use
        // today's date as the end while the year is still in progress, so a
        // duration threshold would incorrectly fall back to daily labels.
        $isYear = substr($range['start'], 5, 5) === '01-01'
            && substr($range['start'], 0, 4) === substr($range['end'], 0, 4);
        if ($isYear) {
            $points = [];
            for ($month = 1; $month <= 12; $month++) {
                $start = sprintf('%s-%02d-01', substr($range['end'], 0, 4), $month); $end = date('Y-m-t', strtotime($start));
                $monthRange = compact('start', 'end');
                $points[] = ['id' => substr($start, 0, 7), 'label' => sprintf('%02d月', $month), 'actual_performance_cents' => $this->actualCashTotal($tenantId, $stores, $monthRange, $categoryIds), 'consumption_performance_cents' => $categoryIds === [] ? (new \app\services\query\metric\RegisteredMetricReadServices())->summary('consume_amount', $tenantId, $stores, $monthRange) : $this->performanceTotal($tenantId, $stores, $monthRange, $categoryIds)];
            }
            return ['granularity' => 'month', 'range_label' => '本年（自然月）', 'points' => $points, 'source_explanation' => '本年按自然月汇总实际业绩和消耗业绩。'];
        }
        $trendRange = ['start' => $periodStart, 'end' => $periodEnd];
        $actual = $this->dailyCashPerformance($tenantId, $stores, $trendRange, $categoryIds);
        $consumption = $this->dailyPerformance($tenantId, $stores, $trendRange, $categoryIds);
        $points = [];
        for ($day = $periodStart; $day <= $periodEnd; $day = date('Y-m-d', strtotime($day . ' +1 day'))) $points[] = ['id' => $day, 'label' => substr($day, 8) . '日', 'actual_performance_cents' => (int)($actual[$day] ?? 0), 'consumption_performance_cents' => (int)($consumption[$day] ?? 0)];
        return ['granularity' => 'day', 'range_label' => '所在月份（自然日）', 'points' => $points, 'source_explanation' => '今天和本月固定展示所在自然月从 1 日到最后一天的实际业绩和消耗业绩。'];
    }

    private function dailyPerformance(string $tenantId, array $stores, array $range, array $categoryIds): array
    {
        $out = [];
        $reader = new \app\services\query\metric\RegisteredMetricReadServices();
        $rows = $categoryIds === []
            ? $reader->dailyTotals('consume_amount', $tenantId, $stores, $range)
            : $reader->categoryDailyTotals('consume_amount', $tenantId, $stores, $range, $categoryIds);
        foreach ($rows as $row) $out[(string)$row['business_date']] = (int)$row['metric_value'];
        return $out;
    }

    /**
     * A caught-up flag alone cannot protect a read if a rebuild was performed
     * against a different fact projection. Compare the exact totals and the
     * per-store distribution before allowing the aggregate into any card,
     * trend, goal or ranking. Otherwise all projections use the same facts.
     */
    private function aggregateMatchesFacts(string $tenantId, array $stores, array $range): bool
    {
        $reader = new \app\services\query\metric\RegisteredMetricReadServices();
        $contracts = [
            'actual_performance' => 'actual_performance_cents',
            'consume_amount' => 'consumption_performance_cents',
        ];
        $expected = [];
        foreach ($contracts as $metricCode => $column) {
            foreach ($reader->dailyStoreTotals($metricCode, $tenantId, $stores, $range) as $row) {
                $expected[$metricCode][(int)$row['store_id']][(string)$row['business_date']] = (int)$row['amount_cents'];
            }
        }
        $actual = [];
        foreach ($this->dailyAggregate->rows($tenantId, $stores, $range['start'], $range['end']) as $row) {
            $storeId = (int)($row['store_id'] ?? 0);
            $day = (string)($row['business_date'] ?? '');
            foreach ($contracts as $metricCode => $column) {
                $actual[$metricCode][$storeId][$day] = (int)($row[$column] ?? 0);
            }
        }
        foreach ($contracts as $metricCode => $_column) {
            foreach ($stores as $storeId) {
                $days = array_unique(array_merge(
                    array_keys((array)($expected[$metricCode][(int)$storeId] ?? [])),
                    array_keys((array)($actual[$metricCode][(int)$storeId] ?? []))
                ));
                foreach ($days as $day) {
                    if ((int)($expected[$metricCode][(int)$storeId][$day] ?? 0)
                        !== (int)($actual[$metricCode][(int)$storeId][$day] ?? 0)) return false;
                }
            }
        }
        return true;
    }

    /** @return array{start:string,end:string} */
    private function aggregateRange(array $range): array
    {
        $start = substr($range['start'], 0, 7) . '-01';
        $end = date('Y-m-t', strtotime($range['end']));
        if (substr($range['start'], 0, 4) !== substr($range['end'], 0, 4)
            && strtotime($range['end']) - strtotime($range['start']) > 180 * 86400) {
            $start = substr($range['end'], 0, 4) . '-01-01';
            $end = substr($range['end'], 0, 4) . '-12-31';
        }
        return compact('start', 'end');
    }

    private function sources(array $stores, array $range, array $cashRows, array $serviceRows): array
    {
        $configured = Db::name('cashier_v3_business_source')->where('parent_id', 0)->where('status', 1)->order('sort', 'asc')->order('id', 'asc')->field('id,name')->select()->toArray();
        $out = [];
        foreach ($configured as $source) {
            $sourceId = (int)$source['id'];
            $name = (string)$source['name'];
            $matches = array_values(array_filter($cashRows, static function (array $row) use ($sourceId): bool {
                return (int)($row['business_source_primary_id'] ?? 0) === $sourceId
                    && (int)($row['amount_cents'] ?? 0) > 0;
            }));
            $services = array_values(array_filter($serviceRows, static function (array $row) use ($sourceId): bool {
                return (int)($row['business_source_primary_id'] ?? 0) === $sourceId;
            }));
            $orders = []; foreach ($matches as $row) if (trim((string)($row['order_id'] ?? '')) !== '') $orders[(string)$row['order_id']] = true;
            $activeMembers = []; foreach ($services as $row) if ((int)($row['member_id'] ?? 0) > 0) $activeMembers[(int)$row['member_id']] = true;
            $code = strtoupper(substr(trim($name), 0, 1));
            $item = ['source_id' => $sourceId, 'name' => $name, 'source_code' => $code, 'visit_count' => count($services), 'deal_count' => count($orders), 'deal_amount_cents' => $this->sum($matches, 'amount_cents')];
            if ($code === 'A') $item['active_customer_count'] = count($activeMembers);
            $out[] = $item;
        }
        return ['records' => $out, 'source_explanation' => '按实际启用的一级业务来源动态展示；仅来源 A 展示活客数。进店数按该来源完成服务记录统计，成交数按该来源成功成交订单去重，成交金额按该来源正向成功收款及卡内项目分摊统计；A 来源活客数按完成服务的不同会员去重。'];
    }

    private function rankings(string $tenantId, array $stores, array $range, array $targets, bool $aggregateEligible, array $categoryIds = []): array
    {
        $actual = $this->cashByStore($tenantId, $stores, $range, $categoryIds);
        $storeNames = Db::name('system_store')->whereIn('id', $stores)->column('name', 'id'); $dimensionRows = []; foreach ($stores as $storeId) { $row = ['store_id' => $storeId]; $this->organizationDimensions->project($row, '', '', $range['end']); $dimensionRows[$storeId] = $row; }
        $build = function (string $dimension) use ($stores, $actual, $targets, $storeNames, $dimensionRows): array { $rows = []; foreach ($stores as $storeId) { $key = $dimension === 'store' ? (string)$storeId : (string)($dimensionRows[$storeId][$dimension] ?? '未配置'); $name = $dimension === 'store' ? (string)($storeNames[$storeId] ?? ('门店' . $storeId)) : $key; if (!isset($rows[$key])) $rows[$key] = ['name' => $name, 'target_amount_cents' => 0, 'actual_performance_cents' => 0]; $rows[$key]['target_amount_cents'] += (int)($targets[$storeId] ?? 0); $rows[$key]['actual_performance_cents'] += (int)($actual[$storeId] ?? 0); } foreach ($rows as &$row) $row['achievement_rate'] = $row['target_amount_cents'] > 0 ? round($row['actual_performance_cents'] / $row['target_amount_cents'] * 100, 1) : null; unset($row); usort($rows, static function ($a, $b): int { return ($b['achievement_rate'] ?? -1) <=> ($a['achievement_rate'] ?? -1) ?: strcmp($a['name'], $b['name']); }); return $rows; };
        return ['company' => ['title' => '分公司目标排行', 'records' => $build('company')], 'city_manager' => ['title' => '城市经理目标排行', 'records' => $build('city_manager')], 'store' => ['title' => '门店目标排行', 'records' => $build('store')], 'source_explanation' => '门店目标按当前分公司、城市经理组织统计维度向上汇总；实际业绩按现金业绩减退款金额后的净额汇总。'];
    }

    private function alerts(string $tenantId, array $stores, array $range, array $targets, bool $aggregateEligible, array $categoryIds = []): array
    {
        $actual = $this->cashByStore($tenantId, $stores, $range, $categoryIds); $names = Db::name('system_store')->whereIn('id', $stores)->column('name', 'id'); $out = [];
        foreach ($stores as $id) { $target = (int)($targets[$id] ?? 0); if ($target <= 0) continue; $rate = round((int)($actual[$id] ?? 0) / $target * 100, 1); if ($rate < 80) $out[] = ['store_id' => $id, 'store_name' => (string)($names[$id] ?? ''), 'type' => '目标达成未达标', 'message' => '目标达成率 ' . $rate . '% ，低于 80%', 'achievement_rate' => $rate]; }
        return ['records' => $out, 'source_explanation' => '当前阶段预警门店目标达成率低于 80%；后续环比和退款阈值使用同一后端阈值配置后再启用。'];
    }

    /** @return array<int,int> */
    private function cashByStore(string $tenantId, array $stores, array $range, array $categoryIds): array
    {
        $out = [];
        if ($categoryIds === []) {
            foreach ((new \app\services\query\metric\RegisteredMetricReadServices())->dailyStoreTotals('actual_performance', $tenantId, $stores, $range) as $row) {
                $storeId = (int)$row['store_id'];
                $out[$storeId] = (int)($out[$storeId] ?? 0) + (int)$row['amount_cents'];
            }
            return $out;
        }
        foreach ((new \app\services\query\metric\RegisteredMetricReadServices())->categoryStoreTotals('actual_performance', $tenantId, $stores, $range, $categoryIds) as $row) {
            $storeId = (int)$row['store_id'];
            $out[$storeId] = (int)$row['amount_cents'];
        }
        return $out;
    }
    private function registeredMetric(string $outputCode, string $metricCode, int $value): array
    {
        $contract = \app\services\query\metric\MetricDefinitionRegistry::get($metricCode);
        $definition = (new \app\services\metric\MetricDictionaryServices())->getTooltip($metricCode);
        if (($definition['user_ready'] ?? false) !== true) throw new \RuntimeException('DASHBOARD_METRIC_DICTIONARY_NOT_READY');
        $type = $contract['storage_unit'] === 'fen' ? 'money' : 'count';
        return $this->metric($outputCode, (string)$definition['name'], $value, $type, (string)$definition['summary']);
    }

    private function metric(string $code,string $name,$value,string $type,string $explanation):array{return['metric_code'=>$code,'name'=>$name,'value_cents'=>$type==='money'?$value:null,'value'=>$type==='count'?$value:null,'value_type'=>$type,'drilldown'=>in_array($code,['cash_performance','refund_performance','actual_performance','consumption_performance'],true)?['metric_code'=>$code]:null,'source_explanation'=>$explanation];}
    private function sum(array $rows,string $key):int{$sum=0;foreach($rows as$row)$sum+=(int)($row[$key]??0);return$sum;}
    private function monthsForRange(array $range):array{$out=[];$date=substr($range['start'],0,7).'-01';$end=substr($range['end'],0,7).'-01';while($date<=$end){$out[]=(int)substr($date,5,2);$date=date('Y-m-01',strtotime($date.' +1 month'));}return$out;}
    private function goalProjection(string $title,int $target,int $actual,string $explanation):array{$rate=$target>0?round($actual/$target*100,1):null;return['title'=>$title,'target_amount_cents'=>$target,'actual_performance_cents'=>$actual,'remaining_amount_cents'=>max(0,$target-$actual),'achievement_rate'=>$rate,'source_explanation'=>$explanation];}
    private function filterSchema(array $roots):array{$options=[['value'=>0,'label'=>'全部商品']];foreach($roots as$row)$options[]=['value'=>(int)$row['id'],'label'=>(string)$row['name']];return[['key'=>'start_date','label'=>'开始日期','type'=>'date','source_explanation'=>'包含开始当天。'],['key'=>'end_date','label'=>'截止日期','type'=>'date','source_explanation'=>'包含截止当天。'],['key'=>'category_id','label'=>'商品分类','type'=>'select','options'=>$options,'source_explanation'=>'只显示当前启用一级商品分类；选择后包含全部下级分类。'],['key'=>'store_ids','label'=>'组织 / 门店','type'=>'scope_picker','source_explanation'=>'当前账号权限范围由后端强制，选择只能缩小范围。']];}
    private function fieldExplanations(): array
    {
        $dictionary = new \app\services\metric\MetricDictionaryServices();
        $mapping = [
            'cash_performance' => 'cash_performance',
            'refund_performance' => 'refund_performance',
            'actual_performance' => 'actual_performance',
            'consumption_performance' => 'consume_amount',
            'consumption_count' => 'completed_service_item_count',
        ];
        $out = [];
        foreach ($mapping as $outputCode => $metricCode) {
            $definition = $dictionary->getTooltip($metricCode);
            if (($definition['user_ready'] ?? false) !== true) throw new \RuntimeException('DASHBOARD_METRIC_DICTIONARY_NOT_READY');
            $out[$outputCode] = (string)$definition['summary'];
        }
        $out['consumption_unit_price'] = '消耗业绩除以消耗数量；分母为零显示 -。';
        return $out;
    }
}
