<?php

declare(strict_types=1);

/**
 * 只读集成验证：选取一笔同时含正式销售行和权益服务行的本地订单，
 * 确认合并列表只扩展展示明细，不改写销售单件数或应收金额。
 */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\order\CashierV3SalesOrderQueryServices;
use think\facade\Db;

$app = new \think\App(rtrim($backend, '/\\') . '/');
$app->initialize();

$fixture = Db::name('cashier_v3_sales_order')->alias('so')
    ->join('cashier_v3_entitlement_service_fact sf', 'sf.tenant_id=so.tenant_id AND sf.checkout_request_id=so.checkout_request_id')
    ->leftJoin('cashier_v3_sales_order_line sl', 'sl.order_id=so.order_id AND sl.order_line_id=sf.source_line_id')
    ->whereNull('sl.id')->where('so.order_status', 'settled')->where('so.order_direction', 'forward')
    ->field('so.tenant_id,so.store_id,so.business_date,so.order_id,so.order_no,so.checkout_request_id,so.sale_amount_cents')
    ->order('so.id', 'desc')->find();
if (!$fixture) throw new RuntimeException('No local mixed sales/service fixture');

$tenantId = (string)$fixture['tenant_id'];
$storeId = (int)$fixture['store_id'];
$date = (string)$fixture['business_date'];
$orderId = (string)$fixture['order_id'];
$purchaseQuantity = (int)Db::name('cashier_v3_sales_order_line')
    ->where('order_id', $orderId)->where('line_status', 'settled')->where('line_direction', 'forward')->sum('quantity');
$purchaseLineIds = array_map('strval', Db::name('cashier_v3_sales_order_line')
    ->where('order_id', $orderId)->where('line_status', 'settled')->where('line_direction', 'forward')
    ->column('order_line_id'));
$entitlementQuery = Db::name('cashier_v3_entitlement_service_fact')
    ->where('tenant_id', $tenantId)->where('checkout_request_id', (string)$fixture['checkout_request_id']);
if ($purchaseLineIds !== []) $entitlementQuery->whereNotIn('source_line_id', $purchaseLineIds);
$entitlementCount = (int)$entitlementQuery->count('id');

$scope = new CashierV3DataScopeContext(
    71, 701, $storeId, $tenantId, 'organization:test', [$storeId],
    CashierV3DataScopeContext::MODE_ALL, [], true, 'test-super', 'permission-v2',
    ['cashier.v3.order_center', 'cashier.v3.order.service_detail'], ['account' => 'sales-service-readonly']
);
$page = (new CashierV3SalesOrderQueryServices())->querySalesOrders([
    'recordType' => 'sales', 'storeIds' => [$storeId], 'dataScope' => 'all', 'status' => '',
    'page' => 1, 'pageSize' => 100, 'dateFrom' => $date, 'dateTo' => $date,
], new CashierV3OperatorScope($storeId, 71, 'organization:test', $tenantId), $scope);

$record = null;
foreach ((array)$page['records'] as $candidate) {
    if ((string)($candidate['orderId'] ?? '') === $orderId) $record = $candidate;
}
if (!is_array($record)) throw new RuntimeException('Mixed order missing from sales list');
$purchaseRows = array_values(array_filter((array)$record['items'], static fn(array $item): bool => ($item['businessTag'] ?? '') === '购买'));
$entitlementRows = array_values(array_filter((array)$record['items'], static fn(array $item): bool => ($item['businessTag'] ?? '') === '权益'));
if ($purchaseRows === [] || count($entitlementRows) !== $entitlementCount) {
    throw new RuntimeException('Purchase/entitlement display rows do not match source facts');
}
if ((int)$record['itemCount'] !== $purchaseQuantity
    || abs((float)$record['receivableAmount'] - ((int)$fixture['sale_amount_cents'] / 100)) > 0.000001) {
    throw new RuntimeException('Linked service rows changed sales economics or purchase quantity');
}
foreach ($entitlementRows as $item) {
    if ((int)($item['serviceFactId'] ?? 0) <= 0
        || ($item['unitPrice'] ?? null) !== null
        || ($item['payableAmount'] ?? null) !== null
        || !array_key_exists('craftsmenListAllocations', $item)) {
        throw new RuntimeException('Entitlement display row contract is incomplete');
    }
}

echo 'sales order linked services mysql: PASS; order=' . $fixture['order_no']
    . '; purchaseRows=' . count($purchaseRows) . '; entitlementRows=' . count($entitlementRows) . "\n";
