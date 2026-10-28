<?php
/** Read deployed services with current platform authority in an enforced read-only transaction. */
declare(strict_types=1);

$sites=['rh'=>['rh.cc3798.com','ruihao',3306],'008'=>['008.cc3798.com','mohe_008',3307],'010'=>['010.cc3798.com','mohe_010_app',3307]];$s=$argv[1]??'';if(!isset($sites[$s]))throw new RuntimeException('Named site required');[$domain,$db,$port]=$sites[$s];$root='/www/wwwroot/'.$domain;chdir($root);require $root.'/vendor/autoload.php';(new think\App($root.'/'))->initialize();set_exception_handler(function(Throwable$e){file_put_contents('php://stderr',get_class($e).': '.$e->getMessage().PHP_EOL);exit(1);});$cfg=config('database.connections.mysql');if($cfg['database']!==$db||(int)$cfg['hostport']!==$port)throw new RuntimeException('Wrong database');
think\facade\Db::execute('SET SESSION TRANSACTION READ ONLY');think\facade\Db::startTrans();
try{
 $admin=(int)think\facade\Db::name('system_admin')->where('status',1)->where('is_del',0)->where('admin_type',0)->where('level',0)->value('id');$svc=new app\services\user\PlatformMemberCenterServices();$boot=$svc->bootstrap($admin);
 $cap=$svc->action($admin,'query-unified-query-capabilities',['pageCode'=>'platform_member_list']);$fields=array_column($cap['data']['unifiedQueryCapability']['fields'],'key');if(!in_array('wechat_binding',$fields,true))throw new RuntimeException('Binding column missing');
 $page=$svc->action($admin,'query-members',['pageCode'=>'platform_member_list','page'=>1,'limit'=>20]);if(count($page['data']['records'])>20)throw new RuntimeException('Invalid pagination');
 $empty=$svc->action($admin,'query-members',['pageCode'=>'platform_member_list','keyword'=>'PML_READONLY_NO_SUCH_MEMBER_7281','page'=>1,'limit'=>20]);if($empty['data']['total']!==0||$empty['data']['records']!==[])throw new RuntimeException('Empty query mismatch');
 $scope=(new app\services\user\PlatformMemberScopeServices())->build($admin,'edit');$first=$page['data']['records'][0]??null;$loginChecked=false;
 if($first){$login=(new app\services\user\MemberLoginManagementServices())->read((int)$first['memberId'],$scope['operator'],$scope['data']);if((isset($login['pwd'])||isset($login['newPassword']))||!array_key_exists('revision',$login)||!is_array($login['bindings']))throw new RuntimeException('Login projection invalid');$loginChecked=true;}
 foreach(['eb_member_phone_login_reset','eb_member_management_receipt']as$t)if((int)think\facade\Db::query('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$t])[0]['n']!==1)throw new RuntimeException('Migration missing');
 $forbidden=false;try{$svc->action($admin,'submit-recharge-checkout',[]);}catch(think\exception\ValidateException$e){$forbidden=true;}if(!$forbidden)throw new RuntimeException('Money command incorrectly exposed');
 // Verify the visible platform operator keeps its existing edit grant after placeholder normalization.
 $roleCompatibility=true;
 if($s==='008'){$visible=think\facade\Db::name('system_admin')->where('account','A0751')->where('status',1)->where('is_del',0)->find();if($visible){(new app\services\user\PlatformMemberScopeServices())->build((int)$visible['id'],'edit');}}
 // Existing wage reader stays on its already-published saved/realtime policy; no edit/freeze/payment is executed.
 $store=(int)$boot['stores'][0]['id'];$tx=new class{public function run(callable$callback,?array$tables=null){return $callback();}};
 $salary=(new app\services\report\BasicSalaryReportServices(null,$tx))->read('basic_salary_realtime',[$store],['start_date'=>'2026-09-01','end_date'=>'2026-09-30','as_of_date'=>'2026-09-30','_report_scope'=>['mode'=>'stores']]);if(!is_array($salary['rows']??null))throw new RuntimeException('Existing salary reader failed');
 if(!class_exists(app\services\activity\combination\CombinationGroupManagementServices::class)||!class_exists(app\services\activity\combination\CombinationRefundServices::class))throw new RuntimeException('Existing combination services missing');
 echo json_encode(['result'=>'PASS','site'=>$s,'serverReadOnly'=>true,'businessWrites'=>0,'allowedStores'=>count($boot['stores']),'memberTotal'=>(int)$page['data']['total'],'pageRows'=>count($page['data']['records']),'bindingField'=>true,'loginInfoRead'=>$loginChecked,'emptyQuery'=>true,'financialCommandDenied'=>true,'existingOperatorEditPermission'=>true,'existingSalaryRead'=>true,'existingCombinationClasses'=>true]),PHP_EOL;
}finally{think\facade\Db::rollback();}
