<?php
declare(strict_types=1);
/** 仅本地隔离测试启动器；与当前平台相同源码，使用独立环境、数据库和运行目录。 */
require '/var/www/html/vendor/autoload.php';
define('DS',DIRECTORY_SEPARATOR);
$app=new think\App('/var/www/html/');$app->setEnvName('growth-local');$app->setRuntimePath('/var/www/html/runtime/growth-local/');
$http=$app->http;$response=$http->run();$response->send();$http->end($response);
