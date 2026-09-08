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
    private const CASH_EXPLANATION = '成功记账收款总额，包含充值及充值欠款补交；商品与卡项收款按销售明细及卡内项目分摊，充值不归入商品分类。退款按退款成功日期单独统计，不在现金业绩中重复扣减。';
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
            $cashTotals = $this->cashTotals($scope['tenant_id'], $scope['store_ids'], $range);
            $consumption = $aggregateReady
                ? $this->aggregatePerformanceTotal($scope['tenant_id'], $scope['store_ids'], $range, 'consumption_performance_recorded')
                : $this->performanceTotalScalar($scope['tenant_id'], $scope['store_ids'], $range, 'consumption_performance_recorded');
            $grossCash = (int)$cashTotals['gross_cents'];
            $refund = (int)$cashTotals['refund_cents'];
            $cash = $grossCash;
            $actual = $grossCash + $refund;
            return [
                'cards' => [
                    $this->metric('cash_performance', '现金业绩', $cash, 'money', self::CASH_EXPLANATION),
                    $this->metric('refund_amount', '退款金额', abs($refund), 'money', '退款成功后形成的退款金额，按退款成功日期统计，以绝对值展示。'),
                    $this->metric('actual_performance', '实际业绩', $actual, 'money', '现金业绩减退款金额；现金业绩按成功记账收款正负事实汇总，退款按退款成功日期以负数冲减，不重复扣减。'),
                    $this->metric('consumption_performance', '消耗业绩', $consumption, 'money', '项目实际完成服务后形成的项目级消耗业绩。'),
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
        $consumption = $aggregateReady
            ? $this->aggregatePerformanceTotal($scope['tenant_id'], $scope['store_ids'], $range, 'consumption_performance_recorded')
            : $this->performanceTotal($scope['tenant_id'], $scope['store_ids'], $range, 'consumption_performance_recorded', $categoryIds);
        $refund = $this->sum(array_values(array_filter($cashRows, static function (array $row): bool {
            return (int)($row['amount_cents'] ?? 0) < 0;
        })), 'amount_cents');
        $grossCash = $this->sum(array_values(array_filter($cashRows, static function (array $row): bool {
            return (int)($row['amount_cents'] ?? 0) > 0;
        })), 'amount_cents');
        // Cash facts retain signed refund adjustments. Actual performance is
        // the same net result: gross receipts less successful refunds.
        $cash = $grossCash;
        $actual = $grossCash + $refund;
        $serviceCount = $this->sum($serviceRows, 'quantity');
        $month = substr($range['end'], 0, 7);
        $year = (int)substr($range['end'], 0, 4);
        $cards = [
            $this->metric('cash_performance', '现金业绩', $cash, 'money', self::CASH_EXPLANATION),
            $this->metric('refund_amount', '退款金额', abs($refund), 'money', '退款成功后形成的退款金额，按退款成功日期统计，以绝对值展示。'),
            $this->metric('actual_performance', '实际业绩', $actual, 'money', '现金业绩减退款金额；现金业绩按成功记账收款正负事实汇总，退款按退款成功日期以负数冲减，不重复扣减。'),
            $this->metric('consumption_performance', '消耗业绩', $consumption, 'money', '项目实际完成服务后形成的项目级消耗业绩。'),
            $this->metric('consumption_count', '消耗数量', $serviceCount, 'count', '所选期间内成功完成服务的项目数量；同一次项目服务只计一次。'),
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
        $categoryCashRows = array_values(array_filter($cashRows, static function (array $row): bool {
            return (int)($row['amount_cents'] ?? 0) > 0;
        }));
        $categoryCards = $this->categoryCards($categoryTree['roots'], $categoryTree['children'], $categoryCashRows, $scope['store_ids'], $range);
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
        if (!in_array($metric, ['cash_performance', 'actual_performance', 'consumption_performance', 'refund_amount'], true)) {
            throw new \InvalidArgumentException('下钻指标无效');
        }
        if ($metric === 'cash_performance' || $metric === 'refund_amount') {
            $rows = $this->cashRows($scope['tenant_id'], $scope['store_ids'], $range, $categoryIds);
            if ($metric === 'cash_performance') {
                $rows = array_values(array_filter($rows, static function (array $row): bool {
                    return (int)($row['amount_cents'] ?? 0) > 0;
                }));
            }
            if ($metric === 'refund_amount') {
                $rows = array_values(array_filter($rows, static function (array $row): bool {
                    return (int)($row['amount_cents'] ?? 0) < 0;
                }));
            }
        } else {
            if ($metric === 'actual_performance') {
                // Actual performance is the signed net cash result. Keep the
                // same cash facts in the drilldown so the card is explainable.
                $rows = $this->cashRows($scope['tenant_id'], $scope['store_ids'], $range, $categoryIds);
            } else {
                $rows = $this->performanceRows($scope['tenant_id'], $scope['store_ids'], $range, 'consumption_performance_recorded', $categoryIds);
            }
        }
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

    /** Frozen direct rows plus card contained project allocations. @return array<int,array<string,mixed>> */
    private function cashRows(string $tenantId, array $stores, array $range, array $categoryIds): array
    {
        $base = Db::name('cashier_v3_payment_sale_allocation_fact')->alias('p')
            ->leftJoin('cashier_v3_payment_sale_allocation_fact original', 'original.tenant_id=p.tenant_id AND original.allocation_fact_id=p.reversal_of')
            ->join('cashier_v3_sale_fact s', 's.tenant_id=p.tenant_id AND s.fact_id=COALESCE(original.sale_fact_id,p.sale_fact_id)')
            ->where('p.tenant_id', $tenantId)->whereIn('p.store_id', $stores)
            ->whereBetween('p.business_date', [$range['start'], $range['end']])->where('p.status', 'effective');
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderFacts($base, 's.tenant_id', 's.order_id');
        $direct = clone $base;
        $direct->join('cashier_v3_report_sale_dimension_fact d', 'd.tenant_id=s.tenant_id AND d.sale_fact_id=s.fact_id')->where('s.source_type', '<>', 'card');
        if ($categoryIds !== []) $direct->whereIn('d.category_id_snapshot', $categoryIds);
        $rows = $direct->fieldRaw('p.id,p.store_id,p.member_id,p.order_id,p.source_line_id,p.business_date,p.amount_cents,p.organization_id,s.organization_path_snapshot,s.store_name_snapshot store_name,s.business_source_primary_id,s.business_source_label_snapshot source_label,d.item_id,d.item_name_snapshot item_name,d.product_type_snapshot,d.category_id_snapshot category_id,d.category_path_snapshot category_path')->group('p.id')->select()->toArray();
        $cards = (clone $base)->where('s.source_type', 'card')->fieldRaw('p.id,p.store_id,p.member_id,p.order_id,p.source_line_id,p.business_date,p.amount_cents,p.organization_id,s.organization_path_snapshot,s.store_name_snapshot store_name,s.business_source_primary_id,s.business_source_label_snapshot source_label,s.fact_id sale_fact_id')->group('p.id')->select()->toArray();
        // Recharge has no sale allocation. Reuse the same cash-fact reader as
        // the scalar summary, before the no-card early return. A category
        // constraint must never be silently broadened to include recharge.
        if ($categoryIds === []) {
            $rows = array_merge($rows, (new \app\services\query\metric\GroupPerformanceMetricReadServices())->rechargeCashRows($tenantId, $stores, $range));
        }
        if ($cards === []) return $rows;
        $saleIds = array_values(array_unique(array_filter(array_column($cards, 'sale_fact_id'))));
        $bySale = [];
        foreach (Db::name('cashier_v3_card_sale_item_allocation_fact')->where('tenant_id', $tenantId)->whereIn('sale_fact_id', $saleIds)->where('status', 'effective')->field('allocation_fact_id,sale_fact_id,component_product_id,item_name_snapshot,category_id_snapshot,category_path_snapshot,component_count,sale_amount_cents,configured_amount_cents')->select()->toArray() as $row) $bySale[(string)$row['sale_fact_id']][] = $row;
        $allowed = array_fill_keys($categoryIds, true);
        foreach ($cards as $payment) {
            $items = $bySale[(string)$payment['sale_fact_id']] ?? [];
            foreach ($this->allocate((int)$payment['amount_cents'], $items) as $allocation) {
                $item = $allocation['item'];
                if ($allowed !== [] && !isset($allowed[(int)$item['category_id_snapshot']])) continue;
                $rows[] = array_merge($payment, ['amount_cents' => $allocation['amount_cents'], 'item_id' => (string)$item['component_product_id'], 'item_name' => (string)$item['item_name_snapshot'], 'product_type_snapshot' => 'card_component', 'category_id' => (int)$item['category_id_snapshot'], 'category_path' => (string)$item['category_path_snapshot']]);
            }
        }
        return $rows;
    }

    /** @return array<int,array{item:array<string,mixed>,amount_cents:int}> */
    private function allocate(int $amount, array $items): array
    {
        $weight = 0; foreach ($items as $item) $weight += max(0, (int)($item['configured_amount_cents'] ?? $item['sale_amount_cents'] ?? 0));
        if ($weight <= 0) $weight = count($items);
        $out = []; $assigned = 0;
        foreach ($items as $index => $item) {
            $part = $index === count($items) - 1 ? $amount - $assigned : (int)floor($amount * max(1, (int)($item['configured_amount_cents'] ?? $item['sale_amount_cents'] ?? 1)) / $weight);
            $assigned += $part; $out[] = ['item' => $item, 'amount_cents' => $part];
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function serviceRows(string $tenantId, array $stores, array $range, array $categoryIds): array
    {
        $query = Db::name('cashier_v3_entitlement_service_fact')->alias('s')->where('s.tenant_id', $tenantId)->whereIn('s.store_id', $stores)->whereBetween('s.business_date', [$range['start'], $range['end']])->where('s.service_status', 'completed');
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderServices($query, 's');
        if ($categoryIds !== []) $query->whereIn('s.project_category_id_snapshot', $categoryIds);
        return $query->fieldRaw("s.service_fact_id,s.store_id,s.member_id,s.business_date,s.organization_id,s.organization_path_snapshot,s.store_name_snapshot,s.project_id,s.project_name_snapshot,s.project_category_id_snapshot,s.project_category_path_snapshot,s.source_line_id,s.quantity,(SELECT MAX(sf.business_source_primary_id) FROM eb_cashier_v3_sale_fact sf WHERE sf.tenant_id=s.tenant_id AND sf.checkout_request_id=s.checkout_request_id AND sf.source_line_id=s.source_line_id AND sf.fact_direction='forward' AND sf.status='effective') business_source_primary_id")->select()->toArray();
    }

    private function performanceTotal(string $tenantId, array $stores, array $range, string $type, array $categoryIds): int
    {
        if ($categoryIds === []) return $this->performanceTotalScalar($tenantId, $stores, $range, $type);
        return $this->sum($this->performanceRows($tenantId, $stores, $range, $type, $categoryIds), 'amount_cents');
    }

    private function performanceTotalScalar(string $tenantId, array $stores, array $range, string $type): int
    {
        return (new \app\services\query\metric\GroupPerformanceMetricReadServices())->performanceTotal($tenantId, $stores, $range, $type);
    }

    /** @return array{gross_cents:int,refund_cents:int} */
    private function cashTotals(string $tenantId, array $stores, array $range): array
    {
        return (new \app\services\query\metric\GroupPerformanceMetricReadServices())->cashTotals($tenantId, $stores, $range);
    }

    private function actualCashTotal(string $tenantId, array $stores, array $range, array $categoryIds): int
    {
        return $this->sum($this->cashRows($tenantId, $stores, $range, $categoryIds), 'amount_cents');
    }

    /** @return array<string,int> */
    private function dailyCashPerformance(string $tenantId, array $stores, array $range, array $categoryIds): array
    {
        $out = [];
        foreach ($this->cashRows($tenantId, $stores, $range, $categoryIds) as $row) {
            $date = (string)($row['business_date'] ?? '');
            if ($date !== '') $out[$date] = (int)($out[$date] ?? 0) + (int)($row['amount_cents'] ?? 0);
        }
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
                $points[] = ['id' => substr($start, 0, 7), 'label' => sprintf('%02d月', $month), 'actual_performance_cents' => $this->actualCashTotal($tenantId, $stores, $monthRange, $categoryIds), 'consumption_performance_cents' => $aggregateEligible ? $this->aggregatePerformanceTotal($tenantId, $stores, $monthRange, 'consumption_performance_recorded') : $this->performanceTotal($tenantId, $stores, $monthRange, 'consumption_performance_recorded', $categoryIds)];
            }
            return ['granularity' => 'month', 'range_label' => '本年（自然月）', 'points' => $points, 'source_explanation' => '本年按自然月汇总实际业绩和消耗业绩。'];
        }
        $trendRange = ['start' => $periodStart, 'end' => $periodEnd];
        $actual = $this->dailyCashPerformance($tenantId, $stores, $trendRange, $categoryIds);
        $consumption = $aggregateEligible ? $this->aggregateDailyPerformance($tenantId, $stores, $trendRange, 'consumption_performance_recorded') : $this->dailyPerformance($tenantId, $stores, $trendRange, 'consumption_performance_recorded', $categoryIds);
        $points = [];
        for ($day = $periodStart; $day <= $periodEnd; $day = date('Y-m-d', strtotime($day . ' +1 day'))) $points[] = ['id' => $day, 'label' => substr($day, 8) . '日', 'actual_performance_cents' => (int)($actual[$day] ?? 0), 'consumption_performance_cents' => (int)($consumption[$day] ?? 0)];
        return ['granularity' => 'day', 'range_label' => '所在月份（自然日）', 'points' => $points, 'source_explanation' => '今天和本月固定展示所在自然月从 1 日到最后一天的实际业绩和消耗业绩。'];
    }

    private function dailyPerformance(string $tenantId, array $stores, array $range, string $type, array $categoryIds): array
    {
        $out = [];
        foreach ($this->performanceRows($tenantId, $stores, $range, $type, $categoryIds) as $row) {
            $date = (string)($row['business_date'] ?? '');
            if ($date !== '') $out[$date] = (int)($out[$date] ?? 0) + (int)($row['amount_cents'] ?? 0);
        }
        return $out;
    }

    private function aggregatePerformanceTotal(string $tenantId, array $stores, array $range, string $type): int
    {
        $column = $type === 'actual_performance_recorded' ? 'actual_performance_cents' : 'consumption_performance_cents';
        $total = 0;
        foreach ($this->dailyAggregate->rows($tenantId, $stores, $range['start'], $range['end']) as $row) {
            $total += (int)($row[$column] ?? 0);
        }
        return $total;
    }

    /**
     * A caught-up flag alone cannot protect a read if a rebuild was performed
     * against a different fact projection. Compare the exact totals and the
     * per-store distribution before allowing the aggregate into any card,
     * trend, goal or ranking. Otherwise all projections use the same facts.
     */
    private function aggregateMatchesFacts(string $tenantId, array $stores, array $range): bool
    {
        $types = ['actual_performance_recorded', 'consumption_performance_recorded'];
        foreach ($types as $type) {
            $facts = $this->performanceTotal($tenantId, $stores, $range, $type, []);
            $aggregate = $this->aggregatePerformanceTotal($tenantId, $stores, $range, $type);
            if ($facts !== $aggregate) return false;
        }
        $factByStore = $this->performanceByStore($tenantId, $stores, $range, 'actual_performance_recorded', []);
        $aggregateByStore = $this->aggregateByStoreActual($tenantId, $stores, $range);
        foreach ($stores as $storeId) {
            if ((int)($factByStore[(int)$storeId] ?? 0) !== (int)($aggregateByStore[(int)$storeId] ?? 0)) return false;
        }
        return true;
    }

    /** @return array<string,int> */
    private function aggregateDailyPerformance(string $tenantId, array $stores, array $range, string $type): array
    {
        $column = $type === 'actual_performance_recorded' ? 'actual_performance_cents' : 'consumption_performance_cents';
        $out = [];
        foreach ($this->dailyAggregate->rows($tenantId, $stores, $range['start'], $range['end']) as $row) {
            $day = (string)($row['business_date'] ?? '');
            if ($day !== '') $out[$day] = (int)($out[$day] ?? 0) + (int)($row[$column] ?? 0);
        }
        return $out;
    }

    /** @return array<int,int> */
    private function aggregateByStoreActual(string $tenantId, array $stores, array $range): array
    {
        $out = [];
        foreach ($this->dailyAggregate->rows($tenantId, $stores, $range['start'], $range['end']) as $row) {
            $storeId = (int)($row['store_id'] ?? 0);
            if ($storeId > 0) $out[$storeId] = (int)($out[$storeId] ?? 0) + (int)($row['actual_performance_cents'] ?? 0);
        }
        return $out;
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

    /**
     * Performance facts inherit the sold line's category. A card shell is
     * allocated to its contained projects with the same signed split used by
     * payment allocation, so filtering a category never attributes the whole
     * card performance to one component.
     *
     * @return array<int,array<string,mixed>>
     */
    private function performanceRows(string $tenantId, array $stores, array $range, string $type, array $categoryIds): array
    {
        if ($type === 'consumption_performance_recorded') {
            // Consumption facts are keyed to completed service lines. They do
            // not necessarily have a matching sale fact (existing card rights
            // are a valid example), so joining through sale_fact would erase
            // legitimate consumption amounts.
            $query = Db::name('cashier_v3_performance_fact')->alias('p')
                ->join('cashier_v3_entitlement_service_fact sv', 'sv.tenant_id=p.tenant_id AND sv.checkout_request_id=p.checkout_request_id AND sv.source_line_id=p.source_line_id AND sv.service_status=\'completed\'')
                ->where('p.tenant_id', $tenantId)->whereIn('p.store_id', $stores)
                ->whereBetween('p.business_date', [$range['start'], $range['end']])
                ->where('p.status', 'effective')->where('p.performance_type', $type);
            (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderServices($query, 'sv');
            if ($categoryIds !== []) $query->whereIn('sv.project_category_id_snapshot', $categoryIds);
            return $query->fieldRaw('p.fact_id,p.store_id,p.member_id,p.order_id,p.source_line_id,p.business_date,p.amount_cents,sv.project_category_id_snapshot category_id')->group('p.fact_id')->select()->toArray();
        }

        $base = Db::name('cashier_v3_performance_fact')->alias('p')
            ->join('cashier_v3_sale_fact s', "s.tenant_id=p.tenant_id AND s.source_line_id=p.source_line_id AND s.fact_direction='forward' AND s.status='effective'")
            ->where('p.tenant_id', $tenantId)->whereIn('p.store_id', $stores)
            ->whereBetween('p.business_date', [$range['start'], $range['end']])
            ->where('p.status', 'effective')->where('p.performance_type', $type);
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderFacts($base, 'p.tenant_id', 'p.order_id');

        $direct = clone $base;
        $direct->join('cashier_v3_report_sale_dimension_fact d', 'd.tenant_id=s.tenant_id AND d.sale_fact_id=s.fact_id')
            ->where('s.source_type', '<>', 'card');
        if ($categoryIds !== []) $direct->whereIn('d.category_id_snapshot', $categoryIds);
        $rows = $direct->fieldRaw('p.fact_id,p.store_id,p.member_id,p.order_id,p.source_line_id,p.business_date,p.amount_cents,d.category_id_snapshot category_id')->group('p.fact_id')->select()->toArray();

        $cards = (clone $base)->where('s.source_type', 'card')
            ->fieldRaw('p.fact_id,p.store_id,p.member_id,p.order_id,p.source_line_id,p.business_date,p.amount_cents,s.fact_id sale_fact_id')->group('p.fact_id')->select()->toArray();
        if ($cards === []) return $rows;
        $saleIds = array_values(array_unique(array_filter(array_column($cards, 'sale_fact_id'))));
        if ($saleIds === []) return $rows;
        $itemsBySale = [];
        foreach (Db::name('cashier_v3_card_sale_item_allocation_fact')->where('tenant_id', $tenantId)->whereIn('sale_fact_id', $saleIds)->where('status', 'effective')
            ->field('allocation_fact_id,sale_fact_id,category_id_snapshot,configured_amount_cents,sale_amount_cents')->select()->toArray() as $item) {
            $itemsBySale[(string)$item['sale_fact_id']][] = $item;
        }
        $allowed = array_fill_keys($categoryIds, true);
        foreach ($cards as $performance) {
            foreach ($this->allocate((int)$performance['amount_cents'], (array)($itemsBySale[(string)$performance['sale_fact_id']] ?? [])) as $allocation) {
                $item = $allocation['item'];
                $categoryId = (int)($item['category_id_snapshot'] ?? 0);
                if ($allowed !== [] && !isset($allowed[$categoryId])) continue;
                $rows[] = [
                    'fact_id' => (string)$performance['fact_id'],
                    'store_id' => (int)$performance['store_id'],
                    'member_id' => (int)$performance['member_id'],
                    'order_id' => (string)$performance['order_id'],
                    'source_line_id' => (string)$performance['source_line_id'],
                    'business_date' => (string)$performance['business_date'],
                    'amount_cents' => (int)$allocation['amount_cents'],
                    'category_id' => $categoryId,
                ];
            }
        }
        return $rows;
    }

    private function categoryCards(array $roots, array $children, array $cashRows, array $stores, array $range): array
    {
        $total = $this->sum($cashRows, 'amount_cents'); $cards = [];
        $categorized = 0;
        foreach ($roots as $root) {
            $ids = array_fill_keys($this->descendants((int)$root['id'], $children), true); $rows = array_values(array_filter($cashRows, static function (array $row) use ($ids): bool { return isset($ids[(int)($row['category_id'] ?? 0)]); }));
            $amount = $this->sum($rows, 'amount_cents');
            $categorized += $amount;
            $cards[] = ['category_id' => (int)$root['id'], 'name' => (string)$root['name'], 'cash_performance_cents' => $amount, 'share' => $total === 0 ? null : round($amount / $total * 100, 1), 'drilldown' => ['metric_code' => 'cash_performance', 'category_id' => (int)$root['id']], 'project_rankings' => $this->top($rows, 'item_name', '项目'), 'product_rankings' => $this->top(array_values(array_filter($rows, static function (array $row): bool { return (string)($row['product_type_snapshot'] ?? '') !== 'project'; })), 'item_name', '产品'), 'source_explanation' => '当前启用一级商品分类及全部下级分类的现金业绩；卡项按卡内项目分类和分摊金额归入。'];
        }
        $unclassified = $total - $categorized;
        if ($unclassified !== 0) {
            $classifiedIds = [];
            foreach ($roots as $root) $classifiedIds += $this->descendants((int)$root['id'], $children);
            $classifiedIds = array_fill_keys($classifiedIds, true);
            $rows = array_values(array_filter($cashRows, static function (array $row) use ($classifiedIds): bool {
                $categoryId = (int)($row['category_id'] ?? 0);
                if ($categoryId <= 0) return true;
                return !isset($classifiedIds[$categoryId]);
            }));
            $cards[] = ['category_id' => 0, 'name' => '未分类', 'cash_performance_cents' => $unclassified, 'share' => $total === 0 ? null : round($unclassified / $total * 100, 1), 'drilldown' => ['metric_code' => 'cash_performance'], 'project_rankings' => $this->top($rows, 'item_name', '项目'), 'product_rankings' => $this->top(array_values(array_filter($rows, static function (array $row): bool { return (string)($row['product_type_snapshot'] ?? '') !== 'project'; })), 'item_name', '产品'), 'source_explanation' => '未能匹配当前启用一级商品分类的现金业绩；保留在独立未分类卡中，确保分类合计与现金业绩一致。'];
        }
        return $cards;
    }

    private function top(array $rows, string $field, string $type): array
    {
        $amounts = []; foreach ($rows as $row) { $name = trim((string)($row[$field] ?? '')); if ($name !== '') $amounts[$name] = (int)($amounts[$name] ?? 0) + (int)($row['amount_cents'] ?? 0); }
        arsort($amounts, SORT_NUMERIC); $out = []; foreach (array_slice($amounts, 0, 5, true) as $name => $amount) $out[] = ['name' => $name, 'amount_cents' => $amount, 'type' => $type]; return $out;
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

    private function performanceByStore(string $tenantId, array $stores, array $range, string $type, array $categoryIds): array
    {
        $out = [];
        foreach ($this->performanceRows($tenantId, $stores, $range, $type, $categoryIds) as $row) {
            $storeId = (int)($row['store_id'] ?? 0);
            if ($storeId > 0) $out[$storeId] = (int)($out[$storeId] ?? 0) + (int)($row['amount_cents'] ?? 0);
        }
        return $out;
    }

    /** @return array<int,int> */
    private function cashByStore(string $tenantId, array $stores, array $range, array $categoryIds): array
    {
        $out = [];
        foreach ($this->cashRows($tenantId, $stores, $range, $categoryIds) as $row) {
            $storeId = (int)($row['store_id'] ?? 0);
            if ($storeId > 0) $out[$storeId] = (int)($out[$storeId] ?? 0) + (int)($row['amount_cents'] ?? 0);
        }
        return $out;
    }
    private function metric(string $code,string $name,$value,string $type,string $explanation):array{return['metric_code'=>$code,'name'=>$name,'value_cents'=>$type==='money'?$value:null,'value'=>$type==='count'?$value:null,'value_type'=>$type,'drilldown'=>in_array($code,['cash_performance','refund_amount','actual_performance','consumption_performance'],true)?['metric_code'=>$code]:null,'source_explanation'=>$explanation];}
    private function sum(array $rows,string $key):int{$sum=0;foreach($rows as$row)$sum+=(int)($row[$key]??0);return$sum;}
    private function monthsForRange(array $range):array{$out=[];$date=substr($range['start'],0,7).'-01';$end=substr($range['end'],0,7).'-01';while($date<=$end){$out[]=(int)substr($date,5,2);$date=date('Y-m-01',strtotime($date.' +1 month'));}return$out;}
    private function goalProjection(string $title,int $target,int $actual,string $explanation):array{$rate=$target>0?round($actual/$target*100,1):null;return['title'=>$title,'target_amount_cents'=>$target,'actual_performance_cents'=>$actual,'remaining_amount_cents'=>max(0,$target-$actual),'achievement_rate'=>$rate,'source_explanation'=>$explanation];}
    private function filterSchema(array $roots):array{$options=[['value'=>0,'label'=>'全部商品']];foreach($roots as$row)$options[]=['value'=>(int)$row['id'],'label'=>(string)$row['name']];return[['key'=>'start_date','label'=>'开始日期','type'=>'date','source_explanation'=>'包含开始当天。'],['key'=>'end_date','label'=>'截止日期','type'=>'date','source_explanation'=>'包含截止当天。'],['key'=>'category_id','label'=>'商品分类','type'=>'select','options'=>$options,'source_explanation'=>'只显示当前启用一级商品分类；选择后包含全部下级分类。'],['key'=>'store_ids','label'=>'组织 / 门店','type'=>'scope_picker','source_explanation'=>'当前账号权限范围由后端强制，选择只能缩小范围。']];}
    private function fieldExplanations():array{return['cash_performance'=>self::CASH_EXPLANATION,'refund_amount'=>'退款成功的实际退款金额，按退款成功日期统计，以绝对值展示。','actual_performance'=>'现金业绩减退款金额；现金业绩取正向成功收款，退款取退款成功事实的绝对值，得到净实际业绩。','consumption_performance'=>'项目实际完成服务后，从对应的消耗业绩事实汇总；已有卡内权益完成服务也计入，不要求存在销售明细。','consumption_count'=>'成功完成服务的项目数量。','consumption_unit_price'=>'消耗业绩除以消耗数量；分母为零显示 -。'];}
}
