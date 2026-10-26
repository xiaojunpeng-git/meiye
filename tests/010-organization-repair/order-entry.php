<?php
require '/var/www/html/vendor/autoload.php';$app=new think\App('/var/www/html/');$app->initialize();$c=config('database.connections.mysql');
if(!is_file('/.dockerenv')||!in_array($c['hostname'],['mysql','mohe-mysql'],true))throw new RuntimeException('local only');
$p=new PDO('mysql:host='.$c['hostname'].';port='.$c['hostport'].';dbname='.$c['database'],$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db='test_010_source_'.bin2hex(random_bytes(4));$a=function($v,$n){if(!$v)throw new RuntimeException($n);echo "PASS $n\n";};
try{$p->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4");foreach(['store_v3_permission_catalog','store_v3_permission_audit'] as $t)$p->exec("CREATE TABLE `$db`.eb_$t LIKE `{$c['database']}`.eb_$t");$cfg=config('database');$cfg['connections']['mysql']['database']=$db;$app->config->set($cfg,'database');putenv('SOURCE010_TEST=1');require '/tmp/010-order-entry.php';
$rows=json_decode(file_get_contents('/tmp/010-catalog.json'),true);
// 本地正本较现网新增目录定义，测试夹具补齐本地定义；线上迁移不增加任何节点。
$known=array_column($rows,null,'id');foreach(array_merge(array_values(app\services\organization\StorePermissionCatalogServices::definitions()),array_values(app\services\organization\StorePermissionCatalogServices::navigationGroups()),array_values(app\services\organization\StorePermissionCatalogServices::navigationItems())) as $def)if(!isset($known[$def['id']])){$def['enabled']=1;$rows[]=$def;}
think\facade\Db::name('store_v3_permission_catalog')->insert(['id'=>1,'rules_json'=>json_encode($rows),'revision'=>1,'updated_at'=>time()]);
$s=app()->make(app\services\organization\StorePermissionCatalogServices::class);$a($s->filterEnabled(['cashier.v3.order_center','cashier.v3.order.void'])===[],'disabled_group_blocks_order_features');
enable010OrderEntry();$after=json_decode(think\facade\Db::name('store_v3_permission_catalog')->where('id',1)->value('rules_json'),true);
$changes=[];foreach($rows as $i=>$r)if($r!==$after[$i])$changes[]=$r['id'];$a($changes===[-2806],'only_order_group_changed');
$a(count($s->filterEnabled(['cashier.v3.order_center','cashier.v3.order.void','cashier.v3.order.service_void']))===3,'existing_order_features_unblocked');
$a($s->filterEnabled([])===[],'no_grants_created');$a(think\facade\Db::name('store_v3_permission_audit')->count()===1,'audit_recorded');
try{enable010OrderEntry();throw new RuntimeException('expected reject');}catch(RuntimeException $e){$a($e->getMessage()==='BASELINE_CHANGED','repeat_rejected');}
}finally{$p->exec("DROP DATABASE IF EXISTS `$db`");}
