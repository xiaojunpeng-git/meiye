<?php
declare(strict_types=1);

namespace app\services\report;

use think\facade\Db;

/**
 * 门店统计“销售项目数”按有效原销售行求项目/卡项数量。
 * 旧购物车必须经原主键逐行覆盖审计；金额和数量只从 V3 正式事实读取。
 */
final class Sidebar007SoldProjectCountReadServices
{
    private $saleReader;
    private $coverageReader;

    /** 注入点只供合同；正式来源为同一统一销售事实与旧人口覆盖门禁。 */
    public function __construct(?callable $saleReader=null,?callable $coverageReader=null)
    {
        $this->saleReader=$saleReader ?: [$this,'sales'];
        $this->coverageReader=$coverageReader ?: [new StoreItemSales007SourceCoverageServices(),'read'];
    }

    public function read(array $stores,array $range):array
    {
        if($stores===[]||count($stores)!==count(array_unique($stores)))
            throw new \InvalidArgumentException('销售项目数门店范围无效');
        foreach($stores as $id)if(!is_int($id)||$id<1)
            throw new \InvalidArgumentException('销售项目数授权门店无效');
        foreach(['start','end'] as $key){
            $value=$range[$key]??null;
            $date=is_string($value)?\DateTimeImmutable::createFromFormat('!Y-m-d',$value):false;
            if(!$date||$date->format('Y-m-d')!==$value)
                throw new \InvalidArgumentException('销售项目数日期无效');
        }
        if($range['start']>$range['end']||$range['start']<StoreUnifiedReportServices::COVERAGE_START)
            throw new \InvalidArgumentException('销售项目数超出V3覆盖期');
        $sales=($this->saleReader)($stores,$range);
        if(!is_array($sales)||count($sales)>10000)
            throw new \LogicException('销售项目事实过大或无效');
        $allowed=array_fill_keys($stores,true);$seen=[];
        foreach($sales as $fact){
            $id=(int)($fact['store_id']??0);$factId=(string)($fact['fact_id']??'');
            if(!isset($allowed[$id])||$factId===''||isset($seen[$factId]))
                throw new \LogicException('销售项目事实越权或重复');
            $seen[$factId]=true;
        }
        $coverage=($this->coverageReader)($stores,$range,$sales);
        if(!is_array($coverage)||!is_bool($coverage['complete']??null))
            throw new \LogicException('销售项目旧来源覆盖无效');
        if(!$coverage['complete'])return ['by_store'=>array_fill_keys($stores,null),
            'complete'=>false,'gaps'=>$coverage['gaps']??[],
            'source_coverage'=>$coverage];
        $eligible=array_fill_keys($coverage['eligible_legacy_fact_ids']??[],true);
        $count=array_fill_keys($stores,0);
        foreach($sales as $fact){
            $id=(int)$fact['store_id'];$factId=(string)$fact['fact_id'];
            $legacy=($fact['source_document_type']??'')==='legacy_store_order';
            if($legacy&&!isset($eligible[$factId]))continue;
            if(!$legacy&&!in_array((string)($fact['source_type']??''),['project','card'],true))continue;
            $quantity=$fact['quantity']??null;
            $number=filter_var($quantity,FILTER_VALIDATE_INT);
            if($number===false||$number<0||$count[$id]>PHP_INT_MAX-$number)
                throw new \LogicException('销售项目数量无效或溢出');
            $count[$id]+=$number;
        }
        return ['by_store'=>$count,'complete'=>true,'gaps'=>[],
            'source_coverage'=>$coverage,'source'=>'v3_original_sale_quantity_with_007_cart_coverage'];
    }

    /** 正向有效原销售事实排成功作废及冲销，取业务日期而非旧下单日。 */
    private function sales(array $stores,array $range):array
    {
        $query=Db::name('cashier_v3_sale_fact')->alias('s')
            ->where('s.tenant_id','0')->whereIn('s.store_id',$stores)
            ->whereBetween('s.business_date',[$range['start'],$range['end']])
            ->where('s.status','effective')->where('s.fact_direction','forward')
            ->whereNotExists(function($reversal):void{
                $reversal->name('cashier_v3_sale_fact')->alias('rv')
                    ->whereRaw('rv.tenant_id=s.tenant_id')
                    ->whereRaw('rv.reversal_of=s.fact_id')
                    ->where('rv.fact_direction','reversal')->where('rv.status','effective');
            });
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderFacts(
            $query,'s.tenant_id','s.order_id');
        return $query->field('s.fact_id,s.store_id,s.order_id,s.source_document_type,'
            .'s.source_line_id,s.source_type,s.quantity')->limit(10001)->select()->toArray();
    }
}
