<?php
declare(strict_types=1);
/** 本地真实数据库验证预约日历与三端选人同一名单；员工、排班与开关改动全部回滚。 */
error_reporting(E_ALL & ~E_DEPRECATED);
require '/var/www/html/vendor/autoload.php';
use think\facade\Db;
use app\services\store\roster\StaffRosterAvailability;
use app\services\store\StoreReservationStaffServices;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\reservation\CashierV3ReservationPartitionProvider;
use app\services\cashier\v3\reservation\CashierV3ReservationModule;
use app\services\cashier\v3\reservation\CashierV3ReservationLifecycleServices;
$app = new think\App('/var/www/html/'); $app->initialize();
set_exception_handler(static function(Throwable $e): void {file_put_contents('php://stderr', get_class($e).':'.$e->getMessage().PHP_EOL);exit(1);});
$checks=[];
function ok(string $name, bool $value): void {global $checks;if (!$value) throw new RuntimeException($name);$checks[]=$name;}
$store=(int)Db::name('system_store')->where('is_del',0)->order('id')->value('id');
$day='2030-08-12';$ts=fn($v)=>strtotime($day.' '.$v);
$calendar=new ReflectionMethod(CashierV3ReservationPartitionProvider::class,'calendar');$calendar->setAccessible(true);
$craftsmen=new ReflectionMethod(CashierV3ReservationModule::class,'craftsmen');$craftsmen->setAccessible(true);
$booking=app()->make(StoreReservationStaffServices::class);$availability=new StaffRosterAvailability();
Db::startTrans();try {
    $ids=[];
    foreach (['上班','全天休息','未排班','上班午休'] as $label) $ids[]=(int)Db::name('system_store_staff')->insertGetId([
        'store_id'=>$store,'staff_name'=>'排班可视测试-'.$label,'account'=>'visibility-'.bin2hex(random_bytes(8)),
        'status'=>1,'is_del'=>0,'cashier_craftsman_enabled'=>1,'is_reservable'=>1,'off_work_time'=>'']);
    $add=function($id,$type,$start='',$end='')use($store,$day){Db::name('store_staff_schedule')->insert(['store_id'=>$store,'staff_id'=>$id,'schedule_date'=>$day,'schedule_type'=>$type,'start_time'=>$start,'end_time'=>$end]);};
    $add($ids[0],1,'10:00','18:00');$add($ids[1],0);$add($ids[3],1,'10:00','18:00');$add($ids[3],0,'12:00','13:00');
    // 保留已有预约的时间、房间块及原人员归属，不能借日历列过滤改写或删除预约。
    $row=['id'=>91001,'appointment_start_at'=>$ts('11:00'),'appointment_end_at'=>$ts('12:00'),'status'=>'UNSTARTED','member_name_snapshot'=>'测试会员','room_id'=>0];
    $read=fn()=>$calendar->invoke(null,CashierV3ScopeResolver::TENANT_SCOPE_ID,$store,$day,[$row],[],[91001=>[$ids[1]]],[]);
    $set=function($v){Db::name('system_config')->where('menu_name','reservation_schedule_manage')->where('is_store',0)->update(['value'=>(string)$v]);};
    $set(0);$off=$read();
    $staffIds=fn($c)=>array_map(fn($r)=>(int)substr($r['id'],6),array_values(array_filter($c['resources'],fn($r)=>$r['type']==='staff')));
    ok('关闭时原员工列完整保留',!array_diff($ids,$staffIds($off)));
    ok('关闭时未排班可预约',$booking->isStaffSelectableForReservation($ids[2],$store,$day,'11:00',60));
    $set(1);$on=$read();
    ok('开启只显示上班及部分休息人员',array_values(array_intersect($ids,$staffIds($on)))===[$ids[0],$ids[3]]);
    ok('日历与统一上班名单一致',$availability->workingStaffIds($ids,$store,$day)===[$ids[0],$ids[3]]);
    ok('只选日期也拒绝未排班',!$booking->isStaffSelectableForReservation($ids[2],$store,$day,''));
    ok('午休不隐藏整个人且休息结束可约',$booking->isStaffSelectableForReservation($ids[3],$store,$day,'13:00',60));
    ok('午休期间不可约',!$booking->isStaffSelectableForReservation($ids[3],$store,$day,'12:00',60));
    ok('预约图过滤不改变房间及预约块',$off['blocks']===$on['blocks']&&array_values(array_filter($off['resources'],fn($r)=>str_starts_with($r['type'],'room')||$r['type']==='unassigned_room'))===array_values(array_filter($on['resources'],fn($r)=>str_starts_with($r['type'],'room')||$r['type']==='unassigned_room')));
    ok('未分配列继续保留',in_array('staff:unassigned',array_column($on['resources'],'id'),true));
    $operator=new CashierV3OperatorScope($store,$ids[0],'',CashierV3ScopeResolver::TENANT_SCOPE_ID);
    foreach (['11:00','12:00','13:00'] as $clock) {
        $options=$craftsmen->invoke(null,$operator,['appointmentTime'=>$day.'T'.$clock,'appointmentEndAt'=>$day.'T'.date('H:i',$ts($clock)+3600)]);
        $map=array_column($options,'selectable','id');
        foreach ($ids as $id) ok('门店商家选人同一预约校验-'.$clock.'-'.$id,$map[$id]===$booking->isStaffSelectableForReservation($id,$store,$day,$clock,60));
    }
    $options=$craftsmen->invoke(null,$operator,['appointmentDate'=>$day]);$map=array_column($options,'selectable','id');
    ok('未选具体时段也不能选择休息或未排班人员',$map[$ids[0]]&&!$map[$ids[1]]&&!$map[$ids[2]]&&$map[$ids[3]]);
    try {(new CashierV3ReservationLifecycleServices())->assertStaffAvailabilityInTx($store,0,[$ids[2]],$ts('11:00'),$ts('12:00'));throw new RuntimeException('提交未排班未拒绝');}
    catch (\app\services\cashier\v3\CashierV3CommandException $e) {ok('最终事务拒绝未排班人员',true);}
    $set(0);ok('关回后名单恢复原样',$read()===$off);
    ok('关回后未排班恢复原预约规则',$booking->isStaffSelectableForReservation($ids[2],$store,$day,'11:00',60));
    ok('相同员工其他日期无排班不混入当天',$availability->workingStaffIds($ids,$store,'2030-08-13')===[]);
    echo json_encode(['passed'=>count($checks),'checks'=>$checks,'rolledBack'=>true],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),PHP_EOL;
} finally {Db::rollback();}
