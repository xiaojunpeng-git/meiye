<?php

declare(strict_types=1);

/** 产品销售按商品×销售人×支付方式拆行，逐人余额金额无权威事实时留空。 */
require (getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3).'/后端代码').'/vendor/autoload.php';

use app\services\report\ProductSales007ReadServices;

$reader=new ProductSales007ReadServices();$project=new ReflectionMethod($reader,'project'); $project->setAccessible(true);
$range=['start'=>'2026-10-20','end'=>'2026-10-20'];
$sales=[['fact_id'=>'product-1','store_id'=>9,'order_id'=>'order-1','source_line_id'=>'line-1',
    'business_date'=>'2026-10-20','store_name'=>'甲店','member_name_snapshot'=>'顾客',
    'item_name_snapshot'=>'产品A','quantity'=>1,'sale_amount_cents'=>1000]];
$people=[
    ['fact_id'=>'person-1','store_id'=>9,'order_id'=>'order-1','source_line_id'=>'line-1',
        'employee_id'=>11,'employee_name_snapshot'=>'员工甲','amount_cents'=>300],
    ['fact_id'=>'person-2','store_id'=>9,'order_id'=>'order-1','source_line_id'=>'line-1',
        'employee_id'=>12,'employee_name_snapshot'=>'员工乙','amount_cents'=>300],
];
$payments=['payments'=>[['fact_id'=>'payment-1','store_id'=>9,'order_id'=>'order-1','amount_cents'=>600]],
    'allocations'=>[['payment_fact_id'=>'payment-1','sale_fact_id'=>'product-1','store_id'=>9,'amount_cents'=>600]],
    'balances'=>[['store_id'=>9,'order_id'=>'order-1','order_line_id'=>'line-1','amount_cents'=>400]]];
$result=$project->invoke($reader,$sales,$people,$payments,[9],$range);
if(count($result['rows'])!==4||$result['complete'])throw new RuntimeException('two people by two payment methods');
$cash=array_values(array_filter($result['rows'],static fn(array $row):bool=>$row['payment_method']==='现金'));
$balance=array_values(array_filter($result['rows'],static fn(array $row):bool=>$row['payment_method']==='余额'));
if(count($cash)!==2||array_sum(array_column($cash,'amount'))!==6
    ||count($balance)!==2||$balance[0]['amount']!==null||$balance[1]['amount']!==null)
    throw new RuntimeException('person cash and unresolved balance amounts');
if(array_slice(array_column($result['fields'],'label'),0,7)!==
    ['日期','门店','客户名称','商品名称','支付方式','金额','销售人'])
    throw new RuntimeException('007 visible fields');
if(count($result['field_sources'])!==count($result['fields']))throw new RuntimeException('field source completeness');
$cashOnly=$payments;$cashOnly['balances']=[];
$proved=$project->invoke($reader,$sales,$people,$cashOnly,[9],$range);
if(!$proved['complete']||count($proved['rows'])!==2||$proved['rows'][0]['amount']===null)
    throw new RuntimeException('cash-only original sale');
$missingPerson=$project->invoke($reader,$sales,[],$payments,[9],$range);
if($missingPerson['complete']||$missingPerson['rows']!==[]
    ||!in_array('SALESPERSON_FACT_MISSING',array_column($missingPerson['gaps'],'code'),true))
    throw new RuntimeException('missing salesperson must not be invented');
$selfWithoutParticipation=$project->invoke($reader,$sales,[],$payments,[9],$range,11);
if(!$selfWithoutParticipation['complete']||$selfWithoutParticipation['rows']!==[])
    throw new RuntimeException('self filter narrows rather than creating missing-person gap');
try{$project->invoke($reader,$sales,$people,$payments,[8],$range);throw new RuntimeException('store scope');}
catch(LogicException $expected){}
$failClosed=new ReflectionMethod($reader,'failClosed'); $failClosed->setAccessible(true);
$blocked=$failClosed->invoke($reader,[9],$range,0,['complete'=>false,'gaps'=>[
    ['code'=>'LEGACY_PRODUCT_SALE_FACT_MISSING','source_count'=>3]]]);
if($blocked['complete']||$blocked['rows']!==[]||$blocked['sum_fields']!==[]
    ||$blocked['gaps'][0]['code']!=='LEGACY_PRODUCT_SALE_FACT_MISSING')
    throw new RuntimeException('partial old source must return no user-visible rows');
$legacySales=$sales;$legacySales[0]['source_document_type']='legacy_store_order';
$legacyPeople=$people;
foreach($legacyPeople as &$legacyPerson)$legacyPerson['source_document_type']='legacy_store_order';
unset($legacyPerson);
$legacyPayments=$cashOnly;
$legacyPayments['payments'][0]['source_document_type']='legacy_store_order';
$legacyResult=$project->invoke($reader,$legacySales,$legacyPeople,$legacyPayments,[9],$range);
if(!$legacyResult['complete']||count($legacyResult['rows'])!==2)
    throw new RuntimeException('legacy product salesperson source');
$eligibleSales=new ReflectionMethod($reader,'eligibleSales');$eligibleSales->setAccessible(true);
$unqualified=$legacySales[0];$unqualified['fact_id']='legacy-unqualified';
$newV3=$sales[0];$newV3['fact_id']='new-v3';$newV3['source_document_type']='cashier_snapshot';
$bounded=$eligibleSales->invoke($reader,[$legacySales[0],$unqualified,$newV3],
    ['eligible_legacy_fact_ids'=>['product-1']]);
if(array_column($bounded,'fact_id')!==['product-1','new-v3'])
    throw new RuntimeException('ineligible imported old cart must stay outside 007 result');
$wrongSource=$legacyPeople;$wrongSource[0]['source_document_type']='cashier_snapshot';
try{$project->invoke($reader,$legacySales,$wrongSource,$legacyPayments,[9],$range);
    throw new RuntimeException('mixed salesperson source must be rejected');}
catch(LogicException $expected){}
echo "product-sales-007-read: PASS\n";
