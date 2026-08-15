<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__, 3) . '/后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php');
$view = file_get_contents(dirname(__DIR__, 3) . '/前端代码/cashier-v3/src/views/StoreBusinessReportView.vue');
$marketPerformance = substr($source, strpos($source, 'private function marketPerformance'), strpos($source, 'private function marketDetail') - strpos($source, 'private function marketPerformance'));
$marketDetail = substr($source, strpos($source, 'private function marketDetail'), strpos($source, 'private function memberVisitAnalysis') - strpos($source, 'private function marketDetail'));

$checks = [
    'effective-people click forwards the metric filter' => strpos($marketPerformance, "\$params['metric_code'] = 'effective_people'") !== false,
    'detail filter reuses the already-loaded detail records' => strpos($marketDetail, "\$this->marketEffectiveMemberKeys(\$rows)") !== false,
    'detail excludes non-effective members before pagination' => strpos($marketDetail, "if (\$metricCode === 'effective_people')") !== false && strpos($marketDetail, 'array_filter($rows') !== false,
    'effective threshold is fixed by source type' => strpos($source, "\$this->sourcePrefix(\$source) === 'A' ? 100000 : 50000") !== false,
    'field guide explains effective-person drilldown' => strpos($view, "key)) return '同一门店、同一渠道中，在当前日期内累计现金业绩达到有效门槛") !== false,
];

$passed = 0;
foreach ($checks as $name => $valid) {
    echo ($valid ? 'PASS ' : 'FAIL ') . $name . "\n";
    if ($valid) $passed++;
}
echo "market effective people drilldown contract: {$passed}/" . count($checks) . " PASS\n";
exit($passed === count($checks) ? 0 : 1);
