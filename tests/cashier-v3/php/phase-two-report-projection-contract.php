<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = (string)file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php');
$report = (string)file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportServices.php');
$view = (string)file_get_contents($root . '/前端代码/cashier-v3/src/views/StoreBusinessReportView.vue');

foreach ([
    "'table_layout'=>['fixed'=>true,'sticky_header'=>true,'sticky_summary'=>true,'result_scroll'=>true]",
    'private function fixedColumns',
    'private function summaryRow',
    'private function summaryMetricKind',
    "'metric_version'=>'store-operations-phase-two-v1'",
    "'data_as_of'=>date('Y-m-d H:i:s')",
    'if(!$columns)return[]',
] as $needle) {
    if (strpos($service, $needle) === false) throw new RuntimeException('phase-two table projection contract missing: ' . $needle);
}
if (strpos($service, 'if(!$records||!$columns)return[]') !== false) {
    throw new RuntimeException('phase-two reports must keep a summary row when the result set is empty');
}
foreach ([
    "array_merge(\$input, ['_internal_all' => true, 'page' => 1])",
    "'summary_row' => \$result['summary_row'] ?? []",
    "'column_groups' => \$result['column_groups'] ?? []",
    "'metric_version' => \$result['metric_version'] ?? self::METRIC_VERSION",
] as $needle) {
    if (strpos($report, $needle) === false) {
        throw new RuntimeException('report export contract missing: ' . $needle);
    }
}
foreach ([
    "'new_customer_analysis' => '新客明细表'",
    "'new_customer_analysis_summary' => '新客汇总表'",
    "'report'=>'new_customer_analysis'",
    "'member_id'=>'member_id','source_id'=>'source_id','source_label'=>'source_label','salesperson'=>'salesperson'",
    "'cross_industry_customer_detail' => '异业收客明细表'",
    "'cross_industry_customer_summary' => '异业收客汇总表'",
    "'include_followup_cash'=>1",
    "'report'=>'field_acquisition_detail'",
] as $needle) {
    if (strpos($service, $needle) === false) throw new RuntimeException('new-customer drilldown contract missing: ' . $needle);
}
foreach ([
    "'experience_card_amount_version'",
    "array_key_exists('value',\$experienceCardAmount)",
    "'新客明细表'=>['editable_fields'=>[['key'=>'experience_card_amount'",
] as $needle) {
    if (strpos($service, $needle) === false) throw new RuntimeException('new-customer manual experience amount contract missing: ' . $needle);
}
$annotation = (string)file_get_contents($root . '/后端代码/app/services/report/StoreOperationsReportAnnotationServices.php');
foreach (["'new_customer_analysis' => ['experience_card_amount', 'care_duration']", "'experience_card_amount' => 'integer_cents'"] as $needle) {
    if (strpos($annotation, $needle) === false) throw new RuntimeException('new-customer annotation contract missing: ' . $needle);
}
if (preg_match('/\.\.\.\(serverCatalogByCode\.value\.get\(tab\.code\) \|\| \{\}\),\s*\.\.\.tab/s', $view) !== 1) {
    throw new RuntimeException('report navigation must keep the confirmed standard names when historical catalog labels exist');
}

echo "PASS phase-two report projection contract\n";
