<?php

/**
 * 本地只读回归：跨日期查询含权益服务的门店销售单，避免一条历史快照中断整页。
 * 仅读取当前配置的本地数据库，不创建订单、不改事实、不触发人员/作废命令。
 */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\order\CashierV3SalesOrderQueryServices;
use think\facade\Db;

$app = new \think\App($backend . '/');
$app->initialize();
$stores = Db::name('cashier_v3_entitlement_service_fact')
    ->whereBetween('business_date', ['2026-01-01', '2026-12-31'])
    ->where('service_status', 'completed')
    ->field('tenant_id,store_id')->group('tenant_id,store_id')->select()->toArray();
if ($stores === []) throw new RuntimeException('year_query_fixture_missing');

$reader = new CashierV3SalesOrderQueryServices();
$recordCount = 0;
foreach ($stores as $store) {
    $tenant = (string)$store['tenant_id'];
    $storeId = (int)$store['store_id'];
    $scope = new CashierV3DataScopeContext(71, 701, $storeId, $tenant,
        'organization:test', [$storeId], CashierV3DataScopeContext::MODE_ALL, [],
        true, 'test-super', 'permission-v2',
        ['cashier.v3.order_center', 'cashier.v3.order.service_detail'], []);
    $operator = new CashierV3OperatorScope($storeId, 71, 'organization:test', $tenant);
    $cursor = '';
    for ($page = 1; $page <= 100; $page++) {
        $result = $reader->querySalesOrders([
            'page' => $page, 'pageSize' => 100,
            'dateFrom' => '2026-01-01', 'dateTo' => '2026-12-31',
            'status' => 'normal', 'dataScope' => 'all', 'queryCursor' => $cursor,
        ], $operator, $scope);
        $recordCount += count($result['records']);
        $next = (string)($result['paginationCursor']['next'] ?? '');
        if ($next === '') break;
        if ($next === $cursor || $page === 100) throw new RuntimeException('year_query_pagination_stalled');
        $cursor = $next;
    }
}
if ($recordCount === 0) throw new RuntimeException('year_query_no_records');
echo 'order-center year query: PASS; stores=' . count($stores) . '; records=' . $recordCount . PHP_EOL;
