<?php
/**
 * 模拟 Swoole Worker：进程内保留请求缓存，但不调用 clearSourceModeCache。
 * argv: readyFile goFile outFile
 */
declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';

$app = new think\App();
$app->initialize();

use app\services\organization\OrganizationScopeService;

$ready = (string)($argv[1] ?? '');
$go = (string)($argv[2] ?? '');
$out = (string)($argv[3] ?? '');

/** @var OrganizationScopeService $scope */
$scope = app()->make(OrganizationScopeService::class);

$modeBefore = $scope->getSourceMode();
$verBefore = OrganizationScopeService::getSourceModeVersionPublic();
file_put_contents($ready, json_encode([
    'mode' => $modeBefore,
    'ver' => $verBefore,
    'pid' => getmypid(),
], JSON_UNESCAPED_UNICODE));

$deadline = time() + 20;
while (time() < $deadline && !is_file($go)) {
    usleep(30000);
}

// 关键：此处不调用 clearSourceModeCache，仅依赖 Redis 版本失效请求内缓存
$modeAfter = $scope->getSourceMode();
$verAfter = OrganizationScopeService::getSourceModeVersionPublic();

file_put_contents($out, json_encode([
    'mode_before' => $modeBefore,
    'mode_after' => $modeAfter,
    'ver_before' => $verBefore,
    'ver_after' => $verAfter,
    'did_not_call_clear_in_worker' => true,
    'pid' => getmypid(),
], JSON_UNESCAPED_UNICODE));
exit(0);
