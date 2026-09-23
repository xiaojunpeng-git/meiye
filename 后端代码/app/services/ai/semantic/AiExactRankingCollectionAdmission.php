<?php
namespace app\services\ai\semantic;

use app\services\query\metric\MetricSemanticCatalog;

/**
 * Admits only one or more fully closed explicit metric extrema.
 *
 * The admission layer does not infer synonyms, formulas or default metrics.
 * Every measurement and analytical object must come from the active source
 * registries, while this class owns only reusable Chinese coordination and
 * extremum grammar.  A clause that leaves any business word unexplained is
 * rejected so the ordinary model path keeps all open-ended AI capability.
 */
final class AiExactRankingCollectionAdmission
{
    /**
     * @param array<int,array{object_kind:string,object_label:string}> $objectVocabulary
     * @param array<int,string> $allowedMetricCodes
     * @return array<int,array{metric_code:string,object_kind:string,direction:string,limit:int}>|null
     */
    public function match(string $question,array $objectVocabulary,array $allowedMetricCodes): ?array
    {
        if ($question==='' || preg_match('//u',$question)!==1) return null;
        $clauses=preg_split('/[，,；;。]+/u',$question,-1,PREG_SPLIT_NO_EMPTY);
        if (!is_array($clauses) || $clauses===[] || count($clauses)>6) return null;

        $objects=$this->objects($objectVocabulary);$items=[];
        foreach ($clauses as $rawClause) {
            $clause=trim($rawClause);
            if ($clause==='') continue;
            $metric=MetricSemanticCatalog::uniqueTermInText($clause,$allowedMetricCodes);
            $object=$this->uniqueObjectInText($clause,$objects);
            $direction=$this->direction($clause);

            // A short coordinated tail such as “最低是哪个” belongs to the
            // immediately preceding explicit metric/object clause. It may add
            // only a ranking direction; any other residue fails closed.
            if ($metric===null && $object===null && $direction!==null && $items!==[]
                && $this->residue($clause,null,null)==='') {
                $last=count($items)-1;
                $items[$last]['direction']=$this->combineDirection($items[$last]['direction'],$direction);
                continue;
            }
            if ($metric===null || $object===null || $direction===null) return null;
            if ($this->residue($clause,$metric['term'],$object['object_label'])!=='') return null;
            $items[]=['metric_code'=>$metric['metric_code'],'object_kind'=>$object['object_kind'],
                'direction'=>$direction,'limit'=>1];
        }
        if ($items===[] || count($items)>4) return null;
        return $items;
    }

    /** Prefer the longest registered label and reject labels owned by different object kinds. */
    private function uniqueObjectInText(string $text,array $objects): ?array
    {
        $matches=[];$longest=0;
        foreach ($objects as $object) {
            $label=$object['object_label'];
            if (mb_strpos($text,$label,0,'UTF-8')===false) continue;
            $length=mb_strlen($label,'UTF-8');
            if ($length>$longest) {$matches=[];$longest=$length;}
            if ($length===$longest) $matches[$object['object_kind']."\0".$label]=$object;
        }
        $kinds=[];foreach($matches as $match)$kinds[$match['object_kind']]=true;
        return count($kinds)===1 ? array_values($matches)[0] : null;
    }

    /** @return array<int,array{object_kind:string,object_label:string}> */
    private function objects(array $vocabulary): array
    {
        $out=[];
        foreach ($vocabulary as $item) {
            if (!is_array($item) || !is_string($item['object_kind']??null)
                || !is_string($item['object_label']??null) || trim($item['object_label'])==='') continue;
            $out[$item['object_kind']."\0".$item['object_label']]=[
                'object_kind'=>$item['object_kind'],'object_label'=>trim($item['object_label']),
            ];
        }
        return array_values($out);
    }

    private function direction(string $text): ?string
    {
        $top=(bool)preg_match('/最高|最多|最好|最大/u',$text);
        $bottom=(bool)preg_match('/最低|最少|最差|最小/u',$text);
        if ($top && $bottom) return 'top_and_bottom';
        if ($top) return 'top';
        if ($bottom) return 'bottom';
        return null;
    }

    private function combineDirection(string $left,string $right): string
    {
        if ($left===$right) return $left;
        return 'top_and_bottom';
    }

    /**
     * Remove only admitted carriers and closed question grammar. Remaining
     * text is an unhandled business instruction and must stay with the model.
     */
    private function residue(string $clause,?string $metricTerm,?string $objectLabel): string
    {
        $residue=$clause;
        foreach (array_filter([$metricTerm,$objectLabel]) as $term) $residue=str_replace($term,' ',$residue);
        $residue=preg_replace('/最高|最多|最好|最大|最低|最少|最差|最小/u',' ',$residue);
        $residue=preg_replace('/这个月|这一个月|本月|这月|上个月|上月|今天|今日|昨天|昨日|前天/u',' ',$residue);
        $residue=preg_replace('/(?:的)?(?:是)?(?:哪一天|哪一日|哪天|哪个|哪一个|什么)(?:又)?|分别|同时|以及|和|与|的|是|又|、|\s+/u','',$residue);
        return is_string($residue)?trim($residue):$clause;
    }
}
