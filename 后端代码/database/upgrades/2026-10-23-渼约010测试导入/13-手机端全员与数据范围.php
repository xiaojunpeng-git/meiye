<?php
/**
 * 010 一次性手机授权迁移。承接原岗位，不重投影平台/门店角色；HQ范围原样保留。
 * 必须停010写入并核对固定基线后执行。全部SQL、权限失效与审计在同一事务中。
 */
function enable010MobileEmployees(): array
{
    $db=(string)\think\facade\Db::query('SELECT DATABASE() d')[0]['d'];
    $local=is_file('/.dockerenv')&&getenv('MOBILE010_TEST')==='1'&&preg_match('/^test_010_mobile_[a-f0-9]+$/D',$db);
    if(PHP_SAPI!=='cli'||(!$local&&$db!=='mohe_010_app'))throw new RuntimeException('010_TARGET_REQUIRED');
    return \think\facade\Db::transaction(function()use($local){
        $D='think\\facade\\Db';
        if($D::name('employee_change_log')->where('action','010_mobile_all_applied')->count())throw new RuntimeException('ALREADY_APPLIED');
        $base=[];
        $base['employees']=$D::name('employee')->where('is_del',0)->field('id,status')->order('id')->lock(true)->select()->toArray();
        $base['positions']=$D::name('position')->order('id')->lock(true)->select()->toArray();
        $base['staff']=$D::name('system_store_staff')->where('is_del',0)->field('id,employee_id,store_id,status,position,is_manager')->order('id')->lock(true)->select()->toArray();
        $base['jobs']=$D::name('staff_job_position')->where('is_del',0)->where('status',1)->where('end_time',0)->field('id,employee_id,staff_id,store_id,position_id')->order('id')->lock(true)->select()->toArray();
        $base['scopes']=$D::name('employee_data_scope')->where('is_del',0)->order('id')->lock(true)->select()->toArray();
        $base['mobile_rules']=(new \app\services\mobile\merchant\MobileMerchantCapabilityCatalog())->allRuleIds();
        $base['channel_rules']=$D::name('job_position_channel_rule')->order('id')->lock(true)->select()->toArray();
        if(!$local&&hash('sha256',json_encode($base,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))!=='b0ef668eb61f85ab010f4cc259ecd515526ca3a03bd473af39eab4704c333f01')throw new RuntimeException('BASELINE_CHANGED');
        app()->make(\app\services\growth\GrowthSchemaServices::class)->assertReady();
        if($D::name('growth_setting')->where('id',1)->lock(true)->find())throw new RuntimeException('GROWTH_SETTING_BASELINE_CHANGED');
        $rules=[401100,401200,401300,401009,401007,401004,401001,401030,401002,401003,401010,401005,401006,401008];sort($rules);
        $now=time();$jobs=app()->make(\app\services\organization\StaffJobPositionServices::class);
        $scope=app()->make(\app\services\organization\EmployeeDataScopeServices::class);
        // 校验桌面身份与所有非手机渠道字节不变，防止手机开通顺带扩大登录入口。
        $desktop=function()use($D){$out=[];foreach(['system_admin','system_store_staff','system_role'] as $t)$out[$t]=$D::name($t)->order('id')->select()->toArray();$out['channels']=$D::name('job_position_channel_rule')->where('channel','<>','mobile')->order('id')->select()->toArray();return hash('sha256',json_encode($out));};
        $beforeDesktop=$desktop();$positions=array_column($base['positions'],null,'id');$generic=0;$newJobs=0;$processedPositions=[];$counts=['store'=>0,'personal'=>0,'hq_preserved'=>0];
        foreach($base['employees'] as $e){
            $eid=(int)$e['id'];if((int)$e['status']!==1)throw new RuntimeException('INACTIVE_EMPLOYEE');
            $account=$D::name('employee_internal_account')->where('employee_id',$eid)->where('status',1)->where('is_del',0)->find();if(!$account)throw new RuntimeException('LOGIN_ACCOUNT_REQUIRED');
            $staff=array_values(array_filter($base['staff'],fn($s)=>(int)$s['employee_id']===$eid));
            $active=array_values(array_filter($staff,fn($s)=>(int)$s['status']===1));
            $existing=array_values(array_filter($base['jobs'],fn($j)=>(int)$j['employee_id']===$eid));
            if(count($staff)!==1||count($active)>1||count($existing)>1)throw new RuntimeException('AMBIGUOUS_APPOINTMENT');
            $s=$staff[0];$sid=$active?(int)$s['id']:0;$store=$active?(int)$s['store_id']:0;
            $pid=$existing?(int)$existing[0]['position_id']:(int)$s['position'];
            if($existing&&((int)$existing[0]['staff_id']!==$sid||(int)$existing[0]['store_id']!==$store))throw new RuntimeException('JOB_APPOINTMENT_MISMATCH');
            if(!$pid){
                // 原岗位空缺者仅承接手机业务入口，不伪造店长或桌面管理岗位。
                if(!$generic){$generic=$D::name('position')->insertGetId(['name'=>'手机业务员工','status'=>1,'use_mobile'=>1,'use_platform'=>0,'use_store'=>0,'use_cashier'=>0,'allow_store_select'=>0,'is_store_manager'=>0,'remark'=>'010原岗位空缺员工手机授权承接','version'=>1,'update_time'=>$now]);$positions[$generic]=['id'=>$generic,'status'=>1];}
                $pid=$generic;
            }
            if(!isset($positions[$pid])||(int)$positions[$pid]['status']!==1)throw new RuntimeException('POSITION_DISABLED');
            if(!$existing){
                $D::name('staff_job_position')->insert(['employee_id'=>$eid,'staff_id'=>$sid,'store_id'=>$store,'position_id'=>$pid,'status'=>1,'start_time'=>$now,'end_time'=>0,'is_del'=>0,'reason'=>'010手机全员开通承接原岗位','request_token'=>'010-mobile-'.$eid,'operator_id'=>1,'operator_name'=>'010手机授权迁移','add_time'=>$now,'update_time'=>$now]);$newJobs++;
            }
            if(!isset($processedPositions[$pid])) {
            $processedPositions[$pid]=true;
            $D::name('position')->where('id',$pid)->update(['use_mobile'=>1,'version'=>(int)($positions[$pid]['version']??0)+1,'update_time'=>$now]);
            $r=$D::name('job_position_channel_rule')->where('position_id',$pid)->where('channel','mobile')->find();
            $value=['rules'=>implode(',',$rules),'status'=>1,'update_time'=>$now];
            if($r){$value['version']=(int)$r['version']+1;$D::name('job_position_channel_rule')->where('id',$r['id'])->update($value);}else{$D::name('job_position_channel_rule')->insert(array_merge($value,['position_id'=>$pid,'channel'=>'mobile','version'=>1,'add_time'=>$now]));}
            }
            $priorScope=$D::name('employee_data_scope')->where('employee_id',$eid)->order('id')->select()->toArray();
            $mode='hq_preserved';
            if($active){
                // 正式岗位优先；只有原定义的店长1/副店2可看整店，不能凭旧is_manager扩大权限。
                $mode=in_array($pid,[1,2],true)?'store':'personal';
                $scope->saveScopeInTx($eid,['source_type'=>'hq','source_store_id'=>0,'scope_mode'=>$mode],['id'=>1,'level'=>0,'account'=>'admin'],['request_token'=>'010-mobile-scope-'.$eid,'operator_name'=>'010手机授权迁移']);
            }
            $counts[$mode]++;
            $auth=$jobs->projectMobileAuthRules($eid,1);$got=$auth['rule_ids'];sort($got);if($got!==$rules)throw new RuntimeException('MOBILE_RULES_MISMATCH');
            $jobs->bumpEmployeeAuthVersion($eid);$jobs->invalidateMerchantSessions($eid);
            $D::name('employee_change_log')->insert(['employee_id'=>$eid,'action'=>'010_mobile_all_applied','target_type'=>'mobile_auth','target_id'=>$auth['id'],'before_data'=>json_encode(['jobs'=>$existing,'scopes'=>$priorScope],JSON_UNESCAPED_UNICODE),'after_data'=>json_encode(['position_id'=>$pid,'mobile_rules'=>$rules,'scope'=>$mode]),'operator_id'=>1,'operator_name'=>'010手机授权迁移','add_time'=>$now]);
        }
        // 用户已授权启用成长。按安装正本补默认行，再走正式设置服务；不补发历史任务、不启用业绩奖励联动。
        $D::name('growth_setting')->insert(['id'=>1,'templates_json'=>'[]','enabled'=>0,'business_link_enabled'=>0]);
        $growthContext=app()->make(\app\services\growth\GrowthAccessServices::class)->principal(0,'admin:1',true,true);
        app()->make(\app\services\growth\GrowthSettingsServices::class)->save($growthContext,['idempotency_key'=>'010-mobile-growth-enable-20261025','enabled'=>true,'business_link_enabled'=>false,'templates'=>[],'reason'=>'010全员手机端开通，同时启用成长管理'],1);
        if(!app()->make(\app\services\growth\GrowthAccessServices::class)->isEnabled())throw new RuntimeException('GROWTH_ENABLE_FAILED');
        if($desktop()!==$beforeDesktop)throw new RuntimeException('DESKTOP_CHANGED');
        return ['processed'=>count($base['employees']),'new_jobs'=>$newJobs,'scopes'=>$counts,'mobile_rule_count'=>count($rules),'desktop_unchanged'=>true,'growth_enabled'=>true];
    });
}
