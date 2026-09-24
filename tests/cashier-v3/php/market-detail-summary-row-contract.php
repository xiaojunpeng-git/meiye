<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php');
$view = file_get_contents($root . '/前端代码/cashier-v3/src/views/StoreBusinessReportView.vue');
$marketDetail = substr($service, strpos($service, 'private function marketDetail'), strpos($service, 'private function memberVisitAnalysis') - strpos($service, 'private function marketDetail'));

$checks = [
    'market detail returns a first-row summary separately from records' => strpos($marketDetail, "\$result['summary_row'] = \$this->marketDetailSummaryRow(\$rows)") !== false,
    'summary labels the date column and keeps unsuitable fields blank' => strpos($service, "'business_date' => '合计', 'store_name_snapshot' => '-', 'member_name_snapshot' => '-', 'member_phone' => '-'") !== false,
    'summary adds the applicable count and amount fields' => strpos($service, "'dimension' => '-', 'walk_in' => \$walkIn, 'visits' => \$visits, 'effective_people' => count(\$effectiveMembers)") !== false && strpos($service, "'amount' => \$this->money(\$amountCents)") !== false,
    'effective people are deduplicated by member in the summary' => strpos($service, "\$effectiveMembers[(int)\$row['member_id']] = true") !== false,
    'market detail explains source-specific effective thresholds and member-level summary' => strpos($service, "if(\$title==='市场明细表')\$columns=\$this->marketDetailColumnExplanations(\$columns)") !== false
        && strpos($service, 'A 来源至少 1,000 元，其他来源至少 500 元') !== false
        && strpos($service, '同一会员跨日期可能显示多行 1，但合计按会员去重') !== false,
    'market detail explains member-day amount and member-or-guest visits' => strpos($service, '合计本行会员当天在该门店和来源下的有效记账收款净额') !== false
        && strpos($service, '游客每张有效服务单记 1') !== false
        && strpos($service, '同单多项目不重复，不要求当次有收款') !== false,
    'market performance and detail expose business-language source explanations' => strpos($service, "if(\$title==='市场业绩表')\$columns=\$this->marketPerformanceColumnExplanations(\$columns)") !== false
        && strpos($service, '新旧值不会重复相加。仅 B 来源显示该列') !== false
        && strpos($service, '退款会抵减，已作废销售不计') !== false
        && strpos($service, '余额支付、赠金和旧卡录入不计') !== false
        && strpos($service, '显示收款或服务实际归属的门店') !== false,
    'shared report view renders the summary before ordinary rows' => strpos($view, '<tr v-if="summaryRow" class="store-business-report__summary-row">') !== false && strpos($view, '<tr v-for="(row, rowIndex) in records"') !== false,
];

$passed = 0;
foreach ($checks as $name => $valid) {
    echo ($valid ? 'PASS ' : 'FAIL ') . $name . "\n";
    if ($valid) $passed++;
}
echo "market detail summary row contract: {$passed}/" . count($checks) . " PASS\n";
exit($passed === count($checks) ? 0 : 1);
