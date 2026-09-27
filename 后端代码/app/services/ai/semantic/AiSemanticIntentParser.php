<?php
namespace app\services\ai\semantic;

/** Local semantic slots. No raw spans, names, formulae or business values leave this parser. */
final class AiSemanticIntentParser
{
    public function parse(string $text): array
    {
        // Presentation/operation negation is not a business-data exclusion. Match
        // complete clauses first; e.g. "不含退款" remains a real constraint.
        $text=preg_replace('/(?:先)?不查(?:钱数|金额)(?:[，,、]\s*)?|不用(?:计算|算)(?:差额|增幅)[。.]?|不按当前页面(?:的)?筛选[。.]?/u',' ',$text);
        $signals=[]; $covered=$text; $constraints=[];
        $limits=[];
        preg_match_all('/(?:前|后|最高|最低|最好|最差)(?:的)?\s*([0-9]+|[一二两三四五六七八九十百]+)\s*(?:名)?|([0-9]+|[一二两三四五六七八九十百]+)\s*家/u',$text,$counts,PREG_SET_ORDER);
        foreach($counts as $count) {
            if (!preg_match('/排行|排名|门店|家店|倒数|(?:前|后|最高|最低|最好|最差)(?:的)?\s*[一二两三四五六七八九十0-9]+\s*(?:名)?(?![天日月年号])/u',$text)) continue;
            $raw=$count[1]!==''?$count[1]:($count[2]??'');$limits[]=$this->number($raw);
            if(preg_match('/前|最高|最好/u',$count[0])) $signals[]='rank_top';
            if(preg_match('/后|最低|最差/u',$count[0])) $signals[]='rank_bottom';
            $covered=str_replace($count[0],' ',$covered);
        }
        $take=static function(string $pattern,string $code) use (&$covered,&$signals): bool {
            if (!preg_match($pattern,$covered)) return false;
            $signals[]=$code; $covered=preg_replace($pattern,' ',$covered); return true;
        };
        // Specific meanings precede generic wording; unsupported meanings stay
        // explicit constraints rather than disappearing into an available metric.
        require_once __DIR__.'/AiSemanticVocabulary.php';
        $patterns=AiSemanticVocabulary::patterns();
        foreach($patterns as $code=>$pattern) $take($pattern,$code);
        $relative=['这个月'=>'THIS_MONTH','这一个月'=>'THIS_MONTH','上个月'=>'LAST_MONTH','本月'=>'THIS_MONTH','这月'=>'THIS_MONTH','上月'=>'LAST_MONTH','今天'=>'TODAY','今日'=>'TODAY','昨天'=>'YESTERDAY','昨日'=>'YESTERDAY','前天'=>'DAY_BEFORE_YESTERDAY','明天'=>'TOMORROW'];
        // Normalize date synonyms only locally. Original text is never retained.
        $normalized=strtr($text,['这个月'=>'本月','这一个月'=>'本月','上个月'=>'上月','今日'=>'今天','昨日'=>'昨天']);
        // Consume full calendar spans before individual relative words so the
        // start of a connected interval cannot become an unresolved business filter.
        $covered=self::calendarEvidence($covered)['remainder'];
        foreach($relative as $word=>$code) if (strpos($text,$word)!==false) $signals[]=$code;
        preg_match_all('/(?<![0-9])[0-9]{4}-[0-9]{2}-[0-9]{2}(?![0-9])/',$text,$dates);
        if(in_array('ranking',$signals,true)) {
            $covered=str_replace(['名单','家店','门店','家','名'],' ',$covered);
        }
        $limits=array_values(array_unique($limits));
        foreach(['person_filter','category_filter','source_filter','exclusion','page_reference','history_point'] as $type) if(in_array($type,$signals,true)) $constraints[]=['type'=>$type,'status'=>'not_bound'];
        if (in_array('attention_goal',$signals,true) || in_array('ranking',$signals,true)) $covered=str_replace(['门店','哪家店','哪家','家店','家','哪','经营','做得'],' ',$covered);
        // Only grammar/phrasing is discarded. Unparsed content is represented
        // separately from known capability gaps, and never becomes no filter.
        // Calendar edit verbs are grammar, not new business conditions. Keep
        // them in this shared cleanup list so "change it to today" can use
        // the same closed date-only continuation as "today?" without adding
        // a question-specific gateway branch.
        $covered=preg_replace('/(?:午休前|下班前)?核对一下|请帮我|麻烦帮我|麻烦|帮忙|帮我|我想知道|我想了解|我想查|查询|查一下|查查|先不查钱数|看一下|看看|看|多少钱|多少|怎么样|当前权限范围|相同日期|同样日期|上述日期|原日期|生成|导出|一份|数据|情况|一下|请问|请|问|的|是|有|和|与|到|至|从|及|把|给我|呢|那|换成|改成|改为|同时|再|继续|了|吗|列出|列|附|分成两项|分成|两项|展示|显示|都要|各|一起|名单|金额|这个指标|结果|也要|还要|放上|成|就|还是|查|按|[\s？?。，,、！!：:“”"（）()]/u','',$covered);
        $covered=str_replace('为','',$covered);
        $unparsed=$covered!=='';
        if($unparsed) $constraints[]=['type'=>'unparsed_business_condition','status'=>'unresolved'];
        $signals=array_values(array_unique($signals));
        $limit=count($limits)===1?$limits[0]:null;
        if($limit===5 && !in_array('exclusion',$signals,true)) {
            if(in_array('rank_top',$signals,true)) $signals[]='top_5';
            if(in_array('rank_bottom',$signals,true)) $signals[]='bottom_5';
        }
        $blocking=null;
        foreach($constraints as $constraint) if($constraint['type']!=='unparsed_business_condition') $blocking='AI_CAPABILITY_NOT_READY';
        if(!$blocking && $unparsed) $blocking='AI_INTENT_UNRESOLVED';
        // Legacy store-only compact routing keeps its historic fixed five
        // contract. Object dimensions bypass it and use model result shapes.
        if($limits && ($limits!==[5])) $blocking='AI_RANK_LIMIT_NOT_READY';
        if(in_array('TOMORROW',$signals,true)) $blocking='AI_FUTURE_ACTUALS_UNAVAILABLE';
        $calendar=self::calendarEvidence($normalized);
        $periods=$calendar['periods'];
        if(count($periods)===2 && preg_match('/分别|并排|各.*昨天|各.*今天/u',$text) && !in_array('comparison',$signals,true)) $signals[]='comparison';
        return ['signals'=>array_values(array_unique($signals)),'dates'=>$dates[0],'blocking_reason'=>$blocking,
            'date_terms'=>$periods,'date_grouping_ambiguous'=>!$calendar['complete'],
            'unresolved_condition'=>$unparsed,'projection_version'=>'mohe-semantic-intent-v2',
            // “最高/最低”只说明取值方式，不能单独决定按门店、日期或业务对象分组。
            // 只有客户明确表达“排行/排名”时，辅助投影才保留通用排名目标；完整对象仍由模型理解并经契约核对。
            'semantic_intent'=>['version'=>2,'goal'=>in_array('definition',$signals,true)?'metric_definition':(in_array('ranking',$signals,true)?'object_metric_ranking':'business_results'),
                'constraints'=>$constraints,'rank_limits'=>$limits,'rank_limit'=>$limit,
                'status'=>$unparsed?'unresolved':($constraints?'understood_unavailable':'understood')]];
    }

    private function number(string $text): int
    {
        if(ctype_digit($text)) return strlen($text)>6?1000000:(int)$text;
        $digits=['一'=>1,'二'=>2,'两'=>2,'三'=>3,'四'=>4,'五'=>5,'六'=>6,'七'=>7,'八'=>8,'九'=>9,'十'=>10];
        if(isset($digits[$text])) return $digits[$text];
        if(preg_match('/^([一二三四五六七八九])?十([一二三四五六七八九])?$/u',$text,$m)) return ($digits[$m[1]??'']??1)*10+($digits[$m[2]??'']??0);
        return 1000000;
    }

    /** Connected endpoints form one interval; unrecognised temporal residue
     * disables deterministic correction rather than silently dropping a bound.
     * Ranking and model correction consume the same calendar evidence. */
    public static function calendarEvidence(string $text): array
    {
        $text=strtr($text,['这一个月'=>'本月','这个月'=>'本月','上个月'=>'上月','今日'=>'今天','昨日'=>'昨天','至今为止'=>'至今天','至今'=>'至今天']);
        $number='[0-9一二两三四五六七八九十]+';
        $endpoint='(?:今天|昨天|前天|明天|本月|这月|上月|最近'.$number.'天|(?<![0-9])[0-9]{4}-[0-9]{2}-[0-9]{2}(?![0-9])|(?:[0-9]{4}年|今年|去年)?'.$number.'月(?:份)?(?:'.$number.'[日号])?)';
        $pattern='/(?:从|自)?(?<start>'.$endpoint.')(?:\s*(?:开始)?\s*(?:一直到|截至|截止到|到|至)\s*(?<end>'.$endpoint.'|现在|目前))?(?:为止)?/u';
        $terms=[];$codes=['今天'=>'TODAY','昨天'=>'YESTERDAY','前天'=>'DAY_BEFORE_YESTERDAY','明天'=>'TOMORROW','本月'=>'THIS_MONTH','这月'=>'THIS_MONTH','上月'=>'LAST_MONTH'];
        $remainder=preg_replace_callback($pattern,static function(array $m) use(&$terms,$codes): string {
            $start=$m['start'];$end=$m['end']??'';
            if ($end!=='') $terms[]=['code'=>'CALENDAR_DATES','start'=>$start,'end'=>$end];
            elseif (isset($codes[$start])) $terms[]=['code'=>$codes[$start]];
            elseif (preg_match('/^最近(.+)天$/u',$start,$rolling)) $terms[]=['code'=>'ROLLING_DAYS','days'=>(new self())->number($rolling[1])];
            else $terms[]=['code'=>strpos($start,'月')!==false?'CALENDAR_DATES':'EXPLICIT','start'=>$start,'end'=>$start];
            return '【日期】';
        },$text);
        // Unsupported calendar qualifiers must retain model ownership. The
        // recognised endpoint alone cannot prove a complete customer interval.
        $remaining=str_replace('【日期】',' ',$remainder);
        $complete=!preg_match('/年|月|日|号|季度|星期|周|半年|以来|至今|截至|截止|上旬|中旬|下旬|月初|月底/u',$remaining)
            && !preg_match('/(?:到|至)\s*【日期】|【日期】\s*(?:初|底|末|到|至)|(?:从|自)\s*$/u',$remainder);
        return ['periods'=>$terms,'complete'=>$complete,'remainder'=>$remaining];
    }
}
