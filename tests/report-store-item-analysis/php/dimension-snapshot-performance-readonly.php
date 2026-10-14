<?php

declare(strict_types=1);

// Local read-only regression: a cross-store report must not query the same
// organization assignments once per fact, and export must reuse its result.
require '/var/www/html/vendor/autoload.php';

use app\services\report\StoreUnifiedReportServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('only the local ruihao Docker database is allowed');
}

$stores = array_map('intval', Db::name('system_store')->where('is_del', 0)->column('id'));
if (count($stores) < 2) {
    throw new RuntimeException('performance regression needs multiple local stores');
}

$phase = 'query';
$dimensionSelects = ['query' => 0, 'export' => 0];
Db::listen(static function (string $sql) use (&$phase, &$dimensionSelects): void {
    if (stripos($sql, 'SELECT ') === 0
        && stripos($sql, 'report_organization_dimension') !== false) {
        $dimensionSelects[$phase]++;
    }
});

$input = [
    'report' => 'store_item_analysis',
    'start_date' => '2026-10-01',
    'end_date' => '2026-10-14',
];
$service = new StoreUnifiedReportServices();
Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
try {
    $started = microtime(true);
    $report = $service->query($stores, $input);
    $querySeconds = microtime(true) - $started;
    $phase = 'export';
    $export = $service->export($stores, $input);
    $exportSeconds = microtime(true) - $started - $querySeconds;
} finally {
    Db::rollback();
}

if (count($report['records']) < 2 || count($report['columns']) < 10) {
    throw new RuntimeException('cross-store report did not return a representative result');
}
foreach ($dimensionSelects as $name => $count) {
    // There are several registered metric populations, but the query count
    // must depend on those populations, not on fact or store-day row count.
    if ($count > 40) {
        throw new RuntimeException("{$name} repeated organization lookup {$count} times");
    }
}
if ($report['records'] !== $export['records']
    || $report['summary_row'] !== $export['summary_row']
    || $report['columns'] !== $export['columns']
    || $report['column_groups'] !== $export['column_groups']
    || $report['metric_version'] !== $export['metric_version']) {
    throw new RuntimeException('query and export results diverged');
}
$groupLabels = array_column($export['column_groups'], 'label');
foreach (['现金业绩', '分成业绩', '分成后业绩', '消耗业绩'] as $requiredLabel) {
    if (!in_array($requiredLabel, $groupLabels, true)) {
        throw new RuntimeException("export is missing the {$requiredLabel} header group");
    }
}

printf(
    "PASS cross-store item analysis: stores=%d rows=%d dimension_selects=%d/%d query=%.3fs export=%.3fs\n",
    count($stores), count($report['records']), $dimensionSelects['query'],
    $dimensionSelects['export'], $querySeconds, $exportSeconds
);
