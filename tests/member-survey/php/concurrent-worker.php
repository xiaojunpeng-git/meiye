<?php
declare(strict_types=1);
/** 独立PHP连接并发重放合成任务；只允许测试专属本地库，不创建/修改正式实例。 */
$root=getenv('BACKEND_ROOT')?:getcwd();require$root.'/vendor/autoload.php';
spl_autoload_register(static function(string$c)use($root):void{if(strpos($c,'app\\')!==0)return;$p=$root.'/app/'.str_replace('\\','/',substr($c,4)).'.php';if(is_file($p))require$p;},true,true);
$app=new think\App($root.'/');if($env=getenv('LOCAL_TEST_ENV'))$app->env->load($env);$app->initialize();
if(!in_array(config('database.connections.mysql.hostname'),['mysql','mohe-mysql'],true)||!preg_match('/^r12_survey_[a-f0-9]{10}$/D',$argv[1]??''))throw new RuntimeException('isolated local database required');
think\facade\Db::execute('USE `'.$argv[1].'`');
$result=(new app\services\user\MemberSurveyServices())->submit(101,(int)$argv[2],['1'=>'并发答卷'],1);
echo json_encode(['worker_passed'=>true,'result'=>$result],JSON_UNESCAPED_UNICODE).PHP_EOL;
