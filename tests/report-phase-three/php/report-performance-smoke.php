<?php

declare(strict_types=1);

$repoRoot = dirname(__DIR__, 3);
$backend = is_dir('/workspace/后端代码') ? '/workspace/后端代码'
    : (is_dir('/var/www/html/app') ? '/var/www/html' : $repoRoot . '/后端代码');
require $backend . '/vendor/autoload.php';

use app\services\report\StoreUnifiedReportPhaseThreeServices;
use think\facade\Config;
use think\facade\Db;

$app = new \think\App($backend . '/');
foreach ([
    'cache.driver' => 'file', 'CACHE_DRIVER' => 'file',
    'database.type' => 'mysql', 'DATABASE_TYPE' => 'mysql',
    'database.hostname' => getenv('DB_HOST') ?: 'mysql',
    'DATABASE_HOSTNAME' => getenv('DB_HOST') ?: 'mysql',
    'database.hostport' => getenv('DB_PORT') ?: '3306',
    'DATABASE_HOSTPORT' => getenv('DB_PORT') ?: '3306',
    'database.database' => getenv('DB_DATABASE') ?: 'ruihao',
    'DATABASE_DATABASE' => getenv('DB_DATABASE') ?: 'ruihao',
    'database.username' => getenv('DB_USERNAME') ?: 'root',
    'DATABASE_USERNAME' => getenv('DB_USERNAME') ?: 'root',
    'database.password' => getenv('DB_PASSWORD') ?: 'localdev123',
    'DATABASE_PASSWORD' => getenv('DB_PASSWORD') ?: 'localdev123',
] as $key => $value) {
    $app->env->set($key, $value);
}
$envName = new ReflectionProperty($app, 'envName');
$envName->setAccessible(true);
$envName->setValue($app, 'phase_three_report_performance_skip_dotenv_reload');
$app->initialize();
Config::set(['default' => 'file'], 'cache');
Db::connect('mysql', true)->query('SELECT 1');

$stores = array_values(array_map('intval', Db::name('system_store')
    ->where('is_del', 0)->where('name', '<>', '总部')->order('id', 'asc')->column('id')));
if ($stores === []) {
    throw new RuntimeException('六维报表性能烟测至少需要一个本地门店');
}

$createdSixDimensionRoot = false;
Db::startTrans();
if (!Db::name('store_product_category')->where('pid', 0)->where('is_show', 1)
    ->where('cate_name', '六维')->find()) {
    Db::name('store_product_category')->insert([
        'pid' => 0, 'type' => 0, 'relation_id' => 0, 'sync_cate_id' => 0,
        'cate_name' => '六维', 'path' => '', 'level' => 0, 'sort' => 0,
        'pic' => '', 'is_show' => 1, 'mobile_card_show' => 1,
        'add_time' => time(), 'big_pic' => '', 'adv_pic' => '', 'adv_link' => '',
    ]);
    $createdSixDimensionRoot = true;
}

$captured = [];
Db::listen(static function (string $sql, string $runtime) use (&$captured): void {
    $normalized = trim($sql);
    if (stripos($normalized, 'select ') !== 0 || preg_match('/^SELECT\s+1\s*$/i', $normalized)) {
        return;
    }
    $captured[] = ['sql' => $normalized, 'runtime_ms' => (float)$runtime * 1000];
});

$service = new StoreUnifiedReportPhaseThreeServices();
$input = [
    'page' => 1,
    'limit' => 100,
    '_authorized_store_ids' => $stores,
    '_report_scope' => ['mode' => 'all'],
    '_internal_all' => true,
];
$reportTimes = [];
foreach (StoreUnifiedReportPhaseThreeServices::reportCodes() as $reportCode) {
    $range = $reportCode === 'six_dimension_performance_distribution'
        ? ['start' => '2026-10-01', 'end' => '2026-10-31']
        : ['start' => StoreUnifiedReportPhaseThreeServices::COVERAGE_START, 'end' => StoreUnifiedReportPhaseThreeServices::COVERAGE_START];
    $startedAt = microtime(true);
    $service->query($reportCode, $stores, $range, $input);
    $reportTimes[$reportCode] = (microtime(true) - $startedAt) * 1000;
}

$unique = [];
foreach ($captured as $entry) {
    $fingerprint = hash('sha256', preg_replace('/\s+/', ' ', $entry['sql']));
    if (!isset($unique[$fingerprint]) || $entry['runtime_ms'] > $unique[$fingerprint]['runtime_ms']) {
        $unique[$fingerprint] = $entry;
    }
}

$failed = 0;
$explained = 0;
$fullScanRisks = [];
$slowSql = [];
$explainConnection = Db::connect('mysql');
foreach ($unique as $entry) {
    if ($entry['runtime_ms'] > 1000) {
        $slowSql[] = $entry['runtime_ms'];
    }
    try {
        $plan = $explainConnection->query('EXPLAIN ' . $entry['sql']);
    } catch (Throwable $error) {
        $failed++;
        echo 'FAIL EXPLAIN: ' . $error->getMessage() . "\n";
        continue;
    }
    $explained++;
    foreach ($plan as $row) {
        $accessType = strtoupper((string)($row['type'] ?? ''));
        $estimatedRows = (int)($row['rows'] ?? 0);
        if ($accessType === 'ALL' && $estimatedRows >= 10000) {
            $fullScanRisks[] = [
                'table' => (string)($row['table'] ?? '-'),
                'rows' => $estimatedRows,
                'extra' => (string)($row['Extra'] ?? ''),
            ];
        }
    }
}

foreach ($reportTimes as $reportCode => $milliseconds) {
    if ($milliseconds > 3000) {
        $failed++;
        echo 'FAIL ' . $reportCode . ' query_ms=' . number_format($milliseconds, 2, '.', '') . "\n";
    } else {
        echo 'PASS ' . $reportCode . ' query_ms=' . number_format($milliseconds, 2, '.', '') . "\n";
    }
}
if ($slowSql !== []) {
    $failed++;
    echo 'FAIL SQL statements above 1000ms=' . count($slowSql) . "\n";
}
if ($fullScanRisks !== []) {
    $failed++;
    foreach ($fullScanRisks as $risk) {
        echo 'FAIL EXPLAIN large full scan table=' . $risk['table']
            . ' rows=' . $risk['rows'] . ' extra=' . $risk['extra'] . "\n";
    }
}
if ($explained === 0) {
    $failed++;
    echo "FAIL no report SQL was captured for EXPLAIN\n";
} else {
    echo 'PASS EXPLAIN statements=' . $explained
        . ' unique_selects=' . count($unique)
        . ' large_full_scans=' . count($fullScanRisks) . "\n";
}

Db::rollback();
if ($createdSixDimensionRoot
    && Db::name('store_product_category')->where('pid', 0)->where('cate_name', '六维')->count() !== 0) {
    $failed++;
    echo "FAIL performance smoke did not roll back its temporary six-dimension category\n";
}

echo "PHASE3_REPORT_PERFORMANCE_SMOKE failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
