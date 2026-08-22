<?php

use app\services\cashier\v3\order\settlement\CashierV3SalesOrderPlanV1;
use app\services\cashier\v3\settlement\CashierV3CheckoutSettlementCanonicalizer;
use app\services\cashier\v3\settlement\payment\CashierV3PaymentCollectionPlanV1;

require_once dirname(__DIR__, 3) . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutCraftsmenSnapshot.php';

function saleOnlyFactPayment(
    int $index,
    string $method,
    int $amount,
    int $memberId
): array {
    $authority = [
        'paymentAuthorityKey' => 'payment-authority-' . $method,
        'method' => $method,
        'amountCents' => $amount,
        'businessTime' => 1785283200,
        'externalTransactionNo' => strtoupper($method) . '-TRACE-' . $index,
        'remark' => '结账测试 ' . $index,
    ];
    return [
        'id' => $index,
        'payment_draft_id' => salesOrderAuthorityOpaqueId('CKP', $index),
        'request_id' => salesOrderAuthorityOpaqueId('CKR', 1),
        'draft_version' => 7,
        'tenant_id' => 'tenant-local',
        'store_id' => 8,
        'member_id' => $memberId,
        'operator_id' => 88,
        'payment_authority_key' => $authority['paymentAuthorityKey'],
        'payment_method' => $method,
        'amount_cents' => $amount,
        'external_transaction_no' => $authority['externalTransactionNo'],
        'remark' => $authority['remark'],
        'business_date' => '2026-07-29',
        'business_timezone' => 'Asia/Shanghai',
        'operation_occurred_at' => 1785283200,
        'recorded_at' => 1785283201,
        'operator_name_snapshot' => '收银员 A',
        'source_document_type' => 'cashier_workspace',
        'source_document_id' => 'workspace-8-88',
        'source_document_no' => 'WS-20260729-1',
        'payment_fingerprint' => CashierV3CheckoutSettlementCanonicalizer::fingerprint($authority),
        'draft_status' => 'draft',
        'sort_no' => $index,
        'add_time' => 1785283200,
        'update_time' => 1785283200,
    ];
}

function saleOnlyFactLockedAggregate(): array
{
    $aggregate = salesOrderAuthorityLockedAggregate();
    $aggregate['payments'] = [
        saleOnlyFactPayment(1, 'wechat', 5000, 0),
        saleOnlyFactPayment(2, 'alipay', 3500, 0),
    ];
    return $aggregate;
}

function saleOnlyFactMixedLockedAggregate(): array
{
    $aggregate = salesOrderAuthorityLockedAggregate([
        'includeEntitlement' => true,
        'memberId' => 9,
    ]);
    $aggregate['payments'] = [
        saleOnlyFactPayment(1, 'wechat', 5000, 9),
        saleOnlyFactPayment(2, 'alipay', 3500, 9),
    ];
    return $aggregate;
}

function saleOnlyFactSalesOrder(
    array $aggregate,
    string $commandKey,
    string $secret
): CashierV3SalesOrderPlanV1 {
    return CashierV3SalesOrderPlanV1::fromLockedCheckoutAggregate(
        $aggregate,
        $commandKey,
        1785283250,
        1785283260,
        1785283261,
        $secret,
        'XS26072900001'
    );
}

function saleOnlyFactPaymentCollection(
    array $aggregate,
    CashierV3SalesOrderPlanV1 $salesOrder,
    string $commandKey,
    string $secret
): CashierV3PaymentCollectionPlanV1 {
    return CashierV3PaymentCollectionPlanV1::fromLockedCheckoutAggregate(
        $aggregate,
        $salesOrder,
        $commandKey,
        1785283250,
        1785283260,
        1785283261,
        $secret
    );
}

function saleOnlyFactSalesResult(
    CashierV3SalesOrderPlanV1 $plan,
    bool $replayed = false
): array {
    $header = $plan->header();
    return [
        'contractVersion' => CashierV3SalesOrderPlanV1::CONTRACT_VERSION,
        'orderId' => $header['order_id'],
        'orderNo' => $header['order_no'],
        'checkoutRequestId' => $header['checkout_request_id'],
        'orderStatus' => $header['order_status'],
        'orderVersion' => $header['order_version'],
        'planFingerprint' => $plan->fingerprint(),
        'replayed' => $replayed,
        'affected' => [
            'headerRows' => $replayed ? 0 : 1,
            'lineRows' => $replayed ? 0 : count($plan->lines()),
        ],
        'businessEffectsWritten' => !$replayed,
    ];
}

function saleOnlyFactPaymentResult(
    CashierV3PaymentCollectionPlanV1 $plan,
    bool $replayed = false
): array {
    $batch = $plan->batch();
    return [
        'contractVersion' => CashierV3PaymentCollectionPlanV1::CONTRACT_VERSION,
        'batchId' => $batch['batch_id'],
        'salesOrderId' => $batch['sales_order_id'],
        'checkoutRequestId' => $batch['checkout_request_id'],
        'collectionIds' => array_column($plan->collections(), 'collection_id'),
        'collectionCount' => count($plan->collections()),
        'collectedAmountCents' => $batch['collected_amount_cents'],
        'cashPerformanceAmountCents' => $batch['cash_performance_amount_cents'],
        'planFingerprint' => $plan->fingerprint(),
        'replayed' => $replayed,
        'affected' => [
            'batchRows' => $replayed ? 0 : 1,
            'collectionRows' => $replayed ? 0 : count($plan->collections()),
        ],
        'businessEffectsWritten' => !$replayed,
    ];
}

function saleOnlyFactEvent(
    CashierV3SalesOrderPlanV1 $salesOrder,
    string $commandKey
): array {
    $order = $salesOrder->header();
    return [
        'event_id' => 901,
        'event_no' => 'EV-0123456789abcdef0123456789abcdef',
        'event_key' => 'checkout.completed:sales_order:' . $order['order_id'] . ':1',
        'event_type' => 'checkout.completed',
        'aggregate_type' => 'sales_order',
        'aggregate_id' => $order['order_id'],
        'aggregate_name_snapshot' => $order['order_no'],
        'aggregate_version' => 1,
        'source_type' => 'submit-checkout',
        'source_id' => $order['checkout_request_id'],
        'command_idempotency_key' => $commandKey,
        'business_date' => $order['business_date'],
        'occurred_at' => $order['occurred_at'],
        'settled_at' => $order['settled_at'],
        'recorded_at' => $order['recorded_at'],
    ];
}
