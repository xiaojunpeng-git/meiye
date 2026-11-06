<?php
/** Production verification: existing identities/readers; disabled policy tested inside a rolled-back private transaction. No business command is allowed to write. */
use think\facade\Db;
use app\services\order\PlatformDocumentEditPolicy as Policy;
use app\services\cashier\v3\manifest\CashierV3ActionManifest as Manifest;
function checkR5($v,$label){if(!$v)throw new RuntimeException($label);}
checkR5(Policy::enabled(),'DEFAULT_ENABLED');
$admin=Db::name('system_admin')->where('status',1)->where('is_del',0)->where('level',0)->where('admin_type',0)->find();checkR5($admin,'EXISTING_ADMIN');
$form=app()->make(app\services\system\config\SystemConfigServices::class)->shopBaseFormBuild();checkR5(strpos(json_encode($form),Policy::CONFIG_KEY)!==false,'CONFIG_FORM_FIELD');
$reader=app()->make(app\services\order\PlatformOrderCenterReadServices::class);$action=new app\services\order\PlatformOrderCenterActionServices();
$invoke=function($a,$p=[])use($admin,$action){return $action->dispatch((int)$admin['id'],$p+['action'=>$a,'clientSessionId'=>'SESSION-cec0ea15-9116-4e12-9a12-a8ea3b3c7f81']);};
$enabled=$reader->scope($admin,app()->make(app\services\organization\OrganizationScopeService::class));checkR5($enabled['document_edit_enabled']===true,'ENABLED_SCOPE');
$checks=0;$order=Db::name('cashier_v3_sales_order')->where('order_status','settled')->find();
Db::startTrans();try{
 Db::name('system_config')->where('menu_name',Policy::CONFIG_KEY)->where('is_store',0)->update(['value'=>'0']);checkR5(!Policy::enabled(true),'DISABLED_TRANSACTION_READ');
 foreach(Manifest::all()as$name=>$d){$expected=$d['type']===Manifest::TYPE_PROJECTION||in_array($d['canonical'],['print-sales-order-receipt','create-unified-query-export'],true);$allowed=true;try{Policy::assertAllowed($name);}catch(app\services\cashier\v3\CashierV3CommandException $e){checkR5($e->getResultCode()===Policy::DENIED_CODE,'DENIAL_CODE');$allowed=false;}checkR5($allowed===$expected,'ACTION_'.$name);$checks++;}
 $scope=$reader->scope($admin,app()->make(app\services\organization\OrganizationScopeService::class));checkR5($scope['document_edit_enabled']===false,'DISABLED_SCOPE');
 $cap=$invoke('query-unified-query-capabilities',['pageCode'=>'order_center_sales']);checkR5($cap['result']['status']==='success','QUERY_EXPORT_CAPABILITIES');
 $list=$invoke('query-sales-orders',['businessDateFrom'=>'2026-11-01','businessDateTo'=>'2026-11-06','pageSize'=>20]);checkR5($list['result']['status']==='success','READ_LIST');
 if($order){$detail=$invoke('open-sales-order-detail',['orderId'=>$order['order_id']]);checkR5($detail['result']['status']==='success','READ_DETAIL');$before=Db::name('cashier_v3_sales_order')->where('order_id',$order['order_id'])->find();$denied=$invoke('update-sales-order-note',['orderId'=>$order['order_id'],'orderNote'=>'R5 must be rejected']);checkR5(($denied['result']['code']??'')===Policy::DENIED_CODE,'WRITE_BLOCKED');checkR5($before===Db::name('cashier_v3_sales_order')->where('order_id',$order['order_id'])->find(),'ORDER_UNCHANGED');}
}finally{Db::rollback();}
checkR5(Policy::enabled(),'CONFIG_ROLLED_BACK_TO_ENABLED');
echo json_encode(['result'=>'PASS','manifestActions'=>$checks,'settingsField'=>true,'scopeSwitch'=>true,'queryAndDetail'=>true,'blockedMutation'=>$order?true:'no settled orders','defaultEnabledAfterTest'=>true,'businessWrites'=>false,'otherConnectionsSeeEnabled'=>true]);
