<?php
namespace app\services\ai\semantic;

/** Source-owned business language resources. No formulas, SQL or report page identities. */
final class AiSemanticVocabulary
{
    public const VERSION='mohe-semantic-vocabulary-r5-v3';
    public static function patterns(): array
    {
        return [
            'cash_performance'=>'/现金业绩|收了多少钱|收了多少款|收款金额|收得怎么样/u',
            'consume_amount'=>'/消耗业绩/u',
            'actual_performance'=>'/实际业绩/u',
            'service_metric_ambiguity'=>'/服务业绩|耗卡业绩|扣卡业绩/u',
            'unavailable_metric'=>'/劳动业绩|销售额|销售金额|销售数量|服务数量|退款业绩|退款金额|净收款|净利润|毛利润|毛利率|利润|储值扣款|余额扣款|工资|手工费|转化率|增长(?:了)?|增长率|增幅|差额|赚了多少钱|赚了多少/u',
            'unavailable_count'=>'/服务了?多少人|服务了?几个人|多少人次|多少次|几次|多少件|几件|几笔|订单数|订单|项目数|会员数|人数|人次/u',
            'unavailable_domain'=>'/库存|仓库|临期|过期|未耗|没消耗|没做|目标|培训|课程|必修课|考核|教培|会员|客户|老客|新客/u',
            'person_filter'=>'/销售人|手艺人|操作人|员工|店长|岗位|老师/u',
            'category_filter'=>'/商品分类|分类|品项|项目|产品|生美|私密|花园/u',
            'source_filter'=>'/合作方|渠道|来源/u',
            'exclusion'=>'/排除|不含|不包括|不要|不是|除了|仅限|只看/u',
            'page_reference'=>'/按这张表|按这个图|这张表|这个图|这批|当前页面/u',
            'history_point'=>'/截至|截止|月末|年末|首年|累计|自然年|今年|去年/u',
            'cash_performance_short'=>'/现金/u',
            'consume_amount_short'=>'/消耗/u',
            'income_ambiguity'=>'/收入/u',
            'sales_ambiguity'=>'/卖了多少/u',
            'ambiguous_metric'=>'/业绩|做了多少|经营情况/u',
            'definition'=>'/是什么意思|什么意思|指什么|是什么|怎么理解|口径说明|统计口径|解释/u',
            'trend'=>'/每天|每日|逐日|按天|趋势/u',
            'comparison'=>'/对比|相比|比较|分别|并排/u',
            'summary'=>'/汇总|合计|总额/u',
            'ranking'=>'/排行|排名|哪几家店|哪些门店/u',
            'attention_goal'=>'/需要关注|值得关注|经营健康|健康/u',
            'rank_top'=>'/最高|最好|从高到低/u',
            'rank_bottom'=>'/最低|最差|倒数|从低到高/u',
            'current_store'=>'/本店|店里/u',
            'xlsx'=>'/Excel|excel|EXCEL|表格/u',
            'original_result'=>'/刚才的答案|刚才的数据|原答案|条件不变|其他条件别动/u',
        ];
    }
    public static function fingerprint(): string
    {
        return hash('sha256',self::VERSION.json_encode(self::patterns(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
}
