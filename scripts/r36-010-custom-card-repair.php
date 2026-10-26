<?php
/** 仅010补齐缺失的定制卡配置；只执行正本幂等SQL，不覆盖管理员已有选择，不清全库缓存。 */
$dir=$argv[1]??'';$mode=$argv[2]??'';
if($dir!=='/www/backups/010/r36-custom-card-entry-20261026'||!in_array($mode,['apply','resume-cache','verify'],true))throw new RuntimeException('EXPLICIT_010_SCOPE');
$root='/www/wwwroot/010.cc3798.com';require $root.'/vendor/autoload.php';(new think\App($root.'/'))->initialize();
$c=config('database.connections.mysql');if($c['database']!=='mohe_010_app'||(string)$c['hostport']!=='3307')throw new RuntimeException('WRONG_INSTANCE');
use think\facade\Db;
set_exception_handler(static function(Throwable $e):void{file_put_contents('php://stderr',$e->getMessage().PHP_EOL);exit(1);});
$key='cashier_custom_card_entry_switch';$receipt=$dir.'/repair.json';
$otherHash=static function()use($key){return hash('sha256',json_encode(Db::name('system_config')->where('menu_name','<>',$key)->order('id')->select()->toArray()));};
if($mode==='apply'){
 if(is_file($receipt))throw new RuntimeException('ALREADY_RECORDED_VERIFY_FIRST');
 $sql=file_get_contents($dir.'/upgrade.sql');if(hash('sha256',$sql)!=='3cad0b6459e910e14d84a5e7e29184e1c6d2e713b048e7b162c2906ee70a5365')throw new RuntimeException('CANONICAL_SQL_MISMATCH');
 Db::startTrans();try{
  $rows=Db::name('system_config')->where('menu_name',$key)->lock(true)->select()->toArray();if($rows)throw new RuntimeException('CONFIG_ALREADY_EXISTS_PRESERVED');
  $before=['site'=>'010','config'=>$key,'rows'=>$rows,'otherConfigurationSHA256'=>$otherHash(),'canonicalUpgradeSHA256'=>hash('sha256',$sql)];
  if(file_put_contents($dir.'/before.json',json_encode($before,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))===false)throw new RuntimeException('BACKUP_FAILED');chmod($dir.'/before.json',0600);
  Db::execute($sql);$row=Db::name('system_config')->where('menu_name',$key)->where('is_store',0)->find();
  if(!$row||(string)$row['value']!=='1'||Db::name('system_config')->where('menu_name',$key)->count()!==1||$otherHash()!==$before['otherConfigurationSHA256'])throw new RuntimeException('SCOPED_INSERT_FAILED');
  Db::commit();
 }catch(Throwable $e){Db::rollback();throw $e;}
 // 单键失效；保持其他实例和其他系统配置缓存不变。
 mohe\services\CacheService::redisHandler()->delete('system_config:'.$key);
 file_put_contents($receipt,json_encode(['site'=>'010','insertedId'=>(int)$row['id'],'config'=>$key,'value'=>1,'database'=>'mohe_010_app','otherConfigurationSHA256'=>$before['otherConfigurationSHA256'],'rollback'=>'仅在新行仍未被修改时按insertedId移除该补齐行，并失效同一缓存键'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));chmod($receipt,0600);
}
// 仅用于确认插入已提交、但缓存步骤中断的恢复；不重跑INSERT或覆盖现有值。
if($mode==='resume-cache'){
 if(is_file($receipt))throw new RuntimeException('RECEIPT_EXISTS_VERIFY_ONLY');
 $before=json_decode(file_get_contents($dir.'/before.json'),true);$row=Db::name('system_config')->where('menu_name',$key)->where('is_store',0)->find();
 if($before['rows']!==[]||!$row||(int)$row['id']!==835||(string)$row['value']!=='1'||$otherHash()!==$before['otherConfigurationSHA256'])throw new RuntimeException('RECOVERY_BASELINE_MISMATCH');
 mohe\services\CacheService::redisHandler()->delete('system_config:'.$key);
 file_put_contents($receipt,json_encode(['site'=>'010','insertedId'=>(int)$row['id'],'config'=>$key,'value'=>1,'database'=>'mohe_010_app','otherConfigurationSHA256'=>$before['otherConfigurationSHA256'],'recovery'=>'insert committed; only cache invalidation resumed','rollback'=>'only remove this unchanged inserted ID and invalidate its cache'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));chmod($receipt,0600);
}
Db::execute('SET TRANSACTION READ ONLY');Db::startTrans();try{
 $row=Db::name('system_config')->where('menu_name',$key)->where('is_store',0)->find();$effective=sys_config($key,1);$types=app\services\cashier\v3\cashier\CashierV3SaleCatalogServices::visibleCatalogTypes();
 if(!$row||(string)$row['value']!=='1'||(int)$effective!==1||!in_array('定制卡',$types,true))throw new RuntimeException('CONFIG_READBACK_FAILED');
 $r=json_decode(file_get_contents($receipt),true);if($otherHash()!==$r['otherConfigurationSHA256'])throw new RuntimeException('OTHER_CONFIGURATION_CHANGED');
 echo json_encode(['site'=>'010','mode'=>$mode,'result'=>'PASS','insertedId'=>(int)$row['id'],'effectiveValue'=>$effective,'catalogTypes'=>$types,'otherConfigurationsUnchanged'=>true,'backup'=>$dir,'productionRowsInserted'=>1,'productionExistingRowsUpdated'=>0],JSON_UNESCAPED_UNICODE),PHP_EOL;
}finally{Db::rollback();}
