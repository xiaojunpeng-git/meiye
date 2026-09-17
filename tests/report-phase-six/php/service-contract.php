<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = (string)file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportPhaseSixServices.php');
$controller = (string)file_get_contents($root . '/后端代码/app/controller/admin/v1/report/UnifiedReport.php');
$store = (string)file_get_contents($root . '/后端代码/app/controller/store/report/UnifiedReport.php');
$cashier = (string)file_get_contents($root . '/后端代码/app/controller/cashier/v3/Report.php');
$migration = (string)file_get_contents($root . '/后端代码/database/upgrades/2026-08-18-第六阶段其他报表/02-正式升级.sql');
$serviceCustomerReader = (string)file_get_contents($root . '/后端代码/app/services/query/metric/ServiceCustomerMetricReadServices.php');

function assertPhaseSix(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
    fwrite(STDOUT, "PASS: {$message}\n");
}

$reports = [
    'phase_six_garden_item_analysis' => '花园品项分析表',
    'phase_six_monthly_featured_item' => '月主推数据统计表',
    'phase_six_headquarters_acquisition' => '总部拓客数据统计表',
    'phase_six_other_multi_payment' => '其他多收款业绩表',
    'phase_six_salary_summary' => '员工薪资汇总月报表',
    'phase_six_salary_detail' => '员工薪资明细月报表',
    'phase_six_training_employee' => '教培员工需求统计表',
    'phase_six_acquisition_source' => '拓客部门客户来源数据分析表',
    'phase_six_human_store_health' => '人力-院店健康报表',
];
foreach ($reports as $code => $title) {
    assertPhaseSix(str_contains($service, "'{$code}'") && str_contains($service, "'{$title}'"), "stable report {$code}");
    assertPhaseSix(str_contains($controller, "'{$code}'"), "platform permission dispatch {$code}");
}
assertPhaseSix(str_contains($service, 'source_explanation') && str_contains($service, 'table_layout'), 'column contract includes business explanation and fixed layout');
assertPhaseSix(str_contains($service, 'cashier_v3_payment_sale_allocation_fact') && str_contains($service, 'cashier_v3_report_sale_dimension_fact'), 'reports read unified payment and sale dimension facts');
assertPhaseSix(str_contains($service, 'cashier_v3_report_beautician_establishment') && str_contains($service, 'beautician_fill_rate'), 'health report reads one current establishment value per store');
assertPhaseSix(str_contains($service, 'manualColumn') && str_contains($service, 'project_count'), 'manual fields and project count are declared');
assertPhaseSix(
    str_contains($service, 'service_visit_count') && str_contains($service, 'service_people_count')
    && str_contains($service, 'ServiceCustomerMetricReadServices') && str_contains($service, 'salaryFactsWithServiceCustomerMetrics'),
    'salary reports consume shared employee service-customer metrics'
);
assertPhaseSix(
    str_contains($service, "['service_visit_count','service_people_count']") && str_contains($service, 'round((float)$v*10)')
    && str_contains($service, 'tenth($sum)'),
    'salary service metrics preserve tenth-unit summary precision'
);
assertPhaseSix(
    str_contains($serviceCustomerReader, 'friend_counts_as_customer') && str_contains($serviceCustomerReader, "return \$this->integer(\$row['friend_counts_as_customer'] ?? null) === 1")
    && str_contains($serviceCustomerReader, "'record:' . \$serviceFactId"),
    'shared reader excludes friend-not-counted and keeps friend or guest records distinct'
);
assertPhaseSix(
    str_contains($serviceCustomerReader, 'snapshotEmployees') && str_contains($serviceCustomerReader, "'had_labor_fact' => false")
    && str_contains($serviceCustomerReader, "(int)\$service['labor_amount_cents'] !== 0")
    && str_contains($service, 'detail_context_by_labor_key') && str_contains($service, "'service-customer:'"),
    'zero-price completed services use only the immutable craftsmen snapshot and keep a zero-value detail row'
);
assertPhaseSix(str_contains($migration, 'uk_tenant_store') && str_contains($migration, 'project_count') && str_contains($migration, 'mentor_employee_id'), 'migration is additive and repeatable');
assertPhaseSix(str_contains($store, 'StoreUnifiedReportPhaseSixServices') && str_contains($cashier, 'StoreUnifiedReportPhaseSixServices'), 'both store controllers integrate phase six service');
assertPhaseSix(str_contains($store, 'platformOnlyReportCodes') && str_contains($cashier, 'platformOnlyReportCodes'), 'store endpoints reject platform-only reports');

require_once $root . '/后端代码/app/services/report/StoreUnifiedReportPhaseSixServices.php';
$phaseSix = new \app\services\report\StoreUnifiedReportPhaseSixServices();
$result = new ReflectionMethod($phaseSix, 'result');
$tenthSummary = $result->invoke($phaseSix, '服务客数十分位合计', [[
    'key' => 'service_visit_count', 'label' => '服务人次', 'source_explanation' => '', 'summable' => true, 'width' => 120,
], [
    'key' => 'service_people_count', 'label' => '服务人数', 'source_explanation' => '', 'summable' => true, 'width' => 120,
]], [
    ['service_visit_count' => '0.3', 'service_people_count' => '0.3'],
    ['service_visit_count' => '0.3', 'service_people_count' => '0.3'],
    ['service_visit_count' => '0.4', 'service_people_count' => '0.4'],
], ['start' => '2026-09-01', 'end' => '2026-09-30']);
assertPhaseSix(
    ($tenthSummary['summary_row']['service_visit_count'] ?? null) === '1.0'
    && ($tenthSummary['summary_row']['service_people_count'] ?? null) === '1.0',
    'salary service metrics sum tenth-unit shares exactly'
);
echo "PASS phase-six backend contract\n";
