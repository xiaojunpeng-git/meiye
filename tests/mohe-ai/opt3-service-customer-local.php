<?php
/** Explicit LOCAL-only, read-only verification. No business rows, names, keys or SQL leave this script. */
$mode = $argv[1] ?? '';
if (!in_array($mode, ['baseline', 'stream'], true)) throw new RuntimeException('MODE_REQUIRED');
require getcwd() . '/vendor/autoload.php';
(new think\App())->initialize();
$db = (array)config('database.connections.' . config('database.default'));
if (($db['hostname'] ?? null) !== 'mysql' || ($db['database'] ?? null) !== 'ruihao'
    || (think\facade\Db::query('SELECT @@server_uuid uuid')[0]['uuid'] ?? null) !== '3506c91f-98cb-11f1-af5f-9e1a52451c2a') throw new RuntimeException('LOCAL_INSTANCE_MISMATCH');
$admin = (int)think\facade\Db::name('system_admin')->where('account', 'admin')->value('id');
$context = (new app\services\ai\execution\AiPlatformPrincipalResolver())->authenticated($admin);
if (empty($context['can_use']) || empty($context['personnel_data_authorized'])) throw new RuntimeException('LOCAL_AUTH_REQUIRED');
$tenant = (string)($context['tenant_id'] ?? 0); $stores = $context['store_ids'];
$range = ['start' => '2026-10-01', 'end' => '2026-10-11'];
$connection = think\facade\Db::connect();
$connection->execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
$connection->startTrans();
$started = microtime(true); $hashes = []; $sizes = []; $totals = []; $compare = null;
try {
    $reader = new app\services\query\metric\ServiceCustomerMetricReadServices(static function (string $table) use ($connection) { return $connection->name($table); });
    if ($mode === 'baseline') {
        // Extra memory is allowed only in this separate reference process, to
        // compare the old detail projection. Production remains at 128MB.
        $full = $reader->employeeMetrics($tenant, $stores, $range);
        foreach (['summary_by_store_employee', 'daily_by_store_employee'] as $key) $hashes[$key] = hash('sha256', json_encode($full[$key]));
        unset($full);
    }
    $pdo = $connection->getPdo(); $before = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
    $compact = $reader->aggregateEmployeeMetrics($tenant, $stores, $range);
    $restored = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY) === $before;
    $connection->query('SELECT 1'); // An unclosed cursor would prevent the next query on this connection.
    // Consumer cancellation/contract errors also destroy a partly read cursor.
    $streamMethod = new ReflectionMethod($reader, 'aggregateRows'); $streamMethod->setAccessible(true);
    $stream = $streamMethod->invoke($reader, $tenant, $stores, $range); $stream->rewind(); unset($stream);
    $interruptedRestored = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY) === $before;
    $connection->query('SELECT 1');
    foreach ($compact as $key => $rows) { $compactHashes[$key] = hash('sha256', json_encode($rows)); $sizes[$key] = count($rows); }
    foreach (['visit_tenths', 'people_tenths', 'point_visit_tenths'] as $field) $totals[$field] = array_sum(array_column($compact['summary_by_store_employee'], $field));
    if ($mode === 'baseline') $compare = $hashes === $compactHashes;
    $connection->rollback();
} catch (Throwable $e) { $connection->rollback(); throw $e; }
$result = ['suite' => 'OPT3-service-customer-local', 'mode' => $mode, 'php' => PHP_VERSION,
    'memory_limit' => ini_get('memory_limit'), 'store_count' => count($stores), 'range' => $range,
    'elapsed_ms' => (int)round((microtime(true) - $started) * 1000), 'peak_bytes' => memory_get_peak_usage(true),
    'projection_sha256' => $compactHashes, 'reference_sha256' => $hashes,
    'row_counts' => $sizes, 'totals_tenths' => $totals, 'same_snapshot_equal' => $compare, 'pdo_buffer_restored' => $restored,
    'interrupted_cursor_restored' => $interruptedRestored,
    'pass' => $restored && $interruptedRestored && ($compare !== false)];
echo json_encode($result, JSON_UNESCAPED_SLASHES), PHP_EOL;
if (!$result['pass']) exit(1);
