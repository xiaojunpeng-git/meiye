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
        $count=(bool)preg_match('/(?:'.$objects.'.{0,8}(?:有)?(?:多少(?:个|位|名|家|项|笔|条|人)?|几个|几位|几名|几家)|(?:多少(?:个|位|名|家|项|笔|条|人)?|几个|几位|几名|几家).{0,8}'.$objects.')/u',$text);
        return $list===$count?null:($list?'list':'count');
    }
}
