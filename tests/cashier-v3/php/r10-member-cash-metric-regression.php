<?php
/** 逐会员对账注册现金业绩与分析筛选读源，验证三类收款没有重复或遗漏；仅本地只读。 */
require '/var/www/html/vendor/autoload.php';(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
if(strpos((string)config('database.connections.mysql.hostname'),'mysql')===false)throw new Exception('local only');
Db::execute('SET TRANSACTION READ ONLY');Db::startTrans();
try {
 $reader=new app\services\query\metric\RegisteredMetricReadServices();
 $method=new ReflectionMethod($reader,'memberMetricAggregateQuery');$method->setAccessible(true);$range=['start'=>'1900-01-01','end'=>'2026-11-05'];$checks=[];
 foreach([[1],array_map('intval',Db::name('system_store')->column('id'))] as $stores){
  [$q,$expr]=$method->invoke($reader,'cash_performance','0',$stores,$range);
  $old=array_column($q->fieldRaw('s.member_id member_id,'.$expr.' cash_cents')->select()->toArray(),'cash_cents','member_id');
  $new=array_column($reader->memberAnalysisReceiptAggregate('0',$stores,$range)->select()->toArray(),'cash_cents','member_id');
  ksort($old);ksort($new);if(array_keys($old)!==array_keys($new))throw new Exception('cash population changed');
  foreach($old as $id=>$value)if(bccomp((string)$value,(string)$new[$id],0)!==0)throw new Exception('cash member amount differs');
  $checks[]=['stores'=>count($stores),'members'=>count($new)];
 }
 echo json_encode(['result'=>'PASS','registered_cash_metric_equals_analysis'=>true,'checks'=>$checks,'writes'=>0]),"\n";
}finally{Db::rollback();}
