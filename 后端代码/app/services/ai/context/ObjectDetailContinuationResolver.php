<?php
namespace app\services\ai\context;

use RuntimeException;

/**
 * Resolves a natural-language detail continuation against the preceding
 * permission-checked read view. The model supplies only presentation intent;
 * stable object identity always comes from verified result rows.
 */
final class ObjectDetailContinuationResolver
{
    /** Resolve a member asset request or one displayed ranking object. */
    public function resolve(array $query,array $view,array $request): array
    {
        $effective=$view['query']??null;
        if (!is_array($effective)) throw new RuntimeException('AI_RESULT_REFERENCE_UNAVAILABLE');
        $shape=$effective['query_shape']??null;
        $kind=$effective['business_filters']['object_kind']??null;
        // Store is the registry's unfiltered base grain, so an ordinary store
        // ranking legitimately has no business_filters marker. Prefer an
        // explicit result contract when present, then the documented default.
        if ($kind===null&&$shape==='ranking') {
            $resultKind=$view['results'][0]['object_kind']??null;
            $kind=is_string($resultKind)&&$resultKind!==''?$resultKind:'store';
        }
        if (($effective['query_shape']??null)==='condition_list'
            &&($effective['condition_set']['subject']??null)==='member') $kind='member';
        if ($kind==='member') {
            $member=(new MemberDetailContinuationResolver())->resolve($query,$view,$request);
            return $member+['object_kind'=>'member'];
        }
        if ($shape!=='ranking'||!in_array($kind,['person','store'],true)
            ||($request['target']??null)!=='single'||($request['view']??null)!=='summary') {
            throw new RuntimeException('AI_OBJECT_DETAIL_NOT_READY');
        }
        $rows=$this->rankingRows($view,$kind);
        $ordinal=$request['ordinal']??null;
        if ($ordinal===null) {
            if (count($rows)!==1) throw new RuntimeException('AI_OBJECT_DETAIL_SELECTION_REQUIRED');
            $index=0;
        } else {
            if (!is_int($ordinal)||$ordinal<1||$ordinal>count($rows)) throw new RuntimeException('AI_RESULT_REFERENCE_UNAVAILABLE');
            $index=$ordinal-1;
        }
        $row=$rows[$index];
        return $kind==='person'
            ?['object_kind'=>'person','selection_ref'=>'person:'.$row['id'],'label'=>$row['label'],'view'=>'summary']
            :['object_kind'=>'store','store_id'=>$row['id'],'label'=>$row['label'],'view'=>'summary'];
    }

    /** Keep visible order while collapsing tied rows for one stable identity. */
    private function rankingRows(array $view,string $kind): array
    {
        $rows=[];$seen=[];
        foreach ((array)($view['results']??[]) as $result) {
            if (isset($result['object_kind'])&&$result['object_kind']!==$kind) continue;
            foreach (['top','bottom'] as $group) foreach ((array)($result['rows'][$group]??[]) as $row) {
                if (!is_array($row)) continue;
                $id=$kind==='person'?($row['employee_id']??$row['entity_id']??null):($row['store_id']??$row['entity_id']??null);
                $label=$kind==='person'?($row['employee_name']??$row['person_name']??$row['entity_name']??null):($row['store_name']??$row['entity_name']??null);
                if (!is_int($id)||$id<1||!is_string($label)||trim($label)===''||isset($seen[$id])) continue;
                $seen[$id]=true;$rows[]=['id'=>$id,'label'=>trim($label)];
            }
        }
        if ($rows===[]) throw new RuntimeException('AI_RESULT_REFERENCE_UNAVAILABLE');
        return $rows;
    }
}
