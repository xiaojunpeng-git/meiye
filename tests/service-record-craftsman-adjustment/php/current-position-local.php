<?php
/** Local-only transaction rehearsal of entry -> actual command -> persisted facts -> reopen -> replay. */
require '/var/www/html/vendor/autoload.php';(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
use app\services\order\PlatformOrderCenterActionServices;
set_exception_handler(function(Throwable $e){echo $e->getMessage()."\n";exit(1);});
if(config('database.connections.mysql.hostname')!=='mysql'||config('database.connections.mysql.database')!=='ruihao')throw new RuntimeException('LOCAL_ONLY');
function ok6($v,$s){if(!$v)throw new RuntimeException($s);echo "PASS $s\n";}
$beforeCount=Db::name('cashier_v3_service_record_adjustment_operation')->count();
Db::startTrans();try{
$admin=Db::name('system_admin')->where('level',0)->where('status',1)->where('is_del',0)->where('admin_type',0)->find();$svc=new PlatformOrderCenterActionServices();
$invoke=function($action,$data=[])use($svc,$admin){return $svc->dispatch((int)$admin['id'],$data+['action'=>$action,'clientSessionId'=>'SESSION-6ec0ea15-9116-4e12-9a12-a8ea3b3c7f81']);};
$source=null;$entry=null;
foreach(Db::name('cashier_v3_entitlement_service_fact')->where('service_status','completed')->order('id desc')->limit(300)->select()->toArray()as$row){if(!Db::name('cashier_v3_performance_fact')->where('tenant_id',$row['tenant_id'])->where('checkout_request_id',$row['checkout_request_id'])->where('source_line_id',$row['source_line_id'])->where('performance_type','consumption_performance_recorded')->where('status','effective')->count())continue;$r=$invoke('open-service-record-craftsman-adjustment',['serviceFactId'=>$row['id']]);$e=$r['data']['serviceRecordCraftsmanAdjustment']??[];if(count($e['craftsmenCandidates']??[])>=2&&count($e['allocations']??[])>0){$source=$row;$entry=$e;break;}}
ok6((bool)$source,'existing service fixture');
$selected=array_slice($entry['craftsmenCandidates'],0,2);$positions=Db::name('position')->where('status',1)->limit(2)->column('id');ok6(count($positions)===2,'two current job positions');
$excludedPositions=sys_config('cashier_service_excluded_position_ids',[]);ok6(count($excludedPositions)>0,'local fixture has current excluded job');$positions[1]=(int)$excludedPositions[0];if($positions[0]===$positions[1])$positions[0]=(int)Db::name('position')->where('id','<>',$positions[1])->where('status',1)->value('id');
foreach($selected as$i=>$row){$staffId=$row['staffId']??$row['id'];Db::name('staff_job_position')->where('staff_id',$staffId)->where('status',1)->where('is_del',0)->where('end_time',0)->update(['position_id'=>$positions[$i]]);Db::name('position')->where('id',$positions[$i])->update(['performance_independent'=>1]);}
// Change only local fixture consumption facts; rollback below leaves original business facts intact.
$q=Db::name('cashier_v3_performance_fact')->where('tenant_id',$source['tenant_id'])->where('checkout_request_id',$source['checkout_request_id'])->where('source_line_id',$source['source_line_id'])->where('performance_type','consumption_performance_recorded')->where('status','effective');
$ids=(clone $q)->order('id desc')->column('id');ok6(count($ids)>0,'project consumption authority');
(clone $q)->update(['amount_cents'=>0]);Db::name('cashier_v3_performance_fact')->where('id',$ids[0])->update(['amount_cents'=>7700]);
Db::name('system_store_staff')->where('id',$selected[0]['staffId']??$selected[0]['id'])->update(['craftsman_performance_type'=>'commission']);
$opened=$invoke('open-service-record-craftsman-adjustment',['serviceFactId'=>$source['id']]);$e=$opened['data']['serviceRecordCraftsmanAdjustment'];$alloc=[];
foreach($selected as$row){$staffId=$row['staffId']??$row['id'];$candidate=null;foreach($e['craftsmenCandidates']as$c)if(($c['staffId']??$c['id'])===$staffId)$candidate=$c;ok6((bool)$candidate,'current candidate');$alloc[]=['staffId'=>$staffId,'allocationAmountCents'=>3850,'laborWeight'=>100,'laborFeeCents'=>500,'projectCount'=>'1','allocationGroupKey'=>$candidate['allocationGroupKey']];}
$contexts=[];foreach($opened['versions']as$v)$contexts[]=['kind'=>$v['kind'],'id'=>$v['id'],'expectedVersion'=>$v['version']];
$body=['serviceFactId'=>$source['id'],'recordVersion'=>$e['recordVersion'],'allocations'=>$alloc,'reason'=>'DATA-R6 local rollback','command'=>['action'=>'adjust-service-record-craftsmen','idempotencyKey'=>'CMD-6ec0ea15-9116-4e12-9a12-a8ea3b3c7f82','contexts'=>$contexts]];
$result=$invoke('adjust-service-record-craftsmen',$body);ok6(($result['result']['status']??'')==='success','real command '.json_encode($result['result']??[]));
$op=Db::name('cashier_v3_service_record_adjustment_operation')->where('service_fact_id',$source['id'])->order('id desc')->find();$saved=json_decode($op['after_snapshot_json'],true);ok6(array_column($saved,'allocationAmountCents')===[7700,7700],'saved 77/77 despite old 38.5/38.5 client amounts');
ok6($saved[0]['laborFeeCents']===0,'current commission type ignores forbidden hand fee like checkout');
ok6($saved[1]['projectCount']==='0'&&$saved[1]['laborFeeCents']===0,'current excluded job writes zero projects and hand fee');
$reopened=$invoke('open-service-record-craftsman-adjustment',['serviceFactId'=>$source['id']]);ok6(array_column($reopened['data']['serviceRecordCraftsmanAdjustment']['allocations'],'allocationAmountCents')===[7700,7700],'reopen matches saved amounts');
ok6($reopened['data']['serviceRecordCraftsmanAdjustment']['allocations'][1]['projectCount']==='0','current excluded job reopens with zero projects');
(clone $q)->update(['status'=>'r6_fixture_hidden']);
$legacy=$invoke('open-service-record-craftsman-adjustment',['serviceFactId'=>$source['id']]);
ok6($legacy['data']['serviceRecordCraftsmanAdjustment']['allocationTotalAmountCents']===7700,'missing consumption facts retain frozen project base, not 154 yuan labor sum');
$count=Db::name('cashier_v3_service_record_adjustment_operation')->count();$replay=$invoke('adjust-service-record-craftsmen',$body);ok6(Db::name('cashier_v3_service_record_adjustment_operation')->count()===$count,'retry does not duplicate adjustment');
}finally{Db::rollback();}
ok6(Db::name('cashier_v3_service_record_adjustment_operation')->count()===$beforeCount,'local rehearsal rolled back all writes');
