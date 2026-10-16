<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/后端代码/app/services/report/ProductManagementDashboardServices.php';

use app\services\report\ProductManagementDashboardServices;

$service = new ProductManagementDashboardServices();
$call = static function (string $name, array $args) use ($service) {
    return (new ReflectionMethod($service, $name))->invokeArgs($service, $args);
};
$check = static function (bool $pass, string $message): void {
    if (!$pass) throw new RuntimeException($message);
};

$tree = $call('categoryTree', [[
    ['id' => 1, 'pid' => 0, 'cate_name' => '开启分类', 'statistics_enabled' => 1],
    ['id' => 2, 'pid' => 0, 'cate_name' => '关闭分类', 'statistics_enabled' => 0],
    ['id' => 3, 'pid' => 1, 'cate_name' => '子分类', 'statistics_enabled' => 0],
]]);
$check(array_column($tree['roots'], 'id') === [1], '关闭统计的一级分类不能出现在看板筛选和卡片');
$check(isset($tree['visible_ids'][3]) && !isset($tree['visible_ids'][2]), '开启一级分类的子分类仍归属父分类');

$rows = [
    ['store_id' => 10, 'product_id' => 101, 'product_name' => '同名商品', 'quantity' => 2, 'inventory_cost_cents' => 500],
    ['store_id' => 10, 'product_id' => 102, 'product_name' => '同名商品', 'quantity' => 3, 'inventory_cost_cents' => 700],
    ['store_id' => 11, 'product_id' => 103, 'product_name' => '同名商品', 'quantity' => 4, 'inventory_cost_cents' => 900],
    ['store_id' => 10, 'product_id' => 104, 'product_name' => '同名商品', 'quantity' => 5, 'inventory_cost_cents' => 1100],
];
$map = ['10:101' => 1, '10:102' => 1, '11:103' => 1, '10:104' => 2];
$inventory = $call('inventoryCategoryRows', [$rows, $map, $tree, 0, true]);
$check(count($inventory) === 1, '关闭统计的分类不能生成库存排行卡片');
$check(count($inventory[0]['rows']) === 1, '同分类同品名不同商品ID及批次需合成一行');
$check($inventory[0]['rows'][0]['available_quantity'] === 9.0, '同品名库存数量应合计');
$check($inventory[0]['rows'][0]['inventory_cost_cents'] === 2100, '同品名库存成本应按分合计');
$check($inventory[0]['inventory_cost_cents'] === 2100, '关闭分类的成本不能混入开启分类');
$hiddenCost = $call('inventoryCategoryRows', [$rows, $map, $tree, 0, false]);
$check($hiddenCost[0]['rows'][0]['inventory_cost_cents'] === null, '无成本权限不得暴露合并后的成本');

$check($call('grossMarginRate', [0, 25800]) === 0.0, '零毛利率应为0%，不得使用同比变化公式');
$check($call('grossMarginRate', [7500, 10000]) === 75.0, '毛利率必须为毛利除以收入');
$check($call('grossMarginRate', [null, 10000]) === null, '缺失成本时毛利率未知');
$check($call('grossMarginRate', [0, 0]) === null, '收入为零时毛利率不应除零');
$ranking = $call('ranking', [[
    '零毛利' => ['income' => 25800, 'cost' => 25800, 'complete' => true],
    '缺成本' => ['income' => 10000, 'cost' => 0, 'complete' => false],
]]);
$check($ranking[0]['name'] === '零毛利' && $ranking[0]['gross_margin_rate'] === 0.0, '品项排行应实际使用毛利率公式');
$check($ranking[1]['gross_margin_rate'] === null, '品项排行成本缺失时不得给出毛利率');

$expiryRows = [];
foreach (range(1, 12) as $days) {
    $expiryRows[] = ['days' => $days, 'category_name' => 'A', 'product_name' => 'P', 'batch_no' => (string)$days];
}
$expiryRows[] = ['days' => null, 'category_name' => 'A', 'product_name' => 'P', 'batch_no' => 'unknown'];
$expiryRows[] = ['days' => -2, 'category_name' => 'A', 'product_name' => 'P', 'batch_no' => 'expired'];
$top = $call('topExpiryRows', [array_reverse($expiryRows)]);
$check(count($top) === 10, '临期明细只展示前10批');
$check($top[0]['batch_no'] === 'expired' && $top[9]['batch_no'] === '9', '前10批必须按到期紧急程度排序');

echo "product dashboard round 37 contract: PASS\n";
