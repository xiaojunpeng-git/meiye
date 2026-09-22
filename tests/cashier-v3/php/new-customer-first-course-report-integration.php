<?php

declare(strict_types=1);

use app\services\report\StoreUnifiedReportPhaseTwoServices;
use think\facade\Db;

// Run inside the local PHP container. This test only reads lifecycle, order,
// payment, and report facts; it never creates customers or changes balances.
require '/var/www/html/vendor/autoload.php';
$app = new \think\App('/var/www/html/');
$app->initialize();

$year = (int)date('Y');
$range = ['start' => sprintf('%04d-01-01', $year), 'end' => date('Y-m-d')];
$stores = array_map('intval', Db::name('cashier_v3_customer_lifecycle_fact')
    ->where('event_type', 'first_course_completed')
    ->whereBetween('business_date', [$range['start'], $range['end']])
    ->group('store_id')->column('store_id'));
if (!$stores) {
    echo "SKIP no local first-course completion facts\n";
    exit(0);
}

$service = new StoreUnifiedReportPhaseTwoServices();
$input = ['_internal_all' => true];
$detail = $service->query('new_customer_analysis', $stores, $range, $input);
$summary = $service->query('new_customer_analysis_summary', $stores, $range, $input);
$orders = [];
foreach ($detail['records'] as $row) {
    $order = (string)($row['order_id'] ?? '');
    if ($order === '') throw new RuntimeException('new customer detail has no first-card order');
    $orders[$order] = true;
    if ((string)($row['business_date'] ?? '') < $range['start'] || (string)($row['business_date'] ?? '') > $range['end']) {
        throw new RuntimeException('first-course completion is outside the selected dates');
    }
}
$count = 0;
foreach ($summary['summary_row'] ?? [] as $key => $value) {
    if (preg_match('/^month_\d+_count$/', (string)$key)) $count += (int)$value;
}
if (count($orders) !== $count) {
    throw new RuntimeException('new-customer count must equal distinct qualifying first-card orders');
}

// Each payment is counted once in both reports, including split payments and
// the debt settlement tied back to the original first-course order.
$toCents = static function ($amount): int {
    $number = (string)$amount;
    $negative = strpos($number, '-') === 0;
    $parts = explode('.', ltrim($number, '-'), 2);
    $cents = ((int)$parts[0]) * 100 + (int)str_pad(substr($parts[1] ?? '', 0, 2), 2, '0');
    return $negative ? -$cents : $cents;
};
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
$expected = Db::name('cashier_v3_customer_lifecycle_fact')
    ->whereIn('store_id', $stores)->where('event_type', 'first_course_completed')
    ->where('status', 'effective')->whereBetween('business_date', [$range['start'], $range['end']])
    ->column('related_order_id');
$expected = array_values(array_diff(array_unique($expected), $voids));
sort($expected);
$actual = array_keys($orders);
sort($actual);
if ($actual !== $expected) {
    throw new RuntimeException('detail orders differ from valid first-course completion events');
}
foreach ([$detail, $summary] as $report) {
    foreach ($report['columns'] as $column) {
        if (strpos((string)($column['source_explanation'] ?? ''), '口径') !== false) {
            throw new RuntimeException('new-customer column explanation contains unexplained jargon');
        }
    }
}
echo 'PASS first-course new-customer reports: ', count($orders), " orders, ", $count, " people\n";
