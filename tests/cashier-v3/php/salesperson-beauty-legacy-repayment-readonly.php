<?php

declare(strict_types=1);

$backendRoot = getenv('CASHIER_V3_BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backendRoot . '/vendor/autoload.php';

use app\services\report\StoreReportCurrentProductCategoryServices;
use app\services\report\StoreUnifiedReportPhaseTwoServices;
use think\facade\Db;

(new \think\App($backendRoot . '/'))->initialize();

// This imported-card fixture has a real repayment and a priced project line, but no V3 sale fact.
// The test is read-only: neither the historical order nor its immutable payment facts are repaired here.
$repayment = Db::name('cashier_v3_debt_repayment')->where('repayment_no', 'BJ26100100009')->find();
if (!is_array($repayment)) {
    fwrite(STDERR, "BLOCKED historical card repayment fixture unavailable\n");
    exit(2);
}

$order = Db::name('store_order')->where('order_id', (string)$repayment['sales_order_id'])->find();
$lines = is_array($order) ? Db::name('store_order_cart_info')->where('oid', (int)$order['id'])
    ->where('product_type', 6)->select()->toArray() : [];
if (!is_array($order) || count($lines) !== 1 || (string)$order['channel_type'] !== 'migration'
    || Db::name('cashier_v3_sale_fact')->where('order_id', (string)$repayment['sales_order_id'])->count() !== 0) {
    fwrite(STDERR, "BLOCKED historical card fixture no longer has the expected original-order shape\n");
    exit(2);
}

$paymentFacts = Db::name('cashier_v3_payment_fact')->where('order_id', (string)$repayment['repayment_id'])
    ->where('status', 'effective')->where('fact_direction', 'forward')->column('fact_id');
if ($paymentFacts === [] || Db::name('cashier_v3_payment_sale_allocation_fact')
    ->whereIn('payment_fact_id', $paymentFacts)->where('status', 'effective')->count() !== 0) {
    fwrite(STDERR, "BLOCKED historical card fixture now has normal payment allocations\n");
    exit(2);
}

$category = (new StoreReportCurrentProductCategoryServices())->resolve([
    ['store_id' => (int)$repayment['store_id'], 'product_id' => (int)$lines[0]['product_id']],
]);
$parts = explode('/', (string)($category[0]['path'] ?? ''));
if (count($parts) !== 2 || $parts[0] !== '生美') {
    fwrite(STDERR, "BLOCKED historical card project is not in a current beauty category\n");
    exit(2);
}

$date = (string)$repayment['business_date'];
$result = (new StoreUnifiedReportPhaseTwoServices())->query('salesperson_large_order_statistics',
    [(int)$repayment['store_id']], ['start' => $date, 'end' => $date], ['page' => 1, 'limit' => 500]);
$salesRows = array_values(array_filter((array)($result['records'] ?? []), static fn(array $row): bool =>
    (string)($row['order_id'] ?? '') === (string)$repayment['repayment_id']
    && (string)($row['role_snapshot'] ?? '') === 'salesperson'));
$expected = (int)Db::name('cashier_v3_performance_fact')->where('order_id', (string)$repayment['repayment_id'])
    ->where('performance_type', 'sales_performance_allocated')->where('status', 'effective')
    ->where('fact_direction', 'forward')->sum('amount_cents');
if (count($salesRows) !== 1 || (int)$salesRows[0]['amount_cents'] !== $expected
    || (string)$salesRows[0]['beauty_category'] !== $parts[1]
    || (string)$salesRows[0]['remark'] !== '补交单号：' . (string)$repayment['repayment_no']
    || (string)($result['metric_version'] ?? '') !== 'beauty-large-order-repayment-v6') {
    fwrite(STDERR, "FAIL historical card repayment report amount/category mismatch\n");
    exit(1);
}

echo "PASS historical card repayment enters beauty large-order report without duplicate cash\n";
