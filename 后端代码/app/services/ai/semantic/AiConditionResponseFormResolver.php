<?php
namespace app\services\ai\semantic;

/**
 * Resolves only an explicitly stated population answer form (list or count).
 * It does not choose the population, metric, predicate, period or authority.
 * Ambiguous or mixed wording deliberately remains model-owned.
 */
final class AiConditionResponseFormResolver
{
    public static function resolve(string $text): ?string
    {
        if ($text==='' || preg_match('//u',$text)!==1) return null;
        $objects='(?:会员|客户|顾客|员工|人员|门店|店铺|订单|项目|产品|商品|卡项|记录)';
        $list=(bool)preg_match('/(?:'.$objects.'.{0,8}(?:有哪些|是哪些|都有谁|是谁|名单)|(?:哪些|哪几个|哪几位|哪几名|哪几家).{0,8}'.$objects.'|列出.{0,12}'.$objects.')/u',$text);
        $count=(bool)preg_match('/'.$objects.'.{0,12}(?:有多少|多少个|多少位|多少名|几个|几位|几名|几家)|(?:有多少|多少个|多少位|多少名|几个|几位|几名|几家).{0,12}'.$objects.'/u',$text)
            ||self::countPhrases($text)!==[];
        return $list===$count?null:($list?'list':'count');
    }

    /**
     * A model may label words from an explicit population-count question as
     * a second measurement. Only an unregistered term wholly inside this
     * response-form phrase is eligible for removal by the condition contract;
     * the predicate, object and any independently stated metric stay intact.
     */
    public static function countPhraseContains(string $question,string $term): bool
    {
        if ($term==='') return false;
        foreach (self::countPhrases($question) as $phrase) {
            if (mb_strpos($phrase,$term,0,'UTF-8')!==false) return true;
        }
        return false;
    }

    /** @return array<int,string> Explicit count-form excerpts, never metric evidence. */
    private static function countPhrases(string $text): array
    {
        if ($text==='' || preg_match('//u',$text)!==1) return [];
        // Keep only the answer-form words. A business measurement between an
        // object and “多少人” must never be swallowed with the count phrase.
        preg_match_all('/(?:有)?多少(?:个|位|名)?人|(?:有)?几个|几位|几名|几家/u',$text,$matches);
        return $matches[0]??[];
    }
}
