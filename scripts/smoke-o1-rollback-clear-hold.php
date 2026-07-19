<?php
/**
 * clearSourceModeCache 在 INCR 后、rollback 审计前持锁暂停。
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

$result = ['pid' => getmypid(), 'ok' => 0, 'error' => ''];
try {
    OrganizationScopeService::setTestHold('after_incr_before_rollback_audit', $ready, $go);
    OrganizationScopeService::clearSourceModeCache();
    OrganizationScopeService::clearTestHold();
    $result['ok'] = 1;
} catch (\Throwable $e) {
    $result['error'] = $e->getMessage();
    OrganizationScopeService::clearTestHold();
}
file_put_contents($out, json_encode($result, JSON_UNESCAPED_UNICODE));
exit(0);
