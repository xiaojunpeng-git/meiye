<?php
namespace app\services\ai\execution;

use app\services\ai\contract\AiContractException;

/** Registered workflows and compiler live together; the model cannot supply graph edges. */
final class AiWorkflowPlanner
{
    private $names = ['cash_performance' => '现金业绩', 'consume_amount' => '消耗业绩'];

    public function compile(array $projection, array $selection, array $capabilities, string $format, string $today): array
    {
        if (!empty($projection['unresolved_condition']) || $selection['decision'] === 'unsupported') throw new AiContractException('AI_UNSUPPORTED_CONDITION');
        if (!in_array($format,['screen','screen_and_xlsx'],true)) throw new AiContractException('AI_OUTPUT_FORMAT_INVALID');
        $signals = $projection['signals'];
        $format=($format==='screen_and_xlsx' || in_array('xlsx',$signals,true))?'screen_and_xlsx':'screen';
        if ($format==='screen_and_xlsx' && !in_array($format,$capabilities['output_formats']??[],true)) throw new AiContractException('AI_EXPORT_NOT_READY');
        if (in_array('current_store',$signals,true) && empty($capabilities['current_store_bound'])) throw new AiContractException('AI_UNSUPPORTED_CONDITION');
        $metrics = array_values(array_intersect(array_keys($this->names), $signals));
        if (in_array('actual_performance', $signals, true)) throw new AiContractException('AI_METRIC_NOT_READY');
        foreach ($metrics as $metric) if (!in_array($metric, $capabilities['metric_codes'], true)) throw new AiContractException('AI_METRIC_NOT_READY');
        $shapes=array_values(array_intersect(['summary','trend','ranking','comparison'],$signals));
        if (in_array('top_5', $signals, true) || in_array('bottom_5', $signals, true)) $shapes[]='ranking';
        $shapes=array_values(array_unique($shapes));
        if (count($shapes)>1) throw new AiContractException('AI_UNSUPPORTED_CONDITION');
        $shape=$shapes[0]??'summary';
        if (!in_array($shape, $capabilities['query_shapes'], true)) throw new AiContractException('AI_QUERY_SHAPE_NOT_READY');
        if ($selection['query_shape'] !== $shape) throw new AiContractException('AI_MODEL_SELECTION_MISMATCH');
        $selected = $selection['metric_codes']; sort($selected); $expected = $metrics; sort($expected);
        if ($metrics && $selected !== $expected) throw new AiContractException('AI_MODEL_SELECTION_MISMATCH');
        $dateCode = null;
        foreach (['TODAY', 'YESTERDAY', 'THIS_MONTH', 'LAST_MONTH'] as $code) if (in_array($code, $signals, true)) {
            if ($dateCode !== null) throw new AiContractException('AI_UNSUPPORTED_CONDITION');
            $dateCode = $code;
        }
        $range = null;
        if ($projection['dates']) {
            if ($dateCode !== null || count($projection['dates']) > 2) throw new AiContractException('AI_UNSUPPORTED_CONDITION');
            $range = $this->range($projection['dates'][0], $projection['dates'][1] ?? $projection['dates'][0]);
        } elseif ($dateCode) {
            $date = $this->date($today);
            if ($dateCode === 'YESTERDAY') $date = $date->modify('-1 day');
            if ($dateCode === 'LAST_MONTH') $date = $date->modify('first day of last month');
            $range = $this->range($dateCode === 'THIS_MONTH' || $dateCode === 'LAST_MONTH' ? $date->format('Y-m-01') : $date->format('Y-m-d'),
                $dateCode === 'LAST_MONTH' ? $date->format('Y-m-t') : $date->format('Y-m-d'));
        }
        $ranking=null;
        if ($shape==='ranking' && (in_array('top_5',$signals,true)||in_array('bottom_5',$signals,true))) {
            $ranking=['direction'=>in_array('top_5',$signals,true)?(in_array('bottom_5',$signals,true)?'top_and_bottom':'top'):'bottom','limit'=>5];
        }
        if (!$metrics || !$range || in_array('ambiguous_metric', $signals, true) || $shape==='comparison' || ($shape==='ranking' && !$ranking)) {
            $fields = [];
            if (!$metrics || in_array('ambiguous_metric', $signals, true)) {
                $options = []; foreach ($capabilities['metric_codes'] as $metric) if (isset($this->names[$metric])) $options[] = ['value' => $metric, 'label' => $this->names[$metric]];
                if (!$options) throw new AiContractException('AI_METRIC_NOT_READY');
                $fields[] = ['key' => 'metric_code', 'label' => '业绩指标', 'type' => 'select', 'options' => $options];
            }
            if (!$range) { $fields[] = ['key' => 'start_date', 'label' => '开始日期', 'type' => 'date']; $fields[] = ['key' => 'end_date', 'label' => '结束日期', 'type' => 'date']; }
            if ($shape==='comparison') { $fields[]=['key'=>'compare_start','label'=>'对比开始日期','type'=>'date']; $fields[]=['key'=>'compare_end','label'=>'对比结束日期','type'=>'date']; }
            if ($shape==='ranking' && !$ranking) $fields[]=['key'=>'rank_direction','label'=>'排行方式','type'=>'select','options'=>[['value'=>'top','label'=>'前五家'],['value'=>'bottom','label'=>'后五家'],['value'=>'top_and_bottom','label'=>'前五及后五家']]];
            return ['kind' => 'clarification', 'fields' => $fields, 'resolved_metrics' => $metrics, 'resolved_range' => $range, 'query_shape' => $shape,'ranking'=>$ranking,'output_format'=>$format];
        }
        return $this->plan($metrics, $range, $shape,null,$ranking,$format);
    }

    public function choose(array $envelope, array $choices): array
    {
        $required = array_column($envelope['fields'], 'key'); $actual = array_keys($choices); sort($required); sort($actual);
        if ($required !== $actual) throw new AiContractException('AI_CLARIFICATION_INVALID');
        $metrics = $envelope['resolved_metrics']; $range = $envelope['resolved_range'];
        foreach ($envelope['fields'] as $field) if ($field['key'] === 'metric_code') {
            if (!is_string($choices['metric_code']) || !in_array($choices['metric_code'], array_column($field['options'], 'value'), true)) throw new AiContractException('AI_CLARIFICATION_INVALID');
            $metrics = [$choices['metric_code']];
        }
        if (!$range) $range = $this->range($choices['start_date'] ?? null, $choices['end_date'] ?? null);
        $compare=$envelope['query_shape']==='comparison'?$this->range($choices['compare_start']??null,$choices['compare_end']??null):null;
        $ranking=$envelope['ranking']??null;
        if ($envelope['query_shape']==='ranking' && !$ranking) {
            if (!in_array($choices['rank_direction']??null,['top','bottom','top_and_bottom'],true)) throw new AiContractException('AI_CLARIFICATION_INVALID');
            $ranking=['direction'=>$choices['rank_direction'],'limit'=>5];
        }
        return $this->plan($metrics, $range, $envelope['query_shape'],$compare,$ranking,$envelope['output_format']??'screen');
    }

    private function plan(array $metrics, array $range, string $shape,?array $compare=null,?array $ranking=null,string $format='screen'): array
    {
        if (!in_array($shape,['summary','trend','ranking','comparison'],true)) throw new AiContractException('AI_QUERY_SHAPE_NOT_READY');
        $plan = ['schema_version' => 'mohe-executable-workflow-v1', 'workflow_code' => 'wf_performance_snapshot', 'workflow_version' => 1,
            'query' => ['query_shape' => $shape, 'metric_codes' => $metrics, 'start_date' => $range['start'], 'end_date' => $range['end'],
                'compare_range' => $compare, 'store_ids' => [], 'business_filters' => [],'ranking'=>$ranking],
            'nodes' => ['query_metric_summary', 'all_evidence_guard', 'deterministic_answer'],
            'max_visits' => 3, 'max_tool_calls' => 1, 'output_format' => $format];
        $plan['compiled_run_hash'] = hash('sha256', json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return ['kind' => 'plan', 'plan' => $plan];
    }
    private function range($start, $end): array
    {
        $first = $this->date($start); $last = $this->date($end);
        if ($start > $end || (int)$first->diff($last)->format('%a') > 365) throw new AiContractException('AI_DATE_INVALID');
        return ['start' => $start, 'end' => $end];
    }
    private function date($text): \DateTimeImmutable
    {
        if (!is_string($text) || !preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $text)) throw new AiContractException('AI_DATE_INVALID');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text, new \DateTimeZone('Asia/Shanghai'));
        if (!$date || $date->format('Y-m-d') !== $text) throw new AiContractException('AI_DATE_INVALID');
        return $date;
    }
}
