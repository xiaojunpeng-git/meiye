<?php
declare(strict_types=1);
/** 真实会员创建入口覆盖排班拒绝/恢复；仅本地，所有预约与排班回滚。 */
error_reporting(E_ALL & ~E_DEPRECATED);
if(!function_exists('swoole_cpu_num')){function swoole_cpu_num():int{return 1;}}
if(!class_exists('Swoole\\Table'))eval('namespace Swoole; final class Table{const TYPE_STRING=7;const TYPE_INT=1;}');
require '/var/www/html/vendor/autoload.php';
use think\facade\Db;
use app\services\cashier\v3\reservation\MemberV3ReservationServices;
use app\services\store\roster\StaffRosterAvailability;
$app=new think\App('/var/www/html/');$app->initialize();
set_exception_handler(static function(Throwable $e):void{file_put_contents('php://stderr',get_class($e).':'.$e->getMessage().PHP_EOL);exit(1);});
$fixture=Db::name('store_order')->alias('o')->join('store_order_cart_info c','c.oid=o.id')->join('organization_store os','os.store_id=o.store_id')
 ->where('o.paid',1)->where('o.is_del',0)->where('o.is_system_del',0)->where('o.is_user_del',0)->where('o.refund_status',0)->where('o.terminal_action',0)
 ->where('c.cart_type',2)->where('c.product_type',6)->where('c.write_surplus_times','>=',2)->field('o.id,o.uid,o.store_id,c.id AS detail_id')->order('o.id desc')->find();
if(!$fixture)throw new RuntimeException('缺少本地已购权益夹具');
$staff=Db::name('system_store_staff')->where('store_id',$fixture['store_id'])->where('status',1)->where('is_del',0)->order('id')->find();
if(!$staff)throw new RuntimeException('缺少夹具门店员工');
$id=(int)$staff['id'];$store=(int)$fixture['store_id'];$date='2030-06-15';
$body=['cart_num'=>1,'reservation_time'=>$date,'reservation_start'=>'10:00','reservation_end'=>'11:00','custom_form'=>[[]],'cart_info_id'=>$fixture['detail_id'],'service_staff_id'=>$id,'service_duration_minutes'=>60,'addon_items'=>[],'sync_all'=>[],'store_id'=>$store,'mark'=>'ROSTER-ROLLBACK'];
Db::startTrans();
Db::name('system_config')->where('menu_name','reservation_schedule_manage')->where('is_store',0)->update(['value'=>'1']);
try{
 Db::name('system_store_staff')->where('id',$id)->update(['is_reservable'=>1,'off_work_time'=>'','cashier_craftsman_enabled'=>1]);
 Db::name('store_staff_schedule')->whereIn('staff_id',StaffRosterAvailability::aliases([$id]))->where('schedule_date',$date)->delete();
 $rest=(int)Db::name('store_staff_schedule')->insertGetId(['store_id'=>$store,'staff_id'=>$id,'schedule_date'=>$date,'schedule_type'=>0]);
 try{(new MemberV3ReservationServices())->create((int)$fixture['uid'],(int)$fixture['id'],$body);throw new RuntimeException('会员预约未拒绝休息');}
 catch(\app\services\cashier\v3\CashierV3CommandException $e){if(strpos($e->getMessage(),'不可预约')===false)throw $e;}
 Db::name('store_staff_schedule')->where('id',$rest)->delete();
 try{(new MemberV3ReservationServices())->create((int)$fixture['uid'],(int)$fixture['id'],$body);throw new RuntimeException('会员预约未拒绝未排班');}
 catch(\app\services\cashier\v3\CashierV3CommandException $e){if(strpos($e->getMessage(),'不可预约')===false)throw $e;}
 Db::name('store_staff_schedule')->insert(['store_id'=>$store,'staff_id'=>$id,'schedule_date'=>$date,'schedule_type'=>1,'start_time'=>'10:00','end_time'=>'18:00']);
 $result=(new MemberV3ReservationServices())->create((int)$fixture['uid'],(int)$fixture['id'],$body);
 if(empty($result['reservationId']))throw new RuntimeException('上班后会员预约未成功');
 echo json_encode(['passed'=>3,'checks'=>['真实会员创建拒绝休息员工','真实会员创建拒绝未排班','排班上班后会员预约成功'],'rolledBack'=>true],JSON_UNESCAPED_UNICODE),PHP_EOL;
}finally{Db::rollback();}
