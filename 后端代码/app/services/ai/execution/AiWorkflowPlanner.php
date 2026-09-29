<?php
namespace app\services\ai\execution;

use app\services\ai\contract\AiContractException;
require_once __DIR__.'/AiCapabilityGuidanceCatalog.php';

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
        // Candidate meanings come from the lower-layer provider contracts which
        // this user may use now, not from a report-page or phrase-specific list.
        $objectKind=$selection['object_kind']??'store';
        $available=$definition?($capabilities['definition_metric_codes']??[]):array_keys(AiCapabilityGuidanceCatalog::discover($capabilities,$objectKind));
        foreach ($metrics as $metric) if (!in_array($metric, $available, true)) throw new AiContractException('AI_METRIC_NOT_READY');
        $shapes=array_values(array_intersect(['summary','breakdown','trend','ranking','comparison','threshold_count','condition_count','condition_list'],$signals));
        if (in_array('top_5', $signals, true) || in_array('bottom_5', $signals, true)) $shapes[]='ranking';
        $shapes=array_values(array_unique($shapes));
        if (in_array('comparison',$shapes,true) && count($projection['date_terms']??[])===2) $shapes=array_values(array_diff($shapes,['summary']));
        if (count($shapes)>1) throw new AiContractException('AI_UNSUPPORTED_CONDITION');
        $shape=$definition?'definition':($shapes[0]??'summary');
        if ($definition && ($shapes || $projection['date_terms'] || $format!=='screen')) throw new AiContractException('AI_UNSUPPORTED_CONDITION');
        if (!$definition && !in_array($shape, $capabilities['query_shapes'], true)) throw new AiContractException('AI_QUERY_SHAPE_NOT_READY');
        $shapeAvailable=$definition?$available:array_keys(AiCapabilityGuidanceCatalog::discover($capabilities,$objectKind,$shape));
        foreach ($metrics as $metric) if (!$definition && !in_array($metric,$shapeAvailable,true)) throw new AiContractException('AI_QUERY_SHAPE_NOT_READY');
        if (!$definition && $selection['query_shape'] !== $shape) throw new AiContractException('AI_MODEL_SELECTION_MISMATCH');
        $selected = $selection['metric_codes']; sort($selected); $expected = $metrics; sort($expected);
        if ($metrics && !in_array('ambiguous_metric',$signals,true) && $selected !== $expected) throw new AiContractException('AI_MODEL_SELECTION_MISMATCH');
        // Condition positions bind each registered metric to one operator and
        // threshold. The signal list is a set and its registry-order
        // projection is suitable for summaries, but it must never reorder
        // that positional condition contract. Preserve the already validated
        // binding order; the registered compiler below still requires an
        // exact one-to-one match with the canonical condition set.
        if (in_array($shape,['condition_count','condition_list'],true)) {
            $metrics=array_values($selection['metric_codes']);
        }
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
        // A complete analytical request may omit a time expression.  The
        // platform's neutral first-answer policy is the current business day:
        // it is a system default, not an inferred customer period, and it is
        // applied only when no period was supplied or inherited. Explicit or
        // ambiguous multi-period meanings above remain untouched, while a
        // comparison still requires both customer periods.
        if (!$definition && $shape!=='comparison' && $range===null
            && count((array)($projection['date_terms']??[]))===0
            && empty($projection['date_grouping_ambiguous'])) {
            $range=$this->period(['code'=>'TODAY'],$today);
        }
        $ranking=null;
        if ($shape==='ranking' && isset($selection['ranking'])) {
            $candidate=$selection['ranking'];
            $candidateKeys=is_array($candidate)?array_keys($candidate):[];sort($candidateKeys);
            if (!is_array($candidate) || $candidateKeys!==['direction','limit']
                || !in_array($candidate['direction']??null,['top','bottom','top_and_bottom','unspecified'],true)
                || (!is_null($candidate['limit']??null) && !is_int($candidate['limit']))) throw new AiContractException('AI_MODEL_RESPONSE_INVALID');
            $ranking=['direction'=>$candidate['direction']==='unspecified'?null:$candidate['direction'],'limit'=>$candidate['limit']];
        }
        if ($shape==='ranking' && (in_array('top_5',$signals,true)||in_array('bottom_5',$signals,true))) {
            $ranking=$ranking??['direction'=>in_array('top_5',$signals,true)?(in_array('bottom_5',$signals,true)?'top_and_bottom':'top'):'bottom','limit'=>5];
        }
        if ($shape==='ranking' && !$ranking) {
            $direction=in_array('rank_top',$signals,true)?(in_array('rank_bottom',$signals,true)?'top_and_bottom':'top'):(in_array('rank_bottom',$signals,true)?'bottom':null);
            $ranking=['direction'=>$direction,'limit'=>$projection['semantic_intent']['rank_limit']??null];
        }
        // Missing result count is not ambiguity.  Once the language model has
        // understood a ranking direction, return the Reader's bounded page of
        // results instead of asking the customer to choose a number.  The
        // bound protects execution only; it is never presented as a customer
        // condition and does not rewrite an explicit requested count.
        if ($shape==='ranking' && $ranking['direction'] && $ranking['limit']===null) $ranking['limit']=20;
        if (!$metrics || (!$definition && !$range) || in_array('ambiguous_metric', $signals, true) || ($shape==='comparison' && !$compare) || ($shape==='ranking' && (!$ranking['direction'] || !$ranking['limit']))) {
            $fields = [];
            if (!$metrics || in_array('ambiguous_metric', $signals, true)) {
                $options = []; foreach ($shapeAvailable as $metric) if (isset($names[$metric])) $options[] = ['value' => $metric, 'label' => \app\services\query\metric\MetricSemanticCatalog::optionLabel($metric)];
                if (!$options) throw new AiContractException('AI_METRIC_NOT_READY');
                $options[]=['value'=>'other_registered_metric','label'=>'以上都不是','action'=>'stop'];
                $fields[] = ['key' => 'metric_code', 'label' => '想了解哪一个已登记指标？', 'type' => 'select', 'options' => $options];
            }
            if (!$definition && !$range) { $fields[] = ['key' => 'start_date', 'label' => '开始日期', 'type' => 'date']; $fields[] = ['key' => 'end_date', 'label' => '结束日期', 'type' => 'date']; }
            if ($shape==='comparison' && !$compare) { $fields[]=['key'=>'compare_start','label'=>'对比开始日期','type'=>'date']; $fields[]=['key'=>'compare_end','label'=>'对比结束日期','type'=>'date']; }
            if ($shape==='ranking' && !$ranking['direction']) $fields[]=['key'=>'rank_direction','label'=>'想看表现较高还是较低的门店？','type'=>'select','options'=>[['value'=>'top','label'=>'业绩较高'],['value'=>'bottom','label'=>'业绩较低'],['value'=>'top_and_bottom','label'=>'高低都看']]];
            return $this->step(['kind' => 'clarification', 'fields' => $fields, 'resolved_metrics' => $metrics, 'resolved_range' => $range, 'resolved_compare_range' => $compare, 'query_shape' => $shape,'ranking'=>$ranking,'output_format'=>$format,'semantic_constraints'=>$projection['semantic_intent']??[],'requested_period_terms'=>$projection['date_terms']??[]]);
        }
        return $this->plan($metrics, $range, $shape,$compare,$ranking,$format,[],
            $selection['aggregate_condition']??null,$objectKind,$selection['condition_set']??null);
    }

    public function choose(array $envelope, array $choices): array
    {
        $required = array_column($envelope['fields'], 'key'); $actual = array_keys($choices); sort($required); sort($actual);
        if ($required !== $actual) throw new AiContractException('AI_CLARIFICATION_INVALID');
        $metrics = $envelope['resolved_metrics']; $range = $envelope['resolved_range'];
        foreach ($envelope['fields'] as $field) if ($field['key'] === 'metric_code') {
            if (!is_string($choices['metric_code']) || !in_array($choices['metric_code'], array_column($field['options'], 'value'), true)) throw new AiContractException('AI_CLARIFICATION_INVALID');
            foreach ($field['options'] as $option) if ($option['value']===$choices['metric_code'] && (($option['action']??null)==='stop')) throw new AiContractException('AI_CAPABILITY_NOT_READY');
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
            if(!is_string($choices['rank_limit']) || !in_array($choices['rank_limit'],['1','3','5','10','20'],true)) throw new AiContractException('AI_CLARIFICATION_INVALID');
            $ranking['limit']=(int)$choices['rank_limit'];
        }
        // The direction may have been supplied through a server-owned
        // clarification after the initial compile. Apply the same technical
        // presentation bound used by compile(), otherwise this path would
        // incorrectly keep asking for a customer count.
        if (($envelope['query_shape']??null)==='ranking' && !empty($ranking['direction']) && empty($ranking['limit'])) {
            $ranking['limit']=20;
            // Compatibility with an envelope created before the no-count
            // guidance change: discard only the obsolete presentation field,
            // never another unresolved customer condition.
            if (isset($envelope['pending_fields']) && is_array($envelope['pending_fields'])) {
                $envelope['pending_fields']=array_values(array_filter($envelope['pending_fields'],static function(array $field): bool {
                    return ($field['key']??null)!=='rank_limit';
                }));
            }
        }
        if(!empty($envelope['pending_fields'])) {
            $envelope['fields']=$envelope['pending_fields'];unset($envelope['pending_fields']);
            $envelope['resolved_metrics']=$metrics;$envelope['resolved_range']=$range;$envelope['resolved_compare_range']=$compare;$envelope['ranking']=$ranking;
            return $this->step($envelope);
        }
        return $this->plan($metrics, $range, $envelope['query_shape'],$compare,$ranking,$envelope['output_format']??'screen',$envelope['resolved_store_ids']??[],
            $envelope['aggregate_condition']??null,$envelope['object_kind']??'store',$envelope['condition_set']??null);
    }

    /** One semantic question; range endpoints are a single controlled input. */
    private function step(array $envelope): array
    {
        $all=$envelope['fields'];$first=array_shift($all);$fields=[$first];
        if(in_array($first['key'],['start_date','compare_start'],true)) $fields[]=array_shift($all);
        $envelope['schema_version']='mohe-guidance-state-v2';
        $envelope['fields']=$fields;$envelope['pending_fields']=$all;$envelope['guidance_step']=$first['key'];
        $questions=['metric_code'=>'您想了解哪一个已登记指标？','start_date'=>'您想查询哪个时间段？','compare_start'=>'您想和哪个时间段比较？','rank_direction'=>'您想看业绩较高还是较低的门店？','rank_limit'=>'您希望查看多少家门店？'];
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

    private function plan(array $metrics, ?array $range, string $shape,?array $compare=null,?array $ranking=null,string $format='screen',array $storeIds=[],?array $aggregateCondition=null,string $objectKind='store',?array $conditionSet=null): array
    {
        if($shape==='definition') {
            return ['kind'=>'plan','plan'=>['schema_version'=>'mohe-executable-workflow-v1','workflow_code'=>'wf_metric_definition',
                'query_shape'=>'definition','definition_metric_codes'=>$metrics,'output_format'=>'screen']];
        }
        if (!in_array($shape,['summary','breakdown','trend','ranking','comparison','threshold_count','condition_count','condition_list'],true)) throw new AiContractException('AI_QUERY_SHAPE_NOT_READY');
        $conditionPopulation=in_array($shape,['condition_count','condition_list'],true);
        if ($conditionPopulation && (!in_array($objectKind,['person','member','store','order','sale_line','card','project','product'],true)||$conditionSet===null
            ||($conditionSet['subject']??null)!==$objectKind||$ranking!==null||$compare!==null)) throw new AiContractException('AI_UNSUPPORTED_CONDITION');
        $conditionFilters=$objectKind==='person'
            ? ['object_kind'=>'person','selection_ref'=>'cohort:active_personnel']
            : ['object_kind'=>$objectKind];
        $plan = ['schema_version' => 'mohe-executable-workflow-v1', 'workflow_code' => 'wf_performance_'.$shape,
            'query' => ['query_shape' => $shape, 'metric_codes' => $metrics, 'start_date' => $range['start'], 'end_date' => $range['end'],
                'compare_range' => $compare, 'store_ids' => $storeIds,
                'business_filters' => $conditionPopulation?$conditionFilters:(($shape==='threshold_count' || $shape==='breakdown' || ($shape==='summary' && $objectKind!=='store')) ? ['object_kind'=>$objectKind] : []),
                'ranking'=>$ranking,'aggregate_condition'=>$aggregateCondition],
            // Nodes and budgets are compiled from the selected registration; this
            // preliminary plan deliberately carries no editable graph or hash.
            'output_format' => $format];
        if ($conditionPopulation) $plan['query']['condition_set']=$conditionSet;
        return ['kind' => 'plan', 'plan' => $plan];
    }
    private function period(array $term, string $today): array
    {
        if (($term['code'] ?? '') === 'EXPLICIT') return $this->range($term['start'] ?? null, $term['end'] ?? null);
        $code=$term['code']??'';
        if ($code==='CALENDAR_YEAR') {
            $year=$this->calendarYear($term['start']??null,$today);
            $start=sprintf('%04d-01-01',$year);
            return $this->range($start,$year===(int)substr($today,0,4)?$today:sprintf('%04d-12-31',$year));
        }
        if ($code==='CALENDAR_DATES') {
            $start=$this->calendarEndpoint($term['start']??null,$today,false);
            // An omitted year on the second month inherits the stated start
            // year, never an unrelated server year. Reversed ranges remain invalid.
            $end=$term['end']??null;
            if (is_string($end) && preg_match('/^[0-9一二两三四五六七八九十]+月/u',$end)) $end=substr($start,0,4).'年'.$end;
            return $this->range($start,$this->calendarEndpoint($end,$today,true));
        }
        if ($code==='ROLLING_DAYS') {
            return $this->normalizeNaturalPeriod(['kind'=>'relative_days','days'=>$term['days']??null,'end_offset_days'=>0],$today);
        }
        if ($code==='TOMORROW') return $this->normalizeNaturalPeriod(['kind'=>'relative_days','days'=>1,'end_offset_days'=>1],$today);
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
    /** Month precision means first/last day, with the current month to date.
     * Both endpoints use the server business day; strict date validation is
     * retained, so invalid dates cannot roll over into another month. */
    private function calendarEndpoint($value,string $today,bool $end): string
    {
        if (is_string($value) && preg_match('/^(?:[0-9]{4}年|今年|去年)$/uD',$value)) {
            $year=$this->calendarYear($value,$today);
            return $end?sprintf('%04d-12-31',$year):sprintf('%04d-01-01',$year);
        }
        $relative=['今天'=>'TODAY','现在'=>'TODAY','目前'=>'TODAY','昨天'=>'YESTERDAY','前天'=>'DAY_BEFORE_YESTERDAY','明天'=>'TOMORROW','本月'=>'THIS_MONTH','这月'=>'THIS_MONTH','上月'=>'LAST_MONTH'];
        if (is_string($value) && isset($relative[$value])) return $this->period(['code'=>$relative[$value]],$today)[$end?'end':'start'];
        if (is_string($value) && preg_match('/^(?:(?<year>[0-9]{4})年|(?<relative>今年|去年))?(?<month>[0-9一二两三四五六七八九十]+)月(?:份)?(?:(?<day>[0-9一二两三四五六七八九十]+)[日号])?$/uD',$value,$m)) {
            $number=static function(string $text): int {
                if (ctype_digit($text)) return (int)$text;
                $digits=['一'=>1,'二'=>2,'两'=>2,'三'=>3,'四'=>4,'五'=>5,'六'=>6,'七'=>7,'八'=>8,'九'=>9];
                if (isset($digits[$text])) return $digits[$text];
                if (preg_match('/^([一二三])?十([一二三四五六七八九])?$/u',$text,$parts)) return ($digits[$parts[1]??'']??1)*10+($digits[$parts[2]??'']??0);
                throw new AiContractException('AI_DATE_INVALID');
            };
            $year=($m['year']??'')!==''?(int)$m['year']:(int)substr($today,0,4)-(($m['relative']??'')==='去年'?1:0);
            $date=$this->date(sprintf('%04d-%02d-%02d',$year,$number($m['month']),($m['day']??'')!==''?$number($m['day']):1));
            if (($m['day']??'')!=='' || !$end) return $date->format('Y-m-d');
            return $date->format('Y-m')===substr($today,0,7)?$today:$date->format('Y-m-t');
        }
        return $this->date($value)->format('Y-m-d');
    }
    /** Resolve a year label against the trusted business day, never system clock. */
    private function calendarYear($value,string $today): int
    {
        $current=(int)substr($this->date($today)->format('Y-m-d'),0,4);
        if ($value==='今年') return $current;
        if ($value==='去年') return $current-1;
        if (!is_string($value)||!preg_match('/^([1-9][0-9]{3})年$/D',$value,$match)) throw new AiContractException('AI_DATE_INVALID');
        return (int)$match[1];
    }
    /** Resolves meaning only. Bounds here are calendar representation, not query capacity. */
    public function normalizeNaturalPeriod(array $period,string $today): array
    {
        if (!\app\services\ai\contract\AiIntentResultContract::periods([$period])) throw new AiContractException('AI_DATE_INVALID');
        if ($period['kind']==='date_range') return $this->range($period['start'],$period['end']);
        $reference=$this->date($today);
        if ($period['kind']==='relative_days') {
            $minimum=$this->date('1000-01-01');$maximum=$this->date('9999-12-31');
            $offset=$period['end_offset_days'];
            if ($offset < -$minimum->diff($reference)->days || $offset > $reference->diff($maximum)->days) throw new AiContractException('AI_DATE_INVALID');
            $end=$reference->modify($offset.' days');
            if ($period['days']>$minimum->diff($end)->days+1) throw new AiContractException('AI_DATE_INVALID');
            return $this->range($end->modify('-'.($period['days']-1).' days')->format('Y-m-d'),$end->format('Y-m-d'));
        }
        $offset=$period['offset_months'];$year=(int)$reference->format('Y');$month=(int)$reference->format('n');
        if ($offset < - (($year-1000)*12+$month-1) || $offset > (9999-$year)*12+12-$month) throw new AiContractException('AI_DATE_INVALID');
        $start=$reference->modify('first day of this month')->modify($offset.' months');
        // Existing product meaning: current calendar month is month-to-date.
        return $this->range($start->format('Y-m-d'),$offset===0?$today:$start->format('Y-m-t'));
    }
    private function range($start, $end): array
    {
        try { return \app\services\query\metric\MetricQueryDatePolicy::normalize(['start'=>$start,'end'=>$end]); }
        catch (\app\services\query\metric\MetricQueryContractException $error) { throw new AiContractException(\app\services\query\metric\MetricQueryDatePolicy::aiReason($error->getErrorCode())); }
    }
    private function date($text): \DateTimeImmutable
    {
        if (!is_string($text) || !preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $text)) throw new AiContractException('AI_DATE_INVALID');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text, new \DateTimeZone('Asia/Shanghai'));
        if (!$date || $date->format('Y-m-d') !== $text) throw new AiContractException('AI_DATE_INVALID');
        return $date;
    }
}
