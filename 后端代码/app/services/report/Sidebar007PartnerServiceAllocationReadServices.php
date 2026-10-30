<?php
declare(strict_types=1);

namespace app\services\report;

use think\facade\Db;

/**
 * 门店统计店内消耗专用合作方分配桥。仅按同一完成服务的 partner
 * 人员实际劳动业绩扣减；旧服务子单归店及重复笔数由服务订单桥固定。
 */
final class Sidebar007PartnerServiceAllocationReadServices
{
    private $serviceReader;
    private $factReader;

    /** 注入只供合同测试；正式人员类型与分配额均来自不可变 V3 劳务事实。 */
    public function __construct(?callable $serviceReader=null,?callable $factReader=null)
    {
        $this->serviceReader=$serviceReader ?: static fn(array $stores,array $range):array=>
            (new Sidebar007ServiceOrderReadServices())->read($stores,$range);
        $this->factReader=$factReader ?: [$this,'laborFacts'];
    }

    /** 对同一核销的两张旧子单分别归店，partner 扣减也逐子单同样计两笔。 */
    public function read(array $stores,array $range,?array $serviceSource=null):array
    {
        if($stores===[]||count($stores)!==count(array_unique($stores)))
            throw new \InvalidArgumentException('店内消耗授权门店无效');
        foreach($stores as $id)if(!is_int($id)||$id<1)
            throw new \InvalidArgumentException('店内消耗授权门店无效');
        $source=$serviceSource??($this->serviceReader)($stores,$range);
        if(!is_array($source)||!is_bool($source['complete']??null))
            throw new \LogicException('店内消耗服务来源无效');
        if(!$source['complete'])return $this->gap($stores,$source['gaps']??[]);
        $entries=$source['qualified_entries']??null;
        if(!is_array($entries))return $this->gap($stores,[['code'=>'SERVICE_ORDER_IDENTITY_MISSING']]);
        $allowed=array_fill_keys($stores,true);$serviceIds=[];
        foreach($entries as $entry){
            $owner=(int)($entry['store_id']??0);
            $factId=(string)($entry['service_fact_id']??'');
            if(!isset($allowed[$owner])||$factId==='')
                throw new \LogicException('店内消耗子单身份越权或缺失');
            $serviceIds[$factId]=true;
        }
        $facts=($this->factReader)($stores,array_keys($serviceIds));
        if(!is_array($facts)||count($facts)>100000)
            throw new \LogicException('合作方服务劳务来源过大');
        $byService=[];$seenFact=[];$gaps=[];
        foreach($facts as $row){
            $service=(string)($row['service_fact_id']??'');
            $store=(int)($row['service_store_id']??0);
            if(!isset($serviceIds[$service])||!isset($allowed[$store]))
                throw new \LogicException('合作方劳务事实越权或服务身份无效');
            $laborAmount=$row['labor_amount_cents']??null;
            if(!is_numeric($laborAmount)
                ||!preg_match('/^(?:0|[1-9][0-9]*)$/D',(string)$laborAmount))
                throw new \LogicException('同次服务劳动金额无效');
            $laborInteger=filter_var((string)$laborAmount,FILTER_VALIDATE_INT);
            if($laborInteger===false)throw new \LogicException('同次服务劳动金额溢出');
            if(isset($byService[$service])
                &&($byService[$service]['store_id']!==$store
                    ||$byService[$service]['labor_amount_cents']!==$laborInteger))
                throw new \LogicException('同次服务劳务事实身份矛盾');
            $byService[$service]['store_id']=$store;
            $byService[$service]['labor_amount_cents']=$laborInteger;
            $byService[$service]['partner_cents']??=0;
            $byService[$service]['has_labor_fact']??=false;
            $factId=(string)($row['fact_id']??'');
            if($factId==='')continue;
            if(isset($seenFact[$factId]))throw new \LogicException('合作方劳务事实重复');
            $seenFact[$factId]=true;
            $byService[$service]['has_labor_fact']=true;
            $employee=(int)($row['employee_id']??0);
            $type=(string)($row['employee_type_snapshot']??'');
            $version=(int)($row['employee_type_authority_version']??0);
            $direction=(string)($row['fact_direction']??'');
            $amount=$row['amount_cents']??null;
            if($employee<1||!in_array($type,['internal','partner','outsourced'],true)
                ||$version<1||!in_array($direction,['forward','reversal'],true)
                ||!is_numeric($amount)||!preg_match('/^-?(?:0|[1-9][0-9]*)$/D',(string)$amount)){
                $gaps[]=['code'=>'PARTNER_SERVICE_EMPLOYEE_SNAPSHOT_UNVERIFIED'];break;
            }
            $amount=filter_var((string)$amount,FILTER_VALIDATE_INT);
            if($amount===false){$gaps[]=['code'=>'PARTNER_SERVICE_AMOUNT_OUT_OF_RANGE'];break;}
            if($type==='partner'){
                if($direction==='reversal'&&$amount>0){
                    $gaps[]=['code'=>'PARTNER_SERVICE_UNSIGNED_REVERSAL'];break;
                }
                $current=$byService[$service]['partner_cents'];
                if(($amount>0&&$current>PHP_INT_MAX-$amount)
                    ||($amount<0&&$current<PHP_INT_MIN-$amount))
                    throw new \OverflowException('合作方服务分配金额溢出');
                $byService[$service]['partner_cents']=$current+$amount;
            }
        }
        foreach(array_keys($serviceIds) as $factId){
            $item=$byService[$factId]??null;
            if($item===null){$gaps[]=['code'=>'PARTNER_SERVICE_FACT_MISSING'];break;}
            if($item['labor_amount_cents']>0&&!$item['has_labor_fact']){
                $gaps[]=['code'=>'PARTNER_SERVICE_LABOR_FACT_MISSING'];break;
            }
        }
        if($gaps!==[])return $this->gap($stores,$gaps);
        $partner=array_fill_keys($stores,0);$inside=array_fill_keys($stores,0);
        foreach($entries as $entry){
            $owner=(int)$entry['store_id'];$factId=(string)$entry['service_fact_id'];
            $share=$byService[$factId]['partner_cents'];
            if(($share>0&&$partner[$owner]>PHP_INT_MAX-$share)
                ||($share<0&&$partner[$owner]<PHP_INT_MIN-$share))
                throw new \OverflowException('店内消耗合作方分配溢出');
            $partner[$owner]+=$share;
        }
        foreach($stores as $id){
            $total=$source['by_store'][$id]['consumption_amount_cents']??null;
            if(!is_int($total))throw new \LogicException('店内消耗总额来源缺失');
            if(($partner[$id]<0&&$total>PHP_INT_MAX+$partner[$id])
                ||($partner[$id]>0&&$total<PHP_INT_MIN+$partner[$id]))
                throw new \OverflowException('店内消耗金额溢出');
            $inside[$id]=$total-$partner[$id];
        }
        return ['by_store'=>$inside,'partner_by_store'=>$partner,'complete'=>true,
            'gaps'=>[],'source'=>'service_order_consumption_minus_same_service_partner_labor'];
    }

    /** 只按认证服务ID读取同次有效劳动分配；原分配与有效冲销均保留符号。 */
    private function laborFacts(array $stores,array $serviceIds):array
    {
        $out=[];
        foreach(array_chunk($serviceIds,500) as $chunk){
            $rows=Db::name('cashier_v3_entitlement_service_fact')->alias('s')
                ->leftJoin('cashier_v3_performance_fact p',
                    "p.tenant_id=s.tenant_id AND p.store_id=s.store_id AND p.checkout_request_id=s.checkout_request_id AND p.source_line_id=s.source_line_id AND p.performance_type='labor_performance_allocated' AND p.status='effective' AND p.fact_direction IN ('forward','reversal')")
                ->where('s.tenant_id','0')->whereIn('s.store_id',$stores)
                ->whereIn('s.service_fact_id',$chunk)
                ->field('s.service_fact_id,s.store_id service_store_id,s.labor_amount_cents,'
                    .'p.fact_id,p.employee_id,p.employee_type_snapshot,'
                    .'p.employee_type_authority_version,p.fact_direction,p.amount_cents')
                ->limit(100001)->select()->toArray();
            if(count($rows)>100000)throw new \RuntimeException('合作方劳务事实超过安全范围');
            $out=array_merge($out,$rows);
            if(count($out)>100000)throw new \RuntimeException('合作方劳务事实超过安全范围');
        }
        return $out;
    }

    private function gap(array $stores,array $gaps):array
    {
        return ['by_store'=>array_fill_keys($stores,null),'partner_by_store'=>array_fill_keys($stores,null),
            'complete'=>false,'gaps'=>$gaps,
            'source'=>'service_order_consumption_minus_same_service_partner_labor'];
    }
}
