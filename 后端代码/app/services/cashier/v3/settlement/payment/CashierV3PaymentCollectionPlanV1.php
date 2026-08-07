<?php

namespace app\services\cashier\v3\settlement\payment;

use app\services\cashier\v3\CashierV3BusinessDocumentNumberServices;
use app\services\cashier\v3\config\CashierV3BusinessConfigServices;
use app\services\cashier\v3\order\settlement\CashierV3SalesOrderPlanV1;
use app\services\cashier\v3\settlement\CashierV3CheckoutSettlementCanonicalizer;
use app\services\cashier\v3\settlement\CashierV3CheckoutVerifiedSourceSet;

/**
 * Immutable server-only plan for one sale-bearing checkout collection set.
 *
 * The checkout aggregate must still be locked by the caller. A sales-order
 * plan generated from that same locked aggregate supplies the authoritative
 * order identity; neither identity nor payment details are accepted from HTTP.
 */
final class CashierV3PaymentCollectionPlanV1
{
    public const CONTRACT_VERSION = 'cashier-v3-payment-collection-authority-v1';
    public const LOCKED_SOURCE_CONTRACT_VERSION = 'cashier-v3-locked-payment-collection-source-v1';
    public const STATUS_SETTLED = 'settled';
    public const DIRECTION_FORWARD = 'forward';

    private const MAX_MONEY_CENTS = 100000000000;
    private const MAX_PAYMENT_ROWS = 200;
    private const SALE_BEARING_COMPOSITIONS = ['sale_only', 'mixed'];
    private const PAYMENT_METHOD_LABELS = [
        'unionpay' => '银联',
        'wechat' => '微信',
        'alipay' => '支付宝',
        'dianping_voucher' => '大众验券',
        'douyin_voucher' => '抖音验券',
        'partner_collection' => '合作方收款',
        'other_collection' => '其他收款',
    ];

    private const AGGREGATE_KEYS = [
        'request', 'lines', 'payments', 'sources', 'currentRequest', 'verifiedSources',
    ];

    private const REQUEST_KEYS = [
        'id', 'request_id', 'tenant_id', 'organization_id', 'organization_path',
        'organization_name_snapshot', 'workspace_id', 'state_context_id', 'store_id',
        'store_name_snapshot', 'member_id', 'member_name_snapshot', 'operator_id',
        'operator_name_snapshot', 'request_version', 'request_status', 'composition',
        'business_date', 'business_timezone', 'operation_occurred_at', 'recorded_at',
        'order_note', 'supplement_enabled', 'supplement_reason',
        'supplement_operator_id', 'supplement_operator_name_snapshot',
        'supplement_operated_at',
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

    private const PAYMENT_KEYS = [
        'id', 'payment_draft_id', 'request_id', 'draft_version', 'tenant_id', 'store_id',
        'member_id', 'operator_id', 'payment_authority_key', 'payment_method',
        'amount_cents', 'external_transaction_no', 'remark', 'business_date',
        'business_timezone', 'operation_occurred_at', 'recorded_at',
        'operator_name_snapshot', 'source_document_type', 'source_document_id',
        'source_document_no', 'payment_fingerprint', 'draft_status', 'sort_no',
        'add_time', 'update_time',
    ];

    /** @var array */
    private $batch;

    /** @var array<int,array> */
    private $collections;

    /** @var string */
    private $fingerprint;

    /** @var string */
    private $composition;

    private function __construct(
        array $batch,
        array $collections,
        string $fingerprint,
        string $composition
    ) {
        $this->batch = $batch;
        $this->collections = $collections;
        $this->fingerprint = $fingerprint;
        $this->composition = $composition;
    }

    /**
     * @param array{request:array,lines:array,payments:array,sources:array,currentRequest:array,verifiedSources:CashierV3CheckoutVerifiedSourceSet} $lockedAggregate
     */
    public static function fromLockedCheckoutAggregate(
        array $lockedAggregate,
        CashierV3SalesOrderPlanV1 $salesOrder,
        string $commandIdempotencyKey,
        int $occurredAt,
        int $settledAt,
        int $recordedAt,
        string $serverNamespaceSecret
    ): self {
        self::assertExactKeys(
            $lockedAggregate,
            self::AGGREGATE_KEYS,
            'payment_collection_locked_aggregate_shape_invalid'
        );
        if (!is_array($lockedAggregate['request'])
            || !is_array($lockedAggregate['lines'])
            || !is_array($lockedAggregate['payments'])
            || !is_array($lockedAggregate['sources'])
            || !is_array($lockedAggregate['currentRequest'])
            || !($lockedAggregate['verifiedSources'] instanceof CashierV3CheckoutVerifiedSourceSet)) {
            throw self::failure('payment_collection_locked_aggregate_shape_invalid');
        }

        $request = self::normalizeRequest($lockedAggregate['request']);
        self::assertCurrentRequest($lockedAggregate['currentRequest'], $request);
        self::assertIdempotencyKey(
            $commandIdempotencyKey,
            'payment_collection_command_idempotency_key_invalid'
        );
        if ($occurredAt <= 0
            || $settledAt < $occurredAt
            || $recordedAt < $settledAt
            || $occurredAt < $request['operation_occurred_at']
            || $occurredAt < $request['recorded_at']) {
            throw self::failure('payment_collection_final_times_invalid');
        }

        $order = self::assertSalesOrderIdentity(
            $salesOrder,
            $request,
            $lockedAggregate['verifiedSources'],
            $commandIdempotencyKey,
            $occurredAt,
            $settledAt,
            $recordedAt
        );
        $payments = self::normalizePayments($lockedAggregate['payments'], $request);
        $paymentTotal = self::sum($payments, 'amount_cents');
        if ($paymentTotal !== $request['selected_payment_amount_cents']
            || $paymentTotal !== $request['cash_performance_amount_cents']) {
            throw self::failure('payment_collection_checkout_total_mismatch');
        }
        $balanceTotal = $request['balance_deduction_amount_cents'];
        $debtTotal = $request['debt_amount_cents'];
        if ($paymentTotal > 100000000000 - $balanceTotal
            || $paymentTotal + $balanceTotal > 100000000000 - $debtTotal
            || $paymentTotal + $balanceTotal + $debtTotal
                !== $request['receivable_amount_cents']) {
            throw self::failure('payment_collection_sale_only_settlement_mismatch');
        }

        $ids = new CashierV3PaymentCollectionIdFactory($serverNamespaceSecret);
        $batchId = $ids->batchId($request['tenant_id'], $request['request_id']);
        $collectionRows = [];
        $businessConfig = new CashierV3BusinessConfigServices();
        foreach ($payments as $index => $payment) {
            // The immutable collection keeps the name as of settlement. The
            // transaction lock also rejects a method disabled mid-checkout.
            $methodSnapshot = $businessConfig->resolveAccountingMethodSnapshot(
                $payment['payment_method'],
                true
            );
            $row = [
                'collection_id' => $ids->collectionId(
                    $request['tenant_id'],
                    $request['request_id'],
                    $payment['payment_draft_id']
                ),
                'collection_no' => $ids->collectionNo(
                    $request['tenant_id'],
                    $request['request_id'],
                    $payment['payment_draft_id'],
                    $request['business_date']
                ),
                'natural_key' => 'checkout_payment_collection:'
                    . $request['request_id'] . ':' . $payment['payment_draft_id'],
                'contract_version' => self::CONTRACT_VERSION,
                'command_idempotency_key' => $commandIdempotencyKey,
                'batch_id' => $batchId,
                'tenant_id' => $request['tenant_id'],
                'organization_id' => $request['organization_id'],
                'organization_path_snapshot' => $request['organization_path'],
                'organization_name_snapshot' => $request['organization_name_snapshot'],
                'store_id' => $request['store_id'],
                'store_name_snapshot' => $request['store_name_snapshot'],
                'member_id' => $request['member_id'],
                'member_name_snapshot' => $request['member_name_snapshot'],
                'operator_id' => $payment['operator_id'],
                'operator_name_snapshot' => $payment['operator_name_snapshot'],
                'sales_order_id' => $order['order_id'],
                'sales_order_no_snapshot' => $order['order_no'],
                'sales_order_fingerprint' => $order['immutable_fingerprint'],
                'checkout_request_id' => $request['request_id'],
                'checkout_request_version' => $request['request_version'],
                'checkout_payment_draft_id' => $payment['payment_draft_id'],
                'checkout_payment_authority_key' => $payment['payment_authority_key'],
                'checkout_payment_fingerprint' => $payment['payment_fingerprint'],
                'payment_line_no' => $index + 1,
                'checkout_payment_sort_no' => $payment['sort_no'],
                'payment_method' => $payment['payment_method'],
                'payment_method_name_snapshot' => $methodSnapshot['displayNameSnapshot'],
                'amount_cents' => $payment['amount_cents'],
                'cash_performance_amount_cents' => $payment['amount_cents'],
                'business_date' => $request['business_date'],
                'business_timezone' => $request['business_timezone'],
                'payment_business_time_snapshot' => $payment['operation_occurred_at'],
                'payment_recorded_at_snapshot' => $payment['recorded_at'],
                'occurred_at' => $occurredAt,
                'settled_at' => $settledAt,
                'recorded_at' => $recordedAt,
                'source_document_type' => $payment['source_document_type'],
                'source_document_id' => $payment['source_document_id'],
                'source_document_no_snapshot' => $payment['source_document_no'],
                'external_transaction_no_snapshot' => $payment['external_transaction_no'],
                'remark_snapshot' => $payment['remark'],
                'collection_status' => self::STATUS_SETTLED,
                'collection_version' => 1,
                'collection_direction' => self::DIRECTION_FORWARD,
                'reversal_of_collection_id' => '',
                'reversal_reason_snapshot' => '',
            ];
            $row['immutable_fingerprint'] = self::canonicalFingerprint($row);
            $collectionRows[] = $row;
        }

        $batch = [
            'batch_id' => $batchId,
            'natural_key' => 'checkout_payment_collection_batch:' . $request['request_id'],
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
            'sales_order_id' => $order['order_id'],
            'sales_order_no_snapshot' => $order['order_no'],
            'sales_order_fingerprint' => $order['immutable_fingerprint'],
            'checkout_request_id' => $request['request_id'],
            'checkout_request_version' => $request['request_version'],
            'checkout_prepare_idempotency_key' => $request['last_idempotency_key'],
            'checkout_authority_fingerprint' => $request['authority_fingerprint'],
            'checkout_aggregate_fingerprint' => $request['aggregate_fingerprint'],
            'collection_count' => count($collectionRows),
            'collected_amount_cents' => $paymentTotal,
            'cash_performance_amount_cents' => $request['cash_performance_amount_cents'],
            'receivable_amount_cents' => $request['receivable_amount_cents'],
            'business_date' => $request['business_date'],
            'business_timezone' => $request['business_timezone'],
            'occurred_at' => $occurredAt,
            'settled_at' => $settledAt,
            'recorded_at' => $recordedAt,
            'source_document_type' => $request['source_document_type'],
            'source_document_id' => $request['source_document_id'],
            'source_document_no_snapshot' => $request['source_document_no'],
            'batch_status' => self::STATUS_SETTLED,
            'batch_version' => 1,
            'batch_direction' => self::DIRECTION_FORWARD,
            'reversal_of_batch_id' => '',
            'reversal_reason_snapshot' => '',
        ];
        $fingerprintInput = $batch;
        $fingerprintInput['collection_fingerprints'] = array_column(
            $collectionRows,
            'immutable_fingerprint'
        );
        $batch['immutable_fingerprint'] = self::canonicalFingerprint($fingerprintInput);
        return new self(
            $batch,
            $collectionRows,
            $batch['immutable_fingerprint'],
            $request['composition']
        );
    }

    public function batch(): array
    {
        return $this->batch;
    }

    /** @return array<int,array> */
    public function collections(): array
    {
        return $this->collections;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    public function checkoutRequestId(): string
    {
        return (string)$this->batch['checkout_request_id'];
    }

    public function commandIdempotencyKey(): string
    {
        return (string)$this->batch['command_idempotency_key'];
    }

    public function composition(): string
    {
        return $this->composition;
    }

    private static function normalizeRequest(array $request): array
    {
        self::assertExactKeys(
            $request,
            self::REQUEST_KEYS,
            'payment_collection_locked_request_shape_invalid'
        );
        $normalized = [
            'request_id' => self::opaqueId(
                $request['request_id'],
                'CKR',
                'payment_collection_checkout_request_id_invalid'
            ),
            'tenant_id' => self::token(
                $request['tenant_id'],
                32,
                'payment_collection_tenant_id_invalid'
            ),
            'organization_id' => self::token(
                $request['organization_id'],
                32,
                'payment_collection_organization_id_invalid'
            ),
            'organization_path' => self::organizationPath($request['organization_path']),
            'organization_name_snapshot' => self::text(
                $request['organization_name_snapshot'],
                128,
                'payment_collection_organization_name_invalid',
                true
            ),
            'workspace_id' => self::token(
                $request['workspace_id'],
                64,
                'payment_collection_workspace_id_invalid'
            ),
            'store_id' => self::positiveInt(
                $request['store_id'],
                'payment_collection_store_id_invalid'
            ),
            'store_name_snapshot' => self::text(
                $request['store_name_snapshot'],
                128,
                'payment_collection_store_name_invalid',
                false
            ),
            'member_id' => self::nonNegativeInt(
                $request['member_id'],
                'payment_collection_member_id_invalid'
            ),
            'member_name_snapshot' => self::text(
                $request['member_name_snapshot'],
                128,
                'payment_collection_member_name_invalid',
                true
            ),
            'operator_id' => self::positiveInt(
                $request['operator_id'],
                'payment_collection_operator_id_invalid'
            ),
            'operator_name_snapshot' => self::text(
                $request['operator_name_snapshot'],
                128,
                'payment_collection_operator_name_invalid',
                false
            ),
            'request_version' => self::positiveInt(
                $request['request_version'],
                'payment_collection_checkout_version_invalid'
            ),
            'request_status' => self::token(
                $request['request_status'],
                32,
                'payment_collection_checkout_status_invalid'
            ),
            'composition' => self::token(
                $request['composition'],
                24,
                'payment_collection_composition_invalid'
            ),
            'business_date' => self::businessDate($request['business_date']),
            'business_timezone' => self::timezone(
                $request['business_timezone'],
                'payment_collection_business_timezone_invalid'
            ),
            'operation_occurred_at' => self::positiveInt(
                $request['operation_occurred_at'],
                'payment_collection_checkout_occurred_at_invalid'
            ),
            'recorded_at' => self::positiveInt(
                $request['recorded_at'],
                'payment_collection_checkout_recorded_at_invalid'
            ),
            'source_document_type' => self::token(
                $request['source_document_type'],
                32,
                'payment_collection_source_type_invalid'
            ),
            'source_document_id' => self::token(
                $request['source_document_id'],
                64,
                'payment_collection_source_id_invalid'
            ),
            'source_document_no' => self::text(
                $request['source_document_no'],
                64,
                'payment_collection_source_no_invalid',
                false
            ),
            'sales_amount_cents' => self::money(
                $request['sales_amount_cents'],
                'payment_collection_sales_amount_invalid'
            ),
            'receivable_amount_cents' => self::money(
                $request['receivable_amount_cents'],
                'payment_collection_receivable_amount_invalid'
            ),
            'selected_payment_amount_cents' => self::money(
                $request['selected_payment_amount_cents'],
                'payment_collection_selected_amount_invalid'
            ),
            'balance_deduction_amount_cents' => self::money(
                $request['balance_deduction_amount_cents'],
                'payment_collection_balance_amount_invalid'
            ),
            'debt_amount_cents' => self::money(
                $request['debt_amount_cents'],
                'payment_collection_debt_amount_invalid'
            ),
            'cash_performance_amount_cents' => self::money(
                $request['cash_performance_amount_cents'],
                'payment_collection_cash_performance_invalid'
            ),
            'entitlement_actual_amount_cents' => self::money(
                $request['entitlement_actual_amount_cents'],
                'payment_collection_entitlement_amount_invalid'
            ),
            'authority_fingerprint' => self::sha256(
                $request['authority_fingerprint'],
                'payment_collection_authority_fingerprint_invalid'
            ),
            'aggregate_fingerprint' => self::sha256(
                $request['aggregate_fingerprint'],
                'payment_collection_aggregate_fingerprint_invalid'
            ),
            'creation_idempotency_key' => (string)$request['creation_idempotency_key'],
            'last_idempotency_key' => (string)$request['last_idempotency_key'],
            'last_operation_fingerprint' => self::sha256(
                $request['last_operation_fingerprint'],
                'payment_collection_operation_fingerprint_invalid'
            ),
            'last_operation' => self::token(
                $request['last_operation'],
                32,
                'payment_collection_last_operation_invalid'
            ),
        ];
        self::assertIdempotencyKey(
            $normalized['creation_idempotency_key'],
            'payment_collection_creation_idempotency_key_invalid',
            true
        );
        self::assertIdempotencyKey(
            $normalized['last_idempotency_key'],
            'payment_collection_prepare_idempotency_key_invalid',
            true
        );
        if ($normalized['request_status'] !== 'ready_for_submit'
            || $normalized['last_operation'] !== 'prepare_submission') {
            throw self::failure('checkout_request_not_ready_for_payment_collection');
        }
        if (!in_array($normalized['composition'], self::SALE_BEARING_COMPOSITIONS, true)) {
            throw self::failure('payment_collection_sale_composition_required');
        }
        if ($normalized['business_timezone'] !== 'Asia/Shanghai') {
            throw self::failure('payment_collection_business_timezone_not_supported');
        }
        if ($normalized['recorded_at'] < $normalized['operation_occurred_at']) {
            throw self::failure('payment_collection_checkout_times_invalid');
        }
        if ($normalized['composition'] === 'sale_only'
            && $normalized['entitlement_actual_amount_cents'] !== 0) {
            throw self::failure('payment_collection_sale_only_settlement_mismatch');
        }
        $balanceVersion = self::nonNegativeInt(
            $request['balance_account_version'],
            'payment_collection_balance_version_invalid'
        );
        $debtVersion = self::nonNegativeInt(
            $request['debt_policy_version'],
            'payment_collection_debt_policy_version_invalid'
        );
        if (($normalized['balance_deduction_amount_cents'] === 0
                && ((string)$request['balance_authority_key'] !== ''
                    || (string)$request['balance_account_id'] !== ''
                    || $balanceVersion !== 0))
            || ($normalized['balance_deduction_amount_cents'] > 0
                && ((string)$request['balance_authority_key'] === ''
                    || (string)$request['balance_account_id'] === ''
                    || $balanceVersion <= 0))
            || ($normalized['debt_amount_cents'] === 0
                && ((string)$request['debt_authority_key'] !== '' || $debtVersion !== 0))
            || ($normalized['debt_amount_cents'] > 0
                && ($normalized['member_id'] <= 0
                    || (string)$request['debt_authority_key'] === ''
                    || $debtVersion <= 0))) {
            throw self::failure('payment_collection_non_cash_authority_invalid');
        }
        self::positiveInt(
            $request['authority_snapshot_version'],
            'payment_collection_authority_snapshot_version_invalid'
        );
        return $normalized;
    }

    private static function assertCurrentRequest(array $current, array $request): void
    {
        self::assertExactKeys($current, [
            'requestId', 'tenantId', 'workspaceId', 'version', 'status',
            'creationIdempotencyKey', 'lastIdempotencyKey', 'lastOperationFingerprint',
        ], 'payment_collection_current_request_shape_invalid');
        if (!hash_equals($request['request_id'], (string)$current['requestId'])
            || !hash_equals($request['tenant_id'], (string)$current['tenantId'])
            || !hash_equals($request['workspace_id'], (string)$current['workspaceId'])
            || $request['request_version'] !== self::positiveInt(
                $current['version'],
                'payment_collection_current_request_version_invalid'
            )
            || !hash_equals($request['request_status'], (string)$current['status'])
            || !hash_equals(
                $request['creation_idempotency_key'],
                (string)$current['creationIdempotencyKey']
            )
            || !hash_equals(
                $request['last_idempotency_key'],
                (string)$current['lastIdempotencyKey']
            )
            || !hash_equals(
                $request['last_operation_fingerprint'],
                (string)$current['lastOperationFingerprint']
            )) {
            throw self::failure('payment_collection_current_request_mismatch');
        }
    }

    private static function assertSalesOrderIdentity(
        CashierV3SalesOrderPlanV1 $salesOrder,
        array $request,
        CashierV3CheckoutVerifiedSourceSet $verifiedSources,
        string $commandIdempotencyKey,
        int $occurredAt,
        int $settledAt,
        int $recordedAt
    ): array {
        $order = $salesOrder->header();
        $required = [
            'order_id', 'order_no', 'immutable_fingerprint', 'command_idempotency_key',
            'tenant_id', 'organization_id', 'store_id', 'member_id', 'operator_id',
            'checkout_request_id', 'checkout_request_version',
            'checkout_authority_fingerprint', 'checkout_aggregate_fingerprint',
            'checkout_source_set_fingerprint', 'composition', 'business_date',
            'business_timezone', 'occurred_at', 'settled_at', 'recorded_at',
            'source_document_type', 'source_document_id', 'source_document_no_snapshot',
            'sale_amount_cents', 'order_status', 'order_version', 'order_direction',
            'reversal_of_order_id',
        ];
        foreach ($required as $field) {
            if (!array_key_exists($field, $order)) {
                throw self::failure('payment_collection_sales_order_identity_incomplete', [
                    'field' => $field,
                ]);
            }
        }
        if (!hash_equals($salesOrder->fingerprint(), (string)$order['immutable_fingerprint'])
            || !hash_equals($salesOrder->commandIdempotencyKey(), $commandIdempotencyKey)
            || !hash_equals((string)$order['command_idempotency_key'], $commandIdempotencyKey)
            || !hash_equals((string)$order['tenant_id'], $request['tenant_id'])
            || !hash_equals((string)$order['organization_id'], $request['organization_id'])
            || (int)$order['store_id'] !== $request['store_id']
            || (int)$order['member_id'] !== $request['member_id']
            || (int)$order['operator_id'] !== $request['operator_id']
            || !hash_equals((string)$order['checkout_request_id'], $request['request_id'])
            || (int)$order['checkout_request_version'] !== $request['request_version']
            || !hash_equals(
                (string)$order['checkout_authority_fingerprint'],
                $request['authority_fingerprint']
            )
            || !hash_equals(
                (string)$order['checkout_aggregate_fingerprint'],
                $request['aggregate_fingerprint']
            )
            || !hash_equals(
                (string)$order['checkout_source_set_fingerprint'],
                $verifiedSources->fingerprint()
            )
            || !in_array(
                (string)$order['composition'],
                self::SALE_BEARING_COMPOSITIONS,
                true
            )
            || !hash_equals((string)$order['composition'], $request['composition'])
            || (string)$order['business_date'] !== $request['business_date']
            || (string)$order['business_timezone'] !== $request['business_timezone']
            || (int)$order['occurred_at'] !== $occurredAt
            || (int)$order['settled_at'] !== $settledAt
            || (int)$order['recorded_at'] !== $recordedAt
            || (string)$order['source_document_type'] !== $request['source_document_type']
            || (string)$order['source_document_id'] !== $request['source_document_id']
            || (string)$order['source_document_no_snapshot'] !== $request['source_document_no']
            || (int)$order['sale_amount_cents'] !== $request['sales_amount_cents']
            || (string)$order['order_status'] !== 'settled'
            || (int)$order['order_version'] !== 1
            || (string)$order['order_direction'] !== 'forward'
            || (string)$order['reversal_of_order_id'] !== '') {
            throw self::failure('payment_collection_sales_order_identity_mismatch');
        }
        self::opaqueId(
            (string)$order['order_id'],
            'CSO',
            'payment_collection_sales_order_id_invalid'
        );
        self::sha256(
            (string)$order['immutable_fingerprint'],
            'payment_collection_sales_order_fingerprint_invalid'
        );
        if (!CashierV3BusinessDocumentNumberServices::isSalesOrderNo((string)$order['order_no'])) {
            throw self::failure('payment_collection_sales_order_no_invalid');
        }
        return $order;
    }

    /** @return array<int,array> */
    private static function normalizePayments(array $rows, array $request): array
    {
        if (count($rows) > self::MAX_PAYMENT_ROWS) {
            throw self::failure('payment_collection_payment_count_invalid');
        }
        $normalized = [];
        $draftIds = [];
        $authorityKeys = [];
        $sortNumbers = [];
        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) {
                throw self::failure('payment_collection_payment_shape_invalid', ['index' => $index]);
            }
            self::assertExactKeys(
                $row,
                self::PAYMENT_KEYS,
                'payment_collection_payment_shape_invalid'
            );
            if (!hash_equals($request['request_id'], (string)$row['request_id'])
                || !hash_equals($request['tenant_id'], (string)$row['tenant_id'])
                || self::positiveInt(
                    $row['draft_version'],
                    'payment_collection_payment_version_invalid'
                ) !== $request['request_version']
                || self::positiveInt(
                    $row['store_id'],
                    'payment_collection_payment_store_invalid'
                ) !== $request['store_id']
                || self::nonNegativeInt(
                    $row['member_id'],
                    'payment_collection_payment_member_invalid'
                ) !== $request['member_id']
                || self::positiveInt(
                    $row['operator_id'],
                    'payment_collection_payment_operator_invalid'
                ) !== $request['operator_id']
                || (string)$row['draft_status'] !== 'draft') {
                throw self::failure('payment_collection_payment_binding_mismatch', ['index' => $index]);
            }
            $draftId = self::opaqueId(
                $row['payment_draft_id'],
                'CKP',
                'payment_collection_payment_draft_id_invalid'
            );
            $method = (string)$row['payment_method'];
            if ($method === 'old_card_entry') {
                throw self::failure('old_card_entry_payment_collection_forbidden');
            }
            if (!array_key_exists($method, self::PAYMENT_METHOD_LABELS)) {
                throw self::failure('payment_collection_method_invalid', ['method' => $method]);
            }
            $authorityKey = self::token(
                $row['payment_authority_key'],
                96,
                'payment_collection_authority_key_invalid'
            );
            $sortNo = self::positiveInt(
                $row['sort_no'],
                'payment_collection_payment_sort_invalid'
            );
            // A checkout may contain several independent collections through
            // the same bookkeeping method. The immutable payment draft ID,
            // authority key and ordering position, rather than the method,
            // identify one collection row.
            if (isset($draftIds[$draftId])
                || isset($authorityKeys[$authorityKey])
                || isset($sortNumbers[$sortNo])) {
                throw self::failure('payment_collection_payment_duplicate', ['index' => $index]);
            }
            $draftIds[$draftId] = true;
            $authorityKeys[$authorityKey] = true;
            $sortNumbers[$sortNo] = true;

            $amount = self::money(
                $row['amount_cents'],
                'payment_collection_amount_invalid'
            );
            if ($amount <= 0) {
                throw self::failure('payment_collection_amount_invalid');
            }
            $paymentTime = self::positiveInt(
                $row['operation_occurred_at'],
                'payment_collection_payment_time_invalid'
            );
            $paymentRecordedAt = self::positiveInt(
                $row['recorded_at'],
                'payment_collection_payment_recorded_at_invalid'
            );
            $operatorName = self::text(
                $row['operator_name_snapshot'],
                128,
                'payment_collection_payment_operator_name_invalid',
                false
            );
            $sourceType = self::token(
                $row['source_document_type'],
                32,
                'payment_collection_payment_source_type_invalid'
            );
            $sourceId = self::token(
                $row['source_document_id'],
                64,
                'payment_collection_payment_source_id_invalid'
            );
            $sourceNo = self::text(
                $row['source_document_no'],
                64,
                'payment_collection_payment_source_no_invalid',
                false
            );
            $externalNo = self::text(
                $row['external_transaction_no'],
                64,
                'payment_collection_external_reference_invalid',
                true
            );
            $remark = self::text(
                $row['remark'],
                255,
                'payment_collection_remark_invalid',
                true
            );
            $authority = [
                'paymentAuthorityKey' => $authorityKey,
                'method' => $method,
                'amountCents' => $amount,
                'businessTime' => $paymentTime,
                'externalTransactionNo' => $externalNo,
                'remark' => $remark,
            ];
            $paymentFingerprint = self::sha256(
                $row['payment_fingerprint'],
                'payment_collection_payment_fingerprint_invalid'
            );
            if ($paymentTime !== $request['operation_occurred_at']
                || $paymentRecordedAt !== $request['recorded_at']
                || (string)$row['business_date'] !== $request['business_date']
                || (string)$row['business_timezone'] !== $request['business_timezone']
                || $operatorName !== $request['operator_name_snapshot']
                || $sourceType !== $request['source_document_type']
                || $sourceId !== $request['source_document_id']
                || $sourceNo !== $request['source_document_no']
                || !hash_equals($paymentFingerprint, self::canonicalFingerprint($authority))) {
                throw self::failure('payment_collection_payment_snapshot_mismatch', [
                    'paymentDraftId' => $draftId,
                ]);
            }
            $normalized[] = [
                'payment_draft_id' => $draftId,
                'payment_authority_key' => $authorityKey,
                'payment_method' => $method,
                'amount_cents' => $amount,
                'operation_occurred_at' => $paymentTime,
                'recorded_at' => $paymentRecordedAt,
                'operator_id' => $request['operator_id'],
                'operator_name_snapshot' => $operatorName,
                'source_document_type' => $sourceType,
                'source_document_id' => $sourceId,
                'source_document_no' => $sourceNo,
                'external_transaction_no' => $externalNo,
                'remark' => $remark,
                'payment_fingerprint' => $paymentFingerprint,
                'sort_no' => $sortNo,
            ];
        }
        usort($normalized, static function (array $left, array $right): int {
            $sort = $left['sort_no'] <=> $right['sort_no'];
            return $sort !== 0
                ? $sort
                : strcmp($left['payment_draft_id'], $right['payment_draft_id']);
        });
        return $normalized;
    }

    private static function sum(array $rows, string $field): int
    {
        $total = 0;
        foreach ($rows as $row) {
            $total = self::safeAdd(
                $total,
                (int)$row[$field],
                'payment_collection_total_overflow'
            );
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
            throw self::failure('payment_collection_business_date_invalid');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw self::failure('payment_collection_business_date_invalid');
        }
        return $value;
    }

    private static function organizationPath($value): string
    {
        if (!is_string($value)
            || strlen($value) > 191
            || preg_match('#^/[1-9][0-9]*(?:/[1-9][0-9]*)*/$#D', $value) !== 1) {
            throw self::failure('payment_collection_organization_path_invalid');
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
                '/^' . $prefix . '-[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}'
                . '-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
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
            throw self::failure('payment_collection_canonicalization_failed', [
                'cause' => $exception->getMessage(),
            ]);
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
    ): CashierV3PaymentCollectionAuthorityException {
        return new CashierV3PaymentCollectionAuthorityException($reason, $detail);
    }
}
