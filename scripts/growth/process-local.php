<?php
declare(strict_types=1);
/** 仅隔离本地库的恢复与奖励验证入口；生产使用同一服务接项目任务调度。 */
require '/var/www/html/vendor/autoload.php';
$app=new think\App('/var/www/html/');$app->setEnvName('growth-local');$app->setRuntimePath('/var/www/html/runtime/growth-local/');$app->initialize();
if(config('database.connections.mysql.database')!=='growth_qa_20261023')throw new RuntimeException('拒绝非隔离本地库');
echo json_encode(app()->make(app\services\growth\GrowthProcessServices::class)->tick(500),JSON_UNESCAPED_UNICODE).PHP_EOL;
