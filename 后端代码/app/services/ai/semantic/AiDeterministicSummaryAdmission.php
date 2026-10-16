<?php
namespace app\services\ai\semantic;

/**
 * Proves that an entire question is one registered store metric and one explicit
 * period in ordinary summary form. A partial phrase match is never executable:
 * every remaining business word sends the question to natural-language understanding.
 */
final class AiDeterministicSummaryAdmission
{
    private const DATE_PATTERN='/(?:这一个月|这个月|上个月|今天|今日|昨天|昨日|前天|本月|这月|上月|(?<![0-9])[0-9]{4}-[0-9]{2}-[0-9]{2}(?![0-9]))/u';

    /** @return array{metric_code:string,date_term:array}|null */
    public function match(string $question, array $entries): ?array
    {
        if ($question==='' || preg_match('//u',$question)!==1) return null;

        // Use all registered owners before checking this account's capabilities.
        // A hidden or unfinished owner must not make an ambiguous name unique.
        $owners=[];
        foreach ($entries as $code=>$entry) foreach ((array)($entry['terms']??[]) as $term) {
            if (is_string($code) && is_string($term) && $term!=='') $owners[$term][$code]=true;
        }
        if ($owners===[]) return null;
        $terms=array_keys($owners);
        usort($terms,static function(string $a,string $b):int {
            return strlen($b)<=>strlen($a);
        });
        $metricPattern='/'.implode('|',array_map(static function(string $term):string {
            return preg_quote($term,'/');
        },$terms)).'/u';
        if (preg_match_all($metricPattern,$question,$metricMatches,PREG_OFFSET_CAPTURE)!==1) return null;
        [$metricTerm,$metricOffset]=$metricMatches[0][0];
        if (count($owners[$metricTerm])!==1) return null;
        $metricCode=array_key_first($owners[$metricTerm]);

        // The accepted calendar vocabulary is deliberately narrow. The shared
        // workflow planner still owns date normalization and range validation.
        if (preg_match_all(self::DATE_PATTERN,$question,$dateMatches,PREG_OFFSET_CAPTURE)!==1) return null;
        [$dateWord,$dateOffset]=$dateMatches[0][0];
        $metricEnd=$metricOffset+strlen($metricTerm);
        $dateEnd=$dateOffset+strlen($dateWord);
        if ($metricOffset<$dateEnd && $dateOffset<$metricEnd) return null;
        $dateTerm=$this->dateTerm($dateWord);

        // Remove only the two proved spans. Conjunctions, comparisons, people,
        // store names and other effective conditions remain visible and fail
        // this small grammar, so the model can understand the complete request.
        $spans=[[$metricOffset,strlen($metricTerm)],[$dateOffset,strlen($dateWord)]];
        usort($spans,static function(array $a,array $b):int {return $b[0]<=>$a[0];});
        $remainder=$question;
        foreach ($spans as [$offset,$length]) $remainder=substr_replace($remainder,'',$offset,$length);
        $remainder=(string)preg_replace('/[\s？?。，,！!：:、“”"（）()]/u','',$remainder);
        if (preg_match('/^(?:(?:我想知道|我想了解|请问|请|帮我|麻烦|给我|查一下|查询|看一下|看看|看|查|告诉我|统计一下|统计))*(?:的)?(?:有|是|为)?(?:多少|多少钱|数值|金额)?(?:呢|呀|吗)?$/uD',$remainder)!==1) return null;

        return ['metric_code'=>$metricCode,'date_term'=>$dateTerm];
    }

    /**
     * Proves one complete summary question with one or more registered metrics
     * and, optionally, one exact authorized store. Every recognized span is
     * removed before a closed grammar check, so unknown filters, comparisons
     * and fuzzy store names cannot enter execution.
     *
     * @return array{metric_codes:array<int,string>,date_term:array,store_id?:int,store_label?:string}|null
     */
    public function matchQuery(string $question,array $entries,array $storeObjects=[],int $maxMetrics=12): ?array
    {
        if ($question==='' || preg_match('//u',$question)!==1 || $maxMetrics<1) return null;
        $metricSpans=$this->metricSpans($question,$entries);
        if ($metricSpans===[]) return null;
        $metricCodes=[];
        foreach ($metricSpans as $span) if (!in_array($span['code'],$metricCodes,true)) $metricCodes[]=$span['code'];
        if (count($metricCodes)>$maxMetrics) return null;

        // Exactly one date prevents separate per-metric windows from being
        // flattened. Normalization still belongs to the workflow planner.
        if (preg_match_all(self::DATE_PATTERN,$question,$dateMatches,PREG_OFFSET_CAPTURE)!==1) return null;
        [$dateWord,$dateOffset]=$dateMatches[0][0];
        $dateSpan=['offset'=>$dateOffset,'length'=>strlen($dateWord)];
        foreach ($metricSpans as $span) if ($this->overlaps($span,$dateSpan)) return null;

        $storeSpan=$this->storeSpan($question,$storeObjects);
        if ($storeSpan!==null) {
            if ($this->overlaps($storeSpan,$dateSpan)) return null;
            foreach ($metricSpans as $span) if ($this->overlaps($span,$storeSpan)) return null;
        }

        $spans=array_merge($metricSpans,[$dateSpan],$storeSpan===null?[]:[$storeSpan]);
        usort($spans,static function(array $left,array $right):int {return $right['offset']<=>$left['offset'];});
        $remainder=$question;
        foreach ($spans as $span) $remainder=substr_replace($remainder,'',$span['offset'],$span['length']);
        $remainder=(string)preg_replace('/[\s？?。，,！!：:;；、“”"()（）]/u','',$remainder);
        // Only presentation glue is disposable. Any remaining noun or
        // operator is an unproved condition and must stay model work.
        $glue='(?:我想知道|我想了解|请问|请|帮我|麻烦|给我|查一下|查询|看一下|看看|看|查|告诉我|统计一下|统计|的|有|是|为|多少钱|多少|数值|金额|分别|各自|以及|还有|和|与|及|呢|呀|吗|么)*';
        if (preg_match('/^'.$glue.'$/uD',$remainder)!==1) return null;

        $result=['metric_codes'=>$metricCodes,'date_term'=>$this->dateTerm($dateWord)];
        if ($storeSpan!==null) {
            $result['store_id']=$storeSpan['store_id'];
            $result['store_label']=$storeSpan['label'];
        }
        return $result;
    }

    /** A closed calendar-only turn may change only the period of a signed query. */
    public function periodOnly(string $question): ?array
    {
        if ($question==='' || preg_match('//u',$question)!==1
            || preg_match_all(self::DATE_PATTERN,$question,$matches,PREG_OFFSET_CAPTURE)!==1) return null;
        [$word,$offset]=$matches[0][0];
        $remainder=substr_replace($question,'',$offset,strlen($word));
        $remainder=(string)preg_replace('/[\s？?。，,！!：:、“”"（）()]/u','',$remainder);
        // Only presentation words are accepted. Business objects, metrics,
        // comparisons and incomplete conjunctions remain model work.
        if (preg_match('/^(?:(?:那|再|继续|继续看|再看|看|换成|改成|改为|查|查一下|看看|这个|的|呢|呀|吗))*$/uD',$remainder)!==1) return null;
        return $this->dateTerm($word);
    }

    /** Date codes are resolved by the existing workflow planner, not here. */
    private function dateTerm(string $word): array
    {
        $codes=['今天'=>'TODAY','今日'=>'TODAY','昨天'=>'YESTERDAY','昨日'=>'YESTERDAY',
            '前天'=>'DAY_BEFORE_YESTERDAY','本月'=>'THIS_MONTH','这月'=>'THIS_MONTH',
            '这个月'=>'THIS_MONTH','这一个月'=>'THIS_MONTH','上月'=>'LAST_MONTH','上个月'=>'LAST_MONTH'];
        return isset($codes[$word])?['code'=>$codes[$word]]
            :['code'=>'EXPLICIT','start'=>$word,'end'=>$word];
    }

    /** @return array<int,array{offset:int,length:int,code:string}> */
    private function metricSpans(string $question,array $entries): array
    {
        // All registered owners participate before account capabilities are
        // checked. A hidden owner must not make an ambiguous word unique.
        $owners=[];
        foreach ($entries as $code=>$entry) foreach ((array)($entry['terms']??[]) as $term) {
            if (is_string($code) && is_string($term) && $term!=='') $owners[$term][$code]=true;
        }
        $candidates=[];
        foreach ($owners as $term=>$codes) {
            if (count($codes)!==1) continue;
            // Date-bearing legacy aliases such as “本月业绩” must not
            // consume the period span. The shorter registered metric term and
            // the explicit date are proved independently instead.
            if (preg_match(self::DATE_PATTERN,$term)===1) continue;
            if (preg_match_all('/'.preg_quote($term,'/').'/u',$question,$matches,PREG_OFFSET_CAPTURE)===false) return [];
            foreach ($matches[0] as [$matched,$offset]) $candidates[]=['offset'=>$offset,'length'=>strlen($matched),
                'code'=>(string)array_key_first($codes)];
        }
        usort($candidates,static function(array $left,array $right):int {
            return [$right['length'],-$left['offset']]<=>[$left['length'],-$right['offset']];
        });
        $selected=[];
        foreach ($candidates as $candidate) {
            $overlap=false;
            foreach ($selected as $span) if ($this->overlaps($candidate,$span)) {$overlap=true;break;}
            if (!$overlap) $selected[]=$candidate;
        }
        usort($selected,static function(array $left,array $right):int {return $left['offset']<=>$right['offset'];});
        return $selected;
    }

    /** @return array{offset:int,length:int,store_id:int,label:string}|null */
    private function storeSpan(string $question,array $storeObjects): ?array
    {
        $owners=[];$labels=[];
        foreach ($storeObjects as $object) {
            if (!is_array($object) || !preg_match('/^store:([1-9][0-9]*)$/D',(string)($object['ref']??''),$match)) continue;
            $label=trim((string)($object['label']??''));
            foreach (array_merge([$label],(array)($object['aliases']??[])) as $term) {
                if (!is_string($term) || trim($term)==='') continue;
                $term=trim($term);$owners[$term][(int)$match[1]]=true;$labels[$term]=$label;
            }
        }
        $candidates=[];
        foreach ($owners as $term=>$ids) {
            // Duplicate labels require a choice; never pick either store here.
            if (count($ids)!==1) continue;
            if (preg_match_all('/'.preg_quote($term,'/').'/u',$question,$matches,PREG_OFFSET_CAPTURE)===false) return null;
            foreach ($matches[0] as [$matched,$offset]) $candidates[]=['offset'=>$offset,'length'=>strlen($matched),
                'store_id'=>(int)array_key_first($ids),'label'=>$labels[$term]];
        }
        if ($candidates===[]) return null;
        usort($candidates,static function(array $left,array $right):int {
            return [$right['length'],-$left['offset']]<=>[$left['length'],-$right['offset']];
        });
        $selected=[];
        foreach ($candidates as $candidate) {
            $overlap=false;
            foreach ($selected as $span) if ($this->overlaps($candidate,$span)) {$overlap=true;break;}
            if (!$overlap) $selected[]=$candidate;
        }
        $stores=[];
        foreach ($selected as $span) $stores[$span['store_id']]=true;
        return count($stores)===1?$selected[0]:null;
    }

    private function overlaps(array $left,array $right): bool
    {
        return $left['offset']<$right['offset']+$right['length']
            && $right['offset']<$left['offset']+$left['length'];
    }
}
