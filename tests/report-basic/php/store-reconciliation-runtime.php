<?php
/** 真实Runtime对象图/Provider/权限目录联调，空的合成门店范围不读取客户金额。 */
$root=getenv('BACKEND_ROOT')?:getcwd();require$root.'/vendor/autoload.php';
spl_autoload_register(static function(string$class)use($root):void{if(strpos($class,'app\\')!==0)return;$f=$root.'/app/'.str_replace('\\','/',substr($class,4)).'.php';if(is_file($f))require$f;},true,true);
$app=new think\App($root.'/');if($env=getenv('LOCAL_TEST_ENV'))$app->env->load($env);$app->initialize();
set_exception_handler(static function(Throwable$error):void{fwrite(STDERR,$error->getMessage()."\n");exit(1);});
if(!in_array(config('database.connections.mysql.hostname'),['mohe-mysql','mysql'],true))throw new RuntimeException('local only');
$context=app\services\report\BasicReportHostServices::context([999999999],'stores',999901,'platform',false,0,'basic_store_reconciliation');
$runtime=app\services\query\UnifiedQueryRuntime::runtime();$catalog=app\services\report\BasicReportHostServices::catalog();$checks=0;
foreach($catalog as$entry){$provider=$runtime['providers']->resolve($entry['code']);if($provider->pageCode()!==$entry['code'])throw new RuntimeException('provider mapping invalid');$checks++;}
$result=app\services\report\BasicReportHostServices::query($context,['pageCode'=>'basic_store_reconciliation','start_date'=>'2026-09-01','end_date'=>'2026-09-30']);
if($result['rows']||$result['pagination']['total']!==0||count($result['queryFields'])!==2)throw new RuntimeException('empty authorized scope leaked rows');$checks++;
if(count($catalog)!==22)throw new RuntimeException('catalog incomplete');$checks++;
echo json_encode(['passed'=>true,'checks'=>$checks,'reports'=>count($catalog)])."\n";
