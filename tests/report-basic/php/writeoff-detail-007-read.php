<?php

declare(strict_types=1);

/** 核销明细：007列顺序、服务行粒度、手艺人快照及缺下单门店的明确缺口。 */
require (getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3).'/后端代码').'/vendor/autoload.php';

use app\services\report\WriteoffDetail007ReadServices;

$reader=new WriteoffDetail007ReadServices();
$project=new ReflectionMethod($reader,'project'); $project->setAccessible(true);
$date=['start'=>'2026-10-20','end'=>'2026-10-20'];
$service=[['id'=>41,'service_fact_id'=>'service-41','store_id'=>9,'store_name_snapshot'=>'核销店',
    'member_id'=>81,'member_name_snapshot'=>'顾客','document_no_snapshot'=>'WO-41',
    'project_name_snapshot'=>'护理','project_category_name_snapshot'=>'身体',
    'quantity'=>2,'operator_name_snapshot'=>'操作人','business_date'=>'2026-10-20',
    'occurred_at'=>strtotime('2026-10-19 23:59:00 Asia/Shanghai'),
    'checkout_request_id'=>'checkout-41','source_line_id'=>'line-41',
    'source_document_type'=>'sales_order','source_document_id'=>5,
    'craftsmen_snapshot_json'=>json_encode([['staff_id'=>1,'staff_name_snapshot'=>'手艺人','is_point_customer'=>1]],JSON_UNESCAPED_UNICODE)]];
$amount=[['fact_id'=>'consumption-41','store_id'=>9,'checkout_request_id'=>'checkout-41',
    'source_line_id'=>'line-41','amount_cents'=>1888]];
$source=['phones'=>[81=>'13800000000'],'ordering_store_ids'=>['service-41'=>8],
    'store_names'=>[8=>'下单店']];
$result=$project->invoke($reader,$service,$amount,$source,[9],$date);
$row=$result['rows'][0]??[];
if(!$result['complete']||count($result['rows'])!==1||$row['id']!==41
    ||$row['craftsmen']!=='手艺人(点)'||$row['writeoff_amount']!==18.88
    ||$row['ordering_store']!=='下单店'||$row['writeoff_store']!=='核销店'
    ||$row['writeoff_time']!=='2026-10-20')
    throw new RuntimeException('writeoff service fact projection');
if(array_slice(array_column($result['fields'],'label'),0,13)!==
    ['ID','订单号','客户名称','手机号','商品分类','商品名称','手艺人','核销数量','核销金额',
        '下单门店','核销门店','核销人员','核销时间'])throw new RuntimeException('007 field order');
if(count($result['field_sources'])!==count($result['fields']))throw new RuntimeException('field source completeness');
$legacy=$service;$legacy[0]['source_document_type']='legacy_paid_project';
$legacy[0]['source_document_id']=707;
$legacyProjected=$project->invoke($reader,$legacy,$amount,$source,[9],$date);
if(!$legacyProjected['complete']||$legacyProjected['rows'][0]['id']!==707
    ||$legacyProjected['rows'][0]['ordering_store']!=='下单店')
    throw new RuntimeException('imported 007 writeoff displays source ID and verified original store');
$direct=$service;$direct[0]['source_document_type']='direct';$direct[0]['source_document_id']=0;
$missing=$project->invoke($reader,$direct,$amount,['phones'=>$source['phones'],
    'ordering_store_ids'=>[],'store_names'=>$source['store_names']],[9],$date);
if($missing['complete']||$missing['rows'][0]['ordering_store']!==null
    ||!in_array('ORDERING_STORE_UNAVAILABLE',array_column($missing['gaps'],'code'),true))
    throw new RuntimeException('direct service ordering store must not be guessed');
$guest=$service;$guest[0]['member_id']=0;
$guestResult=$project->invoke($reader,$guest,$amount,$source,[9],$date);
if(!$guestResult['complete']||$guestResult['rows'][0]['phone']!==''
    ||in_array('CURRENT_PHONE_UNAVAILABLE',array_column($guestResult['gaps'],'code'),true))
    throw new RuntimeException('legitimate guest has no phone without source gap');
$noAmount=$project->invoke($reader,$service,[],$source,[9],$date);
if($noAmount['complete']||$noAmount['rows'][0]['writeoff_amount']!==null)
    throw new RuntimeException('missing consumption amount must not show zero');
$orphanAmount=$amount;$orphanAmount[]=['fact_id'=>'orphan-42','store_id'=>9,
    'checkout_request_id'=>'checkout-other','source_line_id'=>'line-other','amount_cents'=>500];
$orphan=$project->invoke($reader,$service,$orphanAmount,$source,[9],$date);
if($orphan['complete']||!in_array('WRITEOFF_ORPHAN_AMOUNT',array_column($orphan['gaps'],'code'),true))
    throw new RuntimeException('unmatched consumption amount must surface gap');
try{$project->invoke($reader,$service,$amount,$source,[8],$date);throw new RuntimeException('store scope');}
catch(LogicException $expected){}
echo "writeoff-detail-007-read: PASS\n";
