<?php
declare(strict_types=1);

/** 使用真实聚合和统一执行器验证数量模式、整数稳定排序、分页前合计及权限裁剪；不连接数据库。 */
$root = dirname(__DIR__, 3);
require $root . '/后端代码/vendor/autoload.php';

use app\services\product\inventory\query\InventoryProductStockSummaryServices;
use app\services\product\inventory\query\InventoryBatchStockUnifiedQueryRegistrar;
use app\services\query\StructuredExpressionEvaluator;
use app\services\query\StructuredExpressionValidator;
use app\services\query\UnifiedQueryExecutionServices;
use app\services\query\UnifiedQueryPageRegistry;

$registry = UnifiedQueryPageRegistry::fromRegistrars([], [new InventoryBatchStockUnifiedQueryRegistrar()]);
$execution = new UnifiedQueryExecutionServices($registry, new StructuredExpressionValidator($registry), new StructuredExpressionEvaluator());
$source = [];
foreach ([[1, 101, '0'], [2, 102, '60'], [3, 102, '70'], [4, 103, '200'], [5, 104, '0.5'], [6, 105, '999']] as $item) {
    $source[] = ['batch_balance_id' => $item[0], 'product_id' => $item[1], 'sku_id' => $item[1], 'quantity_scale' => 1, 'batch_balance_quantity' => $item[2], 'inventory_amount' => '0.00', 'product_name' => '商品' . $item[1]];
}
$rows = (new InventoryProductStockSummaryServices())->summarize($source);
$context = ['tenant_id' => '0', 'operator_id' => 1, 'permissions' => ['inventory.batch.view'], 'query_cutoff_date' => '2026-10-09', 'data_as_of' => 1];
$passed = 0;
$check = static function (string $name, bool $condition) use (&$passed): void {
    if (!$condition) throw new RuntimeException('FAIL ' . $name);
    $passed++;
    echo 'PASS ' . $name . PHP_EOL;
};
$query = static function (array $filters, int $limit = 20) use ($execution, $rows, $context): array {
    return $execution->execute('inventory_batch_stock', $rows, [], [
        'topFilters' => $filters, 'visibleFields' => ['product_name', 'batch_balance_quantity'], 'limit' => $limit,
        'summaries' => [['field' => 'batch_balance_quantity', 'operator' => 'sum']],
    ], $context, static function (array $row): bool { return $row['product_id'] !== 105; });
};
$filter = static function (string $operator, string $value): array { return ['field' => 'available_quantity', 'operator' => $operator, 'value' => $value]; };
$all = $query([$filter('gte', '0')]);
$check('全部包含零库存且排除权限外商品', $all['pagination']['total'] === 4);
$check('默认稳定排序不会将文本复合键送入数字比较', count($all['rows']) === 4);
$zero = $query([$filter('eq', '0')]);
$check('等于0只返回零库存', $zero['pagination']['total'] === 1 && $zero['rows'][0]['batch_balance_quantity'] === '0');
$positive = $query([$filter('gt', '0')]);
$check('大于0严格排除零且包含小数库存', $positive['pagination']['total'] === 3);
$range = $query([$filter('gte', '130'), $filter('lte', '200')]);
$check('自定义范围包含上下边界', $range['pagination']['total'] === 2);
$check('先汇总批次60加70再按商品数量130筛选', $range['rows'][0]['batch_balance_quantity'] === '130');
$check('自定义数量合计来自完整结果', bccomp($range['summaries']['batch_balance_quantity:sum'], '330', 6) === 0);
$page = $query([$filter('gte', '0')], 1);
$check('分页不截断合计且不包含越权999', bccomp($page['summaries']['batch_balance_quantity:sum'], '330.5', 6) === 0 && count($page['rows']) === 1);
$decimal = $query([$filter('gte', '0.25'), $filter('lte', '0.75')]);
$check('自定义小数范围保留数量精度', $decimal['pagination']['total'] === 1 && $decimal['rows'][0]['batch_balance_quantity'] === '0.5');
$empty = $query([$filter('gte', '201'), $filter('lte', '300')]);
$check('无匹配结果返回空列表', $empty['pagination']['total'] === 0);
echo 'STOCK_QUANTITY_PRESETS_CONTRACT_OK checks=' . $passed . PHP_EOL;
