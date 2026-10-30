<?php
/** 本地只读未消耗明细性能诊断；只输出查询形状、数量与耗时，不输出业务行。 */
declare(strict_types=1);
$root = getenv('BACKEND_ROOT') ?: getcwd();
require $root.'/vendor/autoload.php';
(new think\App($root.'/'))->initialize();
use think\facade\Db;
use app\services\report\BasicReportHostServices as Host;
$connection = config('database.connections.mysql');
if (!in_array($connection['hostname'] ?? '', ['mysql','mohe-mysql'], true)) throw new RuntimeException('Local only');
Db::execute('SET SESSION MAX_EXECUTION_TIME=8000');
Db::execute('SET SESSION TRANSACTION READ ONLY');
$store = (int)(getenv('REPORT_TEST_STORE') ?: 105);
$stats = [];
Db::listen(static function ($query, $seconds) use (&$stats): void {
    $sql = strtolower((string)$query);
    $normalized = preg_replace('/\x27(?:\x27\x27|[^\x27])*\x27|\b[0-9]+\b/', '?', $sql);
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    $key = substr(hash('sha256', $normalized), 0, 12);
    if (!isset($stats[$key])) $stats[$key] = ['count'=>0,'ms'=>0,'shape'=>substr($normalized,0,180)];
    ++$stats[$key]['count']; $stats[$key]['ms'] += (int)round((float)$seconds*1000);
});
$context = Host::context([$store], 'stores', 999901, 'platform', false, 0, 'basic_unconsumed_detail');
$day = $context['query_cutoff_date'];
$start = microtime(true);
try {
    $result = Host::query($context, ['pageCode'=>'basic_unconsumed_detail',
        'start_date'=>$day, 'end_date'=>$day, 'as_of_date'=>$day]);
    $status = 'pass'; $count = $result['pagination']['total'] ?? null;
    $complete = $result['complete'] ?? null;
} catch (Throwable $exception) {
    $status = 'fail'; $count = null; $complete = null;
}
uasort($stats, static fn(array $a, array $b): int => $b['ms'] <=> $a['ms']);
echo json_encode(['local_only'=>true,'business_writes'=>0,'store_id'=>$store,'status'=>$status,
    'row_count'=>$count,'complete'=>$complete,'elapsed_ms'=>(int)((microtime(true)-$start)*1000),
    'top_queries'=>array_slice($stats,0,20,true)], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),PHP_EOL;
Db::execute('SET SESSION TRANSACTION READ WRITE');
