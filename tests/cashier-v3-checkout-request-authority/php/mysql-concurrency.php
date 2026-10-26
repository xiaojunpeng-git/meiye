<?php
declare(strict_types=1);

require __DIR__ . '/mysql-fixture.php';

use think\facade\Db;

$passed = 0;
$failed = 0;
function checkoutConcurrencyOk(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}" . ($detail === '' ? '' : ": {$detail}") . "\n";
}
function checkoutConcurrencyStart(string $label, string $coord): array
{
    $command = escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg(__DIR__ . '/mysql-concurrency-worker.php') . ' '
        . escapeshellarg($label);
    $pipes = [];
    putenv('CHECKOUT_REQUEST_CONCURRENCY_DIR=' . $coord);
    $_ENV['CHECKOUT_REQUEST_CONCURRENCY_DIR'] = $coord;
    $_SERVER['CHECKOUT_REQUEST_CONCURRENCY_DIR'] = $coord;
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('CHECKOUT_WORKER_START_FAILED:' . $label);
    }
    return ['process' => $process, 'pipes' => $pipes, 'label' => $label];
}
function checkoutConcurrencyFinish(array $worker): array
{
    $stdout = (string)stream_get_contents($worker['pipes'][1]);
    $stderr = (string)stream_get_contents($worker['pipes'][2]);
    fclose($worker['pipes'][1]);
    fclose($worker['pipes'][2]);
    $exit = proc_close($worker['process']);
    $matches = [];
    $result = null;
    if (preg_match('/^CHECKOUT_WORKER_RESULT=(\{.*\})$/m', $stdout, $matches) === 1) {
        $decoded = json_decode($matches[1], true);
        $result = is_array($decoded) ? $decoded : null;
    }
    return compact('exit', 'stdout', 'stderr', 'result');
}

$coord = getenv('CHECKOUT_REQUEST_CONCURRENCY_DIR') ?: sys_get_temp_dir() . '/checkout-request-concurrency';
if (!is_dir($coord) && !mkdir($coord, 0777, true) && !is_dir($coord)) {
    throw new RuntimeException('CHECKOUT_CONCURRENCY_DIR_CREATE_FAILED');
}
foreach (glob($coord . '/*') ?: [] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}
checkoutAuthorityReset();

$workerA = checkoutConcurrencyStart('A', $coord);
$workerB = checkoutConcurrencyStart('B', $coord);
$deadline = microtime(true) + 20.0;
while ((!is_file($coord . '/ready-A') || !is_file($coord . '/ready-B'))
    && microtime(true) < $deadline) {
    usleep(20000);
}
$bothReady = is_file($coord . '/ready-A') && is_file($coord . '/ready-B');
checkoutConcurrencyOk('both independent PHP workers reach the create barrier', $bothReady);
file_put_contents($coord . '/release', 'go');

$finishedA = checkoutConcurrencyFinish($workerA);
$finishedB = checkoutConcurrencyFinish($workerB);
$diagnostic = 'A=' . trim($finishedA['stderr'] . ' ' . $finishedA['stdout'])
    . ' B=' . trim($finishedB['stderr'] . ' ' . $finishedB['stdout']);
checkoutConcurrencyOk('both concurrent creation attempts complete',
    $finishedA['exit'] === 0 && $finishedB['exit'] === 0,
    $diagnostic);

$resultA = $finishedA['result'];
$resultB = $finishedB['result'];
$replays = is_array($resultA) && is_array($resultB)
    ? [$resultA['replayed'], $resultB['replayed']]
    : [];
sort($replays);
checkoutConcurrencyOk('concurrent same-key creation has one write and one replay',
    $replays === [false, true]
        && $resultA['requestId'] === $resultB['requestId']
        && $resultA['requestVersion'] === 1
        && $resultB['requestVersion'] === 1,
    $diagnostic);
checkoutConcurrencyOk('database unique keys leave one exact aggregate',
    (int)Db::name('cashier_v3_checkout_request')->count() === 1
        && (int)Db::name('cashier_v3_checkout_line_draft')->count() === 2
        && (int)Db::name('cashier_v3_checkout_payment_draft')->count() === 0
        && (int)Db::name('cashier_v3_checkout_source_reference')->count() === 1);

echo "CHECKOUT_REQUEST_CONCURRENCY passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
