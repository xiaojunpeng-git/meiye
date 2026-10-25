<?php
/** Explicit local-only disposable store/account/position fixture. Does not grant
 * an existing employee/position or mutate real store settings. Cleanup is exact-ID
 * and checks the fixture marker before removing its own test rows.
 */
require '/var/www/html/vendor/autoload.php';(new \think\App('/var/www/html/'))->initialize();
set_exception_handler(static function(Throwable $e){file_put_contents('php://stderr',$e->getMessage().PHP_EOL);exit(1);});
use think\facade\Db;
$cfg=config('database.connections.mysql');if(!in_array($cfg['hostname'],['mysql','mohe-mysql'],true)||$cfg['database']!=='ruihao')throw new RuntimeException('Local only');
$mode=$argv[1]??'';$file='/tmp/r59-store-profile-fixture.json';
if($mode==='prepare'){
 if(is_file($file)||Db::name('employee_internal_account')->where('account','r59_profile_fixture')->count())throw new RuntimeException('Fixture exists; inspect/clean before retry');
 $result=Db::transaction(function(){
  $row=Db::name('system_store')->where('is_del',0)->order('id')->find();if(!$row)throw new RuntimeException('Missing store template');unset($row['id']);
  $row['name']='R59隔离测试店';$row['phone']='010-12345678';$row['detailed_address']='R59隔离测试地址';$row['introduction']='隔离测试';$row['is_show']=1;$row['is_del']=0;$row['business_status']=1;$row['longitude']='117.227200';$row['latitude']='31.820600';$row['day_time']='10:00:00 - 21:00:00';$row['day_start']='10:00:00';$row['day_end']='21:00:00';$row['image']=$row['background_image']='/static/r59-profile-fixture.png';
  $parent=0;$ids=[];$names=[];foreach(['province','city','area']as $key){$city=Db::name('city_area')->where('parent_id',$parent)->order('id')->find();if(!$city)throw new RuntimeException('Missing city fixture');$row[$key]=$parent=(int)$city['id'];$ids[]=$parent;$names[]=$city['name'];}$row['street']=0;$row['address']=implode('',$names);
  $store=(int)Db::name('system_store')->insertGetId($row);
  $employee=(int)Db::name('employee')->insertGetId(['name'=>'R59隔离测试员','phone'=>'19900000059','status'=>1,'is_del'=>0,'add_time'=>time(),'update_time'=>time()]);
  $password='R59_Profile_Test_Only!';$pwd=password_hash($password,PASSWORD_BCRYPT);
  $account=(int)Db::name('employee_internal_account')->insertGetId(['employee_id'=>$employee,'account'=>'r59_profile_fixture','pwd'=>$pwd,'status'=>1,'credential_version'=>1,'is_del'=>0,'add_time'=>time(),'update_time'=>time()]);
  $staff=(int)Db::name('system_store_staff')->insertGetId(['employee_id'=>$employee,'store_id'=>$store,'account'=>'r59_profile_fixture','pwd'=>$pwd,'staff_name'=>'R59隔离测试员','phone'=>'19900000059','status'=>1,'is_del'=>0,'is_cashier'=>1,'is_store'=>1,'add_time'=>time()]);
  $position=(int)Db::name('position')->insertGetId(['name'=>'R59隔离测试岗位','status'=>1,'use_store'=>1,'version'=>1]);
  $rule=(int)Db::name('job_position_channel_rule')->insertGetId(['position_id'=>$position,'channel'=>'store_v3','rules'=>'301063','status'=>1,'version'=>1,'add_time'=>time(),'update_time'=>time()]);
  $job=(int)Db::name('staff_job_position')->insertGetId(['employee_id'=>$employee,'store_id'=>$store,'staff_id'=>$staff,'position_id'=>$position,'status'=>1,'is_del'=>0,'start_time'=>time(),'end_time'=>0,'reason'=>'R59 isolated local test','add_time'=>time(),'update_time'=>time()]);
  $token=app()->make(\app\services\cashier\LoginServices::class)->createToken($staff,'cashier_v3',$pwd);
  return compact('store','employee','account','staff','position','rule','job','password')+['login'=>'r59_profile_fixture','token'=>$token['token']];
 });
 file_put_contents($file,json_encode($result));chmod($file,0600);
 $image='/var/www/html/public/static/r59-profile-fixture.png';if(is_file($image))throw new RuntimeException('Image fixture already exists');
 file_put_contents($image,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLbtAAAAABJRU5ErkJggg=='));
 echo 'R59_ISOLATED_FIXTURE_READY realGrantsChanged=false',PHP_EOL;
}elseif($mode==='cleanup'){
 $r=json_decode(file_get_contents($file),true);if(!$r||Db::name('employee_internal_account')->where('id',$r['account'])->value('account')!=='r59_profile_fixture')throw new RuntimeException('Fixture identity mismatch');
 // Remove only the explicit disposable namespace, never other local audit/history.
 Db::transaction(function()use($r){
  Db::name('cashier_v3_store_profile_audit')->where('store_id',$r['store'])->delete();
  Db::name('cashier_v3_state_context')->where('store_id',$r['store'])->where('operator_id',$r['staff'])->delete();
  Db::name('cashier_v3_resource_version')->where('scope_type','store')->where('scope_id',(string)$r['store'])->delete();
  Db::name('staff_job_position')->where('id',$r['job'])->where('employee_id',$r['employee'])->delete();
  Db::name('job_position_channel_rule')->where('id',$r['rule'])->where('position_id',$r['position'])->delete();
  Db::name('position')->where('id',$r['position'])->where('name','R59隔离测试岗位')->delete();
  Db::name('system_store_staff')->where('id',$r['staff'])->where('employee_id',$r['employee'])->delete();
  Db::name('employee_internal_account')->where('id',$r['account'])->where('employee_id',$r['employee'])->delete();
  Db::name('employee')->where('id',$r['employee'])->where('name','R59隔离测试员')->delete();
  Db::name('system_store')->where('id',$r['store'])->delete();
 });
 app()->make(\mohe\services\CacheService::class)->clearToken(md5($r['token']));
 @unlink('/var/www/html/public/static/r59-profile-fixture.png');unlink($file);echo 'R59_ISOLATED_FIXTURE_CLEANED',PHP_EOL;
}else throw new RuntimeException('prepare|cleanup required');
