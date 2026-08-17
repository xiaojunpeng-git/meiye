<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = (string)file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportPhaseSixServices.php');
$controller = (string)file_get_contents($root . '/后端代码/app/controller/admin/v1/report/UnifiedReport.php');
$store = (string)file_get_contents($root . '/后端代码/app/controller/store/report/UnifiedReport.php');
$cashier = (string)file_get_contents($root . '/后端代码/app/controller/cashier/v3/Report.php');
$migration = (string)file_get_contents($root . '/后端代码/database/upgrades/2026-08-18-第六阶段其他报表/02-正式升级.sql');

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
assertPhaseSix(str_contains($migration, 'uk_tenant_store') && str_contains($migration, 'project_count') && str_contains($migration, 'mentor_employee_id'), 'migration is additive and repeatable');
assertPhaseSix(str_contains($store, 'StoreUnifiedReportPhaseSixServices') && str_contains($cashier, 'StoreUnifiedReportPhaseSixServices'), 'both store controllers integrate phase six service');
assertPhaseSix(str_contains($store, 'platformOnlyReportCodes') && str_contains($cashier, 'platformOnlyReportCodes'), 'store endpoints reject platform-only reports');
echo "PASS phase-six backend contract\n";
