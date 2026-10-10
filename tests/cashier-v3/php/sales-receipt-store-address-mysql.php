<?php
declare(strict_types=1);

/** 本地真实详情路径测试；地址夹具仅在事务内覆盖并回滚，不生成业务单据。 */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';
(new \think\App($backend . '/'))->initialize();
use think\facade\Db;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\order\CashierV3SalesOrderQueryServices;
if (getenv('RECEIPT_ADDRESS_LOCAL_TEST') !== '1'
    || config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') throw new RuntimeException('Local test only');
$orders = Db::name('cashier_v3_sales_order')->where('order_status', 'settled')
    ->where('order_direction', 'forward')->order('id', 'desc')->limit(30)->select()->toArray();
$service = new CashierV3SalesOrderQueryServices();
$checks = 0;
function checkAddress(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++; echo 'PASS ' . $message . "\n";
}
$scopeFor = static function (array $order, array $stores): CashierV3DataScopeContext {
    return new CashierV3DataScopeContext(71, 701, (int)$order['store_id'], (string)$order['tenant_id'],
        'organization:test', $stores, CashierV3DataScopeContext::MODE_STORES, [], false,
        '', 'permission-v2', ['cashier.v3.order_center'], ['account' => 'receipt-address-test']);
};
$order = null;
foreach ($orders as $candidate) {
    $operator = new CashierV3OperatorScope((int)$candidate['store_id'], 71, 'organization:test', (string)$candidate['tenant_id']);
    $scope = $scopeFor($candidate, [(int)$candidate['store_id']]);
    $detail = $service->salesOrderDetail(['orderId' => $candidate['order_id']], $operator, $scope);
    if ($detail !== null) { $order = $candidate; break; }
}
if (!$order) throw new RuntimeException('No readable local order fixture');
$storeId = (int)$order['store_id'];
$beforeStore = Db::name('system_store')->where('id', $storeId)->field('address,detailed_address')->find();
$beforeOrder = $order;
Db::startTrans();
try {
    foreach ([
        ['河南省许昌市禹州市', '禹王大道<测试> & 2楼', '河南省许昌市禹州市禹王大道<测试> & 2楼'],
        ['河南省许昌市禹州市', '河南省许昌市禹州市禹王大道', '河南省许昌市禹州市禹王大道'],
        ['', '仅详细地址', '仅详细地址'],
        ['只有地区', '', '只有地区'],
        ['', '', ''],
    ] as $case) {
        Db::name('system_store')->where('id', $storeId)->update(['address' => $case[0], 'detailed_address' => $case[1]]);
        $detail = $service->salesOrderDetail(['orderId' => $order['order_id'], 'storeId' => 999999999], $operator, $scope);
        checkAddress($detail['storeAddress'] === $case[2], 'Address assembly and client store isolation');
        checkAddress($detail['storeName'] === $order['store_name_snapshot'], 'Historical store name retained');
    }
    $denied = $service->salesOrderDetail(['orderId' => $order['order_id']], $operator, $scopeFor($order, [999999999]));
    checkAddress($denied === null, 'Unauthorized order exposes no address');
    checkAddress(Db::name('cashier_v3_sales_order')->where('id', $order['id'])->find() === $beforeOrder, 'Order facts unchanged');
} finally { Db::rollback(); }
checkAddress(Db::name('system_store')->where('id', $storeId)->field('address,detailed_address')->find() === $beforeStore, 'Store fixtures rolled back');
echo 'receipt store address: ' . $checks . " PASS\n";
