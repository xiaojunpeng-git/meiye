<?php
namespace app\services\ai\presentation;

use app\services\query\metric\MetricMoneyFormatter;
use app\services\query\metric\MetricReadViewServices;
use RuntimeException;

/** Converts verified Reader evidence into the stable customer answer shape. */
final class AiAnswerRenderer
{
    public function render(array $view): array
    {
        $dictionary = new \app\services\metric\MetricDictionaryServices();
        $cards = []; $rows = []; $facts = []; $shape = $view['query']['query_shape'];
        $threshold = $shape === 'threshold_count' ? $this->thresholdCondition($view['query']) : null;
        foreach ($view['results'] as $row) {
            $registered = MetricReadViewServices::metricCapabilities();
            if (!isset($registered[$row['metric_code'] ?? '']) || !$registered[$row['metric_code']]['ai_query_ready'] || !in_array($row['period'] ?? '', ['current', 'comparison'], true)) {
                throw new RuntimeException('AI_EVIDENCE_INVALID');
            }
            $tooltip = $dictionary->getTooltip($row['metric_code']);
            if (empty($tooltip['user_ready'])) throw new RuntimeException('AI_METRIC_EXPLANATION_NOT_READY');
            $storageUnit = (string)($row['storage_unit'] ?? 'fen'); $unit = $storageUnit === 'count' ? '个' : '元';
            $range = $row['period'] === 'current' ? ['start' => $view['query']['start_date'], 'end' => $view['query']['end_date']] : $view['query']['compare_range'];
            if ($shape === 'threshold_count') {
                if ($storageUnit !== 'count' || ($row['object_kind'] ?? null) !== 'member'
                    || !self::same($row['aggregate_condition'] ?? null, $threshold)
                    || !is_int($row['count'] ?? null) || $row['count'] < 0 || !is_array($range)) {
                    throw new RuntimeException('AI_EVIDENCE_INVALID');
                }
                $facts[$row['metric_code']][$row['period']] = ['name' => $tooltip['name'], 'count' => $row['count']];
                $cards[] = ['metric_name' => '达标会员数', 'display_value' => (string)$row['count'], 'unit' => '人', 'tooltip' => $tooltip,
                    'period_label' => ($row['period'] === 'current' ? '查询期间：' : '对比期间：') . $range['start'] . ' 至 ' . $range['end'],
                    'start_date' => $range['start'], 'end_date' => $range['end'], 'data_as_of' => $view['data_as_of']];
                continue;
            }
            if ($shape === 'trend') {
                foreach ($row['rows'] as $point) $rows[] = ['label' => $point['business_date'], 'metric' => $tooltip['name'], 'value' => $this->metricValue($point['amount_cents'], $storageUnit), 'unit' => $unit];
                continue;
            }
            if ($shape === 'ranking') {
                $metricLabel = $tooltip['name'];
                if (($row['participant_relation'] ?? false) === true && is_string($row['object_label'] ?? null)) $metricLabel = $row['object_label'] . '关联订单' . $tooltip['name'];
                foreach ($row['rows'] as $direction => $points) foreach ($points as $index => $point) $rows[] = [
                    'label' => $point['employee_name'] ?? ($point['member_name'] ?? ($point['entity_name'] ?? ($point['store_name'] ?? ('门店 ID ' . $point['store_id'])))),
                    'metric' => $metricLabel, 'rank' => ($direction === 'top' ? '前' : '后') . ($index + 1), 'value' => $this->metricValue($point['amount_cents'], $storageUnit), 'unit' => $unit,
                ];
                continue;
            }
            $display = $this->metricValue($storageUnit === 'count' ? ($row['count'] ?? null) : ($row['amount_cents'] ?? null), $storageUnit);
            $facts[$row['metric_code']][$row['period']] = ['name' => $tooltip['name'], 'value' => $display, 'unit' => $unit];
            $cards[] = ['metric_name' => $tooltip['name'], 'display_value' => $display, 'unit' => $unit, 'tooltip' => $tooltip,
                'period_label' => ($row['period'] === 'current' ? '查询期间：' : '对比期间：') . $range['start'] . ' 至 ' . $range['end'],
                'start_date' => $range['start'], 'end_date' => $range['end'], 'data_as_of' => $view['data_as_of']];
        }
        $summary = $shape === 'threshold_count' ? $this->thresholdSummary($facts, $threshold) : $this->resultSummary($shape, $facts, $rows);
        $summary .= ($summary === '' ? '' : ' ') . '统计时间：' . $view['query']['start_date'] . ' 至 ' . $view['query']['end_date'] . '。';
        $answer = ['summary' => $summary, 'cards' => $cards];
        $queryMetrics = is_array($view['query']['metric_codes'] ?? null) ? $view['query']['metric_codes'] : [];
        if ($shape === 'summary' && count($queryMetrics) > 1) {
            $names = [];
            foreach ($queryMetrics as $code) {
                $name = $dictionary->getTooltip($code)['name'] ?? null;
                if (is_string($name) && $name !== '' && !in_array($name, $names, true)) $names[] = $name;
            }
            if ($names) $answer['summary'] .= ' 我先从' . implode('、', $names) . '这些已登记经营事实查看本次情况；您可以继续说明想进一步了解的业务方向、对象或时间。';
        }
        $objectKind = $view['query']['business_filters']['object_kind'] ?? 'store'; $person = $objectKind === 'person'; $member = $objectKind === 'member'; $dimensionLabel = null;
        if (!$person && !$member && $shape === 'ranking') foreach ($view['results'] as $result) {
            if (($result['object_kind'] ?? null) === $objectKind && is_string($result['object_label'] ?? null)) { $dimensionLabel = $result['object_label']; break; }
        }
        if ($person) {
            $answer['summary'] .= ' 人员范围：' . $view['personnel_selection_label'] . '（按当前任职筛选）。';
            $criteria = []; foreach ($view['query']['metric_codes'] as $code) $criteria[] = $dictionary->getTooltip($code)['name'];
            $answer['summary'] .= ' 评价指标：' . implode('、', $criteria) . '。';
            if ($shape === 'ranking') $answer['summary'] .= $rows ? '仅按所选指标排序，不代表综合评价；相同金额按稳定人员顺序展示。' : '本期间没有符合条件的人员业绩事实，不能据此评定谁表现最好。';
        }
        if ($member && $shape === 'ranking') {
            $criteria = []; foreach ($view['query']['metric_codes'] as $code) $criteria[] = $dictionary->getTooltip($code)['name'];
            $answer['summary'] .= ' 会员评价指标：' . implode('、', $criteria) . '。';
            if ($shape === 'ranking') $answer['summary'] .= $rows ? '仅按所选指标排序；相同数值按稳定会员顺序展示。' : '本期间没有符合条件的会员数据。';
        }
        if ($dimensionLabel !== null) {
            $criteria = []; foreach ($view['query']['metric_codes'] as $code) $criteria[] = $dictionary->getTooltip($code)['name'];
            $answer['summary'] .= $dimensionLabel . '评价指标：' . implode('、', $criteria) . '。';
            if ($shape === 'ranking') $answer['summary'] .= $rows ? '仅按所选指标排序；相同数值按稳定' . $dimensionLabel . '顺序展示。' : '本期间没有符合条件的' . $dimensionLabel . '数据。';
        }
        if ($view['query']['compare_range']) $answer['summary'] .= '对比时间：' . $view['query']['compare_range']['start'] . ' 至 ' . $view['query']['compare_range']['end'] . '。';
        if ($rows) {
            $columns = [['key' => 'label', 'label' => $shape === 'trend' ? '日期' : ($person ? '人员' : ($member ? '会员' : ($dimensionLabel ?? '门店')))], ['key' => 'metric', 'label' => '指标'], ['key' => 'value', 'label' => '数值'], ['key' => 'unit', 'label' => '单位']];
            if ($shape === 'ranking') $columns[] = ['key' => 'rank', 'label' => '名次'];
            $answer['table'] = ['columns' => $columns, 'rows' => $rows];
        }
        return $answer;
    }

    /** Builds the first visible sentence only from the Reader evidence already on screen. */
    private function resultSummary(string $shape, array $facts, array $rows): string
    {
        if ($shape === 'summary' && $facts) {
            $parts = [];
            foreach ($facts as $periods) {
                $current = $periods['current'] ?? null;
                if (!is_array($current)) continue;
                $text = $current['name'] . '为' . $current['value'] . $current['unit'];
                $comparison = $periods['comparison'] ?? null;
                if (is_array($comparison)) $text .= '；对比期间为' . $comparison['value'] . $comparison['unit'];
                $parts[] = $text;
            }
            return $parts ? implode('。', $parts) . '。' : '';
        }
        if ($shape === 'ranking' && $rows) {
            $first = $rows[0];
            // A short ranking is commonly a continuation such as “第二名
            // 呢？”.  State every already verified row instead of leading
            // with the first one and making the customer scan a table for the
            // requested result.  This is deliberately based only on the
            // generic read-view rows: no metric, object or Chinese phrase is
            // special-cased.  Longer rankings remain table-first to keep the
            // answer readable on mobile.
            if (count($rows) > 1 && count($rows) <= 3) {
                return implode('；', array_map(static function (array $row): string {
                    return ($row['rank'] ?? '首位') . '是' . $row['label'] . '，'
                        . $row['metric'] . '为' . $row['value'] . $row['unit'];
                }, $rows)) . '。';
            }
            return ($first['rank'] ?? '首位') . '是' . $first['label'] . '，' . $first['metric'] . '为' . $first['value'] . $first['unit'] . '。';
        }
        if ($shape === 'trend' && $rows) {
            $last = $rows[count($rows) - 1];
            return $last['label'] . '的' . $last['metric'] . '为' . $last['value'] . $last['unit'] . '。';
        }
        return '已按您当前报表的数据范围查询。';
    }

    /** The condition is already compiler-verified; this only turns it into customer wording. */
    private function thresholdSummary(array $facts, array $condition): string
    {
        $parts = [];
        foreach ($facts as $periods) {
            $current = $periods['current'] ?? null;
            if (!is_array($current) || !is_int($current['count'] ?? null)) continue;
            $operator = ['gte' => '达到', 'gt' => '超过', 'lte' => '不超过', 'lt' => '低于', 'eq' => '等于'][$condition['operator']];
            $amount = MetricMoneyFormatter::integerYuan($condition['amount_cents']);
            $parts[] = '累计' . $current['name'] . $operator . $amount . '元的会员共有' . $current['count'] . '人。';
        }
        return $parts ? implode('', $parts) : '已按您当前报表的数据范围查询。';
    }

    /** @return array{subject:string,aggregation:string,operator:string,amount_cents:int} */
    private function thresholdCondition(array $query): array
    {
        $condition = $query['aggregate_condition'] ?? null;
        $keys = is_array($condition) ? array_keys($condition) : []; sort($keys, SORT_STRING);
        if ($keys !== ['aggregation', 'amount_cents', 'operator', 'subject']
            || ($condition['subject'] ?? null) !== 'member' || ($condition['aggregation'] ?? null) !== 'period_total'
            || !in_array($condition['operator'] ?? null, ['gte', 'gt', 'lte', 'lt', 'eq'], true)
            || !is_int($condition['amount_cents'] ?? null) || $condition['amount_cents'] < 1) {
            throw new RuntimeException('AI_EVIDENCE_INVALID');
        }
        return $condition;
    }

    private static function same($left, $right): bool
    {
        if (!is_array($left) || !is_array($right) || count($left) !== count($right)) return false;
        ksort($left); ksort($right);
        return $left === $right;
    }

    private function metricValue($value, string $storageUnit): string
    {
        if ($storageUnit === 'fen') return MetricMoneyFormatter::integerYuan($value);
        if ($storageUnit === 'count' && is_int($value)) return (string)$value;
        throw new RuntimeException('AI_EVIDENCE_VALUE_INVALID');
    }
}
