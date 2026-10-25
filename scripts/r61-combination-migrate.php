<?php
/** Add five workflow tables only. Back up each table's absence/schema/data and upgrade-log identity per instance. */
if(PHP_SAPI!=='cli')exit(1);
[$unused,$directory,$mode,$expected]=array_pad($argv,4,'');
if(!preg_match('~^/www/backups/(rh|008|010)/r61-combination-([a-f0-9]{8})(?:-retry[1-9][0-9]*)?$~D',$directory,$m)
 ||!in_array($mode,['backup','migrate','verify'],true)||!preg_match('/^[a-f0-9]{64}$/D',$expected)
 ||hash_file('sha256',$directory.'/payload/manifest.json')!==$expected)throw new RuntimeException('Fixed manifest required');
$site=$m[1];$manifest=json_decode(file_get_contents($directory.'/payload/manifest.json'),true);
if($manifest['site']!==$site||substr($manifest['commit'],0,8)!==$m[2])throw new RuntimeException('Wrong site');
$root='/www/wwwroot/'.($site==='rh'?'rh.cc3798.com':$site.'.cc3798.com');
$ini=parse_ini_file($root.'/.env',true,INI_SCANNER_RAW);$cfg=array_change_key_case($ini['DATABASE']??$ini['database']??[]);
$database=['rh'=>'ruihao','008'=>'mohe_008','010'=>'mohe_010_app'][$site];$port=$site==='rh'?'3306':'3307';
if($cfg['database']!==$database||(string)$cfg['hostport']!==$port||$cfg['prefix']!=='eb_')throw new RuntimeException('Wrong database');
$pdo=new PDO('mysql:host='.$cfg['hostname'].';port='.$port.';dbname='.$database.';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$tables=['eb_combination_group_origin'=>'group_id','eb_combination_force_completion'=>'group_id','eb_combination_payment_origin'=>'channel_order_no','eb_combination_order_fulfillment'=>'order_id','eb_combination_order_refund'=>'order_id'];
$key='20261025-r61-combination-fulfillment-refund';
$state=static function()use($pdo,$tables){$out=[];foreach($tables as $table=>$pk){$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);$out[$table]=(int)$q->fetchColumn()?['schema'=>$pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1],'rows'=>$pdo->query('SELECT * FROM '.$table.' ORDER BY '.$pk)->fetchAll(PDO::FETCH_ASSOC)]:['schema'=>null,'rows'=>[]];}return $out;};
$file=$directory.'/combination-before.json';
if($mode==='backup'){
 if(is_file($file)||!is_file($directory.'/prepared.json'))throw new RuntimeException('Unique file backup required');
 $pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();try{$data=['site'=>$site,'database'=>$database,'commit'=>$manifest['commit'],'state'=>$state()];$q=$pdo->prepare('SELECT * FROM eb_database_upgrade_log WHERE upgrade_key=?');$q->execute([$key]);$data['upgradeBefore']=$q->fetchAll(PDO::FETCH_ASSOC);}finally{$pdo->rollBack();}
 $json=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(file_put_contents($file,$json)===false)throw new RuntimeException('Backup failed');chmod($file,0600);file_put_contents($file.'.sha256',hash('sha256',$json));
 if(hash_file('sha256',$file)!==hash('sha256',$json))throw new RuntimeException('Backup readback failed');echo 'R61_TABLE_BACKUP_PASS',PHP_EOL;exit;
}
if(!is_file($file)||hash_file('sha256',$file)!==trim(file_get_contents($file.'.sha256')))throw new RuntimeException('Backup corrupt');
$backup=json_decode(file_get_contents($file),true);if($backup['site']!==$site||$backup['database']!==$database||$backup['commit']!==$manifest['commit'])throw new RuntimeException('Backup identity mismatch');
$sql=$directory.'/payload/migration.sql';if(hash_file('sha256',$sql)!==$manifest['migrationSHA256'])throw new RuntimeException('SQL drift');
if((int)$pdo->query("SELECT GET_LOCK('r61-combination-schema',10)")->fetchColumn()!==1)throw new RuntimeException('Concurrent migration');
try{
 $q=$pdo->prepare('SELECT sql_checksum FROM eb_database_upgrade_log WHERE upgrade_key=?');$q->execute([$key]);$old=$q->fetchColumn();
 if($old!==false&&$old!==$manifest['migrationSHA256'])throw new RuntimeException('Upgrade checksum conflict');
 if($mode==='migrate'){
  if($state()!==$backup['state'])throw new RuntimeException('Table changed since backup');
  $pdo->exec(file_get_contents($sql));
 }
 $current=$state();foreach($tables as $table=>$pk){
  if(!$current[$table]['schema']||($mode==='migrate'&&$current[$table]['rows']!==$backup['state'][$table]['rows']))throw new RuntimeException('Unexpected schema/history change');
  $primary=$pdo->query("SHOW INDEX FROM $table WHERE Key_name='PRIMARY'")->fetchAll(PDO::FETCH_ASSOC);if(count($primary)!==1||$primary[0]['Column_name']!==$pk)throw new RuntimeException('Primary identity mismatch');
 }
 $indexes=$pdo->query('SHOW INDEX FROM eb_combination_order_refund')->fetchAll(PDO::FETCH_ASSOC);if(count(array_unique(array_column($indexes,'Key_name')))!==3)throw new RuntimeException('Refund indexes missing');
 if($mode==='migrate'){
  if($old===false){$q=$pdo->prepare('INSERT INTO eb_database_upgrade_log (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note,created_at) VALUES (?,?,?,?,?,NOW(),?,?,NOW())');$q->execute([$key,'拼团权益发放及原路退款','database/upgrades/2026-10-25-拼团权益退款/01-schema.sql',$manifest['migrationSHA256'],$manifest['commit'],'Codex R61','Five additive workflow tables; no historical backfill or balance updates']);}
  file_put_contents($directory.'/migration.json',json_encode(['site'=>$site,'commit'=>$manifest['commit'],'sqlSHA256'=>$manifest['migrationSHA256'],'upgradeKey'=>$key,'backupSHA256'=>hash_file('sha256',$file)],JSON_PRETTY_PRINT));
 }
}finally{$pdo->query("SELECT RELEASE_LOCK('r61-combination-schema')");}
echo 'R61_TABLE_'.strtoupper($mode).'_PASS',PHP_EOL;
