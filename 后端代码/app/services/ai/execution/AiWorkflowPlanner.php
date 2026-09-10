<?php
namespace app\services\ai\execution;

use app\services\ai\contract\AiContractException;

/** Registered workflows and compiler live together; the model cannot supply graph edges. */
final class AiWorkflowPlanner
{
    private function names(array $codes=[]): array
    {
        return \app\services\query\metric\MetricSemanticCatalog::names($codes);
    }

    public function compile(array $projection, array $selection, array $capabilities, string $format, string $today): array
    {
        if (!empty($projection['blocking_reason'])) throw new AiContractException($projection['blocking_reason']);
        if (!empty($projection['unresolved_condition'])) throw new AiContractException('AI_INTENT_UNRESOLVED');
        if ($selection['decision'] === 'unsupported') throw new AiContractException('AI_UNSUPPORTED_CONDITION');
        if (!in_array($format,['screen','screen_and_xlsx'],true)) throw new AiContractException('AI_OUTPUT_FORMAT_INVALID');
        $signals = $projection['signals'];
        $format=($format==='screen_and_xlsx' || in_array('xlsx',$signals,true))?'screen_and_xlsx':'screen';
        if ($format==='screen_and_xlsx' && !in_array($format,$capabilities['output_formats']??[],true)) throw new AiContractException('AI_EXPORT_NOT_READY');
        if (in_array('current_store',$signals,true) && empty($capabilities['current_store_bound'])) throw new AiContractException('AI_UNSUPPORTED_CONDITION');
        $names=$this->names();
        $metrics = array_values(array_intersect(array_keys($names), $signals));
        $definition=in_array('definition',$signals,true);
        $available=$definition?($capabilities['definition_metric_codes']??[]):$capabilities['metric_codes'];
        foreach ($metrics as $metric) if (!in_array($metric, $available, true)) throw new AiContractException('AI_METRIC_NOT_READY');
        $shapes=array_values(array_intersect(['summary','trend','ranking','comparison'],$signals));
        if (in_array('top_5', $signals, true) || in_array('bottom_5', $signals, true)) $shapes[]='ranking';
        $shapes=array_values(array_unique($shapes));
        if (in_array('comparison',$shapes,true) && count($projection['date_terms']??[])===2) $shapes=array_values(array_diff($shapes,['summary']));
        if (count($shapes)>1) throw new AiContractException('AI_UNSUPPORTED_CONDITION');
        $shape=$definition?'definition':($shapes[0]??'summary');
        if ($definition && ($shapes || $projection['date_terms'] || $format!=='screen')) throw new AiContractException('AI_UNSUPPORTED_CONDITION');
        if (!$definition && !in_array($shape, $capabilities['query_shapes'], true)) throw new AiContractException('AI_QUERY_SHAPE_NOT_READY');
        if (!$definition && $selection['query_shape'] !== $shape) throw new AiContractException('AI_MODEL_SELECTION_MISMATCH');
        $selected = $selection['metric_codes']; sort($selected); $expected = $metrics; sort($expected);
        if ($metrics && !in_array('ambiguous_metric',$signals,true) && $selected !== $expected) throw new AiContractException('AI_MODEL_SELECTION_MISMATCH');
        $range = null; $compare = null;
        if ($shape === 'comparison') {
            // Signals are a vocabulary set, not user order. Only the locally
            // projected ordered periods can bind primary vs comparison.
            $terms = $projection['date_terms'] ?? [];
            if (count($terms) <= 2 && empty($projection['date_grouping_ambiguous'])) {
                if (isset($terms[0])) $range = $this->period($terms[0], $today);
                if (isset($terms[1])) $compare = $this->period($terms[1], $today);
            }
            // More than two ungrouped periods is ambiguous: request both
            // ranges together instead of dropping one or choosing endpoints.
        } elseif (array_key_exists('date_terms',$projection)) {
            if (count($projection['date_terms'])===1 && empty($projection['date_grouping_ambiguous'])) $range=$this->period($projection['date_terms'][0],$today);
        } elseif (!empty($projection['date_terms'])) {
            if (count($projection['date_terms'])!==1 || !empty($projection['date_grouping_ambiguous'])) throw new AiContractException('AI_UNSUPPORTED_CONDITION');
            $range=$this->period($projection['date_terms'][0],$today);
        } else {
        $dateCode = null;
        foreach (['TODAY', 'YESTERDAY', 'THIS_MONTH', 'LAST_MONTH'] as $code) if (in_array($code, $signals, true)) {
            if ($dateCode !== null) throw new AiContractException('AI_UNSUPPORTED_CONDITION');
            $dateCode = $code;
        }
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
        }
        $ranking=null;
        if ($shape==='ranking' && (in_array('top_5',$signals,true)||in_array('bottom_5',$signals,true))) {
            $ranking=['direction'=>in_array('top_5',$signals,true)?(in_array('bottom_5',$signals,true)?'top_and_bottom':'top'):'bottom','limit'=>5];
        }
        if ($shape==='ranking' && !$ranking) {
            $direction=in_array('rank_top',$signals,true)?(in_array('rank_bottom',$signals,true)?'top_and_bottom':'top'):(in_array('rank_bottom',$signals,true)?'bottom':null);
            $ranking=['direction'=>$direction,'limit'=>$projection['semantic_intent']['rank_limit']??null];
        }
        if (!$metrics || (!$definition && !$range) || in_array('ambiguous_metric', $signals, true) || ($shape==='comparison' && !$compare) || ($shape==='ranking' && (!$ranking['direction'] || !$ranking['limit']))) {
            $fields = [];
            if (!$metrics || in_array('ambiguous_metric', $signals, true)) {
                $options = []; foreach ($available as $metric) if (isset($names[$metric])) $options[] = ['value' => $metric, 'label' => \app\services\query\metric\MetricSemanticCatalog::optionLabel($metric)];
                if (!$options) throw new AiContractException('AI_METRIC_NOT_READY');
                if(in_array('service_metric_ambiguity',$signals,true)) {
                    $options=array_values(array_filter($options,static function($option){return $option['value']==='consume_amount';}));
                    if(!$options) throw new AiContractException('AI_CAPABILITY_NOT_READY');
                    foreach (['staff_labor_yeji','balance_deduction_amount'] as $metric) if (isset($names[$metric]) && in_array($metric,$available,true)) $options[]=['value'=>$metric,'label'=>\app\services\query\metric\MetricSemanticCatalog::optionLabel($metric)];
                    $options[]=['value'=>'other_service_meaning','label'=>'以上都不是','action'=>'stop'];
                }
                if(in_array('income_ambiguity',$signals,true)) {
                    $options=array_values(array_filter($options,static function($option){return $option['value']==='cash_performance';}));
                    $options[]=['value'=>'other_income_meaning','label'=>'财务收入（暂未接入）','action'=>'stop'];
                }
                if(in_array('sales_ambiguity',$signals,true)) {
                    $options=array_values(array_filter($options,static function($option){return $option['value']==='cash_performance';}));
                    foreach (['sales_amount','completed_service_item_count'] as $metric) if (isset($names[$metric]) && in_array($metric,$available,true)) $options[]=['value'=>$metric,'label'=>\app\services\query\metric\MetricSemanticCatalog::optionLabel($metric)];
                    $options[]=['value'=>'other_sales_meaning','label'=>'以上都不是','action'=>'stop'];
                }
                $fields[] = ['key' => 'metric_code', 'label' => '想了解哪一种业绩？', 'type' => 'select', 'options' => $options];
            }
            if (!$definition && !$range) { $fields[] = ['key' => 'start_date', 'label' => '开始日期', 'type' => 'date']; $fields[] = ['key' => 'end_date', 'label' => '结束日期', 'type' => 'date']; }
            if ($shape==='comparison' && !$compare) { $fields[]=['key'=>'compare_start','label'=>'对比开始日期','type'=>'date']; $fields[]=['key'=>'compare_end','label'=>'对比结束日期','type'=>'date']; }
            if ($shape==='ranking' && !$ranking['direction']) $fields[]=['key'=>'rank_direction','label'=>'想看表现较高还是较低的门店？','type'=>'select','options'=>[['value'=>'top','label'=>'业绩较高'],['value'=>'bottom','label'=>'业绩较低'],['value'=>'top_and_bottom','label'=>'高低都看']]];
            if ($shape==='ranking' && !$ranking['limit']) $fields[]=['key'=>'rank_limit','label'=>'当前已支持前后五家，是否查询五家？','type'=>'select','options'=>[['value'=>'5','label'=>'查询五家'],['value'=>'other','label'=>'需要其他数量（暂未接入）','action'=>'stop']]];
            return $this->step(['kind' => 'clarification', 'fields' => $fields, 'resolved_metrics' => $metrics, 'resolved_range' => $range, 'resolved_compare_range' => $compare, 'query_shape' => $shape,'ranking'=>$ranking,'output_format'=>$format,'semantic_constraints'=>$projection['semantic_intent']??[],'requested_period_terms'=>$projection['date_terms']??[]]);
        }
        return $this->plan($metrics, $range, $shape,$compare,$ranking,$format);
    }

    public function choose(array $envelope, array $choices): array
    {
        $required = array_column($envelope['fields'], 'key'); $actual = array_keys($choices); sort($required); sort($actual);
        if ($required !== $actual) throw new AiContractException('AI_CLARIFICATION_INVALID');
        $metrics = $envelope['resolved_metrics']; $range = $envelope['resolved_range'];
        foreach ($envelope['fields'] as $field) if ($field['key'] === 'metric_code') {
            if (!is_string($choices['metric_code']) || !in_array($choices['metric_code'], array_column($field['options'], 'value'), true)) throw new AiContractException('AI_CLARIFICATION_INVALID');
            if(in_array($choices['metric_code'],['other_service_meaning','other_income_meaning','other_sales_meaning'],true)) throw new AiContractException('AI_CAPABILITY_NOT_READY');
            $metrics = array_values(array_unique(array_merge($metrics,[$choices['metric_code']])));
        }
        if (array_key_exists('start_date',$choices)) $range = $this->range($choices['start_date'], $choices['end_date'] ?? null);
        $compare=$envelope['resolved_compare_range']??null;
        if(array_key_exists('compare_start',$choices)) $compare=$this->range($choices['compare_start'],$choices['compare_end']??null);
        $ranking=$envelope['ranking']??null;
        if (array_key_exists('rank_direction',$choices)) {
            if (!in_array($choices['rank_direction']??null,['top','bottom','top_and_bottom'],true)) throw new AiContractException('AI_CLARIFICATION_INVALID');
            $ranking['direction']=$choices['rank_direction'];
        }
        if(array_key_exists('rank_limit',$choices)) {
            if($choices['rank_limit']==='other') throw new AiContractException('AI_RANK_LIMIT_NOT_READY');
            if($choices['rank_limit']!=='5') throw new AiContractException('AI_CLARIFICATION_INVALID');
            $ranking['limit']=5;
        }
        if(!empty($envelope['pending_fields'])) {
            $envelope['fields']=$envelope['pending_fields'];unset($envelope['pending_fields']);
            $envelope['resolved_metrics']=$metrics;$envelope['resolved_range']=$range;$envelope['resolved_compare_range']=$compare;$envelope['ranking']=$ranking;
            return $this->step($envelope);
        }
        return $this->plan($metrics, $range, $envelope['query_shape'],$compare,$ranking,$envelope['output_format']??'screen');
    }

    /** One semantic question; range endpoints are a single controlled input. */
    private function step(array $envelope): array
    {
        $all=$envelope['fields'];$first=array_shift($all);$fields=[$first];
        if(in_array($first['key'],['start_date','compare_start'],true)) $fields[]=array_shift($all);
        $envelope['schema_version']='mohe-guidance-state-v2';
        $envelope['fields']=$fields;$envelope['pending_fields']=$all;$envelope['guidance_step']=$first['key'];
        $questions=['metric_code'=>'您想了解哪一种业绩？','start_date'=>'您想查询哪个时间段？','compare_start'=>'您想和哪个时间段比较？','rank_direction'=>'您想看业绩较高还是较低的门店？','rank_limit'=>'当前支持查询五家，是否按五家查询？'];
        $envelope['question']=$questions[$first['key']]??$first['label'];
        $summary=[];
        if($envelope['resolved_metrics']) {
            $names=$this->names($envelope['resolved_metrics']);
            $summary[]=['label'=>'已确定指标','value'=>implode('、',array_map(static function($code)use($names){return $names[$code]??$code;},$envelope['resolved_metrics']))];
        }
        foreach(['resolved_range'=>'查询期间','resolved_compare_range'=>'对比期间'] as $key=>$label) if(!empty($envelope[$key])) $summary[]=['label'=>$label,'value'=>$envelope[$key]['start'].' 至 '.$envelope[$key]['end']];
        if(!empty($envelope['ranking']['direction'])) $summary[]=['label'=>'排行方向','value'=>['top'=>'业绩较高','bottom'=>'业绩较低','top_and_bottom'=>'高低都看'][$envelope['ranking']['direction']]];
        if(!empty($envelope['ranking']['limit'])) $summary[]=['label'=>'门店数量','value'=>(string)$envelope['ranking']['limit'].' 家'];
        $envelope['confirmed_summary']=$summary;
        return $envelope;
    }

    private function plan(array $metrics, ?array $range, string $shape,?array $compare=null,?array $ranking=null,string $format='screen'): array
    {
        if($shape==='definition') {
            $plan=['schema_version'=>'mohe-executable-workflow-v1','workflow_code'=>'wf_metric_definition','workflow_version'=>1,'query_shape'=>'definition','definition_metric_codes'=>$metrics,
                'nodes'=>['metric_catalog_read','metadata_guard','deterministic_definition'],'max_visits'=>3,'max_tool_calls'=>1,'output_format'=>'screen'];
            $plan['compiled_run_hash']=hash('sha256',json_encode($plan,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));return ['kind'=>'plan','plan'=>$plan];
        }
        if (!in_array($shape,['summary','trend','ranking','comparison'],true)) throw new AiContractException('AI_QUERY_SHAPE_NOT_READY');
        $plan = ['schema_version' => 'mohe-executable-workflow-v1', 'workflow_code' => 'wf_performance_snapshot', 'workflow_version' => 1,
            'query' => ['query_shape' => $shape, 'metric_codes' => $metrics, 'start_date' => $range['start'], 'end_date' => $range['end'],
                'compare_range' => $compare, 'store_ids' => [], 'business_filters' => [],'ranking'=>$ranking],
            'nodes' => ['query_metric_summary', 'all_evidence_guard', 'deterministic_answer'],
            'max_visits' => 3, 'max_tool_calls' => 1, 'output_format' => $format];
        $plan['compiled_run_hash'] = hash('sha256', json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return ['kind' => 'plan', 'plan' => $plan];
    }
    private function period(array $term, string $today): array
    {
        if (($term['code'] ?? '') === 'EXPLICIT') return $this->range($term['start'] ?? null, $term['end'] ?? null);
        $code=$term['code']??'';
        if ($code==='CALENDAR_DATES') {
            $normalize=function($value) use($today) {
                if (is_string($value) && preg_match('/^(?:([0-9]{4})年)?([0-9]{1,2})月([0-9]{1,2})[日号]$/uD',$value,$m)) return sprintf('%04d-%02d-%02d',$m[1]!==''?(int)$m[1]:(int)substr($today,0,4),(int)$m[2],(int)$m[3]);
                return $value;
            };
            return $this->range($normalize($term['start']??null),$normalize($term['end']??null));
        }
        if ($code==='ROLLING_DAYS') {
            $days=$term['days']??null;if(!is_int($days)||$days<1||$days>366)throw new AiContractException('AI_DATE_INVALID');
            return $this->range($this->date($today)->modify('-'.($days-1).' days')->format('Y-m-d'),$today);
        }
        if ($code==='TOMORROW') throw new AiContractException('AI_FUTURE_ACTUALS_UNAVAILABLE');
        if (!in_array($code,['TODAY','YESTERDAY','DAY_BEFORE_YESTERDAY','THIS_MONTH','LAST_MONTH'],true)) throw new AiContractException('AI_DATE_INVALID');
        $date=$this->date($today);
        if ($code==='YESTERDAY') $date=$date->modify('-1 day');
        if ($code==='DAY_BEFORE_YESTERDAY') $date=$date->modify('-2 days');
        if ($code==='LAST_MONTH') $date=$date->modify('first day of last month');
        return $this->range(in_array($code,['THIS_MONTH','LAST_MONTH'],true)?$date->format('Y-m-01'):$date->format('Y-m-d'),
            $code==='LAST_MONTH'?$date->format('Y-m-t'):$date->format('Y-m-d'));
    }
    /** Shared date normalization for capability-driven planners; no model date arithmetic. */
    public function normalizePeriod(array $term,string $today): array { return $this->period($term,$today); }
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
