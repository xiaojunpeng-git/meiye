<?php
/**
 * F5 Worker B：正式 bindStoreToOrg 路径（需等待结构锁）
 * 用法：php worker-b.php <storeId> <orgId>
 */
declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';
$app = new think\App();
$app->initialize();

use app\services\organization\OrganizationManageServices;

$storeId = (int)($argv[1] ?? 0);
$orgId = (int)($argv[2] ?? 0);
if ($storeId <= 0 || $orgId <= 0) {
    fwrite(STDERR, "USAGE\n");
    exit(2);
}

fwrite(STDOUT, 'B_START ' . sprintf('%.6f', microtime(true)) . "\n");
fflush(STDOUT);
try {
    /** @var OrganizationManageServices $manage */
    $manage = app()->make(OrganizationManageServices::class);
    $manage->bindStoreToOrg($storeId, $orgId, 1, 'o4concB', []);
    fwrite(STDOUT, 'B_DONE ' . sprintf('%.6f', microtime(true)) . "\n");
    fflush(STDOUT);
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'B_ERR ' . $e->getMessage() . "\n");
    fwrite(STDOUT, 'B_ERR ' . $e->getMessage() . "\n");
    fflush(STDOUT);
    exit(5);
}
