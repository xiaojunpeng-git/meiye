<?php
namespace app\services\ai\execution;

/**
 * Requires an explicit customer decision before a new semantic object may
 * replace a verified object filter inherited from an earlier query.
 */
final class AiContextReplacementGuidancePlanner
{
    public function start(array $base,array $remainingPending=[],?array $constraints=null,array $metricOptions=[],array $targetIntent=[],array $operationOptions=[]): array
    {
        if (!in_array($base['kind']??null,['plan','clarification'],true)) throw new \RuntimeException('AI_CONTEXT_DELTA_CONFLICT');
        return ['kind'=>'clarification','schema_version'=>'mohe-context-replacement-guidance-v1',
            'context_replacement_state'=>['base'=>$base,'remaining_pending'=>$remainingPending,
                'constraints'=>$constraints,'metric_options'=>$metricOptions,'target_intent'=>$targetIntent,'operation_options'=>$operationOptions],
            'fields'=>[['key'=>'replace_previous_object_filter','type'=>'select','label'=>'查询对象','options'=>[
                ['value'=>'replace','label'=>'按本次对象继续查询'],
            ]]],
            'question'=>'本次对象与上一轮已选筛选不同。请确认是否按本次对象继续；未确认前不会移除上一轮条件。','confirmed_summary'=>[]];
    }

    public function choose(array $envelope,array $choices): array
    {
        if (array_keys($choices)!==['replace_previous_object_filter'] || ($choices['replace_previous_object_filter']??null)!=='replace') {
            throw new \RuntimeException('AI_CLARIFICATION_INVALID');
        }
        $base=$envelope['context_replacement_state']['base']??null;
        if (!is_array($base)||!in_array($base['kind']??null,['plan','clarification'],true)) throw new \RuntimeException('AI_CLARIFICATION_INVALID');
        if (($base['kind']??null)==='clarification') {
            $base['confirmed_summary'][]=['label'=>'查询对象','value'=>'按本次对象继续'];
        }
        $remaining=$envelope['context_replacement_state']['remaining_pending']??[];
        $constraints=$envelope['context_replacement_state']['constraints']??null;
        $metricOptions=$envelope['context_replacement_state']['metric_options']??[];
        $targetIntent=$envelope['context_replacement_state']['target_intent']??[];
        $operationOptions=$envelope['context_replacement_state']['operation_options']??[];
        if ($remaining) {
            if (!is_array($remaining)||!is_array($constraints)||!is_array($metricOptions)||!is_array($targetIntent)||!is_array($operationOptions)) throw new \RuntimeException('AI_CLARIFICATION_INVALID');
            // The object decision has just been confirmed. Rebase the private
            // preview onto that object before exposing the next choice, so a
            // store-only response form cannot leak into a member query.
            $base=(new AiPendingContextGuidancePlanner())->applyConfirmedIntent($base,$targetIntent);
            return (new AiPendingContextGuidancePlanner())->start($base,$remaining,$constraints,$metricOptions,$targetIntent,$operationOptions);
        }
        if ($targetIntent) return (new AiPendingContextGuidancePlanner())->applyConfirmedIntent($base,$targetIntent);
        return $base;
    }
}
