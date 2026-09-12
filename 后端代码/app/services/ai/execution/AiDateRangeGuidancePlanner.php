<?php
namespace app\services\ai\execution;

use app\services\query\metric\MetricDefinitionRegistry;
use app\services\query\metric\MetricQueryContractException;
use app\services\query\metric\MetricQueryDatePolicy;

/** Turns a proven date boundary into a customer-confirmed range; never clips silently. */
final class AiDateRangeGuidancePlanner
{
    public function start(array $plan,string $today): ?array
    {
        $query=$plan['query']??null;
        if (!is_array($query) || !is_array($query['metric_codes']??null) || !$query['metric_codes']) return null;
        $ranges=['current'=>['start'=>$query['start_date']??null,'end'=>$query['end_date']??null]];
        if (is_array($query['compare_range']??null)) $ranges['comparison']=$query['compare_range'];
        $pending=[];
        foreach ($ranges as $key=>$range) {
            $options=$this->options($range,MetricDefinitionRegistry::COVERAGE_START,$today);
            if ($options===null) continue;
            if ($options===[]) return null; // Future/invalid ranges retain their own accurate reason.
            $pending[$key]=$options;
        }
        return $pending?$this->next(['plan'=>$plan,'pending'=>$pending]):null;
    }

    public function choose(array $envelope,array $choices): array
    {
        $state=$envelope['date_range_guidance_state']??null;
        if (!is_array($state) || !is_array($state['plan']??null) || !is_array($state['pending']??null) || !$state['pending']) throw new \RuntimeException('AI_CLARIFICATION_INVALID');
        $key=array_key_first($state['pending']); $field=$this->fieldKey($key);
        if (array_keys($choices)!==[$field] || !is_string($choices[$field]??null)) throw new \RuntimeException('AI_CLARIFICATION_INVALID');
        $selected=null;
        foreach ($state['pending'][$key] as $option) if (($option['value']??null)===$choices[$field]) $selected=$option['range'];
        if ($selected===null) throw new \RuntimeException('AI_CLARIFICATION_INVALID');
        if ($key==='current') {
            $state['plan']['query']['start_date']=$selected['start'];
            $state['plan']['query']['end_date']=$selected['end'];
        } else $state['plan']['query']['compare_range']=$selected;
        unset($state['pending'][$key]);
        return $state['pending']?$this->next($state):['kind'=>'plan','plan'=>$state['plan']];
    }

    /** null=already valid, []=another date condition owns the failure. */
    private function options(array $range,string $coverageStart,string $today): ?array
    {
        MetricQueryDatePolicy::normalize($range);
        // An explicit future end is not a range the server may reinterpret.
        // This check intentionally precedes the span check: a long request
        // that also reaches the future remains a future-data request.
        if ($range['end']>$today) return [];
        try {
            MetricQueryDatePolicy::assertExecutable($range,$coverageStart,$today);
            return null;
        } catch (MetricQueryContractException $error) {
            if (!in_array($error->getErrorCode(),['METRIC_QUERY_COVERAGE_UNAVAILABLE','METRIC_QUERY_RANGE_TOO_LONG'],true)) return [];
        }
        $start=max($range['start'],$coverageStart); $end=min($range['end'],$today);
        if ($start>$end) return [];
        $firstEnd=min($end,$this->addDays($start,MetricQueryDatePolicy::MAX_DAYS-1));
        $lastStart=max($start,$this->addDays($end,-(MetricQueryDatePolicy::MAX_DAYS-1)));
        $unique=[];
        foreach ([['start'=>$start,'end'=>$firstEnd],['start'=>$lastStart,'end'=>$end]] as $candidate) {
            MetricQueryDatePolicy::assertExecutable($candidate,$coverageStart,$today);
            $value=$candidate['start'].'/'.$candidate['end'];
            $unique[$value]=['value'=>$value,'label'=>$candidate['start'].' 至 '.$candidate['end'],'range'=>$candidate];
        }
        return array_values($unique);
    }

    private function next(array $state): array
    {
        $key=array_key_first($state['pending']); $label=$key==='comparison'?'对比期间':'查询期间';
        return ['kind'=>'clarification','schema_version'=>'mohe-date-range-guidance-v1','date_range_guidance_state'=>$state,
            'fields'=>[['key'=>$this->fieldKey($key),'type'=>'select','label'=>$label,'options'=>array_map(static function(array $option): array {
                return ['value'=>$option['value'],'label'=>$option['label']];
            },$state['pending'][$key])]],
            'question'=>'所选'.$label.'超出当前可查询范围。请确认要保留的期间；系统不会自动裁剪日期。','confirmed_summary'=>[]];
    }

    private function fieldKey(string $key): string
    {
        if (!in_array($key,['current','comparison'],true)) throw new \RuntimeException('AI_CLARIFICATION_INVALID');
        return $key.'_date_candidate';
    }

    private function addDays(string $date,int $days): string
    {
        return MetricQueryDatePolicy::date($date)->modify(($days<0?'':' +').$days.' days')->format('Y-m-d');
    }
}
