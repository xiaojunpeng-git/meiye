<?php
/** 008只读实际表单回归：不输出KEY值、不保存模拟密钥、不改缓存或业务数据。 */
$root='/www/wwwroot/008.cc3798.com';require $root.'/vendor/autoload.php';(new think\App($root.'/'))->initialize();
if(config('database.connections.mysql.database')!=='mohe_008'||(string)config('database.connections.mysql.hostport')!=='3307')throw new RuntimeException('INSTANCE_MISMATCH');
think\facade\Db::execute('SET TRANSACTION READ ONLY');think\facade\Db::startTrans();
try{
 $service=app()->make(app\services\system\config\SystemConfigServices::class);$form=$service->thirdPartyFormBuild();$text=json_encode($form,JSON_UNESCAPED_UNICODE);
 foreach(['腾讯地图KEY','快递查询密钥','阿里云物流查询','99Api']as $label)if(strpos($text,$label)===false)throw new RuntimeException('FORM_DEFINITION_MISSING');
 $rows=think\facade\Db::name('system_config')->where('is_store',0)->whereIn('menu_name',['tengxun_map_key','system_express_app_code','logistics_type','system_product_copy_type'])->select()->toArray();
 if(count($rows)!==4)throw new RuntimeException('DEFINITION_COUNT');
 echo json_encode(['site'=>'008','result'=>'PASS','actualFormLoaded'=>true,'definitions'=>count($rows),'productionTestWrites'=>0,'codeDeployment'=>'not required']),PHP_EOL;
}finally{think\facade\Db::rollback();}
