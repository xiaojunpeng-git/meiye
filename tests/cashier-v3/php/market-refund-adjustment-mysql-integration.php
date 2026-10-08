<?php

declare(strict_types=1);

/** Market K and its drilldown must include recorded refunds without inventing a payment method. */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';

use app\services\report\StoreUnifiedReportServices;
use think\facade\Db;

$app = new \think\App(rtrim($backend, '/\\') . '/');
$app->initialize();
date_default_timezone_set('Asia/Shanghai');

$seed = Db::name('cashier_v3_payment_fact')->where('fact_type', 'manual_refund_adjustment')
    ->where('source_document_type', 'manual_refund')->where('status', 'effective')->find();
$source = Db::name('cashier_v3_business_source')->where('parent_id', 0)->where('status', 1)
    ->where('name', 'like', 'K%')->find();
$refundOperation = $seed ? Db::name('cashier_v3_order_lifecycle_operation')
    ->where('tenant_id', (string)$seed['tenant_id'])
    ->where('command_idempotency_key', (string)$seed['command_idempotency_key'])->find() : null;
$saleSource = Db::name('cashier_v3_business_source')->where('parent_id', 0)->where('status', 1)
    ->where('name', 'like', 'A%')->find();
if (!$seed || !$source || !$refundOperation || !$saleSource) {
    throw new RuntimeException('Local refund, operation and source fixtures are required');
}

$storeId = (int)$seed['store_id'];
$day = (string)$seed['business_date'];
$kAmount = 'channel_' . (int)$source['id'] . '_amount_cents';
$reports = new StoreUnifiedReportServices();
$input = static function (string $report, array $extra = []) use ($day): array {
    return array_merge([
        'report' => $report, 'start_date' => $day, 'end_date' => $day,
        'page' => 1, 'limit' => 100,
    ], $extra);
};
$market = static function () use ($reports, $storeId, $input): array {
    return $reports->query([$storeId], $input('market_performance'))['records'][0] ?? [];
};
$kDetail = static function () use ($reports, $storeId, $input, $source): array {
    return $reports->query([$storeId], $input('market_detail', [
        'dimension_code' => (string)$source['id'], 'metric_code' => 'amount',
    ]));
};

$before = $market();
$beforeDetail = $kDetail();
$beforeDetailCents = array_sum(array_map('intval', array_column($beforeDetail['records'], 'amount_cents')));
$token = bin2hex(random_bytes(16));
Db::startTrans();
try {
    foreach ([['manual_refund_adjustment', 'manual_refund', -12300], ['payment_refunded', 'imported_card_refund', -1700]] as $index => [$type, $document, $amount]) {
        $fact = $seed;
        unset($fact['id']);
        $fact['fact_id'] = 'MRKT-' . $token . '-' . $index;
        $fact['business_event_no'] = 'market-refund-test:' . $token . ':' . $index;
        $fact['natural_key'] = 'market-refund-test:' . $token . ':' . $index;
        $fact['command_idempotency_key'] = 'market-refund-test:' . $token . ':' . $index;
        $fact['order_id'] = 'market-refund-test:' . $token . ':' . $index;
        $fact['order_no_snapshot'] = 'TKTEST' . $token . $index;
        $fact['checkout_request_id'] = $fact['order_id'];
        $fact['payment_authority_key'] = $fact['natural_key'];
        $fact['fact_type'] = $type;
        $fact['fact_direction'] = 'adjustment';
        $fact['source_document_type'] = $document;
        $fact['payment_method'] = $index === 0 ? 'manual_adjustment' : 'manual_refund';
        $fact['business_source_primary_id'] = 0;
        $fact['amount_cents'] = $amount;
        $fact['immutable_fingerprint'] = hash('sha256', $fact['natural_key']);
        Db::name('cashier_v3_payment_fact')->insert($fact);
    }

    $after = $market();
    if ((int)($after[$kAmount] ?? 0) !== (int)($before[$kAmount] ?? 0) - 14000
        || (int)($after['total_performance_cents'] ?? 0) !== (int)($before['total_performance_cents'] ?? 0) - 14000) {
        throw new RuntimeException('Market K or total performance missed a recorded refund');
    }
    foreach ($before as $key => $value) {
        if (strpos($key, 'payment_') === 0 && ($after[$key] ?? null) !== $value) {
            throw new RuntimeException('Recorded refund incorrectly changed a payment channel');
        }
    }
    $afterDetail = $kDetail();
    $afterDetailCents = array_sum(array_map('intval', array_column($afterDetail['records'], 'amount_cents')));
    if ($afterDetailCents !== $beforeDetailCents - 14000) {
        throw new RuntimeException('K drilldown does not reconcile with market summary');
    }
    $export = $reports->export([$storeId], $input('market_detail', ['dimension_code' => (string)$source['id']]));
    $exportCents = array_sum(array_map('intval', array_column($export['records'], 'amount_cents')));
    if ($exportCents !== $afterDetailCents) throw new RuntimeException('K export differs from drilldown');

    // A real payment reversal belongs to K, not the sale's former source;
    // unlike a manual refund it also reverses its original payment channel.
    $reversalKey = 'market-refund-reversal-test:' . $token;
    $operation = $refundOperation;
    unset($operation['id']);
    $operation['operation_id'] = 'OLO-MRKT-' . $token;
    $operation['operation_no'] = 'TKTEST' . $token;
    $operation['command_idempotency_key'] = $reversalKey;
    $operation['business_event_no'] = $reversalKey;
    $operation['immutable_fingerprint'] = hash('sha256', $reversalKey);
    $operation['operation_type'] = 'refund';
    $operation['status'] = 'succeeded';
    Db::name('cashier_v3_order_lifecycle_operation')->insert($operation);
    $reversal = $seed;
    unset($reversal['id']);
    $reversal['fact_id'] = 'MRKT-REV-' . $token;
    $reversal['natural_key'] = $reversalKey;
    $reversal['business_event_no'] = $reversalKey;
    $reversal['command_idempotency_key'] = $reversalKey;
    $reversal['checkout_request_id'] = $reversalKey;
    $reversal['order_id'] = $reversalKey;
    $reversal['order_no_snapshot'] = 'TKTESTREV' . $token;
    $reversal['payment_authority_key'] = $reversalKey;
    $reversal['fact_type'] = 'payment_refunded';
    $reversal['fact_direction'] = 'reversal';
    $reversal['source_document_type'] = 'sale';
    $reversal['payment_method'] = 'unionpay';
    $reversal['business_source_primary_id'] = (int)$saleSource['id'];
    $reversal['business_source_primary_name_snapshot'] = (string)$saleSource['name'];
    $reversal['business_source_label_snapshot'] = (string)$saleSource['name'];
    $reversal['amount_cents'] = -3300;
    $reversal['immutable_fingerprint'] = hash('sha256', $reversalKey);
    Db::name('cashier_v3_payment_fact')->insert($reversal);

    $withReversal = $market();
    $withReversalDetail = $kDetail();
    $withReversalCents = array_sum(array_map('intval', array_column($withReversalDetail['records'], 'amount_cents')));
    if ((int)$withReversal[$kAmount] !== (int)$after[$kAmount] - 3300
        || (int)$withReversal['total_performance_cents'] !== (int)$after['total_performance_cents'] - 3300
        || (int)$withReversal['payment_unionpay_cents'] !== (int)($after['payment_unionpay_cents'] ?? 0) - 3300
        || $withReversalCents !== $afterDetailCents - 3300) {
        throw new RuntimeException('Cash refund K summary, payment channel or drilldown diverged');
    }
    $formerSource = $reports->query([$storeId], $input('market_detail', [
        'dimension_code' => (string)$saleSource['id'], 'metric_code' => 'amount',
    ]));
    foreach ($formerSource['records'] as $row) {
        if (in_array($reversalKey, array_column((array)($row['_market_orders'] ?? []), 'order_id'), true)) {
            throw new RuntimeException('Cash refund leaked into its former sale source');
        }
    }

    // A day with no receipt still needs a market row for its recorded refund.
    $refundOnlyDay = '2026-10-02';
    if ((int)Db::name('cashier_v3_payment_fact')->where('store_id', $storeId)
        ->where('business_date', $refundOnlyDay)->count() !== 0) {
        throw new RuntimeException('Refund-only fixture day already has payment facts');
    }
    $isolated = $seed;
    unset($isolated['id']);
    $isolatedKey = 'market-refund-only-test:' . $token;
    $isolated['fact_id'] = 'MRKT-ONLY-' . $token;
    $isolated['business_event_no'] = $isolatedKey;
    $isolated['natural_key'] = $isolatedKey;
    $isolated['command_idempotency_key'] = $isolatedKey;
    $isolated['checkout_request_id'] = $isolatedKey;
    $isolated['order_id'] = $isolatedKey;
    $isolated['order_no_snapshot'] = 'TKTESTONLY' . $token;
    $isolated['payment_authority_key'] = $isolatedKey;
    $isolated['business_date'] = $refundOnlyDay;
    $isolated['amount_cents'] = -2000;
    $isolated['immutable_fingerprint'] = hash('sha256', $isolatedKey);
    Db::name('cashier_v3_payment_fact')->insert($isolated);
    $isolatedInput = [
        'start_date' => $refundOnlyDay, 'end_date' => $refundOnlyDay,
        'page' => 1, 'limit' => 100,
    ];
    $isolatedMarket = $reports->query([$storeId], ['report' => 'market_performance'] + $isolatedInput);
    $isolatedRow = $isolatedMarket['records'][0] ?? [];
    $isolatedDetail = $reports->query([$storeId], [
        'report' => 'market_detail', 'dimension_code' => (string)$source['id'],
        'metric_code' => 'amount',
    ] + $isolatedInput);
    if ((int)($isolatedRow[$kAmount] ?? 0) !== -2000
        || (int)($isolatedRow['total_performance_cents'] ?? 0) !== -2000
        || array_sum(array_map('intval', array_column($isolatedDetail['records'], 'amount_cents'))) !== -2000) {
        throw new RuntimeException('Refund-only day disappeared from market summary or drilldown');
    }
    $personal = $reports->query([$storeId], [
        'report' => 'market_detail', 'dimension_code' => (string)$source['id'],
        '_report_scope' => ['mode' => 'self_participant', 'employee_id' => 999999999],
    ] + $isolatedInput);
    if ($personal['records'] !== []) throw new RuntimeException('Refund leaked outside participant scope');
    echo "market refund adjustment integration: PASS\n";
} finally {
    Db::rollback();
}
