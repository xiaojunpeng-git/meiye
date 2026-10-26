<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$group = file_get_contents($root . '/后端代码/app/services/report/GroupManagementDashboardServices.php');
$product = file_get_contents($root . '/后端代码/app/services/report/ProductManagementDashboardServices.php');
$member = file_get_contents($root . '/后端代码/app/services/report/MemberManagementDashboardServices.php');
$reader = file_get_contents($root . '/后端代码/app/services/query/metric/RegisteredMetricReadServices.php');
$groupView = file_get_contents($root . '/前端代码/cashier-v3/src/views/GroupManagementDashboardView.vue');
foreach (compact('group', 'product', 'member', 'reader', 'groupView') as $name => $source) {
    if ($source === false) throw new RuntimeException('Cannot read ' . $name);
}

$checks = [
    'group top six metric contracts are retained' => str_contains($group, "\$this->registeredMetric('cash_performance'")
        && str_contains($group, "\$this->registeredMetric('refund_performance'")
        && str_contains($group, "\$this->registeredMetric('actual_performance'")
        && str_contains($group, "\$this->registeredMetric('consumption_performance'")
        && str_contains($group, "\$this->registeredMetric('consumption_count'")
        && str_contains($group, "\$this->metric('consumption_unit_price'"),
    'group category cards use actual performance' => str_contains($group, "categoryDashboardCards(\n            'actual_performance'"),
    'category cards retain signed refund rows' => str_contains($reader, "if (\$metricCode !== 'actual_performance')")
        && str_contains($reader, "'amount_cents' => \$amount")
        && str_contains($reader, "'metric_code' => \$metricCode"),
    'group source money is signed but headcount stays independent' => str_contains($group, "'deal_amount_cents' => \$this->sum(\$matches, 'amount_cents')")
        && !str_contains($group, "&& (int)(\$row['amount_cents'] ?? 0) > 0")
        && str_contains($group, 'marketQualifiedMemberDays('),
    'orange consumption trend remains an explicit exception' => str_contains($group, "'consumption_performance_cents'")
        && str_contains($groupView, 'trendChart.consumptionPoints')
        && str_contains($groupView, '消耗业绩</span>'),
    'product sales current and comparison periods use actual' => substr_count($product, "\$reader->summary('actual_performance'") === 1
        && substr_count($product, "\$reader->categorySummary('actual_performance'") === 1,
    'member money does not read completed-service consumption performance' => str_contains($member, "categoryRows('actual_performance'")
        && str_contains($member, '$serviceAmount = $cashAmount;')
        && !str_contains($member, 'consumption_performance_recorded'),
];

$checks['product comparison reader is actual'] = str_contains($product, "\$read(\$group['current'], 'actual_performance')")
    && str_contains($product, "\$read(\$group['yoy'], 'actual_performance')")
    && str_contains($product, "\$read(\$group['mom'], 'actual_performance')");

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
if ($failed !== []) {
    fwrite(STDERR, "FAIL\n" . implode("\n", $failed) . "\n");
    exit(1);
}
echo 'PASS ' . count($checks) . " R40 actual-performance contract checks\n";
