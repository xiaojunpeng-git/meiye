<?php
declare(strict_types=1);
namespace app\services\payroll;

/**
 * 工资来源按实例明确的启用日期切换；空配置保留现有实时行为。
 * 工资按自然月查询，因此切换只能是月首；业务发生日期决定归属，导入/保存时间不能替代。
 */
final class SalaryStatisticsSourcePolicy
{
    public static function realtimeStart():?string
    {
        $value=config('payroll.realtime_start_date','');
        if($value===null||$value==='')return null;
        $date=is_string($value)?\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('Asia/Shanghai')):false;
        if(!$date||$date->format('Y-m-d')!==$value||substr($value,8)!=='01')
            throw new \RuntimeException('工资实时统计起始日期必须为有效自然月首日');
        return $value;
    }

    /** 输入来自后端已校验的工资月份；不接收浏览器指定来源或绕过切换的参数。 */
    public static function savedMonth(string $month):bool
    {
        $date=self::realtimeStart();
        return $date!==null&&$month<substr($date,0,7);
    }
}
