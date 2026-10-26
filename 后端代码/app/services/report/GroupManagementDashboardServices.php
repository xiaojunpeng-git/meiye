<?php

declare(strict_types=1);

namespace app\services\report;

use think\facade\Db;

/**
 * Platform/mobile read contract for the group management dashboard.
 *
 * The dashboard never derives metrics in a browser. Payment and performance
 * facts keep signed reversals, while category rows resolve linked platform
 * products at read time; card cash weights remain frozen by component.
 */
final class GroupManagementDashboardServices
{
    public const DASHBOARD_CODE = 'group_management_dashboard';
    public const METRIC_VERSION = 'group-management-shared-home-v9';
    public const COVERAGE_START = '2026-08-10';

    private $organizationDimensions;
    private $targets;

    public function __construct(
        ?StoreUnifiedReportOrganizationDimensionServices $organizationDimensions = null,
        ?GroupManagementDashboardTargetServices $targets = null
    ) {
        $this->organizationDimensions = $organizationDimensions ?: new StoreUnifiedReportOrganizationDimensionServices();
        $this->targets = $targets ?: new GroupManagementDashboardTargetServices();
    }

    /** @return array<string,mixed> */
    public function dashboard(array $context, array $input): array
    {
        $scope = $this->scope($context, $input);
        $range = $this->range($input);
        $categoryTree = $this->categories();
        $selectedCategory = max(0, (int)($input['category_id'] ?? 0));
        $categoryIds = $selectedCategory > 0 ? $this->descendants($selectedCategory, $categoryTree['children']) : [];
        if ($selectedCategory > 0 && !isset($categoryTree['visible_ids'][$selectedCategory])) {
            throw new \InvalidArgumentException('商品分类不存在或已关闭统计，请重新选择');
        }
        // The old daily read model summed a retired fact. Its canonical
        // rebuild still lacks reliable online invalidation, so every displayed
        // metric comes from the registered Reader and no aggregate is marked fresh.
        $aggregationHealth = [
            'status_code' => $categoryIds === [] ? 'realtime' : 'not_applicable',
            'aggregation_caught_up' => false,
            'checked_at' => time(),
            'scope' => $categoryIds === [] ? 'unfiltered_overview' : 'category_filtered',
        ];
        // Product dashboard summary requests do not need row-level cash,
        // service, trend or ranking projections. For an unfiltered scope,
        // aggregate the two required facts in SQL so a high-volume month does
        // not materialize every payment allocation in the PHP worker.
        if (!empty($input['summary_only']) && $categoryIds === []) {
            $reader = \app\services\query\metric\RegisteredMetricReadServices::withCurrentReportProductCategory();
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
                'aggregation_caught_up' => false,
            ];
        }
        $cashRows = $this->cashRows($scope['tenant_id'], $scope['store_ids'], $range, $categoryIds);
        $serviceRows = $this->serviceRows($scope['tenant_id'], $scope['store_ids'], $range, $categoryIds);
        $year = (int)substr($range['end'], 0, 4);
        $cards = $this->operatingCards($scope, $range, $categoryIds);
        if (!empty($input['summary_only'])) {
            return [
                'cards' => $cards,
                'metric_version' => self::METRIC_VERSION,
                'aggregation_caught_up' => false,
            ];
        }
        // 有目标时只比较已设置目标的门店×月份；完全无目标时由目标服务返回
        // 授权范围内的真实完成值，目标和完成率保持空值而不是伪造为零。
        $goalProgress = (new \app\services\target\TargetSchemeReportServices())->groupProgress(
            ['tenant_id' => $scope['tenant_id'], 'store_ids' => $scope['store_ids']], $year, $range['end']
        );
        $monthTargets = $goalProgress['month_targets'];
        $currentTarget = $monthTargets ? array_sum($monthTargets) : null;
        $yearTarget = $goalProgress['year_targets'] ? array_sum($goalProgress['year_targets']) : null;
        $monthActual = $goalProgress['month_actual'];
        $yearActual = $goalProgress['year_actual'];
        $targetedStoreIds = array_map('intval', array_keys($monthTargets));
        $rankingStoreIds = $targetedStoreIds ?: $scope['store_ids'];
        $sections = $this->performanceSections($scope, $range, $categoryTree, $categoryIds);

        return [
            'dashboard_code' => self::DASHBOARD_CODE,
            'title' => '集团管理看板',
            'scope' => ['store_ids' => $scope['store_ids'], 'range' => $range, 'selected_category_id' => $selectedCategory],
            'filter_schema' => $this->filterSchema($categoryTree['roots']),
            'cards' => $cards,
            'sale_type_cards' => $sections['sale_type_cards'],
            'trend' => $this->trend($scope['tenant_id'], $scope['store_ids'], $range, $categoryIds),
            'categories' => $sections['categories'],
            'sources' => $this->sources($scope['store_ids'], $range, $cashRows, $serviceRows, $categoryIds),
            'goals' => [
                'month' => $this->goalProjection('本月目标进度', $currentTarget, $monthActual, $monthTargets
                    ? '只比较本月已设置全店实际业绩目标的门店；完成值来自相同门店的统一事实，不随商品分类筛选改变。'
                    : '本月未设置目标；完成值来自授权门店的统一事实，目标、剩余目标和达成率不可计算。'),
                'year' => $this->goalProjection('本年目标进度', $yearTarget, $yearActual, $goalProgress['year_targets']
                    ? '全年目标汇总已配置月份；完成值只统计这些门店和月份截至所选日期的统一事实。'
                    : '本年未设置目标；完成值来自授权门店截至所选日期的统一事实，目标、剩余目标和达成率不可计算。'),
                'source_explanation' => '目标由已关联集团管理看板的生效目标方案提供；有目标时只比较对应门店和月份，完全无目标时展示授权范围的实际业绩，目标、剩余目标和达成率显示 -。',
            ],
            'rankings' => $this->rankings($rankingStoreIds, $monthTargets, $goalProgress['month_store_actuals'], $range['end']),
            'alerts' => $this->alerts($targetedStoreIds, $monthTargets, $goalProgress['month_store_actuals']),
            'field_explanations' => $this->fieldExplanations(),
            'target_management' => $this->targets->yearTargets(['tenant_id' => $scope['tenant_id'], 'store_ids' => $scope['store_ids']], $year),
            'metric_version' => self::METRIC_VERSION,
            'data_as_of' => date('Y-m-d H:i:s'),
            'aggregation_caught_up' => false,
            'aggregation_status' => $categoryIds !== []
                ? '已选择商品分类，分类指标按原明细金额和关联平台商品当前分类实时读取。'
                : '按统一事实实时读取；离线日汇总尚未具备可靠的变更失效机制。',
            'aggregation_health' => $aggregationHealth,
            'coverage_start' => self::COVERAGE_START,
        ];
    }

    /** 六项经营业绩由平台和商家端共用；消耗单价在后端按同一服务项目数派生，零分母为空。 */
    private function operatingCards(array $scope, array $range, array $categoryIds = []): array
    {
        if ($categoryIds === []) {
            $reader = new \app\services\query\metric\RegisteredMetricReadServices();
            $cash = $reader->summary('cash_performance', $scope['tenant_id'], $scope['store_ids'], $range);
            $refund = $reader->summary('refund_performance', $scope['tenant_id'], $scope['store_ids'], $range);
            $actual = $reader->summary('actual_performance', $scope['tenant_id'], $scope['store_ids'], $range);
            $consumption = $reader->summary('consume_amount', $scope['tenant_id'], $scope['store_ids'], $range);
            $serviceCount = $reader->summary('completed_service_item_count', $scope['tenant_id'], $scope['store_ids'], $range);
        } else {
            $reader = \app\services\query\metric\RegisteredMetricReadServices::withCurrentReportProductCategory();
            $cash = $reader->categorySummary('cash_performance', $scope['tenant_id'], $scope['store_ids'], $range, $categoryIds);
            $refund = $reader->categorySummary('refund_performance', $scope['tenant_id'], $scope['store_ids'], $range, $categoryIds);
            $actual = $reader->categorySummary('actual_performance', $scope['tenant_id'], $scope['store_ids'], $range, $categoryIds);
            $consumption = $reader->categorySummary('consume_amount', $scope['tenant_id'], $scope['store_ids'], $range, $categoryIds);
            $serviceCount = $reader->categorySummary('completed_service_item_count', $scope['tenant_id'], $scope['store_ids'], $range, $categoryIds);
        }
        return [
            $this->registeredMetric('cash_performance', 'cash_performance', $cash),
            $this->registeredMetric('refund_performance', 'refund_performance', $refund),
            $this->registeredMetric('actual_performance', 'actual_performance', $actual),
            $this->registeredMetric('consumption_performance', 'consume_amount', $consumption),
            $this->registeredMetric('consumption_count', 'completed_service_item_count', $serviceCount),
            $this->metric('consumption_unit_price', '消耗单价', $serviceCount > 0 ? (int)round($consumption / $serviceCount) : null, 'money', '消耗业绩除以消耗数量；分母为零显示 -。'),
        ];
    }

    /**
     * 平台与商家端共用销售类型、分类和排行投影；调用方必须传入服务端授权范围。
     * 只读取本模块所需事实，不为了手机首页加载目标、预警等无关报表。
     */
    public function homeSections(array $context, array $input, bool $includeSources = true, bool $includeOperatingCards = false): array
    {
        $scope = $this->scope($context, $input);
        $range = $this->range($input);
        $sections = $this->performanceSections($scope, $range, $this->categories(), []);
        if ($includeOperatingCards) $sections['cards'] = $this->operatingCards($scope, $range);
        if ($includeSources) {
            $sections['sources'] = $this->sources($scope['store_ids'], $range,
                $this->cashRows($scope['tenant_id'], $scope['store_ids'], $range, []),
                $this->serviceRows($scope['tenant_id'], $scope['store_ids'], $range, []), []);
        }
        return $sections;
    }

    /** 后端决定分类显隐和配置顺序，两端仅按同一契约渲染；金额不在客户端重算。 */
    private function performanceSections(array $scope, array $range, array $categoryTree, array $categoryIds): array
    {
        $reader = \app\services\query\metric\RegisteredMetricReadServices::withCurrentReportProductCategory();
        $categoryCards = $reader->categoryDashboardCards(
            'actual_performance', $scope['tenant_id'], $scope['store_ids'], $range, $categoryTree['roots'], $categoryTree['children'], $categoryIds
        );
        // The three sale-mix cards share the dashboard's authorized store/date
        // scope. Their positive sale receipts are narrower than the overall
        // cash card, which also includes recharge and debt repayments.
        // One reader result supplies both levels' fixed three types. Experience
        // cards are omitted, not merged; this layer never recalculates values.
        $saleTypeBreakdown = $reader->dashboardSaleTypeBreakdown($scope['tenant_id'], $scope['store_ids'], $range,
            $categoryTree['roots'], $categoryTree['children'], $categoryIds);
        $saleTypeTotals = $saleTypeBreakdown['totals'];
        $saleTypeCards = [];
        foreach (['normal_card' => '疗程卡', 'project' => '单项', 'product' => '产品'] as $code => $title) {
            $saleTypeCards[] = [
                'code' => $code,
                'title' => $title,
                'sales_quantity' => $saleTypeTotals[$code]['sales_quantity'],
                'cash_performance_cents' => $saleTypeTotals[$code]['cash_performance_cents'],
            ];
        }
        foreach ($categoryCards as &$category) {
            $category['sale_types'] = [];
            foreach ($saleTypeCards as $type) {
                $values = $saleTypeBreakdown['categories'][$category['category_id']][$type['code']] ?? null;
                if ($values === null) continue;
                $category['sale_types'][] = ['code' => $type['code'], 'title' => $type['title']] + $values;
            }
            $hasSale = false;
            foreach ($category['sale_types'] as $type) {
                if ($type['sales_quantity'] != 0 || $type['cash_performance_cents'] != 0) $hasSale = true;
            }
            // 未分类仍保留在返回值供总额追溯；不生成可误导用户的分类入口。
            $category['visible'] = (int)$category['category_id'] > 0
                && trim((string)$category['name']) !== '' && $category['name'] !== '未分类'
                && ((int)$category['amount_cents'] !== 0 || $category['project_rankings'] !== []
                    || $category['product_rankings'] !== [] || $hasSale);
        }
        unset($category);
        return ['sale_type_cards' => $saleTypeCards, 'categories' => $categoryCards];
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
        if ($categoryId > 0) {
            $categoryTree = $this->categories();
            if (!isset($categoryTree['visible_ids'][$categoryId])) {
                throw new \InvalidArgumentException('商品分类不存在或已关闭统计，请重新选择');
            }
            $categoryIds = $this->descendants($categoryId, $categoryTree['children']);
        }
        $metric = trim((string)($input['metric_code'] ?? 'cash_performance'));
        if (!in_array($metric, ['cash_performance', 'actual_performance', 'consumption_performance', 'refund_performance'], true)) {
            throw new \InvalidArgumentException('下钻指标无效');
        }
        $canonical = $metric === 'consumption_performance' ? 'consume_amount' : $metric;
        $rows = \app\services\query\metric\RegisteredMetricReadServices::withCurrentReportProductCategory()
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

    /**
     * Only first-level categories with statistics enabled appear in the group
     * dashboard cards and filter. The configured category order is authoritative
     * for every consumer: sort desc first, then id desc for a stable tie-breaker.
     * Keep the full tree for authorized category drilldown and attribution;
     * hiding a card never rewrites cash facts or removes its amount from the
     * overall operating metrics.
     * @return array{roots:array<int,array<string,mixed>>,children:array<int,array<int,int>>,visible_ids:array<int,bool>,labels:array<int,array<string,mixed>>}
     */
    private function categories(): array
    {
        // 商品分类表同时保存平台配置和按门店同步的副本。看板只使用平台
        // 分类正本，但不因目录隐藏状态抹掉已有商品的报表分类。
        $rows = Db::name('store_product_category')->where('type', 0)->where('relation_id', 0)
            ->field('id,pid,cate_name,statistics_enabled,sort')->order('pid', 'asc')->order('sort', 'desc')->order('id', 'desc')->select()->toArray();
        $ids = [];
        $children = [];
        foreach ($rows as $row) {
            $id = (int)$row['id']; $pid = (int)$row['pid'];
            $ids[$id] = ['id' => $id, 'pid' => $pid, 'name' => (string)$row['cate_name'],
                'statistics_enabled' => (int)$row['statistics_enabled'], 'sort' => (int)$row['sort']];
            $children[$pid][] = $id;
        }
        $roots = [];
        $visibleIds = [];
        foreach ($ids as $id => $row) {
            if (isset($ids[(int)$row['pid']]) && (int)$row['pid'] > 0) continue;
            if ($row['statistics_enabled'] !== 1) continue;
            $roots[] = $row;
            // Child switches do not erase amounts from an enabled parent or
            // its secondary-category breakdown; the parent controls visibility.
            foreach ($this->descendants($id, $children) as $visibleId) $visibleIds[$visibleId] = true;
        }
        return ['roots' => $roots, 'children' => $children, 'visible_ids' => $visibleIds, 'labels' => $ids];
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

    /** Signed actual-performance rows with current category and frozen component weights. @return array<int,array<string,mixed>> */
    private function cashRows(string $tenantId, array $stores, array $range, array $categoryIds): array
    {
        return \app\services\query\metric\RegisteredMetricReadServices::withCurrentReportProductCategory()
            ->categoryRows('actual_performance', $tenantId, $stores, $range, $categoryIds);
    }

    /** @return array<int,array<string,mixed>> */
    private function serviceRows(string $tenantId, array $stores, array $range, array $categoryIds): array
    {
        return \app\services\query\metric\RegisteredMetricReadServices::withCurrentReportProductCategory()
            ->categoryRows('completed_service_item_count', $tenantId, $stores, $range, $categoryIds);
    }

    /** @return array<string,int> */
    private function dailyCashPerformance(string $tenantId, array $stores, array $range, array $categoryIds): array
    {
        $out = [];
        $reader = \app\services\query\metric\RegisteredMetricReadServices::withCurrentReportProductCategory();
        $rows = $categoryIds === []
            ? $reader->dailyTotals('actual_performance', $tenantId, $stores, $range)
            : $reader->categoryDailyTotals('actual_performance', $tenantId, $stores, $range, $categoryIds);
        foreach ($rows as $row) $out[(string)$row['business_date']] = (int)$row['metric_value'];
        return $out;
    }

    /** @return array<string,mixed> */
    private function trend(string $tenantId, array $stores, array $range, array $categoryIds): array
    {
        $periodStart = substr($range['start'], 0, 7) . '-01';
        $periodEnd = date('Y-m-t', strtotime($range['end']));
        // A year query is identified by its Jan 1 start. The caller may use
        // today's date as the end while the year is still in progress, so a
        // duration threshold would incorrectly fall back to daily labels.
        $isYear = substr($range['start'], 5, 5) === '01-01'
            && substr($range['start'], 0, 4) === substr($range['end'], 0, 4);
        if ($isYear) {
            // Keep future months at zero, but read the registered daily facts
            // only twice for the whole calendar year instead of 24 queries.
            $yearRange = ['start' => $periodStart, 'end' => substr($range['end'], 0, 4) . '-12-31'];
            $actualByMonth = []; $consumptionByMonth = [];
            foreach ($this->dailyCashPerformance($tenantId, $stores, $yearRange, $categoryIds) as $day => $value) {
                $monthKey = substr($day, 0, 7);
                $actualByMonth[$monthKey] = ($actualByMonth[$monthKey] ?? 0) + $value;
            }
            foreach ($this->dailyPerformance($tenantId, $stores, $yearRange, $categoryIds) as $day => $value) {
                $monthKey = substr($day, 0, 7);
                $consumptionByMonth[$monthKey] = ($consumptionByMonth[$monthKey] ?? 0) + $value;
            }
            $points = [];
            for ($month = 1; $month <= 12; $month++) {
                $key = sprintf('%s-%02d', substr($range['end'], 0, 4), $month);
                $points[] = ['id' => $key, 'label' => sprintf('%02d月', $month), 'actual_performance_cents' => (int)($actualByMonth[$key] ?? 0), 'consumption_performance_cents' => (int)($consumptionByMonth[$key] ?? 0)];
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
        $reader = \app\services\query\metric\RegisteredMetricReadServices::withCurrentReportProductCategory();
        $rows = $categoryIds === []
            ? $reader->dailyTotals('consume_amount', $tenantId, $stores, $range)
            : $reader->categoryDailyTotals('consume_amount', $tenantId, $stores, $range, $categoryIds);
        foreach ($rows as $row) $out[(string)$row['business_date']] = (int)$row['metric_value'];
        return $out;
    }

    /** 来源人数复用原销售单首次达标事件；分类筛选只投影归属，不重算门槛。 */
    private function sources(array $stores, array $range, array $cashRows, array $serviceRows, array $categoryIds): array
    {
        $configured = Db::name('cashier_v3_business_source')->where('parent_id', 0)->where('status', 1)->order('sort', 'asc')->order('id', 'asc')->field('id,name')->select()->toArray();
        $market = new StoreUnifiedReportPhaseTwoServices();
        $qualifiedDays = $market->marketQualifiedMemberDays($stores, $range);
        $dealCounts = [];
        foreach ($qualifiedDays as $day) {
            // 分类筛选只显示达标参与现金分摊最大的分类的人数。
            if ($categoryIds !== [] && !in_array((int)($day['category_id'] ?? 0), $categoryIds, true)) continue;
            $sourceId = (int)$day['business_source_primary_id'];
            $dealCounts[$sourceId] = ($dealCounts[$sourceId] ?? 0) + 1;
        }
        $out = [];
        foreach ($configured as $source) {
            $sourceId = (int)$source['id'];
            $name = (string)$source['name'];
            // Signed actual-performance rows keep a refund on its own business date.
            $matches = array_values(array_filter($cashRows, static function (array $row) use ($sourceId): bool {
                return (int)($row['business_source_primary_id'] ?? 0) === $sourceId;
            }));
            $services = array_values(array_filter($serviceRows, static function (array $row) use ($sourceId): bool {
                return (int)($row['business_source_primary_id'] ?? 0) === $sourceId;
            }));
            // 来源维度的进店数与 A 来源活客均是门店到店指标；体验服务不取得资格。
            $services = \app\services\query\metric\StoreCustomerVisitEligibility::filter($services);
            $activeMembers = []; foreach ($services as $row) if ((int)($row['member_id'] ?? 0) > 0) $activeMembers[(int)$row['member_id']] = true;
            // 来源名称可用中文开头；按完整 UTF-8 字符提取展示代码，避免截断字节导致整页 JSON 编码失败。
            // 英文字母仍转大写，保留仅 A 来源显示活客数的既有业务口径。
            $code = mb_strtoupper(mb_substr(trim($name), 0, 1, 'UTF-8'), 'UTF-8');
            $item = ['source_id' => $sourceId, 'name' => $name, 'source_code' => $code, 'visit_count' => count($services), 'deal_count' => $dealCounts[$sourceId] ?? 0, 'deal_amount_cents' => $this->sum($matches, 'amount_cents')];
            if ($code === 'A') $item['active_customer_count'] = count($activeMembers);
            $out[] = $item;
        }
        return ['records' => $out, 'source_explanation' => '按实际启用的一级业务来源动态展示；仅来源 A 展示活客数。进店数及活客只按非体验完成服务统计，不受人员“不算人数人次”开关影响；成交人数按同一会员当天新消费跨单累计成功现金收款，未计过的原单补交按原单累计，参与过跨单达标的每张原单后续补交不再计人数，在首次达到平台设置的来源门槛之日计一次，跨店补交归原销售门店。同一会员同日跨单跨店只计一次，多天可累计，游客不计；旧导入及旧卡录入原单补交不推动门槛，退款不改变人数资格。按商品分类筛选人数时，资格只归首次达标时参与现金分摊最多的分类。实际业绩金额仍按成功现金收款减现金退款及各自业务日期统计。'];
    }

    private function rankings(array $stores, array $targets, array $actual, string $asOfDate): array
    {
        if (!$stores) return ['company' => ['title' => '分公司目标排行', 'records' => []],
            'city_manager' => ['title' => '城市经理目标排行', 'records' => []],
            'store' => ['title' => '门店目标排行', 'records' => []],
            'source_explanation' => '当前权限范围没有门店。'];
        $storeNames = Db::name('system_store')->whereIn('id', $stores)->column('name', 'id');
        $dimensionRows = [];
        $this->organizationDimensions->beginCurrentDimensionSnapshot();
        try {
            foreach ($stores as $storeId) {
                $row = ['store_id' => $storeId];
                $this->organizationDimensions->project($row, '', '', $asOfDate);
                $dimensionRows[$storeId] = $row;
            }
        } finally {
            $this->organizationDimensions->endCurrentDimensionSnapshot();
        }
        // 分组行只汇总传入的同一门店集合；全范围没有目标时保留组织排行和实际业绩，
        // 但缺失的分母仍为 null，不能用 0 元目标或 0% 完成率冒充已配置目标。
        $build = function (string $dimension) use ($stores, $actual, $targets, $storeNames, $dimensionRows): array {
            $rows = [];
            foreach ($stores as $storeId) {
                $key = $dimension === 'store' ? (string)$storeId : (string)($dimensionRows[$storeId][$dimension] ?? '未配置');
                $name = $dimension === 'store' ? (string)($storeNames[$storeId] ?? ('门店' . $storeId)) : $key;
                if (!isset($rows[$key])) $rows[$key] = ['name' => $name, 'target_amount_cents' => null, 'actual_performance_cents' => 0];
                if (array_key_exists($storeId, $targets)) {
                    $rows[$key]['target_amount_cents'] = (int)($rows[$key]['target_amount_cents'] ?? 0) + (int)$targets[$storeId];
                }
                $rows[$key]['actual_performance_cents'] += (int)($actual[$storeId] ?? 0);
            }
            foreach ($rows as &$row) {
                $target = $row['target_amount_cents'];
                $row['achievement_rate'] = $target !== null && $target > 0
                    ? round($row['actual_performance_cents'] / $target * 100, 1) : null;
            }
            unset($row);
            usort($rows, static function ($a, $b): int {
                return ($b['achievement_rate'] ?? -1) <=> ($a['achievement_rate'] ?? -1)
                    ?: $b['actual_performance_cents'] <=> $a['actual_performance_cents']
                    ?: strcmp($a['name'], $b['name']);
            });
            return $rows;
        };
        return ['company' => ['title' => '分公司目标排行', 'records' => $build('company')],
            'city_manager' => ['title' => '城市经理目标排行', 'records' => $build('city_manager')],
            'store' => ['title' => '门店目标排行', 'records' => $build('store')],
            'source_explanation' => $targets
                ? '只展示本月已设置全店实际业绩目标的门店；目标按当前组织归属汇总，完成值按相同门店的统一事实计算。'
                : '本月未设置目标；按授权门店的实际业绩展示排行，目标和完成率显示 -。'];
    }

    private function alerts(array $stores, array $targets, array $actual): array
    {
        if (!$stores) return ['records' => [], 'source_explanation' => '当前范围没有已配置月目标的门店。'];
        $names = Db::name('system_store')->whereIn('id', $stores)->column('name', 'id'); $out = [];
        foreach ($stores as $id) { $target = (int)($targets[$id] ?? 0); if ($target <= 0) continue; $rate = round((int)($actual[$id] ?? 0) / $target * 100, 1); if ($rate < 80) $out[] = ['store_id' => $id, 'store_name' => (string)($names[$id] ?? ''), 'type' => '目标达成未达标', 'message' => '目标达成率 ' . $rate . '% ，低于 80%', 'achievement_rate' => $rate]; }
        return ['records' => $out, 'source_explanation' => '当前阶段预警门店目标达成率低于 80%；后续环比和退款阈值使用同一后端阈值配置后再启用。'];
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
    /** 缺少目标行时分母和剩余值保持 null；显示层统一将不可计算字段渲染为 -。 */
    private function goalProjection(string $title, ?int $target, int $actual, string $explanation): array
    {
        $rate = $target !== null && $target > 0 ? round($actual / $target * 100, 1) : null;
        return ['title' => $title, 'target_amount_cents' => $target, 'actual_performance_cents' => $actual,
            'remaining_amount_cents' => $target === null ? null : max(0, $target - $actual),
            'achievement_rate' => $rate, 'source_explanation' => $explanation];
    }
    private function filterSchema(array $roots):array{$options=[['value'=>0,'label'=>'全部商品']];foreach($roots as$row)$options[]=['value'=>(int)$row['id'],'label'=>(string)$row['name']];return[['key'=>'start_date','label'=>'开始日期','type'=>'date','source_explanation'=>'包含开始当天。'],['key'=>'end_date','label'=>'截止日期','type'=>'date','source_explanation'=>'包含截止当天。'],['key'=>'category_id','label'=>'商品分类','type'=>'select','options'=>$options,'source_explanation'=>'只显示已开启统计的一级商品分类；选择后包含全部下级分类。'],['key'=>'store_ids','label'=>'组织 / 门店','type'=>'scope_picker','source_explanation'=>'当前账号权限范围由后端强制，选择只能缩小范围。']];}
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
        $out['sale_type_cards'] = '销售数量来自已完成销售明细；现金业绩来自关联该销售明细的正向实收分摊，不含充值、退款及未关联明细的补交。疗程卡按卡项商品当前普通卡统计类型识别，包含定制卡。定制卡顶部按张计数，各大类按卡内项目当前所属分类分别计张数，同一张卡在同一大类只计一次；现金业绩按原单卡内项目分摊，不重复计金额。单项和产品按销售明细商品类型区分。体验卡不展示，也不并入其他类型，原有经营业绩合计不变。门店、日期及已选择的商品分类沿用本看板筛选范围。';
        return $out;
    }
}
