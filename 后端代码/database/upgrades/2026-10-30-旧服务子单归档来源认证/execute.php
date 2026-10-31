<?php
declare(strict_types=1);
/**
 * Explicitly authorized independent type-2 service archive certification only. check is read-only; apply
 * inserts separate source proofs; rollback removes only this plan's proofs.
 * Original order rows are locked for full-row comparison, never updated or deleted.
 * A proof certifies technical archival only; report paid/refund/status filters remain unchanged.
 */
$options=getopt('',['plan:','expected-sha:','mode:','audit:']);
$mode=$options['mode']??'check';$progress=fopen('php://stderr','w');
if(!in_array($mode,['check','apply','rollback'],true))throw new RuntimeException('MODE');
$bytes=file_get_contents($options['plan']??'');$sha=hash('sha256',$bytes);
if(!hash_equals($options['expected-sha']??'',$sha))throw new RuntimeException('PLAN_SHA');
$plan=json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
$root=dirname(__DIR__,3);require $root.'/vendor/autoload.php';(new think\App($root.'/'))->initialize();
use app\services\report\ServiceOrderArchiveProvenanceServices as Proof;
$purpose=Proof::PURPOSE;$fingerprint=[Proof::class,'fingerprint'];
use think\facade\Db;
$db=config('database.connections.mysql');$target=$plan['target']??[];
foreach(['database','hostport','prefix']as$key)if((string)($db[$key]??'')!==(string)($target[$key]??''))throw new RuntimeException('TARGET_'.$key);
if(!in_array($db['hostname']??'',['localhost','127.0.0.1'],true)||$target['prefix']!=='eb_')throw new RuntimeException('TARGET_HOST');
if(($plan['purpose']??'')!==$purpose||empty($plan['proofs'])||count($plan['proofs'])>100000)throw new RuntimeException('PLAN_SCOPE');
$marker=$plan['source_marker']??[];
if(($marker['table']??'')!=='codex_010_migration_batch')throw new RuntimeException('SOURCE_MARKER_TABLE');
$proofTable='cashier_v3_import_fact_provenance';$existing=Db::query("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_import_fact_provenance'");
$hasTable=(int)($existing[0]['n']??0)===1;
$validateSchema=static function():void{
 $table=Db::query("SELECT ENGINE,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_import_fact_provenance'");
 if(count($table)!==1||strtoupper($table[0]['ENGINE'])!=='INNODB'||$table[0]['TABLE_TYPE']!=='BASE TABLE')throw new RuntimeException('PROOF_TABLE_ENGINE');
 $columns=Db::query("SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_import_fact_provenance'");
 $columns=array_column($columns,null,'COLUMN_NAME');
 $expected=['tenant_id'=>'varchar(64)','fact_id'=>'varchar(128)','purpose'=>'varchar(40)','snapshot_sha256'=>'char(64)','source_batch_key'=>'varchar(128)','source_archive_sha256'=>'char(64)','plan_sha256'=>'char(64)','created_at'=>'timestamp'];
 if(count($columns)!==count($expected))throw new RuntimeException('PROOF_TABLE_COLUMNS');
 foreach($expected as$name=>$type)if(!isset($columns[$name])||strtolower($columns[$name]['COLUMN_TYPE'])!==$type||$columns[$name]['IS_NULLABLE']!=='NO'||($name!=='created_at'&&$columns[$name]['COLLATION_NAME']!=='ascii_bin'))throw new RuntimeException('PROOF_TABLE_COLUMN:'.$name);
 $indexes=Db::query("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_import_fact_provenance' AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX");
 if(array_column($indexes,'COLUMN_NAME')!==['tenant_id','fact_id','purpose'])throw new RuntimeException('PROOF_TABLE_PRIMARY');
};
if($hasTable)$validateSchema();
// Apply's first pass is read-only and validates every fact before DDL. MySQL
// DDL commits implicitly, so schema creation is outside the proof transaction.
$check=static function(bool $lock)use($plan,$marker,$proofTable,$sha,&$hasTable,$purpose,$fingerprint):array{
 $mark=Db::table($marker['table'])->where('batch_key',$marker['batch_key'])->find();
 if(!$mark||!hash_equals((string)$mark['source_archive_sha256'],(string)$marker['source_archive_sha256']))throw new RuntimeException('SOURCE_SEAL_MISMATCH');
 $seen=[];$insert=[];$same=0;
 foreach(array_chunk($plan['proofs'],200)as$chunk){
  $byTenant=[];foreach($chunk as$p){
   $key=$p['tenant_id'].'|'.$p['fact_id'];if(isset($seen[$key]))throw new RuntimeException('DUPLICATE_PROOF');$seen[$key]=true;
   if(!preg_match('/^[0-9a-f]{64}$/D',$p['snapshot_sha256']??''))throw new RuntimeException('SNAPSHOT_SHA');
   $byTenant[(string)$p['tenant_id']][]=$p;
  }
  foreach($byTenant as$tenant=>$batch){
   if((string)$tenant!=='0')throw new RuntimeException('SOURCE_TENANT');
   $ids=array_column($batch,'fact_id');$sourceIds=[];
   foreach($batch as$p){if(!preg_match('/^legacy-order:([1-9][0-9]*)$/D',$p['fact_id'],$match)||(int)($p['source_id']??0)!==(int)$match[1])throw new RuntimeException('SOURCE_ID');$sourceIds[]=(int)$p['source_id'];}
   $q=Db::name('store_order')->whereIn('id',$sourceIds);
   if($lock)$q->lock(true);$rows=$q->field('*')->select()->toArray();$byId=[];foreach($rows as$row)$byId['legacy-order:'.$row['id']]=$row;
   if(count($byId)!==count($batch))throw new RuntimeException('FACT_POPULATION_CHANGED');
   $old=$hasTable?Db::name($proofTable)->where('tenant_id',(string)$tenant)->whereIn('fact_id',$ids)->where('purpose',$purpose)->select()->toArray():[];$old=array_column($old,null,'fact_id');
   foreach($batch as$p){$fact=$byId[$p['fact_id']];
    if(!hash_equals($p['snapshot_sha256'],$fingerprint($fact))||(int)$fact['order_type']!==2||(int)$fact['is_system_del']!==1||(string)$fact['order_id']!==(string)$p['source_order_no']||(int)$fact['link_id']!==(int)$p['source_link_id']||(int)$fact['link_order']!==(int)$p['source_origin_order_id']||(int)$fact['store_id']!==(int)$p['source_store_id']||(int)$fact['uid']!==(int)$p['source_member_id'])throw new RuntimeException('FACT_SNAPSHOT_CHANGED:'.$p['fact_id']);
    $new=['tenant_id'=>(string)$tenant,'fact_id'=>$p['fact_id'],'purpose'=>$purpose,'snapshot_sha256'=>$p['snapshot_sha256'],'source_batch_key'=>$marker['batch_key'],'source_archive_sha256'=>$marker['source_archive_sha256'],'plan_sha256'=>$sha];
    if(isset($old[$p['fact_id']])){foreach(['snapshot_sha256','source_batch_key','source_archive_sha256']as$k)if($old[$p['fact_id']][$k]!==$new[$k])throw new RuntimeException('PROOF_CONFLICT');++$same;}
    else$insert[]=$new;
   }
  }
 }
 return ['validated'=>count($seen),'already_certified'=>$same,'insert'=>$insert];
};
$conn=Db::connect();$conn->execute('SET SESSION max_execution_time=8000');
$conn->execute('SET SESSION TRANSACTION READ ONLY');$conn->startTrans();
try{$preview=$mode==='rollback'?null:$check(false);}finally{$conn->rollback();}
if($mode==='check'){echo json_encode(['mode'=>'check','plan_sha256'=>$sha,'schema_present'=>$hasTable,'validated'=>$preview['validated'],'already_certified'=>$preview['already_certified'],'insert_count'=>count($preview['insert']),'business_writes'=>0],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);exit;}
$audit=$options['audit']??'';if($audit===''||file_exists($audit))throw new RuntimeException('NEW_AUDIT_PATH_REQUIRED');
$conn->execute('SET SESSION TRANSACTION READ WRITE');
$schemaCreated=false;
if(!$hasTable&&$mode==='apply'){$conn->execute(file_get_contents(__DIR__.'/schema.sql'));$hasTable=true;$schemaCreated=true;fwrite($progress,"PROOF_SCHEMA_CREATED: rollback preserves this empty/new table\n");$validateSchema();}
if(!$hasTable)throw new RuntimeException('ROLLBACK_TABLE_MISSING');
$conn->startTrans();
try{
 if($mode==='apply'){
  $locked=$check(true);$changed=$locked['insert'];
  foreach(array_chunk($changed,200)as$chunk)Db::name($proofTable)->insertAll($chunk);
 }else{
  $changed=Db::name($proofTable)->where('plan_sha256',$sha)->where('purpose',$purpose)->lock(true)->select()->toArray();
  $wanted=array_column($plan['proofs'],'snapshot_sha256','fact_id');
  foreach($changed as$row)if(($wanted[$row['fact_id']]??null)!==$row['snapshot_sha256']||$row['source_archive_sha256']!==$marker['source_archive_sha256'])throw new RuntimeException('ROLLBACK_PROOF_CONFLICT');
  Db::name($proofTable)->where('plan_sha256',$sha)->where('purpose',$purpose)->delete();
 }
 // Audit and its directory are fsynced before commit. Failure rolls back only
 // proof rows; MySQL DDL and the forensic audit file are deliberately retained.
 $record=['mode'=>$mode,'plan_sha256'=>$sha,'target'=>$target,'source_marker'=>$marker,'proof_rows'=>$changed,'business_fact_writes'=>0,'schema_created'=>$schemaCreated,'schema_retained_on_rollback'=>true,'created_at'=>gmdate('c')];
 $out=json_encode($record,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);$f=fopen($audit,'x');if(!$f)throw new RuntimeException('AUDIT_CREATE');
 if(fwrite($f,$out)!==strlen($out)||!fflush($f)){fclose($f);throw new RuntimeException('AUDIT_WRITE');}fclose($f);chmod($audit,0600);
 $helper=$root.'/database/upgrades/2026-10-30-旧服务子单归档来源认证/fsync_audit.py';
 exec('/usr/bin/python3 '.escapeshellarg($helper).' '.escapeshellarg($audit).' 2>&1',$syncOutput,$syncExit);
 if($syncExit!==0)throw new RuntimeException('AUDIT_FSYNC_FAILED');
 $conn->commit();echo json_encode(['mode'=>$mode,'plan_sha256'=>$sha,'proof_rows_changed'=>count($changed),'business_fact_writes'=>0,'schema_created'=>$schemaCreated,'schema_retained_on_rollback'=>true,'audit'=>$audit],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
}catch(Throwable$e){$conn->rollback();fwrite($progress,json_encode(['proof_transaction'=>'rolled_back','schema_created'=>$schemaCreated,'schema_retained'=>true,'business_fact_writes'=>0]).PHP_EOL);throw$e;}
