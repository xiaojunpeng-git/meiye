<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/后端代码/app/services/report/ProductManagementDashboardServices.php';

use app\services\report\ProductManagementDashboardServices;

/** Check that the six card boundaries, full totals and top-ten drilldown identities stay aligned. */
function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$service = new ProductManagementDashboardServices();
$method = new ReflectionMethod($service, 'expiry');
$today = new DateTimeImmutable('today');
$date = static fn(int $days): string => $today->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
$rows = [];
$samples = [-1 => 'OVERDUE', 0 => 'WITHIN_30', 30 => 'WITHIN_30', 31 => 'DAYS_31_60', 60 => 'DAYS_31_60', 61 => 'DAYS_61_90', 90 => 'DAYS_61_90', 91 => 'SAFE_OVER_90'];
foreach ($samples as $days => $bucket) {
    $rows[] = ['batch_id' => count($rows) + 1, 'store_id' => 1, 'product_id' => 10, 'product_name' => '边界批次',
        'batch_no' => 'B' . $days, 'expire_date' => $date($days), 'quantity' => 2, 'inventory_cost_cents' => 100];
}
for ($index = 0; $index < 12; $index++) {
    $rows[] = ['batch_id' => count($rows) + 1, 'store_id' => 1, 'product_id' => 10, 'product_name' => '已过期批次',
        'batch_no' => 'E' . $index, 'expire_date' => $date(-2), 'quantity' => 3, 'inventory_cost_cents' => 100];
}
$rows[] = ['batch_id' => count($rows) + 1, 'store_id' => 1, 'product_id' => 10, 'product_name' => '无到期日批次',
    'batch_no' => 'U1', 'expire_date' => null, 'quantity' => 4, 'inventory_cost_cents' => 100];
$rows[] = ['batch_id' => count($rows) + 1, 'store_id' => 2, 'product_id' => 20, 'product_name' => '其他分类',
    'batch_no' => 'C2', 'expire_date' => $date(-1), 'quantity' => 7, 'inventory_cost_cents' => 100];
$map = ['1:10' => 1, '2:20' => 2];
$categories = ['roots' => [['id' => 1, 'name' => '分类一'], ['id' => 2, 'name' => '分类二']], 'children' => [1 => [], 2 => []]];
$scope = ['cost_visible' => false];
$result = $method->invoke($service, $rows, $map, $scope, $categories, 0);
$buckets = array_column($result['buckets'], null, 'key');
check($buckets['OVERDUE']['count'] === 14 && (float)$buckets['OVERDUE']['quantity'] === 45.0, 'expired full count/quantity');
check($buckets['WITHIN_30']['count'] === 2 && $buckets['DAYS_31_60']['count'] === 2 && $buckets['DAYS_61_90']['count'] === 2, 'boundary classification');
check($buckets['SAFE_OVER_90']['count'] === 1 && (float)$buckets['UNKNOWN']['quantity'] === 4.0, 'safe and unknown classification');
check(count($result['rankings']['OVERDUE']) === 10, 'top-ten remains bounded');
foreach ($result['buckets'] as $bucket) {
    check($bucket['drilldown']['detail'] === 'expiry_bucket' && $bucket['drilldown']['bucket_key'] === $bucket['key'], 'exact server-owned bucket key');
}
$filtered = $method->invoke($service, $rows, $map, $scope, $categories, 1);
$filteredBuckets = array_column($filtered['buckets'], null, 'key');
check($filteredBuckets['OVERDUE']['count'] === 13 && (float)$filteredBuckets['OVERDUE']['quantity'] === 38.0, 'category scope');
$empty = $method->invoke($service, [], [], $scope, $categories, 0);
foreach ($empty['buckets'] as $bucket) {
    check($bucket['count'] === 0 && (float)$bucket['quantity'] === 0.0 && $empty['rankings'][$bucket['key']] === [], 'empty bucket');
}
echo "PRODUCT_DASHBOARD_EXPIRY_BUCKET_CONTRACT=PASS\n";
