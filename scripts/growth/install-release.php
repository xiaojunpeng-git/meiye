<?php
declare(strict_types=1);
/** 逐实例发布：先独立备份，核对原运行指纹后迁移并安装固定包。默认关闭新模块，不授予旧岗位。 */
$site=$argv[1]??'';$dir=$argv[2]??'';$mode=$argv[3]??'';
$sites=['rh'=>['ruihao','3306'],'008'=>['mohe_008','3307']];
if(!isset($sites[$site])||!preg_match('~^/www/backups/(rh|008)/20261023-growth-[a-f0-9]{8}$~D',$dir)||strpos($dir,'/www/backups/'.$site.'/')!==0||!in_array($mode,['backup','install','verify','rollback'],true))throw new RuntimeException('Invalid release boundary');
$root='/www/wwwroot/'.$site.'.cc3798.com';$manifest=json_decode(file_get_contents($dir.'/payload/manifest.json'),true,512,JSON_THROW_ON_ERROR);
$assert=static function($ok,$message){if(!$ok)throw new RuntimeException($message);};
$hash=static function($p){return is_file($p)?hash_file('sha256',$p):null;};
$save=static function($p,$v)use($dir){file_put_contents($dir.'/'.$p,json_encode($v,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));};
chdir($root);require $root.'/vendor/autoload.php';(new think\App($root.'/'))->initialize();$cfg=config('database.connections.mysql');
$assert($cfg['database']===$sites[$site][0]&&(string)$cfg['hostport']===$sites[$site][1]&&$cfg['prefix']==='eb_'&&in_array($cfg['hostname'],['localhost','127.0.0.1'],true),'Wrong database');
$pdo=new PDO('mysql:host='.$cfg['hostname'].';port='.$cfg['hostport'].';dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
foreach($manifest['files'] as $p=>$sha){$assert(strpos($p,'..')===false&&preg_match('~^(app/|route/|config/|database/upgrades/|public/)~',$p),'Unsafe path');$assert($hash($dir.'/payload/'.$p)===$sha,'Payload drift: '.$p);}
$protected=['.env','public/index.html','public/view_cashier_v3/index.html'];if($site==='rh')$protected[]='../rh.cc3798.com-8080/public/view_cashier_v3/index.html';
if($mode==='backup'){
 $assert(!is_file($dir.'/before.json'),'Backup exists');$before=['site'=>$site,'commit'=>$manifest['commit'],'files'=>[],'protected'=>[],'dependencies'=>[]];
 foreach($manifest['before'][$site]['files'] as $p=>$expected)$assert($hash($root.'/'.$p)===$expected,'Live dependency changed: '.$p);
 $assert($hash($root.'/public/system.html')===$manifest['before'][$site]['front']['public/system.html']['sha256'],'Live admin changed');
 foreach($manifest['files'] as $p=>$sha){$before['files'][$p]=$hash($root.'/'.$p);if($before['files'][$p]!==null){$dest=$dir.'/backup/'.$p;if(!is_dir(dirname($dest)))mkdir(dirname($dest),0700,true);$assert(copy($root.'/'.$p,$dest),'Backup copy failed');$assert($hash($dest)===$before['files'][$p],'Backup verify failed');}}
 foreach($protected as $p)$before['protected'][$p]=$hash($root.'/'.$p);
 foreach($manifest['before'][$site]['files'] as $p=>$sha)if(!isset($manifest['files'][$p]))$before['dependencies'][$p]=$sha;
 $tables=$pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'eb_growth_%'")->fetchAll(PDO::FETCH_COLUMN);$assert(!$tables,'Existing growth database requires separate reviewed migration');
 // 原菜单与升级日志完整备份只留在本实例；新增业务表尚无数据，不拷贝任何本地测试人员。
 $sql="SET NAMES utf8mb4;\n";$pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
 foreach(['eb_system_menus','eb_database_upgrade_log'] as $table){$sql.=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1].";\n";foreach($pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC) as $row)$sql.='INSERT INTO `'.$table.'` (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',array_map(static function($v)use($pdo){return $v===null?'NULL':$pdo->quote((string)$v);},array_values($row))).");\n";}$pdo->rollBack();
 $assert(file_put_contents($dir.'/menus-upgrades.sql',$sql)===strlen($sql),'SQL backup failed');chmod($dir.'/menus-upgrades.sql',0600);$before['sql_sha256']=$hash($dir.'/menus-upgrades.sql');$save('before.json',$before);echo json_encode(['backup'=>'PASS','site'=>$site,'files'=>count($before['files'])]);exit;
}
$before=json_decode(file_get_contents($dir.'/before.json'),true,512,JSON_THROW_ON_ERROR);
foreach($before['protected'] as $p=>$sha)$assert($hash($root.'/'.$p)===$sha,'Protected file changed '.$p);
foreach($before['dependencies'] as $p=>$sha)$assert($hash($root.'/'.$p)===$sha,'Dependency changed '.$p);
if($mode==='install'){
 foreach($before['files'] as $p=>$sha)$assert($hash($root.'/'.$p)===$sha,'Baseline changed '.$p);
 foreach($manifest['migrations'] as $p){$key=pathinfo($p,PATHINFO_FILENAME);$query=$pdo->prepare('SELECT sql_checksum FROM eb_database_upgrade_log WHERE upgrade_key=?');$query->execute([$key]);$old=$query->fetchColumn();if($old!==false){$assert($old===$manifest['files'][$p],'Migration checksum mismatch');continue;}$pdo->exec(file_get_contents($dir.'/payload/'.$p));$pdo->prepare('INSERT INTO eb_database_upgrade_log (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note) VALUES (?,?,?,?,?,NOW(),?,?)')->execute([$key,'成长管理安装',$p,$manifest['files'][$p],$manifest['commit'],'Codex-MYDX','独立备份后安装；默认关闭；无测试数据']);}
 // 先装后端和静态依赖，最后原子替换平台入口；历史静态资源保留供旧会话与回滚读取。
 $paths=array_keys($manifest['files']);usort($paths,static function($a,$b){return ($a==='public/system.html'?1:0)<=>($b==='public/system.html'?1:0);});
 foreach($paths as $p){if(strpos($p,'database/')===0)continue;$dest=$root.'/'.$p;if(!is_dir(dirname($dest)))mkdir(dirname($dest),0755,true);$tmp=$dest.'.growth-install';$assert(copy($dir.'/payload/'.$p,$tmp),'Install copy failed');chmod($tmp,0644);$assert(rename($tmp,$dest),'Atomic switch failed');}
 $save('installed.json',['commit'=>$manifest['commit'],'time'=>date(DATE_ATOM)]);
 echo json_encode(['installed'=>true,'site'=>$site,'commit'=>$manifest['commit'],'migrations'=>count($manifest['migrations'])]);exit;
}
if($mode==='rollback'){
 // 回滚只恢复本轮发布文件；成长事实与升级记录保留，不回灌旧整表覆盖其他任务。
 foreach($before['files'] as $p=>$sha){if(strpos($p,'database/')===0)continue;$current=$hash($root.'/'.$p);$assert($current===$manifest['files'][$p]||$current===$sha,'Do not overwrite newer deployment '.$p);if($sha!==null){$assert($hash($dir.'/backup/'.$p)===$sha,'Bad backup');copy($dir.'/backup/'.$p,$root.'/'.$p);}elseif(is_file($root.'/'.$p)){unlink($root.'/'.$p);}}
 $pdo->exec('UPDATE eb_growth_setting SET enabled=0 WHERE id=1');echo json_encode(['rolled_back'=>true,'retained'=>'成长历史和升级记录']);exit;
}
foreach($manifest['files'] as $p=>$sha)if(strpos($p,'database/')!==0)$assert($hash($root.'/'.$p)===$sha,'Installed mismatch '.$p);
$schema=app()->make(\app\services\growth\GrowthSchemaServices::class)->status();$assert($schema['ready'],'Schema incomplete');
$out=['site'=>$site,'commit'=>$manifest['commit'],'schema'=>$schema,'enabled'=>(int)$pdo->query('SELECT enabled FROM eb_growth_setting WHERE id=1')->fetchColumn(),'menus'=>(int)$pdo->query("SELECT COUNT(*) FROM eb_system_menus WHERE unique_auth LIKE 'admin-growth%'")->fetchColumn(),'pending_events'=>(int)$pdo->query("SELECT COUNT(*) FROM eb_growth_event WHERE delivery_state='PENDING'")->fetchColumn(),'failed_events'=>(int)$pdo->query("SELECT COUNT(*) FROM eb_growth_event WHERE delivery_state='FAILED'")->fetchColumn(),'protected_unchanged'=>true,'migration_count'=>(int)$pdo->query("SELECT COUNT(*) FROM eb_database_upgrade_log WHERE upgrade_key LIKE '20261023_growth_%'")->fetchColumn()];$save('verified.json',$out);echo json_encode($out,JSON_UNESCAPED_UNICODE);
