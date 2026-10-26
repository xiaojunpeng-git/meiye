<?php

declare(strict_types=1);

// 仅在本地瑞昊副本执行：固定事实检验 E 来源护理轮次、汇总和导出，不写业务数据。
if (getenv('FIELD_ACQUISITION_LOCAL_TEST') !== '1') {
    throw new RuntimeException('Explicit local test flag required');
}

$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';
$app = new \think\App(rtrim($backend, '/\\') . '/');
$app->initialize();

$report = new \app\services\report\StoreUnifiedReportServices();
$input = [
    'report' => 'field_acquisition_detail',
    'start_date' => '2026-10-01',
    'end_date' => '2026-10-31',
    'member_id' => 1136096,
    'limit' => 20,
    '_report_scope' => ['mode' => 'stores'],
];
$detail = $report->query([18], $input);
$groups = array_column($detail['column_groups'] ?? [], 'label');
if ($groups !== ['无轮次', '第一次', '第二次', '第三次']) {
    throw new RuntimeException('Service group order is incorrect');
}
if (($detail['metric_version'] ?? '') !== 'field-acquisition-repayment-v6') {
    throw new RuntimeException('Service round metric version is incorrect');
}
$rows = $detail['records'] ?? [];
if (count($rows) !== 2) throw new RuntimeException('Expected two E-source sale lines for member 1136096');
foreach ($rows as $row) {
    if (($row['visit_none_date'] ?? '') !== '2026-10-01'
        || ($row['visit_none_project'] ?? '') !== '面部补水'
        || ($row['first_e_visit_date'] ?? '') !== '2026-10-01') {
        throw new RuntimeException('E-source no-round service is missing');
    }
    foreach (['visit_1_date', 'visit_2_date', 'visit_3_date'] as $key) {
        if (($row[$key] ?? '') !== '') throw new RuntimeException('A-source service leaked into ' . $key);
    }
    if (strpos(json_encode($row, JSON_UNESCAPED_UNICODE), '2026-10-05') !== false) {
        throw new RuntimeException('A-source October 5 service leaked into E-source detail');
    }
}
$export = $report->export([18], $input);
if (($export['column_groups'] ?? []) !== ($detail['column_groups'] ?? [])
    || ($export['records'] ?? []) !== $rows) {
    throw new RuntimeException('Export differs from detail query');
}
$summary = $report->query([18], array_merge($input, ['report' => 'field_acquisition_summary']));
$summaryRows = array_values(array_filter($summary['records'] ?? [], static function (array $row): bool {
    return (int)($row['member_id'] ?? 0) === 1136096;
}));
if (count($summaryRows) !== 1 || ($summaryRows[0]['first_visit_date'] ?? '') !== '2026-10-01') {
    throw new RuntimeException('Summary lost the earliest E-source service date');
}
echo "PASS E-source no-round service, A-source exclusion, summary and export\n";

$firstRound = $report->query([105], array_merge($input, ['member_id' => 1136813]));
if (empty($firstRound['records'])) throw new RuntimeException('First-round fixture is missing');
foreach ($firstRound['records'] as $row) {
    if (!in_array('2026-10-05', explode('、', (string)($row['visit_1_date'] ?? '')), true)
        || ($row['visit_1_project'] ?? '') === '') {
        throw new RuntimeException('E-source first-round service is misplaced: ' . json_encode([
            'none' => $row['visit_none_date'] ?? '',
            'first' => $row['visit_1_date'] ?? '',
            'project' => $row['visit_1_project'] ?? '',
        ], JSON_UNESCAPED_UNICODE));
    }
}
echo "PASS E-source first-round service\n";
