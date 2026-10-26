<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = (string)file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportServices.php');
$view = (string)file_get_contents($root . '/前端代码/cashier-v3/src/views/StoreBusinessReportView.vue');

$checks = [
    'six first-phase reports declare the constrained result-table layout' => substr_count($service, "'table_layout'=>['fixed'=>true]") >= 5 && strpos($service, "'table_layout' => ['fixed'=>true]") !== false,
    'fixed identifiers are declared by the report service, not inferred by the browser' => strpos($service, 'private function fixedColumns(array $columns, array $widths): array') !== false,
    'each first-phase report returns a separate first-row total' => substr_count($service, "'summary_row'") >= 5,
    'member-consumption totals aggregate payment allocation and frozen partner share facts' => strpos($service, 'memberConsumptionSummaryValues') !== false && strpos($service, 'cashier_v3_payment_sale_allocation_fact') !== false && strpos($service, 'cashier_v3_card_sale_category_allocation_fact') !== false,
    'partner summary drilldown carries the exact store and category without a hidden partner split' => strpos($service, "'store_ids'=>'store_id', 'category_path_exact'=>'category_path_snapshot'") !== false,
    'shared view enables fixed layout only when the report service declares it' => strpos($view, 'const usesFixedTableLayout') !== false && strpos($view, "'store-business-report--fixed-table': usesFixedTableLayout") !== false,
    'grouped headers reserve a separate sticky row before the total row' => strpos($view, 'top: calc(var(--report-header-row-index, 0) * 40px)') !== false && strpos($view, 'top: var(--report-table-header-height)') !== false,
];

$passed = 0;
foreach ($checks as $name => $valid) {
    echo ($valid ? 'PASS ' : 'FAIL ') . $name . "\n";
    if ($valid) $passed++;
}
echo "first-phase report table projection contract: {$passed}/" . count($checks) . " PASS\n";
exit($passed === count($checks) ? 0 : 1);
