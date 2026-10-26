<?php

use app\services\cashier\v3\order\settlement\CashierV3SalesOrderPlanV1;
use app\services\cashier\v3\settlement\CashierV3CheckoutSettlementCanonicalizer;
use app\services\cashier\v3\settlement\CashierV3CheckoutVerifiedSourceSet;

function paymentCollectionOpaqueId(string $prefix, int $number): string
{
    return $prefix . '-' . substr(hash('sha256', $prefix . ':' . $number), 0, 40);
}

function paymentCollectionIdempotency(string $prefix, int $number): string
{
    return $prefix . '-00000000-0000-4000-8000-' . str_pad((string)$number, 12, '0', STR_PAD_LEFT);
}

function paymentCollectionSaleLine(int $amount, int $memberId): array
{
    $authority = [
        'authorityKey' => 'sale:product:201',
        'saleClassification' => 'formal_sale',
        'sourceType' => 'product',
        'sourceId' => 201,
        'catalogSkuId' => 1201,
        'sourceVersion' => 5,
        'quantity' => 1,
        'originalAmountCents' => $amount + 500,
        'discountAmountCents' => 500,
        'saleAmountCents' => $amount,
        'sourceNameSnapshot' => '护理产品',
        'sourceCodeSnapshot' => 'PRODUCT-201',
        'categoryIdSnapshot' => 11,
        'categoryNameSnapshot' => '护理产品',
        'serviceObject' => '',
        'isExperience' => 0,
    ];
    return [
        'id' => 1,
        'line_id' => paymentCollectionOpaqueId('CKL', 1),
        'request_id' => paymentCollectionOpaqueId('CKR', 1),
        'draft_version' => 7,
        'draft_status' => 'draft',
        'tenant_id' => 'tenant-local',
        'store_id' => 8,
        'member_id' => $memberId,
        'line_role' => 'sale',
        'authority_key' => 'sale:product:201',
        'source_kind' => 'product',
        'source_type' => 'product',
        'source_id' => 201,
        'catalog_sku_id' => 1201,
        'entitlement_source_detail_id' => 0,
        'source_version' => 5,
        'project_id' => 0,
        'project_version' => 0,
        'service_object' => '',
        'is_experience' => 0,
        'quantity' => 1,
        'original_amount_cents' => $amount + 500,
        'discount_amount_cents' => 500,
        'sale_amount_cents' => $amount,
        'entitlement_actual_amount_cents' => 0,
        'source_name_snapshot' => '护理产品',
        'source_code_snapshot' => 'PRODUCT-201',
        'project_name_snapshot' => '',
        'category_id_snapshot' => 11,
        'category_name_snapshot' => '护理产品',
        'line_fingerprint' => CashierV3CheckoutSettlementCanonicalizer::fingerprint($authority),
        'sort_no' => 1,
        'add_time' => 1785283200,
        'update_time' => 1785283201,
    ];
}

function paymentCollectionEntitlementLine(int $memberId): array
{
    $authority = [
        'authorityKey' => 'entitlement-authority-701',
        'sourceKind' => 'count_card',
        'holderId' => 701,
        'entitlementSourceDetailId' => 702,
        'sourceVersion' => 9,
        'projectId' => 703,
        'projectVersion' => 11,
        'quantity' => 1,
        'actualEntitlementAmountCents' => 1200,
        'sourceNameSnapshot' => '护理次卡',
        'sourceCodeSnapshot' => 'CARD-701',
        'projectNameSnapshot' => '面部护理',
        'projectCategoryIdSnapshot' => 31,
        'projectCategoryNameSnapshot' => '护理',
    ];
    return [
        'id' => 2,
        'line_id' => paymentCollectionOpaqueId('CKL', 2),
        'request_id' => paymentCollectionOpaqueId('CKR', 1),
        'draft_version' => 7,
        'draft_status' => 'draft',
        'tenant_id' => 'tenant-local',
        'store_id' => 8,
        'member_id' => $memberId,
        'line_role' => 'entitlement_service',
        'authority_key' => $authority['authorityKey'],
        'source_kind' => 'count_card',
        'source_type' => 'entitlement_project',
        'source_id' => 701,
        'catalog_sku_id' => 0,
        'entitlement_source_detail_id' => 702,
        'source_version' => 9,
        'project_id' => 703,
        'project_version' => 11,
        'service_object' => '',
        'is_experience' => 0,
        'quantity' => 1,
        'original_amount_cents' => 0,
        'discount_amount_cents' => 0,
        'sale_amount_cents' => 0,
        'entitlement_actual_amount_cents' => 1200,
        'source_name_snapshot' => '护理次卡',
        'source_code_snapshot' => 'CARD-701',
        'project_name_snapshot' => '面部护理',
        'category_id_snapshot' => 31,
        'category_name_snapshot' => '护理',
        'line_fingerprint' => CashierV3CheckoutSettlementCanonicalizer::fingerprint($authority),
        'sort_no' => 2,
        'add_time' => 1785283200,
        'update_time' => 1785283201,
    ];
}

function paymentCollectionPayment(
    int $number,
    string $method,
    int $amount,
    int $memberId
): array {
    $authorityKey = 'payment:' . $method . ':' . $number;
    $authority = [
        'paymentAuthorityKey' => $authorityKey,
        'method' => $method,
        'amountCents' => $amount,
        'businessTime' => 1785283200,
        'externalTransactionNo' => strtoupper($method) . '-TRACE-' . $number,
        'remark' => '第' . $number . '种记账收款',
    ];
    return [
        'id' => $number,
        'payment_draft_id' => paymentCollectionOpaqueId('CKP', $number),
        'request_id' => paymentCollectionOpaqueId('CKR', 1),
        'draft_version' => 7,
        'tenant_id' => 'tenant-local',
        'store_id' => 8,
        'member_id' => $memberId,
        'operator_id' => 88,
        'payment_authority_key' => $authorityKey,
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
        'sort_no' => $number,
        'add_time' => 1785283200,
        'update_time' => 1785283201,
    ];
}

function paymentCollectionLockedAggregate(
    ?array $methods = null,
    int $memberId = 0,
    bool $includeEntitlement = false
): array
{
    if ($includeEntitlement && $memberId <= 0) {
        $memberId = 9;
    }
    $methods = $methods ?: [
        ['wechat', 5000],
        ['alipay', 3500],
    ];
    $payments = [];
    $total = 0;
    foreach ($methods as $index => $specification) {
        $amount = (int)$specification[1];
        $payments[] = paymentCollectionPayment(
            $index + 1,
            (string)$specification[0],
            $amount,
            $memberId
        );
        $total += $amount;
    }
    $requestId = paymentCollectionOpaqueId('CKR', 1);
    $creationKey = paymentCollectionIdempotency('CHECKOUT_PREPARE', 1);
    $prepareKey = paymentCollectionIdempotency('CHECKOUT_PREPARE', 71);
    $operationFingerprint = hash('sha256', 'prepare-payment-collection-71');
    $request = [
        'id' => 1,
        'request_id' => $requestId,
        'tenant_id' => 'tenant-local',
        'organization_id' => 'org-8',
        'organization_path' => '/8/',
        'organization_name_snapshot' => '华东组织',
        'workspace_id' => 'workspace-8-88',
        'state_context_id' => 'state-context-1',
        'store_id' => 8,
        'store_name_snapshot' => '人民路店',
        'member_id' => $memberId,
        'member_name_snapshot' => $memberId > 0 ? '会员 A' : '游客',
        'operator_id' => 88,
        'operator_name_snapshot' => '收银员 A',
        'request_version' => 7,
        'request_status' => 'ready_for_submit',
        'composition' => $includeEntitlement ? 'mixed' : 'sale_only',
        'business_date' => '2026-07-29',
        'business_timezone' => 'Asia/Shanghai',
        'operation_occurred_at' => 1785283200,
        'recorded_at' => 1785283201,
        'source_document_type' => 'cashier_workspace',
        'source_document_id' => 'workspace-8-88',
        'source_document_no' => 'WS-20260729-1',
        'sales_amount_cents' => $total,
        'receivable_amount_cents' => $total,
        'selected_payment_amount_cents' => $total,
        'balance_deduction_amount_cents' => 0,
        'balance_authority_key' => '',
        'balance_account_id' => '',
        'balance_account_version' => 0,
        'debt_amount_cents' => 0,
        'debt_authority_key' => '',
        'debt_policy_version' => 0,
        'cash_performance_amount_cents' => $total,
        'entitlement_actual_amount_cents' => $includeEntitlement ? 1200 : 0,
        'authority_snapshot_version' => 19,
        'authority_fingerprint' => hash('sha256', 'payment-authority-19'),
        'aggregate_fingerprint' => hash(
            'sha256',
            'payment-aggregate-7-' . $total . '-' . ($includeEntitlement ? 'mixed' : 'sale_only')
        ),
        'creation_idempotency_key' => $creationKey,
        'last_idempotency_key' => $prepareKey,
        'last_operation_fingerprint' => $operationFingerprint,
        'last_operation' => 'prepare_submission',
        'add_time' => 1785283100,
        'update_time' => 1785283201,
    ];
    return [
        'request' => $request,
        'lines' => array_merge(
            [paymentCollectionSaleLine($total, $memberId)],
            $includeEntitlement ? [paymentCollectionEntitlementLine($memberId)] : []
        ),
        'payments' => $payments,
        'sources' => [],
        'currentRequest' => [
            'requestId' => $requestId,
            'tenantId' => 'tenant-local',
            'workspaceId' => 'workspace-8-88',
            'version' => 7,
            'status' => 'ready_for_submit',
            'creationIdempotencyKey' => $creationKey,
            'lastIdempotencyKey' => $prepareKey,
            'lastOperationFingerprint' => $operationFingerprint,
        ],
        'verifiedSources' => CashierV3CheckoutVerifiedSourceSet::fromServerVerifiedAuthorityRows(
            'tenant-local',
            8,
            []
        ),
    ];
}

function paymentCollectionSalesOrder(
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
