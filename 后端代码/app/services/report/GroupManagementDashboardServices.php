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
    public const METRIC_VERSION = 'group-management-current-category-v4';
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
        if ($selectedCategory > 0 && $categoryIds === []) throw new \InvalidArgumentException('商品分类不存在或已停用');
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
                'aggregation_caught_up' => false,
            ];
        }
        // 进度只比较已设置目标的门店×月份；普通经营业绩卡仍按完整筛选范围展示。
        $goalProgress = (new \app\services\target\TargetSchemeReportServices())->groupProgress(
            ['tenant_id' => $scope['tenant_id'], 'store_ids' => $scope['store_ids']], $year, $range['end']
        );
        $monthTargets = $goalProgress['month_targets'];
        $categoryCards = \app\services\query\metric\RegisteredMetricReadServices::withCurrentReportProductCategory()->categoryDashboardCards(
            'cash_performance', $scope['tenant_id'], $scope['store_ids'], $range, $categoryTree['roots'], $categoryTree['children'], $categoryIds
        );
        $currentTarget = array_sum($monthTargets);
        $yearTarget = array_sum($goalProgress['year_targets']);
        $monthActual = $goalProgress['month_actual'];
        $yearActual = $goalProgress['year_actual'];
        $targetedStoreIds = array_map('intval', array_keys($monthTargets));

        return [
            'dashboard_code' => self::DASHBOARD_CODE,
            'title' => '集团管理看板',
            'scope' => ['store_ids' => $scope['store_ids'], 'range' => $range, 'selected_category_id' => $selectedCategory],
            'filter_schema' => $this->filterSchema($categoryTree['roots']),
            'cards' => $cards,
            'trend' => $this->trend($scope['tenant_id'], $scope['store_ids'], $range, $categoryIds),
            'categories' => $categoryCards,
            'sources' => $this->sources($scope['store_ids'], $range, $cashRows, $serviceRows),
            'goals' => [
                'month' => $this->goalProjection('本月目标进度', $currentTarget, $monthActual, '只比较本月已设置全店实际业绩目标的门店；完成值来自相同门店的统一事实，不随商品分类筛选改变。'),
                'year' => $this->goalProjection('本年目标进度', $yearTarget, $yearActual, '全年目标汇总已配置月份；完成值只统计这些门店和月份截至所选日期的统一事实。'),
                'source_explanation' => '目标由已关联集团管理看板的生效目标方案提供；未配置目标的门店和月份不进入达成率。',
            ],
            'rankings' => $this->rankings($targetedStoreIds, $monthTargets, $goalProgress['month_store_actuals'], $range['end']),
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

    /** @return array{roots:array<int,array<string,mixed>>,children:array<int,array<int,int>>} */
    private function categories(): array
    {
        // 商品分类表同时保存平台配置和按门店同步的副本。看板只使用平台
        // 分类正本，但不因目录隐藏状态抹掉已有商品的报表分类。
        $rows = Db::name('store_product_category')->where('type', 0)->where('relation_id', 0)
            ->field('id,pid,cate_name')->order('pid', 'asc')->order('id', 'asc')->select()->toArray();
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

    /** Signed net-cash rows with current platform category and frozen component cash weights. @return array<int,array<string,mixed>> */
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
            // 来源维度的进店数与 A 来源活客均是门店到店指标；体验服务不取得资格。
            $services = \app\services\query\metric\StoreCustomerVisitEligibility::filter($services);
            $orders = []; foreach ($matches as $row) if (trim((string)($row['order_id'] ?? '')) !== '') $orders[(string)$row['order_id']] = true;
            $activeMembers = []; foreach ($services as $row) if ((int)($row['member_id'] ?? 0) > 0) $activeMembers[(int)$row['member_id']] = true;
            $code = strtoupper(substr(trim($name), 0, 1));
            $item = ['source_id' => $sourceId, 'name' => $name, 'source_code' => $code, 'visit_count' => count($services), 'deal_count' => count($orders), 'deal_amount_cents' => $this->sum($matches, 'amount_cents')];
            if ($code === 'A') $item['active_customer_count'] = count($activeMembers);
            $out[] = $item;
        }
        return ['records' => $out, 'source_explanation' => '按实际启用的一级业务来源动态展示；仅来源 A 展示活客数。进店数及活客只按非体验完成服务统计，不受人员“不算人数人次”开关影响；成交数按成功成交订单去重，金额按正向成功收款及卡内项目分摊统计。'];
    }

    private function rankings(array $stores, array $targets, array $actual, string $asOfDate): array
    {
        if (!$stores) return ['company' => ['title' => '分公司目标排行', 'records' => []],
            'city_manager' => ['title' => '城市经理目标排行', 'records' => []],
            'store' => ['title' => '门店目标排行', 'records' => []],
            'source_explanation' => '当前范围没有已配置月目标的门店。'];
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
        $build = function (string $dimension) use ($stores, $actual, $targets, $storeNames, $dimensionRows): array { $rows = []; foreach ($stores as $storeId) { $key = $dimension === 'store' ? (string)$storeId : (string)($dimensionRows[$storeId][$dimension] ?? '未配置'); $name = $dimension === 'store' ? (string)($storeNames[$storeId] ?? ('门店' . $storeId)) : $key; if (!isset($rows[$key])) $rows[$key] = ['name' => $name, 'target_amount_cents' => 0, 'actual_performance_cents' => 0]; $rows[$key]['target_amount_cents'] += (int)($targets[$storeId] ?? 0); $rows[$key]['actual_performance_cents'] += (int)($actual[$storeId] ?? 0); } foreach ($rows as &$row) $row['achievement_rate'] = $row['target_amount_cents'] > 0 ? round($row['actual_performance_cents'] / $row['target_amount_cents'] * 100, 1) : null; unset($row); usort($rows, static function ($a, $b): int { return ($b['achievement_rate'] ?? -1) <=> ($a['achievement_rate'] ?? -1) ?: strcmp($a['name'], $b['name']); }); return $rows; };
        return ['company' => ['title' => '分公司目标排行', 'records' => $build('company')], 'city_manager' => ['title' => '城市经理目标排行', 'records' => $build('city_manager')], 'store' => ['title' => '门店目标排行', 'records' => $build('store')], 'source_explanation' => '只展示本月已设置全店实际业绩目标的门店；目标按当前组织归属汇总，完成值按相同门店的统一事实计算。'];
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
