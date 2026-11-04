<?php
declare(strict_types=1);
/** 排班开关及三端共同校验：所有参数、排班、预约和审计改动在本地事务回滚。 */
error_reporting(E_ALL & ~E_DEPRECATED);
require '/var/www/html/vendor/autoload.php';
use think\facade\Db;
use app\services\store\roster\StaffRosterSettingServices as Settings;
use app\services\store\roster\StaffRosterSwitch as Switcher;
use app\services\store\roster\StaffRosterConflict;
use app\services\store\StoreReservationStaffServices;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\reservation\CashierV3ReservationLifecycleServices;
$app=new think\App('/var/www/html/');$app->initialize();
set_exception_handler(static function(Throwable $e):void{file_put_contents('php://stderr',get_class($e).':'.$e->getMessage().PHP_EOL);exit(1);});
$checks=[];function ok($n,$v){global $checks;if(!$v)throw new RuntimeException($n);$checks[]=$n;}
$staff=Db::name('system_store_staff')->where('status',1)->where('is_del',0)->where('store_id','>',0)->find();$id=(int)$staff['id'];$store=(int)$staff['store_id'];$date='2030-07-10';
$settings=new Settings();$booking=app()->make(StoreReservationStaffServices::class);
Db::startTrans();try{
 Db::name('system_store_staff')->where('id',$id)->update(['is_reservable'=>1,'off_work_time'=>'']);
 Db::name('store_staff_schedule')->whereIn('staff_id',\app\services\store\roster\StaffRosterAvailability::aliases([$id]))->whereBetween('schedule_date',['2030-07-09','2030-07-11'])->delete();
 $read=$settings->read();$request=['enabled'=>0,'version'=>$read['version'],'request_token'=>bin2hex(random_bytes(16))];$disabled=$settings->save(1,$request,[$store]);
 ok('关闭值落库并可重新读取',$settings->read()===$disabled&&!Switcher::enabled());
 ok('重放同一参数请求不重复更新版本',$settings->save(1,$request,[$store])===$disabled);
 try{$settings->save(1,array_replace($request,['request_token'=>bin2hex(random_bytes(16))]),[$store]);throw new RuntimeException('旧版本未拒绝');}catch(\think\exception\ValidateException $e){ok('旧参数版本不能覆盖',true);}
 Db::name('store_staff_schedule')->insert(['store_id'=>$store,'staff_id'=>$id,'schedule_date'=>$date,'schedule_type'=>1,'start_time'=>'10:00','end_time'=>'18:00','roster_managed'=>1]);
 Db::name('store_staff_schedule')->insert(['store_id'=>$store,'staff_id'=>$id,'schedule_date'=>$date,'schedule_type'=>0,'start_time'=>'12:00','end_time'=>'13:00','roster_managed'=>1]);
 Db::name('store_staff_schedule')->insert(['store_id'=>$store,'staff_id'=>$id,'schedule_date'=>$date,'schedule_type'=>0,'start_time'=>'15:00','end_time'=>'16:00','roster_managed'=>0]);
 ok('关闭时新上班范围不拦截原预约',$booking->isStaffSelectableForReservation($id,$store,$date,'09:00',60));
 ok('关闭时新休息不拦截原预约',$booking->isStaffSelectableForReservation($id,$store,$date,'12:00',60));
 ok('关闭时原有老师休息继续有效',!$booking->isStaffSelectableForReservation($id,$store,$date,'15:00',60));
 $enabled=$settings->save(1,['enabled'=>1,'version'=>$disabled['version'],'request_token'=>bin2hex(random_bytes(16))],[$store]);
 ok('开启后完整上班范围生效',!$booking->isStaffSelectableForReservation($id,$store,$date,'09:00',60));
 ok('开启后新休息生效',!$booking->isStaffSelectableForReservation($id,$store,$date,'12:00',60));
 ok('开启后正常工作时间可约',$booking->isStaffSelectableForReservation($id,$store,$date,'13:00',60));
 $disabled=$settings->save(1,['enabled'=>0,'version'=>$enabled['version'],'request_token'=>bin2hex(random_bytes(16))],[$store]);
 $rid=(int)Db::name('cashier_v3_reservation')->insertGetId(['tenant_id'=>CashierV3ScopeResolver::TENANT_SCOPE_ID,'lifecycle_generation'=>CashierV3ReservationLifecycleServices::GENERATION,'reservation_id'=>'switch-'.bin2hex(random_bytes(8)),'reservation_no'=>'ROSTER-SWITCH-ROLLBACK','store_id'=>$store,'status'=>'UNSTARTED','appointment_start_at'=>strtotime($date.' 12:00'),'appointment_end_at'=>strtotime($date.' 13:00'),'business_date'=>$date]);
 Db::name('cashier_v3_reservation_staff_schedule')->insert(['tenant_id'=>CashierV3ScopeResolver::TENANT_SCOPE_ID,'reservation_id'=>$rid,'staff_id'=>$id]);
 try{$settings->save(1,['enabled'=>1,'version'=>$disabled['version'],'request_token'=>bin2hex(random_bytes(16))],[$store]);throw new RuntimeException('开启冲突未拒绝');}catch(StaffRosterConflict $e){ok('开启前检查既有预约冲突',count($e->conflicts)>0);}
 ok('开启冲突时配置版本和值均不变',$settings->read()===$disabled);
 Db::name('cashier_v3_reservation')->where('id',$rid)->update(['status'=>'CANCELLED']);
 $settings->save(1,['enabled'=>1,'version'=>$disabled['version'],'request_token'=>bin2hex(random_bytes(16))],[$store]);ok('处理冲突后可重新开启',Switcher::enabled());
 echo json_encode(['passed'=>count($checks),'checks'=>$checks,'rolledBack'=>true],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),PHP_EOL;
}finally{Db::rollback();}
