<?php

declare(strict_types=1);

// Read-only local RH replica check; no production endpoint or write operation.
require '/var/www/html/vendor/autoload.php';

use app\services\report\StoreUnifiedReportPhaseSixServices;
use app\services\report\StoreUnifiedReportServices;

$app = new \think\App('/var/www/html/');
$app->initialize();

function assertReportVisibility(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS: {$message}\n";
}

$storeId = 80;
$input = [
    'report' => 'store_item_analysis',
    'start_date' => '2026-10-17',
    'end_date' => '2026-10-17',
    '_internal_all' => true,
];
$itemService = new StoreUnifiedReportServices();
$item = $itemService->query([$storeId], $input);
assertReportVisibility(count($item['records']) > 0, 'store item analysis has a local fixture row');
$itemKeys = array_column($item['columns'], 'key');
assertReportVisibility(in_array('store_name', $itemKeys, true), 'store identity column remains visible');
assertReportVisibility(
    array_merge(...array_column($item['column_groups'], 'column_keys')) === $itemKeys,
    'item group headers follow the visible column order: columns=' . json_encode($itemKeys)
        . ' groups=' . json_encode(array_merge(...array_column($item['column_groups'], 'column_keys')))
);
foreach ($item['columns'] as $column) {
    $key = $column['key'];
    assertReportVisibility(array_key_exists($key, $item['summary_row']), "item summary retains {$key}");
    if (!empty($column['fixed'])) continue;
    $hasNonzero = false;
    foreach ($item['records'] as $record) {
        if ((float)($record[$key] ?? 0) !== 0.0) $hasNonzero = true;
    }
    assertReportVisibility($hasNonzero, "item metric {$key} is not all zero");
}
$itemExport = $itemService->export([$storeId], $input);
assertReportVisibility(
    array_column($itemExport['columns'], 'key') === $itemKeys
        && $itemExport['summary_row'] === $item['summary_row']
        && $itemExport['column_groups'] === $item['column_groups'],
    'item screen and export have the same visible headers and totals'
);
$itemXlsx = $itemService->exportItemAnalysisXlsx([$storeId], $input);
assertReportVisibility(substr($itemXlsx['content'], 0, 2) === 'PK',
    'item XLSX renders the reduced grouped headers');

$salary = (new StoreUnifiedReportPhaseSixServices())->query(
    'phase_six_salary_summary', [126], ['start' => '2026-10-08', 'end' => '2026-10-08'],
    ['_internal_all' => true]
);
assertReportVisibility(count($salary['records']) > 0, 'salary summary has local fixture rows');
$salaryKeys = array_column($salary['columns'], 'key');
assertReportVisibility(
    array_slice($salaryKeys, 0, 4) === ['company_name', 'store_name', 'employee_name', 'position_name'],
    'salary employee identity columns remain visible'
);
foreach ($salary['columns'] as $column) {
    if (empty($column['summable'])) continue;
    $key = $column['key'];
    $hasNonzero = false;
    foreach ($salary['records'] as $record) {
        if ((float)($record[$key] ?? 0) !== 0.0) $hasNonzero = true;
    }
    assertReportVisibility($hasNonzero && array_key_exists($key, $salary['summary_row']),
        "salary metric {$key} is nonzero somewhere and retains its summary");
}

echo "local report zero-column visibility: PASS\n";
