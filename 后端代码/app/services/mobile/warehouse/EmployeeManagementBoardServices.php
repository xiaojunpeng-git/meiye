<?php
declare(strict_types=1);
namespace app\services\mobile\warehouse;
use app\services\report\HumanEfficiencyMetadataServices;
use app\services\report\StoreUnifiedReportPhaseSixServices;
use app\services\report\StoreReportCurrentProductCategoryServices;
use app\services\query\metric\MetricMoneyFormatter;
use app\services\cashier\v3\CashierV3ScopeResolver;
use think\facade\Db;

/** 员工个人看板只转置共享人员事实；调用方先核实员工身份与授权门店，禁止取整店金额冒充个人。 */
final class EmployeeManagementBoardServices
{
    public function read(array $stores,array $range,int $employee): array
    {
        if ($employee <= 0 || $stores === []) throw new \InvalidArgumentException('请选择授权范围内的员工和门店');
        $categories=(new HumanEfficiencyMetadataServices())->metadata()['categories'];
        $parents=Db::name('store_product_category')->where('type',0)->where('relation_id',0)->column('pid','id');
        $roots=[];$enabled=array_fill_keys(array_column($categories,'id'),true);
        foreach($parents as $id=>$_){$at=$id;$seen=[];while(isset($parents[$at])&&!isset($seen[$at])){if(isset($enabled[$at])){$roots[$id]=$at;break;}$seen[$at]=true;$at=$parents[$at];}}
        $active=[];$visits=0;$items=0;$cash=0;$beauty=0;$sales=[];
        $beautyRoots=array_values(array_filter($categories,static fn($c)=>$c['name']==='生美'));
        $facts=[];
        // 仅保留本人员事实；跨门店分批读取避免一次复制整个组织的宽事实集合。
        foreach(array_chunk($stores,4) as $chunk)foreach((new StoreUnifiedReportPhaseSixServices())->humanEfficiencyFacts($chunk,$range,false) as $fact){
            if((int)$fact['employee_id']===$employee&&!in_array($fact['source_type']??'',['manual_refund','imported_card_refund'],true))$facts[]=$fact;
        }
        $serviceCategories=$this->serviceCategories($facts,$stores);
        foreach($facts as $fact){
            $cash+=(int)($fact['cash_cents']??0);$visits+=(int)($fact['service_visit_tenths']??0);$items+=(float)($fact['project_count']??0);
            if(!empty($fact['service_fact_id'])&&(int)($fact['service_people_tenths']??0)>0&&(int)$fact['member_id']>0)$active[$fact['member_id']]=true;
            // 分类显示名可能重复或含分隔符；生美资格只能由已核实项目当前分类的根ID判断。
            $serviceKey=(int)$fact['store_id'].'|'.(string)($fact['service_fact_id']??'');
            if(count($beautyRoots)===1&&(int)($serviceCategories[$serviceKey]['root_id']??0)===(int)$beautyRoots[0]['id'])$beauty+=(int)($fact['consumption_cents']??0);
            foreach($fact['sales_category_cents']??[] as $id=>$amount){$root=$roots[$id]??0;$sales[$root]=($sales[$root]??0)+(int)$amount;}
        }
        $card=static function(string $code,string $name,$value,bool $money=false):array{return ['code'=>$code,'name'=>$name,'value'=>$value,'tone'=>$money?'money':($code==='active'?'people':'service'),'display'=>$value===null?'—':($money?MetricMoneyFormatter::integerYuan((int)$value):(string)$value)];};
        $service=[$card('active','活客数',count($active)),$card('visits','售后人次',$visits/10),$card('items','服务项目数',round($items,6)),$card('beauty_consume','生美消耗业绩',count($beautyRoots)===1?$beauty:null,true)];
        $sale=self::salesCards($categories,$sales,$cash,$card);
        return ['sections'=>[['name'=>'服务数据','cards'=>$service],['name'=>'销售数据','cards'=>$sale]],'metric_version'=>'employee-board-v1',
            'data_as_of'=>time(),'aggregation_caught_up'=>true];
    }

    /** 只隐藏原始分值恰为零的销售分类；负数及整元展示为零的历史分值仍须保留，合计始终展示。 */
    private static function salesCards(array $categories,array $sales,int $cash,callable $card): array
    {
        $sale=[];$classified=0;
        foreach($categories as $category){
            $amount=(int)($sales[$category['id']]??0);$classified+=$amount;
            if($amount!==0)$sale[]=$card('category:'.$category['id'],$category['name'].'业绩',$amount,true);
        }
        if($cash!==$classified)$sale[]=$card('unclassified','未归类业绩',$cash-$classified,true);
        $sale[]=$card('cash','合计业绩',$cash,true);
        return $sale;
    }

    /** 复用共享当前分类解析器的商品身份链，不允许以项目名或分类路径文本代替分类ID。 */
    private function serviceCategories(array $facts,array $stores): array
    {
        $ids=[];
        foreach($facts as $fact)if(!empty($fact['service_fact_id']))$ids[(string)$fact['service_fact_id']]=true;
        $links=[];
        foreach(array_chunk(array_keys($ids),300) as $chunk){
            $services=Db::name('cashier_v3_entitlement_service_fact')->where('tenant_id',CashierV3ScopeResolver::TENANT_SCOPE_ID)->whereIn('store_id',$stores)
                ->whereIn('service_fact_id',$chunk)->where('service_status','completed')->field('service_fact_id,store_id,project_id product_id')->select()->toArray();
            foreach($services as $service)$links[$service['store_id'].'|'.$service['service_fact_id']]=$service;
        }
        return (new StoreReportCurrentProductCategoryServices())->resolve($links);
    }
}
