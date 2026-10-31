<?php
declare(strict_types=1);
/** Exercise the actual migration transaction on connection-local temporary tables. */
$root=getenv('BACKEND_ROOT')?:dirname(__DIR__,3).'/后端代码';
require $root.'/vendor/autoload.php';(new think\App($root.'/'))->initialize();
use app\services\report\ImportedPerformanceTypeProvenanceServices as Proof;
use think\facade\Db;
if(config('database.connections.mysql.hostname')!=='mysql')throw new RuntimeException('LOCAL_FIXTURE_ONLY');
$dir=$root.'/database/upgrades/2026-10-30-导入人员类型来源认证';
$source=file_get_contents($dir.'/execute.php');
$source=str_replace("\$options=getopt('',['plan:','expected-sha:','mode:','audit:']);",'$options=$fixtureOptions;',$source);
$source=str_replace("\$bytes=file_get_contents(\$options['plan']??'');",'$bytes=$fixtureBytes;',$source);
$source=str_replace("\$root=dirname(__DIR__,3);require \$root.'/vendor/autoload.php';(new think\\App(\$root.'/'))->initialize();",'$root=$fixtureRoot;',$source);
$source=str_replace("fopen('php://stderr','w')","fopen('php://memory','w')",$source);
$source=str_replace("['localhost','127.0.0.1']","['mysql']",$source);
$source=str_replace("\$hasTable=(int)(\$existing[0]['n']??0)===1;",'$hasTable=true;',$source);
$source=str_replace('if($hasTable)$validateSchema();','/* TEMPORARY shadow verified by fixture DDL */',$source);
// The PHP-only local container has no Python. Its coreutils sync -f supplies
// the same fsync barrier here; the shipped Python helper is tested separately.
$source=str_replace("'/usr/bin/python3 '.escapeshellarg(\$helper).' '.escapeshellarg(\$audit)","'/bin/sync -f '.escapeshellarg(\$audit)",$source);
$source=str_replace("'cashier_v3_performance_fact'","'tmp_import_executor_fact'",$source);
$f=array_fill_keys(Proof::FIELDS,'');$f=array_replace($f,['tenant_id'=>'0','fact_id'=>'fixture-1','store_id'=>'4','employee_id'=>'7','employee_type_snapshot'=>'partner','employee_type_authority_version'=>'0','amount_cents'=>'196000']);
$plan=['purpose'=>Proof::PURPOSE,'target'=>['database'=>config('database.connections.mysql.database'),'hostport'=>(string)config('database.connections.mysql.hostport'),'prefix'=>'eb_'],'source_marker'=>['table'=>'codex_010_migration_batch','batch_key'=>'fixture-batch','source_archive_sha256'=>str_repeat('a',64)],'proofs'=>[['tenant_id'=>'0','fact_id'=>'fixture-1','snapshot_sha256'=>Proof::fingerprint($f)]]];
$bytes=json_encode($plan,JSON_THROW_ON_ERROR);$sha=hash('sha256',$bytes);$paths=[];$checks=0;
$run=static function(string$mode,string$audit)use(&$source,$bytes,$sha,$root):array{
 $fixtureRoot=$root;
 $fixtureOptions=['mode'=>$mode,'expected-sha'=>$sha,'audit'=>$audit];$fixtureBytes=$bytes;
 ob_start();try{eval(substr($source,5));$out=ob_get_clean();return json_decode($out,true,512,JSON_THROW_ON_ERROR);}catch(Throwable$e){ob_end_clean();throw$e;}
};
$expect=static function(bool$ok,string$message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);++$checks;};
$next=static function()use(&$paths):string{$p='/tmp/import-proof-fixture-'.bin2hex(random_bytes(8)).'.json';$paths[]=$p;return$p;};
Db::execute('CREATE TEMPORARY TABLE eb_tmp_import_executor_fact ('.implode(',',array_map(static fn($k)=>'`'.$k.'` varchar(256) NULL',Proof::FIELDS)).') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
Db::execute(str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE',file_get_contents($dir.'/schema.sql')));
Db::execute('CREATE TEMPORARY TABLE codex_010_migration_batch (batch_key varchar(128) PRIMARY KEY,source_archive_sha256 char(64)) ENGINE=InnoDB');
try{
 Db::name('tmp_import_executor_fact')->insert($f);Db::table('codex_010_migration_batch')->insert(['batch_key'=>'fixture-batch','source_archive_sha256'=>str_repeat('a',64)]);
 $before=Db::query('SELECT * FROM eb_tmp_import_executor_fact');
 $r=$run('apply',$next());$expect($r['proof_rows_changed']===1,'insert one certified proof');
 $expect((int)Db::name('cashier_v3_import_fact_provenance')->count()===1,'proof persisted');
 $r=$run('apply',$next());$expect($r['proof_rows_changed']===0,'replay is idempotent');
 $expect(Db::query('SELECT * FROM eb_tmp_import_executor_fact')===$before,'business fact unchanged');
 Db::name('tmp_import_executor_fact')->where('fact_id','fixture-1')->update(['amount_cents'=>'196001']);
 $blocked=false;try{$run('apply',$next());}catch(Throwable$e){$blocked=strpos($e->getMessage(),'FACT_SNAPSHOT_CHANGED')!==false;}
 $expect($blocked,'drift prevents certification');
 Db::name('tmp_import_executor_fact')->where('fact_id','fixture-1')->update(['amount_cents'=>'196000']);
 $r=$run('rollback',$next());$expect($r['proof_rows_changed']===1,'rollback removes only proof');
 $expect((int)Db::name('cashier_v3_import_fact_provenance')->count()===0,'proof revoked');
 $blocked=false;try{$run('apply','/missing-fixture-directory/audit.json');}catch(Throwable$e){$blocked=true;}
 $expect($blocked,'audit failure detected');$expect((int)Db::name('cashier_v3_import_fact_provenance')->count()===0,'audit failure rolls back insert');
 $savedSource=$source;$source=str_replace("'/bin/sync -f '","'/bin/false '",$source);
 $blocked=false;try{$run('apply',$next());}catch(Throwable$e){$blocked=strpos($e->getMessage(),'AUDIT_FSYNC_FAILED')!==false;}
 $source=$savedSource;$expect($blocked,'fsync failure detected');
 $expect((int)Db::name('cashier_v3_import_fact_provenance')->count()===0,'fsync failure rolls back proof insert');
 $run('apply',$next());Db::name('cashier_v3_import_fact_provenance')->where('fact_id','fixture-1')->update(['snapshot_sha256'=>str_repeat('b',64)]);
 $blocked=false;try{$run('rollback',$next());}catch(Throwable$e){$blocked=strpos($e->getMessage(),'ROLLBACK_PROOF_CONFLICT')!==false;}
 $expect($blocked,'rollback refuses another changed proof');
 $expect(Db::query('SELECT * FROM eb_tmp_import_executor_fact')===$before,'all actions preserve business facts');
 echo 'imported-performance-type-executor PASS checks='.$checks.PHP_EOL;
}finally{
 foreach($paths as$p)if(file_exists($p))unlink($p);
 Db::execute('DROP TEMPORARY TABLE eb_tmp_import_executor_fact');Db::execute('DROP TEMPORARY TABLE eb_cashier_v3_import_fact_provenance');Db::execute('DROP TEMPORARY TABLE codex_010_migration_batch');
}
