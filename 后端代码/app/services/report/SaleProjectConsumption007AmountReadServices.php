<?php

declare(strict_types=1);

namespace app\services\report;

use think\facade\Db;

/**
 * 即买即用项目整行即时完成的金额桥：007 原核销金额为购物车行价÷购买次数×本次核销次数；
 * V3 该链一次完成整行数量，故仅在销售行、服务行和已结算原单逐项一致时取冻结成交行金额。
 * 此桥只投影报表金额，不创建核销或消费业绩事实，不以现金实收替代含余额的成交额。
 */
final class SaleProjectConsumption007AmountReadServices
{
    /** @return array{rows:array,gaps:array} */
    public function read(array $stores,array $services,array $registeredAmounts):array
    {
        $allowed=array_fill_keys(array_map('intval',$stores),true);$existing=[];$wanted=[];
        foreach($registeredAmounts as $fact)$existing[$this->lineKey($fact)]=true;
        foreach($services as $service){
            if((string)($service['source_document_type']??'')!=='sales_order')continue;
            $store=(int)($service['store_id']??0);
            if(!isset($allowed[$store]))throw new \LogicException('即买即用服务越过授权门店');
            $key=$this->lineKey($service);
            if(isset($existing[$key]))continue; // 已有正式消费事实优先，不能重复计额。
            if(isset($wanted[$key]))throw new \LogicException('即买即用服务行身份重复');
            $wanted[$key]=$service;
        }
        if($wanted===[])return ['rows'=>[],'gaps'=>[]];
        $orderIds=array_values(array_unique(array_filter(array_map('strval',array_column($wanted,'document_id')))));
        $headers=[];
        foreach(array_chunk($orderIds,500) as $chunk){
            foreach(Db::name('cashier_v3_sales_order')->where('tenant_id','0')->whereIn('order_id',$chunk)
                ->field('id,order_id,store_id,member_id,checkout_request_id,order_status,order_direction')
                ->limit(10001)->select()->toArray() as $row){
                $id=(string)$row['order_id'];
                if(array_key_exists($id,$headers))$headers[$id]=null;
                else $headers[$id]=$row;
            }
        }
        $requests=array_values(array_unique(array_filter(array_map('strval',array_column($wanted,'checkout_request_id')))));
        $sales=[];$count=0;
        foreach(array_chunk($requests,500) as $chunk){
            $query=Db::name('cashier_v3_sale_fact')->alias('f')
                ->where('f.tenant_id','0')->whereIn('f.store_id',$stores)
                ->whereIn('f.checkout_request_id',$chunk)->where('f.source_type','project')
                ->where('f.status','effective')->where('f.fact_direction','forward')
                ->whereNotExists(function($reversal):void{
                    $reversal->name('cashier_v3_sale_fact')->alias('r')
                        ->whereRaw('r.tenant_id=f.tenant_id')->whereRaw('r.reversal_of=f.fact_id')
                        ->where('r.fact_direction','reversal')->where('r.status','effective');
                });
            (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderFacts($query,'f.tenant_id','f.order_id');
            $batch=$query->field('f.fact_id,f.store_id,f.member_id,f.checkout_request_id,f.source_line_id,'
                .'f.order_id,f.item_id,f.quantity,f.sale_amount_cents')->limit(10001)->select()->toArray();
            $count+=count($batch);
            if($count>10000)throw new \RuntimeException('即买即用成交行超过10000条，请缩小日期或门店范围');
            foreach($batch as $row)$sales[$this->lineKey($row)][]=$row;
        }
        return $this->project($wanted,$headers,$sales);
    }

    /** Only exact one-to-one same-line original transactions may stand in for the 007 full-line writeoff amount. */
    private function project(array $wanted,array $headers,array $sales):array
    {
        $rows=[];$gaps=[];
        foreach($wanted as $key=>$service){
            $header=$headers[(string)($service['document_id']??'')]??null;
            $candidates=$sales[$key]??[];
            $valid=$header && count($candidates)===1
                &&(string)$header['order_status']==='settled'
                &&(string)$header['order_direction']==='forward'
                &&(int)$header['id']===(int)($service['source_document_id']??0)
                &&(int)$header['store_id']===(int)$service['store_id']
                &&(int)$header['member_id']===(int)$service['member_id']
                &&(string)$header['checkout_request_id']===(string)$service['checkout_request_id'];
            if($valid){
                $sale=$candidates[0];
                $valid=(string)$sale['order_id']===(string)$service['document_id']
                    &&(int)$sale['store_id']===(int)$service['store_id']
                    &&(int)$sale['member_id']===(int)$service['member_id']
                    &&(int)$sale['item_id']===(int)$service['project_id']
                    &&$this->sameQuantity($sale['quantity']??null,$service['quantity']??null)
                    &&$this->cents($sale['sale_amount_cents']??null)>=0;
            }
            if(!$valid){
                $gaps[]=['service_fact_id'=>$service['service_fact_id']??'',
                    'code'=>'SALE_PROJECT_CONSUMPTION_SOURCE_UNVERIFIED',
                    'message'=>'即买即用项目缺唯一且同店、同会员、同项目、同数量的有效原成交行，不能推算核销金额。'];
                continue;
            }
            $rows[]=['fact_id'=>'sale_project_007:'.$sale['fact_id'],
                'store_id'=>(int)$service['store_id'],
                'checkout_request_id'=>(string)$service['checkout_request_id'],
                'source_line_id'=>(string)$service['source_line_id'],
                'amount_cents'=>$this->cents($sale['sale_amount_cents'])];
        }
        return ['rows'=>$rows,'gaps'=>$gaps];
    }

    /** 结账行身份同时带店、请求和行键，不能仅凭可重复的项目名联接。 */
    private function lineKey(array $row):string
    {
        return json_encode([(int)($row['store_id']??0),(string)($row['checkout_request_id']??''),
            (string)($row['source_line_id']??'')]);
    }

    /** 项目数量是收银唯一允许小数的值；服务与成交行须同单位完整相等。 */
    private function sameQuantity($left,$right):bool
    {
        if(!is_numeric($left)||!is_numeric($right)||bccomp((string)$left,'0',6)<0
            ||bccomp((string)$right,'0',6)<0)return false;
        return bccomp((string)$left,(string)$right,6)===0;
    }

    /** 仅接收已经整数分存储的事实，保留原精度不作二次归整。 */
    private function cents($value):int
    {
        if(is_string($value)&&preg_match('/^-?(0|[1-9][0-9]*)$/D',$value))$value=(int)$value;
        if(!is_int($value)||abs($value)>9007199254740991)
            throw new \LogicException('即买即用成交金额不是整数分');
        return $value;
    }
}
