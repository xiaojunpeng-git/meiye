<?php

declare(strict_types=1);

// Read-only local regression against the existing completed TK refund.
ob_start();
require __DIR__ . '/../../report-refund-visibility/php/tk-member-salesperson-local-mysql.php';
ob_end_clean();

use app\services\query\metric\RegisteredMetricReadServices;
use app\services\query\metric\MetricMoneyFormatter;
use app\services\report\StoreMemberConsumptionRefundReadServices;
use app\services\report\StoreUnifiedReportServices;

$storeId = 23;
$day = ['start' => '2026-10-13', 'end' => '2026-10-13'];
$input = ['report' => 'store_item_analysis', 'start_date' => $day['start'], 'end_date' => $day['end']];
$report = (new StoreUnifiedReportServices())->query($storeId, $input);
$export = (new StoreUnifiedReportServices())->export($storeId, $input);
$row = $report['records'][0] ?? [];
$refundReader = new StoreMemberConsumptionRefundReadServices();
$refundQuery = $refundReader->query([$storeId], $day, []);
$ids = array_column((clone $refundQuery)->field('refund_operation.id')->select()->toArray(), 'id');
$refunds = $refundReader->rows($refundQuery, $ids);
$tk = array_values(array_filter($refunds, static fn(array $item): bool =>
    (string)$item['order_no_snapshot'] === 'TK2610130001'));
refundVisibilityCheck(count($tk) === 1 && (int)$tk[0]['amount_cents'] === -33300,
    'TK cash refund is an effective standalone fact for this store and day');
$categoryId = (int)$tk[0]['current_category_id'];
refundVisibilityCheck($categoryId > 0, 'TK refund resolves its original card component to the current product category');

$metric = RegisteredMetricReadServices::withCurrentReportProductCategory();
$positive = $metric->categoryReportBuckets('cash_performance', '0', [$storeId], $day);
$linkedRefund = $metric->categoryReportBucketsWithoutStandaloneRefunds('0', [$storeId], $day, [], []);
$positiveCents = array_sum(array_column($positive, 'metric_value'));
$linkedRefundCents = array_sum(array_column($linkedRefund, 'metric_value'));
$manualCents = array_sum(array_map(static fn(array $item): int =>
    in_array((string)$item['source_document_type'], ['manual_refund', 'imported_card_refund'], true)
        ? (int)$item['amount_cents'] : 0, $refunds));
$expected = $positiveCents - $linkedRefundCents + $manualCents;
refundVisibilityCheck((int)round((float)$row['item_analysis_cash_today'] * 100) === $expected,
    'store cash today equals registered receipts minus linked and standalone refunds once');
$cumulativeRange = ['start' => StoreUnifiedReportServices::COVERAGE_START, 'end' => $day['end']];
$cumulativeCash = $metric->categoryReportBuckets('cash_performance', '0', [$storeId], $cumulativeRange);
$cumulativeLinkedRefund = $metric->categoryReportBucketsWithoutStandaloneRefunds('0', [$storeId], $cumulativeRange, [], []);
$cumulativeQuery = $refundReader->query([$storeId], $cumulativeRange, []);
$cumulativeIds = array_column((clone $cumulativeQuery)->field('refund_operation.id')->select()->toArray(), 'id');
$cumulativeManualCents = 0;
foreach (array_chunk($cumulativeIds, 500) as $batch) {
    foreach ($refundReader->rows($cumulativeQuery, $batch, false) as $item) {
        if (in_array((string)$item['source_document_type'], ['manual_refund', 'imported_card_refund'], true)) {
            $cumulativeManualCents += (int)$item['amount_cents'];
        }
    }
}
$cumulativeExpected = array_sum(array_column($cumulativeCash, 'metric_value'))
    - array_sum(array_column($cumulativeLinkedRefund, 'metric_value')) + $cumulativeManualCents;
refundVisibilityCheck($row['item_analysis_cash_cumulative'] === MetricMoneyFormatter::integerYuan($cumulativeExpected),
    'cumulative cash nets every registered and standalone refund through the cutoff day');
refundVisibilityCheck($report['summary_row']['item_analysis_cash_today'] === $row['item_analysis_cash_today']
    && $report['summary_row']['item_analysis_actual_today'] === $row['item_analysis_actual_today'],
    'full-result summary reconciles to the authorized store row');
refundVisibilityCheck($export['records'] === $report['records']
    && $export['summary_row'] === $report['summary_row'],
    'export uses the same net report query and summary');

$filtered = (new StoreUnifiedReportServices())->query($storeId, $input + ['category_id' => $categoryId]);
$filteredRow = $filtered['records'][0] ?? [];
refundVisibilityCheck((float)($filteredRow['item_analysis_cash_today'] ?? 0) < 0
    && (float)($filteredRow['item_analysis_category_' . $categoryId . '_cash'] ?? 0) < 0,
    'category filter retains and classifies the TK refund on its completion day');
$otherStore = (new StoreUnifiedReportServices())->query(126, $input);
refundVisibilityCheck((int)($otherStore['summary_row']['item_analysis_cash_today'] ?? 0) !== (int)$row['item_analysis_cash_today']
    || $otherStore['records'] === [],
    'a different authorized store does not inherit the TK deduction');

echo "store item refund local integration: PASS\n";
