<?php
declare(strict_types=1);

/** Local finance regression: duplicate 007 recharge carriers are selected once; every fixture rolls back. */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();

use app\services\report\LegacyRechargeCarrierScope;
use app\services\report\BasicReportHostServices;
use app\services\report\Sidebar007OriginalCashReadServices;
use app\services\report\StoreReconciliationReceiptReader;
use app\services\report\StoreReconciliationReportServices;
use think\facade\Db;

if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('This rollback test runs only on the local ruihao database');
}

$payment = Db::name('cashier_v3_payment_fact')->where('tenant_id', '0')
    ->where('source_document_type', 'recharge')->where('fact_direction', 'forward')
    ->where('status', 'effective')->where('payment_method', 'unionpay')
    ->where('order_id', 'like', 'RCH:%')->order('id desc')->find();
$orderTemplate = Db::name('store_order')->order('id desc')->find();
if (!$payment || !$orderTemplate || !preg_match('/^RCH:(\d+)$/', (string)$payment['order_id'], $match)) {
    throw new RuntimeException('Local recharge and order fixtures are missing');
}
$rechargeId = (int)$match[1];
$day = (string)$payment['business_date'];
$reader = new StoreReconciliationReceiptReader();
$methods = $reader->checkoutMethods();
$range = ['start_date' => $day, 'end_date' => $day];
$store = (int)$payment['store_id'];
$contains = static function (array $rows, string $receiptId): bool {
    foreach ($rows as $row) if (($row['receipt_id'] ?? '') === $receiptId) return true;
    return false;
};
$tag = bin2hex(random_bytes(8));
$makeOrder = static function (string $kind, int $paidAt) use ($orderTemplate, $payment, $rechargeId, $tag): int {
    $row = $orderTemplate;
    unset($row['id']);
    $row['order_id'] = 'R38-' . $kind . '-' . $tag;
    $row['unique'] = $kind . '-' . $tag;
    $row['uid'] = (int)$payment['member_id'];
    $row['store_id'] = (int)$payment['store_id'];
    $row['pid'] = -2;
    $row['link_id'] = $rechargeId;
    $row['pay_type'] = $kind === 'combination' ? 'combination' : 'cash';
    $row['paid'] = 1;
    $row['cash_choose'] = $kind === 'combination' ? 0 : 1;
    $row['cash_pay_price'] = '6000.00';
    $row['pay_price'] = '6000.00';
    $row['total_price'] = '6000.00';
    $row['add_time'] = $paidAt;
    $row['pay_time'] = $paidAt;
    $row['is_system_del'] = 0;
    $row['refund_status'] = 0;
    $row['order_type'] = 0;
    return (int)Db::name('store_order')->insertGetId($row);
};

Db::startTrans();
try {
    $directId = $makeOrder('direct', (int)$payment['settled_at']);
    $combinationId = $makeOrder('combination', (int)$payment['settled_at']);
    $lineId = (int)Db::name('combination_order')->insertGetId([
        'order_id' => $combinationId, 'active_pay' => 2, 'price' => '6000.00',
        'cash_choose' => 1, 'pay_sub_type' => '', 'add_time' => (int)$payment['settled_at'],
    ]);
    $directReceipt = 'legacy-order:' . $directId . ':legacy_1';
    $combinationReceipt = 'legacy-combination:' . $lineId . ':legacy_1';
    $before = $reader->receipts([$store], $methods, $range, '0');
    if (!$contains($before, $directReceipt) || !$contains($before, $combinationReceipt)) {
        throw new RuntimeException('Unmigrated old receipts must remain visible');
    }

    // The formal imported fact becomes the payment source; the two old shells have no separate cash event.
    $imported = $payment;
    unset($imported['id']);
    foreach (['fact_id', 'business_event_no', 'natural_key', 'command_idempotency_key', 'source_line_id'] as $key) {
        $imported[$key] = 'R38-' . $tag;
    }
    $imported['immutable_fingerprint'] = hash('sha256', $tag);
    $imported['payment_authority_key'] = 'legacy-recharge:' . $rechargeId . ':payment:' . $tag;
    $imported['amount_cents'] = 12300;
    Db::name('cashier_v3_payment_fact')->insert($imported);
    $after = $reader->receipts([$store], $methods, $range, '0');
    if ($contains($after, $directReceipt) || $contains($after, $combinationReceipt)
        || !$contains($after, 'v3:R38-' . $tag)
        || !$contains($after, 'v3:' . $payment['fact_id'])) {
        throw new RuntimeException('Canonical recharge fact must replace both old shells and retain native cash');
    }
    $expectedCents = 0;
    foreach ($after as $receipt) if ($receipt['method_code'] === 'legacy_1') $expectedCents += (int)$receipt['amount_cents'];
    $context = BasicReportHostServices::context([$store], 'stores', 4796, 'platform', false, 0,
        StoreReconciliationReportServices::CODE);
    $payload = ['pageCode' => StoreReconciliationReportServices::CODE, 'start_date' => $day,
        'end_date' => $day, 'reconciliation_mode' => 'stores', 'reconciliation_store_id' => 0];
    $report = new StoreReconciliationReportServices();
    $matrix = $report->query($context, $payload);
    $row = array_column($matrix['rows'], null, 'method_code')['legacy_1'] ?? null;
    $value = number_format($expectedCents / 100, 2, '.', '');
    if (!$row || ($row['store_' . $store] ?? null) !== $value) {
        throw new RuntimeException('Matrix does not match canonical receipt sum');
    }
    $detailPayload = $payload + ['detail' => ['method_code' => 'legacy_1', 'column_key' => 'store_' . $store],
        'expected_fingerprint' => $matrix['receipt_fingerprint']];
    $detail = $report->query($context, $detailPayload);
    if (bccomp((string)$detail['summaries']['amount:sum'], $value, 2) !== 0) {
        throw new RuntimeException('Drilldown does not explain matrix amount');
    }
    $export = $report->query($context, $payload + ['expected_fingerprint' => $matrix['receipt_fingerprint'],
        'export' => ['scope' => 'query', 'fields' => ['method_code', 'store_' . $store]]]);
    $exportRow = array_column($export['exportRows'], null, 'method_code')['legacy_1'] ?? null;
    if (!$exportRow || ($exportRow['store_' . $store] ?? null) !== $value) {
        throw new RuntimeException('Export does not match matrix amount');
    }
    $sidebar = (new Sidebar007OriginalCashReadServices())->read([$store], ['start' => $day, 'end' => $day]);
    foreach ([$directId, $combinationId] as $id) {
        if (isset($sidebar['by_order'][$store]['historical:order:' . $id])) {
            throw new RuntimeException('PK and store cash source counted a duplicate old shell');
        }
    }

    // Same link ID used on another date is not sufficient proof of the same payment.
    $differentDateId = $makeOrder('different-date', (int)$payment['settled_at'] - 86400 * 2);
    $query = Db::name('store_order')->where('id', $differentDateId);
    LegacyRechargeCarrierScope::excludeCovered($query, '0');
    if ((int)$query->count() !== 1) throw new RuntimeException('Unrelated old shell was hidden');
    echo "PASS one canonical recharge source across reconciliation, drilldown, export and PK cash readers\n";
    echo "PASS unrelated date and native recharge stay visible\n";
} finally {
    Db::rollback();
}
echo "PASS all local finance fixtures rolled back\n";
