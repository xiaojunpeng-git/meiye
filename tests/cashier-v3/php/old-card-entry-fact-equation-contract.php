<?php

require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\cashier\v3\fact\CashierV3SaleOnlyFactAssembler;
use app\services\cashier\v3\settlement\payment\CashierV3PaymentCollectionPlanV1;

$check = new ReflectionMethod(CashierV3SaleOnlyFactAssembler::class, 'assertTotals');
$request = [
    'sales_amount_cents' => 9000, 'selected_payment_amount_cents' => 6000,
    'cash_performance_amount_cents' => 0, 'balance_deduction_amount_cents' => 0,
    'debt_amount_cents' => 3000, 'receivable_amount_cents' => 6000,
    'composition' => 'sale_only',
];
$order = ['original_amount_cents' => 9000, 'discount_amount_cents' => 0, 'sale_amount_cents' => 9000];
$lines = [[
    'item_type' => 'card', 'original_amount_cents' => 9000, 'discount_amount_cents' => 0,
    'sale_amount_cents' => 9000, 'debt_amount_cents' => 3000,
]];
$batch = ['collected_amount_cents' => 0, 'cash_performance_amount_cents' => 0, 'receivable_amount_cents' => 6000];
$drafts = [['payment_method' => 'old_card_entry', 'amount_cents' => 6000]];

// This contract checks the final fact gate independently of database fixtures.
$check->invoke(null, $request, $order, $lines, $batch, [], $drafts);
echo "PASS card historical value plus debt creates no collected amount\n";

$invalidLines = $lines;
$invalidLines[0]['item_type'] = 'product';
try {
    $check->invoke(null, $request, $order, $invalidLines, $batch, [], $drafts);
    fwrite(STDERR, "FAIL product used historical card value\n");
    exit(1);
} catch (\Throwable $exception) {
    if (strpos($exception->getMessage(), 'sale_only_fact_old_card_entry_invalid') === false) {
        throw $exception;
    }
    echo "PASS product cannot use historical card value\n";
}

$cashRequest = $request;
$cashRequest['cash_performance_amount_cents'] = 6000;
$cashBatch = $batch;
$cashBatch['collected_amount_cents'] = 6000;
$cashBatch['cash_performance_amount_cents'] = 6000;
$check->invoke(null, $cashRequest, $order, $lines, $cashBatch, [
    ['amount_cents' => 6000, 'cash_performance_amount_cents' => 6000],
], [['payment_method' => 'wechat', 'amount_cents' => 6000]]);
echo "PASS ordinary collection keeps the original settlement equation\n";

// The collection-count gate must exclude historical value while still
// rejecting a missing formal collection for an ordinary paid method.
$planClass = new ReflectionClass(CashierV3PaymentCollectionPlanV1::class);
$plan = $planClass->newInstanceWithoutConstructor();
$planClass->getConstructor()->invoke($plan, [
    'command_idempotency_key' => 'old-card-test-key',
], [], 'old-card-test-fingerprint', 'sale_only', 0);
$countCheck = new ReflectionMethod(CashierV3SaleOnlyFactAssembler::class, 'assertPaymentCollectionPlan');
$countRequest = [
    'request_id' => 'old-card-test-request', 'request_version' => 1,
    'tenant_id' => 'old-card-test-tenant', 'store_id' => 1,
    'balance_deduction_amount_cents' => 0, 'debt_amount_cents' => 3000,
    'selected_payment_amount_cents' => 6000, 'receivable_amount_cents' => 6000,
];
$countOrder = ['order_id' => 'old-card-test-order', 'immutable_fingerprint' => 'old-card-test-order-fingerprint'];
$countBatch = [
    'command_idempotency_key' => 'old-card-test-key',
    'checkout_request_id' => 'old-card-test-request', 'checkout_request_version' => 1,
    'sales_order_id' => 'old-card-test-order', 'sales_order_fingerprint' => 'old-card-test-order-fingerprint',
    'tenant_id' => 'old-card-test-tenant', 'store_id' => 1,
    'batch_status' => 'settled', 'batch_direction' => 'forward', 'batch_version' => 1,
    'reversal_of_batch_id' => '', 'immutable_fingerprint' => 'old-card-test-fingerprint',
    'collection_count' => 0,
];
$legacyPayment = [
    'payment_draft_id' => 'old-card-test-payment', 'payment_method' => 'old_card_entry',
    'amount_cents' => 6000,
];
$countCheck->invoke(null, $countRequest, ['payments' => [$legacyPayment]], $plan,
    $countBatch, [], $countOrder, 'old-card-test-key');
echo "PASS historical card value plus debt needs no collection row\n";

$countRequest['debt_amount_cents'] = 0;
$countCheck->invoke(null, $countRequest, ['payments' => [$legacyPayment]], $plan,
    $countBatch, [], $countOrder, 'old-card-test-key');
echo "PASS standalone historical card value needs no collection row\n";

$ordinaryPayment = $legacyPayment;
$ordinaryPayment['payment_method'] = 'wechat';
try {
    $countCheck->invoke(null, $countRequest, ['payments' => [$ordinaryPayment]], $plan,
        $countBatch, [], $countOrder, 'old-card-test-key');
    fwrite(STDERR, "FAIL ordinary payment omitted its collection row\n");
    exit(1);
} catch (\Throwable $exception) {
    if (strpos($exception->getMessage(), 'sale_only_fact_payment_collection_count_mismatch') === false) {
        throw $exception;
    }
    echo "PASS ordinary payment still requires a collection row\n";
}
