<?php
declare(strict_types=1);

// Compare the same local, read-only fact population before/after optimization.
// Never run this benchmark against a customer instance or write business data.
require '/var/www/html/vendor/autoload.php';
(new \think\App('/var/www/html/'))->initialize();
$db = config('database.connections.mysql');
if (($db['hostname'] ?? '') !== 'mysql' || ($db['database'] ?? '') !== 'ruihao') {
    throw new RuntimeException('Local ruihao database required');
}
$stores = array_map('intval', \think\facade\Db::name('system_store')->where('is_del', 0)->column('id'));
$queries = [];
\think\facade\Db::listen(static function ($sql, $elapsed) use (&$queries): void {
    $queries[] = ['ms' => round((float)$elapsed * 1000, 2), 'sql' => (string)$sql];
});
\think\facade\Db::execute('SET TRANSACTION READ ONLY');
\think\facade\Db::startTrans();
try {
    $start = microtime(true);
    $result = (new \app\services\report\CustomerAnalyticsServices())->read(
        'customer_item_analysis', $stores,
        ['start' => $argv[1] ?? '2026-10-01', 'end' => $argv[2] ?? '2026-10-31'], [], true);
    if (getenv('EXPLAIN_ITEM')) {
        $captured = $queries;
        foreach ($captured as $q) if (str_starts_with($q['sql'], 'SELECT sv.service_fact_id')) {
            echo json_encode(['service_sql' => $q['sql'], 'explain' => \think\facade\Db::query('EXPLAIN ' . $q['sql'])], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
        }
    }
    usort($queries, static fn($a, $b) => $b['ms'] <=> $a['ms']);
    echo json_encode(['ms' => round((microtime(true) - $start) * 1000, 2),
        'queries' => count($queries), 'rows' => count($result['records']),
        'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
        'summary' => $result['summary_row'], 'slow_queries' => array_map(static fn($q) => ['ms' => $q['ms'], 'sql' => substr($q['sql'], 0, 180)], array_slice($queries, 0, 5))],
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} finally {
    \think\facade\Db::rollback();
}
