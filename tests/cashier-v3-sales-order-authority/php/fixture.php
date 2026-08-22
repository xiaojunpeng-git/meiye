<?php

use app\services\cashier\v3\settlement\CashierV3CheckoutSettlementCanonicalizer;
use app\services\cashier\v3\settlement\CashierV3CheckoutVerifiedSourceSet;

function salesOrderAuthorityOpaqueId(string $prefix, int $number): string
{
    return $prefix . '-' . str_pad(dechex($number), 40, '0', STR_PAD_LEFT);
}

function salesOrderAuthorityIdempotency(string $prefix, int $number): string
{
    return sprintf('%s-00000000-0000-4000-8000-%012d', $prefix, $number);
}

function salesOrderAuthoritySaleLine(
    int $index,
    string $type,
    int $original,
    int $discount,
    int $quantity,
    int $debt = 0
): array {
    $sourceId = 100 + $index;
    $sourceVersion = 10 + $index;
    $categoryId = 20 + $index;
    $catalogSkuId = $type === 'product' ? 1000 + $index : 0;
    $authority = [
        'authorityKey' => 'sale-authority-' . $index,
        'saleClassification' => 'formal_sale',
        'sourceType' => $type,
        'sourceId' => $sourceId,
        'sourceVersion' => $sourceVersion,
        'quantity' => $quantity,
        'originalAmountCents' => $original,
        'discountAmountCents' => $discount,
        'couponUserId' => 0,
        'couponNameSnapshot' => '',
        'couponDiscountCents' => 0,
        'saleAmountCents' => $original - $discount,
        'debtAmountCents' => $debt,
        'sourceNameSnapshot' => ['产品 A', '卡项 B', '项目 C'][$index - 1],
        'sourceCodeSnapshot' => strtoupper($type) . '-00' . $index,
        'categoryIdSnapshot' => $categoryId,
        'categoryNameSnapshot' => '分类 ' . $index,
        'configuredCostCents' => 0,
        'priceChangeReason' => '',
        'priceChangedBy' => 0,
        'priceChangedByNameSnapshot' => '',
        'priceChangedAt' => 0,
        'serviceObject' => $type === 'project' ? 'self' : '',
        'friendCountsAsCustomer' => 1,
        'isExperience' => 0,
        'craftsmen' => [],
        'salespeople' => [],
        'guideSelections' => [],
        'salesManagerSelections' => [],
    ];
    if ($type === 'card') {
        // Card sale lines must carry the immutable purchase snapshot required
        // by the settlement authority. Keep the fixture fingerprint aligned
        // with the locked line shape instead of weakening production checks.
        $authority['cardPurchaseSnapshot'] = [
            'sourceKind' => 'card_package',
            'snapshotVersion' => 1,
        ];
    }
    if ($catalogSkuId > 0) {
        $authority['catalogSkuId'] = $catalogSkuId;
    }
    $lineFingerprint = CashierV3CheckoutSettlementCanonicalizer::fingerprint($authority);
    return [
        'id' => $index,
        'line_id' => salesOrderAuthorityOpaqueId('CKL', $index),
        'request_id' => salesOrderAuthorityOpaqueId('CKR', 1),
        'draft_version' => 7,
        'draft_status' => 'draft',
        'tenant_id' => 'tenant-local',
        'store_id' => 8,
        'member_id' => 0,
        'line_role' => 'sale',
        'authority_key' => $authority['authorityKey'],
        'source_kind' => $type,
        'source_type' => $type,
        'source_id' => $sourceId,
        'catalog_sku_id' => $catalogSkuId,
        'entitlement_source_detail_id' => 0,
        'source_version' => $sourceVersion,
        'project_id' => $type === 'project' ? $sourceId : 0,
        'project_version' => $type === 'project' ? $sourceVersion : 0,
        'service_object' => $type === 'project' ? 'self' : '',
        'friend_counts_as_customer' => 1,
        'is_experience' => 0,
        'is_presale' => 0,
        'inventory_outbound_required' => 1,
        'quantity' => $quantity,
        'original_amount_cents' => $original,
        'discount_amount_cents' => $discount,
        'coupon_user_id' => 0,
        'coupon_name_snapshot' => '',
        'coupon_discount_cents' => 0,
        'sale_amount_cents' => $original - $discount,
        'debt_amount_cents' => $debt,
        'entitlement_actual_amount_cents' => 0,
        'source_name_snapshot' => $authority['sourceNameSnapshot'],
        'source_code_snapshot' => $authority['sourceCodeSnapshot'],
        'project_name_snapshot' => $type === 'project' ? $authority['sourceNameSnapshot'] : '',
        'category_id_snapshot' => $categoryId,
        'category_name_snapshot' => $authority['categoryNameSnapshot'],
        'line_fingerprint' => $lineFingerprint,
        'configured_cost_cents' => 0,
        'price_change_reason' => '',
        'price_changed_by' => 0,
        'price_changed_by_name_snapshot' => '',
        'price_changed_at' => 0,
        'craftsmen_snapshot_json' => '',
        'salespeople_snapshot_json' => '[]',
        'guide_selections_json' => '[]',
        'sales_manager_selections_json' => '[]',
        'manual_labor_fee_cents' => null,
        'card_purchase_snapshot_json' => $type === 'card'
            ? json_encode([
                'sourceKind' => 'card_package',
                'snapshotVersion' => 1,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : '',
        'sort_no' => $index,
        'add_time' => 1785283200,
        'update_time' => 1785283200,
    ];
}

function salesOrderAuthorityEntitlementLine(int $sortNo, int $memberId): array
{
    $authority = [
        'authorityKey' => 'entitlement-authority-1',
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
        'id' => 50,
        'line_id' => salesOrderAuthorityOpaqueId('CKL', 50),
        'request_id' => salesOrderAuthorityOpaqueId('CKR', 1),
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
        'service_object' => 'self',
        'friend_counts_as_customer' => 1,
        'is_experience' => 0,
        'is_presale' => 0,
        'inventory_outbound_required' => 1,
        'quantity' => 1,
        'original_amount_cents' => 0,
        'discount_amount_cents' => 0,
        'coupon_user_id' => 0,
        'coupon_name_snapshot' => '',
        'coupon_discount_cents' => 0,
        'sale_amount_cents' => 0,
        'debt_amount_cents' => 0,
        'entitlement_actual_amount_cents' => 1200,
        'source_name_snapshot' => '护理次卡',
        'source_code_snapshot' => 'CARD-701',
        'project_name_snapshot' => '面部护理',
        'category_id_snapshot' => 31,
        'category_name_snapshot' => '护理',
        'line_fingerprint' => CashierV3CheckoutSettlementCanonicalizer::fingerprint($authority),
        'configured_cost_cents' => 0,
        'price_change_reason' => '',
        'price_changed_by' => 0,
        'price_changed_by_name_snapshot' => '',
        'price_changed_at' => 0,
        'craftsmen_snapshot_json' => '[]',
        'salespeople_snapshot_json' => '[]',
        'guide_selections_json' => '[]',
        'sales_manager_selections_json' => '[]',
        'manual_labor_fee_cents' => null,
        'card_purchase_snapshot_json' => '',
        'sort_no' => $sortNo,
        'add_time' => 1785283200,
        'update_time' => 1785283200,
    ];
}

function salesOrderAuthorityPayment(int $amount, int $memberId): array
{
    $authority = [
        'paymentAuthorityKey' => 'payment-authority-wechat',
        'method' => 'wechat',
        'amountCents' => $amount,
        'businessTime' => 1785283200,
        'externalTransactionNo' => 'WX-TRACE-1',
        'remark' => '结账测试',
    ];
    return [
        'id' => 1,
        'payment_draft_id' => salesOrderAuthorityOpaqueId('CKP', 1),
        'request_id' => salesOrderAuthorityOpaqueId('CKR', 1),
        'draft_version' => 7,
        'tenant_id' => 'tenant-local',
        'store_id' => 8,
        'member_id' => $memberId,
        'operator_id' => 88,
        'payment_authority_key' => 'payment-authority-wechat',
        'payment_method' => 'wechat',
        'amount_cents' => $amount,
        'external_transaction_no' => 'WX-TRACE-1',
        'remark' => '结账测试',
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
        'sort_no' => 1,
        'add_time' => 1785283200,
        'update_time' => 1785283200,
    ];
}

function salesOrderAuthorityLockedAggregate(array $options = []): array
{
    $includeSales = array_key_exists('includeSales', $options)
        ? (bool)$options['includeSales']
        : true;
    $includeEntitlement = !empty($options['includeEntitlement']);
    $memberId = array_key_exists('memberId', $options)
        ? (int)$options['memberId']
        : ($includeEntitlement ? 9 : 0);
    $lines = [];
    if ($includeSales) {
        $lineDebts = (array)($options['lineDebts'] ?? []);
        $lines[] = salesOrderAuthoritySaleLine(1, 'product', 2000, 200, 2, (int)($lineDebts[0] ?? 0));
        $lines[] = salesOrderAuthoritySaleLine(2, 'card', 5000, 500, 1, (int)($lineDebts[1] ?? 0));
        $lines[] = salesOrderAuthoritySaleLine(3, 'project', 3000, 800, 1, (int)($lineDebts[2] ?? 0));
    }
    if ($includeEntitlement) {
        $lines[] = salesOrderAuthorityEntitlementLine(count($lines) + 1, $memberId);
    }
    foreach ($lines as &$line) {
        $line['member_id'] = $memberId;
    }
    unset($line);

    $saleAmount = 0;
    foreach ($lines as $line) {
        if ($line['line_role'] === 'sale') {
            $saleAmount += (int)$line['sale_amount_cents'];
        }
    }
    $composition = $includeSales
        ? ($includeEntitlement ? 'mixed' : 'sale_only')
        : 'entitlement_only';
    $payments = $saleAmount > 0 ? [salesOrderAuthorityPayment($saleAmount, $memberId)] : [];
    $requestId = salesOrderAuthorityOpaqueId('CKR', 1);
    $prepareKey = salesOrderAuthorityIdempotency('CHECKOUT_PREPARE', 71);
    $operationFingerprint = hash('sha256', 'prepare-submission-71');
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
        'member_name_snapshot' => $memberId > 0 ? '肖君鹏' : '游客',
        'operator_id' => 88,
        'operator_name_snapshot' => '收银员 A',
        'request_version' => 7,
        'request_status' => 'ready_for_submit',
        'composition' => $composition,
        'business_date' => '2026-07-29',
        'business_timezone' => 'Asia/Shanghai',
        'operation_occurred_at' => 1785283200,
        'recorded_at' => 1785283201,
        'order_note' => '',
        'supplement_enabled' => 0,
        'supplement_reason' => '',
        'supplement_operator_id' => 0,
        'supplement_operator_name_snapshot' => '',
        'supplement_operated_at' => 0,
        'source_document_type' => 'cashier_workspace',
        'source_document_id' => 'workspace-8-88',
        'source_document_no' => 'WS-20260729-1',
        'resumed_hang_order_id' => '',
        'sales_amount_cents' => $saleAmount,
        'receivable_amount_cents' => $saleAmount,
        'selected_payment_amount_cents' => $saleAmount,
        'balance_deduction_amount_cents' => 0,
        'balance_authority_key' => '',
        'balance_account_id' => '',
        'balance_account_version' => 0,
        'debt_amount_cents' => 0,
        'debt_authority_key' => '',
        'debt_policy_version' => 0,
        'cash_performance_amount_cents' => $saleAmount,
        'entitlement_actual_amount_cents' => $includeEntitlement ? 1200 : 0,
        'authority_snapshot_version' => 19,
        'authority_fingerprint' => hash('sha256', 'authority-19'),
        'aggregate_fingerprint' => hash('sha256', 'aggregate-7-' . $composition),
        'creation_idempotency_key' => salesOrderAuthorityIdempotency('CHECKOUT_PREPARE', 1),
        'last_idempotency_key' => $prepareKey,
        'last_operation_fingerprint' => $operationFingerprint,
        'last_operation' => 'prepare_submission',
        'add_time' => 1785283100,
        'update_time' => 1785283201,
    ];
    return [
        'request' => $request,
        'lines' => $lines,
        'payments' => $payments,
        'sources' => [],
        'currentRequest' => [
            'requestId' => $requestId,
            'tenantId' => 'tenant-local',
            'workspaceId' => 'workspace-8-88',
            'version' => 7,
            'status' => 'ready_for_submit',
            'creationIdempotencyKey' => $request['creation_idempotency_key'],
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
