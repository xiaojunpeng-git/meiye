<?php
/** 本地实库：平台共用查询、导出、业务命令；所有业务写入在事务中回滚。 */
$root=getenv('R65_LOCAL_BACKEND') ?: dirname(__DIR__,2).'/后端代码';
require $root.'/vendor/autoload.php'; (new think\App($root.'/'))->initialize();
use think\facade\Db;
use app\services\order\PlatformOrderCenterActionServices;
use app\services\order\PlatformOrderCenterReadServices;
use app\services\cashier\v3\query\UnifiedQueryModule;
use app\services\query\UnifiedQueryRuntime;
if (Db::query('SELECT DATABASE() db')[0]['db']!=='ruihao'
 || !in_array(config('database.connections.mysql.hostname'),['mysql','mohe-mysql','127.0.0.1','localhost'],true)) throw new RuntimeException('local database only');
function check($v,$msg){ if(!$v)throw new RuntimeException($msg); echo 'PASS '.$msg."\n"; }
$admin=Db::name('system_admin')->where('status',1)->where('is_del',0)->where('level',0)->find();
$svc=app()->make(PlatformOrderCenterActionServices::class);
$base=['clientSessionId'=>'SESSION-9ec0ea15-9116-4e12-9a12-a8ea3b3c7f81','correlationId'=>'CORR-r65'];
$invoke=function($action,$payload=[])use($svc,$admin,$base){return $svc->dispatch((int)$admin['id'],$payload+$base+['action'=>$action]);};
$files=[];
Db::startTrans();
try {
 $cap=$invoke('query-unified-query-capabilities',['pageCode'=>'order_center_recharge']);
 check(($cap['result']['status']??'')==='success','shared query capability');
 check(($cap['data']['unifiedQueryCapability']['permissions']['export']??false)===true,'query access includes export');
 $version=$cap['versions'][0]['version'];
 $query=['recordType'=>'recharge','businessDateFrom'=>'2026-10-01','businessDateTo'=>'2026-10-31','page'=>1,'pageSize'=>20];
 $list=$invoke('query-order-center-records',$query);
 check(($list['result']['status']??'')==='success','platform October recharge list via shared handler');
 // 用现有订单原备注提交同一业务命令，再读取；外层事务回滚，不留下测试修改。
 $sales=$invoke('query-sales-orders',['businessDateFrom'=>'2026-10-01','businessDateTo'=>'2026-10-31','page'=>1,'pageSize'=>20]);
 check(($sales['result']['status']??'')==='success','shared sales list');
 $order=Db::name('cashier_v3_sales_order')->where('order_status','settled')->where('store_id','<>',1)->find();
 if ($order) {
  $detail=$invoke('open-sales-order-detail',['orderId'=>$order['order_id']]);
  check(($detail['result']['status']??'')==='success','cross-store detail through real order scope');
  $ctx=[];foreach(($detail['versions']??[]) as $v)if($v['kind']==='sales_order')$ctx[]=['kind'=>$v['kind'],'id'=>$v['id'],'expectedVersion'=>$v['version']];
  $note=$invoke('update-sales-order-note',['orderId'=>$order['order_id'],'orderNote'=>(string)($order['note']??''),
   'command'=>['action'=>'update-sales-order-note','idempotencyKey'=>'CMD-9ec0ea15-9116-4e12-9a12-a8ea3b3c7f83','contexts'=>$ctx]]);
  check(($note['result']['status']??'')==='success','real order write through shared transaction: '.json_encode($note['result'],JSON_UNESCAPED_UNICODE));
 }
 $key='CMD-9ec0ea15-9116-4e12-9a12-a8ea3b3c7f82';
 $payload=['pageCode'=>'order_center_recharge','scope'=>'page','query'=>['page'=>1,'pageSize'=>20,'topFilters'=>[['field'=>'business_date','operator'=>'gte','value'=>'2026-10-01'],['field'=>'business_date','operator'=>'lte','value'=>'2026-10-31']]],'fields'=>['recharge_order_no','member_name','recharge_amount'],'includeSummary'=>false,'fileName'=>'r65-local.xlsx',
  'command'=>['action'=>'create-unified-query-export','idempotencyKey'=>$key,'contexts'=>[['kind'=>'query_preference','id'=>'order_center_recharge','expectedVersion'=>$version]]]];
 $export=$invoke('create-unified-query-export',$payload);
 check(($export['result']['status']??'')==='success','platform creates same export command: '.json_encode($export['result'],JSON_UNESCAPED_UNICODE));
 $task=$export['data']['exportTask']['taskId'];
 $replay=$invoke('create-unified-query-export',$payload);
 check(($replay['data']['exportTask']['taskId']??'')===$task && !empty($replay['replay']),'same request replays one task');
 $runtime=UnifiedQueryRuntime::runtime();
 $worker=new app\services\query\UnifiedQueryExportWorkerServices($runtime['exports'],$runtime['providers'],$runtime['workerContextResolvers']);
 $done=$worker->processOne($task);
 check(($done['status']??'')==='succeeded','worker generates actual XLSX: '.json_encode($done,JSON_UNESCAPED_UNICODE));
 $status=$invoke('query-unified-query-export-task',['pageCode'=>'order_center_recharge','taskId'=>$task]);
 check(strpos($status['data']['exportTask']['downloadUrl']??'','/adminapi/store/order-center/exports/')===0,'download stays in admin session');
 [,, $context]=app()->make(PlatformOrderCenterReadServices::class)->exportScopes((int)$admin['id'],'order_center_recharge');
 $d=$runtime['exports']->resolveDownloadDescriptor($context,$task);
 $path=UnifiedQueryModule::runtime()['exportStorage']->absolutePath($d['storageKey']); $files[]=$path;
 check(is_file($path) && filesize($path)>0,'download descriptor points to generated file');
 $storeContext=$context; $storeContext['permission_version']='roles:same-numeric-id';
 $denied=false;try{$runtime['exports']->findForAccount($storeContext,$task);}catch(Throwable $e){$denied=true;}
 check($denied,'same numeric staff ID cannot read administrator export');
 $denied=false;try{$runtime['exports']->resolveDownloadDescriptor($storeContext,$task);}catch(Throwable $e){$denied=true;}
 check($denied,'same numeric staff ID cannot download administrator export');
 Db::name('system_admin')->where('id',$admin['id'])->update(['status'=>0]);
 $disabled=$invoke('query-unified-query-capabilities',['pageCode'=>'order_center_recharge']);
 check(($disabled['result']['status']??'')==='failed','disabled login remains rejected');
}finally{Db::rollback();foreach($files as $path)if(is_file($path))unlink($path);}
