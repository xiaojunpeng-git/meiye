<?php
/** Isolated Docker fixture; no customer data, local shared tables or Redis keys are modified. */
require '/var/www/html/vendor/autoload.php';
use think\facade\Db;
$app=new think\App('/var/www/html/');$app->initialize();
set_exception_handler(function($e){fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
$c=config('database.connections.mysql');
if(!is_file('/.dockerenv')||!in_array($c['hostname'],['mysql','mohe-mysql'],true))throw new RuntimeException('local only');
$admin=new PDO('mysql:host='.$c['hostname'].';port='.$c['hostport'].';dbname='.$c['database'],$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$schema='test_010_login_'.bin2hex(random_bytes(5));$prefix=$schema.':';
$admin->exec('CREATE DATABASE `'.$schema.'`');
try {
 foreach($admin->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $t)$admin->exec('CREATE TABLE `'.$schema.'`.`'.$t[0].'` LIKE `'.$c['database'].'`.`'.$t[0].'`');
 $dbc=config('database');$dbc['connections']['mysql']['database']=$schema;$dbc['connections']['mysql']['fields_cache']=false;
 $app->config->set($dbc,'database');
 $cache=config('cache');$cache['stores']['redis']['prefix']=$prefix;$cache['stores']['redis']['tag_prefix']=$prefix.'tag:';$app->config->set($cache,'cache');$app->instance('cache',new \think\Cache($app));
 putenv('LOGIN010_TEST=1');
 $insert=function($table,$values)use($admin,$schema){$row=[];foreach($admin->query('SHOW COLUMNS FROM `'.$schema.'`.`eb_'.$table.'`')->fetchAll(PDO::FETCH_ASSOC) as $col){$k=$col['Field'];if(array_key_exists($k,$values)){$row[$k]=$values[$k];continue;}if($col['Extra']==='auto_increment'||$col['Default']!==null||$col['Null']==='YES')continue;$row[$k]=preg_match('/int|decimal|float|double/',$col['Type'])?0:'';}return Db::name($table)->insertGetId(array_merge($row,$values));};

 foreach([1,2,3,4] as $id)$insert('employee',['id'=>$id,'name'=>'fixture','phone'=>sprintf('138%08d',$id),'status'=>1,'is_del'=>$id===4?1:0]);
 $insert('system_admin',['id'=>1,'account'=>'admin','status'=>1,'level'=>0,'employee_id'=>null]);
 $insert('system_admin',['id'=>2,'account'=>'old-fixture','status'=>0,'level'=>1,'employee_id'=>2]);
 $svc=app()->make(\app\services\employee\EmployeeInternalAccountServices::class);
 $svc->saveAccount(1,'old-account','original',1);
 $assert=function($ok,$name){if(!$ok)throw new RuntimeException($name);echo 'PASS '.$name."\n";};
 require '/var/www/html/database/upgrades/2026-10-23-渼约010测试导入/11-员工统一登录初始化.php';
 $ret=reset010EmployeeLogins();$assert($ret['processed']===3,'all_valid_employees_processed');
 foreach([1,2,3] as $id){$phone=sprintf('138%08d',$id);$a=$svc->authenticateByPassword('A'.$phone,substr($phone,-4));$assert((int)$a['employee_id']===$id,'actual_authentication_'.$id);}
 $assert($svc->getByEmployeeId(4)===null,'deleted_employee_not_enabled');
 $assert(Db::name('system_admin')->where('id',1)->value('account')==='admin','independent_admin_unchanged');
 $assert((int)Db::name('system_admin')->where('id',2)->value('status')===0,'disabled_admin_stays_disabled');
 $assert((int)$svc->getByEmployeeId(1)['credential_version']===2,'old_credential_version_invalidated');
 $assert((int)Db::name('staff_job_position')->count()===0,'no_new_job_permissions');
 $denied=false;try{reset010EmployeeLogins();}catch(Throwable $e){$denied=true;}$assert($denied,'repeat_rejected');
}finally{$admin->exec('DROP DATABASE `'.$schema.'`');}
