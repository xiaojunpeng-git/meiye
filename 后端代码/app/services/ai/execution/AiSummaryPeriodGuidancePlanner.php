<?php
namespace app\services\ai\execution;

/**
 * Confirms the sole missing time slot of an exact, authorized summary.
 * Choices are server-owned ranges; custom dates use the existing validated
 * pending-context planner. No choice can alter a metric or object binding.
 */
final class AiSummaryPeriodGuidancePlanner
{
    public function start(array $base,string $today,array $summary): array
    {
        if (($base['kind']??null)!=='plan' || !is_array($base['plan']['query']??null)
            || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$today)) throw new \RuntimeException('AI_CONTEXT_DELTA_CONFLICT');
        return ['kind'=>'clarification','schema_version'=>'mohe-summary-period-guidance-v1',
            'summary_period_state'=>['base'=>$base,'today'=>$today,'summary'=>$summary],
            'fields'=>[['key'=>'summary_period','type'=>'select','label'=>'统计时间','options'=>[
                ['value'=>'TODAY','label'=>'今天'],['value'=>'YESTERDAY','label'=>'昨天'],
                ['value'=>'THIS_MONTH','label'=>'本月'],['value'=>'LAST_MONTH','label'=>'上月'],
                ['value'=>'CUSTOM','label'=>'自定义日期'],
            ]]],
            'question'=>'已确认查询对象和指标，请选择统计时间后继续。','confirmed_summary'=>$summary];
    }

    public function choose(array $envelope,array $choices): array
    {
        $state=$envelope['summary_period_state']??null;
        if (!is_array($state) || !is_array($state['base']??null) || !is_string($state['today']??null)
            || array_keys($choices)!==['summary_period'] || !is_string($choices['summary_period'])) {
            throw new \RuntimeException('AI_CLARIFICATION_INVALID');
        }
        $value=$choices['summary_period'];
        if ($value==='CUSTOM') {
            $query=$state['base']['plan']['query'];
            $custom=(new AiPendingContextGuidancePlanner())->start($state['base'],['periods'],[
                'store_ids'=>$query['store_ids']??[], 'business_filters'=>$query['business_filters']??null,
            ]);
            $custom['question']='请填写统计开始日期和结束日期。';
            $custom['confirmed_summary']=$state['summary']??[];
            return $custom;
        }
        if (!in_array($value,['TODAY','YESTERDAY','THIS_MONTH','LAST_MONTH'],true)) throw new \RuntimeException('AI_CLARIFICATION_INVALID');
        $range=(new AiWorkflowPlanner())->normalizePeriod(['code'=>$value],$state['today']);
        $base=$state['base'];
        $base['plan']['query']['start_date']=$range['start'];
        $base['plan']['query']['end_date']=$range['end'];
        return $base;
    }
}
