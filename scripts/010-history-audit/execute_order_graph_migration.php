<?php
/**
 * Execute fixed DATA graphs with transaction-bound archival and durable audit.
 * Default rehearsal is isolated and always rolls back. Runtime apply requires
 * the exact accepted manifest SHA and a separately verified database backup.
 * No application bootstrap, settlement replay, DDL or external payment calls.
 * Source fixtures, candidate inserts, holder remaps and old-copy archival all
 * share one transaction. A late source conflict must roll everything back.
 */
declare(strict_types=1);
ini_set('memory_limit','768M');
if(PHP_SAPI!=='cli'||count($argv)<4)throw new RuntimeException('WORK_AUDITDIR_MODE_REQUIRED');
$work=rtrim($argv[1],'/');$auditDir=rtrim($argv[2],'/');$mode=$argv[3];
if(!in_array($mode,['--rehearse','--apply'],true))throw new RuntimeException('EXPLICIT_MODE_REQUIRED');
$apply=$mode==='--apply';$limit=0;$injectAt=0;$accepted='';$backup='';$injectAuditFailure=false;$probeLocks=false;
foreach(array_slice($argv,4)as$arg){
 if(preg_match('/^--limit=([1-9][0-9]*)$/D',$arg,$m)&&!$apply)$limit=(int)$m[1];
 elseif(preg_match('/^--inject-conflict-at=([1-9][0-9]*)$/D',$arg,$m)&&!$apply)$injectAt=(int)$m[1];
 elseif($arg==='--inject-audit-failure'&&!$apply)$injectAuditFailure=true;
 elseif($arg==='--probe-range-locks'&&!$apply)$probeLocks=true;
 elseif(preg_match('/^--accepted-manifest-sha=([a-f0-9]{64})$/D',$arg,$m))$accepted=$m[1];
 elseif(strpos($arg,'--backup=')===0)$backup=substr($arg,9);
 else throw new RuntimeException('UNKNOWN_MIGRATION_ARGUMENT');
}
$manifestPath=$work.'/010-history-execution-manifest.json';
$manifestHash=hash_file('sha256',$manifestPath);
$manifest=json_decode(file_get_contents($manifestPath),true,512,JSON_THROW_ON_ERROR);
if(($manifest['target']??'')!=='mohe_010_app'||($manifest['applicationCodeChanged']??null)!==false
 ||($manifest['foundationChanged']??null)!==false)throw new RuntimeException('MANIFEST_BOUNDARY');
foreach($manifest['inputs']as$name=>$hash){
 if(basename($name)!==$name||hash_file('sha256',$work.'/'.$name)!==$hash)throw new RuntimeException('FIXED_INPUT_CHANGED:'.$name);
}
if(hash_file('sha256',__FILE__)!==$manifest['executorSHA256'])throw new RuntimeException('EXECUTOR_VERSION_CHANGED');
$syncHelper=__DIR__.'/fsync_migration_archive.py';
if(hash_file('sha256',$syncHelper)!==$manifest['auditSyncSHA256'])throw new RuntimeException('AUDIT_SYNC_VERSION_CHANGED');
if($apply&&($accepted!==$manifestHash||!is_file($backup)||is_link($backup)||filesize($backup)<1024
 ||!preg_match('/^\/root\/.+\.sql\.gz$/D',$backup)))throw new RuntimeException('FIXED_ACCEPTANCE_AND_BACKUP_REQUIRED');
$runtimeCheck=static function()use($manifest):void{
 foreach($manifest['runtimeFiles']as$name=>$hash){
  if(strpos($name,'app/services/')!==0||strpos($name,'..')!==false
   ||hash_file('sha256','/www/wwwroot/010.cc3798.com/'.$name)!==$hash)throw new RuntimeException('RUNTIME_BASELINE_CHANGED');
 }
};
$runtimeCheck();
$database=$apply?'mohe_010_app':'mohe_010_migration_test_refresh';
$auditPath=$auditDir.'/audit.ndjson';
$keys=[
 'cashier_v3_checkout_request'=>'request_id','cashier_v3_sales_order'=>'order_id',
 'cashier_v3_sales_order_line'=>'order_line_id','cashier_v3_sale_fact'=>'fact_id',
 'cashier_v3_checkout_payment_draft'=>'payment_draft_id','cashier_v3_payment_collection_batch'=>'batch_id',
 'cashier_v3_payment_collection'=>'collection_id','cashier_v3_payment_fact'=>'fact_id',
 'cashier_v3_payment_sale_allocation_fact'=>'allocation_fact_id','cashier_v3_balance_fact'=>'fact_id',
 'cashier_v3_performance_fact'=>'fact_id','store_order'=>'id','store_order_cart_info'=>'id',
 'cashier_v3_card_purchase_receipt'=>'receipt_id','migration_entitlement_scope'=>'source_detail_id',
 'cashier_v3_entitlement_service_fact'=>'service_fact_id','cashier_v3_entitlement_writeoff_fact'=>'writeoff_id',
 'cashier_v3_service_record_void_operation'=>'operation_id','cashier_v3_entitlement_reversal_fact'=>'reversal_fact_id',
 'cashier_v3_business_event'=>'event_no',
 'cashier_v3_order_balance_allocation'=>'order_line_id',
];
$sourceTables=['order'=>'store_order','detail'=>'store_order_cart_info','holder'=>'user_card_holder','writeoff'=>'store_order_writeoff'];
$protected=['user','user_money','system_store','system_store_staff','system_admin','system_menus','system_config',
 'store_product','organization','organization_store','cashier_v3_business_source'];
$flags=JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS|JSON_THROW_ON_ERROR;
$json=static function($value)use($flags):string{return json_encode($value,$flags);};
$hashRow=static function(array $row)use($json):string{ksort($row);return hash('sha256',$json($row));};
$stream=static function(string $path):Generator {
    $f=gzopen($path,'rb'); if(!$f)throw new RuntimeException('INPUT_UNREADABLE');
    try {while(($line=gzgets($f))!==false)yield json_decode($line,true,512,JSON_THROW_ON_ERROR);}
    finally {gzclose($f);}
};
$graphPath=$work.'/010-history-assembled-order-graphs.ndjson.gz';
$guardPath=$work.'/010-history-graph-verified-source-guards.ndjson.gz';
$preflightPath=$work.'/010-history-graph-current-preflight.ndjson.gz';
$v=json_decode(file_get_contents($work.'/010-history-graph-current-preflight-verification.json'),true,512,JSON_THROW_ON_ERROR);
if($v['passed']!==true||$v['productionWrites']!==0||$v['readyForApply']!==false
 ||hash_file('sha256',$graphPath)!==$v['sourceMetadata']['graphsSHA256']
 ||hash_file('sha256',$guardPath)!==$v['guardSHA256'])throw new RuntimeException('VERIFIED_INPUTS_REQUIRED');
$selected=[];$archives=[];$holders=[];$writeoffs=[];$expectedInserts=0;$expectedDirtyDeltas=[];
foreach($stream($graphPath)as$g){
 if($limit&&count($selected)>=$limit)break;
 if($g['readyForApply']!==false||isset($selected[$g['sourceOrderId']]))throw new RuntimeException('GRAPH_SCOPE');
 $selected[$g['sourceOrderId']]=true;
 foreach($g['archiveAfterSuccessfulGraph']as$a)$archives[(int)$a['where']['id']]=true;
 foreach($g['businessMutations']as$m)$holders[(int)$m['where']['id']]=true;
 foreach($g['sourceWriteoffIds']as$id)$writeoffs[$id]=true;
 foreach($g['insertTables']as$t=>$rows){if(!isset($keys[$t]))throw new RuntimeException('INSERT_TABLE_NOT_ALLOWED');$expectedInserts+=count($rows);}
 // Existing receipt/holder triggers each mark the same owner twice. Preserve
 // those normal invalidations; never disable triggers or modify their code.
 foreach($g['insertTables']['cashier_v3_card_purchase_receipt']??[]as$r){
  $uid=(int)$r['member_id'];if($uid<=0)throw new RuntimeException('CARD_OWNER_REQUIRED');
  $expectedDirtyDeltas[$uid]=($expectedDirtyDeltas[$uid]??0)+2;
 }
 foreach($g['businessMutations']as$m){
  $uid=(int)$m['expectedBefore']['uid'];if($uid<=0)throw new RuntimeException('HOLDER_OWNER_REQUIRED');
  $expectedDirtyDeltas[$uid]=($expectedDirtyDeltas[$uid]??0)+2;
 }
}
if(!$selected||($injectAt>count($selected))||(!$limit&&count($selected)!==$manifest['graphs']))throw new RuntimeException('EMPTY_OR_INVALID_TEST_SCOPE');
$guards=[];
foreach($stream($guardPath)as$r){
 if(!in_array($r['table'],$sourceTables,true))throw new RuntimeException('GUARD_TABLE_NOT_ALLOWED');
 $guards[$r['table']][(int)$r['id']]=$r['sha256'];
}
$c=parse_ini_file('/root/.mysql57-008.cnf',true,INI_SCANNER_RAW)['client'];
$db=new PDO('mysql:host=127.0.0.1;port=3307;dbname='.$database.';charset=utf8mb4',
 $c['user']??'root',$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
 PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,
 PDO::ATTR_STRINGIFY_FETCHES=>true,PDO::MYSQL_ATTR_USE_BUFFERED_QUERY=>false]);unset($c);
if($db->query('SELECT DATABASE()')->fetchColumn()!==$database)throw new RuntimeException('TARGET_DATABASE_MISMATCH');
$db->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
$db->exec('SET SESSION innodb_lock_wait_timeout=15');
$db->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
if((int)$db->query("SELECT GET_LOCK('010-history-data-migration',0)")->fetchColumn()!==1)throw new RuntimeException('ANOTHER_REHEARSAL_RUNNING');
if(file_exists($auditDir)||!mkdir($auditDir,0700))throw new RuntimeException('NEW_AUDIT_DIRECTORY_REQUIRED');
foreach($manifest['inputs']as$name=>$hash){
 if(!copy($work.'/'.$name,$auditDir.'/'.$name)||hash_file('sha256',$auditDir.'/'.$name)!==$hash)throw new RuntimeException('AUDIT_INPUT_COPY_FAILED');
 chmod($auditDir.'/'.$name,0600);
}
if(!copy($manifestPath,$auditDir.'/010-history-execution-manifest.json')
 ||hash_file('sha256',$auditDir.'/010-history-execution-manifest.json')!==$manifestHash
 ||!copy(__FILE__,$auditDir.'/execute_order_graph_migration.php')
 ||hash_file('sha256',$auditDir.'/execute_order_graph_migration.php')!==$manifest['executorSHA256'])throw new RuntimeException('AUDIT_CONTROL_COPY_FAILED');
if(!copy($syncHelper,$auditDir.'/fsync_migration_archive.py')
 ||hash_file('sha256',$auditDir.'/fsync_migration_archive.py')!==$manifest['auditSyncSHA256'])throw new RuntimeException('AUDIT_SYNC_COPY_FAILED');
chmod($auditDir.'/010-history-execution-manifest.json',0600);
chmod($auditDir.'/execute_order_graph_migration.php',0600);
chmod($auditDir.'/fsync_migration_archive.py',0600);
$backupHash=$apply?hash_file('sha256',$backup):null;
$audit=fopen($auditPath,'x');if(!$audit)throw new RuntimeException('NEW_AUDIT_FILE_REQUIRED');chmod($auditPath,0600);
$auditHash=hash_init('sha256');
$emit=static function(array $r)use($audit,$auditHash,$json):void{
 $line=$json($r)."\n";if(fwrite($audit,$line)!==strlen($line))throw new RuntimeException('AUDIT_WRITE_FAILED');
 hash_update($auditHash,$line);fflush($audit);
};
$allTables=array_values(array_unique(array_merge(array_keys($keys),array_values($sourceTables),$protected)));
// Existing import-control table is the atomic commit marker; no schema change.
$markerTable='codex_010_migration_batch';
$batchKey='010-history-graph-'.substr($v['sourceMetadata']['graphsSHA256'],0,24);
$allTables[]=$markerTable;
$derivedTable='ai_member_rights_amount_dirty';$allTables[]=$derivedTable;
$sqlTable=static function(string $t)use($markerTable):string{return $t===$markerTable?$t:'eb_'.$t;};
$schemas=[];$primary=[];
foreach($allTables as$t){
 $q=$db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$sqlTable($t)]);
 if($q->fetchColumn()!=='InnoDB')throw new RuntimeException('TRANSACTIONAL_ENGINE_REQUIRED:'.$t);$q->closeCursor();
 $schemas[$t]=$db->query('SHOW COLUMNS FROM '.$sqlTable($t))->fetchAll();
 $pk=$db->query('SHOW INDEX FROM '.$sqlTable($t)." WHERE Key_name='PRIMARY'")->fetchAll();
 usort($pk,static function($a,$b){return(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index'];});
 $primary[$t]=array_column($pk,'Column_name');if(!$primary[$t])throw new RuntimeException('PRIMARY_KEY_REQUIRED:'.$t);
}
$fingerprint=static function(array $tables,bool $lock=false)use($db,$primary,$json,$sqlTable):array{
 $result=[];foreach($tables as$t){$h=hash_init('sha256');$n=0;
  $q=$db->query('SELECT * FROM '.$sqlTable($t).' ORDER BY `'.implode('`,`',$primary[$t]).'`'.($lock?' LOCK IN SHARE MODE':''));
  while($r=$q->fetch()){ksort($r);hash_update($h,$json($r)."\n");++$n;}$q->closeCursor();
  $result[$t]=['rows'=>$n,'sha256'=>hash_final($h)];
 }return$result;
};
$statements=[];
$insert=static function(string $t,array $r,bool $fixture=false)use($db,$keys,$sourceTables,$schemas,&$statements):void{
 if($fixture?!in_array($t,$sourceTables,true):!isset($keys[$t]))throw new RuntimeException('WRITE_TABLE_FORBIDDEN');
 $cols=array_keys($r);$allowed=array_column($schemas[$t],'Field');
 if(!$cols||array_diff($cols,$allowed))throw new RuntimeException('UNKNOWN_INSERT_COLUMN:'.$t);
 foreach($cols as$col)if(!preg_match('/^[A-Za-z0-9_]+$/D',$col))throw new RuntimeException('INVALID_COLUMN');
 $signature=$t.'|'.(int)$fixture.'|'.implode(',',$cols);
 if(!isset($statements[$signature])){
  $sql='INSERT INTO eb_'.$t.' (`'.implode('`,`',$cols).'`) VALUES ('.implode(',',array_fill(0,count($cols),'?')).')';
  if($fixture)$sql.=' ON DUPLICATE KEY UPDATE '.implode(',',array_map(static function($col){return'`'.$col.'`=VALUES(`'.$col.'`)';},$cols));
  $statements[$signature]=$db->prepare($sql);
 }$statements[$signature]->execute(array_values($r));
};
$readStatements=[];
$readRow=static function(string $t,string $key,$id,bool $lock=false)use($db,$schemas,&$readStatements):array{
 // Imported fact IDs are scoped by tenant in composite unique indexes. Use
 // that same predicate so whole-batch verification does not scan growing
 // tables for every row. Numeric primary-key/source reads remain unchanged.
 $tenant=$key!=='id'&&in_array('tenant_id',array_column($schemas[$t],'Field'),true)?" AND tenant_id='0'":'';
 $signature=$t.'|'.$key.'|'.(int)$lock;
 if(!isset($readStatements[$signature]))$readStatements[$signature]=$db->prepare('SELECT * FROM eb_'.$t.' WHERE `'.$key.'`=?'.$tenant.($lock?' FOR UPDATE':''));
 $q=$readStatements[$signature];$q->execute([$id]);
 $rows=$q->fetchAll();if(count($rows)!==1)throw new RuntimeException('ROW_NOT_UNIQUE:'.$t.':'.$id);return$rows[0];
};
$checkGuard=static function(string $t,int $id)use($guards,$hashRow,$readRow):array{
 $r=$readRow($t,'id',$id,true);
 if(!isset($guards[$t][$id])||$hashRow($r)!==$guards[$t][$id])throw new RuntimeException('SOURCE_GUARD_CONFLICT:'.$t.':'.$id);
 return$r;
};
$sameValue=static function($expected,$actual,string $type):bool{
 if($expected===null||$actual===null)return$expected===$actual;
 if(preg_match('/^(decimal|numeric)\(/',$type))return bccomp((string)$expected,(string)$actual,10)===0;
 if($type==='json')return json_decode((string)$expected,true)===json_decode((string)$actual,true);
 return(string)$expected===(string)$actual;
};
$before=[];$after=[];$protectedBefore=[];$details=[];$seeded=[];$done=0;$inserted=0;$archived=0;$mutated=0;$failure=null;$rollbackVerified=false;$committed=false;$commitAttempted=false;$sealedHash=null;$markerVerified=false;$rangeLocksVerified=false;$dirtyAudit=[];
try{
 $before=$apply?[]:$fingerprint($allTables);$protectedBefore=array_intersect_key($before,array_flip($protected));
 $emit(['kind'=>'begin','database'=>$database,'mode'=>$apply?'apply':'rollback-only','productionWrites'=>0,
  'manifestSHA256'=>$manifestHash,'batchKey'=>$batchKey,'backupSHA256'=>$backupHash,
  'graphs'=>count($selected),'expectedInsertRows'=>$expectedInserts,'graphSHA256'=>hash_file('sha256',$graphPath),
  'preflightSHA256'=>hash_file('sha256',$preflightPath),'guardSHA256'=>hash_file('sha256',$guardPath),'baseline'=>$before]);
 $db->beginTransaction();
 $q=$db->prepare('SELECT * FROM '.$markerTable.' WHERE batch_key=? FOR UPDATE');$q->execute([$batchKey]);
 if($q->fetchAll())throw new RuntimeException('BATCH_ALREADY_RECORDED_DO_NOT_REPLAY');
 // Verify real write constraints against the accepted evidence. Ordinary
 // lookup-index differences are reported elsewhere; unique/FK/trigger rules
 // cannot change silently between rehearsal and execution.
 $constraint=json_decode(file_get_contents($work.'/010-history-graph-current-write-constraints.json'),true,512,JSON_THROW_ON_ERROR);
 $constraintTables=array_values(array_unique(array_merge(array_keys($keys),array_values($sourceTables))));
 $names=array_map(static function($t){return'eb_'.$t;},$constraintTables);
 $in=implode(',',array_fill(0,count($names),'?'));
 $queries=[
  'uniqueIndexes'=>"SELECT TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,COLLATION,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME IN ($in) AND NON_UNIQUE=0 ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX",
  'foreignKeys'=>"SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,ORDINAL_POSITION,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND TABLE_NAME IN ($in) AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION",
  'triggers'=>"SELECT EVENT_OBJECT_TABLE,TRIGGER_NAME,ACTION_TIMING,EVENT_MANIPULATION,SHA2(ACTION_STATEMENT,256) statement_sha256 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=? AND EVENT_OBJECT_TABLE IN ($in) ORDER BY EVENT_OBJECT_TABLE,TRIGGER_NAME",
 ];
 foreach($queries as$kind=>$sql){
  $q=$db->prepare($sql);$q->execute(array_merge([$database],$names));
  if($q->fetchAll()!==$constraint['evidence'][$database][$kind])throw new RuntimeException('WRITE_CONSTRAINT_CHANGED:'.$kind);
 }
 $derivedConstraints=json_decode(file_get_contents($work.'/010-history-graph-current-derived-constraints.json'),true,512,JSON_THROW_ON_ERROR);
 $derivedExpected=$derivedConstraints['evidence'][$database];
 $q=$db->prepare("SELECT ROUTINE_NAME,ROUTINE_TYPE,SQL_DATA_ACCESS,SHA2(ROUTINE_DEFINITION,256) definition_sha256 FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=? AND ROUTINE_NAME IN ('eb_ai_rights_mark_holder','eb_ai_rights_mark_member','eb_ai_rights_mark_order') ORDER BY ROUTINE_NAME");
 $q->execute([$database]);
 if($derivedConstraints['passed']!==true||$derivedExpected['engine']!=='InnoDB'
  ||$schemas[$derivedTable]!==$derivedExpected['columns']||$q->fetchAll()!==$derivedExpected['routines'])throw new RuntimeException('DERIVED_TRIGGER_DEPENDENCY_CHANGED');
 // Fixture preparation is isolated and rollback-bound; foundation rows are
 // never seeded. The runner tests physical graph writes, not account access.
 foreach($stream($preflightPath)as$e){
  $kind=$e['kind'];$r=$e['row'];
  if($kind==='schema'){
   $actual=array_column($schemas[$r['table']],null,'Field');$expected=array_column($r['columns'],null,'Field');
   ksort($actual);ksort($expected);
   // SHOW COLUMNS reports ordinary lookup indexes as MUL. Their absence in
   // the old isolated fixture changes query cost, not the column's write
   // contract. Record this difference; never relax PRI/UNI or value rules.
   foreach($actual as$col=>&$definition){
    if(isset($expected[$col]) && $definition['Key']!==$expected[$col]['Key']
       && in_array($definition['Key'],['','MUL'],true) && in_array($expected[$col]['Key'],['','MUL'],true)){
     $emit(['kind'=>'nonunique-index-difference','table'=>$r['table'],'column'=>$col,
       'isolated'=>$definition['Key'],'runtime'=>$expected[$col]['Key'],'performanceEquivalenceClaimed'=>false]);
     $definition['Key']=$expected[$col]['Key'];
    }
   }unset($definition);
   if($actual!==$expected){
    $diff=[];foreach(array_unique(array_merge(array_keys($actual),array_keys($expected)))as$col)
     if(($actual[$col]??null)!==($expected[$col]??null))$diff[$col]=['actual'=>$actual[$col]??null,'expected'=>$expected[$col]??null];
    $emit(['kind'=>'schema-difference','table'=>$r['table'],'columns'=>$diff]);
    throw new RuntimeException('ISOLATION_SCHEMA_DIFF:'.$r['table']);
   }continue;
  }
  if(!isset($sourceTables[$kind]))continue;$value=$r['value'];$id=(int)$value['id'];
  $wanted=$kind==='order'?isset($archives[$id]):($kind==='detail'?isset($selected[(int)$value['oid']]):($kind==='holder'?isset($holders[$id]):isset($writeoffs[$id])));
  if(!$wanted)continue;$t=$sourceTables[$kind];
  if($hashRow($value)!==$r['sha256']||($guards[$t][$id]??null)!==$r['sha256'])throw new RuntimeException('FIXTURE_NOT_GUARDED');
  if(!$apply)$insert($t,$value,true);$checkGuard($t,$id);$seeded[$t]=($seeded[$t]??0)+1;
  if($kind==='detail')$details[(int)$value['oid']][]=$id;
 }
 // A row hash alone misses newly attached children. Lock the complete source
 // relationship ranges before importing anything, including empty ranges.
 $relationDefinitions=[
  ['details','store_order_cart_info','oid',array_keys($selected),''],
  ['holders','user_card_holder','oid',array_keys($selected),''],
  ['writeoffs','store_order_writeoff','oid',array_keys($selected),''],
  ['oldSuborders','store_order','link_order',array_keys($selected),' AND order_type=2'],
  ['oldSuborders','store_order','link_id',array_keys($writeoffs),' AND order_type=2'],
  ['children','store_order','pid',array_keys($selected),''],
  ['refunds','store_order_refund','store_order_id',array_keys($selected),''],
 ];
 $actualRelations=[];
 foreach($relationDefinitions as[$kind,$t,$key,$ids,$extra])foreach(array_chunk($ids,300)as$chunk){
  $q=$db->prepare('SELECT id FROM eb_'.$t.' WHERE '.$key.' IN('.implode(',',array_fill(0,count($chunk),'?')).')'.$extra.' FOR UPDATE');
  $q->execute($chunk);foreach($q->fetchAll()as$r)$actualRelations[$kind][(int)$r['id']]=true;
 }
 $expectedDetails=[];foreach($details as$ids)foreach($ids as$id)$expectedDetails[$id]=true;
 $expectedRelations=['details'=>$expectedDetails,'holders'=>$holders,'writeoffs'=>$writeoffs,
  'oldSuborders'=>array_diff_key($archives,$selected),'children'=>[],'refunds'=>[]];
 foreach($expectedRelations as$kind=>$ids){
  $actual=$actualRelations[$kind]??[];
  if(array_diff_key($ids,$actual)||array_diff_key($actual,$ids))throw new RuntimeException('SOURCE_RELATIONSHIP_CHANGED:'.$kind);
 }
 if($probeLocks){
  // A second isolated transaction tries to attach a new child. It must time
  // out on the first transaction's range lock, then always roll back. This
  // tests a newly inserted relation, which old-row hashes alone cannot detect.
  $credential=parse_ini_file('/root/.mysql57-008.cnf',true,INI_SCANNER_RAW)['client'];
  $other=new PDO('mysql:host=127.0.0.1;port=3307;dbname=mohe_010_migration_test_refresh;charset=utf8mb4',
   $credential['user']??'root',$credential['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);unset($credential);
  if($other->query('SELECT DATABASE()')->fetchColumn()!=='mohe_010_migration_test_refresh')throw new RuntimeException('LOCK_PROBE_ISOLATION_REQUIRED');
  $other->exec('SET SESSION innodb_lock_wait_timeout=1');$other->beginTransaction();
  try{
   $parent=(int)array_keys($selected)[0];$copy=$checkGuard('store_order',$parent);
   $copy['id']=(int)$other->query('SELECT MAX(id) FROM eb_store_order')->fetchColumn()+10000000;
   $copy['order_id']='QA-RANGE-'.(string)$copy['id'];$copy['pid']=$parent;
   $copy['unique']=md5('010-isolated-range-probe-'.$copy['id']);
   $q=$other->prepare('INSERT INTO eb_store_order (`'.implode('`,`',array_keys($copy)).'`) VALUES ('.implode(',',array_fill(0,count($copy),'?')).')');
   try{$q->execute(array_values($copy));}
   catch(PDOException$e){if((int)($e->errorInfo[1]??0)===1205)$rangeLocksVerified=true;else throw$e;}
  }finally{$other->rollBack();unset($other);}
  if(!$rangeLocksVerified)throw new RuntimeException('CONCURRENT_CHILD_NOT_BLOCKED');
 }
 $protectedBefore=$fingerprint($protected,true);
 $dirtyBefore=array_column($db->query('SELECT * FROM eb_ai_member_rights_amount_dirty ORDER BY member_id FOR UPDATE')->fetchAll(),null,'member_id');
 if($apply)foreach($v['protectedTables']as$r){
  if(($protectedBefore[$r['table']]??null)!==['rows'=>$r['rows'],'sha256'=>$r['sha256']])throw new RuntimeException('PROTECTED_BASELINE_CHANGED:'.$r['table']);
 }
 $emit(['kind'=>'source-ranges-locked','relationships'=>array_map('count',$expectedRelations),
  'fixturesWritten'=>!$apply,'rows'=>$seeded,'protectedBefore'=>$protectedBefore]);
 fwrite(STDERR,$json(['phase'=>'source-ranges-locked','rows'=>$seeded])."\n");
 foreach($stream($graphPath)as$g){
  $oid=(int)$g['sourceOrderId'];if(!isset($selected[$oid]))continue;
  if($injectAt===$done+1){
   $q=$db->prepare('UPDATE eb_store_order SET is_system_del=1-is_system_del WHERE id=?');$q->execute([$oid]);
  }
  foreach($g['archiveAfterSuccessfulGraph']as$a)$checkGuard('store_order',(int)$a['where']['id']);
  foreach($details[$oid]??[]as$id)$checkGuard('store_order_cart_info',$id);
  foreach($g['sourceWriteoffIds']as$id)$checkGuard('store_order_writeoff',$id);
  foreach($g['businessMutations']as$m)$checkGuard('user_card_holder',(int)$m['where']['id']);
  $graphAudit=['kind'=>'graph-staged','sourceOrderId'=>$oid,'insertCounts'=>[],'mutations'=>[],'archives'=>[]];
  foreach($g['insertTables']as$t=>$rows){
   foreach($rows as$r){
    $insert($t,$r);$saved=$readRow($t,$keys[$t],$r[$keys[$t]]);
    $types=array_column($schemas[$t],'Type','Field');
    foreach($r as$col=>$value)if(!$sameValue($value,$saved[$col],$types[$col]))throw new RuntimeException('INSERT_READBACK_MISMATCH:'.$t.':'.$col);
    ++$inserted;
   }$graphAudit['insertCounts'][$t]=count($rows);
  }
  foreach($g['businessMutations']as$m){
   if($m['table']!=='user_card_holder'||array_diff(array_keys($m['set']),['oid','store_id','write_times']))throw new RuntimeException('MUTATION_BOUNDARY');
   $id=(int)$m['where']['id'];$old=$checkGuard('user_card_holder',$id);
   if($hashRow($old)!==$hashRow($m['expectedBefore']))throw new RuntimeException('HOLDER_EXPECTED_BEFORE_DIFF');
   foreach($m['where']as$k=>$value)if((string)$old[$k]!== (string)$value)throw new RuntimeException('HOLDER_PREDICATE_DIFF');
   $q=$db->prepare('UPDATE eb_user_card_holder SET '.implode(',',array_map(static function($k){return'`'.$k.'`=?';},array_keys($m['set']))).' WHERE id=?');
   $q->execute(array_merge(array_values($m['set']),[$id]));$new=$readRow('user_card_holder','id',$id);
   foreach($old as$k=>$value)if((string)($m['set'][$k]??$value)!==(string)$new[$k])throw new RuntimeException('HOLDER_UNEXPECTED_CHANGE:'.$k);
   $graphAudit['mutations'][]=['table'=>'user_card_holder','id'=>$id,'beforeSHA256'=>$hashRow($old),'afterSHA256'=>$hashRow($new),'set'=>$m['set']];++$mutated;
  }
  // Archive only after all exact inserts and authorized holder remaps passed.
  foreach($g['archiveAfterSuccessfulGraph']as$a){
   if($a['table']!=='store_order'||$a['set']!==['is_system_del'=>1]||$a['keepOriginalRow']!==true)throw new RuntimeException('ARCHIVE_BOUNDARY');
   $id=(int)$a['where']['id'];$old=$checkGuard('store_order',$id);
   foreach($a['where']as$k=>$value)if((string)$old[$k]!==(string)$value)throw new RuntimeException('ARCHIVE_PREDICATE_DIFF');
   if((int)$old['is_system_del']!==0)throw new RuntimeException('ALREADY_ARCHIVED_SOURCE');
   $q=$db->prepare('UPDATE eb_store_order SET is_system_del=1 WHERE id=? AND is_system_del=0');$q->execute([$id]);
   if($q->rowCount()!==1)throw new RuntimeException('ARCHIVE_ROWCOUNT');
   $new=$readRow('store_order','id',$id);$wanted=$old;$wanted['is_system_del']='1';
   if($hashRow($new)!==$hashRow($wanted))throw new RuntimeException('ARCHIVE_CHANGED_OTHER_FIELDS');
   $graphAudit['archives'][]=['id'=>$id,'beforeSHA256'=>$hashRow($old),'afterSHA256'=>$hashRow($new)];++$archived;
  }
  ++$done;$emit($graphAudit);
  if($done%500===0)fwrite(STDERR,$json(['phase'=>'graphs-staged','graphs'=>$done,'inserts'=>$inserted,'archives'=>$archived])."\n");
  if($done===count($selected))break;
 }
 if($injectAt)throw new RuntimeException('INJECTED_CONFLICT_NOT_DETECTED');
 if($done!==count($selected)||$inserted!==$expectedInserts||$archived!==count($archives)||$mutated!==count($holders))throw new RuntimeException('FULL_COVERAGE_FAILED');
 if($fingerprint($protected)!==$protectedBefore)throw new RuntimeException('PROTECTED_ROWS_CHANGED');
 $dirtyAfter=array_column($db->query('SELECT * FROM eb_ai_member_rights_amount_dirty ORDER BY member_id')->fetchAll(),null,'member_id');
 foreach(array_unique(array_merge(array_keys($dirtyBefore),array_keys($dirtyAfter)))as$uid){
  $old=$dirtyBefore[$uid]??null;$new=$dirtyAfter[$uid]??null;$delta=$expectedDirtyDeltas[$uid]??0;
  if(!$delta){if($old!==$new)throw new RuntimeException('UNEXPECTED_RIGHTS_INVALIDATION:'.$uid);continue;}
  if($new===null||(int)$new['version']-(int)($old['version']??0)!==$delta)throw new RuntimeException('RIGHTS_INVALIDATION_COUNT_MISMATCH:'.$uid);
  $dirtyAudit[]=['memberId'=>(int)$uid,'versionDelta'=>$delta,'before'=>$old,'after'=>$new];
 }
 if(count($dirtyAudit)!==count($expectedDirtyDeltas))throw new RuntimeException('RIGHTS_INVALIDATION_COVERAGE_MISMATCH');
 $emit(['kind'=>'derived-rights-notifications','expectedVersionIncrements'=>array_sum($expectedDirtyDeltas),'members'=>$dirtyAudit]);
 $emit(['kind'=>'staging-verified','graphs'=>$done,'inserted'=>$inserted,'archived'=>$archived,'holders'=>$mutated,'protectedUnchanged'=>true]);
 $runtimeCheck();
 // Seal an external write-ahead audit before the DB commit. The existing
 // import-control row ties this exact archive hash to the atomic transaction.
 // A crash before commit rolls the marker and business writes back together.
 fflush($audit);$auditDigest=hash_file('sha256',$auditPath);
 $seal=['batchKey'=>$batchKey,'manifestSHA256'=>$manifestHash,'inputs'=>$manifest['inputs'],
  'auditSHA256'=>$auditDigest,'backupSHA256'=>$backupHash,'graphs'=>$done,'insertedRows'=>$inserted,
  'archivedCopies'=>$archived,'holders'=>$mutated,'derivedNotificationMembers'=>count($dirtyAudit),
  'derivedVersionIncrements'=>array_sum($expectedDirtyDeltas),'target'=>$database];
 $sealPath=$auditDir.'/sealed-archive.json';
 $sealed=$json($seal)."\n";
 if(file_put_contents($sealPath,$sealed)!==strlen($sealed))throw new RuntimeException('AUDIT_SEAL_FAILED');
 chmod($sealPath,0600);$sealedHash=hash('sha256',$sealed);
 $syncOutput=[];$syncExit=0;
 exec('/usr/bin/python3 '.escapeshellarg($auditDir.'/fsync_migration_archive.py').' '.escapeshellarg($auditDir).' 2>&1',$syncOutput,$syncExit);
 if($syncExit!==0)throw new RuntimeException('AUDIT_DURABILITY_FAILED');
 if($injectAuditFailure)throw new RuntimeException('INJECTED_AUDIT_DURABILITY_FAILURE');
 $q=$db->prepare('INSERT INTO '.$markerTable.' (batch_key,source_schema,source_archive_sha256,source_member_count,target_member_count,committed_at) VALUES (?,?,?,?,?,?)');
 $q->execute([$batchKey,'mohe_010_refresh',$sealedHash,$manifest['sourceMemberCount'],$manifest['targetMemberCount'],time()]);
 $q=$db->prepare('SELECT * FROM '.$markerTable.' WHERE batch_key=?');$q->execute([$batchKey]);$mark=$q->fetchAll();
 if(count($mark)!==1||$mark[0]['source_archive_sha256']!==$sealedHash)throw new RuntimeException('ATOMIC_MARKER_READBACK_FAILED');
 $markerVerified=true;
 if($apply){$commitAttempted=true;$db->commit();$committed=true;}
}catch(Throwable$e){
 $failure=$e->getMessage();
 // Once sealed, never append to the audit whose hash is stored in the marker.
 if($sealedHash===null)$emit(['kind'=>'failed','reason'=>$failure,'graphsCompleted'=>$done]);
}finally{
 if($db->inTransaction())$db->rollBack();
 if(!$apply){$after=$fingerprint($allTables);$rollbackVerified=$before!==[]&&$after===$before;}
 fclose($audit);
 $db->query("SELECT RELEASE_LOCK('010-history-data-migration')")->fetchColumn();
}
$expectedConflict=$injectAt&&$done===$injectAt-1&&strpos((string)$failure,'SOURCE_GUARD_CONFLICT:store_order:')===0;
$expectedAuditFailure=$injectAuditFailure&&$failure==='INJECTED_AUDIT_DURABILITY_FAILURE'&&!$markerVerified;
$passed=$apply?$committed&&$failure===null:$rollbackVerified&&($injectAt?$expectedConflict:($injectAuditFailure?$expectedAuditFailure:$failure===null&&$markerVerified));
$result=['passed'=>$passed,'productionWrites'=>$committed?$inserted+$archived+$mutated+1:($commitAttempted?null:0),
 'mode'=>$apply?'apply':'rollback-only','database'=>$database,'manifestSHA256'=>$manifestHash,'batchKey'=>$batchKey,
 'committed'=>$committed,'commitOutcomeUnknown'=>$commitAttempted&&!$committed,
 'atomicMarkerVerified'=>$markerVerified,'expectedAuditFailureDetected'=>$expectedAuditFailure,
 'derivedNotificationMembersVerified'=>count($dirtyAudit),'derivedVersionIncrements'=>array_sum($expectedDirtyDeltas),
 'concurrentChildRangeLockVerified'=>$rangeLocksVerified,
 'sealedArchiveSHA256'=>$sealedHash,'graphsRequested'=>count($selected),
 'graphsStaged'=>$done,'insertRowsStaged'=>$inserted,'archivedRowsStaged'=>$archived,'holderRowsStaged'=>$mutated,
 'sourceFixtureRows'=>$seeded,'injectedConflictAt'=>$injectAt,'expectedConflictDetected'=>(bool)$expectedConflict,
 'failure'=>$failure,'rollbackRowsVerified'=>$rollbackVerified,'auditSHA256'=>hash_final($auditHash),
 'rollbackBefore'=>$before,'rollbackAfter'=>$after,
 'limitations'=>['no_browser_or_business_service_test','isolated_auto_increment_sequences_may_advance',
  'postcommit_undo_requires_downstream_review_do_not_blindly_restore']];
 file_put_contents($auditDir.'/result.json',$json($result)."\n");chmod($auditDir.'/result.json',0600);
 echo $json($result)."\n";
exit($passed?0:1);
