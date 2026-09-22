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
}
