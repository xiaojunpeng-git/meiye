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
require __DIR__ . '/fixture.php';

use app\services\cashier\v3\order\settlement\CashierV3SalesOrderAuthorityException;
use app\services\cashier\v3\order\settlement\CashierV3SalesOrderPlanV1;
use app\services\cashier\v3\settlement\CashierV3CheckoutSettlementCanonicalizer;
use app\services\cashier\v3\settlement\CashierV3CheckoutVerifiedSourceSet;

$passed = 0;
$failed = 0;
function salesOrderAuthorityOk(string $name, bool $condition): void
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

function salesOrderAuthorityReason(callable $callback): string
{
    try {
        $callback();
    } catch (CashierV3SalesOrderAuthorityException $exception) {
        return $exception->reason();
    }
    return '';
}

function salesOrderAuthorityPlan(
    array $aggregate,
    int $commandNo = 99,
    string $secret = 'sales-order-authority-local-secret-32-bytes'
): CashierV3SalesOrderPlanV1 {
    return CashierV3SalesOrderPlanV1::fromLockedCheckoutAggregate(
        $aggregate,
        salesOrderAuthorityIdempotency('CHECKOUT', $commandNo),
        1785283250,
        1785283260,
        1785283261,
        $secret,
        'XS26072900001'
    );
}

$guest = salesOrderAuthorityPlan(salesOrderAuthorityLockedAggregate());
$guestHeader = $guest->header();
$guestLines = $guest->lines();
salesOrderAuthorityOk('guest memberId zero is a legal settled order',
    $guestHeader['member_id'] === 0 && $guestHeader['member_name_snapshot'] === '游客');
salesOrderAuthorityOk('one checkout request creates one deterministic order identity',
    preg_match('/^CSO-[0-9a-f]{40}$/D', $guestHeader['order_id']) === 1
        && $guestHeader['order_no'] === 'XS26072900001'
        && $guestHeader['natural_key'] === 'checkout_sales_order:'
            . salesOrderAuthorityOpaqueId('CKR', 1));
salesOrderAuthorityOk('product card and project lines preserve checkout line identity',
    array_column($guestLines, 'item_type') === ['product', 'card', 'project']
        && array_column($guestLines, 'checkout_line_id') === [
            salesOrderAuthorityOpaqueId('CKL', 1),
            salesOrderAuthorityOpaqueId('CKL', 2),
            salesOrderAuthorityOpaqueId('CKL', 3),
        ]);
salesOrderAuthorityOk('name code category and amount snapshots are materialized',
    $guestLines[2]['item_name_snapshot'] === '项目 C'
        && $guestLines[2]['item_code_snapshot'] === 'PROJECT-003'
        && $guestLines[2]['category_name_snapshot'] === '分类 3'
        && $guestLines[2]['original_amount_cents'] === 3000
        && $guestLines[2]['discount_amount_cents'] === 800
        && $guestLines[2]['sale_amount_cents'] === 2200);
salesOrderAuthorityOk('header totals equal immutable formal sale lines',
    $guestHeader['line_count'] === 3
        && $guestHeader['total_quantity'] === 4
        && $guestHeader['original_amount_cents'] === 10000
        && $guestHeader['discount_amount_cents'] === 1500
        && $guestHeader['sale_amount_cents'] === 8500);
salesOrderAuthorityOk('status version and reversal columns are explicitly reserved',
    $guestHeader['order_status'] === 'settled'
        && $guestHeader['order_version'] === 1
        && $guestHeader['order_direction'] === 'forward'
        && $guestHeader['reversal_of_order_id'] === ''
        && $guestLines[0]['line_version'] === 1
        && $guestLines[0]['reversal_of_line_id'] === '');

$same = salesOrderAuthorityPlan(salesOrderAuthorityLockedAggregate());
salesOrderAuthorityOk('identical locked authority has an immutable stable replay fingerprint',
    $same->fingerprint() === $guest->fingerprint()
        && $same->header() === $guest->header()
        && $same->lines() === $guest->lines());
$differentCommand = salesOrderAuthorityPlan(salesOrderAuthorityLockedAggregate(), 100);
salesOrderAuthorityOk('new command cannot create a second identity for one checkout request',
    $differentCommand->header()['order_id'] === $guestHeader['order_id']
        && $differentCommand->header()['order_no'] === $guestHeader['order_no']
        && $differentCommand->fingerprint() !== $guest->fingerprint());

$mixed = salesOrderAuthorityPlan(salesOrderAuthorityLockedAggregate([
    'includeEntitlement' => true,
    'memberId' => 9,
]));
salesOrderAuthorityOk('entitlement service stays out of formal sales-order lines',
    $mixed->header()['composition'] === 'mixed'
        && $mixed->header()['line_count'] === 3
        && count($mixed->lines()) === 3);
$entitlementOnly = salesOrderAuthorityLockedAggregate([
    'includeSales' => false,
    'includeEntitlement' => true,
    'memberId' => 9,
]);
salesOrderAuthorityOk('entitlement-only checkout does not create a zero-sale sales order',
    salesOrderAuthorityReason(static function () use ($entitlementOnly): void {
        salesOrderAuthorityPlan($entitlementOnly);
    }) === 'sales_order_formal_sale_line_required');

$unknown = salesOrderAuthorityLockedAggregate();
$unknown['clientOrderId'] = 'forged';
salesOrderAuthorityOk('client-shaped extra authority is rejected',
    salesOrderAuthorityReason(static function () use ($unknown): void {
        salesOrderAuthorityPlan($unknown);
    }) === 'locked_aggregate_shape_invalid');
$notReady = salesOrderAuthorityLockedAggregate();
$notReady['request']['request_status'] = 'editing';
$notReady['currentRequest']['status'] = 'editing';
salesOrderAuthorityOk('editing checkout cannot become a formal order',
    salesOrderAuthorityReason(static function () use ($notReady): void {
        salesOrderAuthorityPlan($notReady);
    }) === 'checkout_request_not_ready_for_sales_order');
$tampered = salesOrderAuthorityLockedAggregate();
$tampered['lines'][0]['sale_amount_cents']--;
salesOrderAuthorityOk('tampered checkout line is rejected before persistence',
    salesOrderAuthorityReason(static function () use ($tampered): void {
        salesOrderAuthorityPlan($tampered);
    }) === 'sales_order_line_amount_equation_invalid');
$missingFrozenSku = salesOrderAuthorityLockedAggregate();
$missingFrozenSku['lines'][0]['catalog_sku_id'] = 0;
salesOrderAuthorityOk('new formal product sales fail closed without a frozen SKU ID',
    salesOrderAuthorityReason(static function () use ($missingFrozenSku): void {
        salesOrderAuthorityPlan($missingFrozenSku);
    }) === 'sales_order_product_sku_required');
$oldCard = salesOrderAuthorityLockedAggregate();
$oldCard['payments'][0]['payment_method'] = 'old_card_entry';
salesOrderAuthorityOk('legacy old-card entry cannot become sales-order settlement payment',
    salesOrderAuthorityReason(static function () use ($oldCard): void {
        salesOrderAuthorityPlan($oldCard);
    }) === 'locked_checkout_payment_method_invalid');
$guestEntitlement = salesOrderAuthorityLockedAggregate([
    'includeEntitlement' => true,
    'memberId' => 0,
]);
salesOrderAuthorityOk('guest cannot consume member entitlement during checkout',
    salesOrderAuthorityReason(static function () use ($guestEntitlement): void {
        salesOrderAuthorityPlan($guestEntitlement);
    }) === 'entitlement_checkout_member_required');
$badSecret = salesOrderAuthorityLockedAggregate();
salesOrderAuthorityOk('server namespace secret must not be a short client value',
    salesOrderAuthorityReason(static function () use ($badSecret): void {
        salesOrderAuthorityPlan($badSecret, 99, 'short');
    }) === 'sales_order_server_id_secret_invalid');
$invalidTimezone = salesOrderAuthorityLockedAggregate();
$invalidTimezone['request']['business_timezone'] = 'Asia Shanghai';
salesOrderAuthorityOk('malformed business timezone is rejected before order materialization',
    salesOrderAuthorityReason(static function () use ($invalidTimezone): void {
        salesOrderAuthorityPlan($invalidTimezone);
    }) === 'checkout_business_timezone_invalid');
$unsupportedTimezone = salesOrderAuthorityLockedAggregate();
$unsupportedTimezone['request']['business_timezone'] = 'Asia/Tokyo';
salesOrderAuthorityOk('only the fixed Asia Shanghai business timezone is accepted',
    salesOrderAuthorityReason(static function () use ($unsupportedTimezone): void {
        salesOrderAuthorityPlan($unsupportedTimezone);
    }) === 'checkout_business_timezone_not_supported');
$badTime = salesOrderAuthorityLockedAggregate();
salesOrderAuthorityOk('settled and recorded times cannot precede locked checkout preparation',
    salesOrderAuthorityReason(static function () use ($badTime): void {
        CashierV3SalesOrderPlanV1::fromLockedCheckoutAggregate(
            $badTime,
            salesOrderAuthorityIdempotency('CHECKOUT', 99),
            1785283090,
            1785283100,
            1785283101,
            'sales-order-authority-local-secret-32-bytes',
            'XS26072900001'
        );
    }) === 'sales_order_final_times_invalid');
$wrongSources = salesOrderAuthorityLockedAggregate();
$wrongSources['verifiedSources'] = CashierV3CheckoutVerifiedSourceSet::fromServerVerifiedAuthorityRows(
    'tenant-local',
    8,
    [[
        'tenantId' => 'tenant-local',
        'storeId' => 8,
        'kind' => 'room',
        'id' => 'room-1',
        'sourceVersion' => 2,
        'role' => 'checkout_source',
    ]]
);
salesOrderAuthorityOk('verified source set must match locked source rows',
    salesOrderAuthorityReason(static function () use ($wrongSources): void {
        salesOrderAuthorityPlan($wrongSources);
    }) === 'locked_checkout_verified_sources_mismatch');

echo "SALES_ORDER_AUTHORITY_CONTRACT passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
