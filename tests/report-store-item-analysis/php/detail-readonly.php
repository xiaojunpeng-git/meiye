<?php

declare(strict_types=1);

/** Read-only integration: every declared drilldown must reconcile with its clicked cell. */
$backend = getenv('CASHIER_V3_BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\report\StoreUnifiedReportDetailXlsxExporter;
use app\services\report\StoreUnifiedReportServices;

(new \think\App($backend . '/'))->initialize();
$service = new StoreUnifiedReportServices();
$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    ++$checks;
};

foreach ([[165, '2026-10-17'], [165, '2026-10-16'], [126, '2026-10-08']] as [$store, $date]) {
    $filters = ['start_date' => $date, 'end_date' => $date];
    $parent = $service->query([$store], $filters + ['report' => 'store_item_analysis']);
    if (empty($parent['records'])) {
        fwrite(STDERR, "BLOCKED item-analysis local fixture unavailable: {$store} {$date}\n");
        exit(2);
    }
    foreach ($parent['columns'] as $column) {
        $key = (string)($column['key'] ?? '');
        if (strpos($key, 'item_analysis_') !== 0) continue;
        $detailInput = $filters + ['report' => 'store_item_analysis_detail', 'metric_code' => $key];
        $summaryDetail = $service->query([$store], $detailInput);
        $assert((string)$summaryDetail['summary_row']['receipt_amount'] === (string)$parent['summary_row'][$key],
            "summary/detail mismatch {$store} {$date} {$key}");
        foreach ($parent['records'] as $row) {
            $rowDetail = $service->query([$store], $detailInput + [
                'detail_store_key' => (string)$row['item_analysis_store_key'],
            ]);
            $assert((string)$rowDetail['summary_row']['receipt_amount'] === (string)$row[$key],
                "store/detail mismatch {$store} {$date} {$key}");
        }
    }
}

$refundDetail = $service->query([165], ['report' => 'store_item_analysis_detail',
    'start_date' => '2026-10-16', 'end_date' => '2026-10-16',
    'metric_code' => 'item_analysis_cash_today']);
$assert(count(array_filter($refundDetail['records'], static function (array $row): bool {
    return $row['fact_type'] === '独立退款' && (int)$row['amount_cents'] < 0;
})) > 0, 'refund facts must appear as negative contributions');

// Parent result filtering must narrow the popup before pagination or export.
$date = ['start_date' => '2026-10-17', 'end_date' => '2026-10-17'];
$rowFilter = json_encode([['key' => 'store_name', 'op' => 'contains', 'value' => '东台']], JSON_UNESCAPED_UNICODE);
$parent = $service->query([16, 165], $date + ['report' => 'store_item_analysis', 'row_filters' => $rowFilter]);
$detailInput = $date + ['report' => 'store_item_analysis_detail', 'metric_code' => 'item_analysis_cash_today',
    'row_filters' => $rowFilter];
$detail = $service->query([16, 165], $detailInput);
$assert(count($parent['records']) === 1 && (string)$detail['summary_row']['receipt_amount']
    === (string)$parent['summary_row']['item_analysis_cash_today'], 'filtered store total differs');
try {
    $service->query([16], $detailInput + ['detail_store_key' => (string)$parent['records'][0]['item_analysis_store_key']]);
    throw new RuntimeException('forged store key was accepted');
} catch (InvalidArgumentException $expected) {
    ++$checks;
}
try {
    $service->query([165], $date + ['report' => 'store_item_analysis_detail', 'metric_code' => 'item_analysis_fake_today']);
    throw new RuntimeException('unknown metric was accepted');
} catch (InvalidArgumentException $expected) {
    ++$checks;
}

// The popup page is 20 rows while the workbook uses the full authorized fact set.
$consumption = $date + ['report' => 'store_item_analysis_detail',
    'metric_code' => 'item_analysis_consume_cumulative', 'page' => 2, 'limit' => 20];
$page = $service->query([165], $consumption);
$export = $service->export([165], $consumption);
$assert($page['total'] > 20 && count($page['records']) === 20
    && count($export['records']) === $page['total'], 'popup pagination truncated XLSX rows');
$xlsx = (new StoreUnifiedReportDetailXlsxExporter())->render('store_item_analysis_detail', $export);
$assert(substr($xlsx, 0, 2) === 'PK', 'detail XLSX is not a workbook');

echo "store item analysis detail readonly: PASS checks={$checks}\n";
