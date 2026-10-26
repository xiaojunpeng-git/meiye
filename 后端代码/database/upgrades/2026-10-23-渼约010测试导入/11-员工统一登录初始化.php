<?php
/** 010 员工凭据迁移：仅有效员工，A+手机号 / 末四位；复用加密、凭据版本及审计，不新增岗位权限。 */
function reset010EmployeeLogins(): array
{
    $db=(string)\think\facade\Db::query('SELECT DATABASE() d')[0]['d'];
    $local=is_file('/.dockerenv')&&getenv('LOGIN010_TEST')==='1'&&preg_match('/^test_010_login_[a-f0-9]+$/D',$db);
    if(PHP_SAPI!=='cli'||(!$local&&$db!=='mohe_010_app'))throw new RuntimeException('010_TARGET_REQUIRED');
    return \think\facade\Db::transaction(function()use($local){
        $rows=\think\facade\Db::name('employee')->where('is_del',0)->order('id')->lock(true)->select()->toArray();
        $fingerprint=hash('sha256',implode(',',array_map(fn($r)=>$r['id'].':'.$r['phone'],$rows)));
        if(!$local&&(count($rows)!==113||$fingerprint!=='91019f28e913ddb3f12a2b7f52f937feb541f59452f2fec4751811bd45626ad8'))throw new RuntimeException('EMPLOYEE_BASELINE_CHANGED');
        if(\think\facade\Db::name('employee_change_log')->where('action','010_login_rule_applied')->count())throw new RuntimeException('ALREADY_APPLIED');
        $svc=app()->make(\app\services\employee\EmployeeInternalAccountServices::class);
        foreach($rows as $r){
            $id=(int)$r['id'];$phone=(string)$r['phone'];$account='A'.$phone;
            if(!preg_match('/^1[0-9]{10}$/D',$phone)||(int)$r['status']!==1)throw new RuntimeException('ACTIVE_EMPLOYEE_PHONE_REQUIRED');
            foreach(['employee_internal_account','system_admin','system_store_staff'] as $table){
                if(\think\facade\Db::name($table)->where('account',$account)->where('is_del',0)->where(function($q)use($id){$q->where('employee_id','<>',$id)->whereOr('employee_id',null);})->count())throw new RuntimeException('ACCOUNT_CONFLICT');
            }
            // 产品投影会更新同员工平台账号状态；保留原停用状态，避免重置密码扩大入口权限。
            $admins=\think\facade\Db::name('system_admin')->where('employee_id',$id)->where('admin_type',0)->where('is_del',0)->lock(true)->select()->toArray();
            $existing=$svc->getByEmployeeId($id);$status=$existing?(int)$existing['status']:1;
            $svc->saveAccount($id,$account,substr($phone,-4),$status,['operator_id'=>1,'operator_name'=>'010登录规则迁移'],['use_outer_transaction'=>true]);
            foreach($admins as $a)\think\facade\Db::name('system_admin')->where('id',$a['id'])->update(['status'=>$a['status']]);
            $saved=$svc->getByEmployeeId($id);
            if($saved['account']!==$account||!password_verify(substr($phone,-4),$saved['pwd']))throw new RuntimeException('CREDENTIAL_VERIFY_FAILED');
            \think\facade\Db::name('employee_change_log')->insert(['employee_id'=>$id,'action'=>'010_login_rule_applied','target_type'=>'internal_account','target_id'=>$id,'before_data'=>json_encode(['had_account'=>(bool)$existing,'credential_version'=>$existing?(int)$existing['credential_version']:0]),'after_data'=>json_encode(['account_rule'=>'A+phone','password_reset'=>true,'credential_version'=>(int)$saved['credential_version']]),'operator_id'=>1,'operator_name'=>'010登录规则迁移','add_time'=>time()]);
        }
        return ['processed'=>count($rows),'verified'=>count($rows)];
    });
}
