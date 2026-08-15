<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php');
$view = file_get_contents($root . '/前端代码/cashier-v3/src/views/StoreBusinessReportView.vue');
$marketDetail = substr($service, strpos($service, 'private function marketDetail'), strpos($service, 'private function memberVisitAnalysis') - strpos($service, 'private function marketDetail'));

$checks = [
    'market detail returns a first-row summary separately from records' => strpos($marketDetail, "\$result['summary_row'] = \$this->marketDetailSummaryRow(\$rows)") !== false,
    'summary labels only the document column and keeps unsuitable fields blank' => strpos($service, "'order_no_snapshot' => '合计', 'store_name_snapshot' => '-', 'member_name_snapshot' => '-', 'member_phone' => '-'") !== false,
    'summary adds the applicable count and amount fields' => strpos($service, "'dimension' => '-', 'walk_in' => \$walkIn, 'visits' => \$visits, 'effective_people' => count(\$effectiveMembers)") !== false && strpos($service, "'amount' => \$this->money(\$amountCents)") !== false,
    'effective people are deduplicated by member in the summary' => strpos($service, "\$effectiveMembers[(int)\$row['member_id']] = true") !== false,
    'shared report view renders the summary before ordinary rows' => strpos($view, '<tr v-if="summaryRow" class="store-business-report__summary-row">') !== false && strpos($view, '<tr v-for="(row, rowIndex) in records"') !== false,
];

$passed = 0;
foreach ($checks as $name => $valid) {
    echo ($valid ? 'PASS ' : 'FAIL ') . $name . "\n";
    if ($valid) $passed++;
}
echo "market detail summary row contract: {$passed}/" . count($checks) . " PASS\n";
exit($passed === count($checks) ? 0 : 1);
