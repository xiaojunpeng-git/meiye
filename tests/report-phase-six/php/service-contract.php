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
assertPhaseSix(
    str_contains($controller, "['employee_name', '']")
    && str_contains($store, "['employee_name','']")
    && str_contains($cashier, "['employee_name', '']"),
    'platform, store and cashier endpoints preserve the salary employee-name filter'
);

require_once $root . '/后端代码/app/services/report/StoreUnifiedReportPhaseSixServices.php';
require_once $root . '/后端代码/app/services/query/metric/MetricMoneyFormatter.php';
$phaseSix = new \app\services\report\StoreUnifiedReportPhaseSixServices();
$manualColumn = new ReflectionMethod($phaseSix, 'manualColumn');
$manualDisplay = new ReflectionMethod($phaseSix, 'manualDisplayValue');
foreach (['target_amount', 'unit_price'] as $moneyField) {
    $column = $manualColumn->invoke($phaseSix, $moneyField, '金额', '手动补充金额');
    assertPhaseSix(($column['manual_input']['value_type'] ?? '') === 'integer_cents', "{$moneyField} submits integer cents");
    assertPhaseSix($manualDisplay->invoke($phaseSix, $column['manual_input'], '89100') === '891', "{$moneyField} reads back integer yuan");
    assertPhaseSix($manualDisplay->invoke($phaseSix, $column['manual_input'], '89123', true) === '891.23', "{$moneyField} exports cent precision");
    assertPhaseSix($manualDisplay->invoke($phaseSix, $column['manual_input'], '') === '', "{$moneyField} preserves manual clear");
}
assertPhaseSix(str_contains($service, "withManualAnnotations(\$report, \$result, \$stores, !empty(\$input['_internal_all']))")
    && str_contains($service, "->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)")
    && str_contains($service, "->whereIn('store_id', \$stores)->whereIn('subject_key', array_keys(\$keys))")
    && str_contains($service, "'annotation_subject_key' => 'phase_six:payment:'"),
    'manual readback is scoped to the tenant, store and payment allocation');
$projectManualRows = new ReflectionMethod($phaseSix, 'projectManualRows');
$projected = $projectManualRows->invoke($phaseSix, [
    'records' => [
        ['store_id' => 1, 'annotation_subject_key' => 'phase_six:payment:line-a', 'unit_price' => '', '备注' => '原值'],
        ['store_id' => 2, 'annotation_subject_key' => 'phase_six:payment:line-a', 'unit_price' => '', '备注' => '另一店'],
    ],
], [
    'unit_price' => ['value_type' => 'integer_cents'], '备注' => ['value_type' => 'text'],
], [
    ['store_id' => 1, 'subject_key' => 'phase_six:payment:line-a', 'field_key' => 'unit_price', 'field_value' => '89100', 'version' => 2],
    ['store_id' => 1, 'subject_key' => 'phase_six:payment:line-a', 'field_key' => '备注', 'field_value' => '', 'version' => 3],
]);
assertPhaseSix(($projected['records'][0]['unit_price'] ?? null) === '891'
    && ($projected['records'][0]['unit_price_version'] ?? null) === 2
    && ($projected['records'][0]['备注'] ?? null) === ''
    && ($projected['records'][0]['备注_version'] ?? null) === 3
    && ($projected['records'][1]['unit_price'] ?? null) === ''
    && ($projected['records'][1]['备注'] ?? null) === '另一店',
    'manual values survive readback, clear explicitly and never cross store boundaries');
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
assertPhaseSix(
    str_contains($service, 'pf.project_count_decimal')
    && str_contains($service, "!empty(\$row['has_project_count_decimal'])")
    && str_contains($service, '$halfUnits*500000')
    && str_contains($service, "\$grouped[\$key]['legacy_project_count_half_units']"),
    'salary project count reads saved decimals first and retains mixed/legacy half-unit fallback'
);
assertPhaseSix(
    str_contains($service, 'reversedPerformanceFactIds($rows)')
    && str_contains($service, 'reversedPerformanceFactIds($salesRows)')
    && str_contains($service, "->whereIn('reversal_of',array_keys(\$forwardIds))")
    && !preg_match('/reversedPerformanceFactIds[\\s\\S]{0,1800}whereBetween/', $service),
    'salary detail and summary exclude later terminal reversals without limiting the reversal date'
);
$salaryFilter = new ReflectionMethod($phaseSix, 'filterSalaryFactsByEmployeeName');
$filteredSalaryRows = $salaryFilter->invoke($phaseSix, [
    ['employee_name' => '曹小双'], ['employee_name' => '张丽函'], ['employee_name' => '曹非（陈瑶）'],
], '曹');
assertPhaseSix(
    array_column($filteredSalaryRows, 'employee_name') === ['曹小双', '曹非（陈瑶）']
    && str_contains($service, "'key'=>'employee_name','label'=>'员工','show_label'=>false,'aria_label'=>'员工姓名'")
    && str_contains($service, "'placeholder'=>'输入员工姓名'"),
    'salary summary and detail expose the same partial employee-name text filter without a repeated visible title'
);
assertPhaseSix(
    str_contains($service, '后来已作废的记录不再统计')
    && str_contains($service, '页面可输入姓名中的任意文字进行筛选')
    && str_contains($service, '卡项金额按卡内项目的分类和金额比例拆分')
    && !str_contains($service, "'business_date','日期','事实业务日期。'")
    && !str_contains($service, "'cash_amount','现金业绩','销售明细现金业绩分摊事实。'"),
    'salary column-source descriptions use business language and explain filtering, voiding and card allocation'
);
$projectCountMicros = new ReflectionMethod($phaseSix, 'projectCountMicros');
$projectCountText = new ReflectionMethod($phaseSix, 'projectCountText');
assertPhaseSix(
    $projectCountText->invoke($phaseSix, $projectCountMicros->invoke($phaseSix, '6.6')) === '6.6'
    && $projectCountText->invoke($phaseSix, $projectCountMicros->invoke($phaseSix, '0')) === '0'
    && $projectCountText->invoke($phaseSix, $projectCountMicros->invoke($phaseSix, '0.333333')) === '0.333333',
    'salary project counts retain zero and all six supported decimal places'
);
$projectSummary = $result->invoke($phaseSix, '工资项目数合计', [[
    'key' => 'project_count', 'label' => '项目数', 'source_explanation' => '', 'summable' => true, 'width' => 120,
]], [
    ['project_count' => '0.3'], ['project_count' => '0.4'], ['project_count' => '6.6'], ['project_count' => '0'],
], ['start' => '2026-09-01', 'end' => '2026-09-30']);
assertPhaseSix(($projectSummary['summary_row']['project_count'] ?? null) === '7.3',
    'salary project-count summary never rounds each row to a half project');
$categoryDefinitions = new ReflectionMethod($phaseSix, 'buildSalaryCategoryDefinitions');
$categoryProjection = $categoryDefinitions->invoke($phaseSix, [
    ['id' => 1, 'pid' => 0, 'cate_name' => '生美', 'is_show' => 1],
    ['id' => 2, 'pid' => 1, 'cate_name' => '卡项', 'is_show' => 1],
    ['id' => 3, 'pid' => 1, 'cate_name' => '项目', 'is_show' => 1],
    ['id' => 4, 'pid' => 3, 'cate_name' => '三级', 'is_show' => 1],
    ['id' => 5, 'pid' => 0, 'cate_name' => '花园', 'is_show' => 1],
], [4 => '生美 / 项目 / 三级', 9 => '历史 / 旧分类']);
$categoryLabels = array_column($categoryProjection['columns'], 'label');
assertPhaseSix(in_array('生美/卡项', $categoryLabels, true)
    && in_array('生美/项目', $categoryLabels, true)
    && in_array('花园', $categoryLabels, true)
    && in_array('历史/旧分类', $categoryLabels, true)
    && !in_array('生美', $categoryLabels, true)
    && !in_array('生美/项目/三级', $categoryLabels, true),
    'salary category columns use current two-level config and retain zero-sale and historical categories');
assertPhaseSix(($categoryProjection['targets'][4] ?? null) === 'salary_category_cash_3',
    'third-level sale fact rolls into its visible second-level category');
$allocateCategories = new ReflectionMethod($phaseSix, 'salarySaleCategoryAmounts');
$cardParts = $allocateCategories->invoke($phaseSix,
    ['source_type' => 'card', 'amount_cents' => 100], [
        ['category_id_snapshot' => 2, 'category_path_snapshot' => '生美 / 卡项', 'cash_performance_amount_cents' => 2],
        ['category_id_snapshot' => 3, 'category_path_snapshot' => '生美 / 项目', 'cash_performance_amount_cents' => 1],
    ]);
assertPhaseSix(($cardParts[2]['cents'] ?? null) === 66 && ($cardParts[3]['cents'] ?? null) === 34,
    'card employee performance is allocated in cents with stable final-category remainder');
$missingCardParts = $allocateCategories->invoke($phaseSix,
    ['source_type' => 'card', 'amount_cents' => 148000, 'category_id' => 2, 'category_path' => '生美 / 卡项'], []);
assertPhaseSix(($missingCardParts[0]['cents'] ?? null) === 148000 && !isset($missingCardParts[2]),
    'missing card component facts never use the card outer category');
echo "PASS phase-six backend contract\n";
