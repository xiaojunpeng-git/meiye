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
            return 'The previous response used the correct grouped envelope but its item bindings did not match the accepted independent queries. '
                . 'Return exactly {"items":[{"id":"q1","intent":{...}}]}, one item for every supplied group id. '
                . 'Preserve each group’s accepted object, operation, metric, period, and ranking direction; a summary or breakdown must not become a ranking. '
                . 'Bind only measurements supplied by the active registry, with no unresolved fragments or invented selection, condition, identity, '
                . 'period, or business value. Do not merge groups, omit a group, calculate data, or output prose.';
        }
        return 'The previous response used a single-intent envelope for an accepted independent-query collection. '
            . 'Return exactly {"items":[{"id":"q1","intent":{...}}]}. Include exactly one item for every supplied group id, '
            . 'with no other top-level key. Preserve every group requirement exactly; do not merge subjects, omit a group, invent a '
            . 'metric, substitute an object, calculate data, or output any prose.';
    }

    /**
     * Skip a redundant binding model call only for a complete, first-turn
     * breakdown-plus-ranking store request. The language model must already
     * own every object, operation, period and rank; exact current-question
     * catalogue terms alone may fill metric codes. The ordinary grouped
     * contract still rejects any missing or conflicting requirement.
     */
    public static function exactStoreViewCollection(array $understanding,array $safeQuestion,array $metricCodes): ?array
    {
        if (($safeQuestion['prior_query']??null)!==null || ($understanding['status']??null)!=='understood') return null;
        $groups=AiIntentUnderstandingContract::queryGroups($understanding);
        if (count($groups)!==2) return null;
        $items=[];$shapes=[];$periods=null;
        foreach ($groups as $group) {
            $subset=self::subset($understanding,$group['requirement_ids']);
            $requirements=AiIntentUnderstandingContract::requirements($subset);
            if ($requirements===[] || count($requirements)>3) return null;
            $codes=[];$bindings=[];$operation=null;$ranking=null;$scope='unspecified';
            foreach ($requirements as $requirementId=>$requirement) {
                $fields=(array)($requirement['fields']??[]);$values=(array)($requirement['values']??[]);
                if (array_diff($fields,['metric_codes','object_kind','object_relation','operation','periods','ranking','scope'])
                    || !in_array('metric_codes',$fields,true) || !in_array('operation',$fields,true)
                    || ($values['object_kind']??null)!=='store'
                    || ($values['object_relation']??'analysis')!=='analysis'
                    || !in_array($values['operation']??null,['breakdown','ranking'],true)
                    || !is_array($values['periods']??null) || count($values['periods'])!==1
                    || !is_array($values['metric_terms']??null) || count($values['metric_terms'])!==1
                    || !is_string($values['metric_terms'][0])) return null;
                if ($operation!==null && $operation!==$values['operation']) return null;
                if ($periods!==null && $periods!==$values['periods']) return null;
                if (isset($values['scope']) && $scope!=='unspecified' && $scope!==$values['scope']) return null;
                $operation=$values['operation'];$periods=$values['periods'];
                $scope=$values['scope']??$scope;
                $term=$values['metric_terms'][0];
                if ($term==='' || mb_strpos((string)($safeQuestion['question']??''),$term,0,'UTF-8')===false) return null;
                $code=\app\services\query\metric\MetricSemanticCatalog::uniqueCodeForTerms([$term],$metricCodes);
                if ($code===null) return null;
                if (!in_array($code,$codes,true)) $codes[]=$code;
                $bindings[]=['requirement_id'=>$requirementId,'status'=>'satisfied','metric_codes'=>[$code]];
                if (isset($values['ranking'])) {
                    if ($ranking!==null && $ranking!==$values['ranking']) return null;
                    $ranking=$values['ranking'];
                }
            }
            if ($operation==='ranking' && (count($requirements)!==1 || count($codes)!==1
                || !is_array($ranking) || !in_array($ranking['direction']??null,['top','bottom','top_and_bottom'],true))) return null;
            if ($operation==='breakdown' && $ranking!==null && ($ranking['direction']??null)!=='unspecified') return null;
            $shapes[$operation]=true;
            $items[]=['id'=>$group['id'],'intent'=>[
                'object_kind'=>'store','object_relation'=>'analysis','object_term'=>'','operation'=>$operation,
                'metric_codes'=>$codes,'action_codes'=>[],'needs_metric_choice'=>false,
                'initial_observation'=>false,'recommended_initial_answer'=>false,
                'requirement_bindings'=>$bindings,'ranking'=>$ranking??['direction'=>'unspecified','limit'=>null],
                'periods'=>$periods,'scope'=>$scope,'aggregate_condition'=>null,'result_reference'=>null,
                'unresolved_fragments'=>[],
            ]];
        }
        if (!isset($shapes['breakdown'],$shapes['ranking'])) return null;
        try {
            $normalized=self::normalize(['items'=>$items],$metricCodes,[],$safeQuestion,$understanding);
            return self::isExecutableStoreCollection($normalized)?$normalized:null;
        } catch (AiContractException $error) {return null;}
    }

    /** @return array<int,array{id:string,requirement_ids:array<int,string>,intent:array}> */
    public static function normalize($value,array $metricCodes,array $actionCodes,array $safeQuestion,array $understanding): array
    {
        if ($value instanceof \stdClass) $value=get_object_vars($value);
        if (!is_array($value)) throw new AiContractException('AI_MODEL_INTENT_CONTRACT_INVALID',['stage'=>'intent_group_contract','predicate'=>'shape']);
        $groups=AiIntentUnderstandingContract::queryGroups($understanding);
        $topKeys=array_keys($value);sort($topKeys,SORT_STRING);
        if (count($groups)===1 && $topKeys===['intent']) {
            $subset=self::subset($understanding,$groups[0]['requirement_ids']);
            $candidate=AiIntentResultContract::canonicalizeUniqueExactMetricBinding(
                $value['intent'],$subset,$safeQuestion,$metricCodes
            );
            return [[
                'id'=>$groups[0]['id'], 'requirement_ids'=>$groups[0]['requirement_ids'],
                'intent'=>self::applyAcceptedPeriods(
                    AiIntentResultContract::normalize($candidate,$metricCodes,$actionCodes,$safeQuestion,$subset),$subset
                ),
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
            $subset=self::subset($understanding,$group['requirement_ids']);
            $candidate=AiIntentResultContract::canonicalizeUniqueExactMetricBinding(
                $item['intent'],$subset,$safeQuestion,$metricCodes
            );
            $out[]=['id'=>$group['id'],'requirement_ids'=>$group['requirement_ids'],
                'intent'=>self::applyAcceptedPeriods(
                    AiIntentResultContract::normalize($candidate,$metricCodes,$actionCodes,$safeQuestion,$subset),$subset
                )];
        }
        if ($expected!==[]) throw new AiContractException('AI_MODEL_INTENT_CONTRACT_INVALID',['stage'=>'intent_group_contract','predicate'=>'group_coverage']);
        return $out;
    }

    /**
     * The meaning contract already owns an explicit period stated by the
     * customer. A grouped binding model may omit that identical value from
     * every item; projecting the one accepted typed carrier here prevents the
     * dimension planner from defaulting the whole collection to today. This
     * does not parse date words or invent a range, and conflicting period
     * carriers remain untouched for the strict contract to reject.
     */
    private static function applyAcceptedPeriods(array $intent,array $understanding): array
    {
        $sets=[];$ids=[];
        foreach (AiIntentUnderstandingContract::requirements($understanding) as $requirement) {
            if (!in_array('periods',(array)($requirement['fields']??[]),true)) continue;
            $periods=$requirement['values']['periods']??null;
            if (!is_array($periods) || $periods===[]) continue;
            $key=json_encode($periods,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $sets[$key]=$periods;
            if (is_string($requirement['id']??null)) $ids[]=$requirement['id'];
        }
        if (count($sets)!==1) return $intent;
        $intent['periods']=array_values($sets)[0];
        $intent['provenance']['periods']=['source'=>'customer','requirements'=>array_values(array_unique($ids))];
        if (is_array($intent['context_delta']??null)) $intent['context_delta']['periods']='replace';
        return $intent;
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
        $periods=null;
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
            if ($periods===null) {$periods=$itemPeriods;continue;}
            // 复合问句共享统计期，但每个独立对象保留自己的极值方向。
            // 例如“销售额最高的日期”是 top，而“项目最多和最少”是
            // top_and_bottom；强迫两者相同会让合法自然语言在执行前失败。
            if ($itemPeriods!==$periods) return false;
        }
        return true;
    }

    /**
     * A store can be both the breakdown grain and the ranked object in one
     * question. Admit only independent, fully bound store views over one
     * accepted period; the registered planner and Reader still verify each
     * child before the collection publishes anything. Named selections,
     * conditions, comparisons and mixed periods cannot borrow this path.
     */
    public static function isExecutableStoreCollection(array $items): bool
    {
        if (count($items)<2 || count($items)>self::MAX_ITEMS) return false;
        $periods=null;$shapes=[];
        foreach ($items as $item) {
            $intent=is_array($item['intent']??null)?$item['intent']:null;
            if ($intent===null || ($intent['object_kind']??null)!=='store'
                || ($intent['object_relation']??'analysis')!=='analysis'
                || !in_array($intent['operation']??null,['breakdown','ranking'],true)
                || !empty($intent['needs_metric_choice']) || ($intent['object_term']??'')!==''
                || ($intent['unresolved_fragments']??[])!==[]
                || !in_array($intent['scope']??'unspecified',['unspecified','authorized'],true)
                || ($intent['aggregate_condition']??null)!==null
                || ($intent['result_reference']??null)!==null
                || count((array)($intent['metric_codes']??[]))<1
                || count((array)($intent['metric_codes']??[]))>4) return false;
            $itemPeriods=$intent['periods']??null;
            if (!is_array($itemPeriods) || count($itemPeriods)!==1) return false;
            if ($periods!==null && $itemPeriods!==$periods) return false;
            $periods=$itemPeriods;
            $ranking=$intent['ranking']??null;
            if (!is_array($ranking)) return false;
            $shapes[$intent['operation']]=true;
            if ($intent['operation']==='ranking') {
                if (!in_array($ranking['direction']??null,['top','bottom','top_and_bottom'],true)
                    || (!is_null($ranking['limit']??null) && (!is_int($ranking['limit']) || $ranking['limit']<1))) return false;
            } elseif (($ranking['direction']??null)!=='unspecified' || ($ranking['limit']??null)!==null) return false;
        }
        return isset($shapes['breakdown'],$shapes['ranking']);
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
