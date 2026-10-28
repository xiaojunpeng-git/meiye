<?php
declare(strict_types=1);
/** 产品经理明确授权的010财务岗位配置操作；只增加工资编辑/冻结，不写工资或冻结状态。
 * 修改岗位源规则后调用既有平台投影，保留其他渠道、已有权限和任职，不直接拼接员工角色。
 */
$token=$argv[1]??'';
if(!preg_match('/^[a-f0-9-]{36}$/D',$token))throw new RuntimeException('Explicit request token required');
chdir('/www/wwwroot/010.cc3798.com');require 'vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
if(Db::query('SELECT DATABASE() db')[0]['db']!=='mohe_010_app'||(int)config('database.connections.mysql.hostport')!==3307)throw new RuntimeException('Wrong instance');
$position=Db::name('position')->where('id',8)->find();
$rule=Db::name('job_position_channel_rule')->where('position_id',8)->where('channel','platform')->find();
if(!$position||$position['name']!=='财务'||(int)$position['use_platform']!==1||!$rule||(int)$rule['status']!==1)throw new RuntimeException('Finance policy baseline differs');
$actor=Db::name('system_admin')->where('account','A0751')->where('is_del',0)->field('id,account,level,roles,admin_type,employee_id')->find();
if(!$actor||(int)$actor['employee_id']!==106)throw new RuntimeException('Authorized account identity differs');
$ids=[];foreach(['edit','freeze'] as $action){$menus=Db::name('system_menus')->where('unique_auth','admin-report-basic_salary_realtime-'.$action)->where('is_del',0)->select()->toArray();if(count($menus)!==1)throw new RuntimeException('Salary action missing');$ids[]=(int)$menus[0]['id'];}
$employees=Db::name('staff_job_position')->where('position_id',8)->where('is_del',0)->where('status',1)->where('end_time',0)->where('start_time','<=',time())->column('employee_id');
$employees=array_values(array_unique(array_map('intval',$employees)));sort($employees);
$admins=Db::name('system_admin')->whereIn('employee_id',$employees)->where('is_del',0)->field('id,employee_id,roles')->select()->toArray();
if(count($admins)!==2||!in_array((int)$actor['id'],array_map('intval',array_column($admins,'id')),true))throw new RuntimeException('Approved two-account scope changed');
$jobs=app()->make(app\services\organization\StaffJobPositionServices::class);
foreach($employees as $employee)if(!$jobs->dryRunPlatformProjection($employee)['consistent'])throw new RuntimeException('Existing projection requires reconciliation before adding grants');
$oldIds=array_values(array_filter(array_map('intval',explode(',',(string)$rule['rules']))));$next=array_values(array_unique(array_merge($oldIds,$ids)));sort($next);
$roles=Db::name('system_role')->whereIn('id',array_column($admins,'roles'))->field('id,rules,status')->select()->toArray();
$otherChannels=Db::name('job_position_channel_rule')->where('position_id',8)->where('channel','<>','platform')->order('id')->select()->toArray();
$freezeHash=hash('sha256',json_encode(Db::name('salary_freeze')->order('id')->select()->toArray()));
$backup='/www/backups/010/round7-e888c787e5d9-20261027174851/finance-grant-before.json';
// 重复执行只读核对源规则及投影，不重复增加版本或审计事件。
if(!array_diff($ids,$oldIds)){echo json_encode(['result'=>'PASS','configuration_already_matches'=>true,'data'=>['position_id'=>8,'affected_accounts'=>count($admins),'capability_ids'=>$ids,'source_version'=>(int)$rule['version'],'other_channels_unchanged'=>true,'freeze_unchanged'=>true],'replay'=>true,'backup'=>$backup],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}

if(!is_file($backup)){file_put_contents($backup,json_encode(['position'=>$position,'channel_rule'=>$rule,'roles'=>$roles,'admins'=>$admins,'token'=>$token],JSON_THROW_ON_ERROR));chmod($backup,0600);}
$payload=['position_id'=>8,'channel'=>'platform','add_rule_ids'=>$ids,'expected_position_version'=>(int)$position['version'],'expected_rule_version'=>(int)$rule['version']];
$result=app()->make(app\services\organization\OrganizationStrictIdempotencyServices::class)->run('grant_salary_actions','job_position:8',$payload,$actor,['header_token'=>$token,'body_token'=>$token,'operator_ip'=>''],function(array $meta)use($position,$rule,$next,$ids,$employees,$admins,$roles,$otherChannels,$freezeHash,$jobs,$token){
 $locked=Db::name('position')->where('id',8)->lock(true)->find();$current=Db::name('job_position_channel_rule')->where('id',$rule['id'])->lock(true)->find();
 if((int)$locked['version']!==(int)$position['version']||(int)$current['version']!==(int)$rule['version']||$current['rules']!==$rule['rules'])throw new RuntimeException('Finance policy changed concurrently');
 Db::name('job_position_channel_rule')->where('id',$rule['id'])->update(['rules'=>implode(',',$next),'version'=>(int)$rule['version']+1,'update_time'=>time()]);
 Db::name('position')->where('id',8)->update(['version'=>(int)$position['version']+1,'update_time'=>time()]);
 foreach($employees as $employee)$jobs->projectPlatformAdminRoles($employee);
 foreach($roles as $old){$now=Db::name('system_role')->where('id',$old['id'])->find();$prior=array_values(array_filter(array_map('intval',explode(',',(string)$old['rules']))));$actual=array_values(array_filter(array_map('intval',explode(',',(string)$now['rules']))));if(array_diff($prior,$actual)||array_diff(array_diff($actual,$prior),$ids))throw new RuntimeException('Projection changed unrelated authorization');foreach($ids as $id)if(!in_array($id,$actual,true))throw new RuntimeException('Salary capability missing in projection');}
 if(Db::name('job_position_channel_rule')->where('position_id',8)->where('channel','<>','platform')->order('id')->select()->toArray()!==$otherChannels)throw new RuntimeException('Other channel changed');
 if(hash('sha256',json_encode(Db::name('salary_freeze')->order('id')->select()->toArray()))!==$freezeHash)throw new RuntimeException('Freeze state changed during grant');
 Db::name('store_v3_permission_audit')->insert(['operator_id'=>(int)$meta['operator_id'],'action'=>'GRANT_BASIC_SALARY_ACTIONS','before_json'=>json_encode(['rule'=>$rule,'roles'=>$roles],JSON_THROW_ON_ERROR),'after_json'=>json_encode(['position_id'=>8,'channel'=>'platform','added_ids'=>$ids,'request_token'=>$token],JSON_THROW_ON_ERROR),'created_at'=>time()]);
 return ['msg'=>'010财务岗位工资权限已授权','data'=>['position_id'=>8,'affected_accounts'=>count($admins),'capability_ids'=>$ids,'source_version'=>(int)$rule['version']+1,'other_channels_unchanged'=>true,'freeze_unchanged'=>true]];
},false);
echo json_encode(['result'=>'PASS','data'=>$result['data'],'replay'=>$result['replay'],'backup'=>$backup],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
