<?php
/** Local transaction rollback: real settings endpoint, shared transaction guard and unaffected store command. */
require '/var/www/html/vendor/autoload.php';(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;use app\services\order\PlatformDocumentEditPolicy as Policy;
use app\services\order\PlatformOrderCenterReadServices as Reader;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
set_exception_handler(static function(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
if(config('database.connections.mysql.hostname')!=='mysql'||config('database.connections.mysql.database')!=='ruihao')throw new RuntimeException('LOCAL_ONLY');
function ok($v,$s){if(!$v)throw new RuntimeException($s);echo "PASS $s\n";}
Db::startTrans();try{
 Db::execute(file_get_contents('/var/www/html/database/upgrades/20261106_platform_document_edit_switch.sql'));
 $admin=Db::name('system_admin')->where('level',0)->where('is_del',0)->where('status',1)->where('admin_type',0)->find();$req=app('request');$req->adminId=(int)$admin['id'];$req->adminInfo=$admin;
 $settings=new app\controller\admin\v1\system\config\SystemConfig(new think\facade\App(),app()->make(app\services\system\config\SystemConfigServices::class));
 foreach([0,1,'0','1',0]as$v){$req->withPost([Policy::CONFIG_KEY=>$v]);$r=$settings->save_basics($req)->getData();ok($r['status']===200&&Policy::enabled()===((int)$v===1),'actual save and readback '.$v);}
 $req->withPost([Policy::CONFIG_KEY=>2]);$r=$settings->save_basics($req)->getData();ok($r['status']!==200&&!Policy::enabled(),'invalid option rejected without change');
 $staff=null;$resolver=new app\services\cashier\v3\permission\CashierV3FeatureResolver();
 foreach(Db::name('system_store_staff')->where('status',1)->where('is_del',0)->where('employee_id','>',0)->whereIn('store_id',function($q){$q->name('cashier_v3_sales_order')->where('order_status','settled')->field('store_id');})->select()->toArray()as$candidate){if(in_array('cashier.v3.order_center',$resolver->resolveGrantedFeatures($candidate),true)){$staff=$candidate;break;}}
 ok((bool)$staff,'existing authorized store identity');
 $order=Db::name('cashier_v3_sales_order')->where('order_status','settled')->where('store_id',$staff['store_id'])->find();$d=CashierV3Bootstrap::dispatcher();$session=['store_id'=>(int)$staff['store_id'],'operator_id'=>(int)$staff['id'],'operator_profile'=>$staff,'client_session_id'=>'SESSION-bec0ea15-9116-4e12-9a12-a8ea3b3c7f81'];
 $detail=$d->dispatch(['action'=>'open-sales-order-detail','orderId'=>$order['order_id']],$session);ok($detail['result']['status']==='success','store detail same order remains readable');$contexts=[];foreach($detail['versions']as$v)if($v['kind']==='sales_order')$contexts[]=['kind'=>$v['kind'],'id'=>$v['id'],'expectedVersion'=>$v['version']];
 $body=['action'=>'update-sales-order-note','orderId'=>$order['order_id'],'orderNote'=>'DATA-R5 rollback only','command'=>['action'=>'update-sales-order-note','idempotencyKey'=>'CMD-bec0ea15-9116-4e12-9a12-a8ea3b3c7f82','contexts'=>$contexts]];
 $saved=$d->dispatch($body,$session);ok($saved['result']['status']==='success','store real write unaffected while platform disabled');
 [$op,$scope]=app()->make(Reader::class)->trustedScopes((int)$admin['id'],(int)$staff['store_id']);$platform=['store_id'=>$op->storeId(),'operator_id'=>(int)$admin['id'],'operator_profile'=>$scope->operatorProfile(),'client_session_id'=>'SESSION-cec0ea15-9116-4e12-9a12-a8ea3b3c7f81'];
 $detail=$d->dispatch(['action'=>'open-sales-order-detail','orderId'=>$order['order_id']],$platform);foreach($detail['versions']as$v)if($v['kind']==='sales_order')$body['command']['contexts']=[['kind'=>$v['kind'],'id'=>$v['id'],'expectedVersion'=>$v['version']]];$body['command']['idempotencyKey']='CMD-cec0ea15-9116-4e12-9a12-a8ea3b3c7f82';
 try{$r=$d->dispatch($body,$platform);$blocked=($r['result']['code']??'')===Policy::DENIED_CODE;}catch(app\services\cashier\v3\CashierV3CommandException $e){$blocked=$e->getResultCode()===Policy::DENIED_CODE;}
 ok($blocked,'direct shared gateway cannot bypass transaction configuration guard');
}finally{Db::rollback();}
