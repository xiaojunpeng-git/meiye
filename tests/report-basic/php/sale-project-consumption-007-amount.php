<?php

declare(strict_types=1);

/** 即买即用整行核销金额只可来自同一已结算原销售行，余额成交额不按现金收款缩小。 */
require (getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3).'/后端代码').'/vendor/autoload.php';

use app\services\report\SaleProjectConsumption007AmountReadServices;

$reader=new SaleProjectConsumption007AmountReadServices();
$method=new ReflectionMethod($reader,'project');$method->setAccessible(true);
$key=json_encode([9,'checkout-1','line-1']);
$service=['service_fact_id'=>'SSF-1','store_id'=>9,'member_id'=>11,'checkout_request_id'=>'checkout-1',
    'source_line_id'=>'line-1','source_document_type'=>'sales_order','source_document_id'=>55,
    'document_id'=>'order-1','project_id'=>71,'quantity'=>'1.5'];
$header=['id'=>55,'order_id'=>'order-1','store_id'=>9,'member_id'=>11,
    'checkout_request_id'=>'checkout-1','order_status'=>'settled','order_direction'=>'forward'];
$sale=['fact_id'=>'sale-1','store_id'=>9,'member_id'=>11,'checkout_request_id'=>'checkout-1',
    'source_line_id'=>'line-1','order_id'=>'order-1','item_id'=>71,'quantity'=>'1.5',
    'sale_amount_cents'=>1500];
$result=$method->invoke($reader,[$key=>$service],['order-1'=>$header],[$key=>[$sale]]);
if($result['gaps']!==[]||count($result['rows'])!==1||$result['rows'][0]['amount_cents']!==1500)
    throw new RuntimeException('same whole paid project line must map exact settled sale cents');
$wrongQuantity=$sale;$wrongQuantity['quantity']='1';
$bad=$method->invoke($reader,[$key=>$service],['order-1'=>$header],[$key=>[$wrongQuantity]]);
if($bad['rows']!==[]||$bad['gaps'][0]['code']!=='SALE_PROJECT_CONSUMPTION_SOURCE_UNVERIFIED')
    throw new RuntimeException('partial or different-unit service must not borrow full sale amount');
$voided=$header;$voided['order_status']='voided';
if($method->invoke($reader,[$key=>$service],['order-1'=>$voided],[$key=>[$sale]])['rows']!==[])
    throw new RuntimeException('voided original order must not generate writeoff amount');
if($method->invoke($reader,[$key=>$service],['order-1'=>$header],[$key=>[$sale,$sale]])['rows']!==[])
    throw new RuntimeException('duplicate source sale row must fail closed');
echo "sale-project-consumption-007-amount: PASS\n";
