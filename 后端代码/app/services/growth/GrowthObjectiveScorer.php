<?php
declare(strict_types=1);
namespace app\services\growth;
use mohe\exceptions\AdminException;

/** 考试、练习和随堂题共用客观题口径；多选全对，顺序题全部选齐且逐位一致才得分。 */
final class GrowthObjectiveScorer
{
    /** 校验选项有效性；顺序题保留点击顺序并拒绝重复，其他题按自身选择规则归一。 */
    public static function normalize(array $q,$value): array
    {
        if(!is_array($value))throw new AdminException('客观题答案须为选项列表');$actual=[];foreach($value as $index){if(filter_var($index,FILTER_VALIDATE_INT)===false||!array_key_exists((int)$index,$q['options']))throw new AdminException('答案选项无效');$actual[]=(int)$index;}
        if($q['type']==='order'){if(count(array_unique($actual))!==count($actual))throw new AdminException('顺序题不能重复选择同一个选项');}
        else{$actual=array_values(array_unique($actual));if($q['type']!=='multiple'&&count($actual)>1)throw new AdminException('单选或判断不能提交多个答案');sort($actual);}return $actual;
    }
    /** 多选须集合完全一致，顺序题须完整逐位一致，任一错误均不计本题分。 */
    public static function score(array $q,$value): int
    {
        $actual=self::normalize($q,$value);$expected=array_values(array_map('intval',(array)$q['answer']));if($q['type']!=='order')sort($expected);return $actual===$expected?(int)$q['score']:0;
    }
}
