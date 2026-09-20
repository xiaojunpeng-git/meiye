<?php
namespace app\services\ai\contract;

/**
 * Binds a bounded set of independently requested analytical subjects without
 * allowing one subject to borrow another subject's requirement.  A one-item
 * response remains the legacy intent shape; collections are admitted only
 * when the understanding phase explicitly grouped the same requirements.
 */
final class AiIntentGroupContract
{
    public const MAX_ITEMS = 4;

    public static function modelInstruction(bool $hasPriorQuery,array $understanding): string
    {
        $groups=AiIntentUnderstandingContract::queryGroups($understanding);
        if (count($groups)===1) return AiIntentResultContract::modelInstruction($hasPriorQuery);
        return 'The accepted understanding contains independent query groups. Return exactly one JSON object '
            . '{"items":[{"id":"q1","intent":{...}}]}. Include exactly one item for each supplied group id, no other key. '
            . 'Every item may bind only the requirements assigned to its group; a shared requirement may be bound in each assigned group. '
            . 'Do not combine different analytical subjects into one item and do not split one AND/OR population condition into items. '
            . AiIntentResultContract::modelInstruction($hasPriorQuery,true);
    }

    /**
     * A collection transport has one recoverable failure mode: a provider can
     * return the legacy single-intent envelope after accepting grouped input.
     * This permits one format-only repair; it never supplies business meaning
     * or changes the independently accepted requirements.
     */
    public static function repairableFormat(?string $predicate): bool
    {
        return in_array($predicate,['items','collection_plan_shape'],true);
    }

    public static function repairInstruction(string $predicate): string
    {
        if (!self::repairableFormat($predicate)) throw new AiContractException('AI_MODEL_INPUT_INVALID');
        if ($predicate==='collection_plan_shape') {
            return 'The previous response used the correct grouped envelope but one or more item bindings could not be executed '
                . 'by the registered independent-ranking collection path. Return exactly {"items":[{"id":"q1","intent":{...}}]}. '
                . 'Preserve every supplied group and its accepted requirements. Each item must be an anonymous ranking: '
                . 'operation=ranking, exactly one supplied metric_code, needs_metric_choice=false, empty object_term, no unresolved_fragments, '
                . 'no aggregate_condition, scope=unspecified, and the same explicit top, bottom or top_and_bottom ranking direction '
                . '(never unspecified) plus limit for every item. '
                . 'Do not merge groups, add a store/person identity, choose a different period, calculate data, omit a group, or output prose.';
        }
        return 'The previous response used a single-intent envelope for an accepted independent-query collection. '
            . 'Return exactly {"items":[{"id":"q1","intent":{...}}]}. Include exactly one item for every supplied group id, '
            . 'with no other top-level key. Preserve every group requirement exactly; do not merge subjects, omit a group, invent a '
            . 'metric, substitute an object, calculate data, or output any prose.';
    }

    /** @return array<int,array{id:string,requirement_ids:array<int,string>,intent:array}> */
    public static function normalize($value,array $metricCodes,array $actionCodes,array $safeQuestion,array $understanding): array
    {
        if ($value instanceof \stdClass) $value=get_object_vars($value);
        if (!is_array($value)) throw new AiContractException('AI_MODEL_INTENT_CONTRACT_INVALID',['stage'=>'intent_group_contract','predicate'=>'shape']);
        $groups=AiIntentUnderstandingContract::queryGroups($understanding);
        $topKeys=array_keys($value);sort($topKeys,SORT_STRING);
        if (count($groups)===1 && $topKeys===['intent']) {
            return [[
                'id'=>$groups[0]['id'], 'requirement_ids'=>$groups[0]['requirement_ids'],
                'intent'=>AiIntentResultContract::normalize($value['intent'],$metricCodes,$actionCodes,$safeQuestion,
                    self::subset($understanding,$groups[0]['requirement_ids'])),
            ]];
        }
        if ($topKeys!==['items'] || !is_array($value['items']) || count($value['items'])<2 || count($value['items'])>self::MAX_ITEMS
            || array_keys($value['items'])!==range(0,count($value['items'])-1)) {
            throw new AiContractException('AI_MODEL_INTENT_CONTRACT_INVALID',['stage'=>'intent_group_contract','predicate'=>'items']);
        }
        if (count($groups)!==count($value['items'])) throw new AiContractException('AI_MODEL_INTENT_CONTRACT_INVALID',['stage'=>'intent_group_contract','predicate'=>'group_count']);
        $expected=[];foreach($groups as $group) $expected[$group['id']]=$group;
        $out=[];
        foreach($value['items'] as $item) {
            if ($item instanceof \stdClass) $item=get_object_vars($item);
            $itemKeys=is_array($item)?array_keys($item):[];sort($itemKeys,SORT_STRING);
            if (!is_array($item) || $itemKeys!==['id','intent'] || !is_string($item['id']??null) || !isset($expected[$item['id']])) {
                throw new AiContractException('AI_MODEL_INTENT_CONTRACT_INVALID',['stage'=>'intent_group_contract','predicate'=>'item']);
            }
            $group=$expected[$item['id']];unset($expected[$item['id']]);
            $out[]=['id'=>$group['id'],'requirement_ids'=>$group['requirement_ids'],
                'intent'=>AiIntentResultContract::normalize($item['intent'],$metricCodes,$actionCodes,$safeQuestion,
                    self::subset($understanding,$group['requirement_ids']))];
        }
        if ($expected!==[]) throw new AiContractException('AI_MODEL_INTENT_CONTRACT_INVALID',['stage'=>'intent_group_contract','predicate'=>'group_coverage']);
        return $out;
    }

    /**
     * The currently registered collection executor is intentionally narrow:
     * it can run only several independent anonymous rankings as one atomic
     * customer answer. This checks carrier compatibility before any reader
     * work begins, so a provider drift can receive one model-owned repair
     * instead of becoming a generic post-binding capability refusal.
     */
    public static function isExecutableRankingCollection(array $items): bool
    {
        if (count($items)<2 || count($items)>self::MAX_ITEMS) return false;
        $periods=null;$ranking=null;
        foreach ($items as $item) {
            $intent=is_array($item['intent']??null)?$item['intent']:null;
            if ($intent===null || ($intent['operation']??null)!=='ranking' || !empty($intent['needs_metric_choice'])
                || in_array($intent['object_kind']??null,['person','store','unknown'],true)
                || ($intent['object_term']??'')!=='' || ($intent['unresolved_fragments']??[])!==[]
                || ($intent['scope']??'unspecified')!=='unspecified' || ($intent['aggregate_condition']??null)!==null
                || count((array)($intent['metric_codes']??[]))!==1) return false;
            $itemPeriods=$intent['periods']??[];$itemRanking=$intent['ranking']??null;
            if (!is_array($itemRanking) || !in_array($itemRanking['direction']??null,['top','bottom','top_and_bottom'],true)
                || (!is_null($itemRanking['limit']??null) && !is_int($itemRanking['limit']))) return false;
            if ($periods===null) {$periods=$itemPeriods;$ranking=$itemRanking;continue;}
            if ($itemPeriods!==$periods || $itemRanking!==$ranking) return false;
        }
        return true;
    }

    /** @return array{goal:string,status:string,requirements:array<int,array>} */
    public static function subset(array $understanding,array $ids): array
    {
        $wanted=array_fill_keys($ids,true);$requirements=[];
        foreach ((array)($understanding['requirements']??[]) as $requirement) {
            if (is_array($requirement) && isset($wanted[$requirement['id']??''])) $requirements[]=$requirement;
        }
        if (count($requirements)!==count($wanted)) throw new AiContractException('AI_MODEL_INPUT_INVALID');
        return ['goal'=>$understanding['goal'],'status'=>$understanding['status'],'requirements'=>$requirements];
    }
}
