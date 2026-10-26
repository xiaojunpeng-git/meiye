<?php

/** 本地只读业务对账：纯服务会员应入表，进店汇总、按月下钻和导出必须同粒度。 */
if (getenv('R6_LOCAL_TEST') !== '1') throw new RuntimeException('explicit local flag required');
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';
$app = new \think\App(rtrim($backend, '/\\') . '/');
$app->initialize();

use app\services\report\StoreUnifiedReportServices;
use think\facade\Db;

function checkVisit(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
    echo 'PASS ' . $label . PHP_EOL;
}

$range = ['start' => '2026-09-01', 'end' => '2026-10-15'];
$input = ['start_date' => $range['start'], 'end_date' => $range['end'], '_internal_all' => true,
    'page' => 1, 'limit' => 100, '_report_scope' => ['mode' => 'stores']];
$stores = Db::name('cashier_v3_entitlement_service_fact')
    ->whereBetween('business_date', [$range['start'], $range['end']])
    ->where('member_id', '>', 0)->where('service_status', 'completed')
    ->distinct(true)->column('store_id');
$service = new StoreUnifiedReportServices();
$selected = null;
foreach ($stores as $storeId) {
    $storeId = (int)$storeId;
    $summary = $service->query([$storeId], $input + ['report' => 'member_visit_analysis']);
    foreach ($summary['records'] as $row) {
        if ((int)($row['total_visits'] ?? 0) <= 0) continue;
        $memberId = (int)$row['member_id'];
        $cash = Db::name('cashier_v3_payment_fact')->where('store_id', $storeId)
            ->where('member_id', $memberId)->whereBetween('business_date', [$range['start'], $range['end']])
            ->where('status', 'effective')->where('fact_direction', 'forward')->where('amount_cents', '>', 0)->count();
        if ((int)$cash === 0) { $selected = [$storeId, $memberId, $row, $summary]; break 2; }
    }
}
checkVisit($selected !== null, 'local pure-service member is included');
[$storeId, $memberId, $row, $summary] = $selected;
$pagedInput = array_merge($input, ['_internal_all' => false, 'report' => 'member_visit_analysis', 'limit' => 20]);
$paged = $service->query([$storeId], $pagedInput);
checkVisit((int)$paged['total'] === count($summary['records']) && count($paged['records']) === min(20, $paged['total']), 'summary total and first page share full result scope');
$summaryExport = $service->export([$storeId], array_merge($input, ['report' => 'member_visit_analysis']));
checkVisit($summaryExport['records'] === $summary['records'], 'summary export includes pure-service member under the same scope');
$detailInput = $input + ['report' => 'member_visit_detail', 'member_id' => $memberId];
$detail = $service->query([$storeId], $detailInput);
checkVisit(count($detail['records']) === (int)$row['total_visits'], 'distinct visit days equal drilldown rows');
checkVisit($detail['metric_version'] === $summary['metric_version'], 'summary and drilldown metric versions match');
checkVisit(array_column($detail['columns'], 'label') === ['会员', '手机', '单号', '日期', '金额'], 'detail exposes exactly the five requested fields');
foreach ($detail['records'] as $visit) {
    checkVisit((string)$visit['visit_date'] !== '' && (string)$visit['order_no'] !== '', 'visit has date and source document');
    $month = (int)substr((string)$visit['visit_date'], 5, 2);
    $monthly = $service->query([$storeId], $detailInput + ['visit_month' => $month]);
    checkVisit(count($monthly['records']) === (int)$row['month_' . $month . '_visits'], 'month drilldown equals clicked month count');
}
$export = $service->export([$storeId], $detailInput);
checkVisit($export['records'] === $detail['records'], 'detail export matches complete query');
checkVisit((int)$summary['summary_row']['total_visits'] >= (int)$row['total_visits'], 'full result summary contains selected member');
// 金额列的下钻读收退款事实；全年和月度合计必须与被点击的现金业绩相同，不能误用服务消耗业绩。
$cashRow = null;
foreach ($summary['records'] as $candidate) {
    if (round((float)($candidate['annual_cash'] ?? 0) * 100) !== 0.0) { $cashRow = $candidate; break; }
}
checkVisit($cashRow !== null, 'local cash member is available for amount drilldown');
$cashInput = array_merge($detailInput, ['member_id' => (int)$cashRow['member_id'], 'visit_metric' => 'cash']);
$cashDetail = $service->query([$storeId], $cashInput);
checkVisit(round((float)$cashDetail['summary_row']['amount'] * 100) === round((float)$cashRow['annual_cash'] * 100), 'annual cash detail reconciles to clicked amount');
foreach (range(1, 12) as $month) {
    $monthly = $service->query([$storeId], array_merge($cashInput, ['visit_month' => $month]));
    checkVisit(round((float)$monthly['summary_row']['amount'] * 100) === round((float)$cashRow['month_' . $month . '_cash'] * 100), 'month ' . $month . ' cash detail reconciles');
}
$cashExport = $service->export([$storeId], $cashInput);
checkVisit($cashExport['records'] === $cashDetail['records'], 'cash detail export matches complete query');
// 年度列表只补齐当前页的手机与来源；逐页结果必须仍与全量导出的同一位置完全一致。
$annualInput = array_merge($input, ['report' => 'member_visit_analysis', 'start_date' => '2026-01-01',
    'end_date' => '2026-12-31', '_internal_all' => false, 'limit' => 20]);
$annualFirst = $service->query([$storeId], $annualInput);
$annualExport = $service->export([$storeId], $annualInput);
checkVisit($annualFirst['records'] === array_slice($annualExport['records'], 0, 20), 'annual first page matches full export including phone and source');
if ((int)$annualFirst['total'] > 20) {
    $annualSecond = $service->query([$storeId], array_merge($annualInput, ['page' => 2]));
    checkVisit($annualSecond['records'] === array_slice($annualExport['records'], 20, 20), 'annual second page matches full export including phone and source');
}
echo 'LOCAL_SAMPLE store=' . $storeId . ' member=' . $memberId . ' visits=' . $row['total_visits'] . PHP_EOL;
