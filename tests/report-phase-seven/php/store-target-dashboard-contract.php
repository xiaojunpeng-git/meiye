<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$read = static function (string $relative) use ($root): string {
    $source = file_get_contents($root . $relative);
    if ($source === false) {
        fwrite(STDERR, "missing source: {$relative}\n");
        exit(1);
    }
    return $source;
};

$reader = $read('/后端代码/app/services/cashier/v3/dashboard/CashierV3StoreTargetDashboardReadModel.php');
$salary = $read('/后端代码/app/services/report/StoreUnifiedReportPhaseSixServices.php');
$module = $read('/后端代码/app/services/cashier/v3/dashboard/CashierV3BusinessDashboardModule.php');
$manifest = $read('/后端代码/app/services/cashier/v3/manifest/CashierV3C4DashboardModule.php');

$checks = [
    'store target reader and contract version' => str_contains($reader, 'final class CashierV3StoreTargetDashboardReadModel')
        && str_contains($reader, "CONTRACT_VERSION = 'cashier-v3-store-target-dashboard-v1'")
        && str_contains($reader, "METRIC_VERSION = 'cashier-v3-target-salary-parity-v1'"),
    'current store is enforced server side' => str_contains($reader, 'allowsStore($operator->storeId())')
        && str_contains($reader, "->where('p.store_id', \$storeId)")
        && str_contains($reader, 'salaryRankingRows([$storeId], $range)')
        && str_contains($reader, "'forced' => true"),
    'target progress uses effective scheme and unified actual performance' => str_contains($reader, 'TargetSchemeServices')
        && str_contains($reader, "'actual_performance'")
        && str_contains($reader, "\$metrics->summary('actual_performance'")
        && str_contains($reader, "'month' => " . '$this->goal')
        && str_contains($reader, "'year' => " . '$this->goal'),
    'cash money comes from salary net amount, not a second sum' => str_contains($reader, "'cash_performance_cents' => \$cents")
        && str_contains($reader, "\$cents = (int)\$row['cash_cents']")
        && !str_contains($reader, 'SUM(p.amount_cents) AS cash_performance_cents'),
    'sales activity counts retain the scoped effective-sale source' => str_contains($reader, 'cashier_v3_performance_fact')
        && str_contains($reader, "performance_type', 'sales_performance_allocated'")
        && str_contains($reader, "SUM((CASE WHEN p.fact_direction='reversal' THEN -1 ELSE 1 END) * COALESCE(s.quantity,0) * COALESCE(p.allocation_weight_numerator,1) / NULLIF(COALESCE(p.allocation_weight_denominator,1),0)) AS sales_quantity")
        && str_contains($reader, 'COUNT(DISTINCT NULLIF(p.member_id,0)) AS customer_count'),
    'both rankings use one salary projection and deterministic order' => str_contains($reader, '$this->cashRanking($tenantId, $storeId, $range, $salaryRows)')
        && str_contains($reader, '$this->consumptionRanking($salaryRows)')
        && str_contains($reader, "\$b['cash_performance_cents'] <=> \$a['cash_performance_cents']")
        && str_contains($reader, "\$b['consumption_performance_cents'] <=> \$a['consumption_performance_cents']"),
    'salary ranking projects before display rounding' => str_contains($salary, 'public function salaryRankingRows')
        && str_contains($salary, 'return $this->salarySummary($stores,$range,[],true)')
        && str_contains($salary, "'project_count_micros'=>(int)\$r['project_count_micros']")
        && str_contains($salary, "'service_people_tenths'=>(int)(\$summaryMetrics[\$key]['people_tenths']??0)"),
    'range defaults to month and rejects invalid or oversized windows' => str_contains($reader, "date('Y-m-01')")
        && str_contains($reader, "date('Y-m-d')")
        && str_contains($reader, 'strtotime($end) - strtotime($start) > 366 * 86400'),
    'business explanations and fixed ranking columns are returned' => str_contains($reader, 'source_explanation')
        && str_contains($reader, '员工现金业绩排行')
        && str_contains($reader, '员工消耗业绩排行')
        && str_contains($reader, "'服务人数'")
        && str_contains($reader, "'服务人次'"),
    'projection action is installed and authorized' => str_contains($module, "registerProjection('query-store-target-dashboard'")
        && str_contains($manifest, "'query-store-target-dashboard'"),
    'legacy target ranking implementation is not reused' => !str_contains($reader, 'app\\services\\target\\StoreTargetServices')
        && !str_contains($reader, 'staff_yeji'),
];

$failed = array_keys(array_filter($checks, static fn (bool $passed): bool => !$passed));
if ($failed !== []) {
    fwrite(STDERR, "FAIL\n" . implode("\n", $failed) . "\n");
    exit(1);
}

echo 'PASS ' . count($checks) . " store target dashboard contract checks\n";
