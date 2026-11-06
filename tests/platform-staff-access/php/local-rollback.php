<?php
declare(strict_types=1);
/** Real local SQL authorization regression. Synthetic identities only; transaction always rolled back. */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
set_exception_handler(static function(Throwable $e):void{file_put_contents('php://stderr',$e->getMessage().PHP_EOL);exit(1);});
use think\facade\Db;
use app\services\organization\PlatformStaffAccessServices;

if(config('database.connections.mysql.hostname')!=='mysql'||config('database.connections.mysql.database')!=='ruihao')throw new RuntimeException('LOCAL_ONLY');
function seed(string $table,array $r):array {foreach(Db::query('SHOW COLUMNS FROM eb_'.$table)as$f){$k=$f['Field'];if(array_key_exists($k,$r)||$f['Default']!==null||$f['Null']==='YES'||strpos($f['Extra'],'auto_increment')!==false)continue;$r[$k]=preg_match('/int|decimal|float|double/',$f['Type'])?0:'';}return $r;}
function check(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException($msg);echo "PASS $msg\n";}
function deny(callable $fn,string $msg):void {try{$fn();}catch(mohe\exceptions\AdminException $e){check(true,$msg);return;}throw new RuntimeException('NOT_DENIED '.$msg);}
$p='R4_'.bin2hex(random_bytes(5));$scope=new PlatformStaffAccessServices();
Db::startTrans();try{
$menu=(int)Db::name('system_menus')->where('unique_auth','setting-staff-index')->where('type',1)->where('is_del',0)->value('id');check($menu>0,'native menu exists');
$role=(int)Db::name('system_role')->insertGetId(seed('system_role',['role_name'=>$p,'rules'=>(string)$menu,'status'=>1]));
$admin=(int)Db::name('system_admin')->insertGetId(seed('system_admin',['account'=>$p,'roles'=>'','level'=>1,'admin_type'=>0,'status'=>1,'is_del'=>0]));
deny(fn()=>$scope->assertAllowed($admin),'empty role denies access');
$menus=app()->make(app\services\system\SystemMenusServices::class);
[$tree,$unique]=$menus->getMenusList([],1,1,0);check(!$tree&&!$unique,'no implicit personnel navigation for empty role');
Db::name('system_admin')->where('id',$admin)->update(['roles'=>(string)$role]);
[$tree,$unique]=$menus->getMenusList([(string)$role],1,1,0);check(in_array('setting-staff-index',$unique,true),'explicit role exposes native personnel navigation');
Db::name('system_role')->where('id',$role)->update(['status'=>0]);deny(fn()=>$scope->assertAllowed($admin),'disabled role immediately denied');
Db::name('system_role')->where('id',$role)->update(['status'=>1]);
$supplier=(int)Db::name('system_menus')->where('unique_auth','admin-supplier-menu-list')->where('is_del',0)->value('id');Db::name('system_role')->where('id',$role)->update(['rules'=>(string)$supplier]);deny(fn()=>$scope->assertAllowed($admin),'supplier grant cannot grant personnel access');Db::name('system_role')->where('id',$role)->update(['rules'=>(string)$menu]);
$employee=(int)Db::name('employee')->insertGetId(seed('employee',['name'=>$p,'phone'=>'199'.random_int(10000000,99999999),'status'=>1,'is_del'=>0]));
Db::name('system_admin')->where('id',$admin)->update(['employee_id'=>$employee]);
deny(fn()=>$scope->assertAllowed($admin),'employee cannot inherit stale granting role');
$position=(int)Db::name('position')->insertGetId(seed('position',['name'=>$p,'status'=>1,'use_platform'=>1]));
Db::name('staff_job_position')->insert(seed('staff_job_position',['employee_id'=>$employee,'staff_id'=>0,'store_id'=>0,'position_id'=>$position,'status'=>1,'is_del'=>0,'end_time'=>0]));
$channel=(int)Db::name('job_position_channel_rule')->insertGetId(seed('job_position_channel_rule',['position_id'=>$position,'channel'=>'platform','rules'=>(string)$menu,'status'=>1]));
check($scope->assertAllowed($admin)===null,'current job grants personnel access');
Db::name('job_position_channel_rule')->where('id',$channel)->update(['rules'=>'']);
deny(fn()=>$scope->assertAllowed($admin),'current job revocation overrides stale role');
Db::name('system_admin')->where('id',$admin)->update(['level'=>0]);check($scope->assertAllowed($admin)===null,'real root allowed');
Db::name('system_admin')->where('id',$admin)->update(['level'=>1,'account'=>'admin_'.$p]);deny(fn()=>$scope->assertAllowed($admin),'account name does not confer root');

$before=Db::name('system_menus')->where('id',$menu)->find();
$grants=hash('sha256',json_encode(Db::name('job_position_channel_rule')->order('id')->select()->toArray()));
$migrate=require '/var/www/html/database/upgrades/20261106_platform_staff_management_menu.php';
$result=$migrate();$after=Db::name('system_menus')->where('id',$menu)->find();
check($after['menu_name']==='人员管理' && $after['pid']==$result['parent_id'],'native permission renamed and moved');
check($after['id']==$before['id'],'permission identity preserved');
check($grants===hash('sha256',json_encode(Db::name('job_position_channel_rule')->order('id')->select()->toArray())),'job grants unchanged');
check($migrate()['changed_fields']===[],'migration idempotent');
[$tree,$unique]=$menus->getMenusList([(string)$role],1,1,0);
function locate(array $nodes,int $id,array $parents=[]):?array {foreach($nodes as$n){if((int)($n['id']??0)===$id)return array_merge($parents,[$n]);$found=locate($n['children']??[],$id,array_merge($parents,[$n]));if($found)return $found;}return null;}
$chain=locate($tree,$menu);check($chain!==null,'authorized leaf builds visible navigation');
check($chain[count($chain)-2]['id']==$result['parent_id'],'navigation parent is organization management');
$policyTree=$menus->getList(['type'=>0,'is_del'=>0]);$policyChain=locate($policyTree,$menu);
check($policyChain!==null && end($policyChain)['title']==='人员管理','job policy tree uses same personnel label');
// Invoke the actual controller constructor with the local request identity, before any business command.
$request=app()->request;$request->adminId=$admin;$request->adminInfo=Db::name('system_admin')->where('id',$admin)->find();
$service=app()->make(app\services\store\SystemStoreStaffServices::class);
foreach(['index','read','saveStaff','getWorkMemberList']as$action){$request->setAction($action);deny(fn()=>new app\controller\admin\v1\merchant\SystemStoreStaff(new think\facade\App(),$service),'controller rejects '.$action);}
$exportClass=new ReflectionClass(app\controller\admin\v1\other\export\ExportExcel::class);$export=$exportClass->newInstanceWithoutConstructor();
$actor=$exportClass->getProperty('adminId');$actor->setAccessible(true);$actor->setValue($export,$admin);
deny(fn()=>$export->staffListExport($service),'export rejects revoked job before reading data');
Db::name('job_position_channel_rule')->where('id',$channel)->update(['rules'=>(string)$menu]);
$request->setAction('index');new app\controller\admin\v1\merchant\SystemStoreStaff(new think\facade\App(),$service);check(true,'controller accepts current job grant');

foreach(['index','getStoreStaffList','read','personComplete','saveStaff','delete','transfer','featurePermissionSave','newAction']as$action)check($scope->requiresManagement($action),'guard '.$action);
foreach(['getStaffSelect','store_list']as$action)check(!$scope->requiresManagement($action),'preserve shared selector '.$action);
}finally{Db::rollback();}
check(!Db::name('system_admin')->where('id',$admin)->count(),'synthetic transaction rolled back');
