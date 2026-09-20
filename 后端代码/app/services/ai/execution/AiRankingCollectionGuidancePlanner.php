<?php
namespace app\services\ai\execution;

/** One time-range choice for several registry-backed independent rankings. */
final class AiRankingCollectionGuidancePlanner
{
    public function start(array $items): array
    {
        if (count($items)<2 || count($items)>4) throw new \RuntimeException('AI_CLARIFICATION_INVALID');
        $stateItems=[];
        foreach ($items as $item) {
            $state=is_array($item['dimension_state']??null)?$item['dimension_state']:null;
            if (!is_string($item['id']??null) || !is_string($item['label']??null) || $state===null
                || ($state['range']??null)!==null || !is_string($state['object_kind']??null)
                || !is_string($state['metric']??null) || !is_array($state['candidates']??null)
                || !isset($state['candidates'][$state['metric']])
                || !in_array($state['direction']??null,['top','bottom','top_and_bottom'],true)
                || !is_int($state['limit']??null) || $state['limit']<1 || $state['limit']>20
                || ($state['format']??null)!=='screen' || !is_string($state['today']??null)) {
                throw new \RuntimeException('AI_CLARIFICATION_INVALID');
            }
            $stateItems[]=['id'=>$item['id'],'label'=>$item['label'],'state'=>$state];
        }
        return ['kind'=>'clarification','schema_version'=>'mohe-ranking-collection-guidance-v1',
            'collection_ranking_state'=>['items'=>$stateItems],
            'fields'=>[
                ['key'=>'start_date','label'=>'开始日期','type'=>'date'],
                ['key'=>'end_date','label'=>'结束日期','type'=>'date'],
            ], 'question'=>'您想查询哪个时间段？','confirmed_summary'=>[]];
    }

    public function choose(array $envelope,array $choices): array
    {
        $actual=array_keys($choices);sort($actual,SORT_STRING);
        $items=$envelope['collection_ranking_state']['items']??null;
        if ($actual!==['end_date','start_date'] || !is_array($items) || count($items)<2 || count($items)>4
            || !is_string($choices['start_date']??null) || !is_string($choices['end_date']??null)) {
            throw new \RuntimeException('AI_CLARIFICATION_INVALID');
        }
        $plans=[];
        foreach ($items as $item) {
            $state=is_array($item['state']??null)?$item['state']:null;
            if (!is_string($item['id']??null) || !is_string($item['label']??null) || $state===null
                || !is_string($state['object_kind']??null) || !is_string($state['metric']??null)
                || !in_array($state['direction']??null,['top','bottom','top_and_bottom'],true)
                || !is_int($state['limit']??null) || ($state['format']??null)!=='screen') {
                throw new \RuntimeException('AI_CLARIFICATION_INVALID');
            }
            $range=(new AiWorkflowPlanner())->normalizePeriod([
                'code'=>'EXPLICIT','start'=>$choices['start_date'],'end'=>$choices['end_date'],
            ],$state['today']??'');
            $plans[]=['id'=>$item['id'],'label'=>$item['label'],'plan'=>[
                'workflow_code'=>'wf_performance_ranking','query'=>[
                    'query_shape'=>'ranking','metric_codes'=>[$state['metric']],
                    'start_date'=>$range['start'],'end_date'=>$range['end'],'compare_range'=>null,'store_ids'=>[],
                    'business_filters'=>['object_kind'=>$state['object_kind']],
                    'ranking'=>['direction'=>$state['direction'],'limit'=>$state['limit']],
                ],'output_format'=>'screen',
            ]];
        }
        return ['kind'=>'plan','plan'=>['items'=>$plans]];
    }
}
