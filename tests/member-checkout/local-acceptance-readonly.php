<?php
declare(strict_types=1);
// Read-only evidence for the two explicitly retained UI purchases.
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql') throw new RuntimeException('Local only');
use think\facade\Db;
$orders = Db::name('member_commerce_order')->where('member_id',913081)->where('kind','purchase')
    ->whereIn('document_no',['SJ-XS26102000001','SJ-XS26102000002'])->select()->toArray();
$evidence = ['balance'=>Db::name('user')->where('uid',913081)->field('uid,now_money,ben_money,give_money,belong_store_id,balance_version')->find(),'orders'=>[]];
foreach ($orders as $row) {
    $orderId = $row['sales_order_id'];
    $header = Db::name('cashier_v3_sales_order')->where('order_id',$orderId)->find();
    $lines = Db::name('cashier_v3_sales_order_line')->where('order_id',$orderId)->select()->toArray();
    $holders = [];
    foreach ($lines as $line) {
        if ($line['item_type'] === 'project') {
            $no = 'v3p'.substr(hash('sha256',$line['order_line_id']),0,28);
            $oid = Db::name('store_order')->where('order_id',$no)->value('id');
        } else {
            $oid = Db::name('cashier_v3_card_purchase_receipt')->where('sales_order_line_id',$line['order_line_id'])->value('legacy_order_id');
        }
        if (!$oid) continue;
        $holders[] = ['holder'=>Db::name('user_card_holder')->where('oid',$oid)
            ->field('id,oid,card_no,card_name,product_type,write_times,write_surplus_times,is_del')->find(),
            'details'=>Db::name('store_order_cart_info')->where('oid',$oid)->where('cart_type',2)
                ->field('id,product_id,write_times,write_surplus_times,is_writeoff,writeoff_time')->select()->toArray()];
    }
    $evidence['orders'][] = ['publicId'=>$row['public_id'],'documentNo'=>$row['document_no'],'status'=>$row['status'],
        'salesOrderId'=>$orderId,'storeId'=>$row['store_id'],'balanceCents'=>$row['balance_cents'],
        'sourceId'=>$header['business_source_primary_id'],'holders'=>$holders,
        'balanceFacts'=>Db::name('cashier_v3_balance_fact')->where('order_id',$orderId)->field('principal_delta_cents,bonus_delta_cents,status')->select()->toArray(),
        'performanceFacts'=>Db::name('cashier_v3_performance_fact')->where('order_id',$orderId)
            ->field('performance_type,employee_name_snapshot,amount_cents,allocation_weight_numerator,allocation_weight_denominator')->select()->toArray()];
}
$evidence['writeoffs'] = Db::name('cashier_v3_entitlement_writeoff_fact')->whereIn('source_detail_id',[4485553,4485555])
    ->field('writeoff_id,checkout_request_id,source_line_id,source_detail_id,quantity,actual_entitlement_amount_cents,status')->select()->toArray();
$requests = array_column($evidence['writeoffs'],'checkout_request_id');
$evidence['services'] = $requests ? Db::name('cashier_v3_entitlement_service_fact')->whereIn('checkout_request_id',$requests)
    ->field('service_record_no,project_name_snapshot,quantity,service_status,checkout_request_id')->select()->toArray() : [];
if (count($orders) !== 2 || count($evidence['writeoffs']) !== 2 || count($evidence['services']) !== 2
    || $evidence['balance']['now_money'] !== '996454.00') throw new RuntimeException('Acceptance reconciliation failed');
$evidence['verification'] = 'PASS: two UI purchases, two store redemptions, no second balance debit';
echo json_encode($evidence,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),PHP_EOL;
