<?php

declare(strict_types=1);

/** 007 项目消耗：服务数量不随消费事实拆行，金额缺源时不得显示零。 */
require (getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3).'/后端代码').'/vendor/autoload.php';

use app\services\report\ItemConsumption007ReadServices;

$service = new ItemConsumption007ReadServices();
$project = new ReflectionMethod($service, 'project'); $project->setAccessible(true);
$range = ['start'=>'2026-10-20','end'=>'2026-10-20'];
$quantity = [[
    'service_fact_id'=>'service-1','store_id'=>9,'store_name_snapshot'=>'甲店','source_line_id'=>'line-1',
    'item_id'=>101,'project_name_snapshot'=>'护理','category_id'=>7,'category_path'=>'身体/护理','quantity'=>2,
]];
$amount = [[
    'fact_id'=>'consume-1','store_id'=>9,'checkout_request_id'=>'checkout-1','source_line_id'=>'line-1',
    'amount_cents'=>1234,
]];
$snapshots = ['service-1'=>['service_fact_id'=>'service-1','store_id'=>9,'checkout_request_id'=>'checkout-1']];
$result = $project->invoke($service, $quantity, $amount, $snapshots, [9], $range);
if (!$result['complete'] || count($result['rows'])!==1 || $result['rows'][0]['consumption_quantity']!==2.0
    || $result['rows'][0]['consumption_amount']!==12.34) throw new RuntimeException('service amount/quantity grain');
if (array_slice(array_column($result['fields'],'label'),0,5)!==['门店','分类','名称','消耗金额','消耗数量'])
    throw new RuntimeException('007 visible field order');
if (count($result['field_sources'])!==count($result['fields'])) throw new RuntimeException('field source completeness');
$missing = $project->invoke($service,$quantity,[],$snapshots,[9],$range);
if ($missing['complete'] || $missing['rows'][0]['consumption_amount']!==null
    || in_array('consumption_amount',$missing['sum_fields'],true)) throw new RuntimeException('missing amount must not show zero');
$orphan = $amount;
$orphan[] = ['fact_id'=>'consume-orphan','store_id'=>9,'checkout_request_id'=>'checkout-other',
    'source_line_id'=>'line-other','amount_cents'=>500];
$unmatched = $project->invoke($service,$quantity,$orphan,$snapshots,[9],$range);
if ($unmatched['complete'] || in_array('consumption_amount',$unmatched['sum_fields'],true)
    || !in_array('CONSUMPTION_ORPHAN_AMOUNT',array_column($unmatched['gaps'],'code'),true))
    throw new RuntimeException('unmatched consumption must surface gap and disable money sum');
try {$project->invoke($service,$quantity,$amount,$snapshots,[8],$range); throw new RuntimeException('scope not enforced');}
catch (LogicException $expected) {}
$partition=new ReflectionMethod($service,'partitionStores');$partition->setAccessible(true);
$batches=$partition->invoke(null,[105,106,107,108],[105=>2500,106=>2000,107=>2000,108=>0],4500);
if($batches!==[[105,106],[107,108]])throw new RuntimeException('authorized stores must partition without duplication');
try {$partition->invoke(null,[105],[105=>10001],4500);throw new RuntimeException('single store source cap');}
catch (RuntimeException $expected) {}
echo "item-consumption-007-read: PASS\n";
