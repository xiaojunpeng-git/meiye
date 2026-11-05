<?php

declare(strict_types=1);
namespace app\services\report;
use think\facade\Db;

/** 只读取有核实来源的历史管辖期间。没有记录表示未知，不表示当前经理过去一直管辖。 */
final class HumanEfficiencyHistoryServices
{
    public function calendar(array $stores,array $range):array
    {
        if(!$stores)throw new \InvalidArgumentException('历史管辖查询范围不能为空');

        $periods=Db::name('human_efficiency_management_period')->where('tenant_id','0')->whereIn('store_id',$stores)
            ->where('valid_from','<=',$range['end'])->where('valid_to','>=',$range['start'])->where('verified_at','>',0)
            ->where('evidence_reference','<>','')->select()->toArray();
        return self::project($periods,$stores,$range);
    }

    /** 已核实期间的纯日历投影，重叠一律拒绝；业务快照不能补造没有交易日期的管辖。 */
    public static function project(array $periods,array $stores,array $range):array
    {
        $days=[];$managers=[];$covered=true;
        foreach($periods as $p){
            if(!in_array((int)$p['store_id'],$stores,true))throw new \InvalidArgumentException('管辖资料不在查询范围');
            $id=(string)$p['manager_organization_id'];
            $managers[$id]=(string)$p['manager_name_snapshot'];
            $first=max($p['valid_from'],$range['start']);$last=min($p['valid_to'],$range['end']);
            for($day=new \DateTimeImmutable($first);$day->format('Y-m-d')<=$last;$day=$day->modify('+1 day')){
                $date=$day->format('Y-m-d');$store=(int)$p['store_id'];
                if(isset($days[$store][$date]))throw new \InvalidArgumentException('历史经理管辖期间重叠，请核对后再查询');
                $days[$store][$date]=$id;
            }
        }
        foreach($stores as $store)for($day=new \DateTimeImmutable($range['start']);$day->format('Y-m-d')<=$range['end'];$day=$day->modify('+1 day')){
            if(!isset($days[$store][$day->format('Y-m-d')]))$covered=false;
        }
        return ['days'=>$days,'managers'=>$managers,'complete'=>$covered];
    }
}
