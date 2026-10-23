<?php
declare(strict_types=1);
/** 两个独立PHP进程验证真实MySQL锁和回执；仅调用隔离库的既有领域服务。 */
require '/var/www/html/vendor/autoload.php';$app=new think\App('/var/www/html/');$app->setEnvName('growth-local');$app->setRuntimePath('/var/www/html/runtime/growth-local/');$app->initialize();
if(config('database.connections.mysql.database')!=='growth_qa_20261023')throw new RuntimeException('仅允许隔离测试库');
$input=json_decode(stream_get_contents(STDIN),true)?:[];$mode=$input['mode']??'';$payload=(array)($input['payload']??[]);$c=app()->make(app\services\growth\GrowthAccessServices::class)->principal(91002,'employee:91002');
try{if($mode==='exam')$r=app()->make(app\services\growth\GrowthExamServices::class)->answer($c,$payload,true);elseif($mode==='work')$r=app()->make(app\services\growth\GrowthPracticeServices::class)->submit($c,$payload);else throw new RuntimeException('并发测试模式无效');echo json_encode(['ok'=>true,'result'=>$r],JSON_UNESCAPED_UNICODE).PHP_EOL;}catch(Throwable $e){echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE).PHP_EOL;}
