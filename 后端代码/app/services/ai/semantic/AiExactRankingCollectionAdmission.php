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
     * Admit one broad member ranking together with a bounded set-detail view.
     *
     * Natural-language understanding remains the default. This narrow path is
     * available only when the sentence itself closes every execution carrier:
     * one registered member object, one direction, one explicit row limit and
     * a genuine detail request. The caller accepts at most one calendar range;
     * if it is omitted, the existing dimension planner owns the same-day
     * default used by all broad dimension rankings.
     * The business metric is never inferred from wording; it must be the sole
     * active registry default shared by the member ranking capability.
     *
     * @param array<int,array{object_kind:string,object_label:string}> $objectVocabulary
     * @param array<int,string> $allowedMetricCodes
     * @param array<string,array<int,string>> $defaultRankObjectKinds
     * @return array{ranking:array{metric_code:string,object_kind:string,direction:string,limit:int},detail:array{view:string,target:string,ordinal:null}}|null
     */
    public function matchMemberPopulationDetail(
        string $question,array $objectVocabulary,array $allowedMetricCodes,
        array $defaultRankObjectKinds,?int $limit
    ): ?array {
        if ($question==='' || preg_match('//u',$question)!==1 || !is_int($limit) || $limit<1 || $limit>20) return null;
        if (!preg_match('/详情|明细|情况|具体/u',$question)) return null;
        if (MetricSemanticCatalog::uniqueTermInText($question,$allowedMetricCodes)!==null) return null;
        $matched=$this->objectsInText($question,$this->objects($objectVocabulary));
        if ($matched===null || count($matched)!==1 || $matched[0]['object_kind']!=='member') return null;
        $direction=$this->direction($question);
        if (!in_array($direction,['top','bottom'],true)) return null;
        $candidateCodes=[];
        foreach ($allowedMetricCodes as $code) {
            if (in_array('member',array_values(array_unique(array_filter(
                (array)($defaultRankObjectKinds[$code]??[]),'is_string'
            ))),true)) $candidateCodes[]=$code;
        }
        if (count($candidateCodes)!==1 || $this->memberPopulationDetailResidue(
            $question,$matched[0]['object_label']
        )!=='') return null;
        return [
            'ranking'=>['metric_code'=>$candidateCodes[0],'object_kind'=>'member',
                'direction'=>$direction,'limit'=>$limit],
            'detail'=>['view'=>'summary','target'=>'set','ordinal'=>null],
        ];
    }

    /**
     * @param array<int,array{object_kind:string,object_label:string}> $objectVocabulary
     * @param array<int,string> $allowedMetricCodes
     * @param array<string,array<int,string>> $defaultRankObjectKinds
     * @return array<int,array{metric_code:string,object_kind:string,direction:string,limit:int}>|null
     */
    public function match(string $question,array $objectVocabulary,array $allowedMetricCodes,array $defaultRankObjectKinds=[]): ?array
    {
        if ($question==='' || preg_match('//u',$question)!==1) return null;
        $objects=$this->objects($objectVocabulary);
        $shared=$this->sharedDefaultRanking($question,$objects,$allowedMetricCodes,$defaultRankObjectKinds);
        if ($shared!==null) return $shared;
        $clauses=preg_split('/[，,；;。]+/u',$question,-1,PREG_SPLIT_NO_EMPTY);
        if (!is_array($clauses) || $clauses===[] || count($clauses)>6) return null;

        $items=[];
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

    /**
     * A distributive list of registered objects may share one registry-owned
     * broad ranking perspective. The grammar proves only object order,
     * direction and distribution; the metric is admitted only when the
     * intersection of active registry defaults contains exactly one code.
     */
    private function sharedDefaultRanking(string $question,array $objects,array $allowedMetricCodes,array $defaults): ?array
    {
        if (!preg_match('/分别|各自/u',$question)) return null;
        if (MetricSemanticCatalog::uniqueTermInText($question,$allowedMetricCodes)!==null) return null;
        $matched=$this->objectsInText($question,$objects);
        if ($matched===null || count($matched)<2 || count($matched)>4) return null;
        $direction=$this->direction($question);
        if ($direction===null || $this->sharedDefaultResidue($question,array_column($matched,'object_label'))!=='') return null;
        $objectKinds=array_column($matched,'object_kind');$candidateCodes=[];
        foreach ($allowedMetricCodes as $code) {
            $kinds=array_values(array_unique(array_filter((array)($defaults[$code]??[]),'is_string')));
            if (array_diff($objectKinds,$kinds)===[]) $candidateCodes[]=$code;
        }
        if (count($candidateCodes)!==1) return null;
        return array_map(static function(array $object)use($candidateCodes,$direction):array{
            return ['metric_code'=>$candidateCodes[0],'object_kind'=>$object['object_kind'],
                'direction'=>$direction,'limit'=>1];
        },$matched);
    }

    /** Ordered unique registered objects; ambiguous labels fail closed. */
    private function objectsInText(string $text,array $objects): ?array
    {
        $byKind=[];$labelKinds=[];
        foreach ($objects as $object) {
            $label=$object['object_label'];$position=mb_strpos($text,$label,0,'UTF-8');
            if ($position===false) continue;
            $labelKinds[$label][$object['object_kind']]=true;
            $length=mb_strlen($label,'UTF-8');$current=$byKind[$object['object_kind']]??null;
            if ($current===null || $length>$current['length']) {
                $byKind[$object['object_kind']]=['object_kind'=>$object['object_kind'],
                    'object_label'=>$label,'position'=>$position,'length'=>$length];
            }
        }
        foreach ($labelKinds as $kinds) if (count($kinds)>1) return null;
        $matched=array_values($byKind);
        usort($matched,static function(array $left,array $right):int{return $left['position']<=>$right['position'];});
        return array_map(static function(array $item):array{
            return ['object_kind'=>$item['object_kind'],'object_label'=>$item['object_label']];
        },$matched);
    }

    /** Remove only closed broad-ranking grammar; any business residue rejects. */
    private function sharedDefaultResidue(string $question,array $objectLabels): string
    {
        $residue=$question;
        foreach ($objectLabels as $label) $residue=str_replace($label,' ',$residue);
        $residue=preg_replace('/最高|最多|最好|最大|最低|最少|最差|最小/u',' ',$residue);
        $residue=preg_replace('/这个月|这一个月|本月|这月|上个月|上月|今天|今日|昨天|昨日|前天/u',' ',$residue);
        $residue=preg_replace('/业绩|卖得/u',' ',$residue);
        $residue=preg_replace('/(?:的)?(?:是)?(?:哪一天|哪一日|哪天|哪个|哪一个|什么)(?:又)?|分别|各自|同时|以及|和|与|的|是|又|、|，|,|；|;|。|\s+/u','',$residue);
        return is_string($residue)?trim($residue):$question;
    }

    /** Remove only the closed member-ranking/detail presentation grammar. */
    private function memberPopulationDetailResidue(string $question,string $objectLabel): string
    {
        $residue=str_replace($objectLabel,' ',$question);
        $residue=preg_replace('/(?:前|后|最高|最低|最好|最差)(?:的)?\s*(?:[0-9]+|[一二两三四五六七八九十百]+)\s*(?:名)?/u',' ',$residue);
        $residue=preg_replace('/最高|最多|最好|最大|最低|最少|最差|最小/u',' ',$residue);
        $residue=preg_replace('/这个月|这一个月|本月|这月|上个月|上月|今天|今日|昨天|昨日|前天/u',' ',$residue);
        $residue=preg_replace('/业绩|详情|明细|具体情况|情况/u',' ',$residue);
        $residue=preg_replace('/(?:的)?(?:是)?(?:哪些|哪个|哪一个|什么)|分别|各自|同时|以及|和|与|的|是|看看|查看|看|给我|、|，|,|；|;|。|\s+/u','',$residue);
        return is_string($residue)?trim($residue):$question;
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
