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
    /** Resolve a member asset request, a ranking row, or an already selected object. */
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
        if (!in_array($kind,['person','store'],true)
            ||!in_array($request['target']??null,['single','set'],true)||($request['view']??null)!=='summary') {
            throw new RuntimeException('AI_OBJECT_DETAIL_NOT_READY');
        }
        $rows=$shape==='ranking'?$this->rankingRows($view,$kind):$this->selectedRows($effective,$view,$kind);
        // “The current result set” and “this object” denote the same identity
        // when the verified set is a singleton. Never collapse a plural set
        // to its first row; multi-object overviews need an explicit selection.
        if ($request['target']==='set'&&(count($rows)!==1||($request['ordinal']??null)!==null)) {
            throw new RuntimeException('AI_OBJECT_DETAIL_SELECTION_REQUIRED');
        }
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

    /**
     * A second follow-up sees the previous single-object overview, not its
     * original ranking. Reuse only its signed, permission-replayed selection;
     * an aggregate/cohort or a multi-store breakdown is not one identity.
     * Do not infer an ordinal from breakdown rows: the renderer may hide zero
     * rows and paginate them differently from the underlying read view.
     */
    private function selectedRows(array $query,array $view,string $kind): array
    {
        if ($kind==='person'&&($query['query_shape']??null)==='summary') {
            $ref=$query['business_filters']['selection_ref']??'';
            $label=$view['personnel_selection_label']??null;
            if (is_string($ref)&&preg_match('/^person:([1-9][0-9]*)$/D',$ref,$match)
                &&is_string($label)&&trim($label)!=='') {
                return [['id'=>(int)$match[1],'label'=>trim($label)]];
            }
        }
        if ($kind==='store'&&($query['query_shape']??null)==='breakdown') {
            $ids=$query['store_ids']??[];
            if (count($ids)!==1) throw new RuntimeException('AI_OBJECT_DETAIL_NOT_READY');
            if (!is_int($ids[0])||$ids[0]<1) throw new RuntimeException('AI_RESULT_REFERENCE_UNAVAILABLE');
            $label=null;
            foreach ((array)($view['results']??[]) as $result) {
                if (($result['object_kind']??null)!=='store'||!empty($result['has_more'])
                    ||count($result['rows']??[])!==1) throw new RuntimeException('AI_RESULT_REFERENCE_UNAVAILABLE');
                $row=$result['rows'][0];
                if (($row['entity_id']??null)!==$ids[0]||!is_string($row['entity_name']??null)
                    ||trim($row['entity_name'])===''||($label!==null&&$label!==trim($row['entity_name']))) {
                    throw new RuntimeException('AI_RESULT_REFERENCE_UNAVAILABLE');
                }
                $label=trim($row['entity_name']);
            }
            if ($label!==null) return [['id'=>$ids[0],'label'=>$label]];
        }
        throw new RuntimeException('AI_OBJECT_DETAIL_NOT_READY');
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
