<?php
/** Local transaction-only integration: switch persistence, authoritative action classification, no bypass or fact drift. */
require '/var/www/html/vendor/autoload.php';(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
use app\services\order\PlatformDocumentEditPolicy as Policy;
use app\services\cashier\v3\manifest\CashierV3ActionManifest as Manifest;
use app\services\order\PlatformOrderCenterActionServices;
use app\services\order\PlatformOrderCenterReadServices;
set_exception_handler(static function(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
if(config('database.connections.mysql.hostname')!=='mysql'||config('database.connections.mysql.database')!=='ruihao')throw new RuntimeException('LOCAL_ONLY');
function ok($v,$m){if(!$v)throw new RuntimeException($m);echo "PASS $m\n";}
function toggle($v){Db::name('system_config')->where('menu_name',Policy::CONFIG_KEY)->where('is_store',0)->update(['value'=>json_encode($v)]);}
$files=[];Db::startTrans();try {
 Db::name('system_config')->where('menu_name',Policy::CONFIG_KEY)->where('is_store',0)->delete();ok(Policy::enabled(),'missing definition defaults enabled');
 $sql=file_get_contents('/var/www/html/database/upgrades/20261106_platform_document_edit_switch.sql');Db::execute($sql);ok(Policy::enabled(),'migration default enabled');toggle(0);Db::execute($sql);ok(!Policy::enabled(),'rerun preserves disabled value');ok(!Policy::enabled(true),'transaction reads authoritative disabled value');
 $n=0;foreach(Manifest::all()as$action=>$d){$allowed=$d['type']===Manifest::TYPE_PROJECTION||in_array($d['canonical'],['create-unified-query-export','print-sales-order-receipt'],true);$denied=false;try{Policy::assertAllowed($action);}catch(app\services\cashier\v3\CashierV3CommandException $e){$denied=$e->getResultCode()===Policy::DENIED_CODE;}ok($denied===!$allowed,'manifest '.$action);$n++;}
 $admin=Db::name('system_admin')->where('status',1)->where('is_del',0)->where('level',0)->where('admin_type',0)->find();$svc=new PlatformOrderCenterActionServices();
 $invoke=function($a,$p=[])use($svc,$admin){return $svc->dispatch((int)$admin['id'],$p+['action'=>$a,'clientSessionId'=>'SESSION-aec0ea15-9116-4e12-9a12-a8ea3b3c7f81','correlationId'=>'CORR-data-r5']);};
 $reader=app()->make(PlatformOrderCenterReadServices::class);ok($reader->metadata($admin)['readOnly']===true,'metadata closed');ok($reader->scope($admin,app()->make(app\services\organization\OrganizationScopeService::class))['document_edit_enabled']===false,'scope exposes same policy');
 $cap=$invoke('query-unified-query-capabilities',['pageCode'=>'order_center_sales']);ok($cap['result']['status']==='success','query capabilities remain available');
 $list=$invoke('query-sales-orders',['businessDateFrom'=>'2026-11-01','businessDateTo'=>'2026-11-06','pageSize'=>20]);ok($list['result']['status']==='success','list remains readable');
 $order=Db::name('cashier_v3_sales_order')->where('order_status','settled')->find();ok((bool)$order,'existing local order');
 $detail=$invoke('open-sales-order-detail',['orderId'=>$order['order_id']]);ok($detail['result']['status']==='success','detail remains readable');$contexts=[];foreach($detail['versions']as$v)if($v['kind']==='sales_order')$contexts[]=['kind'=>$v['kind'],'id'=>$v['id'],'expectedVersion'=>$v['version']];
 $command=['orderId'=>$order['order_id'],'orderNote'=>(string)($order['note']??''),'command'=>['action'=>'update-sales-order-note','idempotencyKey'=>'CMD-aec0ea15-9116-4e12-9a12-a8ea3b3c7f82','contexts'=>$contexts]];
 $before=Db::name('cashier_v3_sales_order')->where('order_id',$order['order_id'])->find();$denied=$invoke('update-sales-order-note',$command);ok($denied['result']['code']===Policy::DENIED_CODE,'real direct command rejected');ok(Db::name('cashier_v3_sales_order')->where('order_id',$order['order_id'])->find()===$before,'denial leaves order unchanged');
 toggle(1);ok(Policy::enabled()&&$reader->metadata($admin)['readOnly']===false,'reopen readback');$saved=$invoke('update-sales-order-note',$command);ok($saved['result']['status']==='success','enabled real command succeeds');toggle(0);$replay=$invoke('update-sales-order-note',$command);ok($replay['result']['code']===Policy::DENIED_CODE,'stale successful command cannot bypass closure');
 foreach($cap['versions']as$v)if($v['kind']==='query_preference')$version=$v['version'];
 $export=$invoke('create-unified-query-export',['pageCode'=>'order_center_sales','scope'=>'page','query'=>['page'=>1,'pageSize'=>20],'fields'=>['sales_order_no','business_date'],'includeSummary'=>false,'fileName'=>'data-r5.xlsx','command'=>['action'=>'create-unified-query-export','idempotencyKey'=>'CMD-aec0ea15-9116-4e12-9a12-a8ea3b3c7f83','contexts'=>[['kind'=>'query_preference','id'=>'order_center_sales','expectedVersion'=>$version]]]]);ok($export['result']['status']==='success','export command allowed while closed');
 $task=$export['data']['exportTask']['taskId'];$runtime=app\services\query\UnifiedQueryRuntime::runtime();$worker=new app\services\query\UnifiedQueryExportWorkerServices($runtime['exports'],$runtime['providers'],$runtime['workerContextResolvers']);$done=$worker->processOne($task);ok($done['status']==='succeeded','actual XLSX generated while closed');[,, $context]=$reader->exportScopes((int)$admin['id'],'order_center_sales');$descriptor=$runtime['exports']->resolveDownloadDescriptor($context,$task);$path=app\services\cashier\v3\query\UnifiedQueryModule::runtime()['exportStorage']->absolutePath($descriptor['storageKey']);$files[]=$path;ok(substr(file_get_contents($path),0,2)==='PK','export downloadable XLSX');
 echo "MANIFEST_ACTIONS $n\n";
}finally{Db::rollback();foreach($files as$f)if(is_file($f))unlink($f);}
