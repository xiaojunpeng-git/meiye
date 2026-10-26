<?php

declare(strict_types=1);

/** Read-only reconciliation of every local settled sale with a balance debit. */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\order\CashierV3SalesOrderQueryServices;
use think\facade\Db;

$app = new \think\App(rtrim($backend, '/\\') . '/');
$app->initialize();

$fixtures = Db::name('cashier_v3_sales_order')->alias('s')
    ->join('cashier_v3_checkout_request r', 'r.request_id=s.checkout_request_id')
    ->join('cashier_v3_payment_collection_batch b', 'b.sales_order_id=s.order_id')
    ->where('s.order_status', 'settled')->where('s.order_direction', 'forward')
    ->where('r.balance_deduction_amount_cents', '>', 0)
    ->where('b.batch_status', 'settled')->where('b.batch_direction', 'forward')
    ->field('s.id AS sales_order_row_id,s.tenant_id,s.store_id,s.business_date,s.order_id,s.order_no,s.sale_amount_cents,'
        . 'r.creation_idempotency_key,'
        . 'r.balance_deduction_amount_cents,r.debt_amount_cents,b.cash_performance_amount_cents')
    ->select()->toArray();
if ($fixtures === []) throw new RuntimeException('No local settled balance-payment fixture');

$query = new CashierV3SalesOrderQueryServices();
foreach ($fixtures as $fixture) {
    // A succeeded request is not enough: the durable member ledger must show
    // the same debit against this sales order before the display is trusted.
    $ledgerKey = 'cv3-balance-' . hash('sha256',
        (string)$fixture['tenant_id'] . '|' . (string)$fixture['creation_idempotency_key']);
    $ledger = Db::name('user_money')->where('idempotency_key', $ledgerKey)
        ->field('link_id,number,status')->find();
    if (!$ledger || (int)$ledger['link_id'] !== (int)$fixture['sales_order_row_id']
        || (int)$ledger['status'] !== 1
        || (int)round((float)$ledger['number'] * 100) !== (int)$fixture['balance_deduction_amount_cents']) {
        throw new RuntimeException('Balance debit ledger mismatch: ' . $fixture['order_no']);
    }
    $storeId = (int)$fixture['store_id'];
    $tenantId = (string)$fixture['tenant_id'];
    $scope = new CashierV3DataScopeContext(
        71, 701, $storeId, $tenantId, 'organization:test', [$storeId],
        CashierV3DataScopeContext::MODE_ALL, [], true, 'test-super', 'permission-v2',
        ['cashier.v3.order_center'], ['account' => 'sales-balance-readonly']
    );
    $operator = new CashierV3OperatorScope($storeId, 71, 'organization:test', $tenantId);
    $page = $query->querySalesOrders([
        'recordType' => 'sales', 'storeIds' => [$storeId], 'dataScope' => 'all',
        'keyword' => (string)$fixture['order_no'], 'status' => '', 'page' => 1,
        'pageSize' => 20, 'dateFrom' => (string)$fixture['business_date'],
        'dateTo' => (string)$fixture['business_date'],
    ], $operator, $scope);
    $matches = array_values(array_filter((array)$page['records'], static function (array $record) use ($fixture): bool {
        return (string)($record['orderId'] ?? '') === (string)$fixture['order_id'];
    }));
    if (count($matches) !== 1) throw new RuntimeException('Balance sale missing from list: ' . $fixture['order_no']);
    $detail = $query->salesOrderDetail(['orderId' => (string)$fixture['order_id'], 'storeIds' => [$storeId]], $operator, $scope);
    if (!is_array($detail)) throw new RuntimeException('Balance sale missing from detail: ' . $fixture['order_no']);
    foreach ([$matches[0], $detail] as $record) {
        $paymentAmounts = [];
        foreach ((array)($record['paymentDetails'] ?? []) as $payment) {
            $paymentAmounts[(string)$payment['paymentMethodCode']] = (int)round((float)$payment['amount'] * 100);
        }
        if (($record['economicsDataStatus'] ?? '') !== 'ready'
            || ($record['orderStatus'] ?? '') === '数据异常'
            || (int)round((float)$record['receivableAmount'] * 100) !== (int)$fixture['sale_amount_cents']
            || ($paymentAmounts['member_balance'] ?? -1) !== (int)$fixture['balance_deduction_amount_cents']
            || array_sum($paymentAmounts) + (int)$fixture['debt_amount_cents'] !== (int)$fixture['sale_amount_cents']
            || (int)round((float)$record['actualReceivedAmount'] * 100) !== (int)$fixture['cash_performance_amount_cents']) {
            throw new RuntimeException('Balance sale reconciliation failed: ' . $fixture['order_no']);
        }
    }
}

echo 'R18_BALANCE_ORDER_CENTER=PASS; orders=' . count($fixtures) . "\n";
