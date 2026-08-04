<?php

$root = dirname(__DIR__, 3);
$base = $root . '/后端代码/app/services/cashier/v3';
require $base . '/CashierV3BusinessDocumentNumberServices.php';
require $base . '/settlement/CashierV3CheckoutSettlementContractException.php';
require $base . '/settlement/CashierV3CheckoutSettlementCanonicalizer.php';
require $base . '/settlement/CashierV3CheckoutVerifiedSourceSet.php';
require $base . '/order/settlement/CashierV3SalesOrderAuthorityException.php';
require $base . '/order/settlement/CashierV3SalesOrderIdFactory.php';
require $base . '/order/settlement/CashierV3SalesOrderPlanV1.php';
require $base . '/settlement/payment/CashierV3PaymentCollectionAuthorityException.php';
require $base . '/settlement/payment/CashierV3PaymentCollectionIdFactory.php';
require $base . '/settlement/payment/CashierV3PaymentCollectionPlanV1.php';
require __DIR__ . '/fixture.php';

use app\services\cashier\v3\settlement\CashierV3CheckoutSettlementCanonicalizer;
use app\services\cashier\v3\settlement\payment\CashierV3PaymentCollectionAuthorityException;
use app\services\cashier\v3\settlement\payment\CashierV3PaymentCollectionPlanV1;

$passed = 0;
$failed = 0;
function paymentCollectionOk(string $name, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}\n";
}

function paymentCollectionReason(callable $callback): string
{
    try {
        $callback();
    } catch (CashierV3PaymentCollectionAuthorityException $exception) {
        return $exception->reason();
    }
    return '';
}

function paymentCollectionPlan(
    array $aggregate,
    int $commandNo = 99,
    string $secret = 'payment-collection-local-secret-32-bytes'
): CashierV3PaymentCollectionPlanV1 {
    $commandKey = paymentCollectionIdempotency('CHECKOUT', $commandNo);
    return CashierV3PaymentCollectionPlanV1::fromLockedCheckoutAggregate(
        $aggregate,
        paymentCollectionSalesOrder($aggregate, $commandKey, $secret),
        $commandKey,
        1785283250,
        1785283260,
        1785283261,
        $secret
    );
}

$aggregate = paymentCollectionLockedAggregate();
$plan = paymentCollectionPlan($aggregate);
$batch = $plan->batch();
$collections = $plan->collections();
paymentCollectionOk('one payment draft produces exactly one immutable collection',
    count($collections) === count($aggregate['payments'])
        && array_column($collections, 'checkout_payment_draft_id') === array_column(
            $aggregate['payments'],
            'payment_draft_id'
        ));
paymentCollectionOk('two methods keep code name amount and independent identities',
    array_column($collections, 'payment_method') === ['wechat', 'alipay']
        && array_column($collections, 'payment_method_name_snapshot') === ['微信', '支付宝']
        && array_column($collections, 'amount_cents') === [5000, 3500]
        && $collections[0]['collection_id'] !== $collections[1]['collection_id']);
paymentCollectionOk('batch and detail totals equal selected payment cash performance and receivable',
    $batch['collection_count'] === 2
        && $batch['collected_amount_cents'] === 8500
        && $batch['cash_performance_amount_cents'] === 8500
        && $batch['receivable_amount_cents'] === 8500
        && array_sum(array_column($collections, 'cash_performance_amount_cents')) === 8500);
paymentCollectionOk('historical payment time operator source and reference are materialized',
    $collections[0]['payment_business_time_snapshot'] === 1785283200
        && $collections[0]['payment_recorded_at_snapshot'] === 1785283201
        && $collections[0]['operator_name_snapshot'] === '收银员 A'
        && $collections[0]['source_document_no_snapshot'] === 'WS-20260729-1'
        && $collections[0]['external_transaction_no_snapshot'] === 'WECHAT-TRACE-1');
paymentCollectionOk('final success times are server command times rather than draft edit times',
    $collections[0]['occurred_at'] === 1785283250
        && $collections[0]['settled_at'] === 1785283260
        && $collections[0]['recorded_at'] === 1785283261
        && $collections[0]['occurred_at'] !== $collections[0]['payment_business_time_snapshot']);
paymentCollectionOk('sales order identity and immutable fingerprint remain linked',
    preg_match('/^CSO-[0-9a-f]{40}$/D', $batch['sales_order_id']) === 1
        && $collections[0]['sales_order_id'] === $batch['sales_order_id']
        && $collections[0]['sales_order_fingerprint'] === $batch['sales_order_fingerprint']);
paymentCollectionOk('status version and reversal linkage preserve forward history',
    $batch['batch_status'] === 'settled'
        && $batch['batch_version'] === 1
        && $batch['batch_direction'] === 'forward'
        && $batch['reversal_of_batch_id'] === ''
        && $collections[0]['collection_status'] === 'settled'
        && $collections[0]['collection_version'] === 1
        && $collections[0]['collection_direction'] === 'forward'
        && $collections[0]['reversal_of_collection_id'] === '');

$mixedAggregate = paymentCollectionLockedAggregate(null, 9, true);
$mixedPlan = paymentCollectionPlan($mixedAggregate);
paymentCollectionOk('mixed checkout reuses the same immutable collection grain',
    $mixedPlan->composition() === 'mixed'
        && $mixedPlan->batch()['receivable_amount_cents'] === 8500
        && $mixedPlan->batch()['collected_amount_cents'] === 8500
        && count($mixedPlan->collections()) === 2
        && array_column($mixedPlan->collections(), 'payment_method') === ['wechat', 'alipay']);
paymentCollectionOk('sale-only checkout keeps its explicit composition identity',
    $plan->composition() === 'sale_only');

$saleOnlyForMixed = paymentCollectionLockedAggregate(null, 9, false);
$saleOnlyOrderForMixed = paymentCollectionSalesOrder(
    $saleOnlyForMixed,
    paymentCollectionIdempotency('CHECKOUT', 99),
    'payment-collection-local-secret-32-bytes'
);
paymentCollectionOk('payment plan rejects a sales order from a different composition',
    paymentCollectionReason(static function () use ($mixedAggregate, $saleOnlyOrderForMixed): void {
        CashierV3PaymentCollectionPlanV1::fromLockedCheckoutAggregate(
            $mixedAggregate,
            $saleOnlyOrderForMixed,
            paymentCollectionIdempotency('CHECKOUT', 99),
            1785283250,
            1785283260,
            1785283261,
            'payment-collection-local-secret-32-bytes'
        );
    }) === 'payment_collection_sales_order_identity_mismatch');

$entitlementOnly = $mixedAggregate;
$entitlementOnly['request']['composition'] = 'entitlement_only';
$entitlementOnly['request']['sales_amount_cents'] = 0;
$entitlementOnly['request']['receivable_amount_cents'] = 0;
$entitlementOnly['request']['selected_payment_amount_cents'] = 0;
$entitlementOnly['request']['cash_performance_amount_cents'] = 0;
$entitlementOnly['lines'] = [$entitlementOnly['lines'][1]];
$entitlementOnly['payments'] = [];
paymentCollectionOk('entitlement-only checkout never creates a zero-value collection batch',
    paymentCollectionReason(static function () use ($entitlementOnly, $saleOnlyOrderForMixed): void {
        CashierV3PaymentCollectionPlanV1::fromLockedCheckoutAggregate(
            $entitlementOnly,
            $saleOnlyOrderForMixed,
            paymentCollectionIdempotency('CHECKOUT', 99),
            1785283250,
            1785283260,
            1785283261,
            'payment-collection-local-secret-32-bytes'
        );
    }) === 'payment_collection_sale_composition_required');

$unknownComposition = paymentCollectionLockedAggregate();
$unknownComposition['request']['composition'] = 'unknown';
paymentCollectionOk('unknown checkout composition is rejected fail-closed',
    paymentCollectionReason(static function () use ($unknownComposition, $saleOnlyOrderForMixed): void {
        CashierV3PaymentCollectionPlanV1::fromLockedCheckoutAggregate(
            $unknownComposition,
            $saleOnlyOrderForMixed,
            paymentCollectionIdempotency('CHECKOUT', 99),
            1785283250,
            1785283260,
            1785283261,
            'payment-collection-local-secret-32-bytes'
        );
    }) === 'payment_collection_sale_composition_required');

$same = paymentCollectionPlan(paymentCollectionLockedAggregate());
paymentCollectionOk('identical locked source creates a stable replay plan',
    $same->batch() === $batch
        && $same->collections() === $collections
        && $same->fingerprint() === $plan->fingerprint());
$differentCommand = paymentCollectionPlan(paymentCollectionLockedAggregate(), 100);
paymentCollectionOk('new command cannot change stable collection identities',
    array_column($differentCommand->collections(), 'collection_id')
        === array_column($collections, 'collection_id')
        && $differentCommand->fingerprint() !== $plan->fingerprint());

$sevenMethods = [
    ['unionpay', 1000], ['wechat', 1000], ['alipay', 1000],
    ['dianping_voucher', 1000], ['douyin_voucher', 1000],
    ['partner_collection', 1000], ['other_collection', 1000],
];
$seven = paymentCollectionPlan(paymentCollectionLockedAggregate($sevenMethods));
paymentCollectionOk('all and only seven fixed bookkeeping methods are accepted',
    array_column($seven->collections(), 'payment_method') === array_column($sevenMethods, 0)
        && $seven->batch()['collection_count'] === 7);

$oldCard = paymentCollectionLockedAggregate([['old_card_entry', 8500]]);
$validOrder = paymentCollectionSalesOrder(
    paymentCollectionLockedAggregate(),
    paymentCollectionIdempotency('CHECKOUT', 99),
    'payment-collection-local-secret-32-bytes'
);
paymentCollectionOk('legacy old-card entry is never a formal collection',
    paymentCollectionReason(static function () use ($oldCard, $validOrder): void {
        CashierV3PaymentCollectionPlanV1::fromLockedCheckoutAggregate(
            $oldCard,
            $validOrder,
            paymentCollectionIdempotency('CHECKOUT', 99),
            1785283250,
            1785283260,
            1785283261,
            'payment-collection-local-secret-32-bytes'
        );
    }) === 'old_card_entry_payment_collection_forbidden');
$paperCash = paymentCollectionLockedAggregate([['cash', 8500]]);
paymentCollectionOk('paper cash is not a supported bookkeeping method',
    paymentCollectionReason(static function () use ($paperCash, $validOrder): void {
        CashierV3PaymentCollectionPlanV1::fromLockedCheckoutAggregate(
            $paperCash,
            $validOrder,
            paymentCollectionIdempotency('CHECKOUT', 99),
            1785283250,
            1785283260,
            1785283261,
            'payment-collection-local-secret-32-bytes'
        );
    }) === 'payment_collection_method_invalid');
$duplicate = paymentCollectionLockedAggregate([['wechat', 4000], ['wechat', 4500]]);
$duplicatePlan = paymentCollectionPlan($duplicate);
paymentCollectionOk('one checkout materializes multiple independent collections of one method',
    $duplicatePlan->batch()['collection_count'] === 2
        && $duplicatePlan->batch()['collected_amount_cents'] === 8500
        && array_column($duplicatePlan->collections(), 'payment_method') === ['wechat', 'wechat']
        && array_column($duplicatePlan->collections(), 'amount_cents') === [4000, 4500]
        && $duplicatePlan->collections()[0]['collection_id']
            !== $duplicatePlan->collections()[1]['collection_id']
        && $duplicatePlan->collections()[0]['checkout_payment_draft_id']
            !== $duplicatePlan->collections()[1]['checkout_payment_draft_id']);
$editing = paymentCollectionLockedAggregate();
$editingOrder = paymentCollectionSalesOrder(
    paymentCollectionLockedAggregate(),
    paymentCollectionIdempotency('CHECKOUT', 99),
    'payment-collection-local-secret-32-bytes'
);
$editing['request']['request_status'] = 'editing';
$editing['currentRequest']['status'] = 'editing';
paymentCollectionOk('non-final checkout cannot create collection authority',
    paymentCollectionReason(static function () use ($editing, $editingOrder): void {
        CashierV3PaymentCollectionPlanV1::fromLockedCheckoutAggregate(
            $editing,
            $editingOrder,
            paymentCollectionIdempotency('CHECKOUT', 99),
            1785283250,
            1785283260,
            1785283261,
            'payment-collection-local-secret-32-bytes'
        );
    }) === 'checkout_request_not_ready_for_payment_collection');
$extra = paymentCollectionLockedAggregate();
$extra['clientPayment'] = ['method' => 'wechat'];
paymentCollectionOk('client-shaped extra authority is rejected',
    paymentCollectionReason(static function () use ($extra, $validOrder): void {
        CashierV3PaymentCollectionPlanV1::fromLockedCheckoutAggregate(
            $extra,
            $validOrder,
            paymentCollectionIdempotency('CHECKOUT', 99),
            1785283250,
            1785283260,
            1785283261,
            'payment-collection-local-secret-32-bytes'
        );
    }) === 'payment_collection_locked_aggregate_shape_invalid');
$badSecret = paymentCollectionLockedAggregate();
paymentCollectionOk('server HMAC namespace secret cannot be a short client value',
    paymentCollectionReason(static function () use ($badSecret, $validOrder): void {
        CashierV3PaymentCollectionPlanV1::fromLockedCheckoutAggregate(
            $badSecret,
            $validOrder,
            paymentCollectionIdempotency('CHECKOUT', 99),
            1785283250,
            1785283260,
            1785283261,
            'short'
        );
    }) === 'payment_collection_server_id_secret_invalid');
$invalidTimezone = paymentCollectionLockedAggregate();
$invalidTimezone['request']['business_timezone'] = 'Asia Shanghai';
paymentCollectionOk('malformed payment business timezone is rejected before materialization',
    paymentCollectionReason(static function () use ($invalidTimezone, $validOrder): void {
        CashierV3PaymentCollectionPlanV1::fromLockedCheckoutAggregate(
            $invalidTimezone,
            $validOrder,
            paymentCollectionIdempotency('CHECKOUT', 99),
            1785283250,
            1785283260,
            1785283261,
            'payment-collection-local-secret-32-bytes'
        );
    }) === 'payment_collection_business_timezone_invalid');
$unsupportedTimezone = paymentCollectionLockedAggregate();
$unsupportedTimezone['request']['business_timezone'] = 'Asia/Tokyo';
paymentCollectionOk('payment authority accepts only the fixed Asia Shanghai timezone',
    paymentCollectionReason(static function () use ($unsupportedTimezone, $validOrder): void {
        CashierV3PaymentCollectionPlanV1::fromLockedCheckoutAggregate(
            $unsupportedTimezone,
            $validOrder,
            paymentCollectionIdempotency('CHECKOUT', 99),
            1785283250,
            1785283260,
            1785283261,
            'payment-collection-local-secret-32-bytes'
        );
    }) === 'payment_collection_business_timezone_not_supported');

paymentCollectionOk('collection fingerprints are canonical sha256 values',
    preg_match('/^[0-9a-f]{64}$/D', $collections[0]['immutable_fingerprint']) === 1
        && hash_equals(
            $collections[0]['immutable_fingerprint'],
            CashierV3CheckoutSettlementCanonicalizer::fingerprint(array_diff_key(
                $collections[0],
                ['immutable_fingerprint' => true]
            ))
        ));

echo "PAYMENT_COLLECTION_AUTHORITY_CONTRACT passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
