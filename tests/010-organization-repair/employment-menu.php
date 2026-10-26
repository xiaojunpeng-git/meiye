<?php
/** Isolated Docker fixture; no customer data, local shared tables or Redis keys are modified. */
require '/var/www/html/vendor/autoload.php';
use think\facade\Db;
$app=new think\App('/var/www/html/');$app->initialize();
set_exception_handler(function($e){fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
$c=config('database.connections.mysql');
if(!is_file('/.dockerenv')||!in_array($c['hostname'],['mysql','mohe-mysql'],true))throw new RuntimeException('local only');
$admin=new PDO('mysql:host='.$c['hostname'].';port='.$c['hostport'].';dbname='.$c['database'],$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$schema='test_010_menu_'.bin2hex(random_bytes(5));$prefix=$schema.':';
$admin->exec('CREATE DATABASE `'.$schema.'`');
try {
 foreach($admin->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $t)$admin->exec('CREATE TABLE `'.$schema.'`.`'.$t[0].'` LIKE `'.$c['database'].'`.`'.$t[0].'`');
 $dbc=config('database');$dbc['connections']['mysql']['database']=$schema;$dbc['connections']['mysql']['fields_cache']=false;
 $app->config->set($dbc,'database');
 $cache=config('cache');$cache['stores']['redis']['prefix']=$prefix;$cache['stores']['redis']['tag_prefix']=$prefix.'tag:';$app->config->set($cache,'cache');$app->instance('cache',new \think\Cache($app));
 putenv('MENU010_SYNTHETIC_TEST=1');
 $insert=function($table,$values)use($admin,$schema){$row=[];foreach($admin->query('SHOW COLUMNS FROM `'.$schema.'`.`eb_'.$table.'`')->fetchAll(PDO::FETCH_ASSOC) as $col){$k=$col['Field'];if(array_key_exists($k,$values)){$row[$k]=$values[$k];continue;}if($col['Extra']==='auto_increment'||$col['Default']!==null||$col['Null']==='YES')continue;$row[$k]=preg_match('/int|decimal|float|double/',$col['Type'])?0:'';}return Db::name($table)->insertGetId(array_merge($row,$values));};

 $insert('system_menus',['id'=>1829,'pid'=>0,'type'=>1,'menu_name'=>'人员维护','unique_auth'=>'setting-staff-index','is_del'=>0,'auth_type'=>1,'is_show'=>1]);
 require '/var/www/html/database/upgrades/2026-10-23-渼约010测试导入/09-人员类型管理权限补齐.php';
 $service=app()->make(\app\services\system\SystemMenusServices::class);
 $assert=function($ok,$name){if(!$ok)throw new RuntimeException($name);echo 'PASS '.$name."\n";};
 $before=$service->getMenusList('1',0,1,0)[1];
 $assert(!in_array('setting-staff-employment-type',$before,true),'missing_permission_reproduced');
 $id=repair010EmploymentTypeMenu();
 $after=$service->getMenusList('1',0,1,0)[1];
 $assert(in_array('setting-staff-employment-type',$after,true),'superadmin_receives_permission');
 $assert(!in_array('setting-staff-employment-type',$service->getMenusList('1',1,1,0)[1],true),'ordinary_without_role_stays_denied');
 $assert(count(array_diff($after,$before))===1,'only_one_permission_added');
 $assert((int)Db::name('system_role')->count()===0,'roles_unchanged');
 $rejected=false;try{repair010EmploymentTypeMenu();}catch(Throwable $e){$rejected=true;}$assert($rejected&&(int)Db::name('system_menus')->count()===2,'repeat_rejected_without_duplicate');
}finally{$admin->exec('DROP DATABASE `'.$schema.'`');}
