<?php
declare(strict_types=1);

// Reconcile the two retained local cashier UI operations; this script never writes business data.
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();
use think\facade\Db;

try {
    if (config('database.connections.mysql.hostname') !== 'mysql'
        || config('database.connections.mysql.database') !== 'ruihao') {
        throw new RuntimeException('Local mysql/ruihao only');
    }
    $assert = static function (bool $condition, string $message): void {
        if (!$condition) throw new RuntimeException($message);
    };
    $order = Db::name('cashier_v3_sales_order')->where('order_no', 'XS26102000003')->find();
    $assert($order && (int)$order['member_id'] === 913081 && (int)$order['store_id'] === 118,
        'Wrong retained cashier order');
    $assert($order['order_status'] === 'settled' && $order['source_document_type'] === 'cashier_snapshot'
        && (int)$order['operator_id'] === 618 && (int)$order['business_source_primary_id'] === 17
        && (int)$order['sale_amount_cents'] === 298000, 'Cashier order contract changed');
    $id = $order['order_id'];
    $payments = Db::name('cashier_v3_payment_fact')->where('order_id', $id)->select()->toArray();
    $assert(count($payments) === 1 && $payments[0]['payment_method'] === 'cash'
        && $payments[0]['status'] === 'effective' && (int)$payments[0]['amount_cents'] === 298000,
        'Cash collection not effective exactly once');
    $sales = Db::name('cashier_v3_performance_fact')->where('order_id', $id)
        ->where('performance_type', 'sales_performance_allocated')->order('employee_id')->select()->toArray();
    $assert(count($sales) === 2 && array_column($sales, 'employee_name_snapshot') === ['何盼盼', '潘金花']
        && (int)$sales[0]['amount_cents'] === 149000 && (int)$sales[1]['amount_cents'] === 149000,
        'Normal cashier automatic sales allocation changed');
    $receipt = Db::name('cashier_v3_card_purchase_receipt')->where('sales_order_id', $id)->find();
    $assert($receipt && $receipt['status'] === 'completed' && (int)$receipt['card_holder_id'] === 2076898,
        'Card receipt missing');
    $holder = Db::name('user_card_holder')->where('id', 2076898)
        ->field('id,oid,card_no,card_name,write_times,write_surplus_times,is_del')->find();
    $detail = Db::name('store_order_cart_info')->where('id', 4485570)
        ->field('id,oid,product_id,write_times,write_surplus_times,is_writeoff')->find();
    $assert($holder && (int)$holder['write_times'] === 10 && (int)$holder['write_surplus_times'] === 9
        && (int)$holder['is_del'] === 0 && $detail && (int)$detail['write_surplus_times'] === 9,
        'New card must retain nine uses');
    $writeoffs = Db::name('cashier_v3_entitlement_writeoff_fact')->where('source_detail_id', 4485570)
        ->field('writeoff_id,checkout_request_id,holder_id,source_detail_id,quantity,actual_entitlement_amount_cents,status')
        ->select()->toArray();
    $assert(count($writeoffs) === 1 && $writeoffs[0]['status'] === 'effective'
        && (int)$writeoffs[0]['quantity'] === 1
        && (int)$writeoffs[0]['actual_entitlement_amount_cents'] === 29800, 'One whole-yuan redemption required');
    $request = $writeoffs[0]['checkout_request_id'];
    $services = Db::name('cashier_v3_entitlement_service_fact')->where('checkout_request_id', $request)
        ->field('service_record_no,project_name_snapshot,quantity,service_status,checkout_request_id')->select()->toArray();
    $assert(count($services) === 1 && $services[0]['service_status'] === 'completed', 'Service not completed once');
    // A pure entitlement completion must not create a second sale, payment or balance debit.
    foreach (['cashier_v3_sales_order', 'cashier_v3_sale_fact', 'cashier_v3_payment_fact', 'cashier_v3_balance_fact'] as $table) {
        $assert((int)Db::name($table)->where('checkout_request_id', $request)->count() === 0,
            'Pure redemption unexpectedly wrote '.$table);
    }
    $assert((int)Db::name('cashier_v3_balance_fact')->where('order_id', $id)->count() === 0,
        'Cash sale unexpectedly debited balance');
    $balance = Db::name('user')->where('uid', 913081)
        ->field('uid,now_money,ben_money,give_money,belong_store_id,balance_version')->find();
    $assert($balance['now_money'] === '996454.00' && (int)$balance['balance_version'] === 4,
        'Retained member balance changed');
    $assert((int)Db::name('user_card_holder')->where('id', 2076884)->value('write_surplus_times') === 9,
        'Original member card altered');
    $evidence = [
        'verification' => 'PASS: normal cashier card purchase and pure rights redemption retained',
        'environment' => 'local mysql/ruihao; no real payment gateway',
        'order' => array_intersect_key($order, array_flip(['order_id', 'order_no', 'member_id', 'store_id',
            'operator_id', 'business_source_primary_id', 'sale_amount_cents', 'order_status', 'source_document_type'])),
        'payment' => array_intersect_key($payments[0], array_flip(['fact_id', 'payment_method', 'amount_cents', 'status'])),
        'salespeople' => array_map(static function (array $row): array {
            return array_intersect_key($row, array_flip(['employee_name_snapshot', 'amount_cents',
                'allocation_weight_numerator', 'allocation_weight_denominator', 'status']));
        }, $sales),
        'holder' => $holder, 'detail' => $detail, 'writeoffs' => $writeoffs, 'services' => $services,
        'balance' => $balance,
        'uiObservation' => 'Sale result heading says payment succeeded, but cash child result still says pending confirmation; effective payment fact verified. No cashier UI changed.',
    ];
    echo json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: '.$error->getMessage().PHP_EOL);
    exit(1);
}
