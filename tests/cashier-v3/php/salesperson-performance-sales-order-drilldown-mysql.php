<?php

declare(strict_types=1);

/**
 * 只读集成验证：从本地真实销售人业绩事实选取一组人员/门店/日期，确认
 * 销售订单下钻返回且仅返回同一组来源订单。脚本不创建订单、不修改数据。
 */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\order\CashierV3SalesOrderQueryServices;
use app\services\report\StoreReportNormalDataScopeServices;
use think\facade\Db;

$app = new \think\App(rtrim($backend, '/\\') . '/');
$app->initialize();

$fixtureQuery = Db::name('cashier_v3_performance_fact')->alias('pf')
    ->join('cashier_v3_sales_order so', 'so.tenant_id=pf.tenant_id AND so.order_id=pf.order_id')
    ->where('pf.performance_type', 'sales_performance_allocated')
    ->where('pf.status', 'effective')->where('pf.employee_id', '>', 0)
    ->where('pf.amount_cents', '<>', 0)
    ->where('so.order_status', 'settled')->where('so.order_direction', 'forward')->where('so.settled_at', '>', 0)
    ->field('pf.tenant_id,pf.store_id,pf.employee_id,pf.business_date,COUNT(DISTINCT pf.order_id) order_count')
    ->group('pf.tenant_id,pf.store_id,pf.employee_id,pf.business_date')
    ->having('COUNT(DISTINCT pf.order_id) BETWEEN 1 AND 100')
    ->order('pf.business_date', 'desc');
(new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderFacts($fixtureQuery, 'pf.tenant_id', 'pf.order_id');
$fixture = $fixtureQuery->find();
if (!$fixture) throw new RuntimeException('No local salesperson performance fixture');

$tenantId = (string)$fixture['tenant_id'];
$storeId = (int)$fixture['store_id'];
$employeeId = (int)$fixture['employee_id'];
$date = (string)$fixture['business_date'];
$day = (int)substr($date, 8, 2);

$expectedQuery = Db::name('cashier_v3_performance_fact')->alias('pf')
    ->join('cashier_v3_sales_order so', 'so.tenant_id=pf.tenant_id AND so.order_id=pf.order_id')
    ->where('pf.tenant_id', $tenantId)->where('pf.store_id', $storeId)
    ->where('pf.employee_id', $employeeId)->where('pf.business_date', $date)
    ->where('pf.performance_type', 'sales_performance_allocated')->where('pf.status', 'effective')
    ->where('so.order_status', 'settled')->where('so.order_direction', 'forward')->where('so.settled_at', '>', 0);
(new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderFacts($expectedQuery, 'pf.tenant_id', 'pf.order_id');
$expectedOrderIds = array_values(array_unique(array_map('strval', $expectedQuery->column('pf.order_id'))));
sort($expectedOrderIds, SORT_STRING);

$scope = new CashierV3DataScopeContext(
    71, 701, $storeId, $tenantId, 'organization:test', [$storeId],
    CashierV3DataScopeContext::MODE_ALL, [], true, 'test-super', 'permission-v2',
    ['cashier.v3.order_center'], ['account' => 'salesperson-drill-readonly']
);
$page = (new CashierV3SalesOrderQueryServices())->querySalesOrders([
    'recordType'=>'sales','storeIds'=>[$storeId],'dataScope'=>'all','status'=>'',
    'page'=>1,'pageSize'=>100,'dateFrom'=>$date,'dateTo'=>$date,
    'salesPerformanceDrilldown'=>[
        'employeeId'=>$employeeId,'storeId'=>$storeId,
        'from'=>$date,'to'=>$date,'dayOfMonth'=>$day,
    ],
], new CashierV3OperatorScope($storeId, 71, 'organization:test', $tenantId), $scope);
$actualOrderIds = array_values(array_unique(array_map(static function (array $record): string {
    return (string)($record['orderId'] ?? $record['id'] ?? '');
}, (array)$page['records'])));
$actualOrderIds = array_values(array_filter($actualOrderIds, static fn(string $id): bool => $id !== ''));
sort($actualOrderIds, SORT_STRING);

if ($actualOrderIds !== $expectedOrderIds || (int)$page['total'] !== count($expectedOrderIds)) {
    throw new RuntimeException('Sales-order drilldown does not match salesperson performance source orders');
}
echo 'salesperson performance sales-order drilldown mysql: PASS; date=' . $date
    . '; employee=' . $employeeId . '; orders=' . count($actualOrderIds) . "\n";
