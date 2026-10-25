<?php
/** R34 逐实例两字段配置修正：按用户指令不落盘备份原值，不改已删除门店或其他资料。 */
$site=$argv[1]??'';$mode=$argv[2]??'';
$sites=['rh'=>['rh.cc3798.com','ruihao','3306'],'008'=>['008.cc3798.com','mohe_008','3307'],'010'=>['010.cc3798.com','mohe_010_app','3307']];
if(!isset($sites[$site])||!in_array($mode,['inspect','apply','verify'],true))throw new RuntimeException('EXPLICIT_INSTANCE_MODE_REQUIRED');
[$domain,$database,$port]=$sites[$site];$root='/www/wwwroot/'.$domain;
$ini=parse_ini_file($root.'/.env',true,INI_SCANNER_RAW);$cfg=array_change_key_case($ini['DATABASE']??$ini['database']??[]);
if($cfg['database']!==$database||(string)$cfg['hostport']!==$port||$cfg['prefix']!=='eb_')throw new RuntimeException('INSTANCE_MISMATCH');
$db=new PDO('mysql:host='.$cfg['hostname'].';port='.$port.';dbname='.$database.';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$identity=$db->query('SELECT DATABASE() AS db,@@port AS port')->fetch();if($identity['db']!==$database||(string)$identity['port']!==$port)throw new RuntimeException('DATABASE_IDENTITY_MISMATCH');
// The comparison digest excludes only the authorized fields. Old field values stay in memory only.
$otherDigest=static function($rows){foreach($rows as &$r){unset($r['delivery_type'],$r['default_delivery']);}unset($r);return hash('sha256',json_encode($rows));};
if($mode!=='apply')$db->exec('SET TRANSACTION READ ONLY');$db->beginTransaction();
try{
 $rows=$db->query('SELECT * FROM eb_system_store WHERE is_del=0 ORDER BY id'.($mode==='apply'?' FOR UPDATE':''))->fetchAll();
 $deleted=$db->query('SELECT * FROM eb_system_store WHERE is_del<>0 ORDER BY id')->fetchAll();$deletedSHA=hash('sha256',json_encode($deleted));$otherSHA=$otherDigest($rows);$changed=0;$ids=[];
 foreach($rows as $r){$ids[]=(int)$r['id'];if((string)$r['delivery_type']!=='2'||(int)$r['default_delivery']!==2)$changed++;}
 if($mode==='apply'){
  // Disabled but undeleted stores are included; change only the two requested configuration columns.
  $q=$db->prepare("UPDATE eb_system_store SET delivery_type='2',default_delivery=2 WHERE id=? AND is_del=0");foreach($ids as $id)$q->execute([$id]);
  $after=$db->query('SELECT * FROM eb_system_store WHERE is_del=0 ORDER BY id')->fetchAll();
  if($otherDigest($after)!==$otherSHA||hash('sha256',json_encode($db->query('SELECT * FROM eb_system_store WHERE is_del<>0 ORDER BY id')->fetchAll()))!==$deletedSHA)throw new RuntimeException('UNAUTHORIZED_COLUMN_CHANGE');
 }else{$after=$rows;}
 $remaining=0;foreach($after as $r)if((string)$r['delivery_type']!=='2'||(int)$r['default_delivery']!==2)$remaining++;
 if($mode!=='inspect'&&$remaining!==0)throw new RuntimeException('DEFAULTS_NOT_APPLIED');
 if($mode==='apply')$db->commit();else $db->rollBack();
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
$cacheVerified=0;
if($mode!=='inspect'){
 require $root.'/vendor/autoload.php';(new think\App($root.'/'))->initialize();
 $appCfg=config('database.connections.mysql');if($appCfg['database']!==$database||(string)$appCfg['hostport']!==$port)throw new RuntimeException('APP_DATABASE_MISMATCH');
 $dao=app()->make(app\dao\store\SystemStoreDao::class);$service=app()->make(app\services\store\SystemStoreServices::class);
 foreach($ids as $id){
  if($mode==='apply'){
   // Refresh only this instance's store cache from the committed authority, never flush shared Redis.
   $info=$dao->get($id);$dao->cacheUpdate($info->toArray());
  }
  $cached=$service->getStoreDisposeCache($id);$direct=$service->getStoreDispose($id);
  foreach([$cached,$direct] as $value)if(array_map('strval',(array)$value['delivery_type'])!==['2']||(int)$value['default_delivery']!==2)throw new RuntimeException('STORE_READBACK_MISMATCH');
  $cacheVerified++;
 }
 if($mode==='apply')$service->clearEntryStoreCache();
}
echo json_encode(['site'=>$site,'database'=>$database,'mode'=>$mode,'existingStores'=>count($rows),'changedStores'=>$mode==='apply'?$changed:0,'remainingMismatches'=>$remaining,'cacheAndDetailVerified'=>$cacheVerified,'otherColumnsSHA256'=>$otherSHA,'deletedStoresUnchanged'=>true,'oldValuesBackedUp'=>false,'result'=>'PASS'],JSON_UNESCAPED_UNICODE),PHP_EOL;
