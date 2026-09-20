<?php
namespace app\services\ai\execution;

/** Capability-driven selection state. No scene names, SQL, customer-name synonyms,
 * metric formulae or model-created graph nodes live here.
 */
final class AiAnalysisGuidancePlanner
{
    public function start(array $intent,array $projection,array $candidates,array $objects,string $format,string $today): array
    {
        if ($intent['object_kind']!=='person') throw new \RuntimeException('AI_ANALYSIS_PERSON_OBJECT_UNAVAILABLE');
        if (!in_array($intent['operation'],['summary','ranking'],true)) throw new \RuntimeException('AI_ANALYSIS_PERSON_OPERATION_UNAVAILABLE');
        $candidates=array_filter($candidates,static function(array $candidate) use($intent): bool {
            // The optional fallback keeps stored pre-R7 clarification envelopes
            // reviewable; all new gateway candidates carry query_shapes.
            return !isset($candidate['query_shapes']) || in_array($intent['operation'],$candidate['query_shapes'],true);
        });
        $actions=$intent['action_codes']??[];
        if (!is_array($actions)||count($actions)>8||count(array_unique($actions))!==count($actions)) throw new \RuntimeException('AI_MODEL_RESPONSE_INVALID');
        // A customer can explicitly ask the same person for two registered
        // measurements (for example sales and labour).  Each metric need not
        // carry every action label; only an unresolved single-metric choice
        // is narrowed by the requested action vocabulary.
        if ($actions && count($intent['metric_codes'])<=1) $candidates=array_filter($candidates,static function(array $candidate) use($actions):bool {
            return array_diff($actions,(array)($candidate['action_codes']??[]))===[];
        });
        ksort($candidates);
        if (!$candidates) throw new \RuntimeException('AI_PERSONNEL_PERMISSION_REQUIRED');
        if (count($intent['metric_codes'])>4) throw new \RuntimeException('AI_ANALYSIS_PERSON_MULTIPLE_METRICS');
        if (count($intent['metric_codes'])>1 && ($intent['operation']!=='summary' || $intent['needs_metric_choice'])) {
            throw new \RuntimeException('AI_ANALYSIS_PERSON_MULTIPLE_METRICS');
        }
        foreach ($intent['metric_codes'] as $code) if (!isset($candidates[$code])) throw new \RuntimeException('AI_MODEL_SELECTION_MISMATCH');
        $metric=$intent['metric_codes'][0]??null;
        if ($metric!==null && !isset($candidates[$metric])) throw new \RuntimeException('AI_MODEL_SELECTION_MISMATCH');
        if ($intent['needs_metric_choice']) $metric=null;
        if (!$objects['objects']) throw new \RuntimeException('AI_OBJECT_BINDING_UNAVAILABLE');
        $selection=$objects['status']==='resolved'?$objects['objects'][0]['ref']:null;
        // When a registered people-grain metric itself has one authoritative
        // analysis cohort, a broad people question need not make the customer
        // choose an internal role before seeing a useful first answer. An
        // explicit person/position selection always wins; an unavailable or
        // non-unique registered cohort falls back to the normal guidance.
        if ($selection===null && $metric!==null && is_string($candidates[$metric]['default_selection_ref']??null)) {
            $matches=array_values(array_filter($objects['objects'],static function(array $object)use($candidates,$metric):bool {
                return ($object['ref']??null)===$candidates[$metric]['default_selection_ref'];
            }));
            if (count($matches)===1) $selection=$matches[0]['ref'];
        }
        $periods=$projection['date_terms']??[];
        // A metric-only continuation carries no newly stated date in the
        // semantic projection. Its merged intent may nevertheless contain a
        // signed inherited Reader range. Prefer that range over the generic
        // current-day first-answer default; a current date expression remains
        // above and therefore always wins.
        if ($periods===[] && ($intent['context_delta']['periods']??null)==='inherit'
            && is_array($intent['periods']??null) && $intent['periods']!==[]) {
            $periods=$intent['periods'];
        }
        if (count($periods)>1 || !empty($projection['date_grouping_ambiguous'])) throw new \RuntimeException('AI_ANALYSIS_PERSON_PERIOD_COMBINATION_UNAVAILABLE');
        // Person-grain rankings use the same first-answer baseline as every
        // other registered query path. When no date is expressed, compile the
        // current business day instead of asking a redundant date question;
        // explicit and ambiguous date meaning has already been preserved or
        // rejected above and is never overwritten here.
        $periodPlanner=new AiWorkflowPlanner();
        $range=$periods
            ?(isset($periods[0]['kind'])
                ?$periodPlanner->normalizeNaturalPeriod($periods[0],$today)
                :$periodPlanner->normalizePeriod($periods[0],$today))
            :$periodPlanner->normalizePeriod(['code'=>'TODAY'],$today);
        $ranking=$intent['ranking']??null;
        $rankingKeys=is_array($ranking)?array_keys($ranking):[];sort($rankingKeys);
        if (!is_array($ranking) || $rankingKeys!==['direction','limit']
            || !in_array($ranking['direction']??null,['top','bottom','top_and_bottom','unspecified'],true)
            || (!is_null($ranking['limit']??null) && !is_int($ranking['limit']))) throw new \RuntimeException('AI_MODEL_RESPONSE_INVALID');
        $direction=$ranking['direction']==='unspecified'?null:$ranking['direction'];
        $limit=$ranking['limit'];
        if ($limit!==null && (!is_int($limit)||$limit<1||$limit>20)) throw new \RuntimeException('AI_RANK_LIMIT_NOT_READY');
        if (count($intent['metric_codes'])>1) {
            if ($selection===null || $range===null) return $this->next(['metric'=>$intent['metric_codes'][0],'metrics'=>array_values($intent['metric_codes']),
                'candidates'=>$candidates,'object'=>$selection,'objects'=>$objects['objects'],
                'range'=>$range,'operation'=>$intent['operation'],'direction'=>$direction,'limit'=>$limit,'format'=>$format]);
            return ['kind'=>'plan','plan'=>['workflow_code'=>'wf_performance_summary','query'=>[
                'query_shape'=>'summary','metric_codes'=>array_values($intent['metric_codes']),'start_date'=>$range['start'],'end_date'=>$range['end'],
                'compare_range'=>null,'store_ids'=>[],'business_filters'=>['object_kind'=>'person','selection_ref'=>$selection],
                'ranking'=>null,'aggregate_condition'=>null], 'output_format'=>$format]];
        }
        return $this->next(['metric'=>$metric,'metrics'=>$metric===null?[]:[$metric],'candidates'=>$candidates,'object'=>$selection,'objects'=>$objects['objects'],
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
            if ($key==='metric') $state['metrics']=[$value];
        }
        if (isset($choices['start_date'])) $state['range']=(new AiWorkflowPlanner())->normalizePeriod(['code'=>'EXPLICIT','start'=>$choices['start_date'],'end'=>$choices['end_date']],'2000-01-01');
        return $this->next($state);
    }

    private function next(array $state): array
    {
        $state['metrics']=array_values($state['metrics']??($state['metric']===null?[]:[$state['metric']]));
        $fields=[];$question='';
        // Ask for the actual missing business meaning first. An unresolved
        // metric cannot acquire its registry-owned default personnel cohort,
        // so asking for a personnel range first would misdiagnose the gap.
        if ($state['metric']===null) {
            $question='您希望按什么判断表现？请选择底层已登记的指标。';$options=[];
            foreach ($state['candidates'] as $code=>$metric) $options[]=['value'=>$code,'label'=>$metric['name'].'：'.$metric['summary']];
            $fields[]=['key'=>'analysis_metric','type'=>'select','label'=>'评价指标','options'=>$options];
        } elseif ($state['object']===null) {
            $question='您说的是哪一类人员？以下是当前有权查看并可明确选择的人员范围。';
            $fields[]=['key'=>'analysis_object','type'=>'select','label'=>'人员范围','options'=>array_map(static function($o){return ['value'=>$o['ref'],'label'=>$o['label']];},$state['objects'])];
        } elseif ($state['range']===null) {
            $question='您想查询哪个时间段？';$fields=[['key'=>'start_date','label'=>'开始日期','type'=>'date'],['key'=>'end_date','label'=>'结束日期','type'=>'date']];
        } elseif ($state['operation']==='ranking' && $state['direction']===null) {
            $question='您希望从高到低，还是从低到高查看？';$fields[]=['key'=>'analysis_direction','label'=>'排序方向','type'=>'select','options'=>[['value'=>'top','label'=>'从高到低'],['value'=>'bottom','label'=>'从低到高']]];
        } elseif ($state['operation']==='ranking' && $state['limit']===null) {
            $question='您希望查看多少人？';$fields[]=['key'=>'analysis_limit','label'=>'展示人数','type'=>'select','options'=>[['value'=>'1','label'=>'1 人'],['value'=>'5','label'=>'5 人'],['value'=>'10','label'=>'10 人']]];
        }
        if ($fields) {
            $summary=[];
            foreach ($state['objects'] as $object) if ($object['ref']===$state['object']) $summary[]=['label'=>'人员范围','value'=>$object['label']];
            if ($state['metrics']) $summary[]=['label'=>'评价指标','value'=>implode('、',array_map(static function(string $metric)use($state):string {
                return $state['candidates'][$metric]['name'];
            },$state['metrics']))];
            if ($state['range']!==null) $summary[]=['label'=>'查询期间','value'=>$state['range']['start'].' 至 '.$state['range']['end']];
            return ['kind'=>'clarification','schema_version'=>'mohe-analysis-guidance-v1','analysis_state'=>$state,'fields'=>$fields,
                'question'=>$question,'confirmed_summary'=>$summary];
        }
        $query=['query_shape'=>$state['operation'],'metric_codes'=>$state['metrics']?:[$state['metric']],'start_date'=>$state['range']['start'],'end_date'=>$state['range']['end'],
            'compare_range'=>null,'store_ids'=>[],'business_filters'=>['object_kind'=>'person','selection_ref'=>$state['object']],
            'ranking'=>$state['operation']==='ranking'?['direction'=>$state['direction'],'limit'=>$state['limit']]:null];
        // The registered compiler chooses and freezes the concrete Workflow from
        // query_shape.  Guidance never accepts model-authored graph nodes.
        return ['kind'=>'plan','plan'=>['workflow_code'=>'wf_performance_'.$state['operation'],'query'=>$query,'output_format'=>$state['format']]];
    }
}
