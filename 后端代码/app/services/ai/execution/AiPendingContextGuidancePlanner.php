<?php
namespace app\services\ai\execution;

/** Resolves every model-declared missing context field before execution. */
final class AiPendingContextGuidancePlanner
{
    public function start(array $base,array $pending,array $constraints,array $metricOptions=[],array $targetIntent=[],array $operationOptions=[],array $semanticContext=[]): array
    {
        if (!in_array($base['kind']??null,['plan','clarification'],true) || !$pending || !$this->validConstraints($constraints)) throw new \RuntimeException('AI_CONTEXT_DELTA_CONFLICT');
        $pending=array_values(array_unique(array_filter($pending,static function($value): bool {return is_string($value);})));$this->metricOptions($metricOptions);$this->operationOptions($operationOptions);
        if (!$pending) throw new \RuntimeException('AI_CONTEXT_DELTA_CONFLICT');
        return ['kind'=>'clarification','schema_version'=>'mohe-context-pending-guidance-v1',
            'pending_context_state'=>['base'=>$base,'pending'=>$pending,'constraints'=>$constraints,'metric_options'=>$metricOptions,'target_intent'=>$targetIntent,'operation_options'=>$operationOptions,'semantic_context'=>$semanticContext],
            'inherited_query_constraints'=>$constraints,'fields'=>$this->fields($pending[0],$metricOptions,$base,$operationOptions),
            'question'=>'这一项尚未能从本轮问题中准确确认。请补充或选择条件；所有未确认条件完成前不会执行查询。','confirmed_summary'=>[]];
    }

    public function choose(array $envelope,array $choices): array
    {
        $state=$envelope['pending_context_state']??null;
        if (!is_array($state)||!is_array($state['base']??null)||!is_array($state['pending']??null)||!$this->validConstraints($state['constraints']??null)||!is_array($state['metric_options']??null)||!is_array($state['operation_options']??null)) throw new \RuntimeException('AI_CLARIFICATION_INVALID');
        $field=$state['pending'][0]??null;if(!is_string($field))throw new \RuntimeException('AI_CLARIFICATION_INVALID');
        $targetIntent=$state['target_intent']??[];if(!is_array($targetIntent))throw new \RuntimeException('AI_CLARIFICATION_INVALID');
        $semanticContext=$state['semantic_context']??[];if(!is_array($semanticContext))throw new \RuntimeException('AI_CLARIFICATION_INVALID');
        $fields=$this->fields($field,$state['metric_options'],$state['base'],$state['operation_options']);$expected=array_column($fields,'key');$actual=array_keys($choices);sort($expected);sort($actual);if($actual!==$expected)throw new \RuntimeException('AI_CLARIFICATION_INVALID');
        $base=$state['base'];$constraints=$state['constraints'];$selected=$choices[$fields[0]['key']]??null;$value=$this->apply($base,$constraints,$field,$choices,$fields);
        $remaining=array_values(array_slice($state['pending'],1));
        if($field==='operation'&&$selected==='operation:ranking'){
            $ranking=$this->ranking($base);
            if(($ranking['direction']??'unspecified')==='unspecified'||($ranking['limit']??null)===null) array_unshift($remaining,'ranking_options');
        }
        if($field==='operation'&&$selected==='operation:comparison')$remaining[]='comparison_periods';
        if($remaining)return $this->start($base,$remaining,$constraints,$state['metric_options'],$targetIntent,$state['operation_options'],$semanticContext);
        $base=$this->applyConfirmedIntent($base,$targetIntent);
        $base['inherited_query_constraints']=$constraints;$base['_semantic_context']=$semanticContext;if(($base['kind']??null)==='clarification')$base['confirmed_summary'][]=['label'=>$fields[0]['label'],'value'=>$value];return $base;
    }

    private function fields(string $pending,array $metricOptions,array $base=[],array $operationOptions=[]): array
    {
        $labels=['metric_codes'=>'指标','operation'=>'展示方式','periods'=>'统计时间','comparison_periods'=>'对比时间','scope'=>'数据范围','object'=>'查询对象','store_scope'=>'门店范围','business_filters'=>'业务筛选','ranking_options'=>'排行方式','ranking_direction'=>'排序方向','ranking_limit'=>'展示数量'];
        if(!isset($labels[$pending]))throw new \RuntimeException('AI_CONTEXT_DELTA_CONFLICT');
        if($pending==='periods'||$pending==='comparison_periods')return [['key'=>$pending==='periods'?'start_date':'compare_start_date','type'=>'date','label'=>'开始日期'],['key'=>$pending==='periods'?'end_date':'compare_end_date','type'=>'date','label'=>'结束日期']];
        if($pending==='ranking_options')return [
            ['key'=>'pending_ranking_direction','type'=>'select','label'=>'排序方向','options'=>[['value'=>'top','label'=>'从高到低'],['value'=>'bottom','label'=>'从低到高']]],
            ['key'=>'pending_ranking_limit','type'=>'select','label'=>'展示数量','options'=>[['value'=>'1','label'=>'1 项'],['value'=>'3','label'=>'3 项'],['value'=>'5','label'=>'5 项'],['value'=>'10','label'=>'10 项'],['value'=>'20','label'=>'20 项']]],
        ];
        $options=[['value'=>'retain','label'=>'沿用上一轮已确认条件']];
        if($pending==='metric_codes'){
            $options=[];$available=[];
            foreach($metricOptions as $option)$available[]=substr($option['value'],7);
            $current=$this->currentMetrics($base);
            if($current&&!array_diff($current,$available))$options[]=['value'=>'retain','label'=>'沿用上一轮已确认条件'];
            foreach($metricOptions as $option)$options[]=$option;
        }
        elseif($pending==='operation'){
            $options=[];$operations=$this->operationsFor($base,$operationOptions);
            if(in_array($this->currentOperation($base),$operations,true))$options[]=['value'=>'retain','label'=>'沿用上一轮已确认条件'];
            foreach($operations as $operation)$options[]=['value'=>'operation:'.$operation,'label'=>['summary'=>'汇总','breakdown'=>'逐项明细','trend'=>'趋势','ranking'=>'排行','comparison'=>'对比'][$operation]];
        }
        elseif($pending==='ranking_direction')foreach([['value'=>'ranking_direction:top','label'=>'从高到低'],['value'=>'ranking_direction:bottom','label'=>'从低到高']] as $option)$options[]=$option;
        elseif($pending==='ranking_limit')foreach([1,3,5,10,20] as $limit)$options[]=['value'=>'ranking_limit:'.$limit,'label'=>$limit.' 项'];
        elseif($pending==='store_scope'||$pending==='scope')$options[]=['value'=>'clear_store_scope','label'=>'改为当前全部可查看范围'];
        elseif(($pending==='business_filters'||$pending==='object')&&$this->canClearBusinessFilter($base))$options[]=['value'=>'clear_business_filter','label'=>'不沿用上一轮业务筛选'];
        return [['key'=>'pending_'.$pending,'type'=>'select','label'=>$labels[$pending],'options'=>$options]];
    }

    private function apply(array &$base,array &$constraints,string $pending,array $choices,array $fields): string
    {
        if($pending==='periods'){$range=(new AiWorkflowPlanner())->normalizePeriod(['code'=>'EXPLICIT','start'=>$choices['start_date']??null,'end'=>$choices['end_date']??null],'2000-01-01');$this->setRange($base,$range);return $range['start'].' 至 '.$range['end'];}
        if($pending==='comparison_periods'){$range=(new AiWorkflowPlanner())->normalizePeriod(['code'=>'EXPLICIT','start'=>$choices['compare_start_date']??null,'end'=>$choices['compare_end_date']??null],'2000-01-01');$this->setCompareRange($base,$range);return $range['start'].' 至 '.$range['end'];}
        if($pending==='ranking_options'){$direction=$choices['pending_ranking_direction']??null;$limit=filter_var($choices['pending_ranking_limit']??null,FILTER_VALIDATE_INT);if(!in_array($direction,['top','bottom'],true)||$limit===false||!in_array($limit,[1,3,5,10,20],true))throw new \RuntimeException('AI_CLARIFICATION_INVALID');$this->setRanking($base,$direction,$limit);return ($direction==='top'?'从高到低':'从低到高').'，展示 '.$limit.' 项';}
        $key=$fields[0]['key'];$value=$choices[$key]??null;if(!is_string($value)||!in_array($value,array_column($fields[0]['options'],'value'),true))throw new \RuntimeException('AI_CLARIFICATION_INVALID');
        if($value==='retain')return '沿用上一轮已确认条件';
        if($value==='clear_store_scope'){$constraints['store_ids']=null;$this->clearStoreScope($base);return '改为当前全部可查看范围';}
        if($value==='clear_business_filter'){$constraints['business_filters']=null;$this->clearBusinessFilter($base);return '不沿用上一轮业务筛选';}
        if($pending==='metric_codes'&&strpos($value,'metric:')===0){$code=substr($value,7);if(!preg_match('/^[a-z][a-z0-9_]{0,79}$/D',$code))throw new \RuntimeException('AI_CLARIFICATION_INVALID');$this->setMetric($base,$code);return $this->optionLabel($fields[0],$value);}
        if($pending==='operation'&&strpos($value,'operation:')===0){$operation=substr($value,10);if(!in_array($operation,['summary','breakdown','trend','ranking','comparison'],true))throw new \RuntimeException('AI_CLARIFICATION_INVALID');$this->setOperation($base,$operation);return $this->optionLabel($fields[0],$value);}
        if($pending==='ranking_direction'&&strpos($value,'ranking_direction:')===0){$direction=substr($value,18);if(!in_array($direction,['top','bottom'],true))throw new \RuntimeException('AI_CLARIFICATION_INVALID');$this->setRanking($base,$direction,null);return $this->optionLabel($fields[0],$value);}
        if($pending==='ranking_limit'&&strpos($value,'ranking_limit:')===0){$limit=filter_var(substr($value,14),FILTER_VALIDATE_INT);if($limit===false||!in_array($limit,[1,3,5,10,20],true))throw new \RuntimeException('AI_CLARIFICATION_INVALID');$this->setRanking($base,null,$limit);return $this->optionLabel($fields[0],$value);}
        throw new \RuntimeException('AI_CLARIFICATION_INVALID');
    }

    private function setRange(array &$base,array $range): void {if(($base['kind']??null)==='plan'){$base['plan']['query']['start_date']=$range['start'];$base['plan']['query']['end_date']=$range['end'];if(($base['plan']['query']['query_shape']??null)!=='comparison')$base['plan']['query']['compare_range']=null;return;}if(isset($base['analysis_state'])){$base['analysis_state']['range']=$range;return;}if(isset($base['dimension_state'])){$base['dimension_state']['range']=$range;return;}throw new \RuntimeException('AI_CONTEXT_DELTA_CONFLICT');}
    private function setCompareRange(array &$base,array $range): void {if(($base['kind']??null)!=='plan')throw new \RuntimeException('AI_CONTEXT_DELTA_CONFLICT');$base['plan']['query']['compare_range']=$range;}
    private function setMetric(array &$base,string $code): void {if(($base['kind']??null)==='plan'){$base['plan']['query']['metric_codes']=[$code];return;}if(isset($base['analysis_state'])){$base['analysis_state']['metric']=$code;return;}if(isset($base['dimension_state'])){$base['dimension_state']['metric']=$code;return;}throw new \RuntimeException('AI_CONTEXT_DELTA_CONFLICT');}
    private function setOperation(array &$base,string $operation): void {if(($base['kind']??null)==='plan'){$base['plan']['query']['query_shape']=$operation;$base['plan']['workflow_code']='wf_performance_'.$operation;if($operation!=='ranking')$base['plan']['query']['ranking']=null;if($operation!=='comparison')$base['plan']['query']['compare_range']=null;return;}if(isset($base['analysis_state'])){$base['analysis_state']['operation']=$operation;return;}throw new \RuntimeException('AI_CONTEXT_DELTA_CONFLICT');}
    private function setRanking(array &$base,?string $direction,?int $limit): void {if(($base['kind']??null)==='plan'){$ranking=$base['plan']['query']['ranking']??['direction'=>'unspecified','limit'=>null];if($direction!==null)$ranking['direction']=$direction;if($limit!==null)$ranking['limit']=$limit;$base['plan']['query']['ranking']=$ranking;return;}if(isset($base['analysis_state'])){if($direction!==null)$base['analysis_state']['direction']=$direction;if($limit!==null)$base['analysis_state']['limit']=$limit;return;}if(isset($base['dimension_state'])){if($direction!==null)$base['dimension_state']['direction']=$direction;if($limit!==null)$base['dimension_state']['limit']=$limit;return;}throw new \RuntimeException('AI_CONTEXT_DELTA_CONFLICT');}
    private function clearStoreScope(array &$base): void {if(($base['kind']??null)==='plan'){$base['plan']['query']['store_ids']=[];return;}unset($base['resolved_store_ids']);}
    private function clearBusinessFilter(array &$base): void {if(($base['kind']??null)==='plan'){$filters=$base['plan']['query']['business_filters']??[];$kind=$filters['object_kind']??null;if(!is_string($kind)||$kind==='person')throw new \RuntimeException('AI_UNSUPPORTED_CONDITION');$base['plan']['query']['business_filters']=['object_kind'=>$kind];return;}if(isset($base['dimension_state']))return;throw new \RuntimeException('AI_UNSUPPORTED_CONDITION');}
    private function ranking(array $base): array {if(($base['kind']??null)==='plan')return $base['plan']['query']['ranking']??['direction'=>'unspecified','limit'=>null];if(isset($base['analysis_state']))return ['direction'=>$base['analysis_state']['direction']??'unspecified','limit'=>$base['analysis_state']['limit']??null];if(isset($base['dimension_state']))return ['direction'=>$base['dimension_state']['direction']??'unspecified','limit'=>$base['dimension_state']['limit']??null];return ['direction'=>'unspecified','limit'=>null];}
    private function optionLabel(array $field,string $value): string {foreach($field['options'] as $option)if($option['value']===$value)return $option['label'];throw new \RuntimeException('AI_CLARIFICATION_INVALID');}
    private function metricOptions(array $options): void {foreach($options as $option)if(!is_array($option)||!preg_match('/^metric:[a-z][a-z0-9_]{0,79}$/D',$option['value']??'')||!is_string($option['label']??null))throw new \RuntimeException('AI_CONTEXT_DELTA_CONFLICT');}
    private function operationOptions(array $options): void {foreach($options as $metric=>$shapes){if(!is_string($metric)||!preg_match('/^[a-z][a-z0-9_]{0,79}$/D',$metric)||!is_array($shapes)||!$shapes||array_diff($shapes,['summary','breakdown','trend','ranking','comparison']))throw new \RuntimeException('AI_CONTEXT_DELTA_CONFLICT');}}
    /** Return only shapes jointly registered for every selected metric. */
    private function operationsFor(array $base,array $options): array
    {
        $metrics=[];
        if(($base['kind']??null)==='plan')$metrics=$base['plan']['query']['metric_codes']??[];
        elseif(isset($base['analysis_state']['metric']))$metrics=[$base['analysis_state']['metric']];
        elseif(isset($base['dimension_state']['metric']))$metrics=[$base['dimension_state']['metric']];
        if(!is_array($metrics)||!$metrics)throw new \RuntimeException('AI_CONTEXT_DELTA_CONFLICT');
        $allowed=null;
        foreach($metrics as $metric){if(!is_string($metric)||!isset($options[$metric]))throw new \RuntimeException('AI_CONTEXT_DELTA_CONFLICT');$shapes=$options[$metric];$allowed=$allowed===null?$shapes:array_values(array_intersect($allowed,$shapes));}
        $allowed=array_values(array_intersect(['summary','breakdown','trend','ranking','comparison'],$allowed??[]));
        if(!$allowed)throw new \RuntimeException('AI_CONTEXT_DELTA_CONFLICT');
        return $allowed;
    }
    private function currentOperation(array $base): ?string
    {
        if(($base['kind']??null)==='plan')return $base['plan']['query']['query_shape']??null;
        if(isset($base['analysis_state']['operation']))return $base['analysis_state']['operation'];
        if(isset($base['dimension_state']))return 'ranking';
        return null;
    }
    private function currentMetrics(array $base): array
    {
        if(($base['kind']??null)==='plan')return is_array($base['plan']['query']['metric_codes']??null)?$base['plan']['query']['metric_codes']:[];
        if(isset($base['analysis_state']['metric']))return [$base['analysis_state']['metric']];
        if(isset($base['dimension_state']['metric']))return [$base['dimension_state']['metric']];
        return [];
    }
    private function validConstraints($constraints): bool {if(!is_array($constraints))return false;foreach(['store_ids','business_filters'] as $key)if(!array_key_exists($key,$constraints)||($constraints[$key]!==null&&!is_array($constraints[$key])))return false;return true;}
    private function canClearBusinessFilter(array $base): bool {if(($base['kind']??null)!=='plan')return isset($base['dimension_state']);$kind=$base['plan']['query']['business_filters']['object_kind']??null;return is_string($kind)&&$kind!=='person';}
    /** Builds a fresh executable shell from the confirmed semantic intent. */
    public function applyConfirmedIntent(array $base,array $intent): array
    {
        if (!$intent || ($base['kind']??null)!=='plan') return $base;
        $query=$base['plan']['query']??null;if(!is_array($query))throw new \RuntimeException('AI_CONTEXT_DELTA_CONFLICT');
        if (is_array($intent['metric_codes']??null)&&$intent['metric_codes'])$query['metric_codes']=array_values($intent['metric_codes']);
        if (in_array($intent['operation']??null,['summary','breakdown','trend','ranking','comparison'],true))$query['query_shape']=$intent['operation'];
        if (is_array($intent['periods']??null)&&isset($intent['periods'][0]['start'],$intent['periods'][0]['end'])){$query['start_date']=$intent['periods'][0]['start'];$query['end_date']=$intent['periods'][0]['end'];}
        // A pending display choice can be resolved by this planner after the
        // model response.  Do not overwrite that confirmed ranking with the
        // intentionally empty ranking payload that accompanied `operation:
        // pending` in the model delta.
        if (($intent['operation']??null)==='ranking'&&$query['query_shape']==='ranking'&&is_array($intent['ranking']??null)) {
            $ranking=$intent['ranking'];
            if (in_array($ranking['direction']??null,['top','bottom','top_and_bottom'],true)) $query['ranking']['direction']=$ranking['direction'];
            if (is_int($ranking['limit']??null)) $query['ranking']['limit']=$ranking['limit'];
        }
        if ($query['query_shape']!=='ranking')$query['ranking']=null;
        $kind=$intent['object_kind']??null;
        if ($kind==='store') $query['business_filters']=$query['query_shape']==='breakdown'?['object_kind'=>'store']:[];
        elseif (is_string($kind)&&!in_array($kind,['unknown','person'],true)) $query['business_filters']=['object_kind'=>$kind];
        if ($query['query_shape']==='comparison'&&$query['compare_range']===null) throw new \RuntimeException('AI_CONTEXT_DELTA_CONFLICT');
        $base['plan']=['workflow_code'=>'wf_performance_'.$query['query_shape'],'query'=>$query,'output_format'=>$base['plan']['output_format']??'screen'];
        return $base;
    }
}
