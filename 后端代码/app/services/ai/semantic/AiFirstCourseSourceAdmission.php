<?php
namespace app\services\ai\semantic;

/**
 * Bounded grammar for a complete source/member cohort question. It only
 * identifies structural slots; directory IDs, dates, grants and facts remain
 * owned by their existing server-side contracts. Unrecognized residue returns
 * null for normal semantic understanding rather than dropping a condition.
 */
final class AiFirstCourseSourceAdmission
{
    public function match(string $question,bool $hasPriorSource=false): ?array
    {
        if ($question==='' || preg_match('//u',$question)!==1) return null;
        $calendar=AiSemanticIntentParser::calendarEvidence($question);
        if (!$calendar['complete'] || count($calendar['periods'])>1) return null;
        $clean=(string)preg_replace('/[\s？?。，,！!：:、“”"（）()]/u','',$calendar['remainder']);
        $clean=(string)preg_replace('/^(?:请问|请帮我|帮我|麻烦|查一下|查询|统计一下|统计|看一下|看看)+/u','',$clean);
        if (preg_match('/^(?:来源|渠道)(.+?)(?:的)?(?:会员|客户)(?:有|共|一共|总共)?(几个|多少(?:个|位)?人|多少|有哪些|哪些|名单|详情)$/uD',$clean,$match)) {
            $term=(string)preg_replace('/的$/u','',$match[1]);
            if (!$this->validTerm($term)) return null;
            $form=$this->form($match[2]);
            return ['source_term'=>$term,'period'=>$calendar['periods'][0]??null,
                'form'=>$form,'detail'=>$match[2]==='详情'];
        }
        if (!$hasPriorSource) return null;
        if ($calendar['periods']===[] && preg_match('/^(?:换成|改成|再看|看)?(?:来源|渠道)(.+?)(?:呢)?$/uD',$clean,$match)) {
            $term=(string)preg_replace('/的$/u','',$match[1]);
            return $this->validTerm($term)
                ?['source_term'=>$term,'period'=>null,'form'=>null,'detail'=>false]:null;
        }
        if ($calendar['periods']===[] && preg_match('/^(?:这(?:些|批))?(?:会员|客户)(?:的)?(详情|名单|有哪些|哪些|有几个|多少人)$/uD',$clean,$match)) {
            return ['source_term'=>null,'period'=>null,'form'=>$this->form($match[1]),
                'detail'=>$match[1]==='详情'];
        }
        return null;
    }

    private function form(string $word): string
    {
        return in_array($word,['详情','名单','有哪些','哪些'],true)?'list':'count';
    }

    /** A source slot cannot swallow a second business predicate. */
    private function validTerm(string $term): bool
    {
        return $term!=='' && mb_strlen($term)<=80
            && !preg_match('/并且|或者|同时|而且|消费|业绩|金额|(?:大于|小于|超过|至少|不超过)[0-9]|[0-9]+元/u',$term);
    }
}
