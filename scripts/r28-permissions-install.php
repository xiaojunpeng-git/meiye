<?php
declare(strict_types=1);
/** 固定包逐实例安装器：只迁移门店权限元数据，保留各客户岗位、其他终端与业务事实。 */
$site=$argv[1]??'';$mode=$argv[2]??'';$directory=$argv[3]??'';
$sites=['rh'=>['/www/wwwroot/rh.cc3798.com','ruihao','3306'],'008'=>['/www/wwwroot/008.cc3798.com','mohe_008','3307'],'010'=>['/www/wwwroot/010.cc3798.com','mohe_010_app','3307']];
if(!isset($sites[$site])||!in_array($mode,['prepare','install','verify','rollback'],true)||strpos($directory,'/www/backups/'.$site.'/20261023-r28-')!==0)throw new RuntimeException('EXPLICIT_INSTANCE_REQUIRED');
set_exception_handler(static function(Throwable $e):void{file_put_contents('php://stderr',$e->getMessage().PHP_EOL);exit(1);});
[$root,$database,$port]=$sites[$site];$env=parse_ini_file($root.'/.env',true,INI_SCANNER_RAW);$cfg=array_change_key_case($env['DATABASE']??$env['database']??[]);
if($cfg['database']!==$database||(string)$cfg['hostport']!==$port||$cfg['prefix']!=='eb_')throw new RuntimeException('DATABASE_MISMATCH');
$db=new PDO('mysql:host='.$cfg['hostname'].';port='.$port.';dbname='.$database.';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$manifest=json_decode(file_get_contents($directory.'/payload/manifest.json'),true,512,JSON_THROW_ON_ERROR);
$hash=static function(string $p):?string{return is_file($p)?hash_file('sha256',$p):null;};
$check=static function(string $p,?string $h)use($hash):void{if($hash($p)!==$h)throw new RuntimeException('FINGERPRINT_MISMATCH: '.$p);};
$copy=static function(string $src,string $dst):void{if(!is_dir(dirname($dst)))mkdir(dirname($dst),0755,true);$tmp=$dst.'.r28-'.getmypid();if(!copy($src,$tmp))throw new RuntimeException('COPY_FAILED');chmod($tmp,0644);$owner=is_file($dst)?$dst:dirname($dst);chown($tmp,fileowner($owner));chgrp($tmp,filegroup($owner));if(!rename($tmp,$dst))throw new RuntimeException('RENAME_FAILED');};
$before=$manifest['before'][$site];$preparedFile=$directory.'/prepared.json';
if($mode==='prepare'){
 if(is_file($preparedFile))throw new RuntimeException('BACKUP_EXISTS');$targets=[];
 foreach($manifest['backend'] as $f=>$h)$targets[$root.'/'.$f]=['source'=>$directory.'/payload/backend/'.$f,'before'=>$before['backend'][$f]??null,'after'=>$h];
 // 新资源可并存；入口最后切换。瑞昊预览只同步本轮门店包，平台预览保持原状。
 foreach($manifest['frontends'] as $kind=>$assets){$roots=[$root];if($kind==='cashier'&&$site==='rh')$roots[]=$root.'-8080';foreach($roots as $r)foreach($assets as $f=>$h){$dest=$r.'/public/'.($kind==='cashier'?'view_cashier_v3/':'').$f;$prior=$hash($dest);if(preg_match('~\.[a-f0-9]{8}\.~',$f)&&$prior!==null&&$prior!==$h)throw new RuntimeException('IMMUTABLE_ASSET_COLLISION');$targets[$dest]=['source'=>$directory.'/payload/'.$kind.'/'.$f,'before'=>$prior,'after'=>$h];}}
 foreach($targets as $dest=>&$item){$check($dest,$item['before']);$check($item['source'],$item['after']);$item['backup']=$directory.'/before/files/'.hash('sha256',$dest);if($item['before']!==null){if(!is_dir(dirname($item['backup'])))mkdir(dirname($item['backup']),0700,true);copy($dest,$item['backup']);$check($item['backup'],$item['before']);}}unset($item);
 if(!is_dir($directory.'/before'))mkdir($directory.'/before',0700,true);
 // 独立受保护的元数据备份；不包含订单/金额/会员业务，不把客户配置下载到本机。
 $tables=['eb_job_position_channel_rule','eb_system_menus','eb_database_upgrade_log'];$backup=[];
 $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$db->beginTransaction();try{foreach($tables as $table){$backup[$table]=['schema'=>$db->query('SHOW CREATE TABLE '.$table)->fetch(),'rows'=>$db->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll()];}}finally{$db->rollBack();}
 $bytes=json_encode($backup,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);file_put_contents($directory.'/before/metadata.json.gz',gzencode($bytes));chmod($directory.'/before/metadata.json.gz',0600);
 $rows=$backup['eb_job_position_channel_rule']['rows'];if(hash('sha256',json_encode($rows,JSON_UNESCAPED_UNICODE))!==$before['channelRules']['sha256'])throw new RuntimeException('ROLE_BASELINE_CHANGED');
 file_put_contents($preparedFile,json_encode(['site'=>$site,'commit'=>$manifest['commit'],'targets'=>$targets,'backupSha256'=>$hash($directory.'/before/metadata.json.gz')],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
}else{
 $prepared=json_decode(file_get_contents($preparedFile),true,512,JSON_THROW_ON_ERROR);if($prepared['site']!==$site||$prepared['commit']!==$manifest['commit'])throw new RuntimeException('WRONG_PREPARED_PACKAGE');$targets=$prepared['targets'];$check($directory.'/before/metadata.json.gz',$prepared['backupSha256']);
 foreach($before['protected'] as $f=>$h)$check($root.'/'.$f,$h);
 if($mode==='install'){
  foreach($targets as $dest=>$item){$check($dest,$item['before']);$check($item['source'],$item['after']);}
  if(hash('sha256',json_encode($db->query('SELECT * FROM eb_job_position_channel_rule ORDER BY id')->fetchAll(),JSON_UNESCAPED_UNICODE))!==$before['channelRules']['sha256'])throw new RuntimeException('ROLE_CHANGED_BEFORE_INSTALL');
  // 所有本轮后端先就绪，再显式初始化；不在普通 HTTP 请求中变更岗位。
  foreach($targets as $dest=>$item)if(strpos($dest,$root.'/app/')===0||strpos($dest,$root.'/route/')===0||strpos($dest,$root.'/database/')===0)$copy($item['source'],$dest);
  $sql=$directory.'/payload/02-upgrade.sql';$check($sql,$manifest['migrationSha256']);foreach(explode(';',file_get_contents($sql))as $q)if(trim($q)!==''){
   // 受限运行账号无需DDL权限：表由独立升级通道预建后，只执行本实例配置写入。
   if(preg_match('~CREATE TABLE IF NOT EXISTS `(eb_store_v3_permission_(?:catalog|audit))`~',$q,$m)&&$db->query('SHOW TABLES LIKE '.$db->quote($m[1]))->fetch())continue;
   $db->exec($q);
  }
  require $root.'/vendor/autoload.php';(new think\App($root.'/'))->initialize();
  $service=new app\services\organization\StorePermissionCatalogServices();$service->initializeLegacyCatalog($sql);$service->initializeNavigationGroups();$service->initializeOrderNavigation();$service->initializeNavigationItems();
  // 三站使用产品经理验收的同一瑞昊菜单配置，岗位授权绝不跨客户复制。
  $rules=json_decode(file_get_contents($directory.'/payload/menu-config.json'),true,512,JSON_THROW_ON_ERROR)['rules'];$snapshot=$service->snapshot();$service->save(['revision'=>$snapshot['revision'],'rules'=>$rules],0);
  $q=$db->prepare('UPDATE eb_database_upgrade_log SET code_version=? WHERE upgrade_key=?');$q->execute([$manifest['commit'],'20261023-r28-store-permission-catalog']);
  foreach($targets as $dest=>$item)if(!in_array(basename($dest),['index.html','system.html'],true))$copy($item['source'],$dest);
  foreach($targets as $dest=>$item)if(in_array(basename($dest),['index.html','system.html'],true))$copy($item['source'],$dest);
 }elseif($mode==='rollback'){
  // 只回退本次逐行授权迁移；检测任何并发编辑，禁止拿整库备份覆盖线上业务。
  foreach($targets as $dest=>$item)if(!in_array($hash($dest),[$item['before'],$item['after']],true))throw new RuntimeException('CONCURRENT_CODE_CHANGE');
  $exists=$db->query("SHOW TABLES LIKE 'eb_store_v3_permission_audit'")->fetch();if($exists){if((int)$db->query('SELECT COUNT(*) FROM eb_store_v3_permission_audit WHERE operator_id<>0')->fetchColumn()>0)throw new RuntimeException('CONCURRENT_CATALOG_EDIT_REQUIRES_REVIEW');$rows=$db->query("SELECT before_json,after_json FROM eb_store_v3_permission_audit WHERE action='MIGRATE_POSITION' ORDER BY id")->fetchAll();$db->beginTransaction();try{foreach($rows as $r){$old=json_decode($r['before_json'],true);$after=json_decode($r['after_json'],true);$q=$db->prepare('SELECT * FROM eb_job_position_channel_rule WHERE id=? FOR UPDATE');$q->execute([$old['id']]);$now=$q->fetch();if($now['rules']!==$after['rules']||(int)$now['version']!==(int)$old['version']+1)throw new RuntimeException('CONCURRENT_ROLE_CHANGE_REQUIRES_REVIEW');$q=$db->prepare('UPDATE eb_job_position_channel_rule SET rules=?,version=?,update_time=? WHERE id=?');$q->execute([$old['rules'],$old['version'],$old['update_time'],$old['id']]);}$db->commit();}catch(Throwable $e){$db->rollBack();throw $e;}}
  // 清除本轮新建单例，避免重试时把已恢复的旧岗位误认为已经迁移；不清除任何业务表。
  if($exists){if((int)$db->query('SELECT COUNT(*) FROM eb_store_v3_permission_audit WHERE operator_id<>0')->fetchColumn()>0)throw new RuntimeException('CONCURRENT_CATALOG_EDIT_REQUIRES_REVIEW');$db->exec('DELETE FROM eb_store_v3_permission_catalog WHERE id=1');$db->exec('DELETE FROM eb_store_v3_permission_audit');}
  $q=$db->prepare('DELETE FROM eb_database_upgrade_log WHERE upgrade_key=? AND code_version IN (?,?)');$q->execute(['20261023-r28-store-permission-catalog',$manifest['commit'],'']);
  $oldMetadata=json_decode(gzdecode(file_get_contents($directory.'/before/metadata.json.gz')),true);$hadMenu=false;foreach($oldMetadata['eb_system_menus']['rows'] as $menu)if($menu['unique_auth']==='setting-store-permissions')$hadMenu=true;if(!$hadMenu)$db->exec("DELETE FROM eb_system_menus WHERE unique_auth='setting-store-permissions'");
  foreach($targets as $dest=>$item)if($item['before']!==null)$copy($item['backup'],$dest);elseif($hash($dest)===$item['after'])unlink($dest);
 }
 foreach($targets as $dest=>$item)$check($dest,$mode==='rollback'?$item['before']:$item['after']);
 if($mode!=='rollback'){
  $r=$db->query('SELECT rules_json FROM eb_store_v3_permission_catalog WHERE id=1')->fetch();$actual=json_decode($r['rules_json'],true);$expected=json_decode(file_get_contents($directory.'/payload/menu-config.json'),true)['rules'];if($actual!==$expected||count($actual)!==91)throw new RuntimeException('MENU_CONFIGURATION_MISMATCH');
  $old=json_decode(gzdecode(file_get_contents($directory.'/before/metadata.json.gz')),true)['eb_job_position_channel_rule']['rows'];$now=$db->query('SELECT * FROM eb_job_position_channel_rule ORDER BY id')->fetchAll();$originalOther=array_values(array_filter($old,static function($r){return $r['channel']!=='store_v3';}));$newOther=array_values(array_filter($now,static function($r){return $r['channel']!=='store_v3';}));if($originalOther!==$newOther)throw new RuntimeException('OTHER_CHANNEL_GRANTS_CHANGED');
  if(count($old)!==count($now))throw new RuntimeException('ROLE_ROW_COUNT_CHANGED');$byId=[];foreach($now as $row)$byId[$row['id']]=$row;foreach($old as $row){$current=$byId[$row['id']];foreach($row as $key=>$value)if(!in_array($key,['rules','version','update_time'],true)&&$current[$key]!==$value)throw new RuntimeException('ROLE_IDENTITY_CHANGED');}
 }
}
echo json_encode(['result'=>'PASS','site'=>$site,'mode'=>$mode,'commit'=>$manifest['commit'],'files'=>count($targets)],JSON_THROW_ON_ERROR),PHP_EOL;
