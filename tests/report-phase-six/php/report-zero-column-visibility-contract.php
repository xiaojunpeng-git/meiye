<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/后端代码/app/services/report/StoreReportZeroColumnVisibilityServices.php';

use app\services\report\StoreReportZeroColumnVisibilityServices as Visibility;

function assertVisibleColumns(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$message}\n");
}

$input = [
    'columns' => [
        ['key' => 'store_name'], ['key' => 'zero_cash'], ['key' => 'zero_consume'],
        ['key' => 'offset_cash'], ['key' => 'blank_cash'], ['key' => 'missing_cash'],
    ],
    'column_groups' => [
        ['label' => '门店', 'column_keys' => ['store_name']],
        ['label' => '已移除维度', 'column_keys' => ['old_dimension']],
        ['label' => '全零', 'column_keys' => ['zero_cash', 'zero_consume']],
        ['label' => '保留', 'column_keys' => ['offset_cash', 'blank_cash', 'missing_cash']],
    ],
    'records' => [
        ['store_name' => 'A', 'zero_cash' => '0', 'zero_consume' => '0.00', 'offset_cash' => '10', 'blank_cash' => '', 'missing_cash' => '0'],
        ['store_name' => 'B', 'zero_cash' => 0, 'zero_consume' => '-0.0', 'offset_cash' => '-10', 'blank_cash' => '0'],
    ],
    'summary_row' => ['store_name' => '合计', 'zero_cash' => '0', 'zero_consume' => '0', 'offset_cash' => '0', 'blank_cash' => '0', 'missing_cash' => '0'],
];
$filtered = Visibility::hideAllZeroColumns($input, [
    'zero_cash', 'zero_consume', 'offset_cash', 'blank_cash', 'missing_cash',
]);
assertVisibleColumns(
    array_column($filtered['columns'], 'key') === ['store_name', 'offset_cash', 'blank_cash', 'missing_cash'],
    'only metrics that are zero in every complete row are hidden'
);
assertVisibleColumns(
    array_column($filtered['column_groups'], 'label') === ['门店', '保留']
        && $filtered['column_groups'][1]['column_keys'] === ['offset_cash', 'blank_cash', 'missing_cash'],
    'empty groups disappear and retained group keys match visible columns'
);
assertVisibleColumns(
    !array_key_exists('zero_cash', $filtered['summary_row'])
        && !array_key_exists('zero_consume', $filtered['summary_row'])
        && $filtered['summary_row']['offset_cash'] === '0'
        && $filtered['records'] === $input['records'],
    'summary contract follows columns while source rows and cancellation remain intact'
);
assertVisibleColumns(
    Visibility::hideAllZeroColumns(array_replace($input, ['records' => []]), ['zero_cash'])['columns'] === $input['columns'],
    'an empty result does not hide all metric headings'
);

$storeService = (string)file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportServices.php');
$phaseSixService = (string)file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportPhaseSixServices.php');
assertVisibleColumns(
    strpos($storeService, "if (\$report === 'store_item_analysis')") !== false
        && strpos($storeService, 'StoreReportZeroColumnVisibilityServices::hideAllZeroColumns($result, $metricKeys)') !== false
        && strpos($storeService, "'columns' => \$result['columns'] ?? []") !== false,
    'store item analysis applies visibility before shared screen and export result'
);
assertVisibleColumns(
    strpos($phaseSixService, "if (\$report === 'phase_six_salary_summary')") !== false
        && strpos($phaseSixService, 'StoreReportZeroColumnVisibilityServices::hideAllZeroColumns($result, $metricKeys)') !== false,
    'salary summary applies visibility only to its complete query result'
);

echo "report zero-column visibility contract: PASS\n";
