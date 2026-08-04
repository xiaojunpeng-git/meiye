<?php

namespace app\services\cashier\v3\order\settlement;

use app\services\cashier\v3\CashierV3BusinessDocumentNumberServices;
use app\services\cashier\v3\settlement\CashierV3CheckoutCraftsmenSnapshot;
use app\services\cashier\v3\settlement\CashierV3CheckoutSettlementCanonicalizer;
use app\services\cashier\v3\settlement\CashierV3CheckoutVerifiedSourceSet;

/**
 * Immutable server-only persistence plan for one authoritative sales order.
 *
 * The only business source is the checkout request aggregate returned while
 * its request, line, payment and source rows are locked by the final checkout
 * transaction. HTTP payloads and legacy store_order rows are never accepted.
 */
final class CashierV3SalesOrderPlanV1
{
    public const CONTRACT_VERSION = 'cashier-v3-sales-order-authority-v1';
    public const LOCKED_SOURCE_CONTRACT_VERSION = 'cashier-v3-locked-checkout-sales-source-v1';
    public const ORDER_STATUS_SETTLED = 'settled';
    public const ORDER_DIRECTION_FORWARD = 'forward';

    private const SALE_TYPES = ['product', 'card', 'project'];
    private const SALE_TYPE_LABELS = [
        'product' => '产品',
        'card' => '卡项',
        'project' => '项目',
    ];
    // 老卡来源无法可靠分类时，权益完成内核会以 unknown 保留可追溯兼容值。
    // 混合结账的销售订单计划必须接受同一受控枚举，仍由 holder/detail/version
    // 和行指纹共同校验，不能把 unknown 当作任意来源。
    private const ENTITLEMENT_KINDS = ['count_card', 'time_card', 'custom_card', 'gift', 'unknown'];
    private const PAYMENT_METHODS = [
        'unionpay', 'wechat', 'alipay', 'dianping_voucher', 'douyin_voucher',
        'partner_collection', 'other_collection',
    ];

    private const MAX_PAYMENT_ROWS = 200;
    private const COMPOSITIONS = ['sale_only', 'mixed'];
    private const MAX_MONEY_CENTS = 100000000000;
    private const MAX_QUANTITY = 1000000;

    private const REQUEST_KEYS = [
        'id', 'request_id', 'tenant_id', 'organization_id', 'organization_path',
        'organization_name_snapshot', 'workspace_id', 'state_context_id', 'store_id',
        'store_name_snapshot', 'member_id', 'member_name_snapshot', 'operator_id',
        'operator_name_snapshot', 'request_version', 'request_status', 'composition',
        'business_date', 'business_timezone', 'operation_occurred_at', 'recorded_at',
        'source_document_type', 'source_document_id', 'source_document_no',
        'sales_amount_cents', 'receivable_amount_cents', 'selected_payment_amount_cents',
        'balance_deduction_amount_cents', 'balance_authority_key', 'balance_account_id',
        'balance_account_version', 'debt_amount_cents', 'debt_authority_key',
        'debt_policy_version', 'cash_performance_amount_cents',
        'entitlement_actual_amount_cents', 'authority_snapshot_version',
        'authority_fingerprint', 'aggregate_fingerprint', 'creation_idempotency_key',
        'last_idempotency_key', 'last_operation_fingerprint', 'last_operation',
        'add_time', 'update_time',
    ];

    private const LINE_KEYS = [
        'id', 'line_id', 'request_id', 'draft_version', 'draft_status', 'tenant_id',
        'store_id', 'member_id', 'line_role', 'authority_key', 'source_kind',
        'source_type', 'source_id', 'entitlement_source_detail_id', 'source_version',
        'catalog_sku_id',
        'project_id', 'project_version', 'service_object', 'is_experience', 'quantity', 'original_amount_cents',
        'discount_amount_cents', 'sale_amount_cents', 'entitlement_actual_amount_cents',
        'source_name_snapshot', 'source_code_snapshot', 'project_name_snapshot',
        'category_id_snapshot', 'category_name_snapshot', 'line_fingerprint',
        'craftsmen_snapshot_json',
        'sort_no', 'add_time', 'update_time',
    ];

    private const PAYMENT_KEYS = [
        'id', 'payment_draft_id', 'request_id', 'draft_version', 'tenant_id', 'store_id',
        'member_id', 'operator_id', 'payment_authority_key', 'payment_method',
        'amount_cents', 'external_transaction_no', 'remark', 'business_date',
        'business_timezone', 'operation_occurred_at', 'recorded_at',
        'operator_name_snapshot', 'source_document_type', 'source_document_id',
        'source_document_no', 'payment_fingerprint', 'draft_status', 'sort_no',
        'add_time', 'update_time',
    ];

    private const SOURCE_KEYS = [
        'id', 'request_id', 'tenant_id', 'store_id', 'bound_request_version',
        'source_kind', 'source_id', 'source_version', 'source_role',
        'source_fingerprint', 'add_time', 'update_time',
    ];

    /** @var array */
    private $header;

    /** @var array<int,array> */
    private $lines;

    /** @var string */
    private $fingerprint;

    private function __construct(array $header, array $lines, string $fingerprint)
    {
        $this->header = $header;
        $this->lines = $lines;
        $this->fingerprint = $fingerprint;
    }

    /**
     * @param array{request:array,lines:array,payments:array,sources:array,currentRequest:array,verifiedSources:CashierV3CheckoutVerifiedSourceSet} $lockedAggregate
     */
    public static function fromLockedCheckoutAggregate(
        array $lockedAggregate,
        string $commandIdempotencyKey,
        int $occurredAt,
        int $settledAt,
        int $recordedAt,
        string $serverNamespaceSecret,
        string $businessDocumentNo = ''
    ): self {
        self::assertExactKeys(
            $lockedAggregate,
            ['request', 'lines', 'payments', 'sources', 'currentRequest', 'verifiedSources'],
            'locked_aggregate_shape_invalid'
        );
        if (!is_array($lockedAggregate['request'])
            || !is_array($lockedAggregate['lines'])
            || !is_array($lockedAggregate['payments'])
            || !is_array($lockedAggregate['sources'])
            || !is_array($lockedAggregate['currentRequest'])
            || !($lockedAggregate['verifiedSources'] instanceof CashierV3CheckoutVerifiedSourceSet)) {
            throw self::failure('locked_aggregate_shape_invalid');
        }

        $request = self::normalizeRequest($lockedAggregate['request']);
        self::assertCurrentRequest($lockedAggregate['currentRequest'], $request);
        self::assertIdempotencyKey($commandIdempotencyKey, 'sales_order_command_idempotency_key_invalid');
        if ($occurredAt <= 0
            || $settledAt < $occurredAt
            || $recordedAt < $settledAt
            || $occurredAt < $request['operation_occurred_at']
            || $occurredAt < $request['recorded_at']) {
            throw self::failure('sales_order_final_times_invalid');
        }

        $verifiedSources = $lockedAggregate['verifiedSources'];
        if (!hash_equals($request['tenant_id'], $verifiedSources->tenantId())
            || $request['store_id'] !== $verifiedSources->storeId()) {
            throw self::failure('sales_order_source_scope_mismatch');
        }
        self::assertSourceRows($lockedAggregate['sources'], $request, $verifiedSources);
        $paymentTotal = self::assertPaymentRows($lockedAggregate['payments'], $request);
        if ($paymentTotal !== $request['selected_payment_amount_cents']
            || $paymentTotal !== $request['cash_performance_amount_cents']) {
            throw self::failure('sales_order_payment_total_mismatch');
        }

        $lineResult = self::normalizeLines($lockedAggregate['lines'], $request);
        $saleLines = $lineResult['saleLines'];
        if (!$saleLines) {
            throw self::failure('sales_order_formal_sale_line_required');
        }
        if ($lineResult['entitlementLineCount'] > 0 && $request['member_id'] <= 0) {
            throw self::failure('entitlement_checkout_member_required');
        }
        if ($lineResult['entitlementActualAmountCents']
            !== $request['entitlement_actual_amount_cents']) {
            throw self::failure('sales_order_entitlement_trace_total_mismatch');
        }
        $saleAmount = self::sum($saleLines, 'sale_amount_cents');
        $originalAmount = self::sum($saleLines, 'original_amount_cents');
        $discountAmount = self::sum($saleLines, 'discount_amount_cents');
        if ($saleAmount !== $request['sales_amount_cents']
            || $saleAmount !== $request['receivable_amount_cents']
            || $originalAmount - $discountAmount !== $saleAmount) {
            throw self::failure('sales_order_checkout_total_mismatch');
        }
        $settlementTotal = self::safeAdd(
            self::safeAdd(
                $paymentTotal,
                $request['balance_deduction_amount_cents'],
                'sales_order_settlement_total_overflow'
            ),
            $request['debt_amount_cents'],
            'sales_order_settlement_total_overflow'
        );
        if ($settlementTotal !== $request['receivable_amount_cents']) {
            throw self::failure('sales_order_checkout_not_balanced');
        }
        self::assertComposition(
            $request['composition'],
            count($saleLines),
            $lineResult['entitlementLineCount']
        );

        $ids = new CashierV3SalesOrderIdFactory($serverNamespaceSecret);
        $orderId = $ids->orderId($request['tenant_id'], $request['request_id']);
        $orderNo = trim($businessDocumentNo);
        if (!CashierV3BusinessDocumentNumberServices::isSalesOrderNo($orderNo)) {
            throw self::failure('sales_order_document_no_invalid');
        }
        $orderLines = [];
        $totalQuantity = 0;
        foreach ($saleLines as $index => $line) {
            $totalQuantity = self::safeAdd(
                $totalQuantity,
                $line['quantity'],
                'sales_order_quantity_total_overflow'
            );
            $row = [
                'order_line_id' => $ids->lineId($orderId, $line['checkout_line_id']),
                'natural_key' => 'checkout_sales_order_line:'
                    . $request['request_id'] . ':' . $line['checkout_line_id'],
                'command_idempotency_key' => $commandIdempotencyKey,
                'order_id' => $orderId,
                'tenant_id' => $request['tenant_id'],
                'store_id' => $request['store_id'],
                'member_id' => $request['member_id'],
                'checkout_request_id' => $request['request_id'],
                'checkout_request_version' => $request['request_version'],
                'checkout_line_id' => $line['checkout_line_id'],
                'checkout_line_fingerprint' => $line['checkout_line_fingerprint'],
                'line_no' => $index + 1,
                'item_type' => $line['item_type'],
                'item_type_name_snapshot' => self::SALE_TYPE_LABELS[$line['item_type']],
                'item_id' => (string)$line['item_id'],
                'catalog_sku_id' => $line['catalog_sku_id'],
                'item_version' => $line['item_version'],
                'item_code_snapshot' => $line['item_code_snapshot'],
                'item_name_snapshot' => $line['item_name_snapshot'],
                'category_id_snapshot' => (string)$line['category_id_snapshot'],
                'category_name_snapshot' => $line['category_name_snapshot'],
                'service_object' => $line['service_object'],
                'craftsmen_snapshot_json' => $line['craftsmen_snapshot_json'],
                'is_experience' => $line['is_experience'],
                'quantity' => $line['quantity'],
                'original_amount_cents' => $line['original_amount_cents'],
                'discount_amount_cents' => $line['discount_amount_cents'],
                'sale_amount_cents' => $line['sale_amount_cents'],
                'line_status' => self::ORDER_STATUS_SETTLED,
                'line_version' => 1,
                'line_direction' => self::ORDER_DIRECTION_FORWARD,
                'reversal_of_line_id' => '',
            ];
            $row['immutable_fingerprint'] = self::canonicalFingerprint($row);
            $orderLines[] = $row;
        }

        $header = [
            'order_id' => $orderId,
            'order_no' => $orderNo,
            'natural_key' => 'checkout_sales_order:' . $request['request_id'],
            'contract_version' => self::CONTRACT_VERSION,
            'command_idempotency_key' => $commandIdempotencyKey,
            'tenant_id' => $request['tenant_id'],
            'organization_id' => $request['organization_id'],
            'organization_path_snapshot' => $request['organization_path'],
            'organization_name_snapshot' => $request['organization_name_snapshot'],
            'store_id' => $request['store_id'],
            'store_name_snapshot' => $request['store_name_snapshot'],
            'member_id' => $request['member_id'],
            'member_name_snapshot' => $request['member_name_snapshot'],
            'operator_id' => $request['operator_id'],
            'operator_name_snapshot' => $request['operator_name_snapshot'],
            'checkout_request_id' => $request['request_id'],
            'checkout_request_version' => $request['request_version'],
            'checkout_prepare_idempotency_key' => $request['last_idempotency_key'],
            'checkout_authority_fingerprint' => $request['authority_fingerprint'],
            'checkout_aggregate_fingerprint' => $request['aggregate_fingerprint'],
            'checkout_source_set_fingerprint' => $verifiedSources->fingerprint(),
            'composition' => $request['composition'],
            'business_date' => $request['business_date'],
            'business_timezone' => $request['business_timezone'],
            'occurred_at' => $occurredAt,
            'settled_at' => $settledAt,
            'recorded_at' => $recordedAt,
            'source_document_type' => $request['source_document_type'],
            'source_document_id' => $request['source_document_id'],
            'source_document_no_snapshot' => $request['source_document_no'],
            'line_count' => count($orderLines),
            'total_quantity' => $totalQuantity,
            'original_amount_cents' => $originalAmount,
            'discount_amount_cents' => $discountAmount,
            'sale_amount_cents' => $saleAmount,
            'order_status' => self::ORDER_STATUS_SETTLED,
            'order_version' => 1,
            'order_direction' => self::ORDER_DIRECTION_FORWARD,
            'reversal_of_order_id' => '',
            'reversal_reason_snapshot' => '',
        ];
        $headerFingerprintInput = $header;
        $headerFingerprintInput['line_fingerprints'] = array_column(
            $orderLines,
            'immutable_fingerprint'
        );
        $header['immutable_fingerprint'] = self::canonicalFingerprint($headerFingerprintInput);
        return new self($header, $orderLines, $header['immutable_fingerprint']);
    }

    public function header(): array
    {
        return $this->header;
    }

    /** @return array<int,array> */
    public function lines(): array
    {
        return $this->lines;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    public function checkoutRequestId(): string
    {
        return (string)$this->header['checkout_request_id'];
    }

    public function commandIdempotencyKey(): string
    {
        return (string)$this->header['command_idempotency_key'];
    }

    private static function normalizeRequest(array $request): array
    {
        self::assertExactKeys($request, self::REQUEST_KEYS, 'locked_checkout_request_shape_invalid');
        $normalized = [
            'request_id' => self::opaqueId($request['request_id'], 'CKR', 'checkout_request_id_invalid'),
            'tenant_id' => self::token($request['tenant_id'], 32, 'checkout_tenant_id_invalid'),
            'organization_id' => self::token(
                $request['organization_id'],
                32,
                'checkout_organization_id_invalid'
            ),
            'organization_path' => self::organizationPath($request['organization_path']),
            'organization_name_snapshot' => self::text(
                $request['organization_name_snapshot'],
                128,
                'checkout_organization_name_invalid',
                true
            ),
            'workspace_id' => self::token($request['workspace_id'], 64, 'checkout_workspace_id_invalid'),
            'store_id' => self::positiveInt($request['store_id'], 'checkout_store_id_invalid'),
            'store_name_snapshot' => self::text(
                $request['store_name_snapshot'],
                128,
                'checkout_store_name_invalid',
                false
            ),
            'member_id' => self::nonNegativeInt($request['member_id'], 'checkout_member_id_invalid'),
            'member_name_snapshot' => self::text(
                $request['member_name_snapshot'],
                128,
                'checkout_member_name_invalid',
                true
            ),
            'operator_id' => self::positiveInt($request['operator_id'], 'checkout_operator_id_invalid'),
            'operator_name_snapshot' => self::text(
                $request['operator_name_snapshot'],
                128,
                'checkout_operator_name_invalid',
                false
            ),
            'request_version' => self::positiveInt(
                $request['request_version'],
                'checkout_request_version_invalid'
            ),
            'request_status' => self::token(
                $request['request_status'],
                32,
                'checkout_request_status_invalid'
            ),
            'composition' => self::token($request['composition'], 24, 'checkout_composition_invalid'),
            'business_date' => self::businessDate($request['business_date']),
            'business_timezone' => self::timezone(
                $request['business_timezone'],
                'checkout_business_timezone_invalid'
            ),
            'operation_occurred_at' => self::positiveInt(
                $request['operation_occurred_at'],
                'checkout_occurred_at_invalid'
            ),
            'recorded_at' => self::positiveInt(
                $request['recorded_at'],
                'checkout_recorded_at_invalid'
            ),
            'source_document_type' => self::token(
                $request['source_document_type'],
                32,
                'checkout_source_document_type_invalid'
            ),
            'source_document_id' => self::token(
                $request['source_document_id'],
                64,
                'checkout_source_document_id_invalid'
            ),
            'source_document_no' => self::text(
                $request['source_document_no'],
                64,
                'checkout_source_document_no_invalid',
                false
            ),
            'sales_amount_cents' => self::money(
                $request['sales_amount_cents'],
                'checkout_sales_amount_invalid'
            ),
            'receivable_amount_cents' => self::money(
                $request['receivable_amount_cents'],
                'checkout_receivable_amount_invalid'
            ),
            'selected_payment_amount_cents' => self::money(
                $request['selected_payment_amount_cents'],
                'checkout_payment_amount_invalid'
            ),
            'balance_deduction_amount_cents' => self::money(
                $request['balance_deduction_amount_cents'],
                'checkout_balance_amount_invalid'
            ),
            'balance_authority_key' => self::optionalToken(
                $request['balance_authority_key'],
                96,
                'checkout_balance_authority_key_invalid'
            ),
            'balance_account_id' => self::optionalToken(
                $request['balance_account_id'],
                64,
                'checkout_balance_account_id_invalid'
            ),
            'balance_account_version' => self::nonNegativeInt(
                $request['balance_account_version'],
                'checkout_balance_account_version_invalid'
            ),
            'debt_amount_cents' => self::money(
                $request['debt_amount_cents'],
                'checkout_debt_amount_invalid'
            ),
            'debt_authority_key' => self::optionalToken(
                $request['debt_authority_key'],
                96,
                'checkout_debt_authority_key_invalid'
            ),
            'debt_policy_version' => self::nonNegativeInt(
                $request['debt_policy_version'],
                'checkout_debt_policy_version_invalid'
            ),
            'cash_performance_amount_cents' => self::money(
                $request['cash_performance_amount_cents'],
                'checkout_cash_performance_amount_invalid'
            ),
            'entitlement_actual_amount_cents' => self::money(
                $request['entitlement_actual_amount_cents'],
                'checkout_entitlement_actual_amount_invalid'
            ),
            'authority_snapshot_version' => self::positiveInt(
                $request['authority_snapshot_version'],
                'checkout_authority_snapshot_version_invalid'
            ),
            'authority_fingerprint' => self::sha256(
                $request['authority_fingerprint'],
                'checkout_authority_fingerprint_invalid'
            ),
            'aggregate_fingerprint' => self::sha256(
                $request['aggregate_fingerprint'],
                'checkout_aggregate_fingerprint_invalid'
            ),
            'last_idempotency_key' => (string)$request['last_idempotency_key'],
            'creation_idempotency_key' => (string)$request['creation_idempotency_key'],
            'last_operation_fingerprint' => self::sha256(
                $request['last_operation_fingerprint'],
                'checkout_operation_fingerprint_invalid'
            ),
            'last_operation' => self::token(
                $request['last_operation'],
                32,
                'checkout_last_operation_invalid'
            ),
        ];
        self::assertIdempotencyKey(
            $normalized['last_idempotency_key'],
            'checkout_prepare_idempotency_key_invalid',
            true
        );
        self::assertIdempotencyKey(
            $normalized['creation_idempotency_key'],
            'checkout_creation_idempotency_key_invalid',
            true
        );
        if ($normalized['request_status'] !== 'ready_for_submit'
            || $normalized['last_operation'] !== 'prepare_submission') {
            throw self::failure('checkout_request_not_ready_for_sales_order');
        }
        if ($normalized['recorded_at'] < $normalized['operation_occurred_at']) {
            throw self::failure('checkout_request_times_invalid');
        }
        if ($normalized['business_timezone'] !== 'Asia/Shanghai') {
            throw self::failure('checkout_business_timezone_not_supported');
        }
        if ($normalized['composition'] === 'entitlement_only') {
            throw self::failure('sales_order_formal_sale_line_required');
        }
        if (!in_array($normalized['composition'], self::COMPOSITIONS, true)) {
            throw self::failure('checkout_composition_invalid');
        }
        if ($normalized['member_id'] > 0 && $normalized['member_name_snapshot'] === '') {
            throw self::failure('checkout_member_snapshot_incomplete');
        }
        if ($normalized['member_id'] === 0
            && ($normalized['balance_deduction_amount_cents'] !== 0
                || $normalized['debt_amount_cents'] !== 0)) {
            throw self::failure('guest_checkout_member_settlement_forbidden');
        }
        if ($normalized['balance_deduction_amount_cents'] === 0) {
            if ($normalized['balance_authority_key'] !== ''
                || $normalized['balance_account_id'] !== ''
                || $normalized['balance_account_version'] !== 0) {
                throw self::failure('zero_balance_authority_must_be_empty');
            }
        } elseif ($normalized['member_id'] <= 0
            || $normalized['balance_authority_key'] === ''
            || $normalized['balance_account_id'] === ''
            || $normalized['balance_account_version'] <= 0) {
            throw self::failure('checkout_balance_authority_incomplete');
        }
        if ($normalized['debt_amount_cents'] === 0) {
            if ($normalized['debt_authority_key'] !== ''
                || $normalized['debt_policy_version'] !== 0) {
                throw self::failure('zero_debt_authority_must_be_empty');
            }
        } elseif ($normalized['member_id'] <= 0
            || $normalized['debt_authority_key'] === ''
            || $normalized['debt_policy_version'] <= 0) {
            throw self::failure('checkout_debt_authority_incomplete');
        }
        return $normalized;
    }

    private static function assertCurrentRequest(array $current, array $request): void
    {
        self::assertExactKeys($current, [
            'requestId', 'tenantId', 'workspaceId', 'version', 'status',
            'creationIdempotencyKey', 'lastIdempotencyKey', 'lastOperationFingerprint',
        ], 'locked_checkout_current_shape_invalid');
        if (!hash_equals($request['request_id'], (string)$current['requestId'])
            || !hash_equals($request['tenant_id'], (string)$current['tenantId'])
            || !hash_equals($request['workspace_id'], (string)$current['workspaceId'])
            || $request['request_version'] !== self::positiveInt(
                $current['version'],
                'locked_checkout_current_version_invalid'
            )
            || !hash_equals($request['request_status'], (string)$current['status'])
            || !hash_equals(
                $request['creation_idempotency_key'],
                (string)$current['creationIdempotencyKey']
            )
            || !hash_equals($request['last_idempotency_key'], (string)$current['lastIdempotencyKey'])
            || !hash_equals(
                $request['last_operation_fingerprint'],
                (string)$current['lastOperationFingerprint']
            )) {
            throw self::failure('locked_checkout_current_request_mismatch');
        }
    }

    private static function normalizeLines(array $rows, array $request): array
    {
        if (!$rows || count($rows) > 200) {
            throw self::failure('locked_checkout_line_count_invalid');
        }
        $saleLines = [];
        $entitlementLineCount = 0;
        $entitlementActualAmountCents = 0;
        $identities = [];
        $sortNumbers = [];
        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) {
                throw self::failure('locked_checkout_line_shape_invalid', ['index' => $index]);
            }
            self::assertExactKeys($row, self::LINE_KEYS, 'locked_checkout_line_shape_invalid');
            self::assertChildBinding($row, $request, 'line', $index);
            $lineId = self::opaqueId($row['line_id'], 'CKL', 'checkout_line_id_invalid');
            $sortNo = self::positiveInt($row['sort_no'], 'checkout_line_sort_invalid');
            if (isset($identities[$lineId]) || isset($sortNumbers[$sortNo])) {
                throw self::failure('locked_checkout_line_duplicate', ['index' => $index]);
            }
            $identities[$lineId] = true;
            $sortNumbers[$sortNo] = true;
            $role = (string)$row['line_role'];
            if ($role === 'sale') {
                $saleLines[] = self::normalizeSaleLine($row, $lineId, $sortNo);
                continue;
            }
            if ($role !== 'entitlement_service') {
                throw self::failure('checkout_line_role_invalid', ['index' => $index]);
            }
            $entitlementActualAmountCents = self::safeAdd(
                $entitlementActualAmountCents,
                self::assertEntitlementLine($row),
                'entitlement_actual_amount_total_overflow'
            );
            $entitlementLineCount++;
        }
        usort($saleLines, static function (array $left, array $right): int {
            return $left['sort_no'] <=> $right['sort_no'];
        });
        return [
            'saleLines' => $saleLines,
            'entitlementLineCount' => $entitlementLineCount,
            'entitlementActualAmountCents' => $entitlementActualAmountCents,
        ];
    }

    private static function normalizeSaleLine(array $row, string $lineId, int $sortNo): array
    {
        $sourceType = (string)$row['source_type'];
        if (!in_array($sourceType, self::SALE_TYPES, true)
            || (string)$row['source_kind'] !== $sourceType
            || self::nonNegativeInt(
                $row['entitlement_source_detail_id'],
                'sale_line_entitlement_detail_invalid'
            ) !== 0) {
            throw self::failure('sales_order_line_type_invalid');
        }
        $sourceId = self::positiveInt($row['source_id'], 'sales_order_item_id_invalid');
        $sourceVersion = self::positiveInt(
            $row['source_version'],
            'sales_order_item_version_invalid'
        );
        $catalogSkuId = self::nonNegativeInt(
            $row['catalog_sku_id'],
            'sales_order_catalog_sku_invalid'
        );
        if ($sourceType === 'product' && $catalogSkuId <= 0) {
            throw self::failure('sales_order_product_sku_required');
        }
        $quantity = self::quantity($row['quantity'], 'sales_order_quantity_invalid');
        $original = self::money($row['original_amount_cents'], 'sales_order_original_amount_invalid');
        $discount = self::money($row['discount_amount_cents'], 'sales_order_discount_amount_invalid');
        $sale = self::money($row['sale_amount_cents'], 'sales_order_sale_amount_invalid');
        if ($discount > $original || $sale !== $original - $discount) {
            throw self::failure('sales_order_line_amount_equation_invalid');
        }
        $categoryId = self::nonNegativeInt(
            $row['category_id_snapshot'],
            'sales_order_category_id_invalid'
        );
        $categoryName = self::text(
            $row['category_name_snapshot'],
            128,
            'sales_order_category_name_invalid',
            true
        );
        if ($categoryId > 0 && $categoryName === '') {
            throw self::failure('sales_order_category_snapshot_incomplete');
        }
        if ($sourceType === 'project') {
            if (self::positiveInt($row['project_id'], 'sales_order_project_id_invalid') !== $sourceId
                || self::positiveInt(
                    $row['project_version'],
                    'sales_order_project_version_invalid'
                ) !== $sourceVersion) {
                throw self::failure('sales_order_project_binding_invalid');
            }
        } elseif (self::nonNegativeInt($row['project_id'], 'sales_order_project_id_invalid') !== 0
            || self::nonNegativeInt(
                $row['project_version'],
                'sales_order_project_version_invalid'
            ) !== 0) {
            throw self::failure('sales_order_non_project_binding_invalid');
        }
        $serviceObject = trim((string)$row['service_object']);
        $isExperience = self::nonNegativeInt($row['is_experience'], 'sales_order_is_experience_invalid');
        $craftsmenJson = $row['craftsmen_snapshot_json'];
        $craftsmen = self::craftsmenSnapshot($craftsmenJson);
        if ($isExperience > 1) {
            throw self::failure('sales_order_is_experience_invalid');
        }
        if ($sourceType === 'project') {
            if (!in_array($serviceObject, ['self', 'friend'], true)) {
                throw self::failure('sales_order_project_service_object_invalid');
            }
        } elseif ($serviceObject !== '' || $isExperience !== 0 || $craftsmen !== []) {
            throw self::failure('sales_order_non_project_service_tags_invalid');
        }
        $authority = [
            'authorityKey' => self::token(
                $row['authority_key'],
                96,
                'sales_order_authority_key_invalid'
            ),
            'saleClassification' => 'formal_sale',
            'sourceType' => $sourceType,
            'sourceId' => $sourceId,
            'catalogSkuId' => $catalogSkuId,
            'sourceVersion' => $sourceVersion,
            'quantity' => $quantity,
            'originalAmountCents' => $original,
            'discountAmountCents' => $discount,
            'saleAmountCents' => $sale,
            'sourceNameSnapshot' => self::text(
                $row['source_name_snapshot'],
                128,
                'sales_order_item_name_invalid',
                false
            ),
            'sourceCodeSnapshot' => self::text(
                $row['source_code_snapshot'],
                64,
                'sales_order_item_code_invalid',
                true
            ),
            'categoryIdSnapshot' => $categoryId,
            'categoryNameSnapshot' => $categoryName,
            'serviceObject' => $serviceObject,
            'isExperience' => $isExperience,
        ];
        if (!CashierV3CheckoutCraftsmenSnapshot::isLegacyEmpty($craftsmenJson)) {
            $authority['craftsmen'] = $craftsmen;
        }
        if ($catalogSkuId <= 0) {
            unset($authority['catalogSkuId']);
        }
        $lineFingerprint = self::sha256(
            $row['line_fingerprint'],
            'sales_order_checkout_line_fingerprint_invalid'
        );
        if (!hash_equals($lineFingerprint, self::canonicalFingerprint($authority))) {
            throw self::failure('sales_order_checkout_line_fingerprint_mismatch');
        }
        return [
            'checkout_line_id' => $lineId,
            'checkout_line_fingerprint' => $lineFingerprint,
            'sort_no' => $sortNo,
            'item_type' => $sourceType,
            'item_id' => $sourceId,
            'catalog_sku_id' => $catalogSkuId,
            'item_version' => $sourceVersion,
            'item_code_snapshot' => $authority['sourceCodeSnapshot'],
            'item_name_snapshot' => $authority['sourceNameSnapshot'],
            'category_id_snapshot' => $categoryId,
            'category_name_snapshot' => $categoryName,
            'service_object' => $serviceObject,
            'craftsmen_snapshot_json' => CashierV3CheckoutCraftsmenSnapshot::encode($craftsmen),
            'is_experience' => $isExperience,
            'quantity' => $quantity,
            'original_amount_cents' => $original,
            'discount_amount_cents' => $discount,
            'sale_amount_cents' => $sale,
        ];
    }

    private static function assertEntitlementLine(array $row): int
    {
        $kind = (string)$row['source_kind'];
        if (!in_array($kind, self::ENTITLEMENT_KINDS, true)
            || (string)$row['source_type'] !== 'entitlement_project') {
            throw self::failure('entitlement_checkout_line_type_invalid');
        }
        $authority = [
            'authorityKey' => self::token(
                $row['authority_key'],
                96,
                'entitlement_authority_key_invalid'
            ),
            'sourceKind' => $kind,
            'holderId' => self::positiveInt($row['source_id'], 'entitlement_holder_id_invalid'),
            'entitlementSourceDetailId' => self::positiveInt(
                $row['entitlement_source_detail_id'],
                'entitlement_source_detail_id_invalid'
            ),
            'sourceVersion' => self::positiveInt(
                $row['source_version'],
                'entitlement_source_version_invalid'
            ),
            'projectId' => self::positiveInt($row['project_id'], 'entitlement_project_id_invalid'),
            'projectVersion' => self::positiveInt(
                $row['project_version'],
                'entitlement_project_version_invalid'
            ),
            'quantity' => self::quantity($row['quantity'], 'entitlement_quantity_invalid'),
            'actualEntitlementAmountCents' => self::money(
                $row['entitlement_actual_amount_cents'],
                'entitlement_actual_amount_invalid'
            ),
            'sourceNameSnapshot' => self::text(
                $row['source_name_snapshot'],
                128,
                'entitlement_source_name_invalid',
                false
            ),
            'sourceCodeSnapshot' => self::text(
                $row['source_code_snapshot'],
                64,
                'entitlement_source_code_invalid',
                true
            ),
            'projectNameSnapshot' => self::text(
                $row['project_name_snapshot'],
                128,
                'entitlement_project_name_invalid',
                false
            ),
            'projectCategoryIdSnapshot' => self::nonNegativeInt(
                $row['category_id_snapshot'],
                'entitlement_category_id_invalid'
            ),
            'projectCategoryNameSnapshot' => self::text(
                $row['category_name_snapshot'],
                128,
                'entitlement_category_name_invalid',
                true
            ),
        ];
        $lineFingerprint = self::sha256(
            $row['line_fingerprint'],
            'entitlement_line_fingerprint_invalid'
        );
        if ($authority['projectCategoryIdSnapshot'] > 0
            && $authority['projectCategoryNameSnapshot'] === '') {
            throw self::failure('entitlement_category_snapshot_incomplete');
        }
        if (!hash_equals($lineFingerprint, self::canonicalFingerprint($authority))) {
            throw self::failure('entitlement_checkout_line_fingerprint_mismatch');
        }
        return $authority['actualEntitlementAmountCents'];
    }

    private static function assertPaymentRows(array $rows, array $request): int
    {
        if (count($rows) > self::MAX_PAYMENT_ROWS) {
            throw self::failure('locked_checkout_payment_count_invalid');
        }
        $authorities = [];
        $total = 0;
        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) {
                throw self::failure('locked_checkout_payment_shape_invalid', ['index' => $index]);
            }
            self::assertExactKeys($row, self::PAYMENT_KEYS, 'locked_checkout_payment_shape_invalid');
            self::assertChildBinding($row, $request, 'payment', $index);
            if (self::positiveInt(
                $row['operator_id'],
                'locked_checkout_payment_operator_invalid'
            ) !== $request['operator_id']) {
                throw self::failure('locked_checkout_payment_operator_mismatch');
            }
            $method = (string)$row['payment_method'];
            if (!in_array($method, self::PAYMENT_METHODS, true)) {
                throw self::failure('locked_checkout_payment_method_invalid', ['method' => $method]);
            }
            $authorityKey = self::token(
                $row['payment_authority_key'],
                96,
                'locked_checkout_payment_authority_invalid'
            );
            if (isset($authorities[$authorityKey])) {
                throw self::failure('locked_checkout_payment_authority_duplicate');
            }
            $authorities[$authorityKey] = true;
            $amount = self::money($row['amount_cents'], 'locked_checkout_payment_amount_invalid');
            if ($amount <= 0) {
                throw self::failure('locked_checkout_payment_amount_invalid');
            }
            $authority = [
                'paymentAuthorityKey' => $authorityKey,
                'method' => $method,
                'amountCents' => $amount,
                'businessTime' => self::positiveInt(
                    $row['operation_occurred_at'],
                    'locked_checkout_payment_time_invalid'
                ),
                'externalTransactionNo' => self::text(
                    $row['external_transaction_no'],
                    64,
                    'locked_checkout_payment_reference_invalid',
                    true
                ),
                'remark' => self::text(
                    $row['remark'],
                    255,
                    'locked_checkout_payment_remark_invalid',
                    true
                ),
            ];
            if ($authority['businessTime'] !== $request['operation_occurred_at']
                || (string)$row['business_date'] !== $request['business_date']
                || (string)$row['business_timezone'] !== $request['business_timezone']
                || self::positiveInt(
                    $row['recorded_at'],
                    'locked_checkout_payment_recorded_at_invalid'
                ) !== $request['recorded_at']
                || (string)$row['operator_name_snapshot'] !== $request['operator_name_snapshot']
                || (string)$row['source_document_type'] !== $request['source_document_type']
                || (string)$row['source_document_id'] !== $request['source_document_id']
                || (string)$row['source_document_no'] !== $request['source_document_no']
                || !hash_equals(
                    self::sha256(
                        $row['payment_fingerprint'],
                        'locked_checkout_payment_fingerprint_invalid'
                    ),
                    self::canonicalFingerprint($authority)
                )) {
                throw self::failure('locked_checkout_payment_fingerprint_mismatch');
            }
            $total = self::safeAdd($total, $amount, 'locked_checkout_payment_total_overflow');
        }
        return $total;
    }

    private static function assertSourceRows(
        array $rows,
        array $request,
        CashierV3CheckoutVerifiedSourceSet $verifiedSources
    ): void {
        $authorityRows = [];
        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) {
                throw self::failure('locked_checkout_source_shape_invalid', ['index' => $index]);
            }
            self::assertExactKeys($row, self::SOURCE_KEYS, 'locked_checkout_source_shape_invalid');
            if (!hash_equals($request['request_id'], (string)$row['request_id'])
                || !hash_equals($request['tenant_id'], (string)$row['tenant_id'])
                || self::positiveInt($row['store_id'], 'locked_checkout_source_store_invalid')
                    !== $request['store_id']
                || self::positiveInt(
                    $row['bound_request_version'],
                    'locked_checkout_source_version_invalid'
                ) !== $request['request_version']) {
                throw self::failure('locked_checkout_source_binding_mismatch');
            }
            $reference = [
                'kind' => self::token($row['source_kind'], 32, 'locked_checkout_source_kind_invalid'),
                'id' => self::token($row['source_id'], 64, 'locked_checkout_source_id_invalid'),
                'sourceVersion' => self::positiveInt(
                    $row['source_version'],
                    'locked_checkout_source_version_invalid'
                ),
                'role' => self::token($row['source_role'], 32, 'locked_checkout_source_role_invalid'),
            ];
            $expectedFingerprint = CashierV3CheckoutVerifiedSourceSet::referenceFingerprint(
                $reference['kind'],
                $reference['id'],
                $reference['sourceVersion'],
                $reference['role']
            );
            if (!hash_equals(
                self::sha256(
                    $row['source_fingerprint'],
                    'locked_checkout_source_fingerprint_invalid'
                ),
                $expectedFingerprint
            )) {
                throw self::failure('locked_checkout_source_fingerprint_mismatch');
            }
            $authorityRows[] = [
                'tenantId' => $request['tenant_id'],
                'storeId' => $request['store_id'],
                'kind' => $reference['kind'],
                'id' => $reference['id'],
                'sourceVersion' => $reference['sourceVersion'],
                'role' => $reference['role'],
            ];
        }
        try {
            $rebuilt = CashierV3CheckoutVerifiedSourceSet::fromServerVerifiedAuthorityRows(
                $request['tenant_id'],
                $request['store_id'],
                $authorityRows
            );
        } catch (\Throwable $exception) {
            throw self::failure('locked_checkout_verified_sources_invalid', [
                'cause' => $exception->getMessage(),
            ]);
        }
        if (!hash_equals($rebuilt->fingerprint(), $verifiedSources->fingerprint())) {
            throw self::failure('locked_checkout_verified_sources_mismatch');
        }
    }

    private static function assertChildBinding(
        array $row,
        array $request,
        string $kind,
        int $index
    ): void {
        if (!hash_equals($request['request_id'], (string)$row['request_id'])
            || !hash_equals($request['tenant_id'], (string)$row['tenant_id'])
            || self::positiveInt($row['store_id'], 'checkout_child_store_invalid')
                !== $request['store_id']
            || self::nonNegativeInt($row['member_id'], 'checkout_child_member_invalid')
                !== $request['member_id']
            || self::positiveInt($row['draft_version'], 'checkout_child_version_invalid')
                !== $request['request_version']
            || (string)$row['draft_status'] !== 'draft') {
            throw self::failure('locked_checkout_child_binding_mismatch', [
                'kind' => $kind,
                'index' => $index,
            ]);
        }
    }

    private static function assertComposition(
        string $composition,
        int $saleLineCount,
        int $entitlementLineCount
    ): void {
        $valid = ($composition === 'sale_only' && $saleLineCount > 0 && $entitlementLineCount === 0)
            || ($composition === 'mixed' && $saleLineCount > 0 && $entitlementLineCount > 0);
        if (!$valid) {
            throw self::failure('sales_order_checkout_composition_mismatch');
        }
    }

    private static function sum(array $rows, string $field): int
    {
        $total = 0;
        foreach ($rows as $row) {
            $total = self::safeAdd($total, (int)$row[$field], 'sales_order_total_overflow');
        }
        return $total;
    }

    private static function safeAdd(int $left, int $right, string $reason): int
    {
        if ($right > 0 && $left > PHP_INT_MAX - $right) {
            throw self::failure($reason);
        }
        return $left + $right;
    }

    private static function money($value, string $reason): int
    {
        $amount = self::nonNegativeInt($value, $reason);
        if ($amount > self::MAX_MONEY_CENTS) {
            throw self::failure($reason);
        }
        return $amount;
    }

    private static function quantity($value, string $reason): int
    {
        $quantity = self::positiveInt($value, $reason);
        if ($quantity > self::MAX_QUANTITY) {
            throw self::failure($reason);
        }
        return $quantity;
    }

    private static function positiveInt($value, string $reason): int
    {
        $number = self::nonNegativeInt($value, $reason);
        if ($number <= 0) {
            throw self::failure($reason);
        }
        return $number;
    }

    private static function nonNegativeInt($value, string $reason): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (!is_string($value)
            || preg_match('/^(0|[1-9][0-9]*)$/D', $value) !== 1
            || strlen($value) > strlen((string)PHP_INT_MAX)
            || (strlen($value) === strlen((string)PHP_INT_MAX)
                && strcmp($value, (string)PHP_INT_MAX) > 0)) {
            throw self::failure($reason);
        }
        return (int)$value;
    }

    private static function opaqueId($value, string $prefix, string $reason): string
    {
        if (!is_string($value)
            || preg_match('/^' . preg_quote($prefix, '/') . '-[0-9a-f]{40}$/D', $value) !== 1) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function sha256($value, string $reason): string
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function businessDate($value): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            throw self::failure('checkout_business_date_invalid');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw self::failure('checkout_business_date_invalid');
        }
        return $value;
    }

    private static function organizationPath($value): string
    {
        if (!is_string($value)
            || strlen($value) > 191
            || preg_match('#^/[1-9][0-9]*(?:/[1-9][0-9]*)*/$#D', $value) !== 1) {
            throw self::failure('checkout_organization_path_invalid');
        }
        return $value;
    }

    private static function token($value, int $maxBytes, string $reason): string
    {
        if (!is_string($value)) {
            throw self::failure($reason);
        }
        $value = trim($value);
        if ($value === ''
            || strlen($value) > $maxBytes
            || preg_match('/^[A-Za-z0-9_.:-]+$/D', $value) !== 1) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function timezone($value, string $reason): string
    {
        if (!is_string($value)) {
            throw self::failure($reason);
        }
        $value = trim($value);
        if (strlen($value) > 64
            || preg_match('#^[A-Za-z][A-Za-z0-9_.+-]*/[A-Za-z][A-Za-z0-9_.+-]*$#D', $value) !== 1) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function optionalToken($value, int $maxBytes, string $reason): string
    {
        if ($value === '') {
            return '';
        }
        return self::token($value, $maxBytes, $reason);
    }

    private static function text(
        $value,
        int $maxBytes,
        string $reason,
        bool $allowEmpty
    ): string {
        if (!is_string($value) || strpos($value, "\0") !== false || strlen($value) > $maxBytes) {
            throw self::failure($reason);
        }
        $value = trim($value);
        if (!$allowEmpty && $value === '') {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function assertIdempotencyKey(
        string $key,
        string $reason,
        bool $allowPrepare = false
    ): void {
        $prefix = $allowPrepare ? '(?:CHECKOUT|CHECKOUT_PREPARE)' : 'CHECKOUT';
        if (strlen($key) > 128
            || preg_match(
                '/^' . $prefix . '-[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
                $key
            ) !== 1) {
            throw self::failure($reason);
        }
    }

    private static function canonicalFingerprint(array $value): string
    {
        try {
            return CashierV3CheckoutSettlementCanonicalizer::fingerprint($value);
        } catch (\Throwable $exception) {
            throw self::failure('sales_order_canonicalization_failed', [
                'cause' => $exception->getMessage(),
            ]);
        }
    }

    private static function craftsmenSnapshot($json): array
    {
        try {
            return CashierV3CheckoutCraftsmenSnapshot::decode($json);
        } catch (\Throwable $exception) {
            throw self::failure('sales_order_craftsmen_snapshot_invalid');
        }
    }

    private static function assertExactKeys(array $value, array $required, string $reason): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($required, SORT_STRING);
        if ($actual !== $required) {
            throw self::failure($reason, [
                'actualKeys' => $actual,
                'requiredKeys' => $required,
            ]);
        }
    }

    private static function failure(
        string $reason,
        array $detail = []
    ): CashierV3SalesOrderAuthorityException {
        return new CashierV3SalesOrderAuthorityException($reason, $detail);
    }
}
