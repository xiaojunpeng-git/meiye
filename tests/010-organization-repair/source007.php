<?php
require '/var/www/html/vendor/autoload.php';$app=new think\App('/var/www/html/');$app->initialize();$c=config('database.connections.mysql');
if(!is_file('/.dockerenv')||!in_array($c['hostname'],['mysql','mohe-mysql'],true))throw new RuntimeException('local only');
$p=new PDO('mysql:host='.$c['hostname'].';port='.$c['hostport'].';dbname='.$c['database'],$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db='test_010_source_'.bin2hex(random_bytes(4));$a=function($v,$n){if(!$v)throw new RuntimeException($n);echo "PASS $n\n";};
try{
$p->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4");$p->exec("CREATE TABLE `$db`.eb_cashier_v3_business_source LIKE `{$c['database']}`.eb_cashier_v3_business_source");
$cfg=config('database');$cfg['connections']['mysql']['database']=$db;$app->config->set($cfg,'database');putenv('SOURCE010_TEST=1');
require '/tmp/010-source008.php';require '/tmp/010-source007.php';import010SourcesFrom008();
$before=think\facade\Db::name('cashier_v3_business_source')->order('id')->select()->toArray();$map=import010SourcesFrom007();
$a(count($map)===12,'all_12_source007_values');$a(count(array_filter($map,function($r){return $r['status']===1;}))===7,'seven_active_five_disabled');
$after=think\facade\Db::name('cashier_v3_business_source')->where('id','<=',17)->order('id')->select()->toArray();
foreach($after as $i=>$r){$a($r['name']===$before[$i]['name']&&(int)$r['parent_id']===(int)$before[$i]['parent_id']&&(int)$r['status']===0,'preserve_old_id_'.$r['id']);}
$s=app()->make(app\services\cashier\v3\config\CashierV3BusinessConfigServices::class);
foreach($map as $r){$a($r['target010Id']>17,'new_id_'.$r['source007Id']);if($r['status'])$a($s->resolveSourceSnapshot($r['target010Id'])['primarySourceNameSnapshot']===$r['name'],'resolve_'.$r['source007Id']);}
$a(think\facade\Db::table('codex_010_source007_audit')->count()===1,'audit_saved');
$all=think\facade\Db::name('cashier_v3_business_source')->order('id')->select()->toArray();try{import010SourcesFrom007();throw new RuntimeException('expected reject');}catch(RuntimeException $e){$a($e->getMessage()==='ALREADY_APPLIED','repeat_rejected');}
$a($all===think\facade\Db::name('cashier_v3_business_source')->order('id')->select()->toArray(),'repeat_unchanged');
}finally{$p->exec("DROP DATABASE IF EXISTS `$db`");}
