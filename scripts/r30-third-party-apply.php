<?php
/** 008专用发布执行器：固定提交SQL，备份后单事务补缺；既有配置必须逐字节保持不变。 */
$commit=$argv[1]??'';$backup=$argv[2]??'';
if(!preg_match('/^[a-f0-9]{40}$/',$commit)||!preg_match('~^/www/backups/008/r30-third-party-[A-Za-z0-9-]+$~',$backup))throw new RuntimeException('Explicit release and private backup required');
$root='/www/wwwroot/008.cc3798.com';$env=parse_ini_file($root.'/.env',true,INI_SCANNER_RAW);$c=array_change_key_case($env['DATABASE']??$env['database']??[]);
if($c['database']!=='mohe_008'||(string)$c['hostport']!=='3307')throw new RuntimeException('INSTANCE_MISMATCH');
$sql=file_get_contents(__DIR__.'/migration.sql');
if(hash('sha256',$sql)!=='7de40127596fdd7642e7fc01510ca8fe2f1e32b56332c1f077797e67b3b3519a')throw new RuntimeException('ACCEPTED_SQL_CHANGED');
$db=new PDO('mysql:host='.$c['hostname'].';port=3307;dbname=mohe_008;charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$keys=['tengxun_map_key','system_express_app_code','logistics_type','system_product_copy_type'];$lock='r30-third-party-mohe008';
if((int)$db->query("SELECT GET_LOCK('$lock',10)")->fetchColumn()!==1)throw new RuntimeException('MIGRATION_BUSY');
try{
 if(is_dir($backup))throw new RuntimeException('BACKUP_EXISTS');
 if(!mkdir($backup,0700,true))throw new RuntimeException('BACKUP_CREATE_FAILED');
 $db->beginTransaction();
 try{
  $before=$db->query('SELECT * FROM eb_system_config ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);$existing=[];$old=[];
  foreach($before as $row){$old[$row['id']]=$row;if(in_array($row['menu_name'],$keys,true)&&(int)$row['is_store']===0)$existing[]=$row;}
  // 备份只留本机受保护目录，不通过工具输出任何客户配置值。
  if(file_put_contents($backup.'/before.json',json_encode($existing,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT))===false)throw new RuntimeException('BACKUP_WRITE_FAILED');
  chmod($backup.'/before.json',0600);
  $statements=array_filter(array_map('trim',explode(';',preg_replace('/^--.*$/m','',$sql))));
  foreach($statements as $statement)$db->exec($statement);
  $after=$db->query('SELECT * FROM eb_system_config ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);$added=[];$seen=[];
  foreach($after as $row){
   if(isset($old[$row['id']])){if($old[$row['id']]!==$row)throw new RuntimeException('EXISTING_CONFIG_CHANGED');unset($old[$row['id']]);}
   else{if(!in_array($row['menu_name'],$keys,true)||(int)$row['is_store']!==0)throw new RuntimeException('UNEXPECTED_INSERT');$added[]=$row;}
   if(in_array($row['menu_name'],$keys,true)&&(int)$row['is_store']===0)$seen[$row['menu_name']]=($seen[$row['menu_name']]??0)+1;
  }
  if($old||count($seen)!==4||max($seen)!==1)throw new RuntimeException('CONFIG_RECONCILIATION_FAILED');
  $key='20261024-r30-third-party-config';$q=$db->prepare('SELECT id FROM eb_database_upgrade_log WHERE upgrade_key=?');$q->execute([$key]);
  if(!$q->fetchColumn()){
   $now=date('Y-m-d H:i:s');$q=$db->prepare('INSERT INTO eb_database_upgrade_log (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note,created_at) VALUES (?,?,?,?,?,?,?,?,?)');
   $q->execute([$key,'R30 第三方配置缺失定义补齐','后端代码/database/upgrades/20261024_r30_third_party_config_definitions.sql',hash('sha256',$sql),$commit,$now,'Codex',json_encode(['added'=>array_column($added,'menu_name'),'existing_config_unchanged'=>true]),$now]);
  }
  $receipt=['site'=>'008','database'=>'mohe_008','commit'=>$commit,'backup'=>$backup,'added'=>array_map(static function($row){return ['id'=>$row['id'],'menu_name'=>$row['menu_name'],'sha256'=>hash('sha256',json_encode($row))];},$added),'existingConfigUnchanged'=>true,'sqlSha256'=>hash('sha256',$sql)];
  // 回滚只能删除仍等于本次初始值的新增行；用户已填写的配置禁止删除。
  file_put_contents($backup.'/receipt.json',json_encode($receipt,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));chmod($backup.'/receipt.json',0600);
  $db->commit();echo json_encode($receipt,JSON_UNESCAPED_UNICODE),PHP_EOL;
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}finally{$db->query("SELECT RELEASE_LOCK('$lock')");}
