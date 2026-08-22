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
$module = $read('/后端代码/app/services/cashier/v3/dashboard/CashierV3BusinessDashboardModule.php');
$manifest = $read('/后端代码/app/services/cashier/v3/manifest/CashierV3C4DashboardModule.php');

$checks = [
    'store target reader and contract version' => str_contains($reader, 'final class CashierV3StoreTargetDashboardReadModel')
        && str_contains($reader, "CONTRACT_VERSION = 'cashier-v3-store-target-dashboard-v1'"),
    'current store is enforced server side' => str_contains($reader, 'allowsStore($operator->storeId())')
        && (str_contains($reader, '->where(\'store_id\', $storeId)') || str_contains($reader, '->where(\'p.store_id\', $storeId)'))
        && str_contains($reader, "'forced' => true"),
    'target progress uses month and annual target facts' => str_contains($reader, 'GroupManagementDashboardTargetServices')
        && str_contains($reader, '$monthTotals')
        && str_contains($reader, '$yearTotals')
        && str_contains($reader, "'month' => " . '$this->goal')
        && str_contains($reader, "'year' => " . '$this->goal'),
    'cash ranking uses effective unified allocation facts' => str_contains($reader, 'cashier_v3_performance_fact')
        && str_contains($reader, "performance_type', 'sales_performance_allocated'")
        && str_contains($reader, 'SUM(p.amount_cents) AS cash_performance_cents')
        && str_contains($reader, "SUM((CASE WHEN p.fact_direction='reversal' THEN -1 ELSE 1 END) * COALESCE(s.quantity,0) * COALESCE(p.allocation_weight_numerator,1) / NULLIF(COALESCE(p.allocation_weight_denominator,1),0)) AS sales_quantity")
        && str_contains($reader, 'COUNT(DISTINCT NULLIF(p.member_id,0)) AS customer_count'),
    'cash ranking has deterministic descending order' => str_contains($reader, "orderRaw('cash_performance_cents DESC, p.employee_id ASC')"),
    'consumption ranking uses completed services and labor allocations' => str_contains($reader, 'cashier_v3_entitlement_service_fact')
        && str_contains($reader, "service_status=\\'completed\\'")
        && str_contains($reader, "performance_type', 'labor_performance_allocated'")
        && str_contains($reader, "->whereNull('er.id')")
        && str_contains($reader, 'SUM(p.amount_cents) AS consumption_performance_cents')
        && str_contains($reader, 'labor_fee_amount_cents')
        && str_contains($reader, 'project_count_half_units'),
    'consumption ranking has stable descending order and customer de-duplication' => str_contains($reader, "orderRaw('consumption_performance_cents DESC, p.employee_id ASC')")
        && str_contains($reader, 'COUNT(DISTINCT NULLIF(p.member_id,0)) AS customer_count'),
    'goal actual cash nets signed reversal facts' => preg_match('/private function cashTotal\([^}]+?\n    }/s', $reader, $cashTotalMatch) === 1
        && str_contains($cashTotalMatch[0], 'cashier_v3_payment_fact')
        && str_contains($cashTotalMatch[0], 'SUM(amount_cents)')
        && str_contains($cashTotalMatch[0], "where('status', 'effective')")
        && !str_contains($cashTotalMatch[0], "where('fact_direction', 'forward')"),
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
