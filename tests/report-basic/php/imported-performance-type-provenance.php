<?php
declare(strict_types=1);
/** Actual local MySQL proof matching, isolation, drift and null/UTF-8 hashing. */
$root=getenv('BACKEND_ROOT')?:dirname(__DIR__,3).'/后端代码';
require $root.'/vendor/autoload.php';(new think\App($root.'/'))->initialize();
use app\services\report\ImportedPerformanceTypeProvenanceServices as Proof;
use think\facade\Db;
if(config('database.connections.mysql.hostname')!=='mysql')throw new RuntimeException('LOCAL_FIXTURE_ONLY');
$fields=Proof::FIELDS;$f=array_fill_keys($fields,'');
$f=array_replace($f,['tenant_id'=>'0','fact_id'=>'proof-fixture','employee_id'=>'12','employee_type_snapshot'=>'partner','employee_type_authority_version'=>'0','performance_type'=>'sales_performance_allocated','store_id'=>'4','amount_cents'=>'196000','business_date'=>'2026-10-01','status'=>'effective','fact_direction'=>'forward','rule_name_snapshot'=>'not-in-proof']);
unset($f['rule_name_snapshot']);$f['order_id']='中文:|order';$f['reversal_of']=null;
Db::execute('CREATE TEMPORARY TABLE tmp_import_fact ('.implode(',',array_map(static fn($k)=>'`'.$k.'` varchar(256) NULL',$fields)).') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
Db::execute('CREATE TEMPORARY TABLE tmp_import_proof (tenant_id varchar(64),fact_id varchar(128),purpose varchar(40),snapshot_sha256 char(64),PRIMARY KEY(tenant_id,fact_id,purpose)) ENGINE=InnoDB');
try{
 Db::table('tmp_import_fact')->insert($f);
 $sqlHash=Db::query('SELECT '.Proof::fingerprintSql('pf').' proof_hash FROM tmp_import_fact pf')[0]['proof_hash'];
 if($sqlHash!==Proof::fingerprint($f))throw new RuntimeException('PHP_SQL_UTF8_NULL_HASH_MISMATCH');
 $method=new ReflectionMethod(Proof::class,'unverifiedPredicate');$method->setAccessible(true);
 $predicate=$method->invoke(null,'pf','tmp_import_proof');
 $unknown=static fn()=> (int)Db::query('SELECT IF('.$predicate.',1,0) unknown_value FROM tmp_import_fact pf')[0]['unknown_value'];
 if($unknown()!==1)throw new RuntimeException('MISSING_PROOF_ACCEPTED');
 $proof=['tenant_id'=>'0','fact_id'=>$f['fact_id'],'purpose'=>Proof::PURPOSE,'snapshot_sha256'=>Proof::fingerprint($f)];
 Db::table('tmp_import_proof')->insert(array_replace($proof,['tenant_id'=>'other']));
 if($unknown()!==1)throw new RuntimeException('CROSS_TENANT_PROOF_ACCEPTED');
 Db::table('tmp_import_proof')->insert($proof);
 if($unknown()!==0)throw new RuntimeException('CERTIFIED_IMPORT_REJECTED');
 foreach(['amount_cents'=>'196001','employee_id'=>'13','business_date'=>'2026-10-02','employee_type_snapshot'=>'internal','order_id'=>'other']as$key=>$value){
  Db::table('tmp_import_fact')->where('fact_id',$f['fact_id'])->update([$key=>$value]);
  if($unknown()!==1)throw new RuntimeException('MUTATED_FACT_ACCEPTED:'.$key);
  Db::table('tmp_import_fact')->where('fact_id',$f['fact_id'])->update([$key=>$f[$key]]);
 }
 Db::table('tmp_import_proof')->where('tenant_id','0')->delete();
 if($unknown()!==1)throw new RuntimeException('ROLLBACK_PROOF_STILL_ACTIVE');
 Db::table('tmp_import_fact')->where('fact_id',$f['fact_id'])->update(['employee_type_authority_version'=>'3']);
 if($unknown()!==0)throw new RuntimeException('NATIVE_VERSION_REGRESSED');
 $blocked=false;try{Proof::fingerprintSql('pf;DROP');}catch(InvalidArgumentException$e){$blocked=true;}
 if(!$blocked)throw new RuntimeException('ALIAS_INJECTION');
 echo "imported-performance-type-provenance PASS\n";
}finally{Db::execute('DROP TEMPORARY TABLE tmp_import_fact');Db::execute('DROP TEMPORARY TABLE tmp_import_proof');}
