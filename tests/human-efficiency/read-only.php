<?php
/** 本地真实事实对账：只允许Docker ruihao；所有读取包在只读事务中，不连接客户实例。 */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
use app\services\report\HumanEfficiencyFactReader;
use app\services\report\HumanEfficiencyMetadataServices;
use app\services\report\StoreSalespersonNetReadServices;
use app\services\query\metric\RegisteredMetricReadServices;
if (config('database.connections.mysql.hostname') !== 'mysql' || config('database.connections.mysql.database') !== 'ruihao') throw new RuntimeException('Only local Docker ruihao is allowed');
function expect($ok, $label) { if (!$ok) throw new RuntimeException($label); echo 'PASS ', $label, "\n"; }
Db::execute('SET TRANSACTION READ ONLY'); Db::startTrans();
try {
    $reader = new HumanEfficiencyFactReader(); $range = ['start'=>'2026-10-01','end'=>'2026-11-05']; $stores = [23];
    $sum=0; $count=0; $inside=true;
    foreach ($reader->personnel($stores,$range,'staff_sales_yeji') as $row) {
        $inside=$inside && (int)$row['store_id']===23;
        $sum+=(int)$row['metric_value']; $count++;
    }
    expect($inside, '事实没有越出门店范围');
    $expected=(new StoreSalespersonNetReadServices())->matrix($stores,$range);
    expect($sum===(int)$expected['summary']['total_metric_value'], '人员净业绩与现有销售人报表完全一致');
    expect($count>0,'覆盖真实有业绩数据');
    $metadata=(new HumanEfficiencyMetadataServices())->metadata();
    $ids=array_column($metadata['categories'],'id');
    $expectedIds=Db::name('store_product_category')->where('type',0)->where('relation_id',0)->where('pid',0)->where('statistics_enabled',1)->order('sort','desc')->order('id','desc')->column('id');
    expect($ids===array_map('intval',$expectedIds),'实际分类来自平台配置，顺序与集团看板一致');
    $total=0;foreach($reader->cash($stores,$range) as $row)$total+=(int)$row['amount_cents'];
    expect($total===(new RegisteredMetricReadServices())->summary('cash_performance','0',$stores,$range),'分类现金总额与注册现金指标一致');
    try{$reader->staff([]);throw new RuntimeException('missing scope accepted');}catch(InvalidArgumentException $e){echo "PASS 空权限范围拒绝读取\n";}
} finally {Db::rollback();}
