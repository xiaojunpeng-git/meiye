<?php
/**
 * O1 并发移动防环：两个独立 PHP 进程 / 独立连接同时把 A、B 互为父级，证明不能形成 A↔B。
 *
 * docker cp 美容源码/scripts/smoke-o1-organization-concurrent-swap.php mohe-app:/tmp/
 * docker cp 美容源码/scripts/smoke-o1-organization-concurrent-swap-worker.php mohe-app:/tmp/
 * docker exec mohe-app php /tmp/smoke-o1-organization-concurrent-swap.php
 */
declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';

$app = new think\App();
$app->initialize();

use think\facade\Db;

$worker = '/tmp/smoke-o1-organization-concurrent-swap-worker.php';
if (!is_file($worker)) {
    fwrite(STDERR, "missing worker script: {$worker}\n");
    exit(2);
}

$rootId = (int)Db::name('organization')->where('is_del', 0)->where('pid', 0)->value('id');
if ($rootId <= 0) {
    fwrite(STDERR, "no root org\n");
    exit(2);
}

$suffix = (string)time();
$aName = "__o1_conc_a_{$suffix}__";
$bName = "__o1_conc_b_{$suffix}__";
$now = time();

$aId = (int)Db::name('organization')->insertGetId([
    'pid' => $rootId,
    'name' => $aName,
    'sort' => 0,
    'legacy_manage_region_id' => 0,
    'is_del' => 0,
    'add_time' => $now,
    'update_time' => $now,
]);
$bId = (int)Db::name('organization')->insertGetId([
    'pid' => $rootId,
    'name' => $bName,
    'sort' => 0,
    'legacy_manage_region_id' => 0,
    'is_del' => 0,
    'add_time' => $now,
    'update_time' => $now,
]);

$outA = '/tmp/o1_conc_a_result.json';
$outB = '/tmp/o1_conc_b_result.json';
@unlink($outA);
@unlink($outB);

$cmdA = 'php ' . escapeshellarg($worker) . ' ' . (int)$aId . ' ' . (int)$bId . ' ' . escapeshellarg($outA) . ' >/tmp/o1_conc_a.log 2>&1 &';
$cmdB = 'php ' . escapeshellarg($worker) . ' ' . (int)$bId . ' ' . (int)$aId . ' ' . escapeshellarg($outB) . ' >/tmp/o1_conc_b.log 2>&1 &';
exec($cmdA);
exec($cmdB);

$deadline = time() + 30;
while (time() < $deadline) {
    if (is_file($outA) && is_file($outB)) {
        break;
    }
    usleep(50000);
}

$ra = is_file($outA) ? json_decode((string)file_get_contents($outA), true) : null;
$rb = is_file($outB) ? json_decode((string)file_get_contents($outB), true) : null;

$aPid = (int)Db::name('organization')->where('id', $aId)->value('pid');
$bPid = (int)Db::name('organization')->where('id', $bId)->value('pid');
$cycle = ($aPid === $bId && $bPid === $aId);

// cleanup（硬删除冒烟临时行，并尽量收回自增号）
Db::name('organization')->whereIn('id', [$aId, $bId])->delete();
$maxId = (int)Db::name('organization')->max('id');
Db::execute('ALTER TABLE `eb_organization` AUTO_INCREMENT = ' . max(1, $maxId + 1));

echo "A_result=" . json_encode($ra, JSON_UNESCAPED_UNICODE) . "\n";
echo "B_result=" . json_encode($rb, JSON_UNESCAPED_UNICODE) . "\n";
echo "final_pid A={$aPid} B={$bPid}\n";

if ($cycle) {
    echo "FAIL formed_cycle_A_B\n";
    exit(1);
}
if (!is_array($ra) || !is_array($rb)) {
    echo "FAIL missing_worker_results\n";
    exit(1);
}

$okCount = ((int)($ra['ok'] ?? 0)) + ((int)($rb['ok'] ?? 0));
$failCount = ((int)($ra['ok'] ?? 1) === 0 ? 1 : 0) + ((int)($rb['ok'] ?? 1) === 0 ? 1 : 0);

// 至少一方失败，或双方成功但最终不是互指（命名锁串行后第二次应被环校验拒绝）
if ($cycle) {
    echo "FAIL cycle\n";
    exit(1);
}
if ($okCount >= 1 && $failCount >= 1) {
    echo "PASS concurrent_swap_one_blocked ok={$okCount} fail={$failCount}\n";
    echo "ALL_PASS\n";
    exit(0);
}
if ($okCount === 2 && !$cycle && $aPid !== $bId && $bPid !== $aId) {
    // 理论上不应双方都成功改成互指；若双方都“成功”但父级仍是 root，也可能是都失败写 ok=1 误报
    echo "FAIL both_ok_unexpected final_pid A={$aPid} B={$bPid}\n";
    exit(1);
}
if ($okCount === 0) {
    // 双方都失败也可接受（争锁/环校验），只要不成环
    echo "PASS concurrent_swap_both_failed_no_cycle\n";
    echo "ALL_PASS\n";
    exit(0);
}

echo "FAIL unexpected ok={$okCount} fail={$failCount}\n";
exit(1);
