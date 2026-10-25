<?php
// 仅在010隔离演练库验证历史销售读取，不写表、不进入线上服务进程。
$root='/www/wwwroot/010.cc3798.com/';
require $root.'vendor/autoload.php';
require __DIR__.'/CashierV3OrderCenterRecordQueryServices.php';
$app=new think\App($root);$app->env->load($root.'.env');$app->initialize();
$c=parse_ini_file('/root/.mysql57-008.cnf',true,INI_SCANNER_RAW)['client'];
$cfg=config('database');
$cfg['connections']['mysql']=array_replace($cfg['connections']['mysql'],['database'=>'mohe_010_migration_test_refresh','hostname'=>'127.0.0.1','hostport'=>'3307','username'=>$c['user']??'root','password'=>$c['password']]);
think\facade\Config::set($cfg,'database');
if(think\facade\Db::query('SELECT DATABASE() db')[0]['db']!=='mohe_010_migration_test_refresh')throw new RuntimeException('isolation');
think\facade\Db::execute('SET SESSION MAX_EXECUTION_TIME=8000');
think\facade\Db::execute('SET TRANSACTION READ ONLY');think\facade\Db::startTrans();
try {
$service=new app\services\cashier\v3\order\CashierV3OrderCenterRecordQueryServices();
$m=new ReflectionMethod($service,'readLegacyCardUpgrades');$m->setAccessible(true);
$c=['allowedStoreIds'=>null,'businessDateFrom'=>'2026-01-01','businessDateTo'=>'2026-12-31','keyword'=>'','status'=>'','dataScope'=>'normal','operationType'=>'','page'=>1,'pageSize'=>100];
[$rows,$total]=$m->invoke($service,$c);$by=array_column($rows,null,'id');
$r=$by['legacy-card-upgrade-target:52998']??null;
if(!$r||$r['operationAmount']!=='1700.00'||$r['businessDate']!=='2026-05-15')throw new RuntimeException('wrong upgrade amount/date');
$c['allowedStoreIds']=[2];[$oldStore]=$m->invoke($service,$c);
if(in_array($r['id'],array_column($oldStore,'id'),true))throw new RuntimeException('original store leak');
$c['allowedStoreIds']=[11];[$actualStore]=$m->invoke($service,$c);
if(!in_array($r['id'],array_column($actualStore,'id'),true))throw new RuntimeException('actual store missing');
$c['businessDateFrom']='2026-10-01';$c['businessDateTo']='2026-10-31';[$oct]=$m->invoke($service,$c);
if(in_array($r['id'],array_column($oct,'id'),true))throw new RuntimeException('wrong period');
if(count(array_unique(array_column($rows,'id')))!==count($rows))throw new RuntimeException('duplicates');
echo json_encode(['status'=>'PASS','total'=>$total,'checks'=>['target-date','actual-upgrade-difference','target-store-scope','period','unique-target-event']],JSON_UNESCAPED_UNICODE),PHP_EOL;
}finally{think\facade\Db::rollback();}
