<?php

declare(strict_types=1);

// 仅在本地瑞昊副本读取已确认的两笔 E 地推收款；不新增、修改或作废业务事实。
if (getenv('FIELD_ACQUISITION_CASH_MONTH_TEST') !== '1') {
    throw new RuntimeException('Explicit local read-only test flag required');
}

$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';
(new \think\App(rtrim($backend, '/\\') . '/'))->initialize();

$report = new \app\services\report\StoreUnifiedReportServices();
$scope = ['_report_scope' => ['mode' => 'stores']];
$summary = $report->query([18], $scope + [
    'report' => 'field_acquisition_summary',
    'start_date' => '2026-01-01',
    'end_date' => '2026-12-31',
    'page' => 1,
    'limit' => 100,
]);
// 无会员的零额 E 销售仍保留原始订单，但不能成为两张按会员归行的报表记录。
foreach ($summary['records'] as $row) {
    if ((int)($row['member_id'] ?? 0) <= 0) {
        throw new RuntimeException('Unbound sale leaked into field acquisition summary');
    }
}
$ordinaryDetail = $report->query([18], $scope + [
    'report' => 'field_acquisition_detail',
    'start_date' => '2026-10-01',
    'end_date' => '2026-10-31',
    'page' => 1,
    'limit' => 100,
]);
foreach ($ordinaryDetail['records'] as $row) {
    if ((int)($row['member_id'] ?? 0) <= 0) {
        throw new RuntimeException('Unbound sale leaked into field acquisition detail');
    }
}
$rows = array_values(array_filter($summary['records'], static function (array $row): bool {
    return (int)($row['member_id'] ?? 0) === 1136097;
}));
if (count($rows) !== 1 || (string)$rows[0]['month_10'] !== '2000'
    || (string)$rows[0]['annual_total'] !== '2000') {
    throw new RuntimeException('October E-source receipts did not reconcile to 2000');
}
$drilldown = $rows[0]['_drilldown']['month_10'] ?? [];
if (($drilldown['params']['receipt_period'] ?? null) !== 1
    || ($drilldown['params']['start_date'] ?? '') !== '2026-10-01'
    || ($drilldown['params']['end_date'] ?? '') !== '2026-10-31') {
    throw new RuntimeException('October drilldown lost the receipt-month filter');
}

$input = $scope + [
    'report' => 'field_acquisition_detail',
    'start_date' => '2026-10-01',
    'end_date' => '2026-10-31',
    'member_id' => 1136097,
    'receipt_period' => 1,
    'page' => 1,
    'limit' => 100,
];
$detail = $report->query([18], $input);
$export = $report->export([18], $input);
if (count($detail['records']) !== 2 || $export['records'] !== $detail['records']) {
    throw new RuntimeException('October query and export must show the same two E-sale lines');
}
$byDay = [];
foreach ($detail['records'] as $row) {
    $amount = (float)($row['cash_none'] ?? 0) + (float)($row['cash_1'] ?? 0)
        + (float)($row['cash_2'] ?? 0) + (float)($row['cash_3'] ?? 0);
    $byDay[(string)$row['business_date']] = $amount;
}
if (($byDay['2026-10-01'] ?? null) !== 1000.0
    || ($byDay['2026-10-13'] ?? null) !== 1000.0) {
    throw new RuntimeException('October drilldown did not explain both actual E receipts');
}

echo "PASS member-only detail and summary; October 2000 = October 1 receipt 1000 + October 13 receipt 1000; query/export agree\n";
