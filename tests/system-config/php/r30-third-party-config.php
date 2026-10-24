<?php
/** 仅本地回滚测试：复现缺定义的假保存，迁移后走真实控制器保存，验证重跑保值。 */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
use app\services\system\config\SystemConfigServices;
if(config('database.connections.mysql.hostname')!=='mysql'||config('database.connections.mysql.database')!=='ruihao')throw new RuntimeException('LOCAL_ONLY');
function check30($ok,$msg){if(!$ok)throw new RuntimeException($msg);echo 'PASS '.$msg.PHP_EOL;}
/** 仅测试注入请求，调用未修改的真实保存方法；此测试不替代HTTP权限验收。 */
class R30ConfigController extends app\controller\admin\v1\system\config\SystemConfig {
 public function __construct($request,$service){$this->request=$request;$this->services=$service;}
}
$keys=['tengxun_map_key','system_express_app_code','logistics_type','system_product_copy_type'];
$sql=file_get_contents('/tmp/r30-third-party-config.sql');$sql=preg_replace('/^--.*$/m','',$sql);$statements=array_filter(array_map('trim',explode(';',$sql)));
$before=Db::name('system_config')->whereIn('menu_name',$keys)->order('id')->select()->toArray();
Db::startTrans();
try{
 Db::name('system_config')->whereIn('menu_name',$keys)->where('is_store',0)->delete();
 $service=app()->make(SystemConfigServices::class);$request=new app\Request();$request->withPost(['tengxun_map_key'=>'R30-local-test-only']);$controller=new R30ConfigController($request,$service);
 $controller->save_basics($request);
 check30(!Db::name('system_config')->where('menu_name','tengxun_map_key')->where('is_store',0)->find(),'missing definition reproduces skipped save');
 foreach($statements as $statement)Db::execute($statement);
 check30(Db::name('system_config')->whereIn('menu_name',$keys)->where('is_store',0)->count()===4,'all four missing definitions restored');
 $controller->save_basics($request);
 check30(json_decode(Db::name('system_config')->where('menu_name','tengxun_map_key')->where('is_store',0)->value('value'),true)==='R30-local-test-only','real save controller persists map input');
 $form=$service->thirdPartyFormBuild();$formJson=json_encode($form,JSON_UNESCAPED_UNICODE);
 check30(strpos($formJson,'腾讯地图KEY')!==false&&strpos($formJson,'R30-local-test-only')!==false,'fresh form reads saved map key and title');
 check30(strpos($formJson,'阿里云物流查询')!==false&&strpos($formJson,'99Api')!==false,'other restored selectors retain options');
 $once=Db::name('system_config')->order('id')->select()->toArray();
 foreach($statements as $statement)Db::execute($statement);
 check30(Db::name('system_config')->order('id')->select()->toArray()===$once,'repeat migration preserves every existing row and saved value');
}finally{Db::rollback();\mohe\services\SystemConfigService::clear();}
check30(Db::name('system_config')->whereIn('menu_name',$keys)->order('id')->select()->toArray()===$before,'all local test changes rolled back');
echo "R30 THIRD PARTY CONFIG PASS\n";
