<?php
declare(strict_types=1);
/** 排班开关及三端共同校验：所有参数、排班、预约和审计改动在本地事务回滚。 */
error_reporting(E_ALL & ~E_DEPRECATED);
require '/var/www/html/vendor/autoload.php';
use think\facade\Db;
use app\services\store\roster\StaffRosterSettingServices as Settings;
use app\services\store\roster\StaffRosterSwitch as Switcher;
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
 $read=$settings->read();$request=['enabled'=>0,'version'=>$read['version'],'request_token'=>bin2hex(random_bytes(16))];$disabled=$settings->save(1,$request);
 ok('关闭值落库并可重新读取',$settings->read()===$disabled&&!Switcher::enabled());
 ok('重放同一参数请求不重复更新版本',$settings->save(1,$request)===$disabled);
 try{$settings->save(1,array_replace($request,['request_token'=>bin2hex(random_bytes(16))]));throw new RuntimeException('旧版本未拒绝');}catch(\think\exception\ValidateException $e){ok('旧参数版本不能覆盖',true);}
 Db::name('store_staff_schedule')->insert(['store_id'=>$store,'staff_id'=>$id,'schedule_date'=>$date,'schedule_type'=>1,'start_time'=>'10:00','end_time'=>'18:00','roster_managed'=>1]);
 Db::name('store_staff_schedule')->insert(['store_id'=>$store,'staff_id'=>$id,'schedule_date'=>$date,'schedule_type'=>0,'start_time'=>'12:00','end_time'=>'13:00','roster_managed'=>1]);
 Db::name('store_staff_schedule')->insert(['store_id'=>$store,'staff_id'=>$id,'schedule_date'=>$date,'schedule_type'=>0,'start_time'=>'15:00','end_time'=>'16:00','roster_managed'=>0]);
 ok('关闭时新上班范围不拦截原预约',$booking->isStaffSelectableForReservation($id,$store,$date,'09:00',60));
 ok('关闭时新休息不拦截原预约',$booking->isStaffSelectableForReservation($id,$store,$date,'12:00',60));
 ok('关闭时原有老师休息继续有效',!$booking->isStaffSelectableForReservation($id,$store,$date,'15:00',60));
 $enabled=$settings->save(1,['enabled'=>1,'version'=>$disabled['version'],'request_token'=>bin2hex(random_bytes(16))]);
 ok('开启后完整上班范围生效',!$booking->isStaffSelectableForReservation($id,$store,$date,'09:00',60));
 ok('开启后新休息生效',!$booking->isStaffSelectableForReservation($id,$store,$date,'12:00',60));
 ok('开启后正常工作时间可约',$booking->isStaffSelectableForReservation($id,$store,$date,'13:00',60));
 $disabled=$settings->save(1,['enabled'=>0,'version'=>$enabled['version'],'request_token'=>bin2hex(random_bytes(16))]);
 $rid=(int)Db::name('cashier_v3_reservation')->insertGetId(['tenant_id'=>CashierV3ScopeResolver::TENANT_SCOPE_ID,'lifecycle_generation'=>CashierV3ReservationLifecycleServices::GENERATION,'reservation_id'=>'switch-'.bin2hex(random_bytes(8)),'reservation_no'=>'ROSTER-SWITCH-ROLLBACK','store_id'=>$store,'status'=>'UNSTARTED','appointment_start_at'=>strtotime($date.' 12:00'),'appointment_end_at'=>strtotime($date.' 13:00'),'business_date'=>$date]);
 Db::name('cashier_v3_reservation_staff_schedule')->insert(['tenant_id'=>CashierV3ScopeResolver::TENANT_SCOPE_ID,'reservation_id'=>$rid,'staff_id'=>$id]);
 // 已有预约分别覆盖休息、班次外、未排班和服务中，开启不能要求取消或重写它们。
 $existing=[$rid];
 foreach ([['09:00','10:00','UNSTARTED',$date],['12:00','13:00','IN_SERVICE',$date],['10:00','11:00','PENDING_CONFIRMATION','2030-07-11']] as [$start,$end,$status,$day]) {
  $old=(int)Db::name('cashier_v3_reservation')->insertGetId(['tenant_id'=>CashierV3ScopeResolver::TENANT_SCOPE_ID,'lifecycle_generation'=>CashierV3ReservationLifecycleServices::GENERATION,'reservation_id'=>'switch-'.bin2hex(random_bytes(8)),'reservation_no'=>'ROSTER-SWITCH-KEEP-'.bin2hex(random_bytes(6)),'store_id'=>$store,'status'=>$status,'appointment_start_at'=>strtotime($day.' '.$start),'appointment_end_at'=>strtotime($day.' '.$end),'business_date'=>$day]);
  Db::name('cashier_v3_reservation_staff_schedule')->insert(['tenant_id'=>CashierV3ScopeResolver::TENANT_SCOPE_ID,'reservation_id'=>$old,'staff_id'=>$id]);$existing[]=$old;
 }
 $snapshot=static function()use($existing,$id){return [
  Db::name('cashier_v3_reservation')->whereIn('id',$existing)->order('id')->select()->toArray(),
  Db::name('cashier_v3_reservation_staff_schedule')->whereIn('reservation_id',$existing)->order('id')->select()->toArray(),
  Db::name('store_staff_schedule')->where('staff_id',$id)->order('id')->select()->toArray()];};
 $before=$snapshot();$audit=Db::name('staff_roster_command')->count();
 $enable=['enabled'=>1,'version'=>$disabled['version'],'request_token'=>bin2hex(random_bytes(16))];
 $saved=$settings->save(1,$enable);
 ok('已有休息班次外未排班及服务中预约都不阻止开启',Switcher::enabled()&&$settings->read()===$saved);
 ok('开启只推进一次设置版本',$saved['version']===$disabled['version']+1);
 ok('开启保留所有原预约及人员排班数据',$snapshot()===$before);
 ok('开启仅新增一笔设置审计',Db::name('staff_roster_command')->count()===$audit+1);
 ok('开启重试返回同一结果',$settings->save(1,$enable)===$saved);
 ok('开启重试不重复写审计',Db::name('staff_roster_command')->count()===$audit+1);
 ok('开启后新预约仍拒绝休息时段',!$booking->isStaffSelectableForReservation($id,$store,$date,'12:00',60));
 ok('开启后新预约仍拒绝班次外',!$booking->isStaffSelectableForReservation($id,$store,$date,'09:00',60));
 ok('开启后新预约仍拒绝未排班',!$booking->isStaffSelectableForReservation($id,$store,'2030-07-11','10:00',60));
 try{$settings->save(1,array_replace($enable,['enabled'=>0]));throw new RuntimeException('同令牌换内容未拒绝');}catch(\think\exception\ValidateException $e){ok('同令牌不能更换设置内容',true);}
 $settings->save(1,['enabled'=>0,'version'=>$saved['version'],'request_token'=>bin2hex(random_bytes(16))]);
 ok('关闭仍恢复未排班原预约规则',$booking->isStaffSelectableForReservation($id,$store,'2030-07-11','13:00',60));
 ok('开关往返均不改预约和排班',$snapshot()===$before);
 echo json_encode(['passed'=>count($checks),'checks'=>$checks,'rolledBack'=>true],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),PHP_EOL;
}finally{Db::rollback();}
