<?php
/** 本地真库事务测试：只创建隔离夹具，所有调店/权限/回执及失败注入在 finally 回滚。 */
if (getenv('R51_LOCAL_TEST') !== '1') throw new RuntimeException('explicit local test flag required');
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';
$app = new think\App($backend . '/'); $app->initialize();
use think\facade\Db;
use app\services\store\StoreStaffPhoneTransferServices;
use app\services\employee\EmployeePersonCompleteWriteServices;
use app\services\organization\OrganizationOpsStatusServices;
use app\services\organization\StaffJobPositionServices;
use app\services\organization\JobPositionPolicyServices;
function checkTransfer($ok, $name) { if (!$ok) throw new RuntimeException('FAIL '.$name); echo 'PASS '.$name.PHP_EOL; }
function rejectTransfer(callable $fn, string $text) {
    try { $fn(); } catch (Throwable $e) {
        if (strpos($e->getMessage(), $text) === false) throw new RuntimeException('expected '.$text.', got '.$e->getMessage(),0,$e);
        checkTransfer(true, 'reject '.$text); return;
    }
    throw new RuntimeException('expected rejection '.$text);
}
function uuidTransfer(): string { $h=bin2hex(random_bytes(16)); return substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-a'.substr($h,17,3).'-'.substr($h,20); }
$ops = app()->make(OrganizationOpsStatusServices::class);
$stores=[];
foreach(Db::name('system_store')->where('is_del',0)->where('is_show',1)->select()->toArray() as $row) {
    if ($ops->isStoreBusinessEnabled((int)$row['id'])) $stores[]=(int)$row['id'];
}
checkTransfer(count($stores)>1, 'two enabled local stores');
$from=$stores[0]; $to=$stores[1];
$employeeTemplate=Db::name('employee')->where('status',1)->where('is_del',0)->find();
$staffTemplate=Db::name('system_store_staff')->where('status',1)->where('is_del',0)->find();
$jobs=app()->make(StaffJobPositionServices::class);
$svc=app()->make(StoreStaffPhoneTransferServices::class);
$employeeIds=[];
Db::startTrans();
try {
    $create=function (int $store, string $name) use ($employeeTemplate,$staffTemplate,&$employeeIds): array {
        $phone='199'.str_pad((string)random_int(1,99999999),8,'0',STR_PAD_LEFT);
        $e=$employeeTemplate; unset($e['id']); $e['name']='R51调店夹具'.$name; $e['phone']=$phone; $e['uid']=null; $e['auth_version']=1;
        $eid=(int)Db::name('employee')->insertGetId($e); $employeeIds[]=$eid;
        $s=$staffTemplate; unset($s['id'],$s['active_employee_id']); $s['employee_id']=$eid; $s['store_id']=$store;
        $s['staff_name']=$e['name']; $s['phone']=$phone; $s['uid']=0; $s['account']=''; $s['pwd']=''; $s['roles']='';
        $s['is_cashier']=0; $s['is_manager']=0; $s['is_butler']=0; $s['cashier_salesperson_enabled']=0; $s['cashier_craftsman_enabled']=1; $s['craftsman_performance_type']='labor';
        $sid=(int)Db::name('system_store_staff')->insertGetId($s);
        return [$eid,$sid,$phone];
    };
    [$operatorEmployee,$operatorId]=$create($to,'操作人');
    [$employee,$sourceId,$phone]=$create($from,'目标');
    $position=(int)Db::name('position')->where('status',1)->value('id');
    checkTransfer($position>0,'published position prerequisite');
    Db::name('staff_job_position')->insert(['employee_id'=>$employee,'store_id'=>$from,'staff_id'=>$sourceId,'position_id'=>$position,
        'status'=>1,'start_time'=>time()-10,'end_time'=>0,'reason'=>'R51夹具','is_del'=>0,'request_token'=>uuidTransfer(),
        'operator_id'=>$operatorId,'operator_name'=>'R51','add_time'=>time(),'update_time'=>time()]);
    $operator=['id'=>$operatorId,'real_name'=>'R51调店测试'];
    $confirm=function(array $candidate, ?string $token=null) use ($svc,$to,$operator): array {
        $token=$token?:uuidTransfer();
        return $svc->confirm(array_intersect_key($candidate,array_flip(['phone','employee_id','source_staff_id','expected_version','snapshot_hash']))+['request_token'=>$token],$to,$operator,['header_token'=>$token,'body_token'=>$token]);
    };
    // 真正经过门店会话注入和控制器适配，不只直调服务；防止测试漏掉 HTTP 边界字段冲突。
    $httpConfirm=function(array $input) use ($to,$operator): array {
        $request=new app\Request(); $request->storeId=$to;
        $request->withServer(['REQUEST_METHOD'=>'POST','REMOTE_ADDR'=>'127.0.0.1'])->withPost($input);
        return (new app\http\middleware\cashier\ForceStoreSessionMiddleware())->handle($request,function($request) use ($to,$operator) {
            $class=new ReflectionClass(app\controller\store\staff\StoreStaff::class);
            $controller=$class->newInstanceWithoutConstructor();
            foreach(['request'=>$request,'storeId'=>$to,'storeStaffId'=>$operator['id'],'storeStaffInfo'=>['staff_name'=>$operator['real_name']]] as $key=>$value) {
                $property=$class->getProperty($key); $property->setAccessible(true); $property->setValue($controller,$value);
            }
            return $controller->phoneTransferConfirm()->getData();
        });
    };
    rejectTransfer(fn()=>$svc->preview('199',$to,$operatorId),'11位');
    rejectTransfer(fn()=>$svc->preview($phone,$to,0),'登录任职');
    $candidate=$svc->preview($phone,$to,$operatorId);
    checkTransfer(count($candidate)===9 && $candidate['source_staff_id']===$sourceId && $candidate['to_store_id']===$to, 'exact phone minimal candidate forced current store');
    rejectTransfer(fn()=>$svc->confirm(['phone'=>$phone,'store_id'=>$from],$to,$operator,[]),'目标固定');
    rejectTransfer(fn()=>$svc->confirm(['phone'=>$phone],$to,$operator,[]),'先通过手机号码');
    Db::name('system_store_staff')->where('id',$sourceId)->update(['staff_name'=>'R51查询后修改']);
    rejectTransfer(fn()=>$confirm($candidate),'资料已变化');
    $candidate=$svc->preview($phone,$to,$operatorId);
    Db::name('staff_channel_entry')->insert(['employee_id'=>$employee,'store_id'=>$from,'staff_id'=>$sourceId,'channel'=>'mobile','status'=>0,'request_token'=>uuidTransfer(),'is_del'=>0,'operator_id'=>$operatorId,'operator_name'=>'R51','add_time'=>time(),'update_time'=>time()]);
    rejectTransfer(fn()=>$confirm($candidate),'资料已变化');
    $candidate=$svc->preview($phone,$to,$operatorId);
    $token=uuidTransfer();
    $httpInput=array_intersect_key($candidate,array_flip(['phone','employee_id','source_staff_id','expected_version','snapshot_hash']))+['request_token'=>$token];
    $forged=$httpConfirm($httpInput+['store_id'=>$to]);
    checkTransfer((int)$forged['status']!==200 && strpos($forged['msg'],'目标固定')!==false,'HTTP rejects client supplied store even when same target');
    $httpResult=$httpConfirm($httpInput);
    checkTransfer((int)$httpResult['status']===200,'middleware injected store does not block controller confirmation');
    $httpReplay=$httpConfirm($httpInput);
    checkTransfer((int)$httpReplay['status']===200 && $httpReplay['data']===$httpResult['data'],'HTTP original request replay returns same result');
    $result=['replay'=>false,'data'=>$httpResult['data']]; $target=(int)$result['data']['execute_staff_id'];
    checkTransfer($result['replay']===false && $target>0, 'first transfer completes');
    $source=Db::name('system_store_staff')->where('id',$sourceId)->find(); $new=Db::name('system_store_staff')->where('id',$target)->find();
    checkTransfer((int)$source['store_id']===$from && (int)$source['status']===0 && (int)$new['store_id']===$to && (int)$new['status']===1, 'old assignment retained and disabled, target unique active');
    checkTransfer((int)$new['cashier_salesperson_enabled']===0 && $new['craftsman_performance_type']==='labor', 'original service qualifications carried');
    checkTransfer((int)Db::name('staff_channel_entry')->where('staff_id',$target)->where('channel','mobile')->value('status')===0, 'disabled mobile entry remains disabled');
    checkTransfer((int)Db::name('employee')->where('id',$employee)->value('auth_version')>1, 'auth version increased and merchant sessions revoked');
    checkTransfer((int)Db::name('employee_mobile_auth_state')->where('employee_id',$employee)->value('merchant_session_epoch')>1,'merchant lease epoch revoked');
    checkTransfer((int)Db::name('staff_job_position')->where('staff_id',$sourceId)->where('status',1)->count()===0
        && (int)Db::name('staff_job_position')->where('staff_id',$target)->where('position_id',$position)->where('status',1)->count()===1,'original position ended, target carries position');
    checkTransfer((int)Db::name('staff_tenure_period')->where('employee_id',$employee)->where('status',1)->count()===1, 'one active tenure');
    checkTransfer((int)Db::name('employee_change_log')->where('employee_id',$employee)->where('action','staff_transfer_execute')->where('source','store')->count()===1, 'actual store operator audited');
    checkTransfer((int)Db::name('employee_change_log')->where('employee_id',$employee)->where('action','staff_phone_transfer_confirm')->count()===1,'final authorization snapshot appended');
    $replay=$confirm($candidate,$token);
    checkTransfer($replay['replay']===true && $replay['data']===$result['data'], 'same request replay without duplicate transfer');
    rejectTransfer(fn()=>$confirm($candidate),'已在本门店');
    rejectTransfer(fn()=>$svc->preview($phone,$to,$operatorId),'已在本门店');
    $conflict=$candidate; $conflict['expected_version']++;
    rejectTransfer(fn()=>$confirm($conflict,$token),'CONFLICT');
    rejectTransfer(fn()=>$svc->preview($phone,$from,$sourceId),'登录任职');
    // 合法调回复用历史任职；不能因重启原 staff ID（ABA）接受调出前的旧候选。
    [$backOperatorEmployee,$backOperator]=$create($from,'调回操作人');
    $back=$svc->preview($phone,$from,$backOperator); $backToken=uuidTransfer();
    $backResult=$svc->confirm(array_intersect_key($back,array_flip(['phone','employee_id','source_staff_id','expected_version','snapshot_hash']))+['request_token'=>$backToken],$from,['id'=>$backOperator,'real_name'=>'R51调回测试'],['body_token'=>$backToken]);
    checkTransfer((int)$backResult['data']['execute_staff_id']===$sourceId,'historical target assignment reused');
    rejectTransfer(fn()=>$confirm($candidate),'资料已变化');
    $fresh=$svc->preview($phone,$to,$operatorId);
    Db::name('employee')->where('id',$employee)->update(['status'=>0]);
    rejectTransfer(fn()=>$svc->preview($phone,$to,$operatorId),'已离职');
    Db::name('employee')->where('id',$employee)->update(['status'=>1]);
    // 在内核写入后模拟认证投影失败：任职、审计和回执必须整体撤回。
    $app->instance(StaffJobPositionServices::class,new class extends StaffJobPositionServices {
        public function afterEmployeeAuthChanged(int $employeeId): array { throw new RuntimeException('R51 late failure'); }
    });
    $beforeAudit=(int)Db::name('employee_change_log')->where('employee_id',$employee)->count();
    $lateToken=uuidTransfer();
    // 调回同一历史 staff ID 后，既有内核以秒拼接任职令牌；跨秒才能单独验证后置失败回滚。
    sleep(1);
    rejectTransfer(fn()=>$confirm($fresh,$lateToken),'late failure');
    $app->instance(StaffJobPositionServices::class,$jobs);
    checkTransfer((int)Db::name('system_store_staff')->where('id',$sourceId)->value('status')===1
        && (int)Db::name('system_store_staff')->where('id',$target)->value('status')===0,'late failure restores both assignments');
    checkTransfer((int)Db::name('employee_change_log')->where('employee_id',$employee)->count()===$beforeAudit
        && !Db::name('organization_write_idempotency')->where('request_token',$lateToken)->find(),'late failure rolls back audit and receipt');
    $retry=$confirm($fresh,$lateToken);
    checkTransfer($retry['replay']===false,'rolled back request can safely retry original token');
    checkTransfer((int)Db::name('store_staff_transfer_log')->where('employee_id',$employee)->count()===3,'only three successful transfers recorded');

    // 只有主档/停用历史任职时，调入本店不得复活旧登录权限；结果必须可按同令牌安全重放。
    [$inactiveEmployee,$inactiveStaff,$inactivePhone]=$create($to,'无有效任职');
    Db::name('employee')->where('id',$inactiveEmployee)->update(['auth_version'=>0]);
    Db::name('system_store_staff')->where('id',$inactiveStaff)->update([
        'status'=>0,'account'=>'r51-old-account','pwd'=>'r51-old-password','is_cashier'=>1,'is_store'=>1,'is_manager'=>1,
    ]);
    $inactiveCandidate=$svc->preview($inactivePhone,$to,$operatorId);
    checkTransfer($inactiveCandidate['source_staff_id']===0 && $inactiveCandidate['expected_version']===1,'inactive assignment can be found by exact phone');
    $inactiveToken=uuidTransfer(); $inactiveResult=$confirm($inactiveCandidate,$inactiveToken);
    checkTransfer((int)$inactiveResult['data']['execute_staff_id']===$inactiveStaff
        && $inactiveResult['data']['needs_profile_completion']===true,'inactive local assignment reused for profile completion');
    $reactivated=Db::name('system_store_staff')->where('id',$inactiveStaff)->find();
    checkTransfer((int)$reactivated['status']===1 && $reactivated['account']==='' && $reactivated['pwd']===''
        && (int)$reactivated['is_cashier']===0 && (int)$reactivated['is_manager']===0,'historical login and permission do not reactivate');
    checkTransfer((int)Db::name('staff_tenure_period')->where('employee_id',$inactiveEmployee)->where('status',1)->count()===1,
        'one active tenure after inactive assignment attaches');
    $inactiveReplay=$confirm($inactiveCandidate,$inactiveToken);
    checkTransfer($inactiveReplay['replay']===true && $inactiveReplay['data']===$inactiveResult['data'],'inactive assignment replay does not duplicate tenure');
    rejectTransfer(fn()=>$svc->preview($inactivePhone,$to,$operatorId),'已在本门店');

    // 模拟已调入员工仍有总部直属岗位：本店岗位保存只替换本店任职，不结束另一处岗位。
    $selectable=app()->make(JobPositionPolicyServices::class)->listStoreSelectablePositions($to);
    $storePosition=(int)($selectable[0]['value'] ?? 0);
    $directPosition=(int)Db::name('position')->where('status',1)->where('id','<>',$storePosition)->value('id');
    checkTransfer($storePosition>0 && $directPosition>0,'distinct direct and selectable store positions');
    Db::name('staff_job_position')->insert(['employee_id'=>$inactiveEmployee,'store_id'=>0,'staff_id'=>0,
        'position_id'=>$directPosition,'status'=>1,'start_time'=>time()-10,'end_time'=>0,'reason'=>'R51直属岗位夹具',
        'is_del'=>0,'request_token'=>uuidTransfer(),'operator_id'=>$operatorId,'operator_name'=>'R51',
        'add_time'=>time(),'update_time'=>time()]);
    $jobs->bindJobsInTx($inactiveEmployee,$inactiveStaff,$to,[$storePosition],$operator,[],'store',false);
    checkTransfer((int)Db::name('staff_job_position')->where('employee_id',$inactiveEmployee)->where('staff_id',0)
        ->where('position_id',$directPosition)->where('status',1)->count()===1
        && (int)Db::name('staff_job_position')->where('employee_id',$inactiveEmployee)->where('staff_id',$inactiveStaff)
            ->where('position_id',$storePosition)->where('status',1)->count()===1,
        'different appointments retain their own active position');
    $nextStorePosition=(int)($selectable[1]['value'] ?? 0);
    checkTransfer($nextStorePosition>0 && $nextStorePosition!==$storePosition,'second selectable store position');
    $jobs->bindJobsInTx($inactiveEmployee,$inactiveStaff,$to,[$nextStorePosition],$operator,[],'store',false);
    checkTransfer((int)Db::name('staff_job_position')->where('staff_id',$inactiveStaff)->where('status',1)
        ->where('end_time',0)->count()===1
        && (int)Db::name('staff_job_position')->where('staff_id',$inactiveStaff)->where('position_id',$storePosition)
            ->where('status',0)->count()===1
        && (int)Db::name('staff_job_position')->where('employee_id',$inactiveEmployee)->where('staff_id',0)
            ->where('position_id',$directPosition)->where('status',1)->count()===1,
        'same appointment replaces its position and preserves other appointment');

    $bare= $employeeTemplate; unset($bare['id']); $bare['name']='R51仅主档夹具';
    $bare['phone']='199'.str_pad((string)random_int(1,99999999),8,'0',STR_PAD_LEFT); $bare['uid']=null; $bare['auth_version']=1;
    $bareEmployee=(int)Db::name('employee')->insertGetId($bare); $employeeIds[]=$bareEmployee;
    $bareCandidate=$svc->preview($bare['phone'],$to,$operatorId);
    $bareResult=$confirm($bareCandidate);
    checkTransfer((int)$bareResult['data']['execute_staff_id']>0 && $bareResult['data']['needs_profile_completion']===true,
        'employee without any assignment attaches to current store');
    checkTransfer((int)Db::name('system_store_staff')->where('employee_id',$bareEmployee)->where('store_id',$to)->where('status',1)->count()===1,
        'bare employee has exactly one active local assignment');
    $editable=app()->make(EmployeePersonCompleteWriteServices::class)->getComplete(
        $bareEmployee,(int)$bareResult['data']['execute_staff_id'],'store');
    checkTransfer((int)$editable['staff_id']===(int)$bareResult['data']['execute_staff_id']
        && (string)$editable['phone']===$bare['phone'],'attached staff opens current-store complete editor');
    $createToken=uuidTransfer();
    rejectTransfer(fn()=>app()->make(EmployeePersonCompleteWriteServices::class)->saveComplete([
        'store_id'=>$to,'staff_name'=>'重复新增员工','phone'=>$bare['phone'],'position_ids'=>[$position],
        'request_token'=>$createToken,
    ],$operator,['body_token'=>$createToken],'store'),'已经有了员工档案，请使用调店功能');
} finally {
    Db::rollback();
    foreach($employeeIds as $id) checkTransfer(!Db::name('employee')->where('id',$id)->find(),'fixture rolled back '.$id);
}
