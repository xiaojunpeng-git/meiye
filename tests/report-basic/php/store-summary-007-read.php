<?php

declare(strict_types=1);

/** 未取得 007 同义 V3 事实前，14 列表头可以核对，指标不可被同名值偷偷填充。 */
require (getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3).'/后端代码').'/vendor/autoload.php';

use app\services\report\StoreSummary007ReadServices;

/** 测试只替换注册 Reader 的返回，验证按授权门店接线及口径隔离。 */
$atomic=static function(string $code,array $stores,array $range):array {
    return array_map(static fn(int $id):array=>['store_id'=>$id,'metric_value'=>$id*100],$stores);
};
$payments=static fn():array=>[];
$legacy=static fn():array=>[['store_id'=>9,'source_order_id'=>'900','expected_cents'=>100],
    ['store_id'=>10,'source_order_id'=>'1000','expected_cents'=>100]];
$consumptionGap=static fn():array=>['complete'=>false,'gaps'=>[['code'=>'LEGACY_WRITEOFF_SOURCE_UNVERIFIED']],
    'by_store'=>[9=>null,10=>null]];
$consumptionFull=static fn():array=>['complete'=>true,'gaps'=>[],
    'by_store'=>[9=>1500,10=>0]];
$newCustomerGap=static fn():array=>['complete'=>false,'gaps'=>[['code'=>'SOURCE_007_MAPPING_UNAVAILABLE']],
    'by_store'=>[]];
$newCustomerFull=static fn():array=>['complete'=>true,'gaps'=>[],
    'by_store'=>[9=>['sales_analysis'=>['new_customer_count'=>3,'casual_customer_count'=>7]],
        10=>['sales_analysis'=>['new_customer_count'=>0,'casual_customer_count'=>0]]]];
$serviceOrderGap=static fn():array=>['complete'=>false,
    'gaps'=>[['code'=>'LEGACY_SERVICE_ORDER_QUALIFICATION_UNVERIFIED']],'by_store'=>[]];
$serviceOrderFull=static fn():array=>['complete'=>true,'gaps'=>[],'by_store'=>[
    9=>['consumption_amount_cents'=>150000,'service_customer_count'=>10,'consumed_project_count'=>14],
    10=>['consumption_amount_cents'=>0,'service_customer_count'=>0,'consumed_project_count'=>0]]];
$soldGap=static fn():array=>['complete'=>false,'by_store'=>[9=>null,10=>null],
    'gaps'=>[['code'=>'STORE_ITEM_007_SOURCE_UNVERIFIED']]];
$soldFull=static fn():array=>['complete'=>true,'by_store'=>[9=>17,10=>0],'gaps'=>[]];
$designatedGap=static fn():array=>['complete'=>false,'by_store'=>[9=>null,10=>null],
    'gaps'=>[['code'=>'DESIGNATED_POINT_LABOR_FACT_MISSING']]];
$designatedFull=static fn():array=>['complete'=>true,'by_store'=>[9=>6,10=>0],'gaps'=>[]];
$insideGap=static fn():array=>['complete'=>false,'by_store'=>[9=>null,10=>null],
    'gaps'=>[['code'=>'PARTNER_SERVICE_FACT_MISSING']]];
$insideFull=static fn():array=>['complete'=>true,'by_store'=>[9=>130000,10=>0],
    'partner_by_store'=>[9=>20000,10=>0],'gaps'=>[]];

$reader=new StoreSummary007ReadServices(static fn(array $stores):array=>[
    ['id'=>9,'name'=>'甲店'],['id'=>10,'name'=>'乙店']],null,static fn():array=>[],
    $atomic,$payments,$legacy,$consumptionGap,$newCustomerGap,$serviceOrderGap,
    $soldGap,$designatedGap,$insideGap);
$result=$reader->read([9,10],['start'=>'2026-10-20','end'=>'2026-10-20']);
$labels=array_slice(array_column($result['fields'],'label'),0,14);
if($labels!==['门店','现金业绩','实际业绩','店内现金业绩','消耗业绩','店内消耗业绩',
    '耗卡/现金','总客数','指定客','新客数','销售项目数','消耗项目数','新客成交率%','新客总客数'])
    throw new RuntimeException('007 store summary visible column contract');
if(count($result['rows'])!==2||$result['complete']||$result['sum_fields']!==[]
    ||!in_array('STORE_SUMMARY_007_METRICS_UNVERIFIED',array_column($result['gaps'],'code'),true))
    throw new RuntimeException('unverified store metrics must fail closed');
foreach($result['rows'] as $row)foreach(array_slice(array_column($result['fields'],'key'),1,13) as $key)
    if($row[$key]!==null)throw new RuntimeException('unverified metric exposed value');
if(count($result['field_sources'])!==count($result['fields']))
    throw new RuntimeException('field sources incomplete');
if($result['v3_atoms']['by_store'][9]['consume_amount']!==900
    ||$result['v3_atoms']['by_store'][10]['completed_service_item_count']!==1000
    ||$result['rows'][0]['consumption_performance']!==null)
    throw new RuntimeException('V3 实数应接入审计结果，未证明 007 同义前不得填充表格');
if(strpos($result['field_sources']['actual_performance'],'合作方人员实际分配')===false
    ||strpos($result['field_sources']['cash_performance'],'原销售与原充值')===false
    ||strpos($result['field_sources']['inside_cash_performance'],'合作方人员实际分配')===false)
    throw new RuntimeException('业务来源说明须准确且不能泄露内部表名');
$certified=new StoreSummary007ReadServices(static fn(array $stores):array=>[
    ['id'=>9,'name'=>'甲店'],['id'=>10,'name'=>'乙店']],
    static fn(array $stores,array $range):array=>[
        ['store_id'=>9,'amount_cents'=>110000,'definition'=>'007-original-order-cash-v3','complete'=>true],
        ['store_id'=>10,'amount_cents'=>20000,'definition'=>'007-original-order-cash-v3','complete'=>true]],
    static fn(array $stores,array $range):array=>[
        ['store_id'=>9,'partner_amount_cents'=>10000,'outsourced_amount_cents'=>5000,
        'refund_deduction_cents'=>-3000,'unclassified_rows'=>0,'unsigned_reversal_rows'=>0]],
    $atomic,$payments,$legacy,$consumptionFull,$newCustomerFull,$serviceOrderFull,
    $soldFull,$designatedFull,$insideFull);
$verified=$certified->read([9,10],['start'=>'2026-10-20','end'=>'2026-10-20']);
if($verified['rows'][0]['cash_performance']!==110000||$verified['rows'][0]['actual_performance']!==100000
    ||$verified['rows'][0]['inside_cash_performance']!==100000
    ||$verified['rows'][1]['actual_performance']!==20000
    ||$verified['rows'][0]['consumption_performance']!==150000
    ||$verified['rows'][0]['inside_consumption_performance']!==130000
    ||$verified['rows'][0]['customer_count']!==10
    ||$verified['rows'][0]['consumed_project_count']!==14
    ||$verified['rows'][0]['sold_project_count']!==17
    ||$verified['rows'][0]['designated_customer_count']!==6
    ||$verified['rows'][0]['consumption_cash_ratio']!==136
    ||$verified['consumption_source']['by_store'][9]!==1500
    ||$verified['rows'][0]['new_customer_count']!==3
    ||$verified['rows'][0]['new_customer_total_count']!==10
    ||$verified['rows'][0]['new_customer_conversion_rate']!==30
    ||$verified['sum_fields']!==['cash_performance','actual_performance',
        'inside_cash_performance',
        'consumption_performance','inside_consumption_performance',
        'customer_count','designated_customer_count','new_customer_count',
        'sold_project_count','consumed_project_count'])
    throw new RuntimeException('007 certified cash minus partner calculation failed');
if($verified['partner_allocation']['separate_facts'][9]['outsourced_amount_cents']!==5000
    ||$verified['partner_allocation']['separate_facts'][9]['refund_deduction_cents']!==-3000)
    throw new RuntimeException('outsourced and refund must stay separate from partner allocation');
$auto=new StoreSummary007ReadServices(static fn():array=>[['id'=>9,'name'=>'甲店']],null,
    static fn():array=>[['store_id'=>9,'partner_amount_cents'=>10000,'outsourced_amount_cents'=>0,
        'refund_deduction_cents'=>0,'unclassified_rows'=>0,'unsigned_reversal_rows'=>0]],
    $atomic,static fn():array=>[['fact_id'=>'r9','store_id'=>9,'order_id'=>'RCH:9','source_line_id'=>'pay-r9',
        'source_document_type'=>'recharge','amount_cents'=>110000,'eligible'=>true]],
    static fn():array=>[],$consumptionGap,$newCustomerGap,$serviceOrderGap,
    $soldGap,$designatedGap,$insideGap);
$autoResult=$auto->read([9],['start'=>'2026-10-20','end'=>'2026-10-20']);
if($autoResult['rows'][0]['cash_performance']!==110000
    ||$autoResult['rows'][0]['actual_performance']!==100000)
    throw new RuntimeException('V3 原充值现金须自动接入门店统计并减 partner 实配');
try{$reader->read([8],['start'=>'2026-10-20','end'=>'2026-10-20']);
    throw new RuntimeException('store scope failed');}
catch(LogicException $expected){}
echo "store-summary-007-read: PASS\n";
