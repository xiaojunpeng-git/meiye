<?php
declare(strict_types=1);
/** Synthetic local fixture: verify refresh reads, preserved order, compatibility and exclusion. */
require getcwd().'/vendor/autoload.php';
(new \think\App())->initialize();
use think\facade\Db;
use think\facade\Config;
use app\services\cashier\v3\member\CashierV3MemberExclusiveSelectionServices;
$c=config('database');
if(getcwd()!=='/var/www/html'||!in_array($c['connections']['mysql']['hostname'],['mysql','mohe-mysql'],true))throw new RuntimeException('LOCAL_ONLY');
$c['connections']['mysql']['database']='codex_rh_r2_manager_test';Config::set($c,'database');
$expected=[1=>[1,2],2=>[3,1,2],3=>[3,1,2],4=>[1,2],5=>[1,2],6=>[],7=>[]];
$reader=new CashierV3MemberExclusiveSelectionServices();
foreach($expected as$uid=>$ids){
    $actual=array_map('intval',array_column($reader->records($uid),'staff_id'));
    if($actual!==$ids)throw new RuntimeException('REFRESH_SELECTION_'.$uid);
}
$old=Db::name('member_exclusive_service_selection')->where('member_id',2)->where('staff_id',3)->find();
if((int)$old['sort_order']!==100||(int)$old['version']!==1)throw new RuntimeException('ORIGINAL_CHANGED');
if((int)Db::name('member_exclusive_service_selection_change')->count()!==4)throw new RuntimeException('AUDIT_COUNT');
if((int)Db::name('member_exclusive_service_change')->count()!==2)throw new RuntimeException('PRIMARY_AUDIT_COUNT');
if((int)Db::name('employee_change_log')->count()!==2)throw new RuntimeException('STAFF_AUDIT_COUNT');
foreach([1,2]as$id){$staff=Db::name('system_store_staff')->where('id',$id)->find();if((int)$staff['cashier_salesperson_enabled']!==1||(int)$staff['cashier_craftsman_enabled']!==1)throw new RuntimeException('QUALIFICATION_NOT_ENABLED');}
echo "PASS: native refresh, preserve original order/version, deduplication, legacy fallback, inactive reactivation, no-manager/deleted exclusions, both qualifications, audit rows\n";
