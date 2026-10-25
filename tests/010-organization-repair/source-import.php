<?php
/** Isolated Docker fixture; no customer data, local shared tables or Redis keys are modified. */
require '/var/www/html/vendor/autoload.php';
use think\facade\Db;
$app=new think\App('/var/www/html/');$app->initialize();
set_exception_handler(function($e){fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
$c=config('database.connections.mysql');
if(!is_file('/.dockerenv')||!in_array($c['hostname'],['mysql','mohe-mysql'],true))throw new RuntimeException('local only');
$admin=new PDO('mysql:host='.$c['hostname'].';port='.$c['hostport'].';dbname='.$c['database'],$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$schema='test_010_source_'.bin2hex(random_bytes(5));$prefix=$schema.':';
$admin->exec('CREATE DATABASE `'.$schema.'`');
try {
 foreach($admin->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $t)$admin->exec('CREATE TABLE `'.$schema.'`.`'.$t[0].'` LIKE `'.$c['database'].'`.`'.$t[0].'`');
 $dbc=config('database');$dbc['connections']['mysql']['database']=$schema;$dbc['connections']['mysql']['fields_cache']=false;
 $app->config->set($dbc,'database');
 $cache=config('cache');$cache['stores']['redis']['prefix']=$prefix;$cache['stores']['redis']['tag_prefix']=$prefix.'tag:';$app->config->set($cache,'cache');$app->instance('cache',new \think\Cache($app));
 putenv('SOURCE010_TEST=1');
 $insert=function($table,$values)use($admin,$schema){$row=[];foreach($admin->query('SHOW COLUMNS FROM `'.$schema.'`.`eb_'.$table.'`')->fetchAll(PDO::FETCH_ASSOC) as $col){$k=$col['Field'];if(array_key_exists($k,$values)){$row[$k]=$values[$k];continue;}if($col['Extra']==='auto_increment'||$col['Default']!==null||$col['Null']==='YES')continue;$row[$k]=preg_match('/int|decimal|float|double/',$col['Type'])?0:'';}return Db::name($table)->insertGetId(array_merge($row,$values));};

 require '/var/www/html/database/upgrades/2026-10-23-渼约010测试导入/12-承接008来源字典.php';
 $assert=function($ok,$name){if(!$ok)throw new RuntimeException($name);echo 'PASS '.$name."\n";};
 $assert(import010SourcesFrom008()===17,'seventeen_sources_imported');
 $svc=new \app\services\cashier\v3\config\CashierV3BusinessConfigServices();
 $catalog=$svc->checkoutCatalog();$assert(count($catalog['sources'])===8,'eight_checkout_roots');
 $assert(count($svc->sourceTree(false))===11,'eleven_roots_including_disabled_and_refund');
 foreach([[4,14,'抖音'],[5,12,'合作方'],[8,17,'睡眠激活']] as $r){$v=$svc->resolveSourceSnapshot($r[0],$r[1],false);$assert($v['secondarySourceNameSnapshot']===$r[2],'secondary_selection_'.$r[1]);}
 $blocked=false;try{$svc->assertCheckoutSourceAllowed(9);}catch(Throwable $e){$blocked=true;}$assert($blocked,'refund_source_excluded');
 $assert((int)Db::name('cashier_v3_business_source')->where('status',0)->count()===2,'disabled_states_preserved');
 $assert((int)Db::name('cashier_v3_business_source')->where('id',1)->value('is_fixed')===1,'fixed_source_preserved');
 $blocked=false;try{import010SourcesFrom008();}catch(Throwable $e){$blocked=true;}$assert($blocked,'repeat_rejected');
}finally{$admin->exec('DROP DATABASE `'.$schema.'`');}
