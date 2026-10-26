<?php

/** 销售导出逐列核对：一张多商品订单在 Excel 中按表格明细展开，整单金额不重复。 */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\cashier\v3\order\CashierV3OrderCenterQueryExecutionServices;
use app\services\cashier\v3\order\CashierV3OrderCenterUnifiedQueryContract;

function parityCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$declared = CashierV3OrderCenterUnifiedQueryContract::definition('order_center_sales')['fields'];
$fields = array_column(array_values(array_filter($declared, static function (array $field): bool {
    return empty($field['hidden']);
})), 'key');
parityCheck(count($fields) === 28, '消费订单导出字段合同已变化，请逐列补测');
$selected = array_fill_keys($fields, null);
$firstValues = [
    'sales_order_no' => 'XS-TEST-1', 'business_date' => '2026-10-11', 'member_name' => '测试会员',
    'phone' => '13000000000', 'store' => '测试门店', 'item_summary' => '甲商品、乙项目',
    'item_name' => '旧聚合商品', 'unit_price' => '旧聚合单价', 'quantity' => '旧聚合数量',
    'craftsman' => '旧聚合手艺人', 'sales_manager' => '旧聚合经理', 'guide' => '旧聚合导购',
    'line_amount' => '旧聚合金额', 'occurred_at' => '2026-10-11 10:20:30',
    'item_count' => 2, 'receivable_amount' => 120.33, 'discount_amount' => 8.12,
    'debt_amount' => 10.05, 'coupon_discount_amount' => 2.11,
    'actual_received_amount' => 110.28, 'payment_method' => '旧收款摘要',
    'salesperson' => '旧聚合销售人', 'cashier' => '测试收银员', 'source' => '测试来源',
    'payment_status' => '已支付', 'order_status' => '正常', 'supplement' => '正常办理',
    'payment_completed_at' => '2026-10-11 10:21:30',
];
$selected = array_replace($selected, $firstValues);
$source = array_replace($selected, [
    'record_id' => 'sales:1',
    'paymentDetails' => [['methodName' => '现金', 'amount' => 110.28]],
    'items' => [
        ['businessTag' => '购买', 'itemType' => '商品', 'name' => '甲商品', 'isPresale' => true,
            'unitPrice' => 80.12, 'quantity' => 1, 'amountBeforeCoupon' => 80.12,
            'craftsmenListAllocations' => [['name' => '张手艺', 'isPointCustomer' => true,
                'amount' => 12.5, 'laborFeeAmount' => 3.25, 'projectCount' => 1]],
            'salespeople' => [['name' => '李销售', 'salesPerformanceAmount' => 80.12, 'roleSnapshot' => 'sale:presale']],
            'salesManagers' => [['name' => '王经理']], 'guides' => [['name' => '赵导购', 'guideRoundNo' => 2]]],
        ['businessTag' => '权益', 'itemType' => '项目', 'name' => '乙项目',
            'unitPrice' => 40.21, 'quantity' => 2, 'entitlementAmount' => 40.21,
            'craftsmenListAllocations' => [['name' => '陈手艺', 'isPointCustomer' => false,
                'amount' => 4.44, 'laborFeeAmount' => 1.11, 'projectCount' => 2]],
            'salespeople' => [['name' => '孙销售', 'salesPerformanceAmount' => 40.21]],
            'salesManagers' => [], 'guides' => [['name' => '钱导购', 'guideRoundNo' => 0]]],
    ],
]);
$executor = (new ReflectionClass(CashierV3OrderCenterQueryExecutionServices::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod(CashierV3OrderCenterQueryExecutionServices::class, 'exportRows');
$method->setAccessible(true);
$fieldIndex = array_fill_keys($fields, ['operations' => ['export']]);
$rows = $method->invoke($executor, [$source], [$source], ['scope' => 'query', 'fields' => $fields], $fieldIndex);
parityCheck(count($rows) === 2, '多商品销售单没有逐项展开');
$expectedFirst = array_replace($firstValues, [
    'item_name' => '购买 产品 甲商品 预售', 'unit_price' => 80.12, 'quantity' => 1,
    'craftsman' => '张手艺（点、13、3、1）', 'sales_manager' => '王经理', 'guide' => '赵导购（第2轮）',
    'line_amount' => 80.12, 'payment_method' => '现金 ¥110.28', 'salesperson' => '李销售（售前、80）',
]);
$expectedSecond = array_replace($firstValues, [
    'item_summary' => null, 'item_name' => '权益 项目 乙项目', 'unit_price' => 40.21, 'quantity' => 2,
    'craftsman' => '陈手艺（轮、4、1、2）', 'sales_manager' => '—', 'guide' => '钱导购（无）',
    'line_amount' => 40.21, 'item_count' => null, 'receivable_amount' => null, 'discount_amount' => null,
    'debt_amount' => null, 'coupon_discount_amount' => null, 'actual_received_amount' => null,
    'payment_method' => null, 'salesperson' => '孙销售（40）',
]);
foreach ([$expectedFirst, $expectedSecond] as $index => $expected) {
    parityCheck(array_keys($rows[$index]) === $fields, '导出列顺序与字段合同不同');
    foreach ($fields as $field) {
        parityCheck($rows[$index][$field] === $expected[$field], '第 ' . ($index + 1) . ' 行字段 ' . $field . ' 与页面口径不同');
    }
}

// 当前页和全查询先按订单筛选，再展开其明细；非销售记录仍一条一行。
$another = array_replace($source, ['record_id' => 'sales:2', 'sales_order_no' => 'XS-TEST-2']);
$page = $method->invoke($executor, [$source, $another], [$another], ['scope' => 'page', 'fields' => $fields], $fieldIndex);
parityCheck(count($page) === 2 && $page[0]['sales_order_no'] === 'XS-TEST-2', '当前页导出订单范围错误');
$summaryOnly = array_replace($source, ['record_id' => 'sales:3', 'items' => [], 'member_name' => '']);
$fallback = $method->invoke($executor, [$summaryOnly], [$summaryOnly], ['scope' => 'query', 'fields' => $fields], $fieldIndex);
parityCheck(count($fallback) === 1 && $fallback[0]['item_name'] === '甲商品、乙项目'
    && $fallback[0]['quantity'] === 2 && $fallback[0]['member_name'] === '游客',
    '历史无明细订单或游客被导出遗漏');
$recharge = ['record_id' => 'recharge:1', 'salesperson' => ['甲', '乙']];
$other = $method->invoke($executor, [$recharge], [$recharge], ['scope' => 'query', 'fields' => ['salesperson']], $fieldIndex);
parityCheck($other === [['salesperson' => '甲、乙']], '其他页签导出粒度被改变');

echo 'PASS 28 columns × 2 detail rows; page scope and other tabs unchanged' . PHP_EOL;
