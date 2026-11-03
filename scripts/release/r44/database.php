<?php
declare(strict_types=1);
/** Instance-bound additive R44 migration. Backups remain on their own server.
 * No historical order is updated and rollback must retain any newly created facts.
 * The fixed manifest, file backup and database identity are required before DDL.
 */
$directory=$argv[1]??'';$mode=$argv[2]??'';$expected=$argv[3]??'';
if(!preg_match('~^/www/backups/(rh|008|010)/r44-combination-([a-f0-9]{8})(?:-retry[1-9][0-9]*)?$~D',$directory,$match)
 ||!in_array($mode,['backup','apply','verify'],true)||!preg_match('/^[a-f0-9]{64}$/D',$expected)
 ||hash_file('sha256',$directory.'/payload/manifest.json')!==$expected)throw new RuntimeException('Fixed manifest required');
$m=json_decode(file_get_contents($directory.'/payload/manifest.json'),true,512,JSON_THROW_ON_ERROR);
$site=$match[1];$map=['rh'=>['ruihao',3306],'008'=>['mohe_008',3307],'010'=>['mohe_010_app',3307]];
$root='/www/wwwroot/'.$site.'.cc3798.com';[$database,$port]=$map[$site];
if($m['site']!==$site||substr($m['commit'],0,8)!==$match[2]||!is_file($directory.'/prepared.json'))throw new RuntimeException('File backup missing');
$cfg=array_change_key_case(parse_ini_file($root.'/.env',true,INI_SCANNER_RAW)['DATABASE']);
if($cfg['database']!==$database||(int)($cfg['hostport']??3306)!==$port||hash_file('sha256',$root.'/.env')!==$m['environmentSHA256'])throw new RuntimeException('Wrong database/environment');
$pdo=new PDO('mysql:host='.$cfg['hostname'].';port='.$port.';dbname='.$database.';charset=utf8mb4',$cfg['username'],$cfg['password'],
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>true,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true]);
$files=['20261102_r44_combination_promotion.sql','2026-11-02-r44-leader-rewards/upgrade.sql','2026-11-02-r44-third-party-accounting/02-正式升级.sql'];
foreach($files as$file)if(hash_file('sha256',$directory.'/payload/migrations/'.$file)!==($m['migrations'][$file]??null))throw new RuntimeException('Migration drift');
$new=['eb_combination_promotion_order','eb_combination_dashboard_setting','eb_combination_leader_reward_config','eb_combination_leader_reward','eb_combination_leader_reward_item'];
$existing=['eb_store_order','eb_member_commerce_order','eb_store_pink','eb_system_menus','eb_cashier_v3_payment_method_config','eb_database_upgrade_log'];
$exists=static function(string $table)use($pdo):bool{$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);return(bool)$q->fetchColumn();};
$save=static function(string $file,array $data):void{$h=fopen($file,'x');if(!$h)throw new RuntimeException('Receipt already exists');chmod($file,0600);fwrite($h,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));fclose($h);};
$lock=fopen($directory.'/database.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Concurrent migration');
$backup=$directory.'/database-before.json';$key='20261103-r44-combination-promotion';
if($mode==='backup'){
 foreach($new as$t)if($exists($t))throw new RuntimeException('R44 schema already present: '.$t);
 foreach($existing as$t)if(!$exists($t))throw new RuntimeException('Required dependency missing: '.$t);
 if((int)$pdo->query("SELECT COUNT(*) FROM eb_system_menus WHERE unique_auth='marketing-store_combination-index' AND is_del=0")->fetchColumn()!==1)throw new RuntimeException('Ambiguous parent menu');
 $before=['site'=>$site,'commit'=>$m['commit'],'schema'=>[],'rows'=>[],'historicalCutoff'=>[]];
 foreach($existing as$t)$before['schema'][$t]=$pdo->query('SHOW CREATE TABLE `'.$t.'`')->fetch(PDO::FETCH_NUM)[1];
 // Only configuration rows are backed up; customer order contents never leave the DB.
 foreach(['eb_system_menus','eb_cashier_v3_payment_method_config','eb_database_upgrade_log']as$t)$before['rows'][$t]=$pdo->query('SELECT * FROM `'.$t.'`')->fetchAll(PDO::FETCH_ASSOC);
 foreach(['eb_store_order','eb_member_commerce_order']as$t){
  if(strpos($before['schema'][$t],'`accounting_method`')!==false)throw new RuntimeException('Unexpected accounting marker baseline');
  $before['historicalCutoff'][$t]=(int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM `'.$t.'`')->fetchColumn();
 }
 $save($backup,$before);echo "R44_DATABASE_BACKUP_PASS\n";exit;
}
$before=json_decode(file_get_contents($backup),true,512,JSON_THROW_ON_ERROR);
if($before['site']!==$site||$before['commit']!==$m['commit'])throw new RuntimeException('Backup identity mismatch');
$log=$pdo->prepare('SELECT sql_checksum,code_version FROM eb_database_upgrade_log WHERE upgrade_key=?');$log->execute([$key]);$registered=$log->fetch(PDO::FETCH_ASSOC);
$checksum=hash('sha256',implode('',array_map(static function($file)use($directory){return file_get_contents($directory.'/payload/migrations/'.$file);},$files)));
if($mode==='apply'){
 if($registered)throw new RuntimeException('Migration already registered; verify instead');
 // Ignore only the monotonic row counter; concurrent schema changes require a
 // fresh review instead of applying DDL against an unverified live baseline.
 foreach($before['schema']as$t=>$ddl){$now=$pdo->query('SHOW CREATE TABLE `'.$t.'`')->fetch(PDO::FETCH_NUM)[1];
  if(preg_replace('/AUTO_INCREMENT=\d+/','AUTO_INCREMENT=COUNTER',$ddl)!==preg_replace('/AUTO_INCREMENT=\d+/','AUTO_INCREMENT=COUNTER',$now))throw new RuntimeException('Concurrent schema change: '.$t);
 }
 foreach($new as$t)if($exists($t))throw new RuntimeException('Concurrent R44 migration: '.$t);
 // MySQL DDL cannot be rolled back. Execute only the reviewed additive scripts;
 // a failure leaves the backup and explicit partial state for controlled recovery.
 foreach($files as$file){$statement=$pdo->query(file_get_contents($directory.'/payload/migrations/'.$file));do{if($statement->columnCount())$statement->fetchAll(PDO::FETCH_ASSOC);}while($statement->nextRowset());$statement->closeCursor();}
}
foreach($new as$t)if(!$exists($t))throw new RuntimeException('Missing migrated table: '.$t);
foreach($before['historicalCutoff']as$t=>$id)if((int)$pdo->query('SELECT COUNT(*) FROM `'.$t.'` WHERE id<='.(int)$id." AND accounting_method<>''")->fetchColumn()!==0)throw new RuntimeException('Historical accounting facts changed');
if((int)$pdo->query("SELECT COUNT(*) FROM eb_system_menus WHERE unique_auth='marketing-store_combination-dashboard' AND is_del=0")->fetchColumn()!==1)throw new RuntimeException('Dashboard menu missing');
$method=$pdo->query("SELECT default_name,display_name,status FROM eb_cashier_v3_payment_method_config WHERE code='third_party'")->fetch(PDO::FETCH_ASSOC);
if(!$method||$method['display_name']!=='第三方支付'||(int)$method['status']!==1)throw new RuntimeException('Third-party accounting dictionary mismatch');
if($mode==='apply'){
 $q=$pdo->prepare('INSERT INTO eb_database_upgrade_log (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note) VALUES (?,?,?,?,?,NOW(),?,?)');
 $q->execute([$key,'第44轮拼团推广、团长奖励及新手机订单记账',implode(';',$files),$checksum,$m['commit'],'Codex','Additive schema; historical orders unchanged; backup '.$backup]);
 $save($directory.'/database-applied.json',['site'=>$site,'commit'=>$m['commit'],'checksum'=>$checksum,'historicalOrdersUnchanged'=>true]);
}elseif(!$registered||$registered['sql_checksum']!==$checksum||$registered['code_version']!==$m['commit'])throw new RuntimeException('Migration receipt mismatch');
echo 'R44_DATABASE_'.strtoupper($mode)."_PASS\n";
