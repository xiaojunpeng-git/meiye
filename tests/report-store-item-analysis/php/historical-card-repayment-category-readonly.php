<?php

declare(strict_types=1);

$backendRoot = getenv('CASHIER_V3_BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backendRoot . '/vendor/autoload.php';

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\query\metric\RegisteredMetricReadServices;
use app\services\query\metric\MetricMoneyFormatter;
use app\services\report\StoreReportCurrentProductCategoryServices;
use app\services\report\StoreUnifiedReportServices;
use think\facade\Db;

(new \think\App($backendRoot . '/'))->initialize();

// This historical-card repayment has one priced project and no V3 sale allocation.
// The test reads facts and report output only; it never repairs production history.
$repayment = Db::name('cashier_v3_debt_repayment')->where('repayment_no', 'BJ26100100009')->find();
if (!is_array($repayment)) {
    fwrite(STDERR, "BLOCKED historical-card repayment fixture unavailable\n");
    exit(2);
}
$debt = Db::name('store_debt')->where('id', (int)$repayment['debt_id'])->find();
$order = is_array($debt) ? Db::name('store_order')->where('id', (int)$debt['order_id'])->find() : null;
$components = is_array($order) ? Db::name('store_order_cart_info')->where('oid', (int)$order['id'])
    ->where('product_type', 6)->select()->toArray() : [];
if (!is_array($order) || count($components) !== 1 || (int)$components[0]['product_id'] <= 0) {
    fwrite(STDERR, "BLOCKED original-card project fixture changed\n");
    exit(2);
}
$storeId = (int)$repayment['store_id'];
$date = (string)$repayment['business_date'];
$category = (new StoreReportCurrentProductCategoryServices())->resolve([
    ['store_id' => $storeId, 'product_id' => (int)$components[0]['product_id']],
]);
$expectedCategory = $category[0] ?? [];
if ((int)($expectedCategory['id'] ?? 0) <= 0 || (string)($expectedCategory['path'] ?? '') === '') {
    fwrite(STDERR, "BLOCKED original project no longer has a current platform category\n");
    exit(2);
}

$reader = RegisteredMetricReadServices::withCurrentReportProductCategory();
$range = ['start' => $date, 'end' => $date];
$rows = $reader->categoryRows('cash_performance', CashierV3ScopeResolver::TENANT_SCOPE_ID, [$storeId], $range);
$matching = array_values(array_filter($rows, static function (array $row) use ($repayment): bool {
    return (string)($row['order_id'] ?? '') === (string)$repayment['repayment_id'];
}));
if (count($matching) !== 1 || (int)$matching[0]['amount_cents'] !== (int)$repayment['repayment_amount_cents']
    || (int)$matching[0]['item_id'] !== (int)$components[0]['product_id']
    || (int)$matching[0]['category_id'] !== (int)$expectedCategory['id']
    || (string)$matching[0]['category_path'] !== (string)$expectedCategory['path']) {
    fwrite(STDERR, "FAIL historical-card cash was not projected to its verified project category\n");
    exit(1);
}
$cashTotal = $reader->summary('cash_performance', CashierV3ScopeResolver::TENANT_SCOPE_ID, [$storeId], $range);
$classifiedTotal = array_sum(array_map(static function (array $row): int {
    return (int)($row['amount_cents'] ?? 0);
}, $rows));
if ($classifiedTotal !== $cashTotal) {
    fwrite(STDERR, "FAIL category projection changed the store cash total\n");
    exit(1);
}
$filtered = $reader->categoryRows('cash_performance', CashierV3ScopeResolver::TENANT_SCOPE_ID,
    [$storeId], $range, [(int)$expectedCategory['id']]);
if (count(array_filter($filtered, static function (array $row) use ($repayment): bool {
    return (string)($row['order_id'] ?? '') === (string)$repayment['repayment_id'];
})) !== 1) {
    fwrite(STDERR, "FAIL category filter lost the classified repayment\n");
    exit(1);
}
$pathFiltered = $reader->categoryRows('cash_performance', CashierV3ScopeResolver::TENANT_SCOPE_ID,
    [$storeId], $range, [], ['category_path' => (string)$expectedCategory['path']]);
if (count(array_filter($pathFiltered, static function (array $row) use ($repayment): bool {
    return (string)($row['order_id'] ?? '') === (string)$repayment['repayment_id'];
})) !== 1) {
    fwrite(STDERR, "FAIL category-path filter lost the classified repayment\n");
    exit(1);
}

// A broken amount reconciliation must not make a plausible-looking category.
$project = new ReflectionMethod(RegisteredMetricReadServices::class, 'projectHistoricalCardRepaymentComponents');
$project->setAccessible(true);
$partial = $matching[0];
$partial['amount_cents'] = (int)$partial['amount_cents'] - 1;
$partial['item_id'] = 0;
$partial['product_type_snapshot'] = 'sales_debt_repayment_unallocated';
$fallback = $project->invoke($reader, [$partial], CashierV3ScopeResolver::TENANT_SCOPE_ID);
if (count($fallback) !== 1 || (int)$fallback[0]['item_id'] !== 0) {
    fwrite(STDERR, "FAIL unreconciled repayment was classified\n");
    exit(1);
}

$report = (new StoreUnifiedReportServices())->query([$storeId], [
    'report' => 'store_item_analysis', 'start_date' => $date, 'end_date' => $date,
]);
$categoryColumn = null;
foreach ($report['column_groups'] ?? [] as $group) {
    if ((string)($group['label'] ?? '') === (string)$expectedCategory['path']) {
        $categoryColumn = (string)($group['column_keys'][0] ?? '');
        break;
    }
}
if ($categoryColumn === null || $categoryColumn === '' || !array_key_exists($categoryColumn, $report['summary_row'] ?? [])) {
    fwrite(STDERR, "FAIL store item report has no current project category column\n");
    exit(1);
}
$categoryCents = 0;
foreach ($reader->categoryReportBuckets('cash_performance', CashierV3ScopeResolver::TENANT_SCOPE_ID,
    [$storeId], $range) as $bucket) {
    if ((int)($bucket['category_id'] ?? 0) === (int)$expectedCategory['id']) {
        $categoryCents += (int)$bucket['metric_value'];
    }
}
if ((string)$report['summary_row'][$categoryColumn] !== MetricMoneyFormatter::integerYuan($categoryCents)
    || (string)($report['metric_version'] ?? '') !== 'store-item-analysis-historical-card-repayment-v4') {
    fwrite(STDERR, "FAIL store item summary does not match the registered category reader\n");
    exit(1);
}
$export = (new StoreUnifiedReportServices())->export([$storeId], [
    'report' => 'store_item_analysis', 'start_date' => $date, 'end_date' => $date,
]);
if ((string)($export['summary_row'][$categoryColumn] ?? '') !== (string)$report['summary_row'][$categoryColumn]) {
    fwrite(STDERR, "FAIL store item export differs from the on-screen category total\n");
    exit(1);
}

echo "PASS historical card repayment keeps its cash total and enters the current project category\n";
