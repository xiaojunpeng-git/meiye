<?php
declare(strict_types=1);
/** 本地真实MySQL业务测试，所有测试员工/预约/排班/审计均在最外层事务回滚。禁止用于生产。 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!function_exists('swoole_cpu_num')) { function swoole_cpu_num(): int { return 1; } }
if (!class_exists('Swoole\\Table')) eval('namespace Swoole; final class Table { public const TYPE_STRING = 7; public const TYPE_INT = 1; }');
$backend = getenv('CASHIER_V3_BACKEND') ?: '/var/www/html';
require $backend . '/vendor/autoload.php';
use think\facade\Db;
use app\services\store\roster\StaffRosterServices;
use app\services\store\roster\StaffRosterAvailability;
use app\services\store\roster\StaffRosterConflict;
use app\services\store\StoreReservationStaffServices;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\reservation\CashierV3ReservationLifecycleServices;
$app = new think\App($backend . '/'); $app->env->load($backend . '/.env'); $app->initialize();
set_exception_handler(static function(Throwable $e): void { file_put_contents('php://stderr', get_class($e).': '.$e->getMessage().PHP_EOL); exit(1); });
$checks=[];
function check(string $name, bool $ok): void { global $checks; if (!$ok) throw new RuntimeException($name); $checks[]=$name; }
function rejects(string $name, callable $call): void { try { $call(); } catch (\think\exception\ValidateException | \app\services\cashier\v3\CashierV3CommandException | StaffRosterConflict $e) { check($name, true); return; } throw new RuntimeException('未拒绝:'.$name); }
$service = new StaffRosterServices(); $availability = new StaffRosterAvailability(); $booking = app()->make(StoreReservationStaffServices::class);
$person=Db::name('system_store_staff')->where('status',1)->where('is_del',0)->where('employee_id','>',0)->where('store_id','>',0)->order('id asc')->find();
if (!$person) throw new RuntimeException('缺少本地测试任职');
$id=(int)$person['id'];$store=(int)$person['store_id'];$actor=['type'=>'STORE','id'=>$id];$date='2030-06-10';$month='2030-06';$ts=fn($clock)=>strtotime($date.' '.$clock);
Db::startTrans();
Db::name('system_config')->where('menu_name','reservation_schedule_manage')->where('is_store',0)->update(['value'=>'1']);
try {
    Db::name('system_store_staff')->where('id',$id)->update(['is_reservable'=>1,'off_work_time'=>'','cashier_craftsman_enabled'=>1]);
    $aliases=StaffRosterAvailability::aliases([$id]);
    Db::name('store_staff_schedule')->whereIn('staff_id',$aliases)->whereBetween('schedule_date',['2030-06-09','2030-06-12'])->delete();
    $snapshot=function()use($id,$store,$date){return StaffRosterServices::fingerprint(Db::name('store_staff_schedule')->where('store_id',$store)->where('staff_id',$id)->where('schedule_date',$date)->order('id asc')->select()->toArray());};
    $write=function(array $body)use($service,$store,$actor){$body['request_token']=$body['request_token']??bin2hex(random_bytes(16));return $service->command($store,$actor,'schedule',$body);};
    $schedule=function(string $kind,array $extras=[])use($write,$id,$date,$snapshot){return $write($extras+['staff_ids'=>[$id],'dates'=>[$date],'kind'=>$kind,'expected'=>[$id=>[$date=>$snapshot()]]]);};
    check('未排班预约拒绝', !$booking->isStaffSelectableForReservation($id,$store,$date,'10:00',60));
    $shift=$service->command($store,$actor,'shift',['name'=>'排班回滚测试','start_time'=>'10:00','end_time'=>'18:00','request_token'=>bin2hex(random_bytes(16))]);$sid=$shift['id'];
    $schedule('WORK',['shift_id'=>$sid]);
    check('班次内可预约', $booking->isStaffSelectableForReservation($id,$store,$date,'10:00',60));
    check('完整服务超班次拒绝', !$booking->isStaffSelectableForReservation($id,$store,$date,'17:30',60));
    $schedule('REST',['partial'=>true,'start_time'=>'12:00','end_time'=>'13:00']);
    check('部分休息保留原班次',Db::name('store_staff_schedule')->where('staff_id',$id)->where('schedule_date',$date)->count()===2);
    check('部分休息拒绝跨越',!$booking->isStaffSelectableForReservation($id,$store,$date,'11:30',90));
    check('休息结束时可预约',$booking->isStaffSelectableForReservation($id,$store,$date,'13:00',60));
    check('仍不能在班次外约',$availability->allows($id,$store,$ts('08:00'),$ts('09:00'))===false);
    $input=['staff_ids'=>[$id],'dates'=>[$date],'kind'=>'REST','partial'=>false,'expected'=>[$id=>[$date=>$snapshot()]],'request_token'=>bin2hex(random_bytes(16))];
    $first=$write($input);$count=Db::name('staff_roster_command')->count();check('重试回执一致',$write($input)===$first);check('重试不重复写审计',Db::name('staff_roster_command')->count()===$count);
    check('全天休息阻止预约',!$booking->isStaffSelectableForReservation($id,$store,$date,'10:00',60));
    rejects('同请求不能换内容',fn()=>$write(array_replace($input,['kind'=>'CLEAR'])));
    rejects('旧页面不能覆盖新排班',fn()=>$write(array_replace($input,['request_token'=>bin2hex(random_bytes(16))])));
    rejects('跨门店员工写入拒绝',fn()=>$service->command($store+100000,$actor,'schedule',['staff_ids'=>[$id],'dates'=>[$date],'kind'=>'CLEAR','expected'=>[$id=>[$date=>$snapshot()]],'request_token'=>bin2hex(random_bytes(16))]));
    $schedule('CLEAR');check('开启时清除排班不可预约',!$booking->isStaffSelectableForReservation($id,$store,$date,'10:00',60));
    $schedule('WORK',['shift_id'=>$sid]);
    $shiftRow=Db::name('store_staff_shift')->where('id',$sid)->find();
    $service->command($store,$actor,'shift',['id'=>$sid,'name'=>'改名且改时间','start_time'=>'11:00','end_time'=>'17:00','expected'=>StaffRosterServices::fingerprint([$shiftRow]),'request_token'=>bin2hex(random_bytes(16))]);
    check('编辑模板不改变已排班时间',$availability->allows($id,$store,$ts('10:00'),$ts('11:00')));
    $shiftRow=Db::name('store_staff_shift')->where('id',$sid)->find();$service->command($store,$actor,'delete-shift',['id'=>$sid,'expected'=>StaffRosterServices::fingerprint([$shiftRow]),'request_token'=>bin2hex(random_bytes(16))]);
    check('删除模板保留排班',Db::name('store_staff_schedule')->where('staff_id',$id)->where('schedule_date',$date)->count()===1);
    check('删除模板不改变可预约时间',$availability->allows($id,$store,$ts('10:00'),$ts('11:00')));
    $reservation=Db::name('cashier_v3_reservation')->insertGetId(['tenant_id'=>CashierV3ScopeResolver::TENANT_SCOPE_ID,'lifecycle_generation'=>CashierV3ReservationLifecycleServices::GENERATION,'store_id'=>$store,'status'=>'UNSTARTED','reservation_id'=>'roster-'.bin2hex(random_bytes(10)),'reservation_no'=>'ROSTER-ROLLBACK','appointment_start_at'=>$ts('14:00'),'appointment_end_at'=>$ts('15:00'),'business_date'=>$date]);
    Db::name('cashier_v3_reservation_staff_schedule')->insert(['tenant_id'=>CashierV3ScopeResolver::TENANT_SCOPE_ID,'reservation_id'=>$reservation,'staff_id'=>$id]);
    check('已有预约仍占用',!$booking->isStaffSelectableForReservation($id,$store,$date,'14:00',60));
    try{$schedule('REST');throw new RuntimeException('应有预约冲突');}catch(StaffRosterConflict $e){check('冲突显示具体预约',count($e->conflicts)===1&&$e->conflicts[0]['reservation_no']==='ROSTER-ROLLBACK');}
    check('冲突保存整笔回滚',Db::name('store_staff_schedule')->where('staff_id',$id)->where('schedule_date',$date)->value('schedule_type')===1);
    check('预约未被取消',Db::name('cashier_v3_reservation')->where('id',$reservation)->value('status')==='UNSTARTED');
    Db::name('cashier_v3_reservation')->where('id',$reservation)->update(['status'=>'CANCELLED']);$schedule('REST');check('取消预约不再阻止休息',!$availability->allows($id,$store,$ts('14:00'),$ts('15:00')));
    rejects('事务提交共同校验休息',fn()=>(new CashierV3ReservationLifecycleServices())->assertStaffAvailabilityInTx($store,0,[$id],$ts('10:00'),$ts('11:00')));
    $schedule('CLEAR');rejects('事务提交拒绝未排班',fn()=>(new CashierV3ReservationLifecycleServices())->assertStaffAvailabilityInTx($store,0,[$id],$ts('10:00'),$ts('11:00')));
    // 同一员工的另一门店任职也读取这份休息，并共享预约占用与锁。
    $aliasStore=(int)Db::name('system_store')->where('is_del',0)->whereNotIn('id',array_column(Db::name('system_store_staff')->whereIn('id',$aliases)->field('store_id')->select()->toArray(),'store_id'))->value('id');
    if (!$aliasStore) throw new RuntimeException('缺少第二门店夹具');
    $aliasId=(int)Db::name('system_store_staff')->insertGetId(['employee_id'=>$person['employee_id'],'store_id'=>$aliasStore,'staff_name'=>'排班跨店回滚夹具','account'=>'roster-'.bin2hex(random_bytes(8)),'is_reservable'=>1,'status'=>1,'is_del'=>0,'can_choose'=>1]);
    $otherWork=(int)Db::name('store_staff_schedule')->insertGetId(['store_id'=>$aliasStore,'staff_id'=>$aliasId,'schedule_date'=>$date,'schedule_type'=>1,'start_time'=>'10:00','end_time'=>'18:00']);
    $crossShift=$service->command($store,$actor,'shift',['name'=>'跨店排班回滚测试','start_time'=>'10:00','end_time'=>'18:00','request_token'=>bin2hex(random_bytes(16))]);
    rejects('同员工跨店重叠班次拒绝',fn()=>$schedule('WORK',['shift_id'=>$crossShift['id']]));
    Db::name('store_staff_schedule')->where('id',$otherWork)->delete();
    $schedule('REST');check('已有休息在另一门店生效',!$booking->isStaffSelectableForReservation($aliasId,$aliasStore,$date,'10:00',60));
    $schedule('CLEAR');
    $schedule('WORK',['shift_id'=>$crossShift['id']]);
    $foreignReservation=(int)Db::name('cashier_v3_reservation')->insertGetId(['tenant_id'=>CashierV3ScopeResolver::TENANT_SCOPE_ID,'lifecycle_generation'=>CashierV3ReservationLifecycleServices::GENERATION,'store_id'=>$aliasStore,'status'=>'UNSTARTED','reservation_id'=>'roster-'.bin2hex(random_bytes(10)),'reservation_no'=>'ROSTER-OTHER-STORE','appointment_start_at'=>$ts('14:00'),'appointment_end_at'=>$ts('15:00'),'business_date'=>$date]);
    Db::name('cashier_v3_reservation_staff_schedule')->insert(['tenant_id'=>CashierV3ScopeResolver::TENANT_SCOPE_ID,'reservation_id'=>$foreignReservation,'staff_id'=>$aliasId]);
    check('同员工跨店预约不能撞期',!$booking->isStaffSelectableForReservation($id,$store,$date,'14:00',60));
    try{$schedule('REST');throw new RuntimeException('应有跨店预约冲突');}catch(StaffRosterConflict $e){check('跨店冲突不暴露其他店单号',$e->conflicts[0]['reservation_no']==='其他门店预约');}
    Db::name('cashier_v3_reservation')->where('id',$foreignReservation)->update(['status'=>'CANCELLED']);
    $legacy=app()->make(\app\services\store\StoreStaffScheduleServices::class);
    $legacy->saveSchedule($store,['staff_id'=>$id,'schedule_date'=>$date,'schedule_type'=>0,'start_time'=>'12:00','end_time'=>'13:00']);
    check('旧老师休息与新排班共用一份数据',!$booking->isStaffSelectableForReservation($id,$store,$date,'12:00',60));
    $schedule('CLEAR');
    // 超过20条的后端真实分页，以及空结果、最后一页、越界和导出同范围。
    $extraIds=[];
    for($i=0;$i<45;$i++)$extraIds[]=(int)Db::name('system_store_staff')->insertGetId(['store_id'=>$store,'staff_name'=>'ROSTER-PAGE-'.str_pad((string)$i,2,'0',STR_PAD_LEFT),'status'=>1,'is_del'=>0]);
    $p1=$service->query($store,['month'=>$month,'keyword'=>'ROSTER-PAGE-','page'=>1]);$p2=$service->query($store,['month'=>$month,'keyword'=>'ROSTER-PAGE-','page'=>2]);$p3=$service->query($store,['month'=>$month,'keyword'=>'ROSTER-PAGE-','page'=>3]);
    check('第一页及第二页各20条',count($p1['staff'])===20&&count($p2['staff'])===20);
    check('总数统计全部命中',$p1['total']===45&&$p2['total']===45);
    check('分页没有交叉',!array_intersect(array_column($p1['staff'],'id'),array_column($p2['staff'],'id')));
    check('末页保留5条',count($p3['staff'])===5);
    check('越界页落到末页',$service->query($store,['month'=>$month,'keyword'=>'ROSTER-PAGE-','page'=>99])['page']===3);
    check('全部导出不被当前页截断',count($service->exportRows($store,['month'=>$month,'keyword'=>'ROSTER-PAGE-','page'=>2,'range'=>'all'])['rows'])===45*30);
    check('当前页导出与当前页一致',count($service->exportRows($store,['month'=>$month,'keyword'=>'ROSTER-PAGE-','page'=>3,'range'=>'page'])['rows'])===5*30);
    Db::name('system_store_staff')->whereIn('id',$extraIds)->delete();
    $query=$service->query($store,['month'=>$month]);$export=$service->exportRows($store,['month'=>$month]);check('查询和全量导出范围相同',count($export['rows'])===$query['total']*30);
    check('空查询保留第1页',$service->query($store,['month'=>$month,'keyword'=>'NO-SUCH-ROSTER-PERSON-zz'])['page']===1);
    $platform=$service->query($store,['month'=>$month]);check('平台门店相同范围同一结果',$platform===$query);
    rejects('非法日期拒绝',fn()=>$write(['staff_ids'=>[$id],'dates'=>['2030-02-30'],'kind'=>'REST','expected'=>[]]));
    rejects('非法班次范围拒绝',fn()=>$service->command($store,$actor,'shift',['name'=>'bad','start_time'=>'18:00','end_time'=>'10:00','request_token'=>bin2hex(random_bytes(16))]));
    echo json_encode(['passed'=>count($checks),'checks'=>$checks,'rolledBack'=>true],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),PHP_EOL;
} finally { Db::rollback(); }
