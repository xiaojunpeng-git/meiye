<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = file_get_contents($root . '/后端代码/app/services/report/MemberManagementDashboardServices.php');
$controller = file_get_contents($root . '/后端代码/app/controller/admin/v1/report/UnifiedReport.php');
$route = file_get_contents($root . '/后端代码/route/admin.php');
$view = file_get_contents($root . '/前端代码/cashier-v3/src/views/MemberDashboardView.vue');
$api = file_get_contents($root . '/前端代码/cashier-v3/src/services/memberDashboardApi.js');
$menuSql = file_get_contents($root . '/后端代码/database/upgrades/2026-08-19-平台总部会员看板菜单/02-正式升级.sql');

$checks = [
    'backend service exists' => str_contains($service, 'final class MemberManagementDashboardServices'),
    'authorized endpoint' => str_contains($controller, "admin-report-member-management-dashboard")
        && str_contains($route, "Route::get('member-dashboard', 'v1.report.UnifiedReport/memberDashboard')"),
    'customer overview contract' => str_contains($service, "'customer_overview'")
        && str_contains($service, "'consuming_members'")
        && str_contains($service, "'service_members'")
        && str_contains($service, "'sleeping_members'"),
    'cash and consumption facts' => str_contains($service, 'cashier_v3_payment_sale_allocation_fact')
        && str_contains($service, 'consumption_performance_recorded'),
    'dynamic categories and rankings' => str_contains($service, 'store_product_category')
        && str_contains($service, "'projects'")
        && str_contains($service, "'products'")
        && str_contains($service, "'consumption_projects'")
        && str_contains($service, "'consumption_products'"),
    'visit bands and tiers' => str_contains($service, "[30, 60, 90, 180, 360]")
        && str_contains($service, "'new_customer'")
        && str_contains($service, 'metric_version'),
    'experience and frequency scope' => str_contains($service, '$this->memberSet($this->withoutExperience($cash), true)')
        && str_contains($service, '$startMonth = substr($start, 0, 7)'),
    'business field explanation labels' => str_contains($service, "ranking.consumption.cash_income")
        && str_contains($view, 'fieldExplanationLabel(key)'),
    'tier permission contract' => str_contains($service, 'setting-shop-six-dimension-consumption-tier'),
    'frontend uses backend response' => str_contains($api, '/adminapi/report/member-dashboard')
        && str_contains($view, 'queryMemberDashboard({ ...rangeForPeriod(), ...scopeFilters() })')
        && str_contains($view, 'customer_overview'),
    'no horizontal category overflow' => str_contains($view, 'member-dashboard__category-grid')
        && str_contains($view, 'overflow-x:hidden'),
    'headquarters menu is idempotent' => str_contains($menuSql, "unique_auth`='admin-index-index'")
        && str_contains($menuSql, "admin-report-member-management-dashboard")
        && str_contains($menuSql, 'NOT EXISTS'),
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
if ($failed !== []) {
    fwrite(STDERR, "FAIL\n" . implode("\n", $failed) . "\n");
    exit(1);
}

echo 'PASS ' . count($checks) . " member dashboard contract checks\n";
