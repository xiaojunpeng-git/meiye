<?php
namespace app\services\ai\context;

use RuntimeException;

/**
 * Resolves a model-understood ordinal only against the immutable result view
 * that produced a verified context reference.  Returned keys stay server-only.
 */
final class ResultReferenceResolver
{
    public static function resolve(array $query,array $view,array $reference): array
    {
        if (($query['query_shape']??null)!=='ranking' || !is_int($reference['ordinal']??null)
            || !in_array($reference['group']??null,['top','bottom'],true)) {
            throw new RuntimeException('AI_RESULT_REFERENCE_UNAVAILABLE');
        }
        // One displayed result and one explicit group make the ordinal stable
        // against storage ordering and later queries.
        $results=(array)($view['results']??[]);
        if (count($results)!==1 || !is_array($results[0]??null)) throw new RuntimeException('AI_RESULT_REFERENCE_UNAVAILABLE');
        $rows=(array)(($results[0]['rows']??[])[$reference['group']]??null);
        $ordinal=$reference['ordinal'];
        if ($ordinal<1 || $ordinal>count($rows)) throw new RuntimeException('AI_RESULT_REFERENCE_UNAVAILABLE');
        $point=$rows[$ordinal-1];
        $kind=$query['business_filters']['object_kind']??'store';
        if ($kind==='person' && is_int($point['employee_id']??null) && $point['employee_id']>0) {
            return ['object_kind'=>'person','business_filters'=>['object_kind'=>'person','selection_ref'=>'person:'.$point['employee_id']]];
        }
        if ($kind==='store' && is_int($point['store_id']??null) && $point['store_id']>0) {
            return ['object_kind'=>'store','store_ids'=>[$point['store_id']],'business_filters'=>[]];
        }
        // Other registered dimensions are not yet legal executable filters.
        throw new RuntimeException('AI_RESULT_REFERENCE_UNAVAILABLE');
    }
}
