<?php
namespace app\services\ai\execution;

/**
 * Disambiguates only names already present in the current authorized people
 * catalog. A duplicate name never silently binds to the first employee.
 */
final class AiNamedPersonGuidancePlanner
{
    public function start(array $base,array $objects): array
    {
        if (!in_array($base['kind']??null,['plan','clarification'],true) || count($objects)<2) {
            throw new \RuntimeException('AI_OBJECT_BINDING_UNAVAILABLE');
        }
        $options=[];
        foreach ($objects as $object) {
            if (!is_array($object) || !preg_match('/^person:[1-9][0-9]*$/D',(string)($object['ref']??''))
                || !is_string($object['label']??null)) throw new \RuntimeException('AI_OBJECT_BINDING_UNAVAILABLE');
            $options[]=['value'=>$object['ref'],'label'=>$object['label']];
        }
        return ['kind'=>'clarification','schema_version'=>'mohe-named-person-guidance-v1',
            'named_person_state'=>['base'=>$base,'objects'=>$objects],
            'fields'=>[['key'=>'named_person','type'=>'select','label'=>'员工','options'=>$options]],
            'question'=>'有多位同名员工，请选择要查询的员工。','confirmed_summary'=>[]];
    }

    public function choose(array $envelope,array $choices): array
    {
        $state=$envelope['named_person_state']??null;
        if (!is_array($state) || !is_array($state['objects']??null) || !is_array($state['base']??null)
            || array_keys($choices)!==['named_person'] || !is_string($choices['named_person'])) {
            throw new \RuntimeException('AI_CLARIFICATION_INVALID');
        }
        $matches=array_values(array_filter($state['objects'],static function($object)use($choices):bool {
            return is_array($object) && ($object['ref']??null)===$choices['named_person'];
        }));
        if (count($matches)!==1) throw new \RuntimeException('AI_CLARIFICATION_INVALID');
        $base=$state['base'];
        if (($base['kind']??null)==='plan') {
            $base['plan']['query']['business_filters']=['object_kind'=>'person','selection_ref'=>$matches[0]['ref']];
        } elseif (isset($base['summary_period_state']['base']['plan']['query'])) {
            $base['summary_period_state']['base']['plan']['query']['business_filters']=[
                'object_kind'=>'person','selection_ref'=>$matches[0]['ref']];
            $base['confirmed_summary'][]=['label'=>'员工','value'=>$matches[0]['label']];
        } else throw new \RuntimeException('AI_CLARIFICATION_INVALID');
        return $base;
    }
}
