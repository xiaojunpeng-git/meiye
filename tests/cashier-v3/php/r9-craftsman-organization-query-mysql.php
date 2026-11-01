<?php
/** Real local MySQL regression. Synthetic organizations/people roll back; never runs on a remote instance. */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
set_exception_handler(function($e){file_put_contents('php://stderr',$e->getMessage());exit(1);});
use think\facade\Db;
use app\services\cashier\v3\member\CashierV3OtherCraftsmanQueryServices;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3CommandException;
if (getenv('R9_CRAFTSMAN_LOCAL_TEST') !== '1' || config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') throw new RuntimeException('Explicit local Docker fixture only');
$checks=0;
function r9Assert($ok,$message){global $checks;if(!$ok)throw new RuntimeException('FAIL '.$message);$checks++;echo "PASS $message\n";}
Db::startTrans();
try {
    $root=(int)Db::name('organization')->insertGetId(['name'=>'R9集团','pid'=>0,'is_del'=>0]);
    $child=(int)Db::name('organization')->insertGetId(['name'=>'R9事业部','pid'=>$root,'is_del'=>0]);
    $empty=(int)Db::name('organization')->insertGetId(['name'=>'R9直属组织','pid'=>$root,'is_del'=>0]);
    $outside=(int)Db::name('organization')->insertGetId(['name'=>'R9外部集团','pid'=>0,'is_del'=>0]);
    $current=(int)Db::name('system_store')->insertGetId(['name'=>'R9当前店','is_del'=>0]);
    $other=(int)Db::name('system_store')->insertGetId(['name'=>'R9其他店','is_del'=>0]);
    $foreign=(int)Db::name('system_store')->insertGetId(['name'=>'R9外部店','is_del'=>0]);
    foreach([[$root,$current],[$child,$other],[$outside,$foreign]]as$pair)Db::name('organization_store')->insert(['org_id'=>$pair[0],'store_id'=>$pair[1]]);
    $nextPhone=mt_rand(10000000,90000000);
    $make=function($name,$store,$status=1)use(&$nextPhone){$e=(int)Db::name('employee')->insertGetId(['name'=>$name,'phone'=>'198'.(++$nextPhone),'status'=>1,'is_del'=>0,'employment_type_code'=>'internal','employment_type_version'=>1]);$s=(int)Db::name('system_store_staff')->insertGetId(['employee_id'=>$e,'store_id'=>$store,'staff_name'=>$name,'account'=>'R9-'.$e,'status'=>$status,'is_del'=>0,'cashier_craftsman_enabled'=>1]);return[$e,$s];};
    [$xia,$xiaStaff]=$make('陈晓霞（小霞）',$other);
    Db::name('system_store_staff')->insert(['employee_id'=>$xia,'store_id'=>$current,'staff_name'=>'R9另一个任职','account'=>'R9-DUP-'.$xia,'status'=>1,'is_del'=>0]);
    for($i=0;$i<21;$i++)$make('R9分页'.$i,$other);
    [$retired]=$make('R9离职',$other,0); [$foreignEmployee]=$make('R9外部员工',$foreign);
    $direct=(int)Db::name('employee')->insertGetId(['name'=>'R9直属员工','phone'=>'198'.(++$nextPhone),'status'=>1,'is_del'=>0,'employment_type_code'=>'internal','employment_type_version'=>1]);
    Db::name('organization_employee')->insert(['org_id'=>$empty,'employee_id'=>$direct,'status'=>1,'is_del'=>0,'source'=>'r9-local-fixture']);
    $op=new CashierV3OperatorScope($current,1,(string)$root,'0');$service=new CashierV3OtherCraftsmanQueryServices();
    $query=function($node=null,$keyword='',$page=1,$options=false)use($service,$op){return$service->query(['selectorContext'=>['organizationNode'=>$node,'organizationOptionsOnly'=>$options],'keyword'=>$keyword,'page'=>$page,'pageSize'=>20],$op);};
    $meta=$query(null,'',1,true);r9Assert($meta['records']===[]&&$meta['total']===0,'metadata does not eagerly load people');
    $nodes=[];$walk=function($xs)use(&$walk,&$nodes){foreach($xs as$x){$nodes[]=$x;$walk($x['children']??[]);}};$walk($meta['organizationTree']);
    r9Assert(count(array_filter($nodes,fn($x)=>($x['node_type']??'')!=='store'&&(int)$x['id']===$empty))===1,'empty organization with direct personnel retained');
    r9Assert(!array_filter($nodes,fn($x)=>($x['node_type']??'')!=='store'&&(int)$x['id']===$outside),'other group nodes excluded');
    $found=$query(null,'小霞');r9Assert($found['total']===1&&(int)$found['records'][0]['employeeId']===$xia,'nickname finds cross-store employee without direct membership');
    r9Assert($query(null,'R9-'.$xia)['total']===1,'staff-account alias search retained');
    $first=$query(['type'=>'store','id'=>$other]);$second=$query(['type'=>'store','id'=>$other],'',2);
    r9Assert($first['total']===22&&count($first['records'])===20&&count($second['records'])===2,'server pagination 20 plus remainder and exact total');
    r9Assert(!array_intersect(array_column($first['records'],'employeeId'),array_column($second['records'],'employeeId')),'stable pages do not overlap');
    r9Assert($query(['type'=>'org','id'=>$root])['total']===23,'root includes descendants and deduplicates multiple employments');
    $one=$query(['type'=>'org','id'=>$empty]);r9Assert($one['total']===1&&(int)$one['records'][0]['employeeId']===$direct,'organization direct personnel still selectable');
    r9Assert($query(['type'=>'store','id'=>$other],'不存在')['total']===0,'keyword and node combine');
    r9Assert($query(['type'=>'store','id'=>$other],'',99)['page']===2,'shrinking results clamp page bounds');
    foreach([['type'=>'org','id'=>$outside],['type'=>'store','id'=>$foreign],['type'=>'org','id'=>0],['type'=>'sql','id'=>1],['type'=>'org','id'=>[]]]as$node){$blocked=false;try{$query($node);}catch(CashierV3CommandException$e){$blocked=true;}r9Assert($blocked,'untrusted node cannot widen group');}
    r9Assert(count($service->lockedMembership($xia,$current)['staffIds'])===2,'final membership recheck accepts real cross-store employment');
    $dataScope=new \app\services\cashier\v3\CashierV3DataScopeContext(1,1,$current,'0',(string)$root,[$current],'stores',[],false,'','r9-local-test',[],[]);
    $public=(new \app\services\cashier\v3\member\CashierV3QueryEntitySelectorServices())->query(['entityType'=>'person','selectorContext'=>['scope'=>'cashier_other_craftsmen','organizationNode'=>['type'=>'store','id'=>$other]],'keyword'=>'小霞'],$op,$dataScope);
    r9Assert($public['total']===1&&$public['records'][0]['employeeId']===$xia,'public entity-selector dispatches node scope to shared craftsman reader');
    $saleSelector=new \app\services\cashier\v3\member\CashierV3QueryEntitySelectorServices();
    $salePayload=['entityType'=>'person','selectorContext'=>['scope'=>'cashier_other_salespeople','organizationNode'=>['type'=>'store','id'=>$other]],'keyword'=>'小霞'];
    $sale=$saleSelector->query($salePayload,$op,$dataScope);
    r9Assert($sale['total']===1&&$sale['records'][0]['salespersonEligible']&&!$sale['records'][0]['craftsmanEligible'],'salesperson node search returns role-correct cross-store candidate');
    $lockedSale=$saleSelector->lockOrganizationSalesperson($sale['records'][0]['id'],$op);
    r9Assert($lockedSale['employee_id']===$xia&&$lockedSale['store_id']===$current,'sales writer locks real cross-store membership');
    Db::name('employee')->where('id',$xia)->update(['employment_type_code'=>null]);
    r9Assert($saleSelector->query($salePayload,$op,$dataScope)['total']===0,'sales query excludes legacy null employment type');
    Db::name('employee')->where('id',$xia)->update(['employment_type_code'=>'internal']);
    $profiles=new \app\services\cashier\v3\checkout\provider\CashierV3StaffProfileProvider(new \app\services\cashier\v3\checkout\provider\CashierV3EmployeeTypeAuthority());
    $virtual=\app\services\cashier\v3\CashierV3PersonnelIdentity::organizationStaffId($xia);
    $profile=$profiles->lockProfileSnapshotInTx($virtual,$op,$dataScope,'other');
    r9Assert($profile['employeeId']===$xia&&$profile['storeId']===$current&&$profile['personnelSource']==='other','actual save-profile provider accepts cross-store identity in current checkout');
    // Run the real authority-discovery method: profile authorization must not change its fingerprint contract.
    $settlement=(new ReflectionClass(\app\services\cashier\v3\checkout\CashierV3DirectSnapshotEntitlementSettlementServices::class))->newInstanceWithoutConstructor();
    $discover=new ReflectionMethod($settlement,'organizationStaffDiscoveryVersion');$discover->setAccessible(true);
    r9Assert($discover->invoke($settlement,$dataScope,$virtual)===$profile['staffVersion'],'discovery and locked profile agree after adding membership protection');
    $again=$profiles->lockProfileSnapshotInTx($virtual,$op,$dataScope,'other');
    r9Assert($again['staffVersion']===$profile['staffVersion'],'unchanged selected employee does not create false concurrency conflicts');
    $movingEmployee=(int)Db::name('system_store_staff')->where('store_id',$other)->where('employee_id','<>',$xia)->value('employee_id');
    Db::name('organization_store')->where('store_id',$other)->update(['org_id'=>$outside]);
    $blocked=false;try{$query(['type'=>'store','id'=>$other]);}catch(CashierV3CommandException$e){$blocked=true;}r9Assert($blocked,'moved store cannot reuse cached old group scope');
    $blocked=false;try{$service->lockedMembership($movingEmployee,$current);}catch(CashierV3CommandException$e){$blocked=true;}r9Assert($blocked,'final save rejects employee after store moved to other group');
    Db::name('organization_store')->where('store_id',$other)->update(['org_id'=>$child]);
    Db::name('system_store_staff')->where('employee_id',$xia)->update(['status'=>0]);
    r9Assert($query(['type'=>'org','id'=>$root],'小霞')['total']===0,'offboarded employments disappear from search');
    $blocked=false;try{$service->lockedMembership($xia,$current);}catch(CashierV3CommandException$e){$blocked=true;}r9Assert($blocked,'offboarded employee cannot reuse old candidate at save');
    $blocked=false;try{$profiles->lockProfileSnapshotInTx($virtual,$op,$dataScope,'other');}catch(CashierV3CommandException$e){$blocked=($e->getDetail()['reason']??'')==='other_craftsman_membership_inactive';}r9Assert($blocked,'actual save-profile provider rejects offboarded candidate');
    $blocked=false;try{$service->lockedMembership($foreignEmployee,$current);}catch(CashierV3CommandException$e){$blocked=true;}r9Assert($blocked,'final membership rejects forged foreign-group employee');
} finally { Db::rollback(); }
r9Assert(!Db::name('employee')->where('id',$xia)->count(),'all business fixtures rolled back');
echo "TOTAL $checks checks passed\n";
