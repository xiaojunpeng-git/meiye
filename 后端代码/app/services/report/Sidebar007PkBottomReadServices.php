<?php
declare(strict_types=1);

namespace app\services\report;

use think\facade\Db;

/**
 * PK 六个月底标只读桥。六个月每一天均须落在 V3 正式覆盖期内；
 * 原单现金与合作方实配由统一事实层认证，历史调整只读取授权门店自身。
 */
final class Sidebar007PkBottomReadServices
{
    private $cashReader;
    private $partnerReader;
    private $adjustmentReader;

    /** 注入点仅供合同；正式路径不会用旧金额替代缺失的 V3 收款事实。 */
    public function __construct(?callable $cashReader=null,?callable $partnerReader=null,
        ?callable $adjustmentReader=null)
    {
        $this->cashReader=$cashReader ?: static fn(array $stores,array $range):array=>
            (new Sidebar007OriginalCashReadServices())->read($stores,$range);
        $this->partnerReader=$partnerReader ?: static fn(array $stores,array $range):array=>
            (new Sidebar007PartnerAllocationReadServices())->read($stores,$range);
        $this->adjustmentReader=$adjustmentReader ?: [$this,'adjustments'];
    }

    /** 007 公式为此前六个月现金减合作方实配、加本店调整，再除以六并截为整元。 */
    public function read(array $stores,string $month):array
    {
        if($stores===[]||count($stores)!==count(array_unique($stores)))
            throw new \InvalidArgumentException('PK 底标门店范围无效');
        foreach($stores as $id)if(!is_int($id)||$id<1)
            throw new \InvalidArgumentException('PK 底标门店 ID 无效');
        if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$month))
            throw new \InvalidArgumentException('PK 底标月份无效');
        $first=\DateTimeImmutable::createFromFormat('!Y-m-d',$month.'-01',
            new \DateTimeZone('Asia/Shanghai'));
        if(!$first||$first->format('Y-m')!==$month)
            throw new \InvalidArgumentException('PK 底标月份无效');
        $start=$first->modify('-6 months')->format('Y-m-d');
        $end=$first->modify('-1 day')->format('Y-m-d');
        $scope=['start'=>$start,'end'=>$end];
        if($start<StoreUnifiedReportServices::COVERAGE_START)
            return $this->gap($stores,$scope,'PK_BOTTOM_BEFORE_V3_COVERAGE');
        $cash=($this->cashReader)($stores,$scope);
        $partner=($this->partnerReader)($stores,$scope);
        $adjustments=($this->adjustmentReader)($stores,$start,$first->format('Y-m-d'));
        if(!is_array($cash)||!is_array($cash['by_store']??null)
            ||!is_array($partner)||!is_array($partner['by_store']??null)
            ||!is_array($adjustments)||count($adjustments)>10000)
            throw new \LogicException('PK 底标来源结构无效');
        $allowed=array_fill_keys($stores,true);$adjusted=array_fill_keys($stores,0);
        foreach($adjustments as $row){
            $id=(int)($row['store_id']??0);
            if(!isset($allowed[$id]))throw new \LogicException('PK 历史调整越权');
            $amount=$this->cents($row['old_yeji']??null);
            $adjusted[$id]=$this->sum($adjusted[$id],$amount);
        }
        $out=[];$gaps=[];
        foreach($stores as $id){
            $money=$cash['by_store'][$id]??null;
            $share=$partner['by_store'][$id]??null;
            if(!is_array($money)||($money['complete']??false)!==true
                ||!is_int($money['amount_cents']??null)||!is_int($share)){
                $out[$id]=null;$gaps[]=['store_id'=>$id,
                    'code'=>'PK_BOTTOM_SIX_MONTH_SOURCE_UNVERIFIED'];continue;
            }
            $total=$this->sum($this->sum($money['amount_cents'],-$share),$adjusted[$id]);
            // 007 bcdiv(..., 6) 在 PHP 默认 scale=0，按元截去小数。
            $out[$id]=intdiv($total,600)*100;
        }
        return ['by_store'=>$out,'complete'=>$gaps===[],'gaps'=>$gaps,
            'scope'=>$scope,'source'=>'certified_v3_six_month_cash_partner_and_store_adjustment'];
    }

    /** 仅按授权门店取历史手录调整；原 007 漏店条件的全店求和不再沿用。 */
    private function adjustments(array $stores,string $start,string $exclusiveEnd):array
    {
        $zone=new \DateTimeZone('Asia/Shanghai');
        $from=(new \DateTimeImmutable($start.' 00:00:00',$zone))->getTimestamp();
        $to=(new \DateTimeImmutable($exclusiveEnd.' 00:00:00',$zone))->getTimestamp();
        return Db::name('yeji_pk')->whereIn('store_id',$stores)
            ->where('date','>=',$from)->where('date','<',$to)
            ->field('store_id,old_yeji')->limit(10001)->select()->toArray();
    }

    private function cents($value):int
    {
        $text=(string)$value;
        if(!preg_match('/^(-?)(\d{1,13})(?:\.(\d{1,2})(?:0{1,4})?)?$/D',$text,$parts))
            throw new \LogicException('PK 历史调整金额无效');
        $amount=(int)$parts[2]*100+(int)str_pad($parts[3]??'0',2,'0');
        return $parts[1]==='-'?-$amount:$amount;
    }

    private function sum(int $left,int $right):int
    {
        if(($right>0&&$left>PHP_INT_MAX-$right)
            ||($right<0&&$left<PHP_INT_MIN-$right))
            throw new \OverflowException('PK 底标金额溢出');
        return $left+$right;
    }

    private function gap(array $stores,array $scope,string $code):array
    {
        return ['by_store'=>array_fill_keys($stores,null),'complete'=>false,
            'gaps'=>[['code'=>$code,'message'=>'此前六个月历史事实未完整覆盖，底标显示为“-”。']],
            'scope'=>$scope,'source'=>'certified_v3_six_month_cash_partner_and_store_adjustment'];
    }
}
