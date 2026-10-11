<?php
declare(strict_types=1);

$autoload = __DIR__ . '/../../../后端代码/vendor/autoload.php';
require is_file($autoload) ? $autoload : '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\order\CashierV3OrderCenterRecordQueryServices;
use app\services\cashier\v3\order\CashierV3OrderCenterUnifiedQueryContract;

// A refund operation remains one row even when its saved sale contains several products.
$method = new ReflectionMethod(CashierV3OrderCenterRecordQueryServices::class, 'refundProductColumns');
if (PHP_VERSION_ID < 80100) $method->setAccessible(true);
$cases = [
    ['sales', ['source_type' => 'sales'], [
        ['category_name_snapshot' => '生美', 'item_name_snapshot' => '清洁项目'],
        ['category_name_snapshot' => '私美', 'item_name_snapshot' => '护理产品'],
        ['category_name_snapshot' => '生美', 'item_name_snapshot' => '清洁项目'],
    ], ['category' => '生美、私美', 'name' => '清洁项目、护理产品']],
    ['document', ['source_type' => 'document', 'adjustment_category_name' => '花园',
        'adjustment_target_name' => '单据项目'], [], ['category' => '花园', 'name' => '单据项目']],
    ['v3 card', ['source_type' => 'card', 'card_category_name' => '六维',
        'card_product_name' => '六维卡', 'adjustment_target_name' => '卡内项目'],
        [], ['category' => '六维', 'name' => '六维卡']],
    ['old card', ['source_type' => 'card', 'adjustment_target_name' => '卡内项目'],
        [], ['category' => '', 'name' => '卡内项目']],
    ['catalog', ['source_type' => 'catalog', 'adjustment_target_name' => '无原单商品',
        'request_json' => json_encode(['_productCategoryNameSnapshot' => '私美'], JSON_UNESCAPED_UNICODE)],
        [], ['category' => '私美', 'name' => '无原单商品']],
    ['old catalog', ['source_type' => 'catalog', 'adjustment_target_name' => '历史商品',
        'catalog_kind' => '产品', 'request_json' => '{}'],
        [], ['category' => '', 'name' => '历史商品']],
    ['imported card', ['source_type' => 'imported_card',
        'request_json' => json_encode(['cardName' => '历史旧卡'], JSON_UNESCAPED_UNICODE)],
        [], ['category' => '', 'name' => '历史旧卡']],
    ['missing snapshot', ['source_type' => 'imported_card', 'request_json' => '{}'],
        [], ['category' => '', 'name' => '']],
];
foreach ($cases as [$label, $row, $lines, $expected]) {
    $actual = $method->invoke(null, $row, $lines);
    if ($actual !== $expected) {
        throw new RuntimeException($label . ' product columns mismatch: ' . json_encode($actual, JSON_UNESCAPED_UNICODE));
    }
}

$fields = array_column(CashierV3OrderCenterUnifiedQueryContract::definition('order_center_refund')['fields'], 'key');
$position = array_search('refund_amount', $fields, true);
if ($position === false || array_slice($fields, $position - 2, 3) !==
    ['product_category', 'product_name', 'refund_amount']) {
    throw new RuntimeException('Refund export columns are not immediately before amount.');
}
echo "R42 refund product columns PASS\n";
