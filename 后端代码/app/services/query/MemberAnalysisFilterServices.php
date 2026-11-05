<?php
declare(strict_types=1);
namespace app\services\query;

/** 双端会员分析的封闭条件契约：仅允许范围值，永不接受字段名、SQL或客户端权限。 */
final class MemberAnalysisFilterServices
{
    public const FIELDS = [
        'cashPerformance'=>'现金业绩', 'consumeCount'=>'消费次数', 'visitCount'=>'到店次数',
        'totalAssets'=>'总资产', 'remainingTimes'=>'剩余权益总次数', 'remainingAmount'=>'剩余权益总金额',
        'consumePeriod'=>'最近有消费', 'visitPeriod'=>'最近有到店', 'noVisitPeriod'=>'最近无到店',
    ];

    /** 金额输入保留元精度；日期必须完整成对，非法条件不得被悄悄忽略。 */
    public static function normalize($input): array
    {
        if (!is_array($input) || array_diff(array_keys($input), array_keys(self::FIELDS))) self::fail('分析筛选条件无效。');
        $out=[];
        foreach (self::FIELDS as $key=>$label) {
            if (!array_key_exists($key,$input)) continue;
            $range=$input[$key];
            if (!is_array($range) || array_diff(array_keys($range),['min','max'])) self::fail($label.'范围无效。');
            $period=substr($key,-6)==='Period'; $values=[];
            foreach (['min','max'] as $bound) {
                $value=$range[$bound]??'';
                if (!is_string($value) && !is_int($value)) self::fail($label.'范围无效。');
                $value=trim((string)$value);
                if ($value!=='') {
                    if ($period) {
                        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
                        if (!$date || $date->format('Y-m-d')!==$value || $value<'1900-01-01') self::fail($label.'日期无效。');
                    } else {
                        $pattern=in_array($key,['consumeCount','visitCount','remainingTimes'],true)?'/^\d+$/D':'/^\d+(?:\.\d{1,2})?$/D';
                        if (!preg_match($pattern,$value) || bccomp($value,'1000000000',2)>0) self::fail($label.'请输入有效的非负范围。');
                    }
                }
                $values[$bound]=$value;
            }
            if ($values['min']==='' && $values['max']==='') continue;
            if ($period && ($values['min']==='' || $values['max']==='')) self::fail($label.'请选择完整周期。');
            if ($values['min']!=='' && $values['max']!=='' && ($period?$values['min']>$values['max']:bccomp($values['min'],$values['max'],2)>0)) self::fail($label.'最小值不能大于最大值。');
            $out[$key]=$values;
        }
        return $out;
    }
    private static function fail(string $message): void
    {
        throw new UnifiedQueryException('UNIFIED_QUERY_INVALID_QUERY',$message,[]);
    }
}
