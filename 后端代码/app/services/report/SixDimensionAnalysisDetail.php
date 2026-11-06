<?php

declare(strict_types=1);

namespace app\services\report;

use think\facade\Db;

/**
 * 只接收六维汇总循环已经选中的贡献记录，不重新决定见诊、达标或金额口径。
 * 人数沿用母表去重键，金额保留正负分值；汇总行按各门店行累计，不跨行去重。
 */
final class SixDimensionAnalysisDetail
{
    private const COMPONENTS = [
        'new_visit_people' => ['新客见诊人数', '人'], 'old_visit_people' => ['老客见诊人数', '人'],
        'new_deal_people' => ['新客成交人数', '人次'], 'old_deal_people' => ['老客成交人数', '人次'],
        'new_deal_performance' => ['新客成交业绩', '元'], 'old_deal_performance' => ['老客成交业绩', '元'],
        'refund_amount' => ['退款金额', '元'], 'debt' => ['历史总欠款', '元'],
        'self_received_performance' => ['自营实收业绩', '元'], 'partner_received_performance' => ['合作六维实收业绩', '元'],
    ];
    private const COMPOSITE = [
        'total_visit_people' => ['new_visit_people', 'old_visit_people'],
        'total_deal_people' => ['new_deal_people', 'old_deal_people'],
        'total_deal_performance' => ['new_deal_performance', 'old_deal_performance'],
        'self_to_date_performance' => ['self_received_performance'],
        'all_received_performance' => ['self_received_performance', 'partner_received_performance'],
        'new_deal_rate' => ['new_deal_people', 'new_visit_people'],
        'old_deal_rate' => ['old_deal_people', 'old_visit_people'],
        'new_unit_output' => ['new_deal_performance', 'new_deal_people'],
        'old_unit_output' => ['old_deal_performance', 'old_deal_people'],
    ];
    private string $metric;
    private array $components;
    private array $records = [];

    /** 空指标为普通汇总，完全不收集明细；拒绝客户端任意字段作为指标。 */
    public function __construct(string $metric)
    {
        if ($metric !== '' && !isset(self::COMPONENTS[$metric]) && !isset(self::COMPOSITE[$metric])) {
            throw new \InvalidArgumentException('不支持的六维分析明细指标');
        }
        $this->metric = $metric;
        $this->components = self::COMPOSITE[$metric] ?? ($metric === '' ? [] : [$metric]);
    }

    public function enabled(): bool { return $this->metric !== ''; }

    /** 行键绑定母表实际分公司/门店分组；查询时仍须与授权后完整行集相交。 */
    public static function rowKey(string $group): string { return hash('sha256', 'six_dimension_analysis|' . $group); }

    /**
     * amount 是整数分或人数。去重键仅用于计数，不可用来合并收退款金额。
     * 多笔服务同计一人时保留全部来源单号，方便追溯且不重复贡献人数。
     */
    public function add(string $group, string $component, array $fact, int $amount, string $dedupe = ''): void
    {
        if (!in_array($component, $this->components, true)) return;
        $key = $group . '|' . $component . '|' . ($dedupe !== '' ? $dedupe : '#' . count($this->records));
        if (!isset($this->records[$key])) {
            $this->records[$key] = [
                '_group' => self::rowKey($group), '_component' => $component, '_amount' => $amount,
                'member_id' => (int)($fact['member_id'] ?? 0),
                'member_name' => (string)($fact['member_name'] ?? $fact['member_name_snapshot'] ?? ''),
                'component' => self::COMPONENTS[$component][0], 'value_unit' => self::COMPONENTS[$component][1],
                'business_date' => '', 'order_no' => '', 'item_name' => '', 'business_source_label' => '',
            ];
        }
        $document = '';
        foreach (['document_no_snapshot', 'order_no_snapshot', 'service_record_no', 'order_no', 'sales_order_no'] as $field) {
            if (trim((string)($fact[$field] ?? '')) !== '') { $document = (string)$fact[$field]; break; }
        }
        $values = [
            'business_date' => (string)($fact['business_date'] ?? ''),
            'order_no' => $document,
            'item_name' => (string)($fact['item_name'] ?? $fact['project_name_snapshot'] ?? $fact['item_name_snapshot'] ?? ''),
            'business_source_label' => (string)($fact['business_source_label'] ?? $fact['business_source_label_snapshot'] ?? $fact['business_source_primary_name_snapshot'] ?? ''),
        ];
        foreach ($values as $field => $value) {
            $parts = array_filter(explode('、', $this->records[$key][$field]));
            if ($value !== '') $parts[] = $value;
            $parts = array_values(array_unique($parts)); sort($parts, SORT_STRING);
            $this->records[$key][$field] = implode('、', $parts);
        }
    }

    /**
     * 先重放母表结果筛选及稳定行键，再筛明细、合计、分页。客户端不能先缩小
     * 历史读取范围影响新老客判定，也不能用门店名称或合计文字绕过授权。
     */
    public function result(array $parents, array $parentColumns, array $range, array $input): array
    {
        $parentColumns = StoreReportResultRowFilterServices::declareFixedDimensions($parentColumns);
        $parents = StoreReportResultRowFilterServices::filter($parents, $parentColumns, (string)($input['row_filters'] ?? ''));
        $requested = (string)($input['detail_store_key'] ?? '');
        if ($requested === '') throw new \InvalidArgumentException('缺少六维分析明细范围');
        $byKey = array_column($parents, null, 'detail_store_key');
        if ($requested !== 'all' && !isset($byKey[$requested])) {
            throw new \InvalidArgumentException('所选明细不在当前查询范围内，请刷新报表');
        }
        $selected = [];
        foreach ($this->records as $record) {
            $key = $record['_group'];
            if (!isset($byKey[$key]) || ($requested !== 'all' && $requested !== $key)) continue;
            $record['company_name'] = $byKey[$key]['company_name'];
            $record['store_name'] = $byKey[$key]['store_name'];
            $selected[] = $record;
        }
        $selected = StoreUnifiedReportDetailFilter::apply($selected, $input);
        usort($selected, static fn(array $a, array $b): int =>
            [$a['business_date'], $a['_group'], $a['member_id'], $a['_component'], $a['order_no'], $a['item_name'], $a['_amount']]
            <=> [$b['business_date'], $b['_group'], $b['member_id'], $b['_component'], $b['order_no'], $b['item_name'], $b['_amount']]);
        $totals = array_fill_keys($this->components, 0);
        foreach ($selected as $record) $totals[$record['_component']] += $record['_amount'];
        $ratio = str_ends_with($this->metric, '_rate') || str_ends_with($this->metric, '_output');
        $money = !$ratio && self::COMPONENTS[$this->components[0]][1] === '元';
        $column = array_column($parentColumns, null, 'key')[$this->metric];
        $label = implode(' · ', array_unique($column['header_path'] ?? [$column['label']]));
        $sum = array_sum($totals);
        $value = $money ? self::money($sum) : $sum;
        $display = $money ? (string)round($sum / 100) : (string)$sum;
        $formula = '';
        if ($ratio) {
            [$numerator, $denominator] = array_values($totals);
            $percent = str_ends_with($this->metric, '_rate');
            $value = $denominator <= 0 ? '-' : ($percent
                ? rtrim(rtrim(number_format($numerator * 100 / $denominator, 2, '.', ''), '0'), '.') . '%'
                : rtrim(rtrim(number_format($numerator / $denominator / 100, 2, '.', ''), '0'), '.'));
            $display = $percent || $value === '-' ? $value : (string)round((float)$value);
            $formula = '（' . self::COMPONENTS[$this->components[0]][0] . ' '
                . ($percent ? $numerator : (string)round($numerator / 100)) . ' ÷ '
                . self::COMPONENTS[$this->components[1]][0] . ' ' . $denominator . '）';
        }
        $limit = min(100, max(10, (int)($input['limit'] ?? 20)));
        $page = min(max(1, (int)($input['page'] ?? 1)), max(1, (int)ceil(count($selected) / $limit)));
        $visible = !empty($input['_internal_all']) ? $selected : array_slice($selected, ($page - 1) * $limit, $limit);
        // 只为授权明细当前页补会员联系方式，不扫描其他会员或改变姓名快照。
        $ids = array_values(array_unique(array_filter(array_column($visible, 'member_id'))));
        $profiles = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $profiles += Db::name('user')->whereIn('uid', $chunk)->column('phone', 'uid');
        }
        foreach ($visible as &$record) {
            $record['member_phone'] = (string)($profiles[$record['member_id']] ?? '');
            $record['detail_value'] = $record['value_unit'] === '元' ? self::money($record['_amount']) : $record['_amount'];
            unset($record['_group'], $record['_component'], $record['_amount'], $record['member_id']);
        }
        unset($record);
        $columns = [];
        foreach (['company_name' => '分公司', 'store_name' => '门店', 'member_name' => '会员', 'member_phone' => '手机号',
            'business_date' => '业务日期', 'order_no' => '来源单号', 'item_name' => '项目/商品',
            'business_source_label' => '业务来源', 'component' => '统计项', 'detail_value' => '计入数值', 'value_unit' => '单位'] as $key => $name) {
            $columns[] = ['key' => $key, 'label' => $name, 'width' => in_array($key, ['order_no', 'business_date', 'item_name'], true) ? 180 : 140,
                'value_type' => $key === 'detail_value' ? ($money ? 'money' : ($ratio ? 'number' : 'integer')) : 'text',
                'summable' => !$ratio && $key === 'detail_value',
                'source_explanation' => $key === 'member_phone' ? '取明细会员当前登记手机号。' : $column['source_explanation']];
        }
        $summary = array_fill_keys(array_column($columns, 'key'), '-');
        $summary['company_name'] = '合计';
        // 比率明细同时含分子、分母，不能把不同单位简单相加；导出同时保留公式。
        $summary['detail_value'] = $ratio ? '-' : $value;
        if ($ratio) $summary['component'] = $label . '：' . $value . $formula;
        return ['title' => $label . '明细', 'columns' => $columns, 'records' => $visible,
            'total' => count($selected), 'page' => $page, 'page_size' => $limit, 'summary_row' => $summary,
            'metric_value' => $value, 'detail_footer' => '共 ' . count($selected) . ' 条 · ' . $label . '：' . $display . $formula,
            'scope' => ['date_range' => $range], 'metric_version' => 'six-dimension-analysis-refund-display-v7',
            'data_as_of' => date('Y-m-d H:i:s'), 'aggregation_status' => 'reconciled'];
    }

    /** 金额导出仍保留分精度，界面统一整数元展示。 */
    private static function money(int $cents): string
    {
        return ($cents < 0 ? '-' : '') . intdiv(abs($cents), 100) . '.'
            . str_pad((string)(abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }
}
