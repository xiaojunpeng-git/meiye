<?php
/** Isolated Docker fixture; no customer data, local shared tables or Redis keys are modified. */
require '/var/www/html/vendor/autoload.php';
use think\facade\Db;
$app=new think\App('/var/www/html/');$app->initialize();
set_exception_handler(function($e){fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
$c=config('database.connections.mysql');
if(!is_file('/.dockerenv')||!in_array($c['hostname'],['mysql','mohe-mysql'],true))throw new RuntimeException('local only');
$admin=new PDO('mysql:host='.$c['hostname'].';port='.$c['hostport'].';dbname='.$c['database'],$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$schema='test_010_mobile_'.bin2hex(random_bytes(5));$prefix=$schema.':';
$admin->exec('CREATE DATABASE `'.$schema.'`');
try {
 foreach($admin->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $t)$admin->exec('CREATE TABLE `'.$schema.'`.`'.$t[0].'` LIKE `'.$c['database'].'`.`'.$t[0].'`');
 $dbc=config('database');$dbc['connections']['mysql']['database']=$schema;$dbc['connections']['mysql']['fields_cache']=false;
 $app->config->set($dbc,'database');
 $cache=config('cache');$cache['stores']['redis']['prefix']=$prefix;$cache['stores']['redis']['tag_prefix']=$prefix.'tag:';$app->config->set($cache,'cache');$app->instance('cache',new \think\Cache($app));
 putenv('MOBILE010_TEST=1');
 $insert=function($table,$values)use($admin,$schema){$row=[];foreach($admin->query('SHOW COLUMNS FROM `'.$schema.'`.`eb_'.$table.'`')->fetchAll(PDO::FETCH_ASSOC) as $col){$k=$col['Field'];if(array_key_exists($k,$values)){$row[$k]=$values[$k];continue;}if($col['Extra']==='auto_increment'||$col['Default']!==null||$col['Null']==='YES')continue;$row[$k]=preg_match('/int|decimal|float|double/',$col['Type'])?0:'';}return Db::name($table)->insertGetId(array_merge($row,$values));};


 $x=json_decode(file_get_contents('/tmp/010-mobile-fixture.json'),true);
 $mc=config('mobile_auth');$mc['hmac_key']=str_repeat('fixture-key-',5);$app->config->set($mc,'mobile_auth');
 foreach($x['positions'] as $r)$insert('position',$r);
 foreach($x['channel_rules'] as $r)$insert('job_position_channel_rule',$r);
 foreach([64=>0,65=>64,66=>64,67=>64] as $id=>$parent)$insert('organization',['id'=>$id,'pid'=>$parent,'name'=>'fixture-org','status'=>1,'is_del'=>0]);
 foreach([1,2,3,4,6,7,8,9,10,11] as $id){$insert('system_store',['id'=>$id,'name'=>'fixture-store','is_show'=>1,'is_del'=>0]);$insert('organization_store',['store_id'=>$id,'org_id'=>in_array($id,[1,4,7,8,11])?65:66]);}
 $insert('system_admin',['id'=>1,'account'=>'fixture-admin','status'=>1,'level'=>0,'employee_id'=>null]);
 $accountService=app()->make(\app\services\employee\EmployeeInternalAccountServices::class);
 foreach($x['employees'] as $r){$id=(int)$r['id'];$phone=sprintf('138%08d',$id);$insert('employee',array_merge($r,['name'=>'fixture','phone'=>$phone,'is_del'=>0]));$accountService->saveAccount($id,'A'.$phone,substr($phone,-4),1);}
 foreach($x['staff'] as $r){$insert('system_store_staff',array_merge($r,['is_del'=>0]));if(!(int)$r['status'])$insert('organization_employee',['employee_id'=>$r['employee_id'],'org_id'=>67,'status'=>1,'is_del'=>0]);}
 foreach($x['jobs'] as $r)$insert('staff_job_position',array_merge($r,['status'=>1,'is_del'=>0,'end_time'=>0,'request_token'=>'fixture-job-'.$r['id']]));
 foreach($x['scopes'] as $r)$insert('employee_data_scope',$r);
 $insert('employee',['id'=>9999,'name'=>'deleted','phone'=>'13999999999','status'=>1,'is_del'=>1]);
 $assert=function($ok,$name){if(!$ok)throw new RuntimeException($name);echo 'PASS '.$name."\n";};
 require '/var/www/html/database/upgrades/2026-10-23-渼约010测试导入/13-手机端全员与数据范围.php';
 Db::name('employee_internal_account')->where('employee_id',267)->update(['status'=>0]);
 $failed=false;try{enable010MobileEmployees();}catch(Throwable $e){$failed=$e->getMessage()==='LOGIN_ACCOUNT_REQUIRED';}
 $assert($failed && (int)Db::name('employee_change_log')->where('action','010_mobile_all_applied')->count()===0 && (int)Db::name('staff_job_position')->count()===13,'late_failure_rolls_back_all_permissions');
 Db::name('employee_internal_account')->where('employee_id',267)->update(['status'=>1]);
 $ret=enable010MobileEmployees();echo json_encode($ret)."\n";
 $assert($ret['growth_enabled'] && (int)Db::name('growth_setting')->where('id',1)->value('business_link_enabled')===0,'growth_enabled_without_business_reward_link');
 $assert($ret['processed']===113 && $ret['desktop_unchanged'],'113_enabled_desktop_preserved');
 $assert($ret['scopes']===['store'=>33,'personal'=>68,'hq_preserved'=>12],'33_managers_68_personal_12_hq');
 $assert(Db::name('employee_mobile_auth')->where('employee_id',9999)->count()===0,'deleted_not_enabled');
 $assert(Db::name('employee_data_scope')->whereIn('employee_id',[106,20,15])->order('id')->select()->toArray()===$x['scopes'],'hq_scopes_unchanged');
 $sessions=app()->make(\app\services\mobile\merchant\MobileMerchantSessionServices::class);
 foreach($x['employees'] as $r){$id=(int)$r['id'];$phone=sprintf('138%08d',$id);$root=$sessions->passwordLogin(['account'=>'A'.$phone,'pwd'=>substr($phone,-4),'installationId'=>'fixture-installation-'.$id,'idempotencyKey'=>'fixture-login-'.$id],['clientSessionId'=>'fixture-client-'.$id,'requestId'=>'fixture-request-'.$id,'platform'=>'H5']);$assert(in_array('mobile.merchant.growth',$root['availableActions'],true),'actual_mobile_password_login_'.$id);if($id===1||$id===243||$id===106)echo json_encode(['sample'=>$id,'keys'=>array_keys($root),'actions'=>$root['availableActions'],'context'=>$root['contexts']])."\n";}
 $resolver=app()->make(\app\services\mobile\merchant\MobileMerchantRequestContextResolver::class);
 $principal=$resolver->trustedBusinessPrincipal(243,243,6,'66');$assert($principal['dataScopeMode']==='PERSONAL_SELF' && $principal['visibleStoreIds']===[6],'mobile_request_personal_scope');
 $principal=$resolver->trustedBusinessPrincipal(1,1,1,'65');$assert($principal['dataScopeMode']==='STORES' && $principal['visibleStoreIds']===[1],'mobile_request_manager_scope');
 $growth=app()->make(\app\services\growth\GrowthAccessServices::class);
 $assert($growth->employees($growth->principal(243,'employee:243'))===[243],'growth_personal_only_self');
 $allowed=$growth->employees($growth->principal(1,'employee:1'));$assert(in_array(1,$allowed,true)&&!in_array(2,$allowed,true),'growth_manager_own_store_only');
 $denied=false;try{$resolver->trustedBusinessPrincipal(243,243,1,'65');}catch(Throwable $e){$denied=true;}$assert($denied,'forged_cross_store_context_denied');
 $sc=app()->make(\app\services\organization\EmployeeDataScopeServices::class);
 $assert($sc->resolveEffectiveStoreIds(1)===[1],'manager_only_own_store');
 $assert($sc->resolveEffectiveStoreIds(243)===[],'ordinary_no_whole_store');
 $sc->assertOrderReadable(['id'=>90001,'store_id'=>1],1,'store',1,[]);
 $assert(true,'manager_reads_other_staff_in_own_store');
 $sc->assertOrderReadable(['id'=>90004,'store_id'=>6,'service_staff_id'=>243],243,'store',6,[]);$assert(true,'ordinary_own_order_allowed');
 $analytics=app()->make(\app\services\mobile\merchant\MobileMerchantAnalyticsEntryScopeServices::class);
 $a=$analytics->resolve(['employeeId'=>243,'storeId'=>6]);$assert($a['entryType']==='personal' && $a['employeeId']===243 && !$a['showStoreRanking'],'analytics_personal_scope');
 $a=$analytics->resolve(['employeeId'=>1,'storeId'=>1]);$assert($a['entryType']==='store' && $a['authorizedStoreIds']===[1] && !$a['showStoreRanking'],'analytics_manager_own_store');
 $deny=false;try{$sc->assertOrderReadable(['id'=>90002,'store_id'=>2],1,'store',1,[]);}catch(Throwable $e){$deny=true;}$assert($deny,'manager_cross_store_denied');
 $deny=false;try{$sc->assertOrderReadable(['id'=>90003,'store_id'=>6],243,'store',6,[]);}catch(Throwable $e){$deny=true;}$assert($deny,'ordinary_other_staff_denied');
 $deny=false;try{enable010MobileEmployees();}catch(Throwable $e){$deny=$e->getMessage()==='ALREADY_APPLIED';}$assert($deny,'repeat_rejected');
}finally{$admin->exec('DROP DATABASE `'.$schema.'`');}
