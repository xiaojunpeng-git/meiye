<?php
declare(strict_types=1);

require_once __DIR__ . '/mysql-bootstrap.php';

use think\facade\Db;

mbaMysqlBoot();
Db::name('balance_test_barrier')->where('barrier_id', 'member-balance-concurrency')->delete();
mbaSeedMember(201, '100.00', '0.00');

$tmp = sys_get_temp_dir() . '/cashier-v3-balance-' . getmypid();
if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) {
    throw new RuntimeException('cannot create concurrency temp directory');
}
$workers = [];
foreach (['A', 'B'] as $token) {
    $resultFile = $tmp . '/result-' . $token . '.json';
    $stdout = $tmp . '/stdout-' . $token . '.log';
    $stderr = $tmp . '/stderr-' . $token . '.log';
    $command = [
        PHP_BINARY,
        __DIR__ . '/concurrency-worker.php',
        $token,
        'CHECKOUT-BALANCE-CONCURRENT-' . $token,
        $resultFile,
    ];
    $process = proc_open($command, [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', $stdout, 'w'],
        2 => ['file', $stderr, 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('cannot start balance concurrency worker');
    }
    $workers[] = compact('token', 'resultFile', 'stdout', 'stderr', 'process');
}

$deadline = microtime(true) + 15;
while (microtime(true) < $deadline) {
    $ready = (int)Db::name('balance_test_barrier')
        ->where('barrier_id', 'member-balance-concurrency')
        ->count();
    if ($ready === 2) {
        break;
    }
    usleep(20000);
}
if ((int)Db::name('balance_test_barrier')->where('barrier_id', 'member-balance-concurrency')->count() !== 2) {
    throw new RuntimeException('concurrency workers did not reach barrier');
}
Db::name('balance_test_barrier')
    ->where('barrier_id', 'member-balance-concurrency')
    ->update(['released' => 1]);

$results = [];
foreach ($workers as $worker) {
    $exit = proc_close($worker['process']);
    $raw = is_file($worker['resultFile']) ? (string)file_get_contents($worker['resultFile']) : '';
    $decoded = json_decode($raw, true);
    if ($exit !== 0 || !is_array($decoded)) {
        throw new RuntimeException(
            'worker failed: ' . $worker['token'] . ' ' . (string)file_get_contents($worker['stderr'])
        );
    }
    $results[] = $decoded;
}

$statuses = array_column($results, 'status');
$reasons = array_column($results, 'reason');
$member = mbaMember(201);
$ok = count(array_filter($statuses, function ($status): bool { return $status === 'success'; })) === 1
    && count(array_filter($reasons, function ($reason): bool {
        return $reason === 'member_balance_version_conflict';
    })) === 1
    && (string)$member['now_money'] === '20.00'
    && (string)$member['ben_money'] === '20.00'
    && (string)$member['give_money'] === '0.00'
    && (int)$member['balance_version'] === 2
    && (int)Db::name('user_money')->where('uid', 201)->count() === 1;

foreach (glob($tmp . '/*') ?: [] as $file) {
    unlink($file);
}
rmdir($tmp);

if (!$ok) {
    fwrite(STDERR, 'CONCURRENCY_RESULTS=' . json_encode($results) . "\n");
    exit(1);
}
echo "CHECKOUT_BALANCE_CONCURRENCY=PASS\n";
