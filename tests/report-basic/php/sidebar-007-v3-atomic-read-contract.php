<?php
declare(strict_types=1);

require (getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3).'/后端代码').'/vendor/autoload.php';

use app\services\report\Sidebar007V3AtomicReadServices;

/** 注册指标应按同一授权范围返回，不能因为报表名称相似就改写 007 单元格。 */
$calls=[];
$reader=new Sidebar007V3AtomicReadServices(static function(string $code,array $stores,array $range)use(&$calls):array {
    $calls[]=$code;
    if($stores!==[3,4]||$range!==['start'=>'2026-10-20','end'=>'2026-10-21'])
        throw new RuntimeException('atomic scope changed');
    return [['store_id'=>3,'metric_value'=>300],['store_id'=>4,'metric_value'=>400]];
});
$result=$reader->read([3,4],['start'=>'2026-10-20','end'=>'2026-10-21']);
if(count($calls)!==6||$result['by_store'][3]['sales_collected_amount']!==300
    ||$result['by_store'][4]['consume_amount']!==400
    ||count($result['parity'])!==6) throw new RuntimeException('registered V3 atomic read contract');
$bad=new Sidebar007V3AtomicReadServices(static fn():array=>[['store_id'=>9,'metric_value'=>1]]);
try{$bad->read([3],['start'=>'2026-10-20','end'=>'2026-10-21']);
    throw new RuntimeException('atomic reader leaked store');}
catch(LogicException $expected){}
echo "sidebar-007-v3-atomic-read-contract PASS\n";
