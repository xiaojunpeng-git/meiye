<?php
namespace app\services\ai\execution;

/** Apply explicit deltas to an already verified query, never to chat prose.
 * Caller verifies ownership, expiry and current source authority before calling;
 * the resulting plan still passes the registered compiler and a fresh read.
 */
final class AiFollowupQueryPlanner
{
    public function compile(array $source,string $question,array $metricNames,string $format,string $today): array
    {
        $text=$question;$metrics=[];
        // Longest dictionary name first; duplicate display names are ambiguous.
        uasort($metricNames,static function($a,$b){return strlen($b)<=>strlen($a);});
        $byName=[];foreach($metricNames as $code=>$name) if($name!=='')$byName[$name][]=$code;
        foreach($byName as $name=>$codes) if(strpos($text,$name)!==false) {
            if(count($codes)!==1) throw new \RuntimeException('AI_FOLLOWUP_CONDITION_REQUIRED');
            $metrics[]=$codes[0];$text=str_replace($name,' ',$text);
        }
        $text=preg_replace('/做(?:得|的)?(?=最好|最差|最高|最低)/u','',$text);
        $intent=(new \app\services\ai\model\AiModelInputProjector())->project($text);
        // New personnel/category/exclusion conditions must be resolved, not lost.
        if (!empty($intent['semantic_intent']['constraints']) || !empty($intent['unresolved_condition'])
            || !empty($intent['date_grouping_ambiguous']) || count($intent['date_terms'])>1
            || (!empty($intent['blocking_reason']) && $intent['blocking_reason']!=='AI_RANK_LIMIT_NOT_READY'))
            throw new \RuntimeException('AI_FOLLOWUP_CONDITION_REQUIRED');
        $signals=$intent['signals'];
        if(array_diff(array_intersect(['cash_performance','consume_amount','actual_performance'],$signals),array_keys($metricNames)))
            throw new \RuntimeException('AI_FOLLOWUP_CONDITION_REQUIRED');
        foreach(array_intersect(array_keys($metricNames),$signals) as $code)$metrics[]=$code;
        if(array_intersect($signals,['ambiguous_metric','current_store','original_result','definition','comparison','attention_goal']))
            throw new \RuntimeException('AI_FOLLOWUP_CONDITION_REQUIRED');
        $query=$source;
        if($metrics) $query['metric_codes']=array_values(array_unique($metrics));
        if($intent['date_terms']) {
            if($source['compare_range']!==null) throw new \RuntimeException('AI_FOLLOWUP_CONDITION_REQUIRED');
            $range=(new AiWorkflowPlanner())->normalizePeriod($intent['date_terms'][0],$today);
            $query['start_date']=$range['start'];$query['end_date']=$range['end'];
        }
        $shapes=array_values(array_intersect(['summary','ranking','trend'],$signals));
        if(count($shapes)>1) throw new \RuntimeException('AI_FOLLOWUP_CONDITION_REQUIRED');
        if($shapes) $query['query_shape']=$shapes[0];
        if($query['query_shape']==='ranking') {
            $ranking=$source['ranking']??['direction'=>null,'limit'=>null];
            $top=in_array('rank_top',$signals,true);$bottom=in_array('rank_bottom',$signals,true);
            if($top||$bottom)$ranking['direction']=$top?($bottom?'top_and_bottom':'top'):'bottom';
            if($intent['semantic_intent']['rank_limit']!==null)$ranking['limit']=$intent['semantic_intent']['rank_limit'];
            if(!$ranking['direction'] || !is_int($ranking['limit']) || $ranking['limit']<1 || $ranking['limit']>20)
                throw new \RuntimeException('AI_FOLLOWUP_CONDITION_REQUIRED');
            $query['ranking']=$ranking;
        } else $query['ranking']=null;
        // Preserve ALL source filters and store scope. They are not permissions.
        return ['kind'=>'plan','plan'=>['query'=>$query,'output_format'=>in_array('xlsx',$signals,true)?'screen_and_xlsx':$format]];
    }
}
