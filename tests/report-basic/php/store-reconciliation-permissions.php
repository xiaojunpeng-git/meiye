<?php
/** 直接验证真实控制器的单表鉴权；旧总入口不能自动获得新增财务表。 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root=getenv('BACKEND_ROOT')?:dirname(__DIR__,3).'/后端代码';require$root.'/vendor/autoload.php';
spl_autoload_register(static function(string$class)use($root):void{if(strpos($class,'app\\')!==0)return;$f=$root.'/app/'.str_replace('\\','/',substr($class,4)).'.php';if(is_file($f))require$f;},true,true);
$app=new think\App($root.'/');if($env=getenv('LOCAL_TEST_ENV'))$app->env->load($env);$app->initialize();
set_exception_handler(static function(Throwable$error):void{fwrite(STDERR,$error->getMessage()."\n");exit(1);});
$roles=new class extends app\services\system\SystemRoleServices{
 public $fixture=[];
 public function __construct(){}
 public function getRolesByAuth(array$roles,int$auth_type=1,int$type=1,string$cachePrefix=self::ADMIN_RULES_LEVEL){return$this->fixture;}
};
app()->instance(app\services\system\SystemRoleServices::class,$roles);
$controller=(new ReflectionClass(app\controller\admin\v1\report\UnifiedReport::class))->newInstanceWithoutConstructor();
foreach(['adminInfo'=>['id'=>41,'level'=>1,'roles'=>[1]],'adminType'=>0]as$name=>$value){$p=new ReflectionProperty(app\controller\admin\AuthController::class,$name);$p->setAccessible(true);$p->setValue($controller,$value);}
$gate=new ReflectionMethod($controller,'canReadBasicReport');$gate->setAccessible(true);$n=0;
foreach([false,true]as$flag){
 $app->config->set(['platform_menu_all_stores'=>$flag],'basic_report');
 $roles->fixture=[['unique_auth'=>'admin-report-basic']];
 if($gate->invoke($controller,'basic_store_reconciliation'))throw new RuntimeException('旧总入口扩出新增财务权限');$n++;
 $roles->fixture=[['unique_auth'=>'admin-report-basic_store_summary']];
 if($gate->invoke($controller,'basic_store_reconciliation'))throw new RuntimeException('相邻报表扩出新增财务权限');$n++;
 $roles->fixture=[['unique_auth'=>'admin-report-basic_store_reconciliation']];
 if(!$gate->invoke($controller,'basic_store_reconciliation'))throw new RuntimeException('独立叶子授权不能查询');$n++;
}
$feature=app\services\organization\StorePermissionCatalogServices::basicReportFeatureCode('basic_store_reconciliation');
if(!app\services\report\BasicReportHostServices::canReadStoreReport('basic_store_reconciliation',[$feature]))throw new RuntimeException('门店新叶子未生效');$n++;
if(app\services\report\BasicReportHostServices::canReadStoreReport('basic_store_reconciliation',['cashier.v3.basic_reports']))throw new RuntimeException('门店分组扩大单表权限');$n++;
echo json_encode(['passed'=>true,'checks'=>$n])."\n";
