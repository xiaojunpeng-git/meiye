<?php
/** Read-only production smoke: no fabricated users, payment requests, refunds or configuration changes. */
$site=$argv[1]??'';$dbs=['rh'=>'ruihao','008'=>'mohe_008','010'=>'mohe_010_app'];if(!isset($dbs[$site]))throw new RuntimeException('Explicit site');
$root='/www/wwwroot/'.($site==='rh'?'rh.cc3798.com':$site.'.cc3798.com');require $root.'/vendor/autoload.php';(new think\App($root.'/'))->initialize();
set_exception_handler(static function(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
if(config('database.connections.mysql.database')!==$dbs[$site])throw new RuntimeException('Database scope');
think\facade\Db::execute('SET SESSION TRANSACTION READ ONLY');think\facade\Db::startTrans();try{
 foreach(['CombinationFulfillmentServices','CombinationRefundServices','CombinationGroupManagementServices','CombinationRecordServices']as $class)if(!class_exists('app\\services\\activity\\combination\\'.$class))throw new RuntimeException('Missing domain class');
 if(!class_exists('app\\services\\pay\\FuyouCombinationRefundGateway')||!class_exists('app\\jobs\\activity\\pink\\CombinationRecoveryJob'))throw new RuntimeException('Missing gateway/recovery');
 $id=(int)think\facade\Db::name('store_pink')->where('k_id',0)->where('uid','>',0)->order('id','desc')->value('id');
 $rows=0;if($id){$export=(new app\services\activity\combination\CombinationRecordServices())->export($id);if(count($export['header'])!==16||count($export['filekey'])!==16)throw new RuntimeException('Export contract');$rows=count($export['export']);}
 $controller=new ReflectionClass(app\controller\admin\v1\marketing\combination\StoreCombination::class);foreach(['completeGroup','refundParticipant','exportGroup']as $method)if(!$controller->hasMethod($method))throw new RuntimeException('Missing endpoint');
 foreach(['combination_group_origin','combination_force_completion','combination_payment_origin','combination_order_fulfillment','combination_order_refund']as $t)think\facade\Db::query('SHOW COLUMNS FROM eb_'.$t);
 echo json_encode(['result'=>'PASS','site'=>$site,'exportColumns'=>16,'existingGroupExportRows'=>$rows,'domainAndRecoveryLoadable'=>true,'businessWrites'=>0,'providerRequests'=>0]),PHP_EOL;
}finally{think\facade\Db::rollback();}
