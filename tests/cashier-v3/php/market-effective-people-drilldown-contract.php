<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__, 3) . '/后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php');
$view = file_get_contents(dirname(__DIR__, 3) . '/前端代码/cashier-v3/src/views/StoreBusinessReportView.vue');
$marketPerformance = substr($source, strpos($source, 'private function marketPerformance'), strpos($source, 'private function marketDetail') - strpos($source, 'private function marketPerformance'));
$marketDetail = substr($source, strpos($source, 'private function marketDetail'), strpos($source, 'private function memberVisitAnalysis') - strpos($source, 'private function marketDetail'));

$checks = [
    'effective-people click forwards the metric filter' => strpos($marketPerformance, "\$params['metric_code'] = 'effective_people'") !== false,
    // 汇总和明细必须复用原始收款的每日资格，不能从退款已转 K 的展示行另算。
    'summary and detail share member-day eligibility' => strpos($marketPerformance, '$this->marketEffectiveMemberKeys($stores, $range)') !== false
        && strpos($marketDetail, '$this->marketEffectiveMemberKeys($stores, $range, $method)') !== false,
    'detail excludes non-effective members before pagination' => strpos($marketDetail, "if (\$metricCode === 'effective_people')") !== false && strpos($marketDetail, 'array_filter($rows') !== false,
    'effective threshold is fixed by source type' => strpos($source, "\$this->sourcePrefix(\$source) === 'A' ? 100000 : 50000") !== false,
    'field guide explains daily qualification and drilldown' => strpos($view, '不跨天凑门槛') !== false && strpos($view, '仅显示达标当天的会员明细') !== false,
];

$passed = 0;
foreach ($checks as $name => $valid) {
    echo ($valid ? 'PASS ' : 'FAIL ') . $name . "\n";
    if ($valid) $passed++;
}
echo "market effective people drilldown contract: {$passed}/" . count($checks) . " PASS\n";
exit($passed === count($checks) ? 0 : 1);
