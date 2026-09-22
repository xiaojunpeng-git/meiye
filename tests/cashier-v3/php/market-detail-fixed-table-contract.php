<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php');
$view = file_get_contents($root . '/前端代码/cashier-v3/src/views/StoreBusinessReportView.vue');
$marketDetail = substr($service, strpos($service, 'private function marketDetail'), strpos($service, 'private function memberVisitAnalysis') - strpos($service, 'private function marketDetail'));

$checks = [
    'market detail renames dimension to source' => strpos($marketDetail, "'dimension'=>'来源'") !== false,
    'market detail declares the five left-fixed columns' => strpos($marketDetail, "['business_date'=>112, 'store_name_snapshot'=>112, 'member_name_snapshot'=>82, 'member_phone'=>116, 'dimension'=>108]") !== false,
    'shared view applies the server-declared constrained layout' => strpos($view, "'store-business-report--market-detail': activeReport === 'market_detail'") !== false && strpos($view, "'store-business-report--fixed-table': usesFixedTableLayout") !== false && strpos($view, '.store-business-report--market-detail, .store-business-report--fixed-table { grid-template-rows: auto auto minmax(0, 1fr);') !== false,
    'table header and summary stay sticky inside the result area' => strpos($view, '.store-business-report--market-detail thead th, .store-business-report--fixed-table thead th { position: sticky; top: 0; z-index: 5; }') !== false && strpos($view, '.store-business-report--fixed-table .store-business-report__summary-row td { position: sticky; top: var(--report-table-header-height); z-index: 4; }') !== false,
    'fixed columns use server positions in all row types' => substr_count($view, ':style="fixedColumnStyle(column)"') === 4 && strpos($view, 'store-business-report__column--sticky-left') !== false,
];

$passed = 0;
foreach ($checks as $name => $valid) {
    echo ($valid ? 'PASS ' : 'FAIL ') . $name . "\n";
    if ($valid) $passed++;
}
echo "market detail fixed table contract: {$passed}/" . count($checks) . " PASS\n";
exit($passed === count($checks) ? 0 : 1);
