<?php

/**
 * 第二轮订单中心性能合同：普通列表必须走有界数据库分页，高级查询必须回退统一执行器。
 * 本测试不连接数据库、不创建订单，也不改变任何实例数据。
 */
$root = dirname(__DIR__, 2);
$backend = $root . '/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\cashier\v3\order\AbstractCashierV3OrderCenterUnifiedQueryProvider;
use app\services\cashier\v3\order\CashierV3AllOrderUnifiedQueryProvider;
use app\services\cashier\v3\order\CashierV3CardOperationUnifiedQueryProvider;
use app\services\cashier\v3\order\CashierV3DebtRecordUnifiedQueryProvider;
use app\services\cashier\v3\order\CashierV3GiftRecordUnifiedQueryProvider;
use app\services\cashier\v3\order\CashierV3RechargeOrderUnifiedQueryProvider;
use app\services\cashier\v3\order\CashierV3RefundRecordUnifiedQueryProvider;
use app\services\cashier\v3\order\CashierV3SalesOrderUnifiedQueryProvider;
use app\services\cashier\v3\order\CashierV3ServiceRecordUnifiedQueryProvider;
use app\services\cashier\v3\order\CashierV3SupplementRecordUnifiedQueryProvider;

function r61Assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** @return object */
function r61Provider(string $class)
{
    return (new ReflectionClass($class))->newInstanceWithoutConstructor();
}

$ordinary = new ReflectionMethod(AbstractCashierV3OrderCenterUnifiedQueryProvider::class, 'isOrdinaryPageQuery');
@$ordinary->setAccessible(true);
$dateRange = [
    'page'=>1,
    'pageSize'=>20,
    'dataScope'=>'normal',
    'topFilters'=>[
        ['field'=>'business_date','operator'=>'gte','value'=>'2026-10-01'],
        ['field'=>'business_date','operator'=>'lte','value'=>'2026-10-31'],
    ],
];

$ordinaryProviders = [
    'sales' => CashierV3SalesOrderUnifiedQueryProvider::class,
    'recharge' => CashierV3RechargeOrderUnifiedQueryProvider::class,
    'refund' => CashierV3RefundRecordUnifiedQueryProvider::class,
    'debt' => CashierV3DebtRecordUnifiedQueryProvider::class,
    'service' => CashierV3ServiceRecordUnifiedQueryProvider::class,
    'supplement' => CashierV3SupplementRecordUnifiedQueryProvider::class,
    'gift' => CashierV3GiftRecordUnifiedQueryProvider::class,
    'card_operation' => CashierV3CardOperationUnifiedQueryProvider::class,
];
foreach ($ordinaryProviders as $type => $providerClass) {
    r61Assert($ordinary->invoke(r61Provider($providerClass), $dateRange, []) === true,
        $type . ' ordinary date query must use bounded paging');
}
r61Assert($ordinary->invoke(r61Provider(CashierV3AllOrderUnifiedQueryProvider::class), array_replace($dateRange, [
    'topFilters'=>array_merge($dateRange['topFilters'], [
        ['field'=>'business_type','operator'=>'eq','value'=>'消费'],
    ]),
]), []) === true, 'all-order type equality must stay on bounded paging');

$advancedCases = [
    ['filters'=>[['field'=>'member_name','operator'=>'contains','value'=>'张']]],
    ['quickFilters'=>[['field'=>'source','operator'=>'eq','value'=>'大众']]],
    ['keywordFilters'=>[['field'=>'sales_order_no','operator'=>'contains','value'=>'XS']]],
    ['groupBy'=>['store']],
    ['summaries'=>[['field'=>'receivable_amount','function'=>'sum']]],
    ['sorts'=>[['field'=>'receivable_amount','direction'=>'desc']]],
    ['export'=>['scope'=>'query','fields'=>['sales_order_no']]],
];
foreach ($advancedCases as $index => $extra) {
    r61Assert($ordinary->invoke(
        r61Provider(CashierV3SalesOrderUnifiedQueryProvider::class),
        array_replace($dateRange, $extra),
        []
    ) === false, 'advanced query case ' . $index . ' must fall back to the unified executor');
}
r61Assert($ordinary->invoke(
    r61Provider(CashierV3SalesOrderUnifiedQueryProvider::class),
    $dateRange,
    [['field_key'=>'custom_margin']]
) === false, 'custom-field query must not bypass the unified executor');

$providerSource = (string)file_get_contents(
    $backend . '/app/services/cashier/v3/order/AbstractCashierV3OrderCenterUnifiedQueryProvider.php'
);
$salesSource = (string)file_get_contents(
    $backend . '/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php'
);
r61Assert(strpos($providerSource, '$direct = $this->directOrdinaryPage(') !== false
    && strpos($providerSource, '$result = $this->run(') > strpos($providerSource, '$direct = $this->directOrdinaryPage('),
    'bounded page must be attempted before the in-memory unified execution');
r61Assert(strpos($salesSource, 'queryAuthoritySalesOrdersOffsetPage') !== false
    && strpos($salesSource, "->page((int)\$criteria['page'], (int)\$criteria['pageSize'])") !== false,
    'sales authority reader must apply database page and pageSize');
r61Assert(strpos($salesSource, '$snapshots = $this->authoritySnapshots($rows);') !== false,
    'sales snapshots must be assembled only after page rows are selected');
r61Assert(strpos($providerSource, 'if ($window > 100) return null;') !== false,
    'mixed all-order fast path must stay bounded');
r61Assert(strpos($providerSource, 'directSalesSourcePage') !== false
    && strpos($providerSource, 'queryHistoricalSalesOrders($windowSource') !== false,
    'deployed legacy sales source must be retained in the bounded merge window');
r61Assert(strpos($providerSource, 'hasHistoricalSalesRows') === false
    && strpos($providerSource, 'hasHistoricalRefundRows') === false,
    'ordinary pages must not fall back to full-memory reads merely because legacy rows exist');

echo "R61_ORDER_CENTER_BOUNDED_PAGINATION=PASS\n";
