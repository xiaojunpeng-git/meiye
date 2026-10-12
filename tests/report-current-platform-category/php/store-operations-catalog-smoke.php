<?php

declare(strict_types=1);

use app\services\report\StoreUnifiedReportServices;

// Read-only two-store smoke for every menu entry and its hidden controlled drilldown.
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';
(new think\App(rtrim($backend, '/\\') . '/'))->initialize();

$reports = new StoreUnifiedReportServices();
$entries = array_values(array_filter($reports->catalog(),
    static fn(array $entry): bool => (string)($entry['folder'] ?? '') === '门店运营'));
$count = 0;
foreach ($entries as $entry) {
    $report = (string)$entry['code'];
    $result = $reports->query([126, 175], [
        'report' => $report, 'start_date' => '2026-10-11', 'end_date' => '2026-10-11',
        'page' => 1, 'limit' => 20,
    ]);
    if (!isset($result['columns'], $result['records'])
        || !is_array($result['columns']) || !is_array($result['records'])) {
        throw new RuntimeException("{$report} did not return the report contract");
    }
    $count++;
    echo "PASS {$report} rows=" . count($result['records']) . "\n";
}
echo "STORE_OPERATIONS_CATALOG_SMOKE=PASS count={$count}\n";
