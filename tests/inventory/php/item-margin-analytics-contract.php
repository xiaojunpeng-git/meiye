<?php
declare(strict_types=1);
$root = dirname(__DIR__, 3);
require $root . '/后端代码/app/services/product/inventory/query/InventoryItemMarginAnalyticsServices.php';
use app\services\product\inventory\query\InventoryItemMarginAnalyticsServices;
$service = new InventoryItemMarginAnalyticsServices();
$rows = $service->analyze([
 ['project_id'=>1,'project_name'=>'深层补水','direction'=>1,'consumption_amount_cents'=>30000,'actual_cost_cents'=>8600,'estimated_shortage_cost_cents'=>0,'cost_complete'=>true],
 ['project_id'=>1,'project_name'=>'深层补水','direction'=>1,'consumption_amount_cents'=>30000,'actual_cost_cents'=>8600,'estimated_shortage_cost_cents'=>0,'cost_complete'=>true],
 ['project_id'=>2,'project_name'=>'舒敏修护','direction'=>1,'consumption_amount_cents'=>40000,'actual_cost_cents'=>12000,'estimated_shortage_cost_cents'=>3000,'cost_complete'=>false],
], true);
$byProject = [];
foreach ($rows as $row) $byProject[$row['project_id']] = $row;
$failed=0; function marginAssert($n,$c){global $failed;echo($c?'PASS ':'FAIL ').$n."\n";if(!$c)$failed++;}
marginAssert('total and per-consumption gross profit are distinct', $byProject[1]['gross_profit']==='428.00' && $byProject[1]['single_gross_profit']==='214.00');
marginAssert('incomplete cost suppresses gross margin', $byProject[2]['cost_complete']===false && $byProject[2]['gross_margin']===null && $byProject[2]['estimated_shortage_cost']==='30.00');
marginAssert('cost permission hides all cost metrics', $service->analyze([['project_id'=>1,'project_name'=>'深层补水','direction'=>1,'consumption_amount_cents'=>30000,'actual_cost_cents'=>8600,'estimated_shortage_cost_cents'=>0,'cost_complete'=>true]], false)[0]['gross_profit']===null);
exit($failed===0?0:1);
