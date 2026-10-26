<?php

declare(strict_types=1);

// 只读核对本地 BJ26101400003：E 来源补交独立入地推明细、汇总、下钻与导出，原 A 销售来源不变。
if (getenv('FIELD_ACQUISITION_REPAYMENT_TEST') !== '1') {
    throw new RuntimeException('Explicit local read-only test flag required');
}

$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';
(new \think\App(rtrim($backend, '/\\') . '/'))->initialize();

$repayment = \think\facade\Db::name('cashier_v3_debt_repayment')
    ->where('repayment_no', 'BJ26101400003')->find();
if (!$repayment || $repayment['status'] !== 'succeeded'
    || $repayment['business_source_primary_name_snapshot'] !== 'E地推拓客') {
    throw new RuntimeException('E-source repayment fixture is missing');
}
$origin = \think\facade\Db::name('cashier_v3_sale_fact')
    ->where('order_id', (string)$repayment['sales_order_id'])->where('source_type', 'card')->find();
if (!$origin || $origin['business_source_primary_name_snapshot'] !== 'A老客首护') {
    throw new RuntimeException('Original A-source sale changed');
}

$report = new \app\services\report\StoreUnifiedReportServices();
$scope = ['_report_scope' => ['mode' => 'stores']];
$dayInput = $scope + [
    'report' => 'field_acquisition_detail', 'start_date' => '2026-10-14',
    'end_date' => '2026-10-14', 'member_id' => (int)$repayment['member_id'],
    'page' => 1, 'limit' => 100,
];
$day = $report->query([(int)$repayment['store_id']], $dayInput);
$rows = array_values(array_filter($day['records'], static function (array $row): bool {
    return ($row['repayment_no'] ?? '') === 'BJ26101400003';
}));
if (count($rows) !== 1 || $rows[0]['business_date'] !== '2026-10-14'
    || $rows[0]['source'] !== '合作方' || $rows[0]['cash_3'] !== '90'
    || ($rows[0]['annotation_subject_type'] ?? '') !== 'field_acquisition_repayment') {
    throw new RuntimeException('Repayment row, current guide round or stable subject is wrong');
}
$key = (string)$rows[0]['annotation_subject_key'];
$resolver = new \app\services\report\StoreReportParticipantScopeServices();
if (!$resolver->resolveSubject((string)$repayment['tenant_id'], 'field_acquisition_repayment', $key)) {
    throw new RuntimeException('Editable repayment subject cannot be resolved');
}
$guide = \think\facade\Db::name('cashier_v3_supplement_attribution_person')
    ->where('repayment_id', (string)$repayment['repayment_id'])
    ->where('revision_no', 2)->where('role', 'guide')->find();
if (!$guide || !$resolver->resolveSubject((string)$repayment['tenant_id'], 'field_acquisition_repayment', $key, (int)$guide['employee_id'])
    || $resolver->resolveSubject((string)$repayment['tenant_id'], 'field_acquisition_repayment', $key, 1)) {
    throw new RuntimeException('Repayment manual-field participant permission is wrong');
}

$summary = $report->query([(int)$repayment['store_id']], $scope + [
    'report' => 'field_acquisition_summary', 'start_date' => '2026-01-01',
    'end_date' => '2026-12-31', 'member_id' => (int)$repayment['member_id'],
    'page' => 1, 'limit' => 100,
]);
$summaryRows = array_values(array_filter($summary['records'], static function (array $row) use ($repayment): bool {
    return (int)($row['member_id'] ?? 0) === (int)$repayment['member_id'];
}));
if (count($summaryRows) !== 1 || (float)$summaryRows[0]['month_10'] < 90) {
    throw new RuntimeException('E-source repayment is missing from October summary');
}
$drillInput = $dayInput;
$drillInput['start_date'] = '2026-10-01';
$drillInput['end_date'] = '2026-10-31';
$drillInput['receipt_period'] = 1;
$drill = $report->query([(int)$repayment['store_id']], $drillInput);
$export = $report->export([(int)$repayment['store_id']], $drillInput);
$matches = array_values(array_filter($drill['records'], static function (array $row): bool {
    return ($row['repayment_no'] ?? '') === 'BJ26101400003';
}));
if (count($matches) !== 1 || $matches[0]['cash_3'] !== '90'
    || $export['records'] !== $drill['records']) {
    throw new RuntimeException('October drilldown and export do not match the independent repayment');
}
$drillCash = 0.0;
foreach ($drill['records'] as $row) {
    foreach (['cash_none', 'cash_1', 'cash_2', 'cash_3'] as $key) $drillCash += (float)($row[$key] ?? 0);
}
if (abs($drillCash - (float)$summaryRows[0]['month_10']) > 0.001) {
    throw new RuntimeException('October summary and drilldown cash are not reconciled');
}
echo "PASS BJ26101400003: E repayment row 90, original A source unchanged, latest round 3, summary/drilldown/export and permissions\n";
