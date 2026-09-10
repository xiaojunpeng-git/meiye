<?php
namespace app\services\ai\execution;

/** Capability-driven selection state. No scene names, SQL, customer-name synonyms,
 * metric formulae or model-created graph nodes live here.
 */
final class AiAnalysisGuidancePlanner
{
    public function start(array $intent,array $projection,array $candidates,array $objects,string $format,string $today): array
    {
        if ($intent['object_kind']!=='person' || !in_array($intent['operation'],['summary','ranking'],true)) throw new \RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
        $candidates=array_filter($candidates,static function(array $candidate) use($intent): bool {
            // The optional fallback keeps stored pre-R7 clarification envelopes
            // reviewable; all new gateway candidates carry query_shapes.
            return !isset($candidate['query_shapes']) || in_array($intent['operation'],$candidate['query_shapes'],true);
        });
        $actions=$intent['action_codes']??[];
        if (!is_array($actions)||count($actions)>8||count(array_unique($actions))!==count($actions)) throw new \RuntimeException('AI_MODEL_RESPONSE_INVALID');
        if ($actions) $candidates=array_filter($candidates,static function(array $candidate) use($actions):bool {
            return array_diff($actions,(array)($candidate['action_codes']??[]))===[];
        });
        ksort($candidates);
        if (!$candidates) throw new \RuntimeException('AI_PERSONNEL_PERMISSION_REQUIRED');
        if (count($intent['metric_codes'])>1) throw new \RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
        $metric=$intent['metric_codes'][0]??null;
        if ($metric!==null && !isset($candidates[$metric])) throw new \RuntimeException('AI_MODEL_SELECTION_MISMATCH');
        if ($intent['needs_metric_choice']) $metric=null;
        if (!$objects['objects']) throw new \RuntimeException('AI_OBJECT_BINDING_UNAVAILABLE');
        $selection=$objects['status']==='resolved'?$objects['objects'][0]['ref']:null;
        $periods=$projection['date_terms']??[];
        if (count($periods)>1 || !empty($projection['date_grouping_ambiguous'])) throw new \RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
        $range=$periods?(new AiWorkflowPlanner())->normalizePeriod($periods[0],$today):null;
        $ranking=$intent['ranking']??null;
        if (!is_array($ranking) || array_keys($ranking)!==['direction','limit']
            || !in_array($ranking['direction']??null,['top','bottom','top_and_bottom','unspecified'],true)
            || (!is_null($ranking['limit']??null) && !is_int($ranking['limit']))) throw new \RuntimeException('AI_MODEL_RESPONSE_INVALID');
        $direction=$ranking['direction']==='unspecified'?null:$ranking['direction'];
        $limit=$ranking['limit'];
        if ($limit!==null && (!is_int($limit)||$limit<1||$limit>20)) throw new \RuntimeException('AI_RANK_LIMIT_NOT_READY');
        return $this->next(['metric'=>$metric,'candidates'=>$candidates,'object'=>$selection,'objects'=>$objects['objects'],
            'range'=>$range,'operation'=>$intent['operation'],'direction'=>$direction,'limit'=>$limit,'format'=>$format]);
    }

    public function choose(array $envelope,array $choices): array
    {
        $fields=$envelope['fields'];$keys=array_keys($choices);$expected=array_column($fields,'key');sort($keys);sort($expected);
        if ($keys!==$expected) throw new \RuntimeException('AI_CLARIFICATION_INVALID');
        $state=$envelope['analysis_state'];
        foreach ($fields as $field) {
            if ($field['type']==='date') continue;
            $value=$choices[$field['key']];
            if (!is_string($value)||!in_array($value,array_column($field['options'],'value'),true)) throw new \RuntimeException('AI_CLARIFICATION_INVALID');
            $key=['analysis_object'=>'object','analysis_metric'=>'metric','analysis_direction'=>'direction','analysis_limit'=>'limit'][$field['key']]??null;
            if (!$key) throw new \RuntimeException('AI_CLARIFICATION_INVALID');
            $state[$key]=$key==='limit'?(int)$value:$value;
        }
        if (isset($choices['start_date'])) $state['range']=(new AiWorkflowPlanner())->normalizePeriod(['code'=>'EXPLICIT','start'=>$choices['start_date'],'end'=>$choices['end_date']],'2000-01-01');
        return $this->next($state);
    }

    private function next(array $state): array
    {
        $fields=[];$question='';
        if ($state['object']===null) {
            $question='您说的是哪一类人员？以下来自当前有权查看的人员结构；按当前任职筛选。';
            $fields[]=['key'=>'analysis_object','type'=>'select','label'=>'人员范围','options'=>array_map(static function($o){return ['value'=>$o['ref'],'label'=>$o['label']];},$state['objects'])];
        } elseif ($state['metric']===null) {
            $question='您希望按什么判断表现？请选择底层已登记的指标。';$options=[];
            foreach ($state['candidates'] as $code=>$metric) $options[]=['value'=>$code,'label'=>$metric['name'].'：'.$metric['summary']];
            $fields[]=['key'=>'analysis_metric','type'=>'select','label'=>'评价指标','options'=>$options];
        } elseif ($state['range']===null) {
            $question='您想查询哪个时间段？';$fields=[['key'=>'start_date','label'=>'开始日期','type'=>'date'],['key'=>'end_date','label'=>'结束日期','type'=>'date']];
        } elseif ($state['operation']==='ranking' && $state['direction']===null) {
            $question='您希望从高到低，还是从低到高查看？';$fields[]=['key'=>'analysis_direction','label'=>'排序方向','type'=>'select','options'=>[['value'=>'top','label'=>'从高到低'],['value'=>'bottom','label'=>'从低到高']]];
        } elseif ($state['operation']==='ranking' && $state['limit']===null) {
            $question='您希望查看多少人？';$fields[]=['key'=>'analysis_limit','label'=>'展示人数','type'=>'select','options'=>[['value'=>'1','label'=>'1 人'],['value'=>'5','label'=>'5 人'],['value'=>'10','label'=>'10 人']]];
        }
        if ($fields) {
            $summary=[];
            foreach ($state['objects'] as $object) if ($object['ref']===$state['object']) $summary[]=['label'=>'人员范围（当前任职）','value'=>$object['label']];
            if ($state['metric']!==null) $summary[]=['label'=>'评价指标','value'=>$state['candidates'][$state['metric']]['name']];
            if ($state['range']!==null) $summary[]=['label'=>'查询期间','value'=>$state['range']['start'].' 至 '.$state['range']['end']];
            return ['kind'=>'clarification','schema_version'=>'mohe-analysis-guidance-v1','analysis_state'=>$state,'fields'=>$fields,
                'question'=>$question,'confirmed_summary'=>$summary];
        }
        $query=['query_shape'=>$state['operation'],'metric_codes'=>[$state['metric']],'start_date'=>$state['range']['start'],'end_date'=>$state['range']['end'],
            'compare_range'=>null,'store_ids'=>[],'business_filters'=>['object_kind'=>'person','selection_ref'=>$state['object']],
            'ranking'=>$state['operation']==='ranking'?['direction'=>$state['direction'],'limit'=>$state['limit']]:null];
        // The registered compiler chooses and freezes the concrete Workflow from
        // query_shape.  Guidance never accepts model-authored graph nodes.
        return ['kind'=>'plan','plan'=>['workflow_code'=>'wf_performance_'.$state['operation'],'query'=>$query,'output_format'=>$state['format']]];
    }
}
