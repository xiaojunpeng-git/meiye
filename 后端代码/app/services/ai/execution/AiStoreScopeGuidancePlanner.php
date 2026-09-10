<?php
namespace app\services\ai\execution;

/**
 * Binds a named store only from a server-built, report-authorized catalog.
 * It has no language rules, metrics, SQL or permission grants: the model has
 * already supplied a semantic candidate and the selected ref is revalidated
 * here before it reaches the compiled query.
 */
final class AiStoreScopeGuidancePlanner
{
    public function start(array $base,array $objects): array
    {
        if (!in_array($base['kind']??null,['plan','clarification'],true) || !$objects) throw new \RuntimeException('AI_STORE_SCOPE_UNAVAILABLE');
        $options=[];
        foreach ($objects as $object) {
            if (!is_array($object)||!preg_match('/^store:[1-9][0-9]*$/D',$object['ref']??'')||!is_string($object['label']??null)) {
                throw new \RuntimeException('AI_STORE_SCOPE_UNAVAILABLE');
            }
            $options[]=['value'=>$object['ref'],'label'=>$object['label']];
        }
        return ['kind'=>'clarification','schema_version'=>'mohe-store-scope-guidance-v1',
            'store_scope_state'=>['base'=>$base,'objects'=>$objects],
            'fields'=>[['key'=>'store_scope','type'=>'select','label'=>'查询门店','options'=>$options]],
            'question'=>'请从当前有权查看的门店中选择要查询的门店。','confirmed_summary'=>[]];
    }

    public function choose(array $envelope,array $choices): array
    {
        if (array_keys($choices)!==['store_scope'] || !is_string($choices['store_scope'])) throw new \RuntimeException('AI_CLARIFICATION_INVALID');
        $state=$envelope['store_scope_state']??null;
        if (!is_array($state)||!is_array($state['objects']??null)||!is_array($state['base']??null)) throw new \RuntimeException('AI_CLARIFICATION_INVALID');
        $selected=array_values(array_filter($state['objects'],static function($object)use($choices):bool {
            return is_array($object)&&($object['ref']??null)===$choices['store_scope'];
        }));
        if (count($selected)!==1||!preg_match('/^store:([1-9][0-9]*)$/D',$selected[0]['ref'],$match)) throw new \RuntimeException('AI_CLARIFICATION_INVALID');
        $stores=[(int)$match[1]];$base=$state['base'];
        if (($base['kind']??null)==='plan' && is_array($base['plan']['query']??null)) {
            $base['plan']['query']['store_ids']=$stores;
            return $base;
        }
        if (($base['kind']??null)==='clarification') {
            $base['resolved_store_ids']=$stores;
            $base['confirmed_summary'][]=['label'=>'查询门店','value'=>$selected[0]['label']];
            return $base;
        }
        throw new \RuntimeException('AI_CLARIFICATION_INVALID');
    }
}
