<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/后端代码/app/services/report/ProductManagementDashboardServices.php';

use app\services\report\ProductManagementDashboardServices;

$service = new ProductManagementDashboardServices();
$call = static fn(string $name, array $args) => (new ReflectionMethod($service, $name))->invokeArgs($service, $args);
$check = static function (bool $pass, string $message): void {
    if (!$pass) throw new RuntimeException($message);
};
$categories = $call('categoryTree', [[
    ['id' => 1, 'pid' => 0, 'cate_name' => '有数据', 'statistics_enabled' => 1],
    ['id' => 2, 'pid' => 0, 'cate_name' => '无数据', 'statistics_enabled' => 1],
]]);
$check(count($categories['roots']) === 2, '无数据分类仍需保留在筛选项');

$profit = $call('profitCategoryCards', [$categories, [
    '1' => ['income' => 0, 'cost' => 0, 'projects' => [], 'products' => ['测试商品' => ['income' => 0, 'cost' => 0, 'complete' => true]]],
]]);
$check(count($profit) === 1 && $profit[0]['category_id'] === 1, '无事实分类不显示毛利卡，零金额但有事实仍显示');

$inventory = $call('inventoryCategoryRows', [[
    ['store_id' => 10, 'product_id' => 100, 'product_name' => '测试商品', 'quantity' => 1.0, 'inventory_cost_cents' => 0],
], ['10:100' => 1], $categories, 0, true]);
$check(count($inventory) === 1 && $inventory[0]['inventory_cost_cents'] === 0, '有库存数量但成本为零的分类应显示');

// Every bucket is independently ranked; changing the selected bucket cannot
// narrow the aggregate totals or leak batch cost without cost permission.
$today = new DateTimeImmutable('today');
$batches = [];
foreach (range(1, 12) as $index) {
    $batches[] = [
        'store_id' => 10, 'product_id' => 100, 'product_name' => '过期商品',
        'batch_no' => 'OLD-' . $index, 'expire_date' => $today->modify("-{$index} days")->format('Y-m-d'),
        'quantity' => 2.0, 'inventory_cost_cents' => 100,
    ];
    $batches[] = [
        'store_id' => 10, 'product_id' => 100, 'product_name' => '安全商品',
        'batch_no' => 'SAFE-' . $index, 'expire_date' => $today->modify('+' . (90 + $index) . ' days')->format('Y-m-d'),
        'quantity' => 3.0, 'inventory_cost_cents' => 200,
    ];
}
$batches[] = [
    'store_id' => 10, 'product_id' => 100, 'product_name' => '未知商品',
    'batch_no' => 'UNKNOWN', 'expire_date' => null, 'quantity' => 1.0, 'inventory_cost_cents' => 50,
];
$expiry = $call('expiry', [$batches, ['10:100' => 1], ['cost_visible' => false], $categories, 0]);
$totals = array_column($expiry['buckets'], null, 'key');
$check($expiry['default_bucket'] === 'OVERDUE', '排行默认已过期');
$check($totals['OVERDUE']['count'] === 12 && $totals['OVERDUE']['quantity'] === 24.0, '过期汇总必须包含全部批次');
$check($totals['SAFE_OVER_90']['count'] === 12 && $totals['SAFE_OVER_90']['quantity'] === 36.0, '90天以上汇总必须包含全部批次');
$check(count($expiry['rankings']) === 6 && count($expiry['rankings']['OVERDUE']) === 10 && count($expiry['rankings']['SAFE_OVER_90']) === 10, '六档各自最多展示前10名');
$check($expiry['rankings']['OVERDUE'][0]['batch_no'] === 'OLD-12', '过期排行最早到期在前');
$check($expiry['rankings']['SAFE_OVER_90'][0]['batch_no'] === 'SAFE-1', '90天以上排行最近到期在前');
$check($expiry['rankings']['UNKNOWN'][0]['inventory_cost_cents'] === null, '无成本权限不得显示批次成本');
$filtered = $call('expiry', [$batches, ['10:100' => 1], ['cost_visible' => true], $categories, 2]);
$check(array_sum(array_column($filtered['buckets'], 'count')) === 0, '无数据分类筛选后不能显示其他分类批次');

echo "product dashboard round 38 contract: PASS\n";
