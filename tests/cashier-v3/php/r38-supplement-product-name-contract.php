<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$view = (string)file_get_contents($root . '/前端代码/cashier-v3/src/views/OrderCenterView.vue');
$contract = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3OrderCenterUnifiedQueryContract.php');
$records = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php');

$checks = [
    '前端列顺序' => strpos($view, "field('debt_summary', '欠款摘要'), field('product_category', '商品分类'), field('product_name', '商品名称')") !== false,
    '旧列设置兼容' => strpos($view, "activeTabKey.value === 'supplement'") !== false
        && strpos($view, "item.key === 'debt_summary'") !== false,
    '列表导出合同一致' => strpos($contract, "['debt_summary', '欠款摘要'], ['product_category', '商品分类'], ['product_name', '商品名称']") !== false,
    '销售与历史补交共用欠款快照' => substr_count($records, "'productName' => (string)(\$productNames") === 2
        && strpos($records, 'summariesForDebts($debtIds)') !== false,
    '商品分类读取平台当前档案' => strpos($records, 'categorySummariesForDebts($debtIds)') !== false
        && substr_count($records, "'productCategory' => (string)(\$productCategories") === 2,
    '充值补交使用固定业务名' => strpos($records, "'productName' => '储值充值'") !== false,
];

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$passed) $failed++;
}

echo $failed === 0 ? "R38_SUPPLEMENT_PRODUCT_NAME=PASS\n" : "R38_SUPPLEMENT_PRODUCT_NAME=FAIL\n";
exit($failed === 0 ? 0 : 1);
