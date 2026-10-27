<?php
declare(strict_types=1);
/** 只建立隔离库专用体验门店与两名虚构员工，避免把技术测试任务堆到演示首页。 */
require '/var/www/html/vendor/autoload.php';
$app=new think\App('/var/www/html/');$app->setEnvName('growth-local');$app->setRuntimePath('/var/www/html/runtime/growth-local/');$app->initialize();
use think\facade\Db;
if(config('database.connections.mysql.database')!=='growth_qa_20261023')throw new RuntimeException('拒绝非成长隔离库');
$insert=function(string $table,array $row):void{if(Db::name($table)->where('id',$row['id'])->find())return;Db::name($table)->insert($row);};
$old=Db::name('system_admin')->where('id',65532)->find();if($old&&$old['account']!=='growth_demo')throw new RuntimeException('演示账号ID已被其他对象使用');
$password=password_hash('GrowthLocal!2026',PASSWORD_BCRYPT);$then=strtotime('2026-01-01');
Db::transaction(function()use($insert,$password,$then){
 $insert('organization',['id'=>400010,'name'=>'美业大学体验中心','status'=>1]);
 $insert('system_store',['id'=>400010,'name'=>'春日美学体验店','business_status'=>1,'is_show'=>1]);
 $insert('organization_store',['id'=>400010,'org_id'=>400010,'store_id'=>400010]);
 foreach([400010=>'美容师·林晓',400011=>'带教师傅·苏老师'] as $id=>$name){
  $existing=Db::name('employee')->where('id',$id)->find();if($existing&&$existing['name']!==$name)throw new RuntimeException('体验员工ID被其他测试占用');
  $insert('employee',['id'=>$id,'name'=>$name,'phone'=>'199'.str_pad((string)$id,8,'0',STR_PAD_LEFT),'status'=>1,'add_time'=>$then]);
  $insert('employee_internal_account',['id'=>$id,'employee_id'=>$id,'account'=>'emp'.$id,'pwd'=>$password,'status'=>1,'add_time'=>$then]);
  $insert('employee_mobile_auth',['id'=>$id,'employee_id'=>$id,'status'=>1,'scope_mode'=>'personal','rules'=>'401001,401030','request_token'=>'growth-demo-auth-'.$id]);
  if(!Db::name('employee_mobile_auth_state')->where('employee_id',$id)->find())Db::name('employee_mobile_auth_state')->insert(['employee_id'=>$id,'auth_version'=>1,'phone_binding_version'=>1]);
  $insert('organization_employee',['id'=>$id,'org_id'=>400010,'employee_id'=>$id,'status'=>1,'add_time'=>$then]);
  $insert('employee_data_scope',['id'=>$id,'employee_id'=>$id,'scope_mode'=>$id===400011?'org':'personal','org_ids'=>$id===400011?'[400010]':'[]','status'=>1,'request_token'=>'growth-demo-scope-'.$id]);
  $insert('system_store_staff',['id'=>$id,'employee_id'=>$id,'store_id'=>400010,'staff_name'=>$name,'account'=>'emp'.$id,'pwd'=>$password,'status'=>1,'add_time'=>$then]);
  $insert('staff_job_position',['id'=>$id,'employee_id'=>$id,'store_id'=>400010,'staff_id'=>$id,'position_id'=>91001,'status'=>1,'start_time'=>$then,'request_token'=>'growth-demo-job-'.$id]);
 }
 $menus=Db::name('system_menus')->whereLike('unique_auth','admin-growth%')->column('id');
 $insert('system_role',['id'=>400010,'role_name'=>'成长体验管理','type'=>0,'rules'=>implode(',',$menus),'status'=>1,'level'=>1]);
 Db::name('system_admin')->where('id',65532)->where('account','growth_demo')->update(['employee_id'=>400011,'roles'=>'400010']);
 $insert('system_admin',['id'=>65532,'employee_id'=>400011,'account'=>'growth_demo','pwd'=>$password,'real_name'=>'苏老师','status'=>1,'level'=>1,'roles'=>'400010','add_time'=>$then]);
});
echo json_encode(['admin'=>'growth_demo','learner'=>'emp400010','mentor'=>'emp400011','store'=>'春日美学体验店'],JSON_UNESCAPED_UNICODE).PHP_EOL;
