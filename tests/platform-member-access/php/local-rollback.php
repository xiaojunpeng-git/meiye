<?php
declare(strict_types=1);
/** Real local SQL authorization regression. Synthetic identities only; transaction always rolled back. */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
use think\facade\Db;
use app\services\user\PlatformMemberScopeServices;
use app\services\user\PlatformMemberCenterServices;
if(config('database.connections.mysql.hostname')!=='mysql'||config('database.connections.mysql.database')!=='ruihao')throw new RuntimeException('LOCAL_ONLY');
function seed(string $table,array $r):array {foreach(Db::query('SHOW COLUMNS FROM eb_'.$table)as$f){$k=$f['Field'];if(array_key_exists($k,$r)||$f['Default']!==null||$f['Null']==='YES'||strpos($f['Extra'],'auto_increment')!==false)continue;$r[$k]=preg_match('/int|decimal|float|double/',$f['Type'])?0:'';}return $r;}
function check(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException($msg);echo "PASS $msg\n";}
function deny(callable $fn,string $msg):void {try{$fn();}catch(think\exception\ValidateException $e){check($e->getMessage()==='当前岗位无会员管理权限',$msg);return;}throw new RuntimeException('NOT_DENIED '.$msg);}
$p='R3_'.bin2hex(random_bytes(5));$scope=new PlatformMemberScopeServices();$center=new PlatformMemberCenterServices();
Db::startTrans();try{
$menu=(int)Db::name('system_menus')->where('unique_auth','admin-user-user-index')->where('type',1)->where('is_del',0)->value('id');check($menu>0,'native menu exists');
$role=(int)Db::name('system_role')->insertGetId(seed('system_role',['role_name'=>$p,'rules'=>(string)$menu,'status'=>1]));
$admin=(int)Db::name('system_admin')->insertGetId(seed('system_admin',['account'=>$p,'roles'=>'','level'=>1,'admin_type'=>0,'status'=>1,'is_del'=>0]));
deny(fn()=>$scope->build($admin),'empty role denies access');
$menus=app()->make(app\services\system\SystemMenusServices::class);
[$tree,$unique]=$menus->getMenusList([],1,1,0);check(!$tree&&!$unique,'no implicit member navigation for empty role');
deny(fn()=>$center->bootstrap($admin),'bootstrap denies access');
deny(fn()=>$center->action($admin,'query-members',[]),'query denies access');
Db::name('system_admin')->where('id',$admin)->update(['roles'=>(string)$role]);
[$tree,$unique]=$menus->getMenusList([(string)$role],1,1,0);check(in_array('admin-user-user-index',$unique,true),'explicit role exposes native member navigation');
$allowed=$scope->build($admin);check($allowed['permissions']['edit']&&count($allowed['stores'])===(int)Db::name('system_store')->where('is_del',0)->count(),'grant retains operations and full store scope');
Db::name('system_role')->where('id',$role)->update(['status'=>0]);deny(fn()=>$scope->build($admin),'disabled role immediately denied');
Db::name('system_role')->where('id',$role)->update(['status'=>1]);
$employee=(int)Db::name('employee')->insertGetId(seed('employee',['name'=>$p,'phone'=>'199'.random_int(10000000,99999999),'status'=>1,'is_del'=>0]));
Db::name('system_admin')->where('id',$admin)->update(['employee_id'=>$employee]);
deny(fn()=>$scope->build($admin),'employee cannot inherit stale granting role');
$position=(int)Db::name('position')->insertGetId(seed('position',['name'=>$p,'status'=>1,'use_platform'=>1]));
Db::name('staff_job_position')->insert(seed('staff_job_position',['employee_id'=>$employee,'staff_id'=>0,'store_id'=>0,'position_id'=>$position,'status'=>1,'is_del'=>0,'end_time'=>0]));
$channel=(int)Db::name('job_position_channel_rule')->insertGetId(seed('job_position_channel_rule',['position_id'=>$position,'channel'=>'platform','rules'=>(string)$menu,'status'=>1]));
check($scope->build($admin)['permissions']['read'],'current job grants member access');
Db::name('job_position_channel_rule')->where('id',$channel)->update(['rules'=>'']);
foreach(['read','create','edit','deactivate']as$op)deny(fn()=>$scope->build($admin,$op),'job revocation denies '.$op);
Db::name('system_admin')->where('id',$admin)->update(['level'=>0]);check($scope->build($admin)['permissions']['read'],'real root remains authorized');
Db::name('system_admin')->where('id',$admin)->update(['level'=>1,'account'=>'admin_'.$p]);deny(fn()=>$scope->build($admin),'account name does not confer root');
}finally{Db::rollback();}
check(!Db::name('system_admin')->where('id',$admin)->count(),'synthetic transaction rolled back');
