<?php
/**
 * 长驻 Worker：先缓存 isMigrated=true，再在 clear 的 INCR↔rollback 窗口内复测。
 * argv: readyFile probeGoFile probeOutFile finalGoFile finalOutFile
 */
declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';

$app = new think\App();
$app->initialize();

use app\services\organization\OrganizationScopeService;

$ready = (string)($argv[1] ?? '');
$probeGo = (string)($argv[2] ?? '');
$probeOut = (string)($argv[3] ?? '');
$finalGo = (string)($argv[4] ?? '');
$finalOut = (string)($argv[5] ?? '');

/** @var OrganizationScopeService $scope */
$scope = app()->make(OrganizationScopeService::class);

$mig0 = $scope->isMigrated();
$mode0 = $scope->getSourceMode();
$ver0 = OrganizationScopeService::getSourceModeVersionPublic();
file_put_contents($ready, json_encode([
    'pid' => getmypid(),
    'is_migrated' => $mig0,
    'mode' => $mode0,
    'ver' => $ver0,
], JSON_UNESCAPED_UNICODE));

$deadline = time() + 25;
while (time() < $deadline && !is_file($probeGo)) {
    usleep(20000);
}

// 关键：不清本地缓存；依赖版本变化 + runtime=legacy 过渡态
$mig1 = $scope->isMigrated();
$mode1 = $scope->getSourceMode();
$ver1 = OrganizationScopeService::getSourceModeVersionPublic();
file_put_contents($probeOut, json_encode([
    'pid' => getmypid(),
    'is_migrated' => $mig1,
    'mode' => $mode1,
    'ver' => $ver1,
    'did_not_clear_local_cache' => true,
], JSON_UNESCAPED_UNICODE));

$deadline = time() + 25;
while (time() < $deadline && !is_file($finalGo)) {
    usleep(20000);
}

$mig2 = $scope->isMigrated();
$mode2 = $scope->getSourceMode();
$ver2 = OrganizationScopeService::getSourceModeVersionPublic();
file_put_contents($finalOut, json_encode([
    'pid' => getmypid(),
    'is_migrated' => $mig2,
    'mode' => $mode2,
    'ver' => $ver2,
    'did_not_clear_local_cache' => true,
], JSON_UNESCAPED_UNICODE));
exit(0);
