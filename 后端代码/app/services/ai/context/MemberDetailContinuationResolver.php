<?php
namespace app\services\ai\context;

use RuntimeException;

/**
 * Resolves a model-understood member-detail continuation only against the
 * replayed, permission-checked result set from the preceding answer. It never
 * searches by a display name and never lets an ordinal escape that frozen set.
 */
final class MemberDetailContinuationResolver
{
    /** Empty is a valid query outcome, never a substitute for malformed rows. */
    public function isEmptyPopulation(array $view): bool
    {
        return $this->populationRows($view)===[];
    }

    /** Both the execution guard and detail selection inspect actual members. */
    private function populationRows(array $view): array
    {
        $query=$view['query']??null;
        if (!is_array($query)) throw new RuntimeException('AI_RESULT_REFERENCE_UNAVAILABLE');
        if (($query['query_shape']??null)==='ranking') return $this->rankingRows($view);
        if (($query['query_shape']??null)!=='condition_list'
            || ($query['condition_set']['subject']??null)!=='member'
            || !is_array($view['results'][0]['rows']??null)) throw new RuntimeException('AI_RESULT_REFERENCE_UNAVAILABLE');
        $rows=$view['results'][0]['rows'];
        foreach ($rows as $row) $this->member($row,[]);
        return $rows;
    }

    /** Resolve one ordinal or the complete bounded, verified displayed set. */
    public function resolve(array $query,array $view,array $request): array
    {
        // VerifiedQueryContext has already replayed the stored query and
        // checked its signed read-view reference. Use the Reader-normalized
        // query carried by that replay; comparing it byte-for-byte with the
        // planner input would reject harmless normalization such as ranges.
        $rows=$this->populationRows($view);
        if ($rows===[]) throw new RuntimeException('AI_RESULT_REFERENCE_UNAVAILABLE');
        $target=$request['target']??null;$ordinal=$request['ordinal']??null;
        // "This member" is safe only for a singleton result. Explicit plural
        // requests may read the verified set; an ambiguous singular must not
        // silently choose its first row.
        if ($target==='set') {
            // The query service already limits condition lists to 100. Do not
            // silently expand a truncated list into all matching identities.
            $total=$view['results'][0]['count']??count($rows);
            if (!empty($view['results'][0]['has_more'])||(int)$total>count($rows)||count($rows)>100) throw new RuntimeException('AI_MEMBER_DETAIL_SET_NOT_READY');
            $members=[];$seen=[];
            foreach ($rows as $row) {
                $member=$this->member($row,$request);
                if (isset($seen[$member['selection_ref']])) continue;
                $seen[$member['selection_ref']]=true;$members[]=$member;
            }
            return ['members'=>$members,'view'=>$request['view']??'summary'];
        }
        if ($target!=='single') throw new RuntimeException('AI_RESULT_REFERENCE_UNAVAILABLE');
        if ($ordinal===null) {
            if (count($rows)!==1) throw new RuntimeException('AI_MEMBER_DETAIL_SELECTION_REQUIRED');
            $index=0;
        } else {
            if (!is_int($ordinal)||$ordinal<1||$ordinal>count($rows)) throw new RuntimeException('AI_RESULT_REFERENCE_UNAVAILABLE');
            $index=$ordinal-1;
        }
        return $this->member($rows[$index]??null,$request);
    }

    /** Identity is always a validated source row, never a model-generated ID. */
    private function member($row,array $request): array
    {
        $memberId=$row['member_id']??null;$label=$row['member_name']??null;
        if (!is_array($row)||!is_int($memberId)||$memberId<1||!is_string($label)||trim($label)==='') {
            throw new RuntimeException('AI_RESULT_REFERENCE_UNAVAILABLE');
        }
        return ['selection_ref'=>'member:'.$memberId,'label'=>trim($label),'view'=>$request['view']??'summary'];
    }

    /** Ranking groups may repeat one tied member; keep first display order and one identity. */
    private function rankingRows(array $view): array
    {
        $rows=[];$seen=[];$matched=false;
        foreach ((array)($view['results']??[]) as $result) {
            if (($result['object_kind']??null)!=='member') continue;
            $groups=$result['rows']??null;
            if (!is_array($groups) || array_intersect(array_keys($groups),['top','bottom'])===[]
                || array_diff(array_keys($groups),['top','bottom'])!==[]) throw new RuntimeException('AI_RESULT_REFERENCE_UNAVAILABLE');
            $matched=true;
            foreach ($groups as $groupRows) {
                if (!is_array($groupRows)) throw new RuntimeException('AI_RESULT_REFERENCE_UNAVAILABLE');
                foreach ($groupRows as $row) {
                    if (!is_array($row)) throw new RuntimeException('AI_RESULT_REFERENCE_UNAVAILABLE');
                    $memberId=$row['member_id']??($row['entity_id']??null);
                    $label=$row['member_name']??($row['entity_name']??null);
                    // Validate before deduplicating: a damaged row cannot become
                    // an empty success or silently shrink the requested member set.
                    $this->member(['member_id'=>$memberId,'member_name'=>$label],[]);
                    if (isset($seen[$memberId])) continue;
                    $seen[$memberId]=true;$rows[]=['member_id'=>$memberId,'member_name'=>trim($label)];
                }
            }
        }
        if (!$matched) throw new RuntimeException('AI_RESULT_REFERENCE_UNAVAILABLE');
        return $rows;
    }
}
