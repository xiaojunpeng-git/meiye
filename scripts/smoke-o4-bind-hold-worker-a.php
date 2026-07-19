<?php
/**
 * F5 Worker A：持结构锁 → applyBindStoreToOrg → 写 hold → 等待 release → 放锁
 * 用法：php worker-a.php <storeId> <orgId> <holdFile> <releaseFile>
 */
declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';
$app = new think\App();
$app->initialize();

use app\services\organization\OrganizationManageServices;
use think\facade\Db;

$storeId = (int)($argv[1] ?? 0);
$orgId = (int)($argv[2] ?? 0);
$holdFile = (string)($argv[3] ?? '');
$releaseFile = (string)($argv[4] ?? '');
if ($storeId <= 0 || $orgId <= 0 || $holdFile === '' || $releaseFile === '') {
    fwrite(STDERR, "USAGE\n");
    exit(2);
}

$t0 = microtime(true);
fwrite(STDOUT, 'A_START ' . sprintf('%.6f', $t0) . "\n");
fflush(STDOUT);

$got = Db::query('SELECT GET_LOCK(?, 30) AS got', ['mohe_organization_structure']);
$ok = (int)($got[0]['got'] ?? 0);
if ($ok !== 1) {
    fwrite(STDOUT, "A_LOCK_FAIL\n");
    fflush(STDOUT);
    exit(3);
}
fwrite(STDOUT, 'A_LOCK ' . sprintf('%.6f', microtime(true)) . "\n");
fflush(STDOUT);

try {
    /** @var OrganizationManageServices $manage */
    $manage = app()->make(OrganizationManageServices::class);
    Db::startTrans();
    try {
        $manage->applyBindStoreToOrg($storeId, $orgId, 1, 'o4concA', []);
        Db::commit();
    } catch (\Throwable $e) {
        Db::rollback();
        throw $e;
    }
    fwrite(STDOUT, 'A_BOUND ' . sprintf('%.6f', microtime(true)) . "\n");
    fflush(STDOUT);
    file_put_contents($holdFile, 'hold|' . sprintf('%.6f', microtime(true)));
    fwrite(STDOUT, 'A_HOLD ' . sprintf('%.6f', microtime(true)) . "\n");
    fflush(STDOUT);

    // 等待父进程 release；父进程 finally 会尽快写入，避免长期占锁
    $deadline = time() + 12;
    while (time() < $deadline) {
        if (is_file($releaseFile)) {
            fwrite(STDOUT, 'A_RELEASE_SEEN ' . sprintf('%.6f', microtime(true)) . "\n");
            fflush(STDOUT);
            break;
        }
        usleep(30000);
    }
    if (!is_file($releaseFile)) {
        fwrite(STDOUT, "A_RELEASE_TIMEOUT\n");
        fflush(STDOUT);
        exit(4);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'A_ERR ' . $e->getMessage() . "\n");
    fwrite(STDOUT, 'A_ERR ' . $e->getMessage() . "\n");
    fflush(STDOUT);
    exit(5);
} finally {
    Db::query('SELECT RELEASE_LOCK(?) AS released', ['mohe_organization_structure']);
    fwrite(STDOUT, 'A_UNLOCK ' . sprintf('%.6f', microtime(true)) . "\n");
    fflush(STDOUT);
}

fwrite(STDOUT, 'A_DONE ' . sprintf('%.6f', microtime(true)) . "\n");
fflush(STDOUT);
exit(0);
