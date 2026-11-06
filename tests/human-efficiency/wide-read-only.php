<?php
/** 集团跨月压力与守恒回归，在PHP默认128MiB限制下验证汇总和流式下钻。 */
require '/var/www/html/vendor/autoload.php';(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
if(config('database.connections.mysql.hostname')!=='mysql'||config('database.connections.mysql.database')!=='ruihao')throw new RuntimeException('Local only');
Db::execute('SET TRANSACTION READ ONLY');Db::startTrans();
try{
 $stores=array_map('intval',Db::name('system_store')->where('is_del',0)->where('name','<>','总部')->column('id'));
 $range=['start'=>'2026-10-01','end'=>'2026-11-05'];$start=microtime(true);
 $service=new app\services\report\HumanEfficiencyDashboardServices();$r=$service->read($stores,$range);
 echo 'QUERY ',json_encode(['stores'=>count($stores),'seconds'=>round(microtime(true)-$start,2),'peak_mib'=>round(memory_get_peak_usage(true)/1048576,2),'beauty_cash_cents'=>$r['beauty']['cash_cents']]),"\n";
 $d=$service->read($stores,$range,['action'=>'detail','entity_id'=>'summary:beauty','metric'=>'cash','page'=>2]);
 if($r['beauty']['cash_cents']!==$d['cash_cents']||count($d['rows'])!==10)throw new RuntimeException('Wide detail mismatch');
 echo 'PASS ',json_encode(['stores'=>count($stores),'detail_total'=>$d['total'],'cash_cents'=>$d['cash_cents'],'peak_mib'=>round(memory_get_peak_usage(true)/1048576,2),'total_seconds'=>round(microtime(true)-$start,2)]),"\n";
}finally{Db::rollback();}
