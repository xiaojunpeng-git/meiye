<?php

declare(strict_types=1);

/**
 * 第71轮岗位空值契约：正式销售行区分岗位适用性，权益行只开放服务岗位，
 * 旧购物车行缺少权威契约时不得猜测为“未分配”。本测试不连接数据库。
 */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\cashier\v3\order\CashierV3SalesOrderExportRows;
use app\services\cashier\v3\order\CashierV3SalesOrderQueryServices;

$reader = new CashierV3SalesOrderQueryServices();
$formal = new ReflectionMethod($reader, 'mapAuthorityLine');
$service = new ReflectionMethod($reader, 'mapEntitlementServiceLine');
$legacy = new ReflectionMethod($reader, 'mapLine');
$line = ['order_line_id' => 'line-1', 'item_name_snapshot' => '测试明细', 'quantity' => 1,
    'original_amount_cents' => 10000, 'sale_amount_cents' => 10000];

$project = $formal->invoke($reader, $line + ['item_type' => 'project'], []);
$product = $formal->invoke($reader, $line + ['item_type' => 'product'], []);
$entitlement = $service->invoke($reader, ['id' => 1, 'quantity' => 1, 'project_name_snapshot' => '权益项目'], []);
$old = $legacy->invoke($reader, ['id' => 9, 'cart_info' => []]);

$expect = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$expect($project['assignmentAvailability'] === [
    'salespeople' => true, 'craftsmen' => true, 'salesManagers' => true, 'guides' => true,
], 'project_assignment_availability_invalid');
$expect($product['assignmentAvailability']['craftsmen'] === false
    && $product['assignmentAvailability']['salespeople'] === true, 'product_assignment_availability_invalid');
$expect($entitlement['assignmentAvailability'] === [
    'salespeople' => false, 'craftsmen' => true, 'salesManagers' => false, 'guides' => true,
], 'entitlement_assignment_availability_invalid');
$expect(!in_array(true, $old['assignmentAvailability'], true), 'legacy_assignment_was_guessed');

$export = CashierV3SalesOrderExportRows::project(['items' => [$project, $product, $entitlement]], [
    'craftsman' => '', 'salesperson' => '', 'sales_manager' => '', 'guide' => '',
]);
$expect(array_values($export[0]) === ['未分配', '未分配', '未分配', '未分配'], 'project_export_placeholder_invalid');
$expect(array_values($export[1]) === ['—', '未分配', '未分配', '未分配'], 'product_export_placeholder_invalid');
$expect(array_values($export[2]) === ['未分配', '—', '—', '未分配'], 'entitlement_export_placeholder_invalid');

echo "R71 personnel assignment display contract: PASS\n";
