<?php
namespace app\services\ai\execution;

use app\services\ai\contract\AiContractException;
use app\services\query\metric\MetricQueryContractException;
use app\services\query\metric\MetricQueryDatePolicy;

/** Converts model-understood time meaning to one business-date range, never by matching customer words. */
final class AiTemporalPeriodResolver
{
    public static function valid($period): bool
    {
        if (!is_array($period) || !is_string($period['kind']??null)) return false;
        $keys=array_keys($period); sort($keys,SORT_STRING);
        if ($period['kind']==='date_range') return $keys===['end','kind','start']
            && self::date($period['start']??null)!==null && self::date($period['end']??null)!==null
            && $period['start']<=$period['end'];
        if ($period['kind']==='relative_days') return $keys===['days','end_offset_days','kind']
            && is_int($period['days']??null) && $period['days']>0 && is_int($period['end_offset_days']??null);
        // Kept solely for verified older conversations; new model output uses calendar_period.
        if ($period['kind']==='month_offset') return $keys===['kind','offset_months'] && is_int($period['offset_months']??null);
        return $period['kind']==='calendar_period' && $keys===['count','end_mode','kind','offset','unit']
            && in_array($period['unit']??null,['week','month','quarter','year'],true)
            && is_int($period['offset']??null) && is_int($period['count']??null) && $period['count']>=1
            && in_array($period['end_mode']??null,['full','to_reference'],true)
            && ($period['end_mode']!=='to_reference' || $period['offset']===0);
    }

    /** The reference is the frozen request business day in Asia/Shanghai, not the machine clock. */
    public static function resolve(array $period,string $referenceDate): array
    {
        if (!self::valid($period) || ($reference=self::date($referenceDate))===null) throw new AiContractException('AI_DATE_INVALID');
        if ($period['kind']==='month_offset') $period=['kind'=>'calendar_period','unit'=>'month',
            'offset'=>$period['offset_months'],'count'=>1,'end_mode'=>$period['offset_months']===0?'to_reference':'full'];
        if ($period['kind']==='date_range') return self::range($period['start'],$period['end']);
        if ($period['kind']==='relative_days') {
            // Reject arithmetic outside the supported 1000–9999 calendar
            // before DateTime can silently normalize an oversized offset.
            $minimum=self::date('1000-01-01');$maximum=self::date('9999-12-31');
            $offset=$period['end_offset_days'];
            if ($offset < -$minimum->diff($reference)->days || $offset > $reference->diff($maximum)->days) throw new AiContractException('AI_DATE_INVALID');
            $end=$reference->modify(($period['end_offset_days']>=0?'+':'').$period['end_offset_days'].' days');
            if ($period['days']>$minimum->diff($end)->days+1) throw new AiContractException('AI_DATE_INVALID');
            $start=$end->modify('-'.($period['days']-1).' days');
            return self::range($start->format('Y-m-d'),$end->format('Y-m-d'));
        }
        $unit=$period['unit'];
        // These ceilings only guard representable calendar arithmetic; they
        // are not query-length limits and never truncate a customer period.
        $calendarLimit=['week'=>470000,'month'=>108000,'quarter'=>36000,'year'=>9000][$unit];
        if ($period['offset']<-$calendarLimit || $period['offset']>$calendarLimit
            || $period['count']>$calendarLimit+1
            || $period['offset']-$period['count']+1<-$calendarLimit) throw new AiContractException('AI_DATE_INVALID');
        if ($unit==='week') {
            $base=$reference->modify('monday this week');$months=null;
        } elseif ($unit==='month') {
            $base=$reference->modify('first day of this month');$months=1;
        } elseif ($unit==='quarter') {
            $base=$reference->setDate((int)$reference->format('Y'),(int)(floor(((int)$reference->format('n')-1)/3)*3+1),1);$months=3;
        } else {
            $base=$reference->setDate((int)$reference->format('Y'),1,1);$months=12;
        }
        $shift=static fn(int $count): \DateTimeImmutable => $months===null
            ? $base->modify(($count>=0?'+':'').$count.' weeks')
            : $base->modify(($count*$months>=0?'+':'').($count*$months).' months');
        $start=$shift($period['offset']-$period['count']+1);
        $end=$period['end_mode']==='to_reference' ? $reference : $shift($period['offset']+1)->modify('-1 day');
        return self::range($start->format('Y-m-d'),$end->format('Y-m-d'));
    }

    private static function date($value): ?\DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D',$value)) return null;
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('Asia/Shanghai'));
        return $date && $date->format('Y-m-d')===$value ? $date : null;
    }

    private static function range(string $start,string $end): array
    {
        try { return MetricQueryDatePolicy::normalize(['start'=>$start,'end'=>$end]); }
        catch (MetricQueryContractException $error) { throw new AiContractException(MetricQueryDatePolicy::aiReason($error->getErrorCode())); }
    }
}
