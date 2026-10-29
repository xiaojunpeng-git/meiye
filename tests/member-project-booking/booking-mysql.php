<?php
declare(strict_types=1);
/** Local Docker only: clone isolated rights inside one rollback transaction.
 * Exercise actual booking commands; production and existing cards stay unchanged. */
if(getenv('M015_LOCAL_TEST')!=='1')throw new RuntimeException('Local test guard');
require '/var/www/html/vendor/autoload.php';(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
use app\services\cashier\v3\reservation\MemberV3ReservationServices;
use app\services\order\StoreReservationOrderServices;
$c=config('database.connections.mysql');if($c['hostname']!=='mysql'||(int)$c['hostport']!==3306)throw new RuntimeException('Only Docker MySQL');
$base=Db::name('user_card_holder')->alias('h')->join('store_order o','o.id=h.oid')->join('store_order_cart_info d','d.oid=o.id AND d.product_id=h.product_id')->join('store_product p','p.id=d.product_id')->join('organization_store os','os.store_id=o.store_id')->where('h.is_del',0)->where('d.cart_type',2)->where('d.product_type',6)->where('o.paid',1)->where('o.is_del',0)->where('o.is_system_del',0)->field('h.id AS holder_id,o.id AS oid,d.id AS detail_id')->order('h.id desc')->find();
if(!$base)throw new RuntimeException('Project fixture missing');
$originalHolder=Db::name('user_card_holder')->where('id',$base['holder_id'])->find();$originalDetail=Db::name('store_order_cart_info')->where('id',$base['detail_id'])->find();$originalOrder=Db::name('store_order')->where('id',$base['oid'])->find();
$before=hash('sha256',json_encode([$originalHolder,$originalDetail,$originalOrder]));$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;echo 'PASS ',$label,PHP_EOL;};
$read=app()->make(StoreReservationOrderServices::class);$svc=new MemberV3ReservationServices();
Db::startTrans();
try{
 foreach(['single'=>6,'gift'=>5]as $kind=>$holderType){
  $token='M015'.bin2hex(random_bytes(7));$o=$originalOrder;unset($o['id']);$o['order_id']=$token;$o['type']=$kind==='single'?12:11;$o['product_type']=6;$o['channel_type']=$kind==='single'?'member_v3':'cashier_v3_direct_gift';$o['card_upgrade_use_oid']=0;$o['refund_status']=0;$o['terminal_action']=0;$o['is_del']=0;$o['is_user_del']=0;$o['is_system_del']=0;$o['paid']=1;$o['unique']=md5($token);
  $oid=(int)Db::name('store_order')->insertGetId($o);
  $d=$originalDetail;unset($d['id']);$d['oid']=$oid;$d['cart_id']=$token;$d['unique']=md5('detail'.$token);$d['write_times']=1;$d['write_surplus_times']=1;$d['cart_num']=1;$d['is_writeoff']=0;$d['write_start']=time()-60;$d['write_end']=0;$detailId=(int)Db::name('store_order_cart_info')->insertGetId($d);
  Db::name('store_order')->where('id',$oid)->update(['cart_id'=>json_encode([(string)$detailId])]);
  $h=$originalHolder;unset($h['id']);$h['oid']=$oid;$h['product_type']=$holderType;$h['card_name']=$kind==='single'?'M015 单次项目':'M015 赠送项目';$h['card_no']=(string)random_int(8000000,9999999);$h['verify_code']=substr(md5($token),0,12);$h['write_times']=1;$h['write_surplus_times']=1;$h['write_start']=time()-60;$h['write_end']=0;$h['write_valid']=1;$holderId=(int)Db::name('user_card_holder')->insertGetId($h);
  $info=$read->getOrderInfo((int)$h['uid'],$oid,$detailId);$check((int)$info['cart_info_id']===$detailId&&(int)$info['cart_num']===1,$kind.' authoritative booking identity/quota');$check((int)$info['project_service_duration']>0,$kind.' service duration available');
  $input=['cart_num'=>1,'reservation_time'=>date('Y-m-d',strtotime('+2 days')),'reservation_start'=>'10:00','reservation_end'=>'11:00','custom_form'=>[[]],'cart_info_id'=>$detailId,'service_staff_id'=>0,'service_duration_minutes'=>60,'addon_items'=>[],'sync_all'=>[],'store_id'=>(int)$o['store_id'],'mark'=>'M015 LOCAL ROLLBACK TEST'];
  $created=$svc->create((int)$h['uid'],$oid,$input);$rid=(int)$created['reservationId'];$check($rid>0&&Db::name('cashier_v3_reservation')->where('id',$rid)->value('status')==='PENDING_CONFIRMATION',$kind.' creates pending confirmation');
  $again=$svc->create((int)$h['uid'],$oid,$input);$check((int)$again['reservationId']===$rid,$kind.' retry returns same booking');
  $check((int)Db::name('user_card_holder')->where('id',$holderId)->value('write_surplus_times')===1&&(int)Db::name('store_order_cart_info')->where('id',$detailId)->value('write_surplus_times')===1,$kind.' reservation does not consume rights');
  $line=Db::name('cashier_v3_reservation_line')->where('reservation_id',$rid)->find();$check((int)$line['entitlement_source_detail_id']===$detailId&&$line['project_source']==='ENTITLEMENT',$kind.' line binds original issued project');
  $check(Db::name('cashier_v3_reservation_entitlement_occupation')->where('reservation_id',$rid)->count()===0,$kind.' existing no-reservation-of-card-times rule retained');
  $blocked=false;try{$svc->create((int)$h['uid']+10000000,$oid,$input);}catch(Throwable $e){$blocked=true;}$check($blocked,$kind.' rejects another member');
  $svc->cancel((int)$h['uid'],$rid);$check(Db::name('cashier_v3_reservation')->where('id',$rid)->value('status')==='CANCELLED',$kind.' pending cancellation succeeds');
  $reb=$svc->create((int)$h['uid'],$oid,$input);$check((int)$reb['reservationId']!==$rid,$kind.' can rebook after cancellation');$svc->cancel((int)$h['uid'],(int)$reb['reservationId']);
  // Confirm through the same transactional domain handler using an existing
  // source snapshot. Test scaffolding touches only this newly cloned booking.
  $confirmed=$svc->create((int)$h['uid'],$oid,$input);$confirmId=(int)$confirmed['reservationId'];
  $source=Db::name('cashier_v3_reservation')->where('business_source_primary_id','>',0)->order('id desc')->field('business_source_primary_id,business_source_secondary_id,business_source_label_snapshot')->find();
  if(!$source)throw new RuntimeException('Configured reservation source fixture required');
  Db::name('cashier_v3_reservation')->where('id',$confirmId)->update($source);
  $org=(string)Db::name('organization_store')->where('store_id',$o['store_id'])->value('org_id');$tenant=\app\services\cashier\v3\CashierV3ScopeResolver::TENANT_SCOPE_ID;
  $operator=new \app\services\cashier\v3\CashierV3OperatorScope((int)$o['store_id'],(int)$h['uid'],$org,$tenant);
  $scope=new \app\services\cashier\v3\CashierV3DataScopeContext((int)$h['uid'],0,(int)$o['store_id'],$tenant,$org,[(int)$o['store_id']],\app\services\cashier\v3\CashierV3DataScopeContext::MODE_STORES,[],false,'local_test','m015',[],['name'=>'Local test']);
  $recorder=new \app\services\cashier\v3\event\CashierV3BusinessEventRecorder();$key='m015-confirm-'.$token;
  $event=$recorder->newExecution('confirm-reservation',$key,$operator,$scope,'LOCAL-TEST');
  $method=new ReflectionMethod(\app\services\cashier\v3\reservation\CashierV3ReservationModule::class,'confirm');$method->setAccessible(true);
  $result=$method->invoke(null,['payload'=>['reservationId'=>$confirmId],'operator_scope'=>$operator,'data_scope'=>$scope,'idempotency_key'=>$key,'action'=>'confirm-reservation','event_recorder'=>$recorder,'event_execution'=>$event,'event_contract'=>\app\services\cashier\v3\manifest\CashierV3ActionManifest::eventContractFor('confirm-reservation')]);
  $check($result['reservationStatus']==='UNSTARTED'&&Db::name('cashier_v3_reservation')->where('id',$confirmId)->value('status')==='UNSTARTED',$kind.' confirmation domain advances to waiting service');
  $check((int)Db::name('user_card_holder')->where('id',$holderId)->value('write_surplus_times')===1,$kind.' confirmation preserves existing card times');

 }
}finally{Db::rollback();}
$after=hash('sha256',json_encode([Db::name('user_card_holder')->where('id',$base['holder_id'])->find(),Db::name('store_order_cart_info')->where('id',$base['detail_id'])->find(),Db::name('store_order')->where('id',$base['oid'])->find()]));$check($before===$after,'existing order/detail/card unchanged');echo 'M015_MYSQL_PASS checks=',$checks,' productionWrites=0 rolledBack=true',PHP_EOL;
