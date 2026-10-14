<?php

declare(strict_types=1);

use app\services\report\StoreUnifiedReportPhaseTwoServices;
use app\services\report\StoreUnifiedReportServices;
use think\facade\Db;

// Run inside the local PHP container. This test only reads lifecycle, order,
// payment, and report facts; it never creates customers or changes balances.
require '/var/www/html/vendor/autoload.php';
$app = new \think\App('/var/www/html/');
$app->initialize();

$year = (int)date('Y');
$range = ['start' => sprintf('%04d-01-01', $year), 'end' => date('Y-m-d')];
$stores = array_map('intval', Db::name('cashier_v3_customer_lifecycle_fact')
    ->whereIn('event_type', ['course_pending_settlement', 'first_course_completed'])
    ->whereBetween('business_date', [$range['start'], $range['end']])
    ->group('store_id')->column('store_id'));
if (!$stores) {
    echo "SKIP no local first-course sale facts\n";
    exit(0);
}

$service = new StoreUnifiedReportPhaseTwoServices();
$input = ['_internal_all' => true];
$detail = $service->query('new_customer_analysis', $stores, $range, $input);
$summary = $service->query('new_customer_analysis_summary', $stores, $range, $input);
// 同一原销售商品、业务日和来源/人员分组的补清应累计到销售明细，
// 但原单事实与不同来源补清仍保留可追溯的独立归因。
$saleKeys = [];
foreach ($detail['records'] as $row) {
    if (($row['annotation_subject_type'] ?? '') !== 'sale_line') continue;
    $saleKeys[implode('|', [(string)$row['order_id'], (string)$row['source_line_id'],
        (string)$row['business_date'], (string)$row['_summary_group_key']])] = true;
}
foreach ($detail['records'] as $row) {
    if (($row['annotation_subject_type'] ?? '') !== 'debt_repayment') continue;
    $key = implode('|', [(string)$row['order_id'], (string)$row['source_line_id'],
        (string)$row['business_date'], (string)$row['_summary_group_key']]);
    if (isset($saleKeys[$key])) throw new RuntimeException('same-day, same-source repayment was not merged into its sale line');
}
if (($detail['metric_version'] ?? '') !== 'new-customer-first-sale-v6'
    || ($summary['metric_version'] ?? '') !== 'new-customer-first-sale-grouped-v7') {
    throw new RuntimeException('new-customer reports have incorrect dedicated metric versions');
}
// 汇总不得按会员拆行；同店同人员和来源的不同会员合并，异店同名则保持独立。
if (in_array('member', array_column($summary['columns'], 'key'), true)) {
    throw new RuntimeException('new-customer summary still exposes the member column');
}
$addSummaryRow = new ReflectionMethod($service, 'newCustomerSummaryAddRow');
$addSummaryRow->setAccessible(true);
$sampleRows = [];
foreach ([[1, 101], [1, 102], [2, 103]] as [$storeId, $memberId]) {
    $addSummaryRow->invokeArgs($service, [&$sampleRows, [
        'store_id' => $storeId, 'division_name' => '测试分公司', 'store_name' => '同名门店',
        'member_id' => $memberId, 'member_name' => '测试会员', 'business_date' => $range['start'],
    ], [], [], [], 100, 'full', 1, 'B导购引流', 2, []]);
}
if (count($sampleRows) !== 2 || array_sum(array_column($sampleRows, 'month_' . (int)substr($range['start'], 5, 2) . '_count')) !== 3) {
    throw new RuntimeException('new-customer summary groups by member or mixes stores');
}
foreach ($summary['records'] as $row) {
    // 汇总分公司必须来自与明细相同的组织统计维度，分组键也要精确下钻到这些明细。
    $matchingDetail = array_values(array_filter($detail['records'], static fn (array $candidate): bool =>
        (string)($candidate['_summary_group_key'] ?? '') === (string)$row['summary_group_key']));
    if (!$matchingDetail || array_filter($matchingDetail, static fn (array $candidate): bool =>
        (string)($candidate['division_name'] ?? '') !== (string)$row['division_name']
        || (string)($candidate['company_dimension_id'] ?? '') !== (string)($row['company_dimension_id'] ?? ''))) {
        throw new RuntimeException('summary company dimension differs from its detail rows');
    }
    if (array_key_exists('member', $row) || array_key_exists('member_id', $row)) {
        throw new RuntimeException('member identity leaked into a grouped summary row');
    }
    foreach ($row['_drilldown'] ?? [] as $drilldown) {
        if (isset($drilldown['param_map']['member_id']) || ($drilldown['param_map']['summary_group_key'] ?? '') !== 'summary_group_key') {
            throw new RuntimeException('grouped summary drilldown still targets a single member');
        }
    }
    // 精确下钻必须返回这一组所有会员的明细，不能退化为首位会员或其他门店的记录。
    $groupDetail = $service->query('new_customer_analysis', $stores, $range, [
        '_internal_all' => true, 'summary_group_key' => (string)$row['summary_group_key'],
    ]);
    if (($groupDetail['records'] ?? []) === []) {
        throw new RuntimeException('grouped new-customer row has no matching detail');
    }
    foreach ($groupDetail['records'] as $detailRow) {
        if (($detailRow['_summary_group_key'] ?? '') !== $row['summary_group_key']) {
            throw new RuntimeException('grouped new-customer drilldown mixed other dimensions');
        }
    }
}
// 以分为单位比较报表金额，避免展示格式掩盖分摊或来源分组错误。
$toCents = static function ($amount): int {
    $number = (string)$amount;
    $negative = strpos($number, '-') === 0;
    $parts = explode('.', ltrim($number, '-'), 2);
    $cents = ((int)$parts[0]) * 100 + (int)str_pad(substr($parts[1] ?? '', 0, 2), 2, '0');
    return $negative ? -$cents : $cents;
};
// 两表按一级来源 A/G/E 排除首次办卡及补交；已停用来源仍须按 ID 与历史快照排除。
$excludedSourceIds = array_map('intval', array_column(array_filter(
    Db::name('cashier_v3_business_source')->where('parent_id', 0)->field('id,name')->select()->toArray(),
    static fn (array $source): bool => preg_match('/^[AGE]/u', trim((string)$source['name'])) === 1
), 'id'));
$excludedOrders = $excludedSourceIds ? Db::name('cashier_v3_customer_lifecycle_fact')
    ->whereIn('source_primary_id', $excludedSourceIds)->whereIn('event_type', ['course_pending_settlement', 'first_course_completed'])
    ->where('status', 'effective')->whereBetween('business_date', [$range['start'], $range['end']])
    ->column('related_order_id') : [];
if ($excludedSourceIds) {
    // 明确按每个被排除的来源筛选，也不能绕过后端统一行集。
    foreach (['new_customer_analysis', 'new_customer_analysis_summary'] as $reportCode) {
        foreach ($excludedSourceIds as $sourceId) {
            $excludedResult = $service->query($reportCode, $stores, $range, ['source_id' => $sourceId, '_internal_all' => true]);
            if (($excludedResult['records'] ?? []) !== []) throw new RuntimeException('source A/G/E bypassed the new-customer report exclusion');
        }
    }
}
$excludedSource = new ReflectionMethod($service, 'isExcludedNewCustomerSource');
$excludedSource->setAccessible(true);
foreach (['A历史', 'G历史', 'E历史'] as $snapshot) {
    if (!$excludedSource->invoke($service, 0, $snapshot, [])) {
        throw new RuntimeException('excluded historical source snapshot leaked: ' . $snapshot);
    }
}
if ($excludedSource->invoke($service, 0, 'B导购引流', [])) {
    throw new RuntimeException('allowed B source was excluded');
}
if (!$excludedSource->invoke($service, 73, 'B已改名来源', [73])) {
    throw new RuntimeException('renamed excluded source ID leaked');
}
$orders = [];
foreach ($detail['records'] as $row) {
    $order = (string)($row['order_id'] ?? '');
    if ($order === '') throw new RuntimeException('new customer detail has no first-card order');
    if (in_array($order, $excludedOrders, true)) throw new RuntimeException('source A/G/E leaked into new-customer detail');
    if (preg_match('/^[AGE]/u', trim((string)($row['source'] ?? ''))) === 1) throw new RuntimeException('source A/G/E repayment leaked into detail');
    $orders[$order] = true;
    if ((string)($row['business_date'] ?? '') < $range['start'] || (string)($row['business_date'] ?? '') > $range['end']) {
        throw new RuntimeException('first-course sale is outside the selected dates');
    }
}
$count = 0;
foreach ($summary['summary_row'] ?? [] as $key => $value) {
    if (preg_match('/^month_\d+_count$/', (string)$key)) $count += (int)$value;
}
foreach ($summary['records'] as $row) {
    if (in_array((int)($row['source_id'] ?? 0), $excludedSourceIds, true)
        || preg_match('/^[AGE]/u', trim((string)($row['source'] ?? ''))) === 1) {
        throw new RuntimeException('source A/G/E leaked into summary');
    }
}
if (count($orders) !== $count) {
    throw new RuntimeException('new-customer count must equal distinct qualifying first-card orders');
}

// Split payments and later debt settlement must reconcile between the two reports.
foreach (['full' => 'full_payment', 'deposit' => 'deposit_payment', 'cleared' => 'cleared_payment'] as $kind => $detailKey) {
    $summaryCents = 0;
    foreach ($summary['summary_row'] ?? [] as $key => $value) {
        if (preg_match('/^month_\d+_' . $kind . '$/', (string)$key)) $summaryCents += $toCents($value);
    }
    if ($summaryCents !== $toCents($detail['summary_row'][$detailKey] ?? '0')) {
        throw new RuntimeException($kind . ' payment differs between new-customer summary and detail');
    }
}

// A voided first-card order must not appear in either normal operating report.
$voids = Db::name('cashier_v3_order_lifecycle_operation')
    ->where('source_type', 'sales')->where('operation_type', 'void')->where('status', 'succeeded')
    ->column('source_order_id');
if (array_intersect(array_keys($orders), $voids)) {
    throw new RuntimeException('voided first-card order leaked into new-customer report');
}
$allCourseEvents = Db::name('cashier_v3_customer_lifecycle_fact')
    ->whereIn('event_type', ['course_pending_settlement', 'first_course_completed'])
    ->where('status', 'effective')->where('business_date', '<=', $range['end'])
    ->field('tenant_id,store_id,member_id,related_order_id,source_primary_id,source_label_snapshot,business_date')
    ->order('occurred_at', 'asc')->order('id', 'asc')->select()->toArray();
$firstByMember = [];
foreach ($allCourseEvents as $event) {
    if (in_array((string)$event['related_order_id'], $voids, true)) continue;
    $memberKey = (string)$event['tenant_id'] . '|' . (int)$event['member_id'];
    if (!isset($firstByMember[$memberKey])) $firstByMember[$memberKey] = $event;
}
$expected = [];
foreach ($firstByMember as $event) {
    if (!in_array((int)$event['store_id'], $stores, true)
        || (string)$event['business_date'] < $range['start']
        || in_array((int)$event['source_primary_id'], $excludedSourceIds, true)
        || preg_match('/^[AGE]/u', trim((string)($event['source_label_snapshot'] ?? ''))) === 1) continue;
    $expected[] = (string)$event['related_order_id'];
}
$expected = array_values(array_unique($expected));
sort($expected);
$actual = array_keys($orders);
sort($actual);
if ($actual !== $expected) {
    throw new RuntimeException('detail orders differ from valid first-course sales');
}
// 欠款订单在销售当日入表：已收部分只进定金，不把未收欠款伪装成现金。
$pendingOrder = Db::name('cashier_v3_sales_order')->where('order_no', 'XS26100300005')->value('order_id');
$pendingEvent = $pendingOrder ? Db::name('cashier_v3_customer_lifecycle_fact')
    ->where('related_order_id', $pendingOrder)
    ->whereIn('event_type', ['course_pending_settlement', 'first_course_completed'])
    ->where('status', 'effective')->field('source_primary_id,source_label_snapshot')->find() : null;
// 本地旧订单号可留存而首次疗程卡资格事件已不存在；仅在资格事实可核实时断言该夹具。
if ($pendingEvent && !$excludedSource->invoke($service, (int)$pendingEvent['source_primary_id'],
    (string)$pendingEvent['source_label_snapshot'], $excludedSourceIds)) {
    $pendingRange = ['start' => '2026-10-03', 'end' => '2026-10-03'];
    $pendingDetail = $service->query('new_customer_analysis', [23], $pendingRange, ['_internal_all' => true]);
    $pendingSummary = $service->query('new_customer_analysis_summary', [23], $pendingRange, ['_internal_all' => true]);
    $matching = array_values(array_filter($pendingDetail['records'], static fn (array $row): bool =>
        (string)($row['order_id'] ?? '') === (string)$pendingOrder
        && (string)($row['annotation_subject_type'] ?? '') === 'sale_line'));
    if (count($matching) !== 1 || $toCents($matching[0]['deposit_payment'] ?? 0) !== 98000
        || $toCents($matching[0]['full_payment'] ?? 0) !== 0
        || array_sum(array_map(static function (string $key) use ($pendingSummary): int { return (int)($pendingSummary['summary_row'][$key] ?? 0); }, ['month_10_count'])) < 1) {
        throw new RuntimeException('pending first-course sale was not included consistently in detail and summary');
    }
    if ((string)$matching[0]['source'] === 'B导购引流'
        && $toCents($matching[0]['cleared_payment'] ?? 0) !== 20000) {
        throw new RuntimeException('same-source 200-yuan repayment was not included in the original B-source sale row');
    }
    foreach (['full' => 'full_payment', 'deposit' => 'deposit_payment', 'cleared' => 'cleared_payment'] as $kind => $detailKey) {
        if ($toCents($pendingSummary['summary_row']['month_10_' . $kind] ?? 0)
            !== $toCents($pendingDetail['summary_row'][$detailKey] ?? 0)) {
            throw new RuntimeException('daily pending-sale cash differs between detail and summary: ' . $kind);
        }
    }
    if (count(array_filter($pendingDetail['records'], static fn (array $row): bool =>
        preg_match('/^[AGE]/u', trim((string)($row['source'] ?? ''))) === 1)) !== 0
        || count(array_filter($pendingSummary['records'], static fn (array $row): bool =>
            preg_match('/^[AGE]/u', trim((string)($row['source'] ?? ''))) === 1)) !== 0) {
        throw new RuntimeException('A/G/E-source repayment still appears in the first-course reports');
    }
}
// 页面与导出必须走同一查询，避免只在屏幕隐藏来源 A/G/E，CSV 仍泄露旧口径。
$reportService = new StoreUnifiedReportServices();
foreach (['new_customer_analysis' => $detail, 'new_customer_analysis_summary' => $summary] as $code => $visibleReport) {
    $export = $reportService->export($stores, ['report' => $code, 'start_date' => $range['start'], 'end_date' => $range['end']]);
    if (($export['records'] ?? []) != $visibleReport['records']
        || ($export['summary_row'] ?? []) != ($visibleReport['summary_row'] ?? [])
        || ($export['metric_version'] ?? '') !== ($code === 'new_customer_analysis_summary' ? 'new-customer-first-sale-grouped-v7' : 'new-customer-first-sale-v6')) {
        throw new RuntimeException($code . ' export differs from query scope or metric version');
    }
}
foreach ([$detail, $summary] as $report) {
    foreach ($report['columns'] as $column) {
        if (strpos((string)($column['source_explanation'] ?? ''), '口径') !== false) {
            throw new RuntimeException('new-customer column explanation contains unexplained jargon');
        }
    }
}
echo 'PASS first-course new-customer reports: ', count($orders), " orders, ", $count, " people\n";
