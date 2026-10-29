<?php
/** Named-customer, read-only booking and issued-card schema probes. Never creates a reservation. */
declare(strict_types=1);
$sites=['rh'=>['rh.cc3798.com','ruihao',3306],'008'=>['008.cc3798.com','mohe_008',3307],'010'=>['010.cc3798.com','mohe_010_app',3307]];
$site=$argv[1]??'';if(!isset($sites[$site]))throw new RuntimeException('Named instance required');
[$domain,$database,$port]=$sites[$site];$root='/www/wwwroot/'.$domain;chdir($root);require$root.'/vendor/autoload.php';(new think\App($root.'/'))->initialize();
set_exception_handler(static function(Throwable$e):void{file_put_contents('php://stderr',$e->getMessage());exit(1);});
$c=config('database.connections.mysql');if($c['database']!==$database||(int)$c['hostport']!==$port)throw new RuntimeException('Instance mismatch');
$db=think\facade\Db::connect();$db->execute('START TRANSACTION READ ONLY');
try{
 foreach(['cashier_v3_card_rule_state','cashier_v3_card_rule_component','cashier_v3_card_rule_group_state','cashier_v3_reservation','cashier_v3_reservation_line']as$t){$db->query('SELECT 1 FROM eb_'.$t.' LIMIT 1');}
 $db->query('SELECT combination_group_no FROM eb_cashier_v3_card_rule_component LIMIT 1');
 if(($argv[2]??'')==='schema'){echo json_encode(['site'=>$site,'result'=>'PASS','existingSchemaReady'=>true,'writes'=>0]);exit;}
 $svc=new app\services\cashier\v3\reservation\ImportedEntitlementReservationStoreServices();
 $booking=new ReflectionMethod(app\services\cashier\v3\reservation\MemberV3ReservationServices::class,'create');
 $q=$db->name('store_order_cart_info')->alias('c')->join('store_order o','o.id=c.oid')
  ->where('o.paid',1)->where('o.is_del',0)->where('o.is_user_del',0)->where('o.is_system_del',0)->where('o.store_id','>',0)
  ->where('o.refund_status',0)->where('o.terminal_action',0)->where('c.cart_type',2)->where('c.product_type',6)->where('c.is_writeoff',0)->where('c.write_surplus_times','>',0);
 if($site==='008')$q->where('c.id',16711)->where('o.id',6558);
 $mains=$q->field('c.id,c.oid,o.uid,o.store_id')->order('c.id desc')->limit(12)->select()->toArray();
 $verified=null;
 foreach($mains as$main){
  try{$data=$svc->ownedAddonOptions((int)$main['uid'],(int)$main['oid'],(int)$main['id'],(int)$main['store_id']);}
  catch(think\exception\ValidateException$e){continue;}
  $count=0;$historical=0;$cards=0;
  foreach($data['list']as$group){if(!empty($group['order']['card']))$cards++;$hidden=(int)$db->name('store_order')->where('id',(int)$group['order']['id'])->value('is_system_del');
   foreach($group['cart_info']as$item){if((int)$item['id']===(int)$main['id']||(int)$item['write_surplus_times']<=0||!isset($item['card_presentation']['remaining_text']))throw new RuntimeException('Owned picker contract invalid');$count++;if($hidden===1)$historical++;}}
  $denied=false;try{$svc->ownedAddonOptions((int)$main['uid'],(int)$main['oid']+1000000000,(int)$main['id'],(int)$main['store_id']);}catch(think\exception\ValidateException$e){$denied=true;}
  if(!$denied)throw new RuntimeException('Main-order binding bypass');
  $verified=['ownedQuery'=>true,'projects'=>$count,'historicalProjects'=>$historical,'cardGroups'=>$cards,'wrongMainOrderDenied'=>true];break;
 }
 if($site==='008'&&$verified===null)throw new RuntimeException('Current member booking context could not be verified');
 echo json_encode(['site'=>$site,'result'=>'PASS','existingSchemaReady'=>true,'bookingClassLoaded'=>$booking->isPublic(),'readScenario'=>$verified,'writes'=>0]);
}finally{$db->execute('ROLLBACK');}
