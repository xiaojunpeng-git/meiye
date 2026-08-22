<?php

declare(strict_types=1);

namespace app\services\report;

use app\services\cashier\v3\CashierV3ScopeResolver;
use think\facade\Db;

/**
 * 第九阶段客户分析统一读取服务。
 *
 * 这里不重新计算一套客户指标，而是把已经通过统一事实层的客户、运营、
 * 六维和退款报表组合成客户页面需要的功能结果。查询、合计、导出和下钻
 * 都从同一个 read() 结果生成，页面只能展示后端返回值。
 */
final class CustomerAnalyticsServices
{
    public const METRIC_VERSION = 'customer-analytics-v1';

    private const REPORTS = [
        'customer_overview' => '客户概况',
        'customer_source_analysis' => '客户开源分析',
        'customer_visit_analysis' => '到店数据分析',
        'customer_store_health' => '门店健康数据分析',
        'customer_consumption_tier' => '消费分级分析',
        'customer_cash_performance' => '现金业绩分析',
        'customer_refund_performance' => '退货业绩分析',
        'customer_item_analysis' => '客户品相分析',
        'customer_unconsumed_analysis' => '客户未耗分析',
    ];

    public static function reportCodes(): array
    {
        return array_keys(self::REPORTS);
    }

    public static function catalogEntries(): array
    {
        $rows = [];
        foreach (self::REPORTS as $code => $name) {
            $rows[] = ['folder' => '客户', 'code' => $code, 'name' => $name, 'platform_only' => true];
        }
        return $rows;
    }

    public function supports(string $report): bool
    {
        return isset(self::REPORTS[$report]);
    }

    /**
     * 查询和导出的共同入口。$all 为 true 时只取消页面分页，不改变筛选和权限。
     */
    public function read(string $report, array $stores, array $range, array $input, bool $all = false): array
    {
        if (!$this->supports($report)) throw new \InvalidArgumentException('不支持的客户分析功能');
        $stores = array_values(array_unique(array_filter(array_map('intval', $stores))));
        if ($stores === []) throw new \InvalidArgumentException('当前账号没有可查看的门店范围');
        $queryInput = $all ? array_merge($input, ['_internal_all' => true, 'page' => 1]) : $input;
        $queryInput['start_date'] = (string)$range['start'];
        $queryInput['end_date'] = (string)$range['end'];

        $result = $this->readSource($report, $stores, $range, $queryInput);
        $result = is_array($result) ? $result : [];
        $result['report'] = $report;
        $result['title'] = self::REPORTS[$report];
        $result['scope'] = array_merge((array)($result['scope'] ?? []), [
            'store_ids' => $stores,
            'date_range' => ['start' => (string)$range['start'], 'end' => (string)$range['end'], 'inclusive' => true],
        ]);
        $result['metric_version'] = self::METRIC_VERSION . ':' . (string)($result['metric_version'] ?? 'facts');
        $result['data_as_of'] = (string)($result['data_as_of'] ?? date('Y-m-d H:i:s'));
        $pendingByReport = [
            'customer_cash_performance' => ['转化率'],
            'customer_refund_performance' => ['退款率'],
            'customer_unconsumed_analysis' => ['未耗率', '未耗同比'],
        ];
        if (isset($pendingByReport[$report])) {
            $result['pending_metrics'] = array_values(array_unique(array_merge(
                (array)($result['pending_metrics'] ?? []), $pendingByReport[$report]
            )));
        }
        $result['aggregation_caught_up'] = $report === 'customer_source_analysis'
            ? false : (bool)($result['aggregation_caught_up'] ?? true);
        $existingAggregationStatus = trim((string)($result['aggregation_status'] ?? ''));
        $result['aggregation_status'] = $report === 'customer_unconsumed_analysis'
            ? '以下指标尚未完整接入统一事实：' . implode('、', (array)($result['pending_metrics'] ?? [])) . '。未知值保持空态，不用 0 代替。'
            : (($report === 'customer_refund_performance' && isset($pendingByReport[$report]))
                ? '退款成功事实已接入；退款率因同期成功收款分母尚未接入，显示“-”。'
                : (($report === 'customer_cash_performance' && isset($pendingByReport[$report]))
                    ? '客户现金业绩事实已接入；转化率因来源客户分母尚未接入，显示“-”。'
                    : ($existingAggregationStatus !== '' ? $existingAggregationStatus : '已读取到当前统一事实数据。')));
        $result['source_explanations'] = $this->humanSources($report, (array)($result['columns'] ?? []), (array)($result['source_explanations'] ?? []));
        // Keep the UI-friendly ordered rows and expose the standard field
        // dictionary separately.  Consumers can address a column by its
        // stable key instead of matching labels or array positions.
        $result['field_explanations'] = [];
        foreach ((array)$result['source_explanations'] as $explanation) {
            $key = trim((string)($explanation['key'] ?? ''));
            if ($key !== '') $result['field_explanations'][$key] = (string)($explanation['source_explanation'] ?? '');
        }
        $result['table_layout'] = array_merge([
            'fixed' => true, 'sticky_query' => true, 'sticky_header' => true,
            'sticky_summary' => true, 'result_scroll' => true,
        ], (array)($result['table_layout'] ?? []));
        $result['drilldown'] = (array)($result['drilldown'] ?? [
            'enabled' => true,
            'params' => ['report', 'start_date', 'end_date', 'org_id', 'store_id', 'store_ids', 'category_path', 'metric_code'],
        ]);

        return $result;
    }

    public function export(string $report, array $stores, array $range, array $input): array
    {
        $result = $this->read($report, $stores, $range, $input, true);
        $columns = (array)($result['columns'] ?? []);
        $records = (array)($result['records'] ?? []);
        $path = tempnam(sys_get_temp_dir(), 'customer-analytics-');
        if ($path === false) throw new \RuntimeException('客户分析导出文件创建失败');
        $handle = fopen($path, 'wb');
        if ($handle === false) throw new \RuntimeException('客户分析导出文件打开失败');
        fwrite($handle, "\xEF\xBB\xBF");
        $keys = array_map(static fn(array $column): string => (string)($column['key'] ?? ''), $columns);
        $labels = array_map(static fn(array $column): string => (string)($column['label'] ?? $column['key'] ?? ''), $columns);
        $sections = (array)($result['sections'] ?? []);
        if ($sections !== []) {
            // A report may expose an aggregate section and a detail section;
            // keep both in one deterministic CSV instead of mixing their
            // rows under one header.  The blank separator is intentional and
            // makes the two contracts readable in spreadsheet software.
            foreach ($sections as $index => $section) {
                if ($index > 0) fputcsv($handle, []);
                $sectionColumns = (array)($section['columns'] ?? []);
                $sectionKeys = array_map(static fn(array $column): string => (string)($column['key'] ?? ''), $sectionColumns);
                $sectionLabels = array_map(static fn(array $column): string => (string)($column['label'] ?? $column['key'] ?? ''), $sectionColumns);
                fputcsv($handle, [(string)($section['label'] ?? '')]);
                fputcsv($handle, $sectionLabels);
                foreach ((array)($section['records'] ?? []) as $record) {
                    $row = [];
                    foreach ($sectionKeys as $key) $row[] = $key === '' ? '' : ($record[$key] ?? '-');
                    fputcsv($handle, $row);
                }
                if ($index === count($sections) - 1 && (array)($result['summary_row'] ?? []) !== []) {
                    $summary = (array)$result['summary_row'];
                    $row = [];
                    foreach ($sectionKeys as $key) $row[] = $key === '' ? '' : ($summary[$key] ?? '-');
                    fputcsv($handle, $row);
                }
            }
        } else {
            fputcsv($handle, $labels);
            foreach ($records as $record) {
                $row = [];
                foreach ($keys as $key) $row[] = $key === '' ? '' : ($record[$key] ?? '-');
                fputcsv($handle, $row);
            }
            if ((array)($result['summary_row'] ?? []) !== []) {
                $summary = (array)$result['summary_row'];
                $row = [];
                foreach ($keys as $key) $row[] = $key === '' ? '' : ($summary[$key] ?? '-');
                fputcsv($handle, $row);
            }
        }
        fclose($handle);
        return [
            'filename' => '客户分析-' . (string)($result['title'] ?? $report) . '-' . date('YmdHis') . '.csv',
            'path' => $path, 'columns' => $columns, 'records' => $records,
            'summary_row' => $result['summary_row'] ?? [], 'column_groups' => $result['column_groups'] ?? [],
            'sections' => $result['sections'] ?? [],
            'source_explanations' => $result['source_explanations'] ?? [],
            'field_explanations' => $result['field_explanations'] ?? [],
            'scope' => $result['scope'] ?? [], 'metric_version' => $result['metric_version'] ?? self::METRIC_VERSION,
            'data_as_of' => $result['data_as_of'] ?? '', 'aggregation_caught_up' => (bool)($result['aggregation_caught_up'] ?? false),
            'pending_metrics' => $result['pending_metrics'] ?? [],
            'aggregation_status' => $result['aggregation_status'] ?? '',
        ];
    }

    private function readSource(string $report, array $stores, array $range, array $input): array
    {
        $scope = is_array($input['_report_scope'] ?? null) ? $input['_report_scope'] : [];
        if ((string)($scope['mode'] ?? '') === 'none') throw new \InvalidArgumentException('当前账号没有可查看的数据范围');
        $contextInput = $input;
        $contextInput['_authorized_store_ids'] = $stores;

        switch ($report) {
            case 'customer_overview':
                $result = (new StoreUnifiedReportServices())->query($stores, array_merge($contextInput, ['report' => 'customers']));
                return array_merge($result, $this->overviewVisuals($stores, $range, $contextInput));
            case 'customer_source_analysis':
                return $this->sourceAnalysis($stores, $range, $contextInput);
            case 'customer_visit_analysis':
                $result = (new StoreUnifiedReportPhaseFourServices())->query('operations_customer_status_bdegh', $stores, $range, $contextInput);
                return array_merge($result, $this->visitVisuals($stores, $range, $contextInput));
            case 'customer_store_health':
                return (new StoreUnifiedReportPhaseFourServices())->query('operations_health_data', $stores, $range, $contextInput);
            case 'customer_consumption_tier':
                // Phase 3 的分级读取统一现金事实及反向事实，避免会员看板旧
                // cashRows 直接按 sale_fact_id 连接而漏掉退款/作废回接。
                $metric = in_array((string)($contextInput['consumption_metric'] ?? 'cash'), ['cash', 'consumption'], true)
                    ? (string)$contextInput['consumption_metric'] : 'cash';
                $contextInput['consumption_metric'] = $metric;
                return (new StoreUnifiedReportPhaseThreeServices())->query(
                    'six_dimension_cash_consumption_analysis', $stores, $range, $contextInput
                );
            case 'customer_cash_performance':
                return $this->cashPerformance($stores, $range, $contextInput);
            case 'customer_refund_performance':
                return $this->refundPerformance($stores, $range, $contextInput);
            case 'customer_item_analysis':
                return $this->itemAnalysis($stores, $range, $contextInput);
            case 'customer_unconsumed_analysis':
                return $this->unconsumedAnalysis($stores, $range, $contextInput);
        }
        throw new \InvalidArgumentException('不支持的客户分析功能');
    }

    /** Visual series are projections of the same completed-service facts used by the cards. */
    private function overviewVisuals(array $stores, array $range, array $input): array
    {
        $phaseFour = new StoreUnifiedReportPhaseFourServices();
        $facts = $phaseFour->customerServiceRows($stores, $range, (array)($input['_report_scope'] ?? []));
        $cashFacts = $phaseFour->customerCashRows($stores, $range, (array)($input['_report_scope'] ?? []));
        $months = [];
        $members = [];
        foreach ($facts as $fact) {
            $date = (string)($fact['business_date'] ?? '');
            if (preg_match('/^(\d{4}-\d{2})-\d{2}$/', $date, $m)) {
                $months[$m[1]][(string)($fact['member_id'] ?? '')] = true;
            }
        }
        // The age panel is explicitly a成交客户画像, so its denominator comes
        // from successful signed cash facts, not from every service visit.
        foreach ($cashFacts as $fact) {
            $memberId = (int)($fact['member_id'] ?? 0);
            if ($memberId > 0) $members[$memberId] = true;
        }
        ksort($months);
        $trend = [];
        foreach ($months as $month => $ids) $trend[] = ['label' => $month, 'value' => count($ids)];
        $ageCounts = ['18–25岁' => 0, '26–35岁' => 0, '36–45岁' => 0, '46–55岁' => 0, '56岁以上' => 0];
        if ($members) {
            $endDate = (string)($range['end'] ?? date('Y-m-d'));
            $endTs = strtotime($endDate . ' 23:59:59') ?: time();
            foreach (Db::name('user')->whereIn('uid', array_keys($members))->field('uid,birthday')->select()->toArray() as $user) {
                $birthday = (int)($user['birthday'] ?? 0);
                if ($birthday <= 0) continue;
                $age = (int)date('Y', $endTs) - (int)date('Y', $birthday);
                if (date('md', $endTs) < date('md', $birthday)) $age--;
                if ($age < 18) continue;
                $bucket = $age <= 25 ? '18–25岁' : ($age <= 35 ? '26–35岁' : ($age <= 45 ? '36–45岁' : ($age <= 55 ? '46–55岁' : '56岁以上')));
                $ageCounts[$bucket]++;
            }
        }
        $ageTotal = array_sum($ageCounts);
        $age = [];
        foreach ($ageCounts as $label => $value) $age[] = ['label' => $label, 'value' => $value, 'percent' => $ageTotal > 0 ? round($value * 100 / $ageTotal, 1) : null];
        return ['trend' => $trend, 'age' => $age];
    }

    /** Build frequency and recency distributions from completed service facts. */
    private function visitVisuals(array $stores, array $range, array $input): array
    {
        $facts = (new StoreUnifiedReportPhaseFourServices())->customerServiceRows($stores, $range, (array)($input['_report_scope'] ?? []));
        $byMember = [];
        foreach ($facts as $fact) {
            $member = (int)($fact['member_id'] ?? 0);
            if ($member <= 0) continue;
            $byMember[$member][] = (string)($fact['business_date'] ?? '');
        }
        $frequency = ['1次' => 0, '2次' => 0, '3次' => 0, '4次' => 0, '5次以上' => 0];
        $recency = ['30天内到店' => 0, '31–60天' => 0, '61–90天' => 0, '91–180天' => 0, '180天以上' => 0];
        $end = strtotime((string)$range['end'] . ' 23:59:59') ?: time();
        foreach ($byMember as $dates) {
            $count = count($dates);
            $frequency[$count >= 5 ? '5次以上' : ($count . '次')]++;
            $latest = 0;
            foreach ($dates as $date) $latest = max($latest, strtotime($date . ' 23:59:59') ?: 0);
            $days = $latest > 0 ? max(0, (int)floor(($end - $latest) / 86400)) : 9999;
            $bucket = $days <= 30 ? '30天内到店' : ($days <= 60 ? '31–60天' : ($days <= 90 ? '61–90天' : ($days <= 180 ? '91–180天' : '180天以上')));
            $recency[$bucket]++;
        }
        $toRows = static function (array $values): array {
            $total = array_sum($values); $rows = [];
            foreach ($values as $label => $value) $rows[] = ['label' => $label, 'value' => $value, 'percent' => $total > 0 ? round($value * 100 / $total, 1) : null];
            return $rows;
        };
        return ['frequency' => $toRows($frequency), 'recency' => $toRows($recency)];
    }

    private function humanSources(string $report, array $columns, array $existing): array
    {
        $prefix = self::REPORTS[$report] . '：';
        $out = [];
        foreach ($columns as $column) {
            $key = (string)($column['key'] ?? '');
            if ($key === '') continue;
            $label = trim((string)($column['label'] ?? $key));
            $explicit = $this->explicitSource($report, $key);
            $explanation = trim((string)($explicit !== '' ? $explicit : ($column['source_explanation'] ?? ($existing[$key]['source_explanation'] ?? $existing[$key] ?? ''))));
            if ($explanation === '') $explanation = $label . '按当前权限范围和日期筛选后的有效业务记录统计。';
            if (!str_starts_with($explanation, $prefix)) $explanation = $prefix . $explanation;
            $out[] = ['key' => $key, 'label' => $label, 'source_explanation' => $explanation];
        }
        return $out;
    }

    private function explicitSource(string $report, string $key): string
    {
        $sources = [
            'customer_overview' => [
                'member_name' => '取客户在业务事实发生时保存的姓名快照。',
                'lifecycle_label' => '取客户生命周期记录中的阶段快照。',
                'conversion_history' => '取客户生命周期记录中是否曾进入待转换阶段的历史标记。',
                'is_guest' => '取客户生命周期记录中的嘉宾标记快照。',
                'first_course_source' => '取客户首次疗程完成时保存的来源名称快照。',
                'annual_30000' => '按选择自然年累计现金或消耗业绩是否达到3万元判断。',
                'customer_active' => '从客户生命周期记录统计期间完成过有效服务的去重会员人数。',
                'customer_effective' => '从客户生命周期和有效服务记录统计期间产生有效消费或服务的会员人数。',
                'customer_sleeping' => '从客户最近服务日期和生命周期状态统计超过设定周期未再到店的会员人数。',
                'cash_performance' => '按会员统计成功收款分摊事实的现金业绩。',
                'consume_performance' => '按会员统计有效消耗业绩事实。',
                'order_count' => '按统计期间有效销售事实关联的订单编号去重计数。',
                'sale_amount' => '按统计期间有效销售事实中的成交金额快照汇总。',
                'active' => '按期间完成有效服务的事实判断是否活客。',
                'effective' => '按统一客户有效标准和生命周期快照判断是否有效顾客。',
                'sleeping' => '按最近有效服务时间和页面选择的睡眠周期判断。',
                'last_service_at' => '取客户最近一次完成有效服务事实的业务时间。',
            ],
            'customer_source_analysis' => [
                'source_id' => '取首次疗程完成时保存的来源稳定编号，用于精确筛选和下钻。',
                'source_name' => '取会员首次完成疗程时保存的来源名称快照，不读取后来修改的会员资料。',
                'customer_count' => '按首次疗程来源快照统计期间新增客户的去重人数。',
                'cash_amount' => '按首次疗程来源归属的成功收款分摊事实统计净现金业绩，退款和作废按反向事实冲减。',
                'channel_name' => '取会员首次疗程卡成交时保存的来源名称快照。',
                'source_type' => '取首次疗程卡成交时保存的来源类型。',
                'member_count' => '按来源统计首次疗程卡成交顾客的去重人数。',
                'order_count' => '按来源统计首次疗程卡成交订单数。',
                'sale_amount' => '按首次疗程卡成交记录统计成交金额。',
                'receipt_amount' => '按首次疗程卡对应成功收款记录统计收款金额。',
                'cash_amount' => '按成功收款分摊事实净额统计，退款和作废按反向事实冲减。',
                'average_order_amount' => '成交金额除以首次疗程卡订单数，订单数为零显示“-”。',
            ],
            'customer_visit_analysis' => [
                'month' => '按完成服务日期归属自然月。',
                'active_members' => '从完成服务记录统计当前权限范围内的去重活跃会员。',
                'one_to_two_visits' => '统计期间完成1至2次有效服务的去重会员人数。',
                'one_to_two_visit_share' => '1至2次到店会员人数除以当期活客人数，分母为零显示“-”。',
                'three_plus_visits' => '统计期间完成3次及以上有效服务的去重会员人数。',
                'three_plus_visit_share' => '3次及以上到店会员人数除以当期活客人数，分母为零显示“-”。',
                'regular_customers' => '统计近90天内恰好完成1次有效服务的去重会员人数。',
                'inactive_customers' => '统计超过90天未完成有效服务、但历史有服务记录的去重会员人数。',
                'active_rate' => '活客人数除以活客人数与死客人数之和，分母为零显示“-”。',
            ],
            'customer_store_health' => [
                'month' => '按服务或收款业务日期归属自然月。',
                'store_count' => '按当前权限范围内有效门店统计。',
                'active_members' => '按门店和月份统计完成有效服务的去重会员人数。',
                'consuming_members' => '按门店和月份统计产生有效现金业绩的去重会员人数。',
                'consumption_rate' => '消费人数除以活客人数，分母为零显示“-”。',
                'post_sale_visits' => '按完成服务事实统计售后服务次数。',
                'store_average_visits' => '售后服务次数除以门店数，分母为零显示“-”。',
                'actual_staff' => '取平台保存的当月美容师人数手动值，未填写显示“-”。',
                'staff_unit_output' => '店均业绩除以美容师人数，分母为零显示“-”。',
                'monthly_service_visits' => '按报表约定公式计算月均服务人次。',
                'consumption_amount' => '按完成服务事实关联的有效消耗业绩统计。',
                'staff_consumption_output' => '生美消耗除以美容师人数，分母为零显示“-”。',
                'store_average_amount' => '净现金业绩除以门店数，分母为零显示“-”。',
            ],
            'customer_consumption_tier' => [
                'company_name' => '取会员截止统计日归属门店所属组织路径中的分公司名称。',
                'store_name' => '取会员截止统计日生效的归属门店名称。',
                'consumption_tier' => '按当前选择的现金业绩或消耗业绩，匹配系统启用的消费分级区间。',
                'accumulated_consumers' => '统计落入该消费分级的去重会员人数。',
                'category_consumption_amount' => '按当前选择的现金业绩或消耗业绩口径，汇总所选商品分类及下级分类金额；退款、作废按反向事实冲减。',
                'total_consumption_amount' => '按当前选择的现金业绩或消耗业绩口径，汇总该分级会员全部分类金额。',
                'people_share' => '该消费分级人数除以同一归属门店活客人数，分母为零显示“-”。',
                'category_consumption_share' => '分类金额除以该分级全部分类金额，分母为零显示“-”。',
            ],
            'customer_cash_performance' => [
                'company_name' => '按成功收款事实发生时保存的门店组织路径归属分公司。',
                'store_name' => '取成功收款事实保存的门店名称快照。',
                'new_customer_count' => '按首次疗程完成时保存的生命周期阶段统计去重新客人数。',
                'old_customer_count' => '按首次疗程完成时保存的生命周期阶段统计去重老客人数。',
                'cash_amount' => '成功收款分摊事实净额，退款和作废按反向事实冲减。',
                'conversion_rate' => '成交客户人数除以来源客户人数；来源客户分母尚未接入，显示“-”。',
                'average_amount' => '现金业绩除以该门店期间成交会员数，人数为零显示“-”。',
            ],
            'customer_refund_performance' => [
                'company_name' => '取退款成功操作发生时保存的组织名称。',
                'store_name' => '取退款成功操作发生时保存的门店名称。',
                'refund_people' => '退款成功操作关联会员的去重人数。',
                'refund_amount' => '按退款成功日期统计退款金额，未成功的退款申请不计入。',
                'refund_rate' => '退款金额除以同期成功收款金额；同期收款分母尚未接入，显示“-”。',
                'refund_items' => '取退款成功操作保存的退款项目名称快照。',
                'refund_date' => '取退款成功操作完成日期。',
            ],
            'customer_item_analysis' => [
                'item_id' => '取成交事实中的稳定品项编号，用于区分同名品项和精确下钻。',
                'item_name' => '取销售或卡项内含项目在成交时保存的品项名称快照。',
                'cash_amount' => '按成功收款分摊事实和卡项内含项目分摊事实统计净现金业绩，退款和作废按反向事实冲减。',
                'deal_people' => '按品项统计期间成交会员的去重人数。',
                'average_amount' => '品项现金业绩除以成交人数，人数为零显示“-”。',
                'people_share' => '品项成交人数除以全部品项成交人数。',
                'amount_share' => '品项现金业绩除以全部品项现金业绩。',
            ],
            'customer_unconsumed_analysis' => [
                'company_name' => '按当前门店所属组织架构匹配分公司；没有配置分公司时显示“-”。',
                'store_name' => '取当前有效卡项权益所属门店名称。',
                'product_id' => '取卡项权益明细中的项目稳定编号，用于精确筛选和下钻。',
                'item_name' => '取购买卡项时保存的项目名称快照；无法取得快照时显示“-”。',
                'unconsumed_amount' => '按当前有效卡项权益剩余次数乘购买时锁定的单次金额，退款、作废和已核销次数不计入；金额来源不完整时显示“-”。',
                'unconsumed_people' => '按当前有效卡项权益关联会员去重计数。',
                'remaining_count' => '取当前有效卡项权益的剩余可核销次数。',
                'unconsumed_rate' => '未耗金额除以同期有效收款金额；当前接口未提供可靠分母，显示“-”。',
                'unconsumed_yoy' => '与去年同期未耗金额比较；当前接口未提供历史权益快照，显示“-”。',
            ],
        ];
        return trim((string)($sources[$report][$key] ?? ''));
    }

    /**
     * First-course source cohort using lifecycle snapshots plus signed cash
     * allocation facts. The legacy channels reader summed raw sale/payment
     * rows and could not reverse refunds or voids, so it is deliberately not
     * used here.
     */
    private function sourceAnalysis(array $stores, array $range, array $input): array
    {
        $query = Db::name('cashier_v3_customer_lifecycle_projection')
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('store_id', $stores)
            ->whereBetween('first_course_business_date', [$range['start'], $range['end']])
            ->where('first_course_order_id', '<>', '');
        $sourceFilter = (int)($input['source_id'] ?? 0);
        if ($sourceFilter > 0) $query->where('first_course_source_primary_id', $sourceFilter);
        $cohort = $query->field('member_id,store_id,first_course_source_primary_id,first_course_source_label_snapshot,first_course_source_attribution_type_snapshot,first_course_order_id')->select()->toArray();
        $members = [];
        $groups = [];
        foreach ($cohort as $row) {
            $memberId = (int)($row['member_id'] ?? 0);
            $sourceId = (int)($row['first_course_source_primary_id'] ?? 0);
            if ($memberId <= 0 || $sourceId <= 0) continue;
            $members[$memberId] = $sourceId;
            if (!isset($groups[$sourceId])) $groups[$sourceId] = [
                'source_id' => $sourceId,
                'channel_name' => (string)($row['first_course_source_label_snapshot'] ?? '未分类来源'),
                'source_type' => (string)($row['first_course_source_attribution_type_snapshot'] ?? '-'),
                'members' => [], 'order_ids' => [], 'cash_cents' => 0,
            ];
            $groups[$sourceId]['members'][(string)$memberId] = true;
            $orderId = trim((string)($row['first_course_order_id'] ?? ''));
            if ($orderId !== '') $groups[$sourceId]['order_ids'][$orderId] = true;
        }
        if ($members !== []) {
            foreach ((new StoreUnifiedReportPhaseFourServices())->customerCashRows($stores, $range, (array)($input['_report_scope'] ?? [])) as $fact) {
                $memberId = (int)($fact['member_id'] ?? 0);
                $sourceId = (int)($members[$memberId] ?? 0);
                if ($sourceId <= 0 || !isset($groups[$sourceId])) continue;
                $groups[$sourceId]['cash_cents'] += (int)($fact['amount_cents'] ?? 0);
            }
        }
        $records = [];
        foreach ($groups as $group) {
            $records[] = [
                'source_id' => (string)$group['source_id'],
                'channel_name' => $group['channel_name'],
                'source_type' => $group['source_type'],
                'member_count' => count($group['members']),
                'order_count' => count($group['order_ids']),
                // Raw sale_amount/payment_amount cannot be safely reversed
                // without the signed allocation fact; expose signed cash as
                // receipt_amount and keep the unsupported gross sale amount
                // explicitly unknown instead of copying legacy totals.
                'sale_amount' => '-',
                'receipt_amount' => $this->moneyValue((int)$group['cash_cents']),
                'cash_amount' => $this->moneyValue((int)$group['cash_cents']),
                'average_order_amount' => '-',
            ];
        }
        usort($records, static fn(array $a, array $b): int => ((int)($b['member_count'] ?? 0) <=> (int)($a['member_count'] ?? 0)) ?: strcmp((string)$a['channel_name'], (string)$b['channel_name']));
        $result = $this->customerResult('客户开源分析', [
            ['key' => 'source_id', 'label' => '来源编号', 'source_explanation' => '取首次疗程完成时保存的来源稳定编号，用于精确筛选和下钻。'],
            ['key' => 'channel_name', 'label' => '首次疗程卡来源', 'source_explanation' => '取首次疗程完成时保存的来源名称快照。'],
            ['key' => 'source_type', 'label' => '来源类型', 'source_explanation' => '取首次疗程完成时保存的来源归因类型快照。'],
            ['key' => 'member_count', 'label' => '成交顾客', 'source_explanation' => '按首次疗程来源快照统计去重客户人数。', 'summable' => true, 'value_type' => 'integer'],
            ['key' => 'order_count', 'label' => '首次疗程卡单数', 'source_explanation' => '按首次疗程来源快照关联的订单编号去重计数。', 'summable' => true, 'value_type' => 'integer'],
            ['key' => 'sale_amount', 'label' => '成交金额', 'source_explanation' => '签名销售金额尚未有可完整回接退款/作废的统一分摊事实，暂显示“-”，不复制旧原始销售金额。'],
            ['key' => 'receipt_amount', 'label' => '净现金业绩', 'source_explanation' => '按成功收款分摊事实净额统计，退款和作废按反向事实冲减。', 'summable' => true, 'value_type' => 'money'],
            // cash_amount is a compatibility alias for older clients; only
            // receipt_amount participates in the summary to avoid counting
            // the same signed cash twice.
            ['key' => 'cash_amount', 'label' => '净现金业绩（兼容字段）', 'source_explanation' => '按成功收款分摊事实净额统计，退款和作废按反向事实冲减。'],
            ['key' => 'average_order_amount', 'label' => '成交单产', 'source_explanation' => '成交金额事实尚未完整回接退款/作废，暂不计算。'],
        ], $records, $input);
        $result['pending_metrics'] = array_values(array_unique(array_merge((array)($result['pending_metrics'] ?? []), ['成交金额', '成交单产'])));
        $result['aggregation_status'] = '来源客户与净现金业绩已按首次来源快照和签名收款事实统计；成交金额/成交单产因销售反向分摊事实未完整接入显示“-”。';
        return $result;
    }

    /** Cash performance grouped by the frozen organization and lifecycle stage. */
    private function cashPerformance(array $stores, array $range, array $input): array
    {
        $facts = (new StoreUnifiedReportPhaseFourServices())->customerCashRows($stores, $range, (array)($input['_report_scope'] ?? []));
        // Apply page filters to the same signed allocation facts used for the
        // amount.  Client-side filtering would make cards and exports disagree
        // with the selected source/item/category.
        $sourceFilter = (int)($input['source_id'] ?? 0);
        $itemFilter = trim((string)($input['item_id'] ?? ''));
        $categoryFilter = trim((string)($input['category_path'] ?? ''));
        $facts = array_values(array_filter($facts, static function (array $fact) use ($sourceFilter, $itemFilter, $categoryFilter): bool {
            if ($sourceFilter > 0 && (int)($fact['business_source_primary_id'] ?? 0) !== $sourceFilter) return false;
            if ($itemFilter !== '' && (string)($fact['item_id'] ?? '') !== $itemFilter) return false;
            if ($categoryFilter !== '') {
                $path = trim((string)($fact['category_path'] ?? ''));
                if ($path !== $categoryFilter && !str_starts_with($path, $categoryFilter . '/')) return false;
            }
            return true;
        }));
        $stages = $this->lifecycleStages(array_values(array_unique(array_filter(array_map('intval', array_column($facts, 'member_id'))))));
        $rows = [];
        $org = new StoreUnifiedReportOrganizationDimensionServices();
        foreach ($facts as $fact) {
            $projected = $fact;
            $org->project($projected, (string)($fact['organization_id'] ?? ''), (string)($fact['organization_path_snapshot'] ?? ''), (string)($fact['business_date'] ?? $range['end']));
            $company = (string)($projected['company'] ?? '未配置分公司');
            $storeId = (int)($fact['store_id'] ?? 0);
            $key = $company . '|' . $storeId;
            if (!isset($rows[$key])) $rows[$key] = [
                'company_name' => $company, 'store_name' => (string)($fact['store_name'] ?? ('门店' . $storeId)),
                'new_customer_count' => 0, 'old_customer_count' => 0, 'cash_cents' => 0,
                '_new' => [], '_old' => [], '_all' => [],
            ];
            $memberId = (int)($fact['member_id'] ?? 0);
            if ($memberId > 0) {
                $lifecycle = (array)($stages[$memberId] ?? []);
                $stage = strtolower((string)($lifecycle['lifecycle_stage'] ?? ''));
                // Classify against the first-course business date as it was
                // known when the cash fact occurred.  Reading only today's
                // projection stage would relabel historical new-customer
                // receipts after the customer later became post-sale.
                $firstCourseDate = trim((string)($lifecycle['first_course_business_date'] ?? ''));
                if ($firstCourseDate !== '' && (string)($fact['business_date'] ?? '') < $firstCourseDate) $stage = 'pre_sale';
                // 生命周期事实当前使用 pre_sale/post_sale/pending_conversion 阶段；
                // pre_sale 是首次疗程尚未完成的客户，仍应归入新客口径，
                // 避免因阶段枚举未同步而被误计入老客。
                $isNew = in_array($stage, ['new', 'new_customer', 'pre_sale', 'pending_conversion', 'guest'], true);
                $rows[$key]['_all'][(string)$memberId] = true;
                $rows[$key][$isNew ? '_new' : '_old'][(string)$memberId] = true;
            }
            $rows[$key]['cash_cents'] += (int)($fact['amount_cents'] ?? 0);
        }
        foreach ($rows as &$row) {
            $row['new_customer_count'] = count($row['_new']);
            $row['old_customer_count'] = count($row['_old']);
            $row['cash_amount'] = $this->moneyValue((int)$row['cash_cents']);
            $row['average_amount'] = count($row['_all']) > 0 ? $this->moneyValue(intdiv((int)$row['cash_cents'], count($row['_all']))) : '-';
            $row['conversion_rate'] = '-';
            unset($row['_new'], $row['_old'], $row['_all'], $row['cash_cents']);
        }
        unset($row);
        return $this->customerResult('现金业绩分析', [
            ['key' => 'company_name', 'label' => '分公司', 'source_explanation' => '按收款事实发生时保存的门店组织路径归属分公司。'],
            ['key' => 'store_name', 'label' => '门店', 'source_explanation' => '取成功收款事实保存的门店名称。'],
            ['key' => 'new_customer_count', 'label' => '新客人数', 'source_explanation' => '按首次疗程完成时保存的生命周期阶段统计去重新客人数。', 'summable' => true, 'value_type' => 'integer'],
            ['key' => 'old_customer_count', 'label' => '老客人数', 'source_explanation' => '按首次疗程完成时保存的生命周期阶段统计去重老客人数。', 'summable' => true, 'value_type' => 'integer'],
            ['key' => 'cash_amount', 'label' => '现金业绩', 'source_explanation' => '成功收款分摊事实净额，退款和作废按反向事实冲减。', 'summable' => true, 'value_type' => 'money'],
            ['key' => 'conversion_rate', 'label' => '成交率', 'source_explanation' => '来源客户分母未在本结果集中提供，显示“-”，不以金额代替比例。'],
            ['key' => 'average_amount', 'label' => '人均业绩', 'source_explanation' => '现金业绩除以该门店期间成交会员数，人数为零显示“-”。'],
        ], array_values($rows), $input);
    }

    /** Refund detail and company aggregate from successful refund operations. */
    private function refundPerformance(array $stores, array $range, array $input): array
    {
        $rows = (new StoreUnifiedReportPhaseTwoServices())->customerRefundRows($stores, $range, $input);
        $grouped = [];
        foreach ($rows as $row) {
            $company = (string)($row['market'] ?? '未配置分公司');
            $key = $company . '|' . (string)($row['store_name'] ?? '');
            if (!isset($grouped[$key])) $grouped[$key] = ['company_name' => $company, 'store_name' => (string)($row['store_name'] ?? '-'), 'refund_people' => [], 'refund_cents' => 0];
            $memberKey = trim((string)($row['member_id'] ?? $row['customer'] ?? ''));
            if ($memberKey !== '') $grouped[$key]['refund_people'][$memberKey] = true;
            $grouped[$key]['refund_cents'] += (int)($row['refund_amount_cents'] ?? 0);
        }
        $aggregateRecords = [];
        foreach ($grouped as $row) {
            $aggregateRecords[] = [
                'company_name' => $row['company_name'], 'store_name' => $row['store_name'],
                'refund_people' => count($row['refund_people']), 'refund_amount' => $this->moneyValue((int)$row['refund_cents']),
                'refund_rate' => '-',
            ];
        }
        // Detail rows stay in their own section. Mixing these with branch
        // aggregates causes page totals and exports to double count every
        // refund operation.
        $detailRecords = [];
        $refundCents = 0;
        $itemTotals = [];
        $managerTotals = [];
        $storeTotals = [];
        $orderIds = array_values(array_unique(array_filter(array_map(static fn(array $row): string => trim((string)($row['order_id'] ?? '')), $rows))));
        $managerByOrder = [];
        if ($orderIds !== []) {
            foreach (Db::name('cashier_v3_sales_manager_fact')->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)->whereIn('order_id', $orderIds)->where('status', 'effective')->field('order_id,sales_manager_name_snapshot')->select()->toArray() as $manager) {
                $name = trim((string)($manager['sales_manager_name_snapshot'] ?? ''));
                if ($name !== '') $managerByOrder[(string)$manager['order_id']] = $name;
            }
        }
        foreach ($rows as $row) {
            $refundCents += (int)($row['refund_amount_cents'] ?? 0);
            $amount = (int)($row['refund_amount_cents'] ?? 0);
            $storeName = trim((string)($row['store_name'] ?? '')) ?: '未配置门店';
            $storeTotals[$storeName] = (int)($storeTotals[$storeName] ?? 0) + $amount;
            $managerName = $managerByOrder[(string)($row['order_id'] ?? '')] ?? '未配置经理';
            $managerTotals[$managerName] = (int)($managerTotals[$managerName] ?? 0) + $amount;
            $itemNames = array_values(array_unique(array_filter(array_map('trim', preg_split('/[、,，\/]+/u', (string)($row['refund_items'] ?? '-'))), static fn(string $name): bool => $name !== '' && $name !== '-')));
            if ($itemNames !== []) {
                // A refund operation can contain several item snapshots. Split
                // its amount once across those items so the pie remains a true
                // 100% composition instead of counting the same refund once
                // per label.
                $itemShare = intdiv($amount, count($itemNames));
                $remainder = $amount - ($itemShare * count($itemNames));
                foreach ($itemNames as $index => $itemName) {
                    $itemAmount = $itemShare + ($index === 0 ? $remainder : 0);
                    $itemTotals[$itemName] = (int)($itemTotals[$itemName] ?? 0) + $itemAmount;
                }
            }
            $detailRecords[] = [
            'company_name' => (string)($row['market'] ?? '未配置分公司'), 'store_name' => (string)($row['store_name'] ?? '-'),
            'refund_people' => 1, 'refund_amount' => (string)($row['refund_amount'] ?? '-'), 'refund_rate' => '-',
            'refund_items' => (string)($row['refund_items'] ?? '-'), 'refund_date' => (string)($row['refund_date'] ?? '-'),
            ];
        }
        $columns = [
            ['key' => 'company_name', 'label' => '分公司', 'source_explanation' => '取退款成功操作发生时保存的组织名称。'],
            ['key' => 'store_name', 'label' => '门店', 'source_explanation' => '取退款成功操作发生时保存的门店名称。'],
            ['key' => 'refund_people', 'label' => '退款人数', 'source_explanation' => '退款成功操作关联会员人数；分公司汇总区按会员去重，明细区每笔成功退款计一笔。'],
            ['key' => 'refund_amount', 'label' => '退款金额', 'source_explanation' => '按退款成功日期统计退款金额，未成功的退款申请不计入。', 'summable' => true, 'value_type' => 'money'],
            ['key' => 'refund_rate', 'label' => '退款率', 'source_explanation' => '同期成功收款金额分母未在退款明细聚合中提供，显示“-”。'],
            ['key' => 'refund_items', 'label' => '退款项目', 'source_explanation' => '取退款成功操作保存的退款项目名称快照。'],
            ['key' => 'refund_date', 'label' => '退款日期', 'source_explanation' => '取退款成功操作完成日期。'],
        ];
        $aggregateColumns = array_values(array_filter($columns, static fn(array $column): bool => in_array((string)($column['key'] ?? ''), ['company_name', 'store_name', 'refund_people', 'refund_amount', 'refund_rate'], true)));
        $result = $this->customerResult('退货业绩分析', $columns, $detailRecords, $input);
        // Distinct refund people cannot be summed safely across branches;
        // expose the amount total and keep people/rate explicitly unknown.
        $result['summary_row'] = [
            'company_name' => '合计', 'store_name' => '-', 'refund_people' => '-',
            'refund_amount' => $this->moneyValue($refundCents), 'refund_rate' => '-',
            'refund_items' => '-', 'refund_date' => '-',
        ];
        $result['aggregate_records'] = array_values($aggregateRecords);
        $result['detail_records'] = array_values($detailRecords);
        $result['aggregate_columns'] = $aggregateColumns;
        arsort($itemTotals); arsort($managerTotals); arsort($storeTotals);
        $result['item_proportions'] = [];
        foreach ($itemTotals as $name => $amount) {
            $result['item_proportions'][] = ['item_name' => $name, 'refund_amount' => $this->moneyValue($amount), 'amount_share' => $refundCents > 0 ? round($amount * 100 / $refundCents, 1) : null];
        }
        $result['manager_records'] = [];
        foreach ($managerTotals as $name => $amount) $result['manager_records'][] = ['manager_name' => $name, 'refund_amount' => $this->moneyValue($amount)];
        $result['store_records'] = [];
        foreach ($storeTotals as $name => $amount) $result['store_records'][] = ['store_name' => $name, 'refund_amount' => $this->moneyValue($amount)];
        $result['sections'] = [
            ['key' => 'aggregates', 'label' => '分公司退款汇总', 'columns' => $aggregateColumns, 'records' => $aggregateRecords],
            ['key' => 'details', 'label' => '退款明细', 'columns' => $columns, 'records' => $detailRecords],
        ];
        return $result;
    }

    /** Cash item ranking; the same signed allocation facts are used for totals. */
    private function itemAnalysis(array $stores, array $range, array $input): array
    {
        $facts = (new StoreUnifiedReportPhaseFourServices())->customerCashRows($stores, $range, (array)($input['_report_scope'] ?? []));
        $items = [];
        $itemFilter = trim((string)($input['item_id'] ?? ''));
        $categoryFilter = trim((string)($input['category_path'] ?? ''));
        foreach ($facts as $fact) {
            if ($itemFilter !== '' && (string)($fact['item_id'] ?? '') !== $itemFilter) continue;
            if ($categoryFilter !== '') {
                $path = trim((string)($fact['category_path'] ?? ''));
                if ($path !== $categoryFilter && !str_starts_with($path, $categoryFilter . '/')) continue;
            }
            // Group by the stable item id. Display names are snapshots and
            // can collide or change, so using only the name would merge
            // distinct items and break exact drill-downs.
            $itemId = trim((string)($fact['item_id'] ?? ''));
            if ($itemId === '') $itemId = 'snapshot:' . trim((string)($fact['item_name'] ?? '未命名品项'));
            $name = trim((string)($fact['item_name'] ?? '')) ?: '未命名品项';
            if (!isset($items[$itemId])) $items[$itemId] = ['item_id' => $itemId, 'item_name' => $name, 'cash_cents' => 0, 'members' => [], 'consumption_cents' => 0, 'consumption_members' => []];
            $items[$itemId]['cash_cents'] += (int)($fact['amount_cents'] ?? 0);
            if ((int)($fact['member_id'] ?? 0) > 0) $items[$itemId]['members'][(string)$fact['member_id']] = true;
        }
        // Consumption ranking uses the completed service and recorded
        // consumption facts, not the cash allocation facts above. Keep the
        // same item/category filters and union consumption-only items so the
        // two rankings remain independently truthful.
        $serviceFacts = (new StoreUnifiedReportPhaseFourServices())->customerServiceRows($stores, $range, (array)($input['_report_scope'] ?? []));
        foreach ($serviceFacts as $fact) {
            if ($itemFilter !== '' && (string)($fact['project_id'] ?? '') !== $itemFilter) continue;
            if ($categoryFilter !== '') {
                $path = trim((string)($fact['category_path'] ?? ''));
                if ($path !== $categoryFilter && !str_starts_with($path, $categoryFilter . '/')) continue;
            }
            $itemId = trim((string)($fact['project_id'] ?? ''));
            $name = trim((string)($fact['project_name_snapshot'] ?? '')) ?: '未命名品项';
            if ($itemId === '') $itemId = 'snapshot:' . $name;
            if (!isset($items[$itemId])) $items[$itemId] = ['item_id' => $itemId, 'item_name' => $name, 'cash_cents' => 0, 'members' => [], 'consumption_cents' => 0, 'consumption_members' => []];
            $items[$itemId]['consumption_cents'] += (int)($fact['consumption_cents'] ?? 0);
            if ((int)($fact['member_id'] ?? 0) > 0) $items[$itemId]['consumption_members'][(string)$fact['member_id']] = true;
        }
        $totalCash = array_sum(array_map(static fn(array $r): int => (int)$r['cash_cents'], $items));
        $totalConsumption = array_sum(array_map(static fn(array $r): int => (int)$r['consumption_cents'], $items));
        // The denominator must use the same item/category-filtered facts as
        // the rows; all-facts membership would understate the share.
        $filteredMembers = [];
        foreach ($items as $item) foreach (array_keys((array)$item['members']) as $memberId) $filteredMembers[(string)$memberId] = true;
        $totalPeople = count($filteredMembers);
        $records = [];
        foreach ($items as $row) {
            $people = count($row['members']);
            $consumptionPeople = count($row['consumption_members']);
            $records[] = [
                'item_id' => $row['item_id'], 'item_name' => $row['item_name'], 'cash_amount' => $this->moneyValue((int)$row['cash_cents']),
                'deal_people' => $people, 'average_amount' => $people > 0 ? $this->moneyValue(intdiv((int)$row['cash_cents'], $people)) : '-',
                'people_share' => $totalPeople > 0 ? round($people * 100 / $totalPeople, 2) . '%' : '-',
                'amount_share' => $totalCash !== 0 ? round($row['cash_cents'] * 100 / $totalCash, 2) . '%' : '-',
                'consumption_amount' => $this->moneyValue((int)$row['consumption_cents']),
                'consumption_people' => $consumptionPeople,
                'consumption_average_amount' => $consumptionPeople > 0 ? $this->moneyValue(intdiv((int)$row['consumption_cents'], $consumptionPeople)) : '-',
                'consumption_share' => $totalConsumption !== 0 ? round($row['consumption_cents'] * 100 / $totalConsumption, 2) . '%' : '-',
                // Keep a numeric sort key until ranking is complete; sorting
                // the formatted money string would put 9 before 100.
                '_cash_cents' => (int)$row['cash_cents'],
                '_consumption_cents' => (int)$row['consumption_cents'],
            ];
        }
        usort($records, static fn(array $a, array $b): int => ((int)$b['_cash_cents'] <=> (int)$a['_cash_cents']) ?: strcmp((string)$a['item_name'], (string)$b['item_name']));
        $consumptionRecords = $records;
        usort($consumptionRecords, static fn(array $a, array $b): int => ((int)$b['_consumption_cents'] <=> (int)$a['_consumption_cents']) ?: strcmp((string)$a['item_name'], (string)$b['item_name']));
        foreach ($records as &$record) { unset($record['_cash_cents'], $record['_consumption_cents']); }
        unset($record);
        foreach ($consumptionRecords as &$record) { unset($record['_cash_cents'], $record['_consumption_cents']); }
        unset($record);
        $result = $this->customerResult('客户品相分析', [
            ['key' => 'item_id', 'label' => '品项编号', 'source_explanation' => '取成交事实中的稳定品项编号，用于区分同名品项和精确下钻。'],
            ['key' => 'item_name', 'label' => '品项名称', 'source_explanation' => '取成交或卡项内含项目在业务事实发生时保存的品项名称。'],
            ['key' => 'cash_amount', 'label' => '品项现金业绩', 'source_explanation' => '成功收款分摊事实净额，退款和作废按反向事实冲减。', 'summable' => true, 'value_type' => 'money'],
            ['key' => 'deal_people', 'label' => '成交人数', 'source_explanation' => '按品项统计期间成交会员去重人数。', 'summable' => true, 'value_type' => 'integer'],
            ['key' => 'average_amount', 'label' => '成交单产', 'source_explanation' => '品项现金业绩除以成交人数，人数为零显示“-”。'],
            ['key' => 'people_share', 'label' => '人头占比', 'source_explanation' => '品项成交人数除以全部品项成交人数。'],
            ['key' => 'amount_share', 'label' => '业绩占比', 'source_explanation' => '品项现金业绩除以全部品项现金业绩。'],
            ['key' => 'consumption_amount', 'label' => '消耗业绩', 'source_explanation' => '完成服务后形成的有效消耗业绩事实净额；作废按反向事实冲减。', 'summable' => true, 'value_type' => 'money'],
            ['key' => 'consumption_people', 'label' => '消耗人数', 'source_explanation' => '按品项统计期间完成有效服务的会员去重人数。', 'summable' => true, 'value_type' => 'integer'],
            ['key' => 'consumption_average_amount', 'label' => '消耗单价', 'source_explanation' => '消耗业绩除以消耗人数，人数为零显示“-”。'],
            ['key' => 'consumption_share', 'label' => '消耗占比', 'source_explanation' => '品项消耗业绩除以全部品项消耗业绩。'],
        ], $records, $input);
        $result['consumption_records'] = $consumptionRecords;
        return $result;
    }

    /**
     * Current unconsumed card entitlement balance.
     *
     * The legacy member report already reads user_card_holder plus the
     * remaining project rows in store_order_cart_info.  This reader keeps the
     * same fact source, but returns stable product/store rows so the customer
     * page can rank items and stores without rebuilding a second balance
     * calculation in Vue.  The date range is retained in the surrounding
     * report scope; the balance itself is a current-state snapshot as of
     * data_as_of because the legacy entitlement tables do not contain
     * historical balance versions.
     */
    private function unconsumedAnalysis(array $stores, array $range, array $input): array
    {
        $columns = [
            ['key' => 'company_name', 'label' => '分公司', 'source_explanation' => '按当前门店所属组织架构匹配分公司；没有配置分公司时显示“-”。'],
            ['key' => 'store_name', 'label' => '门店', 'source_explanation' => '取当前有效卡项权益所属门店名称。'],
            ['key' => 'product_id', 'label' => '品项编号', 'source_explanation' => '取卡项权益明细中的项目稳定编号，用于精确筛选和下钻。'],
            ['key' => 'item_name', 'label' => '未耗品项', 'source_explanation' => '取购买卡项时保存的项目名称快照；无法取得快照时显示“-”。'],
            ['key' => 'unconsumed_amount', 'label' => '未耗金额', 'source_explanation' => '按当前有效卡项权益剩余次数乘购买时锁定的单次金额；金额来源不完整时显示“-”。', 'summable' => true, 'value_type' => 'money'],
            ['key' => 'unconsumed_people', 'label' => '未耗人数', 'source_explanation' => '按当前有效卡项权益关联会员去重计数。', 'value_type' => 'integer'],
            ['key' => 'remaining_count', 'label' => '剩余次数', 'source_explanation' => '取当前有效卡项权益的剩余可核销次数。', 'summable' => true, 'value_type' => 'integer'],
            ['key' => 'unconsumed_rate', 'label' => '未耗率', 'source_explanation' => '未耗金额除以同期有效收款金额；可靠分母尚未接入，显示“-”。'],
            ['key' => 'unconsumed_yoy', 'label' => '未耗同比', 'source_explanation' => '与去年同期未耗金额比较；历史权益快照尚未接入，显示“-”。'],
        ];

        $empty = static function (array $columns, string $status, array $input): array {
            $result = (new self())->customerResult('客户未耗分析', $columns, [], $input);
            $result['summary_row'] = [];
            $result['aggregation_status'] = $status;
            $result['pending_metrics'] = ['未耗率', '未耗同比'];
            $result['unconsumed_snapshot'] = 'current_card_entitlement';
            $result['rankings'] = ['top_items' => [], 'top_stores' => [], 'company_amount' => []];
            return $result;
        };

        $scope = (array)($input['_report_scope'] ?? []);
        $participantMode = (string)($scope['mode'] ?? '');
        $participantEmployeeId = max(0, (int)($scope['employee_id'] ?? 0));
        if ($participantMode === 'self_participant' && $participantEmployeeId <= 0) {
            throw new \InvalidArgumentException('个人数据权限缺少有效员工身份');
        }
        if ($participantMode === 'self_participant' && !$this->hasColumn('store_order', 'order_id')) {
            // Failing closed is safer than returning all card balances to an
            // employee when the participant relation cannot be established.
            return $empty($columns, '当前门店卡项余额缺少员工参与关系，个人范围暂不返回数据。', $input);
        }

        try {
            $holderQuery = Db::name('user_card_holder')->alias('h')
                ->join('store_order o', 'o.id=h.oid')
                ->whereIn('h.store_id', $stores)
                ->where('h.is_del', 0)
                ->where('h.write_surplus_times', '>', 0)
                ->where('o.paid', 1)
                ->where('o.is_del', 0)
                ->where('o.is_system_del', 0)
                ->where('o.refund_status', 0);
            if ($this->hasColumn('store_order', 'terminal_action')) $holderQuery->where('o.terminal_action', 0);
            if ($this->hasColumn('store_order', 'card_upgrade_use_oid')) $holderQuery->where('o.card_upgrade_use_oid', 0);
            if ($participantMode === 'self_participant') {
                (new StoreReportParticipantScopeServices())->applyOrder($holderQuery, 'o.order_id', $participantEmployeeId);
            }
            $holders = $holderQuery
                ->field('h.uid,h.oid,h.store_id,h.write_surplus_times,h.write_start,h.write_end')
                ->select()->toArray();
        } catch (\Throwable $e) {
            return $empty($columns, '当前卡项权益事实暂不可读取，页面显示空态，不以0代替未知值。', $input);
        }
        if ($holders === []) return $empty($columns, '当前权限范围内没有剩余卡项权益。', $input);

        $oids = [];
        $holderByOid = [];
        $now = time();
        foreach ($holders as $holder) {
            $oid = (int)($holder['oid'] ?? 0);
            $uid = (int)($holder['uid'] ?? 0);
            if ($oid <= 0 || $uid <= 0) continue;
            $startsAt = max(0, (int)($holder['write_start'] ?? 0));
            $endsAt = max(0, (int)($holder['write_end'] ?? 0));
            if (($startsAt > 0 && $startsAt > $now) || ($endsAt > 0 && $endsAt < $now)) continue;
            $oids[$oid] = true;
            $holderByOid[$oid] = [
                'uid' => $uid,
                'store_id' => (int)($holder['store_id'] ?? 0),
                'remaining_count' => max(0, (int)($holder['write_surplus_times'] ?? 0)),
            ];
        }
        if ($oids === []) return $empty($columns, '当前权限范围内没有有效卡项权益。', $input);

        try {
            $cartQuery = Db::name('store_order_cart_info')
                ->whereIn('oid', array_keys($oids))
                ->where('write_surplus_times', '>', 0)
                ->where('cart_type', 2)
                ->where('product_type', 6);
            if ($this->hasColumn('store_order_cart_info', 'is_writeoff')) $cartQuery->where('is_writeoff', 0);
            $carts = $cartQuery->field('id,oid,product_id,pay_price,write_times,write_surplus_times,write_start,write_end')->select()->toArray();
        } catch (\Throwable $e) {
            return $empty($columns, '卡项项目权益明细暂不可读取，页面显示空态，不以0代替未知值。', $input);
        }

        $productIds = array_values(array_unique(array_filter(array_map(static fn(array $row): int => (int)($row['product_id'] ?? 0), $carts))));
        $products = [];
        if ($productIds !== []) {
            try {
                foreach (Db::name('store_product')->whereIn('id', $productIds)->where('is_del', 0)->field('id,store_name,cate_id')->select()->toArray() as $product) {
                    $products[(int)$product['id']] = [
                        'item_name' => trim((string)($product['store_name'] ?? '')),
                        'cate_id' => (string)($product['cate_id'] ?? ''),
                    ];
                }
            } catch (\Throwable $e) {
                // Product snapshots are optional enrichment.  Keep balances
                // and use '-' for the name if the catalogue is unavailable.
            }
        }

        // Build category paths only for the optional category filter.  A
        // missing catalogue must narrow to no rows rather than widen scope.
        $categoryFilter = trim((string)($input['category_path'] ?? ''));
        $categoryPaths = [];
        if ($categoryFilter !== '') {
            try {
                $categoryRows = Db::name('store_product_category')->where('is_show', 1)->field('id,pid,cate_name')->select()->toArray();
                $categoryMap = [];
                foreach ($categoryRows as $category) {
                    $categoryId = (int)($category['id'] ?? 0);
                    if ($categoryId <= 0) continue;
                    $categoryMap[$categoryId] = [
                        'pid' => (int)($category['pid'] ?? 0), 'name' => (string)($category['cate_name'] ?? ''),
                    ];
                }
                foreach ($categoryMap as $id => $category) {
                    $parts = []; $cursor = $id; $guard = 0;
                    while ($cursor > 0 && isset($categoryMap[$cursor]) && $guard++ < 32) {
                        array_unshift($parts, (string)$categoryMap[$cursor]['name']);
                        $cursor = (int)$categoryMap[$cursor]['pid'];
                    }
                    $categoryPaths[$id] = implode(' / ', array_filter($parts, static fn(string $name): bool => trim($name) !== ''));
                }
            } catch (\Throwable $e) {
                return $empty($columns, '商品分类事实暂不可读取，当前分类筛选返回空态。', $input);
            }
        }

        $itemFilter = trim((string)($input['item_id'] ?? ''));
        $groups = [];
        $cartRemainingByOid = [];
        $cartSeenByOid = [];
        $amountComplete = true;
        foreach ($carts as $cart) {
            $oid = (int)($cart['oid'] ?? 0);
            $holder = $holderByOid[$oid] ?? null;
            if ($holder === null) continue;
            $cartSeenByOid[$oid] = true;
            $productId = (int)($cart['product_id'] ?? 0);
            if ($itemFilter !== '' && (string)$productId !== $itemFilter) continue;
            $product = $products[$productId] ?? ['item_name' => '', 'cate_id' => ''];
            $paths = [];
            foreach (explode(',', (string)$product['cate_id']) as $categoryId) {
                $categoryId = (int)trim($categoryId);
                if ($categoryId > 0 && isset($categoryPaths[$categoryId])) $paths[] = $categoryPaths[$categoryId];
            }
            if ($categoryFilter !== '' && !array_filter($paths, static fn(string $path): bool => $path === $categoryFilter || str_starts_with($path, $categoryFilter . ' / '))) continue;
            $remaining = max(0, (int)($cart['write_surplus_times'] ?? 0));
            if ($remaining <= 0) continue;
            $startsAt = max(0, (int)($cart['write_start'] ?? 0));
            $endsAt = max(0, (int)($cart['write_end'] ?? 0));
            if (($startsAt > 0 && $startsAt > $now) || ($endsAt > 0 && $endsAt < $now)) continue;
            $cartRemainingByOid[$oid] = (int)($cartRemainingByOid[$oid] ?? 0) + $remaining;
            $times = (int)($cart['write_times'] ?? 0);
            $hasAmount = $times > 0 && array_key_exists('pay_price', $cart) && trim((string)$cart['pay_price']) !== '';
            $amountCents = $hasAmount ? intdiv($this->cents($cart['pay_price']) * $remaining, $times) : 0;
            if (!$hasAmount) $amountComplete = false;
            $storeId = (int)$holder['store_id'];
            $itemKey = $productId > 0 ? (string)$productId : 'unknown';
            $key = $storeId . '|' . $itemKey;
            if (!isset($groups[$key])) $groups[$key] = [
                'store_id' => $storeId, 'product_id' => $productId > 0 ? (string)$productId : '-',
                'item_name' => trim((string)$product['item_name']) !== '' ? trim((string)$product['item_name']) : '-',
                'amount_cents' => 0, 'amount_known' => true, 'members' => [], 'remaining_count' => 0,
            ];
            $groups[$key]['amount_cents'] += $amountCents;
            $groups[$key]['amount_known'] = $groups[$key]['amount_known'] && $hasAmount;
            $groups[$key]['members'][(string)$holder['uid']] = true;
            $groups[$key]['remaining_count'] += $remaining;
        }

        // A holder can still report remaining times even when its cart detail
        // has no usable project row.  Preserve those facts as an unknown item
        // row instead of silently changing a real balance to zero.
        foreach ($holderByOid as $oid => $holder) {
            $missing = max(0, (int)$holder['remaining_count'] - (int)($cartRemainingByOid[$oid] ?? 0));
            if ($missing <= 0) continue;
            if (isset($cartSeenByOid[$oid])) continue;
            if ($itemFilter !== '' || $categoryFilter !== '') continue;
            $key = (int)$holder['store_id'] . '|unknown';
            if (!isset($groups[$key])) $groups[$key] = [
                'store_id' => (int)$holder['store_id'], 'product_id' => '-', 'item_name' => '-',
                'amount_cents' => 0, 'amount_known' => false, 'members' => [], 'remaining_count' => 0,
            ];
            $groups[$key]['amount_known'] = false;
            $groups[$key]['members'][(string)$holder['uid']] = true;
            $groups[$key]['remaining_count'] += $missing;
            $amountComplete = false;
        }
        if ($groups === []) return $empty($columns, '当前筛选条件下没有剩余卡项权益。', $input);

        $storeNames = [];
        try { $storeNames = Db::name('system_store')->whereIn('id', $stores)->column('name', 'id'); } catch (\Throwable $e) { $storeNames = []; }
        $organization = new StoreUnifiedReportOrganizationDimensionServices();
        $records = [];
        foreach ($groups as $group) {
            $storeId = (int)$group['store_id'];
            try { $company = (string)$organization->resolve('company', '', '', (string)$range['end'], $storeId)['name']; } catch (\Throwable $e) { $company = '-'; }
            if ($company === '' || $company === '未配置分公司') $company = '-';
            $records[] = [
                'company_name' => $company,
                'store_name' => trim((string)($storeNames[$storeId] ?? '')) !== '' ? (string)$storeNames[$storeId] : '-',
                'product_id' => (string)$group['product_id'],
                'item_name' => (string)$group['item_name'],
                'unconsumed_amount' => !empty($group['amount_known']) ? $this->moneyValue((int)$group['amount_cents']) : '-',
                'unconsumed_people' => count($group['members']),
                'remaining_count' => (int)$group['remaining_count'],
                'unconsumed_count' => (int)$group['remaining_count'],
                'unconsumed_rate' => '-', 'unconsumed_yoy' => '-',
                '_amount_cents' => (int)$group['amount_cents'], '_amount_known' => !empty($group['amount_known']),
            ];
        }
        usort($records, static fn(array $a, array $b): int => ((int)$b['_amount_cents'] <=> (int)$a['_amount_cents']) ?: strcmp((string)$a['item_name'], (string)$b['item_name']));
        $itemRanks = []; $storeRanks = []; $companyRanks = [];
        foreach ($records as $record) {
            if ($record['_amount_known']) {
                $itemKey = (string)$record['product_id'];
                $storeKey = (string)$record['store_name'];
                $companyKey = (string)$record['company_name'];
                if (!isset($itemRanks[$itemKey])) $itemRanks[$itemKey] = ['name' => $record['item_name'], 'amount_cents' => 0, 'remaining_count' => 0];
                $itemRanks[$itemKey]['amount_cents'] += (int)$record['_amount_cents'];
                $itemRanks[$itemKey]['remaining_count'] += (int)$record['remaining_count'];
                if (!isset($storeRanks[$storeKey])) $storeRanks[$storeKey] = ['name' => $record['store_name'], 'amount_cents' => 0, 'remaining_count' => 0];
                $storeRanks[$storeKey]['amount_cents'] += (int)$record['_amount_cents'];
                $storeRanks[$storeKey]['remaining_count'] += (int)$record['remaining_count'];
                if (!isset($companyRanks[$companyKey])) $companyRanks[$companyKey] = ['name' => $record['company_name'], 'amount_cents' => 0, 'remaining_count' => 0];
                $companyRanks[$companyKey]['amount_cents'] += (int)$record['_amount_cents'];
                $companyRanks[$companyKey]['remaining_count'] += (int)$record['remaining_count'];
            }
        }
        $rankRows = function (array $rows): array {
            $rows = array_values($rows);
            usort($rows, static fn(array $a, array $b): int => (int)$b['amount_cents'] <=> (int)$a['amount_cents']);
            return array_map(function (array $row): array {
                $amount = $this->moneyValue((int)$row['amount_cents']);
                return [
                    'name' => (string)$row['name'], 'unconsumed_amount' => $amount,
                    'unconsumed_count' => (int)$row['remaining_count'],
                    'remaining_count' => (int)$row['remaining_count'],
                ];
            }, $rows);
        };
        $result = $this->customerResult('客户未耗分析', $columns, array_map(static function (array $record): array { unset($record['_amount_cents'], $record['_amount_known']); return $record; }, $records), $input);
        $allPeople = [];
        $summaryCents = 0; $summaryAmountKnown = true; $summaryCount = 0;
        foreach ($records as $record) { $summaryCents += (int)$record['_amount_cents']; $summaryAmountKnown = $summaryAmountKnown && !empty($record['_amount_known']); $summaryCount += (int)$record['remaining_count']; }
        // Distinct people are reconstructed from the grouped rows below; the
        // report deliberately does not sum people across item rows.
        foreach ($groups as $group) foreach (array_keys($group['members']) as $memberId) $allPeople[(string)$memberId] = true;
        $result['summary_row'] = [
            'company_name' => '合计', 'store_name' => '-', 'product_id' => '-', 'item_name' => '-',
            'unconsumed_amount' => $summaryAmountKnown ? $this->moneyValue($summaryCents) : '-',
            'unconsumed_people' => count($allPeople), 'remaining_count' => $summaryCount,
            'unconsumed_count' => $summaryCount,
            'unconsumed_rate' => '-', 'unconsumed_yoy' => '-',
        ];
        $result['pending_metrics'] = ['未耗率', '未耗同比'];
        $result['unconsumed_snapshot'] = 'current_card_entitlement';
        $itemRankRows = $rankRows($itemRanks);
        $topItems = array_slice($itemRankRows, 0, 6);
        $topItemsTen = array_slice($itemRankRows, 0, 10);
        $topStores = array_slice($rankRows($storeRanks), 0, 10);
        $companyAmount = array_slice($rankRows($companyRanks), 0, 10);
        $result['rankings'] = ['top_items' => $topItems, 'top_items_top10' => $topItemsTen, 'top_stores' => $topStores, 'company_amount' => $companyAmount];
        // These aliases are part of the page contract: the frontend can use
        // the same amounts/counts for the chart and the table without doing a
        // second aggregation or interpreting cents itself.
        $result['items'] = array_map(static fn(array $row): array => ['item_name' => $row['name'], 'unconsumed_amount' => $row['unconsumed_amount'], 'unconsumed_count' => $row['unconsumed_count']], $topItemsTen);
        $result['stores'] = array_map(static fn(array $row): array => ['store_name' => $row['name'], 'unconsumed_amount' => $row['unconsumed_amount'], 'unconsumed_count' => $row['unconsumed_count']], $topStores);
        $result['branches'] = array_map(static fn(array $row): array => ['company_name' => $row['name'], 'unconsumed_amount' => $row['unconsumed_amount'], 'unconsumed_count' => $row['unconsumed_count']], $companyAmount);
        $result['aggregation_status'] = $amountComplete && $summaryAmountKnown
            ? '当前有效卡项权益和项目剩余次数已读取；未耗率、未耗同比因缺少同期分母和历史余额快照显示“-”。'
            : '当前有效卡项权益已读取；部分项目金额缺少可追溯购买金额，未知金额显示“-”；未耗率、未耗同比显示“-”。';
        return $result;
    }

    private function lifecycleStages(array $memberIds): array
    {
        if ($memberIds === []) return [];
        try {
            $rows = Db::name('cashier_v3_customer_lifecycle_projection')
                ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)->whereIn('member_id', $memberIds)
                ->field('member_id,lifecycle_stage,first_course_business_date')->select()->toArray();
        } catch (\Throwable $e) { return []; }
        $out = [];
        foreach ($rows as $row) $out[(int)$row['member_id']] = [
            'lifecycle_stage' => (string)($row['lifecycle_stage'] ?? ''),
            'first_course_business_date' => (string)($row['first_course_business_date'] ?? ''),
        ];
        return $out;
    }

    /**
     * Check optional legacy columns before adding compatibility predicates.
     * The customer report is deployed across instances with slightly
     * different card schemas, so this must fail closed without making the
     * whole report query fatal when an optional column is absent.
     */
    private function hasColumn(string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) return (bool)$cache[$key];
        try {
            $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
            $safeColumn = addslashes($column);
            $cache[$key] = Db::query("SHOW COLUMNS FROM `eb_{$safeTable}` LIKE '{$safeColumn}'") !== [];
        } catch (\Throwable $e) {
            $cache[$key] = false;
        }
        return (bool)$cache[$key];
    }

    private function customerResult(string $title, array $columns, array $records, array $input): array
    {
        $page = max(1, (int)($input['page'] ?? 1));
        $limit = min(100, max(10, (int)($input['limit'] ?? 20)));
        $all = !empty($input['_internal_all']);
        $total = count($records);
        $visible = $all ? array_values($records) : array_slice(array_values($records), ($page - 1) * $limit, $limit);
        $summary = [];
        foreach ($columns as $column) $summary[(string)($column['key'] ?? '')] = '-';
        if ($columns !== []) $summary[(string)($columns[0]['key'] ?? '')] = '合计';
        foreach ($columns as $column) {
            if (empty($column['summable'])) continue;
            $key = (string)($column['key'] ?? '');
            if ($key === '') continue;
            $sum = 0; $has = false;
            foreach ($records as $record) {
                $value = $record[$key] ?? null;
                if ($value === null || $value === '' || $value === '-') continue;
                if (preg_match('/^-?\d+(?:\.\d{1,2})?$/', (string)$value)) {
                    $raw = (string)$value;
                    $negative = str_starts_with($raw, '-');
                    $parts = explode('.', ltrim($raw, '+-'), 2);
                    $cents = ((int)($parts[0] ?? 0) * 100) + (int)str_pad(substr((string)($parts[1] ?? ''), 0, 2), 2, '0');
                    $sum += $negative ? -$cents : $cents;
                    $has = true;
                }
            }
            $summary[$key] = !$has ? '-' : ((string)($column['value_type'] ?? 'money') === 'integer' ? (string)intdiv($sum, 100) : $this->moneyValue($sum));
        }
        // Refund rows intentionally contain both branch aggregates and source
        // details for the existing page contract; summing both would double
        // count, so the page keeps the explicit detail/aggregate rows without
        // fabricating a misleading total.
        if ($title === '退货业绩分析') $summary = [];
        return ['title' => $title, 'columns' => $columns, 'records' => $visible, 'total' => $total,
            'page' => $page, 'page_size' => $limit, 'summary_row' => $summary,
            'metric_version' => self::METRIC_VERSION . ':signed-facts', 'data_as_of' => date('Y-m-d H:i:s'),
            'aggregation_caught_up' => true, 'aggregation_status' => '已读取到当前统一事实数据。',
            'drilldown_keys' => ['report', 'start_date', 'end_date', 'org_id', 'store_id', 'store_ids', 'metric_code'],
            'table_layout' => ['fixed' => true, 'sticky_header' => true, 'sticky_summary' => true, 'result_scroll' => true]];
    }

    private function moneyValue(int $cents): string
    {
        $negative = $cents < 0; $cents = abs($cents);
        $value = intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
        return ($negative ? '-' : '') . rtrim(rtrim($value, '0'), '.');
    }

    /** Convert a stored decimal yuan value to integer cents. */
    private function cents($money): int
    {
        $value = trim((string)$money);
        if ($value === '') return 0;
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        $parts = explode('.', $value, 2);
        $whole = (int)($parts[0] ?? 0);
        $fraction = str_pad(substr((string)($parts[1] ?? ''), 0, 2), 2, '0');
        $cents = ($whole * 100) + (int)$fraction;
        return $negative ? -abs($cents) : $cents;
    }

    private function pendingResult(string $report): array
    {
        $columns = [
            'customer_cash_performance' => [
                ['key' => 'company_name', 'label' => '分公司', 'source_explanation' => '客户现金业绩分析：取客户现金业绩发生时保存的分公司名称。'],
                ['key' => 'new_customer_count', 'label' => '新客人数', 'source_explanation' => '客户现金业绩分析：按首次疗程来源快照判定的新客去重人数。'],
                ['key' => 'old_customer_count', 'label' => '老客人数', 'source_explanation' => '客户现金业绩分析：按首次疗程来源快照判定的老客去重人数。'],
                ['key' => 'cash_amount', 'label' => '现金业绩', 'source_explanation' => '客户现金业绩分析：成功收款分摊事实的净现金业绩。'],
                ['key' => 'conversion_rate', 'label' => '转化率', 'source_explanation' => '客户现金业绩分析：成交客户人数除以来源客户人数，分母为零显示“-”。'],
                ['key' => 'average_amount', 'label' => '人均业绩', 'source_explanation' => '客户现金业绩分析：现金业绩除以成交客户人数，分母为零显示“-”。'],
            ],
            'customer_refund_performance' => [
                ['key' => 'company_name', 'label' => '分公司', 'source_explanation' => '退货业绩分析：取退款成功操作发生时保存的分公司名称。'],
                ['key' => 'refund_people', 'label' => '退款人数', 'source_explanation' => '退货业绩分析：退款成功事实中的去重会员人数。'],
                ['key' => 'refund_amount', 'label' => '退款金额', 'source_explanation' => '退货业绩分析：按退款成功时间统计退款反向事实金额。'],
                ['key' => 'refund_rate', 'label' => '退款率', 'source_explanation' => '退货业绩分析：退款金额除以同期成功收款金额，分母为零显示“-”。'],
            ],
            'customer_item_analysis' => [
                ['key' => 'item_name', 'label' => '品项', 'source_explanation' => '客户品相分析：取成交时保存的品项名称快照。'],
                ['key' => 'cash_amount', 'label' => '品项现金业绩', 'source_explanation' => '客户品相分析：成功收款分摊事实和卡项内含项目分摊事实的净额。'],
                ['key' => 'deal_people', 'label' => '成交人数', 'source_explanation' => '客户品相分析：按品项统计成交会员去重人数。'],
                ['key' => 'average_amount', 'label' => '成交单产', 'source_explanation' => '客户品相分析：品项现金业绩除以成交人数，分母为零显示“-”。'],
                ['key' => 'people_share', 'label' => '人头占比', 'source_explanation' => '客户品相分析：品项成交人数除以全部品项成交人数。'],
                ['key' => 'amount_share', 'label' => '业绩占比', 'source_explanation' => '客户品相分析：品项现金业绩除以全部品项现金业绩。'],
            ],
        ][$report] ?? [];
        return ['columns' => $columns, 'records' => [], 'total' => 0, 'summary_row' => []];
    }

}
