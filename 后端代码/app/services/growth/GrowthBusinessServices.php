<?php
declare(strict_types=1);
namespace app\services\growth;
use think\facade\Db;
use mohe\exceptions\AdminException;
use app\services\query\metric\MetricReadTransaction;
use app\services\query\metric\MetricDefinitionRegistry;
use app\services\query\metric\MetricMoneyFormatter;
use app\services\query\metric\StoreCustomerVisitEligibility;
use app\services\report\StoreReportNormalDataScopeServices;

/** 成长经营联看只调用已有指标读取器；员工服务人次与门店实际到店客流分别取源。 */
final class GrowthBusinessServices
{
    /** 在一致读事务内调用原经营读取器，开关关闭时不返回虚构零指标。 */
    public function read(array $c,array $in): array
    {
        if(!(int)Db::name('growth_setting')->where('id',1)->value('business_link_enabled'))return ['enabled'=>false,'metrics'=>[]];
        $type=(string)($in['object_type']??'employee');$id=(int)($in['object_id']??0);$start=(string)($in['start']??date('Y-m-01'));$end=(string)($in['end']??date('Y-m-d'));foreach([$start,$end] as $d){$v=\DateTimeImmutable::createFromFormat('!Y-m-d',$d);if(!$v||$v->format('Y-m-d')!==$d)throw new AdminException('经营统计日期无效');}if($start>$end||strtotime($end)-strtotime($start)>366*86400)throw new AdminException('经营统计周期须在一年内');
        $range=['start'=>$start,'end'=>$end];$a=app()->make(GrowthAccessServices::class);$stores=[];$pairs=[];
        if($type==='employee'){$a->assertEmployee($c,$id);$name=(string)Db::name('employee')->where('id',$id)->value('name');$stores=array_map('intval',Db::name('system_store_staff')->where('employee_id',$id)->where('is_del',0)->where('status',1)->column('store_id'));if(!$c['super']&&$id!==$c['employee_id'])$stores=array_values(array_intersect($stores,(array)$c['store_ids']));foreach($stores as $s)$pairs[]=['store_id'=>$s,'employee_id'=>$id];$defs=[['staff_sales_yeji','销售业绩','cents'],['staff_labor_yeji','劳动业绩','cents'],['staff_service_num','服务人次','tenths']];}
        elseif($type==='store'){if(!$id||(!$c['super']&&!in_array($id,(array)$c['store_ids'],true)))throw new AdminException('门店不在当前经营查看范围');$name=(string)Db::name('system_store')->where('id',$id)->where('is_del',0)->value('name');$stores=[$id];$defs=[['staff_sales_yeji','销售业绩','cents'],['consume_amount','消耗业绩','cents'],['store_customer_visit','客流','count']];}
        else throw new AdminException('经营观察对象无效');
        if(!$name)throw new AdminException('经营观察对象不存在');if(!$stores)return ['enabled'=>true,'object_type'=>$type,'object_id'=>$id,'object_name'=>$name,'range'=>$range,'scope_store_ids'=>$stores,'metrics'=>[],'unavailable_reason'=>'此员工在当前授权范围内没有可查询的门店任职；成长记录仍可独立使用'];
        return (new MetricReadTransaction())->run(function($reader)use($type,$id,$name,$stores,$pairs,$range,$defs){$cards=[];$dictionary=new \app\services\metric\MetricDictionaryServices();foreach($defs as [$code,$title,$unit]){
                if($code==='store_customer_visit'){
                    // 复用门店资格与去重键，不把含独立岗位额外分配的员工人次相加冒充客流。
                    $q=Db::name('cashier_v3_entitlement_service_fact')->alias('s')->where('s.tenant_id','0')->whereIn('s.store_id',$stores)->where('s.service_status','completed')->whereBetween('s.business_date',[$range['start'],$range['end']]);(new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderServices($q,'s');StoreCustomerVisitEligibility::apply($q,'s');$expression=StoreCustomerVisitEligibility::sqlDailyVisitKey('s');$days=$q->fieldRaw('s.business_date,COUNT(DISTINCT '.$expression.') metric_value')->group('s.business_date')->order('s.business_date')->select()->toArray();$value=array_sum(array_map('intval',array_column($days,'metric_value')));$explanation=StoreCustomerVisitEligibility::EXPLANATION;$version=StoreCustomerVisitEligibility::VERSION;
                }else{$contract=MetricDefinitionRegistry::get($code);$definition=$dictionary->getByCode($code);$explanation=(string)($definition['include']??'').(string)($definition['exclude']??'');$version=(string)($contract['source_version']??$contract['metric_version']??$code);
                    if($type==='employee'){$days=$reader->personnelTotals('0',$stores,$range,$code,$pairs);$value=array_sum(array_map('intval',array_column($days,'metric_value')));}else{$value=$reader->metricTotal('0',$stores,$range,$code);$days=$reader->dailyStoreTotals('0',$stores,$range,$code);}
                }
                $daily=[];foreach($days as $row){$v=(int)($row['metric_value']??$row['amount_cents']??0);$daily[]=['business_date'=>$row['business_date'],'raw_value'=>$v,'display_value'=>$this->display($v,$unit)];}
                $cards[]=['code'=>$code,'title'=>$title,'unit'=>$unit==='cents'?'元':($unit==='tenths'?'人次':'人次'),'raw_unit'=>$unit,'raw_value'=>(int)$value,'display_value'=>$this->display((int)$value,$unit),'value_source'=>$explanation,'metric_version'=>$version,'daily'=>$daily,'drill_params'=>['object_type'=>$type,'object_id'=>$id,'metric'=>$code,'start'=>$range['start'],'end'=>$range['end']]];
            }return ['enabled'=>true,'object_type'=>$type,'object_id'=>$id,'object_name'=>$name,'range'=>$range,'scope_store_ids'=>$stores,'metrics'=>$cards,'data_as_of'=>date('Y-m-d H:i:s'),'aggregation_caught_up'=>true,'notice'=>'经营变化仅作同口径观察；调店、排班、活动和服务机会均可能影响结果，不自动归因为培训。'];});
    }
    private function display(int $value,string $unit): string{if($unit==='cents')return MetricMoneyFormatter::integerYuan($value);if($unit==='tenths')return rtrim(rtrim(number_format($value/10,1,'.',''),'0'),'.');return (string)$value;}
}
