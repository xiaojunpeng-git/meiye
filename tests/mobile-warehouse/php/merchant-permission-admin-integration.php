<?php
declare(strict_types=1);
/** 仅本地Docker：执行本轮幂等DDL，随后用回滚事务验证配置写入、回读、并发版本及审计。 */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
use app\services\mobile\warehouse\MerchantDataPermissionServices;
if(config('database.connections.mysql.hostname')!=='mysql'||config('database.connections.mysql.database')!=='ruihao')throw new RuntimeException('Local Docker only');
function adminAssert(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo 'PASS ',$label,"\n";}
foreach([app\controller\admin\v1\system\StorePermissions::class,app\controller\admin\v1\system\MerchantPermissions::class] as $class){
    $reflection=new ReflectionClass($class);$controller=$reflection->newInstanceWithoutConstructor();
    $info=new ReflectionProperty(app\controller\admin\AuthController::class,'adminInfo');$info->setAccessible(true);
    $type=new ReflectionProperty(app\controller\admin\AuthController::class,'adminType');$type->setAccessible(true);
    $check=new ReflectionMethod($class,'assertManage');$check->setAccessible(true);
    $info->setValue($controller,['id'=>1,'level'=>0,'roles'=>[]]);$type->setValue($controller,0);$check->invoke($controller);
    echo "PASS 平台超级管理员可维护 ",$class,"\n";
    $type->setValue($controller,3);
    try{$check->invoke($controller);throw new RuntimeException('store identity accepted');}catch(RuntimeException $error){adminAssert(strpos($error->getMessage(),'平台后台')!==false,'门店身份不能冒充平台管理员');}
}
$file='/var/www/html/database/upgrades/2026-11-06-商家端数据权限/02-正式升级.sql';
for($attempt=0;$attempt<2;$attempt++)foreach(explode(';',file_get_contents($file)) as $sql)if(trim($sql)!=='')Db::execute($sql);
adminAssert(Db::name('system_menus')->where('unique_auth','setting-merchant-permissions')->where('is_del',0)->count()===1,'平台独立菜单重复执行仅一条');
$menu=Db::name('system_menus')->where('unique_auth','setting-merchant-permissions')->where('is_del',0)->find();
$parent=Db::name('system_menus')->where('unique_auth','setting-system-menus')->where('type',1)->where('is_del',0)->find();
adminAssert($menu['pid']===$parent['pid'] && $menu['header']===$parent['header'] && $menu['path']===$parent['path'] && $menu['module']===$parent['module'],'新菜单继承设置权限分组且header正确');
adminAssert(Db::name('merchant_data_permission_catalog')->where('id',1)->count()===1,'目录初始化幂等且不覆盖已有配置');
$before=Db::name('merchant_data_permission_catalog')->where('id',1)->find();
$auditCount=Db::name('merchant_data_permission_audit')->count();
Db::startTrans();
try{
    // 真实普通平台岗位只持有原权限规则资格；两个独立规则入口应出现在原生菜单，但不能扩张unique接口授权。
    $testRole=Db::name('system_role')->insertGetId(['role_name'=>'r12-navigation-test','remark'=>'rollback only','rules'=>(string)$parent['id'],'cashier_rules'=>'','mall_rules'=>'','status'=>1]);
    [$nativeMenus,$nativeAuth]=app()->make(app\services\system\SystemMenusServices::class)->getMenusList((string)$testRole,1,1,0);
    $nativePaths=[];$collect=function(array $nodes)use(&$collect,&$nativePaths):void{foreach($nodes as $node){$nativePaths[]=$node['path']??'';if(!empty($node['children']))$collect($node['children']);}};$collect($nativeMenus);
    adminAssert(in_array('/setting/store-permissions',$nativePaths,true)&&in_array('/setting/merchant-permissions',$nativePaths,true),'原维护资格补齐两端原生导航');
    adminAssert(!in_array('setting-merchant-permissions',$nativeAuth,true),'补导航不扩张岗位接口授权');
    $service=new MerchantDataPermissionServices();$initial=$service->snapshot();$rules=$initial['rules'];
    foreach($rules as &$row)if($row['feature_code']==='employee.metrics.staff_sales_yeji'){$row['enabled']=0;$row['title']='现金业绩自测';$row['sort']=999;}unset($row);
    $saved=$service->save(['revision'=>$initial['revision'],'rules'=>$rules],1);
    adminAssert($saved['revision']===$initial['revision']+1,'保存配置递增revision');
    adminAssert($service->snapshot()===$saved,'保存后独立回读一致');
    adminAssert(Db::name('merchant_data_permission_audit')->count()===$auditCount+1,'审计与目录同事务写入');
    try{$service->save(['revision'=>$initial['revision'],'rules'=>$rules],1);throw new RuntimeException('stale revision accepted');}catch(RuntimeException $error){adminAssert(strpos($error->getMessage(),'刷新')!==false,'旧revision明确拒绝');}
    adminAssert($service->snapshot()===$saved,'并发冲突不覆盖已保存配置');
    $bad=$rules;$bad[0]['id']=999999;
    try{$service->save(['revision'=>$saved['revision'],'rules'=>$bad],1);throw new RuntimeException('unknown identity accepted');}catch(InvalidArgumentException $error){echo "PASS 任意规则身份拒绝\n";}
    adminAssert($service->snapshot()===$saved,'非法清单不产生半保存');
}finally{Db::rollback();}
adminAssert(Db::name('merchant_data_permission_catalog')->where('id',1)->find()===$before,'测试配置全部回滚');
adminAssert(Db::name('merchant_data_permission_audit')->count()===$auditCount,'测试审计全部回滚');
adminAssert(Db::name('system_role')->where('id',$testRole)->count()===0,'菜单测试岗位全部回滚');
