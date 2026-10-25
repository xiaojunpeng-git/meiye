<?php
/** Read-only instance verification. Validate both contract branches without
 * toggling production configuration or writing debt/order/export records.
 */
$site=$argv[1]??'';$sites=['rh'=>['rh.cc3798.com','ruihao','3306'],'008'=>['008.cc3798.com','mohe_008','3307'],'010'=>['010.cc3798.com','mohe_010_app','3307']];if(!isset($sites[$site]))throw new RuntimeException('Explicit site');
[$domain,$database,$port]=$sites[$site];$root='/www/wwwroot/'.$domain;require $root.'/vendor/autoload.php';(new \think\App($root.'/'))->initialize();set_exception_handler(static function(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
$cfg=config('database.connections.mysql');if($cfg['database']!==$database||(string)$cfg['hostport']!==$port)throw new RuntimeException('Wrong DB');
// Catalog snapshot deliberately precedes the read-only transaction: the legacy
// reader adds FOR UPDATE if any transaction is open; this call performs SELECT only.
$nav=(new \app\services\organization\StorePermissionCatalogServices())->snapshot();$storeSetting=false;foreach($nav['rules']as $r)if((int)$r['id']===301063)$storeSetting=true;if(!$storeSetting)throw new RuntimeException('R59 setting entry lost');
$enabled=(int)sys_config('cashier_debt_pay_switch',0)===1;$graph=\app\services\query\UnifiedQueryRuntime::runtime();$checks=[];
foreach(['all','sales','recharge']as $type){$page='order_center_'.$type;$live=array_column($graph['registry']->fieldsForCapability($page,[]),'key');foreach([false,true]as $flag){$d=\app\services\cashier\v3\order\CashierV3OrderCenterUnifiedQueryContract::definition($page,true,$flag);$keys=array_column($d['fields'],'key');if(in_array($type,['all','sales'],true)&&in_array('debt_amount',$keys,true)!==$flag)throw new RuntimeException('Debt contract incorrect');if(!$flag&&in_array('debt_amount',$keys,true))throw new RuntimeException('Disabled field retained');}if(in_array('debt_amount',$live,true)!==($enabled&&in_array($type,['all','sales'],true)))throw new RuntimeException('Live registry ignores switch');$checks[]=$type.' live registry and enabled/disabled whitelist';}
\think\facade\Db::execute('SET SESSION TRANSACTION READ ONLY');\think\facade\Db::startTrans();try{
 $policy=(new \app\services\cashier\v3\config\CashierV3BusinessSourcePolicyServices())->read();if(!$policy['configured'])throw new RuntimeException('R57 source settings missing');$checks[]='existing source policy retained';
 $custom=new \app\services\cashier\v3\reservation\ReservationCustomServiceServices();if($custom->normalize([['id'=>1,'quantity'=>2]])!==[['id'=>1,'quantity'=>2]])throw new RuntimeException('Custom service normalization changed');$checks[]='R58 custom service contract retained';
 $store=(int)\think\facade\Db::name('system_store')->where('is_del',0)->order('id')->value('id');if($store){$profile=(new \app\services\store\StoreProfileSettingsServices())->read($store);if($profile['store_id']!==$store)throw new RuntimeException('R59 profile scope');}$checks[]='R59 current-store settings readable';
 echo json_encode(['result'=>'PASS','site'=>$site,'configuredDebtEnabled'=>$enabled,'checks'=>$checks,'businessWrites'=>0,'configChanged'=>false]),PHP_EOL;
}finally{\think\facade\Db::rollback();}
