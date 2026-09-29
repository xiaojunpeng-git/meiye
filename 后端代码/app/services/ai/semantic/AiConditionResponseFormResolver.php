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

    /**
     * Prove that a member-count sentence contains exactly one stated monetary
     * predicate. This is a narrow safety gate for discarding provider-invented
     * metric projections: the metric owner is supplied by the registry, while
     * the number and comparison must agree with the customer's actual words.
     * Any unconsumed wording, second number, or incomplete calendar expression
     * leaves the whole request with the ordinary model/contract path.
     */
    public static function closedMemberMoneyCount(string $question,string $registeredTerm,array $predicate): bool
    {
        $parsed=self::parseClosedMemberMoneyCount($question,$registeredTerm);
        return $parsed!==null && ($predicate['unit']??null)===$parsed['unit']
            &&($predicate['operator']??null)===$parsed['operator']
            &&($predicate['quantity']??null)===$parsed['quantity'];
    }

    /**
     * Parse only the customer's complete count sentence; caller supplies an
     * exact registry term and later verifies its published member capability.
     * This method never assigns a metric code, permission or answer value.
     * Null means an independent phrase may exist and the model must decide.
     *
     * @return array{operator:string,quantity:string,unit:string}|null
     */
    public static function parseClosedMemberMoneyCount(string $question,string $registeredTerm): ?array
    {
        if (self::resolve($question)!=='count' || $registeredTerm==='') return null;
        $calendar=AiSemanticIntentParser::calendarEvidence($question);
        if (($calendar['complete']??false)!==true || count($calendar['periods']??[])>1) return null;
        $remaining=preg_replace('/[\s，,。？?！!]/u','',(string)$calendar['remainder']);
        $countPattern='/(?:有)?多少(?:个|位|名)?(?:人|会员|客户|顾客)|(?:有)?几(?:个|位|名)(?:人|会员|客户|顾客)/u';
        preg_match_all($countPattern,$remaining,$countMatches);
        if (count($countMatches[0]??[])!==1) return null;
        $remaining=str_replace($countMatches[0][0],'',$remaining);
        $termPosition=mb_strpos($remaining,$registeredTerm,0,'UTF-8');
        if ($termPosition===false || mb_strpos($remaining,$registeredTerm,$termPosition+mb_strlen($registeredTerm,'UTF-8'),'UTF-8')!==false) return null;
        $remaining=mb_substr($remaining,0,$termPosition,'UTF-8')
            .mb_substr($remaining,$termPosition+mb_strlen($registeredTerm,'UTF-8'),null,'UTF-8');
        $comparison='大于等于|不低于|不少于|至少|小于等于|不高于|不超过|至多|大于|超过|高于|多于|小于|低于|少于|等于|恰好|刚好|正好|为|是';
        preg_match_all('/(?<operator>'.$comparison.')?(?<quantity>[0-9]+(?:\.[0-9]{1,2})?)(?:元|块)?/u',$remaining,$amountMatches,PREG_SET_ORDER);
        if (count($amountMatches)!==1) return null;
        $operator=$amountMatches[0]['operator']??'';
        $operators=['大于等于'=>'gte','不低于'=>'gte','不少于'=>'gte','至少'=>'gte',
            '小于等于'=>'lte','不高于'=>'lte','不超过'=>'lte','至多'=>'lte',
            '大于'=>'gt','超过'=>'gt','高于'=>'gt','多于'=>'gt',
            '小于'=>'lt','低于'=>'lt','少于'=>'lt'];
        $normalizedOperator=$operators[$operator]??'eq';
        $remaining=str_replace($amountMatches[0][0],'',$remaining);
        // Only population nouns and connective words may remain. Unknown
        // conditions such as points, refunds, or a second amount stay visible.
        $remaining=preg_replace('/(?:会员|客户|顾客|人数|人|的|一共|共有|总共)/u','',$remaining);
        if ($remaining!=='') return null;
        return ['operator'=>$normalizedOperator,'quantity'=>$amountMatches[0]['quantity'],'unit'=>'yuan'];
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
