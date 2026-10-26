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
    /**
     * Admit only a self-contained categorical question with no unrecognized
     * predicate. A caller may merge signed scope into that complete request;
     * incomplete follow-ups must use typed semantic understanding instead.
     */
    public function match(string $question): ?array
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
