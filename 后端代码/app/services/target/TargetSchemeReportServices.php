<?php

declare(strict_types=1);

namespace app\services\target;

use app\services\query\metric\MetricDefinitionRegistry;
use app\services\query\metric\RegisteredMetricReadServices;
use think\facade\Db;

/**
 * 所有目标分析的后端组合入口：方案负责目标，注册指标 Reader 负责完成值。
 * 页面只能展示结果，不能自行计算月度业绩或扩大门店权限。
 */
final class TargetSchemeReportServices
{
    private TargetSchemeServices $schemes;
    private RegisteredMetricReadServices $metrics;

    public function __construct(?TargetSchemeServices $schemes = null, ?RegisteredMetricReadServices $metrics = null)
    {
        $this->schemes = $schemes ?: new TargetSchemeServices();
        $this->metrics = $metrics ?: new RegisteredMetricReadServices();
    }

    /** 同一查询结果供卡片、月表、门店表、移动分析与导出投影使用。 */
    public function read(array $context, array $input, string $reportCode): array
    {
        $start = (string)($input['start_month'] ?? date('Y-m'));
        $end = (string)($input['end_month'] ?? $start);
        $months = $this->months($start, $end);
        $stores = array_values(array_unique(array_filter(array_map('intval', (array)($context['store_ids'] ?? [])))));
        if (!$stores) return $this->emptyResult($reportCode, $months);
        $requested = (int)($input['store_id'] ?? 0);
        if ($requested > 0) {
            if (!in_array($requested, $stores, true)) throw new \InvalidArgumentException('无权查看该门店目标');
            $stores = [$requested];
        }
        $bound = $this->schemes->effective(['tenant_id' => (string)($context['tenant_id'] ?? '0'), 'store_ids' => $stores], $reportCode, $months);
        $metricFilter = trim((string)($input['metric_keys'] ?? $input['metric_code'] ?? ''));
        $wanted = $metricFilter === '' ? [] : array_fill_keys(array_filter(array_map('trim', explode(',', $metricFilter))), true);
        $scopeFilter = trim((string)($input['scope_type'] ?? ''));
        $lines = [];
        foreach ($bound['lines'] as $line) {
            if ($wanted && !isset($wanted[$line['metric_code']])) continue;
            if ($scopeFilter !== '' && $line['scope_type'] !== $scopeFilter) continue;
            $lines[] = $line;
        }
        $storeNames = Db::name('system_store')->whereIn('id', $stores)->column('name', 'id');
        $categoryNames = []; $productNames = [];
        $categoryIds = array_values(array_unique(array_map('intval', array_column(array_filter($lines, static fn($l) => $l['scope_type'] === 'category'), 'scope_id'))));
        $productIds = array_values(array_unique(array_map('intval', array_column(array_filter($lines, static fn($l) => $l['scope_type'] === 'product'), 'scope_id'))));
        if ($categoryIds) $categoryNames = Db::name('store_product_category')->whereIn('id', $categoryIds)->column('cate_name', 'id');
        if ($productIds) $productNames = Db::name('store_product')->whereIn('id', $productIds)->column('store_name', 'id');

        $cache = []; $rows = [];
        $tenant = (string)($context['tenant_id'] ?? '0');
        foreach ($lines as $line) {
            $month = (string)$line['month_key'];
            $metric = (string)$line['metric_code'];
            $contract = MetricDefinitionRegistry::get($metric);
            $version = (string)($contract['metric_version'] ?? MetricDefinitionRegistry::VERSION);
            if ($version !== (string)$line['metric_version']) throw new \InvalidArgumentException('目标指标口径已更新，请重新核对方案后启用');
            $coverage = $month . '-01' >= MetricDefinitionRegistry::COVERAGE_START;
            $value = null;
            if ($coverage) {
                $key = implode(':', [$line['store_id'], $month, $metric, $line['scope_type'], $line['scope_id']]);
                if (!array_key_exists($key, $cache)) $cache[$key] = $this->actual($tenant, $line, $month);
                $value = $cache[$key];
            }
            $target = (int)$line['target_value'];
            $scopeName = $line['scope_type'] === 'product' ? (string)($productNames[$line['scope_id']] ?? ('商品#'.$line['scope_id']))
                : ($line['scope_type'] === 'category' ? (string)($categoryNames[$line['scope_id']] ?? ('分类#'.$line['scope_id'])) : '全店');
            $rows[] = [
                'line_id' => (int)$line['id'], 'scheme_id' => (int)$line['scheme_id'],
                'store_id' => (int)$line['store_id'], 'store_name' => (string)($storeNames[$line['store_id']] ?? ('门店#'.$line['store_id'])),
                'month_key' => $month, 'metric_code' => $metric, 'metric_name' => $this->metricName($metric),
                'scope_type' => $line['scope_type'], 'scope_id' => (int)$line['scope_id'], 'scope_name' => $scopeName,
                'unit_code' => $line['unit_code'], 'target_value' => $target, 'actual_value' => $value,
                'completion_rate' => $value !== null && $target > 0 ? round($value / $target * 100, 1) : null,
                'coverage' => $coverage ? 'covered' : 'uncovered', 'metric_version' => $version,
            ];
        }
        return [
            'report_code' => $reportCode, 'scheme_id' => (int)$bound['scheme_id'],
            'scheme_ids' => $bound['scheme_ids'],
            'months' => $months, 'rows' => $rows, 'row_count' => count($rows),
            'series' => in_array($reportCode, ['target_analysis', 'merchant_target_analysis'], true)
                ? $this->analysisSeries($rows) : [],
            'metric_version' => MetricDefinitionRegistry::VERSION,
            'coverage_start' => MetricDefinitionRegistry::COVERAGE_START,
            'data_as_of' => date('Y-m-d H:i:s'),
            'source_explanation' => '目标由当前生效目标方案提供；完成值由同一口径的业务事实计算。历史未覆盖的月份不显示为零。',
        ];
    }

    /** 分析卡片仅汇合同一指标、同一对象的不同门店月份；全店/分类/商品绝不混加。 */
    private function analysisSeries(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = implode(':', [$row['metric_code'], $row['scope_type'], $row['scope_id']]);
            if (!isset($groups[$key])) $groups[$key] = [
                'key' => $key, 'metric_code' => $row['metric_code'], 'metric_name' => $row['metric_name'],
                'scope_type' => $row['scope_type'], 'scope_id' => $row['scope_id'],
                'scope_name' => $row['scope_name'], 'unit_code' => $row['unit_code'], 'months' => [],
            ];
            $month = $row['month_key'];
            if (!isset($groups[$key]['months'][$month])) $groups[$key]['months'][$month] = [
                'month_key' => $month, 'target_value' => 0, 'actual_value' => 0,
                'coverage' => 'covered', 'store_count' => 0,
            ];
            $bucket = &$groups[$key]['months'][$month];
            $bucket['target_value'] += (int)$row['target_value'];
            if ($row['actual_value'] === null) $bucket['coverage'] = 'uncovered';
            else $bucket['actual_value'] += (int)$row['actual_value'];
            $bucket['store_count']++;
            unset($bucket);
        }
        $out = [];
        foreach ($groups as $group) {
            ksort($group['months']);
            $group['months'] = array_values($group['months']);
            foreach ($group['months'] as &$month) {
                if ($month['coverage'] !== 'covered') $month['actual_value'] = null;
                $month['completion_rate'] = $month['actual_value'] !== null && $month['target_value'] > 0
                    ? round($month['actual_value'] / $month['target_value'] * 100, 1) : null;
            }
            unset($month);
            $out[] = $group;
        }
        return $out;
    }

    /** 集团看板仅消费“全店实际业绩”目标，同月同店同指标只保留一个有效值。 */
    public function groupTotals(array $context, int $year, array $months): array
    {
        $monthKeys = array_map(static fn($m) => sprintf('%04d-%02d', $year, (int)$m), $months);
        $bound = $this->schemes->effective($context, 'group_dashboard', $monthKeys);
        $out = [];
        foreach ($bound['lines'] as $line) {
            if ($line['metric_code'] !== 'actual_performance' || $line['scope_type'] !== 'store') continue;
            $id = (int)$line['store_id'];
            $out[$id] = ($out[$id] ?? 0) + (int)$line['target_value'];
        }
        return $out;
    }

    /**
     * 集团进度的分母和分子必须覆盖同一组“门店×月份”。未设置目标的门店或月份不参与达成率，
     * 但仍可在经营业绩卡片看到其真实业绩；商品分类筛选也不能改变全店目标的完成值。
     */
    public function groupProgress(array $context, int $year, string $asOfDate): array
    {
        $months = array_map(static fn($m) => sprintf('%04d-%02d', $year, $m), range(1, 12));
        $bound = $this->schemes->effective($context, 'group_dashboard', $months);
        $byMonth = []; $monthTargets = []; $yearTargets = [];
        $current = substr($asOfDate, 0, 7);
        foreach ($bound['lines'] as $line) {
            if ($line['metric_code'] !== 'actual_performance' || $line['scope_type'] !== 'store') continue;
            $version = (string)(MetricDefinitionRegistry::get('actual_performance')['metric_version'] ?? MetricDefinitionRegistry::VERSION);
            if ((string)$line['metric_version'] !== $version) throw new \InvalidArgumentException('集团目标指标口径已更新，请重新核对目标方案');
            $storeId = (int)$line['store_id']; $month = (string)$line['month_key'];
            $byMonth[$month][] = $storeId;
            $yearTargets[$storeId] = ($yearTargets[$storeId] ?? 0) + (int)$line['target_value'];
            if ($month === $current) $monthTargets[$storeId] = (int)$line['target_value'];
        }
        $monthActual = 0; $yearActual = 0; $monthStoreActuals = [];
        $eligible = array_filter($byMonth, static fn($stores, $month) => $month <= $current, ARRAY_FILTER_USE_BOTH);
        if ($eligible) {
            $storeSets = []; $allStores = [];
            foreach ($eligible as $month => $stores) {
                $storeSets[$month] = array_fill_keys(array_unique(array_map('intval', $stores)), true);
                foreach (array_keys($storeSets[$month]) as $storeId) $allStores[$storeId] = true;
            }
            // The registered Reader owns the cash-minus-refund formula. One
            // store/day population feeds both goal totals and current-month
            // rankings, while excluding stores without a target in that month.
            $range = ['start' => min(array_keys($eligible)) . '-01', 'end' => $asOfDate];
            foreach ($this->metrics->dailyStoreTotals('actual_performance', (string)($context['tenant_id'] ?? '0'), array_keys($allStores), $range) as $row) {
                $month = substr((string)$row['business_date'], 0, 7);
                $storeId = (int)$row['store_id'];
                if (!isset($storeSets[$month][$storeId])) continue;
                $value = (int)$row['amount_cents'];
                $yearActual += $value;
                if ($month === $current) {
                    $monthActual += $value;
                    $monthStoreActuals[$storeId] = ($monthStoreActuals[$storeId] ?? 0) + $value;
                }
            }
        }
        // 没有目标行时仍展示授权门店的真实完成业绩；只在整个比较期缺少分母时
        // 才使用完整范围，不能将无目标门店混入已有目标的达成率分子。
        if (!$monthTargets || !$yearTargets) {
            $fallbackStart = !$yearTargets ? sprintf('%04d-01-01', $year) : $current . '-01';
            foreach ($this->metrics->dailyStoreTotals('actual_performance', (string)($context['tenant_id'] ?? '0'),
                (array)($context['store_ids'] ?? []), ['start' => $fallbackStart, 'end' => $asOfDate]) as $row) {
                $value = (int)$row['amount_cents'];
                if (!$yearTargets) $yearActual += $value;
                if (!$monthTargets && substr((string)$row['business_date'], 0, 7) === $current) {
                    $monthActual += $value;
                    $storeId = (int)$row['store_id'];
                    $monthStoreActuals[$storeId] = ($monthStoreActuals[$storeId] ?? 0) + $value;
                }
            }
        }
        return ['scheme_id' => (int)$bound['scheme_id'], 'scheme_ids' => $bound['scheme_ids'], 'month_targets' => $monthTargets,
            'year_targets' => $yearTargets, 'month_actual' => $monthActual, 'year_actual' => $yearActual,
            'month_store_actuals' => $monthStoreActuals];
    }

    private function actual(string $tenant, array $line, string $month): int
    {
        $range = ['start' => $month . '-01', 'end' => date('Y-m-t', strtotime($month . '-01'))];
        $metric = (string)$line['metric_code'];
        $stores = [(int)$line['store_id']];
        if ($line['scope_type'] === 'store') return $this->metrics->summary($metric, $tenant, $stores, $range);
        if ($line['scope_type'] === 'category') {
            // 只用方案明细保存时冻结的分类集合；当前商品主数据变动不得改写历史达成值。
            $ids = array_values(array_unique(array_filter(array_map('intval',
                (array)(json_decode((string)$line['scope_category_ids_json'], true) ?: [])))));
            if (!in_array((int)$line['scope_id'], $ids, true)) throw new \InvalidArgumentException('分类目标快照不完整，请重新保存草稿');
            return $this->metrics->categorySummary($metric, $tenant, $stores, $range, $ids);
        }
        // 商品金额和项目数量从已注册分类行取发生时的 item_id。
        // Reader 已处理卡项组件分摊、退款和正常数据资格，不能在此重新查询订单金额。
        $rows = $this->metrics->categoryRows($metric, $tenant, $stores, $range);
        $field = $line['unit_code'] === 'count' ? 'quantity' : 'amount_cents';
        $total = 0;
        foreach ($rows as $row) if ((int)($row['item_id'] ?? 0) === (int)$line['scope_id']) $total += (int)($row[$field] ?? 0);
        return $total;
    }

    private function months(string $start, string $end): array
    {
        if (!preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D', $start)
            || !preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D', $end)
            || $start > $end) throw new \InvalidArgumentException('目标月份范围不正确');
        $cursor = new \DateTimeImmutable($start . '-01');
        $last = new \DateTimeImmutable($end . '-01');
        $out = [];
        while ($cursor <= $last) {
            $out[] = $cursor->format('Y-m');
            if (count($out) > 24) throw new \InvalidArgumentException('一次最多查询24个月');
            $cursor = $cursor->modify('+1 month');
        }
        return $out;
    }

    private function metricName(string $code): string
    {
        foreach ($this->schemes->catalog()['metrics'] as $metric) if ($metric['code'] === $code) return $metric['name'];
        return $code;
    }

    private function emptyResult(string $report, array $months): array
    {
        return ['report_code' => $report, 'scheme_id' => 0, 'scheme_ids' => [], 'months' => $months, 'rows' => [], 'row_count' => 0,
            'metric_version' => MetricDefinitionRegistry::VERSION, 'coverage_start' => MetricDefinitionRegistry::COVERAGE_START,
            'data_as_of' => date('Y-m-d H:i:s'), 'source_explanation' => '当前范围没有可查看的目标门店。'];
    }
}
