<?php
declare(strict_types=1);
/** 两个真实MySQL连接验证预约和排班锁互斥；不提交任何业务记录。 */
error_reporting(E_ALL & ~E_DEPRECATED);
require (getenv('CASHIER_V3_BACKEND') ?: '/var/www/html') . '/vendor/autoload.php';
use think\facade\Db;
use app\services\store\roster\StaffRosterAvailability;
$app=new think\App('/var/www/html/');$app->initialize();
set_exception_handler(static function(Throwable $e): void { file_put_contents('php://stderr',$e->getMessage().PHP_EOL);exit(1); });
$c=config('database.connections.mysql');
$dsn='mysql:host='.$c['hostname'].';port='.$c['hostport'].';dbname='.$c['database'].';charset=utf8mb4';
$second=new PDO($dsn,$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$second->exec('SET SESSION innodb_lock_wait_timeout=1');
$id=(int)Db::name('system_store_staff')->where('status',1)->where('is_del',0)->value('id');
Db::startTrans();
try {
    StaffRosterAvailability::lock([$id]);$second->beginTransaction();
    try {$second->query('SELECT id FROM '.$c['prefix'].'system_store_staff WHERE id='.$id.' FOR UPDATE');throw new RuntimeException('员工锁未互斥');}
    catch(PDOException $e){if((int)($e->errorInfo[1]??0)!==1205)throw $e;}
    finally{if($second->inTransaction())$second->rollBack();}
}finally{Db::rollback();}
$second->beginTransaction();$second->query('SELECT id FROM '.$c['prefix'].'system_store_staff WHERE id='.$id.' FOR UPDATE');$second->rollBack();
echo json_encode(['passed'=>2,'checks'=>['并发连接不能绕过同一员工锁','事务回滚后锁释放'],'rolledBack'=>true],JSON_UNESCAPED_UNICODE),PHP_EOL;
// 在主连接先建立RR快照，再由另一连接提交休息/预约，验证拿到员工锁后使用当前读。
$date='2031-06-11';$store=(int)Db::name('system_store_staff')->where('id',$id)->value('store_id');$mark='ROSTER-RR-'.bin2hex(random_bytes(8));
$restId=0;$reservationId=0;
try {
    Db::startTrans();Db::name('system_config')->where('menu_name','reservation_schedule_manage')->where('is_store',0)->update(['value'=>'1']);Db::name('store_staff_schedule')->where('staff_id',$id)->count();
    $stmt=$second->prepare('INSERT INTO '.$c['prefix'].'store_staff_schedule(store_id,staff_id,schedule_date,schedule_type,mark) VALUES(?,?,?,?,?)');$stmt->execute([$store,$id,$date,0,$mark]);$restId=(int)$second->lastInsertId();
    StaffRosterAvailability::lock([$id]);
    if((new StaffRosterAvailability())->allows($id,$store,strtotime($date.' 10:00'),strtotime($date.' 11:00')))throw new RuntimeException('RR旧快照漏掉刚提交休息');
    Db::rollback();$second->exec('DELETE FROM '.$c['prefix'].'store_staff_schedule WHERE id='.$restId);$restId=0;
    Db::startTrans();Db::name('system_config')->where('menu_name','reservation_schedule_manage')->where('is_store',0)->update(['value'=>'1']);Db::name('cashier_v3_reservation')->count();
    $second->beginTransaction();$stmt=$second->prepare('INSERT INTO '.$c['prefix'].'cashier_v3_reservation(tenant_id,lifecycle_generation,reservation_id,reservation_no,store_id,status,appointment_start_at,appointment_end_at,business_date) VALUES(?,?,?,?,?,?,?,?,?)');
    $stmt->execute([\app\services\cashier\v3\CashierV3ScopeResolver::TENANT_SCOPE_ID,\app\services\cashier\v3\reservation\CashierV3ReservationLifecycleServices::GENERATION,$mark,$mark,$store,'UNSTARTED',strtotime($date.' 10:00'),strtotime($date.' 11:00'),$date]);$reservationId=(int)$second->lastInsertId();
    $stmt=$second->prepare('INSERT INTO '.$c['prefix'].'cashier_v3_reservation_staff_schedule(tenant_id,reservation_id,staff_id) VALUES(?,?,?)');$stmt->execute([\app\services\cashier\v3\CashierV3ScopeResolver::TENANT_SCOPE_ID,$reservationId,$id]);$second->commit();
    StaffRosterAvailability::lock([$id]);
    if(!StaffRosterAvailability::reservations([$id],strtotime($date),strtotime($date.' +1 day')))throw new RuntimeException('RR旧快照漏掉刚提交预约');
    $availability=app()->make(\app\services\store\StoreReservationStaffServices::class);
    if($availability->isStaffAvailable($id,$store,strtotime($date.' 10:00'),60))throw new RuntimeException('二次旧人员关系查询漏掉刚提交预约');
    echo json_encode(['passed'=>3,'checks'=>['旧快照不漏新休息','旧快照不漏新预约','预约人员映射不回读旧快照']],JSON_UNESCAPED_UNICODE),PHP_EOL;
} finally {
    if(\app\services\cashier\v3\CashierV3TransactionGuard::isInTransaction())Db::rollback();
    if($second->inTransaction())$second->rollBack();
    if($restId)$second->exec('DELETE FROM '.$c['prefix'].'store_staff_schedule WHERE id='.$restId);
    if($reservationId){$second->exec('DELETE FROM '.$c['prefix'].'cashier_v3_reservation_staff_schedule WHERE reservation_id='.$reservationId);$second->exec('DELETE FROM '.$c['prefix'].'cashier_v3_reservation WHERE id='.$reservationId);}
}
