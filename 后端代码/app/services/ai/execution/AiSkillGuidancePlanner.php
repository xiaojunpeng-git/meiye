<?php
namespace app\services\ai\execution;

/**
 * Guides a recognized business object whose executable query contract has not
 * been registered yet.  It deliberately has no query compiler: selecting an
 * option can explain the missing contract but can never create a best-effort
 * read from a report page or a fact table.
 */
final class AiSkillGuidancePlanner
{
    public function start(array $projection,array $recognizedTerms): array
    {
        $objects=[];
        foreach (($projection['objects']??[]) as $object) $objects[$object['code']]=$object;
        $codes=[];
        foreach ($recognizedTerms as $term) {
            if (($term['kind']??null)==='object' && isset($objects[$term['code']??''])) $codes[$term['code']]=true;
        }
        if (count($codes)!==1) throw new \RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
        $objectCode=array_key_first($codes);$object=$objects[$objectCode];$scene=null;
        foreach (($projection['scenes']??[]) as $candidate) if (in_array($objectCode,$candidate['object_codes']??[],true)) {$scene=$candidate;break;}
        if ($scene===null || count($scene['slot_codes']??[])!==1) throw new \RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
        $slots=[];foreach(($projection['slots']??[]) as $slot)$slots[$slot['code']]=$slot;
        $slot=$slots[$scene['slot_codes'][0]]??null;
        if (!is_array($slot)) throw new \RuntimeException('AI_ANALYSIS_COMBINATION_UNAVAILABLE');
        $state=['object_code'=>$objectCode,'object_label'=>$object['label'],'contract_label'=>$object['contract_label'],
            'scene_code'=>$scene['code'],'slot_code'=>$slot['code'],'slot_label'=>$slot['label'],'options'=>$slot['options']];
        return $this->next($state);
    }

    public function choose(array $envelope,array $choices): array
    {
        $state=$envelope['skill_state']??null;
        if (!is_array($state) || !is_array($envelope['fields']??null) || count($envelope['fields'])!==1) throw new \RuntimeException('AI_CLARIFICATION_INVALID');
        $field=$envelope['fields'][0];$key=$field['key']??'';
        if (array_keys($choices)!==[$key] || !is_string($choices[$key]??null)) throw new \RuntimeException('AI_CLARIFICATION_INVALID');
        $selected=null;foreach($state['options'] as $option) if($option['code']===$choices[$key]){$selected=$option;break;}
        if($selected===null)throw new \RuntimeException('AI_CLARIFICATION_INVALID');
        return ['kind'=>'capability_unavailable','reason'=>$this->reason($state['object_code']),'object_label'=>$state['object_label'],
            'contract_label'=>$state['contract_label'],'selected_label'=>$selected['label']];
    }

    private function next(array $state): array
    {
        return ['kind'=>'clarification','schema_version'=>'mohe-skill-guidance-v1','skill_state'=>$state,
            'question'=>$state['slot_label'].'当前'.$state['object_label'].'对象还没有完成可执行接入；选择后我会明确当前缺少的合同，不会替换为其他对象或指标。',
            'fields'=>[['key'=>'skill_'.$state['slot_code'],'type'=>'select','label'=>$state['slot_label'],
                'options'=>array_map(static function(array $option):array{return ['value'=>$option['code'],'label'=>$option['label']];},$state['options'])]],
            'confirmed_summary'=>[['label'=>'分析对象','value'=>$state['object_label']]]];
    }

    private function reason(string $object): string
    {
        return [
            'project'=>'AI_PROJECT_OBJECT_NOT_READY','product'=>'AI_PRODUCT_OBJECT_NOT_READY','category'=>'AI_CATEGORY_OBJECT_NOT_READY',
            'partner'=>'AI_PARTNER_OBJECT_NOT_READY','member'=>'AI_MEMBER_OBJECT_NOT_READY','inventory'=>'AI_INVENTORY_OBJECT_NOT_READY',
        ][$object]??'AI_OBJECT_CONTRACT_NOT_READY';
    }
}
