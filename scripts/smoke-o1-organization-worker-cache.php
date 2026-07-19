<?php
/**
 * O1 跨进程（模拟多 Worker）source mode 缓存：
 * - WorkerA 先读取并缓存 mode
 * - Coordinator 在另一进程 publishSourceMode
 * - WorkerA 再次 getSourceMode，必须读到新 mode（依赖 Redis 版本，而非本进程 clear）
 *
 * docker cp 美容源码/scripts/smoke-o1-organization-worker-cache.php mohe-app:/tmp/
 * docker cp 美容源码/scripts/smoke-o1-organization-worker-cache-worker.php mohe-app:/tmp/
 * docker exec mohe-app php /tmp/smoke-o1-organization-worker-cache.php
 */
declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';

$app = new think\App();
$app->initialize();

use app\services\organization\OrganizationScopeService;

$worker = '/tmp/smoke-o1-organization-worker-cache-worker.php';
if (!is_file($worker)) {
    fwrite(STDERR, "missing worker\n");
    exit(2);
}

$ready = '/tmp/o1_mode_worker_ready';
$go = '/tmp/o1_mode_worker_go';
$out = '/tmp/o1_mode_worker_out.json';
@unlink($ready);
@unlink($go);
@unlink($out);

// 先发布 legacy，保证起点一致
OrganizationScopeService::publishSourceMode(OrganizationScopeService::MODE_LEGACY);
$ver1 = OrganizationScopeService::getSourceModeVersionPublic();

$cmd = 'php ' . escapeshellarg($worker) . ' '
    . escapeshellarg($ready) . ' '
    . escapeshellarg($go) . ' '
    . escapeshellarg($out)
    . ' >/tmp/o1_mode_worker.log 2>&1 &';
exec($cmd);

$deadline = time() + 20;
while (time() < $deadline && !is_file($ready)) {
    usleep(30000);
}
if (!is_file($ready)) {
    echo "FAIL worker_not_ready\n";
    exit(1);
}

// 另一进程切换 mode（不调用 worker 进程内的 clear）
OrganizationScopeService::publishSourceMode(OrganizationScopeService::MODE_DUAL_READ);
$ver2 = OrganizationScopeService::getSourceModeVersionPublic();
file_put_contents($go, $ver2);

$deadline = time() + 20;
while (time() < $deadline && !is_file($out)) {
    usleep(30000);
}
$result = is_file($out) ? json_decode((string)file_get_contents($out), true) : null;

// 回滚运行时快照
OrganizationScopeService::clearSourceModeCache();

echo 'coordinator_ver1=' . $ver1 . "\n";
echo 'coordinator_ver2=' . $ver2 . "\n";
echo 'worker_result=' . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";

if (!is_array($result)) {
    echo "FAIL no_worker_result\n";
    exit(1);
}
if (($result['mode_before'] ?? '') !== OrganizationScopeService::MODE_LEGACY) {
    echo "FAIL mode_before=" . ($result['mode_before'] ?? '') . "\n";
    exit(1);
}
if (($result['mode_after'] ?? '') !== OrganizationScopeService::MODE_DUAL_READ) {
    echo "FAIL mode_after_not_switched=" . ($result['mode_after'] ?? '') . "\n";
    exit(1);
}
if (($result['ver_before'] ?? '') === ($result['ver_after'] ?? '')) {
    echo "FAIL version_not_bumped\n";
    exit(1);
}
if (empty($result['did_not_call_clear_in_worker'])) {
    echo "FAIL worker_cleared_locally\n";
    exit(1);
}

echo "PASS cross_process_source_mode_switch\n";

// 回滚验证：worker 再起一轮读 legacy
@unlink($ready);
@unlink($go);
@unlink($out);
OrganizationScopeService::publishSourceMode(OrganizationScopeService::MODE_DUAL_READ);
exec($cmd);
$deadline = time() + 20;
while (time() < $deadline && !is_file($ready)) {
    usleep(30000);
}
OrganizationScopeService::clearSourceModeCache(); // 回滚运行时 → 回落 sys_config/default legacy
file_put_contents($go, OrganizationScopeService::getSourceModeVersionPublic());
$deadline = time() + 20;
while (time() < $deadline && !is_file($out)) {
    usleep(30000);
}
$result2 = is_file($out) ? json_decode((string)file_get_contents($out), true) : null;
echo 'rollback_worker_result=' . json_encode($result2, JSON_UNESCAPED_UNICODE) . "\n";
if (!is_array($result2) || ($result2['mode_after'] ?? '') !== OrganizationScopeService::MODE_LEGACY) {
    echo "FAIL rollback_not_visible_to_worker\n";
    exit(1);
}
echo "PASS cross_process_source_mode_rollback\n";
echo "ALL_PASS\n";
exit(0);
