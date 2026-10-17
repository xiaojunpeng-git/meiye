<?php

declare(strict_types=1);

// 仅在本地瑞昊副本读取 E 地推原销售收款和独立补交；不新增、修改或作废业务事实。
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
// 首年合计与十二个月份应共用整数元展示声明，金额原值留给导出和下钻对账。
$summaryColumns = array_column($summary['columns'], null, 'key');
foreach (array_merge(['annual_total'], array_map(static function (int $month): string {
    return 'month_' . $month;
}, range(1, 12))) as $key) {
    if (($summaryColumns[$key]['value_type'] ?? '') !== 'money'
        || ($summaryColumns[$key]['display_precision'] ?? null) !== 0) {
        throw new RuntimeException('Field acquisition summary money column lacks whole-yuan display metadata: ' . $key);
    }
}
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
// 金额列只声明页面整数元精度；明细和导出仍保留原始收款金额供对账。
$moneyColumns = ['labor_fee', 'cash_none', 'cash_1', 'cash_2', 'cash_3'];
$columnsByKey = array_column($ordinaryDetail['columns'], null, 'key');
foreach ($moneyColumns as $key) {
    if (($columnsByKey[$key]['value_type'] ?? '') !== 'money'
        || ($columnsByKey[$key]['display_precision'] ?? null) !== 0) {
        throw new RuntimeException('Field acquisition money column lacks whole-yuan display metadata: ' . $key);
    }
}
foreach ($ordinaryDetail['records'] as $row) {
    if ((int)($row['member_id'] ?? 0) <= 0) {
        throw new RuntimeException('Unbound sale leaked into field acquisition detail');
    }
}
$rows = array_values(array_filter($summary['records'], static function (array $row): bool {
    return (int)($row['member_id'] ?? 0) === 1136097;
}));
if (count($rows) !== 1 || (string)$rows[0]['month_10'] !== '2186'
    || (string)$rows[0]['annual_total'] !== '2186') {
    throw new RuntimeException('October E-source sales and repayments did not reconcile to 2186');
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
if (count($detail['records']) !== 4 || $export['records'] !== $detail['records']) {
    throw new RuntimeException('October query and export must show two E-sale lines and two E-repayment lines');
}
$byDay = [];
$repayments = [];
foreach ($detail['records'] as $row) {
    $amount = (float)($row['cash_none'] ?? 0) + (float)($row['cash_1'] ?? 0)
        + (float)($row['cash_2'] ?? 0) + (float)($row['cash_3'] ?? 0);
    $day = (string)$row['business_date'];
    $byDay[$day] = ($byDay[$day] ?? 0) + $amount;
    if ((string)($row['repayment_no'] ?? '') !== '') {
        $repayments[(string)$row['repayment_no']] = $amount;
    }
}
if (($byDay['2026-10-01'] ?? null) !== 1000.0
    || ($byDay['2026-10-13'] ?? null) !== 1186.0
    || ($repayments['BJ26101300002'] ?? null) !== 98.0
    || ($repayments['BJ26101300003'] ?? null) !== 88.0) {
    throw new RuntimeException('October drilldown did not explain each sale and independent E repayment');
}

echo "PASS member-only detail and summary; October 2186 = sales 2000 + E repayments 186; query/export agree\n";
