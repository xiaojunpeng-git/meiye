<?php
declare(strict_types=1);
/** Real MySQL5.7 session-only fixtures: apply/retry/rollback and shared asset authority. */
require getcwd().'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\services\report\BasicEntitlementReportServices;
$config=config('database.connections.mysql');
if(!in_array($config['hostname'],['mysql','mohe-mysql'],true)||$config['database']!=='ruihao')throw new RuntimeException('LOCAL_ONLY');
define('RELATION_REPAIR_LIBRARY_ONLY',true);
require getcwd().'/database/upgrades/2026-10-30-010报表会员关系补齐/execute.php';
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,(string)$e);exit(1);});
Db::query('SELECT DATABASE() AS db');$db=Db::connect()->getPdo();$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$member=Db::name('user')->where('status',1)->where('is_del',0)->find();
$store=Db::name('system_store')->where('id','>',0)->find();
$created=[];$checks=0;
function ensureRelation(bool $ok,string $why):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($why);}
function rejectRelation(callable $call,string $why):void{try{$call();}catch(Throwable $e){ensureRelation(strpos($e->getMessage(),$why)!==false,$e->getMessage());return;}throw new RuntimeException('expected rejection '.$why);}
$dir=sys_get_temp_dir().'/r4-relation-'.bin2hex(random_bytes(5));mkdir($dir,0700);$receipt=$dir.'/receipt.json';
$persist=static function(string $path,array $r):void{file_put_contents($path,json_encode($r,JSON_THROW_ON_ERROR));};
try{
 foreach(['user','store_user','user_card_holder','system_store']as $name){$ddl=Db::query('SHOW CREATE TABLE eb_'.$name)[0]['Create Table'];Db::execute(str_replace('CREATE TABLE `eb_'.$name.'`','CREATE TEMPORARY TABLE `eb_'.$name.'`',$ddl));$created[]=$name;}
 $store['id']=4;$store['name']='关系修复本地店';Db::name('system_store')->insert($store);
 foreach([975,6787,9052,9647]as $uid){$u=$member;$u['uid']=$uid;$u['phone']='199'.str_pad((string)$uid,8,'0',STR_PAD_LEFT);$u['belong_store_id']=4;$u['status']=1;$u['is_del']=0;$u['delete_time']=null;$u['now_money']='13.05';$u['ben_money']='10.01';$u['give_money']='3.04';Db::name('user')->insert($u);}
 $input=['_report_scope'=>['mode'=>'stores','employee_id'=>0,'operator_id'=>1,'tenant_id'=>'0']];
 $read=static function()use($input):array{return(new BasicEntitlementReportServices())->read(BasicEntitlementReportServices::CUSTOMER_RIGHTS_SOURCE,[4],$input);};
 $before=relationRepairState($db,false);$plan=report010MemberRelationPlan($before['members'],$before['relations']);$hash=$plan['before_sha256'];
 ensureRelation(count($read()['rows'])===0,'missing active relation excluded by real authority');
 rejectRelation(static function()use($db,$receipt,$persist){relationRepairApply($db,str_repeat('0',64),$receipt,$persist);},'PLAN_CHANGED');
 rejectRelation(static function()use($db,$hash,$receipt){relationRepairApply($db,$hash,$receipt,static function(){throw new RuntimeException('simulated disk failure');});},'disk failure');
 ensureRelation(relationRepairState($db,false)===$before,'receipt failure rolls all inserts back');
 $applied=relationRepairApply($db,$hash,$receipt,$persist);ensureRelation($applied['inserted']===4,'only four planned inserts');
 $readResult=$read();ensureRelation(count($readResult['rows'])===4,'all four current-member balances visible through native authority');
 ensureRelation(array_sum(array_column($readResult['rows'],'balance_amount'))===52.2,'balances read unchanged');
 ensureRelation(relationRepairState($db,false)['members']===$before['members'],'no member ownership/status changes');
 $again=relationRepairApply($db,$hash,$receipt,$persist);ensureRelation($again['status']==='already_applied','retry no duplicate');
 Db::name('store_user')->where('uid',975)->update(['status'=>0]);
 rejectRelation(static function()use($db,$receipt){relationRepairRollback($db,$receipt);},'ROLLBACK_STATE_CHANGED');
 Db::name('store_user')->where('uid',975)->update(['status'=>1]);
 $r=relationRepairRollback($db,$receipt);ensureRelation($r['removed']===4,'rollback only own inserts');
 ensureRelation(relationRepairState($db,false)===$before,'exact original state restored');
 ensureRelation(count($read()['rows'])===0,'authority again excludes without links');
 ensureRelation(relationRepairRollback($db,$receipt)['status']==='already_before','rollback retry no writes');
 echo json_encode(['status'=>'PASS','checks'=>$checks,'production_writes'=>0,'fixtures'=>'temporary MySQL tables only']),PHP_EOL;
}finally{
 if($db->inTransaction())$db->rollBack();foreach(array_reverse($created)as $name)Db::execute('DROP TEMPORARY TABLE IF EXISTS eb_'.$name);
 if(is_file($receipt))unlink($receipt);rmdir($dir);
}
