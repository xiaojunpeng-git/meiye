<?php

namespace app\services\cashier\v3\fact;

use app\services\cashier\v3\order\settlement\CashierV3SalesOrderPlanV1;
use app\services\cashier\v3\settlement\CashierV3PaidProjectCraftsmanPerformanceServices;
use app\services\cashier\v3\settlement\CashierV3CheckoutVerifiedSourceSet;
use app\services\cashier\v3\settlement\payment\CashierV3PaymentCollectionPlanV1;
use app\services\cashier\v3\settlement\CashierV3CheckoutDebtAuthorityServices;

/**
 * Server-only assembler for the sale/payment slice of a checkout.
 *
 * Every input is produced or revalidated inside the final locked transaction.
 * This class never accepts an HTTP DTO and never writes a table.
 */
final class CashierV3SaleOnlyFactAssembler
{
    public const CONTRACT_VERSION = 'cashier-v3-sale-fact-assembler-v3';
    public const EVENT_TYPE = 'checkout.completed';

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
        'resumed_hang_order_id',
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
        'project_id', 'project_version', 'service_object', 'friend_counts_as_customer', 'is_experience', 'is_presale', 'inventory_outbound_required', 'quantity', 'original_amount_cents',
        'discount_amount_cents', 'sale_amount_cents', 'debt_amount_cents', 'entitlement_actual_amount_cents',
        'source_name_snapshot', 'source_code_snapshot', 'project_name_snapshot',
        'category_id_snapshot', 'category_name_snapshot', 'line_fingerprint',
        'coupon_user_id', 'coupon_name_snapshot', 'coupon_discount_cents',
        'configured_cost_cents', 'price_change_reason', 'price_changed_by',
        'price_changed_by_name_snapshot', 'price_changed_at',
        'craftsmen_snapshot_json',
        'salespeople_snapshot_json',
        'guide_selections_json', 'sales_manager_selections_json',
        'manual_labor_fee_cents', 'card_purchase_snapshot_json',
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

    private const CURRENT_REQUEST_KEYS = [
        'requestId', 'tenantId', 'workspaceId', 'version', 'status',
        'creationIdempotencyKey', 'lastIdempotencyKey', 'lastOperationFingerprint',
    ];

    private const SALES_ORDER_RESULT_KEYS = [
        'contractVersion', 'orderId', 'orderNo', 'checkoutRequestId', 'orderStatus',
        'orderVersion', 'planFingerprint', 'replayed', 'affected',
        'businessEffectsWritten',
    ];

    private const PAYMENT_RESULT_KEYS = [
        'contractVersion', 'batchId', 'salesOrderId', 'checkoutRequestId',
        'collectionIds', 'collectionCount', 'collectedAmountCents',
        'cashPerformanceAmountCents', 'planFingerprint', 'replayed', 'affected',
        'businessEffectsWritten',
    ];

    private const EVENT_AUTHORITY_KEYS = [
        'event_id', 'event_no', 'event_key', 'event_type', 'aggregate_type',
        'aggregate_id', 'aggregate_name_snapshot', 'aggregate_version', 'source_type', 'source_id',
        'command_idempotency_key', 'business_date', 'occurred_at', 'settled_at',
        'recorded_at',
    ];

    public static function assemble(
        array $lockedAggregate,
        CashierV3SalesOrderPlanV1 $salesOrderPlan,
        array $salesOrderResult,
        CashierV3PaymentCollectionPlanV1 $paymentCollectionPlan,
        array $paymentCollectionResult,
        array $completedEventAuthority,
        string $finalCommandIdempotencyKey,
        string $serverNamespaceSecret,
        ?array $balanceMutation = null,
        array $salespeopleByCheckoutLine = []
    ): CashierV3CheckoutFactPlanV1 {
        $request = self::assertLockedAggregate($lockedAggregate);
        self::assertFinalCommandKey($finalCommandIdempotencyKey);

        $order = $salesOrderPlan->header();
        $orderLines = $salesOrderPlan->lines();
        self::assertSalesOrderPlan(
            $request,
            $lockedAggregate,
            $salesOrderPlan,
            $order,
            $orderLines,
            $finalCommandIdempotencyKey
        );
        self::assertSalesOrderResult($salesOrderPlan, $salesOrderResult, $order, $orderLines);

        $collectionBatch = $paymentCollectionPlan->batch();
        $collections = $paymentCollectionPlan->collections();
        self::assertPaymentCollectionPlan(
            $request,
            $lockedAggregate,
            $paymentCollectionPlan,
            $collectionBatch,
            $collections,
            $order,
            $finalCommandIdempotencyKey
        );
        self::assertPaymentCollectionResult(
            $paymentCollectionPlan,
            $paymentCollectionResult,
            $collectionBatch,
            $collections
        );
        $balanceFact = self::balanceFact(
            $request,
            $order,
            $balanceMutation,
            $serverNamespaceSecret
        );
        $event = self::assertCompletedEvent(
            $completedEventAuthority,
            $request,
            $order,
            $collectionBatch,
            $collections,
            $finalCommandIdempotencyKey
        );
        self::assertTotals($request, $order, $orderLines, $collectionBatch, $collections);

        $ids = new CashierV3CheckoutFactIdFactory($serverNamespaceSecret);
        $saleFacts = [];
        foreach ($orderLines as $line) {
            $sourceLineId = (string)$line['order_line_id'];
            $saleFacts[] = [
                'factId' => $ids->saleFactId(
                    (string)$order['tenant_id'],
                    (string)$order['order_id'],
                    $sourceLineId
                ),
                'naturalKey' => $ids->saleNaturalKey(
                    (string)$order['tenant_id'],
                    (string)$order['order_id'],
                    $sourceLineId
                ),
                'factVersion' => 1,
                'reversalOf' => '',
                'status' => CashierV3CheckoutFactPlanV1::STATUS_EFFECTIVE,
                'sourceLineId' => $sourceLineId,
                'sourceType' => (string)$line['item_type'],
                'itemId' => (string)$line['item_id'],
                'itemCodeSnapshot' => (string)$line['item_code_snapshot'],
                'itemNameSnapshot' => (string)$line['item_name_snapshot'],
                'categoryIdSnapshot' => (string)$line['category_id_snapshot'],
                'categoryNameSnapshot' => (string)$line['category_name_snapshot'],
                'quantity' => (int)$line['quantity'],
                'friendCountsAsCustomer' => (int)($line['friend_counts_as_customer'] ?? 1),
                'isPresale' => (int)($line['is_presale'] ?? 0),
                'inventoryOutboundRequired' => (int)($line['inventory_outbound_required'] ?? 1),
                'originalAmountCents' => (int)$line['original_amount_cents'],
                'discountAmountCents' => (int)$line['discount_amount_cents'],
                'couponUserId' => (int)$line['coupon_user_id'],
                'couponNameSnapshot' => (string)$line['coupon_name_snapshot'],
                'couponDiscountCents' => (int)$line['coupon_discount_cents'],
                'saleAmountCents' => (int)$line['sale_amount_cents'],
                'debtAmountCents' => (int)$line['debt_amount_cents'],
            ];
        }

        $paymentFacts = [];
        foreach ($collections as $collection) {
            $sourceLineId = (string)$collection['collection_id'];
            $paymentFacts[] = [
                'factId' => $ids->paymentFactId(
                    (string)$order['tenant_id'],
                    (string)$order['order_id'],
                    $sourceLineId
                ),
                'naturalKey' => $ids->paymentNaturalKey(
                    (string)$order['tenant_id'],
                    (string)$order['order_id'],
                    $sourceLineId
                ),
                'factVersion' => 1,
                'reversalOf' => '',
                'status' => CashierV3CheckoutFactPlanV1::STATUS_EFFECTIVE,
                'sourceLineId' => $sourceLineId,
                'paymentMethod' => (string)$collection['payment_method'],
                'paymentAuthorityKey' => (string)$collection['checkout_payment_authority_key'],
                'collectionReference' => (string)$collection['collection_no'],
                'amountCents' => (int)$collection['amount_cents'],
            ];
        }

        $actualSourceLineId = (string)$collectionBatch['batch_id'];
        $cashPerformanceAmount = (int)$collectionBatch['cash_performance_amount_cents'];
        $performanceFacts = [];
        $externalSalesAmount = 0;
        $cashByOrderLine = self::allocateAcrossOrderLines($cashPerformanceAmount, $orderLines);
        foreach ($orderLines as $orderLine) {
            $checkoutLineId = (string)$orderLine['checkout_line_id'];
            $salespeople = array_values((array)($salespeopleByCheckoutLine[$checkoutLineId] ?? []));
            if ($salespeople) {
              $weightTotal = array_sum(array_map(static function (array $person): int {
                return (int)($person['allocationWeight'] ?? 0);
              }, $salespeople));
              if ($weightTotal !== 100) {
                throw self::failure('sale_only_fact_salesperson_weight_total_invalid');
              }
              $lineCash = (int)($cashByOrderLine[(string)$orderLine['order_line_id']] ?? 0);
              $employeeAmounts = self::allocateByWeights($lineCash, $salespeople);
              foreach ($salespeople as $index => $person) {
                $employeeId = (int)($person['employeeId'] ?? 0);
                $employeeType = (string)($person['employeeTypeCodeSnapshot'] ?? '');
                $employeeTypeVersion = (int)($person['employeeTypeAuthorityVersion'] ?? 0);
                $employeeName = trim((string)($person['name'] ?? ''));
                $weight = (int)($person['allocationWeight'] ?? 0);
                $sequence = (int)($person['sequence'] ?? ($index + 1));
                $personAmount = (int)($employeeAmounts[$index] ?? 0);
                if ($employeeId <= 0
                    || $employeeName === ''
                    || !in_array($employeeType, ['internal', 'partner', 'outsourced'], true)
                    || $employeeTypeVersion <= 0
                    || $weight <= 0
                    || $sequence <= 0) {
                    throw self::failure('sale_only_fact_salesperson_snapshot_invalid');
                }
                if (in_array($employeeType, ['partner', 'outsourced'], true)) {
                    $externalSalesAmount += $personAmount;
                }
                $identityArgs = [
                    (string)$order['tenant_id'],
                    (string)$order['order_id'],
                    (string)$orderLine['order_line_id'],
                    (string)$employeeId,
                    (string)$sequence,
                ];
                $performanceFacts[] = [
                    'factId' => $ids->salesPerformanceFactId(...$identityArgs),
                    'naturalKey' => $ids->salesPerformanceNaturalKey(...$identityArgs),
                    'factVersion' => 1,
                    'reversalOf' => '',
                    'status' => CashierV3CheckoutFactPlanV1::STATUS_EFFECTIVE,
                    'sourceLineId' => (string)$orderLine['order_line_id'],
                    'performanceType' => CashierV3CheckoutFactPlanV1::SALES_PERFORMANCE,
                    'employeeId' => $employeeId,
                    'employeeNameSnapshot' => $employeeName,
                    'employeeTypeSnapshot' => $employeeType,
                    'employeeTypeAuthorityVersion' => $employeeTypeVersion,
                    'roleSnapshot' => 'salesperson',
                    'allocationWeightNumerator' => $weight,
                    'allocationWeightDenominator' => 100,
                    'allocationBaseAmountCents' => $lineCash,
                    'amountCents' => $personAmount,
                    'ruleCodeSnapshot' => 'SALES-CASH-COLLECTED-V1',
                    'ruleNameSnapshot' => '按本次实收现金和销售分配比例计算',
                    'ruleVersionSnapshot' => 'v1',
                ];
              }
            }

            $craftsmenSnapshotJson = trim((string)($orderLine['craftsmen_snapshot_json'] ?? ''));
            if ((string)$orderLine['item_type'] === 'project'
                && $craftsmenSnapshotJson !== ''
                && $craftsmenSnapshotJson !== '[]') {
                try {
                    $craftsmanPlan = CashierV3PaidProjectCraftsmanPerformanceServices::planInTx(
                        $orderLine,
                        (string)$order['tenant_id']
                    );
                } catch (\InvalidArgumentException $exception) {
                    throw self::failure('sale_only_fact_craftsman_performance_plan_invalid');
                }
                foreach ($craftsmanPlan['allocations'] as $allocation) {
                    if ((int)$allocation['laborPerformanceCents'] === 0
                        && (int)$allocation['laborFeeCents'] === 0) {
                        continue;
                    }
                    $staffId = (int)$allocation['staffId'];
                    $naturalKey = 'sale-project-labor:' . (string)$order['order_id'] . ':'
                        . (string)$orderLine['order_line_id'] . ':' . $staffId;
                    $performanceFacts[] = [
                        'factId' => 'ELP-' . substr(hash('sha256', $naturalKey), 0, 40),
                        'naturalKey' => $naturalKey,
                        'factVersion' => 1,
                        'reversalOf' => '',
                        'status' => CashierV3CheckoutFactPlanV1::STATUS_EFFECTIVE,
                        'sourceLineId' => (string)$orderLine['order_line_id'],
                        'performanceType' => CashierV3CheckoutFactPlanV1::LABOR_PERFORMANCE,
                        'employeeId' => (int)$allocation['employeeId'],
                        'employeeNameSnapshot' => (string)$allocation['name'],
                        'employeeTypeSnapshot' => 'internal',
                        'employeeTypeAuthorityVersion' => 1,
                        'roleSnapshot' => !empty($allocation['isPrimary']) ? 'primary_craftsman' : 'craftsman',
                        'allocationWeightNumerator' => (int)$allocation['laborWeight'],
                        'allocationWeightDenominator' => 100,
                        'allocationBaseAmountCents' => (int)$craftsmanPlan['laborAmountCents'],
                        'amountCents' => (int)$allocation['laborPerformanceCents'],
                        'laborFeeAmountCents' => (int)$allocation['laborFeeCents'],
                        'projectCountHalfUnits' => (int)($allocation['projectCountHalfUnits'] ?? 0),
                        'ruleCodeSnapshot' => 'SALE-PROJECT-LABOR-V1',
                        'ruleNameSnapshot' => '项目劳动业绩',
                        'ruleVersionSnapshot' => 'project-rule:' . (int)$craftsmanPlan['ruleVersion'],
                    ];
                }
            }
        }
        $actualPerformanceAmount = $cashPerformanceAmount - $externalSalesAmount;
        $performanceFacts[] = [
            'factId' => $ids->actualPerformanceFactId(
                (string)$order['tenant_id'],
                (string)$order['order_id'],
                $actualSourceLineId
            ),
            'naturalKey' => $ids->actualPerformanceNaturalKey(
                (string)$order['tenant_id'],
                (string)$order['order_id'],
                $actualSourceLineId
            ),
            'factVersion' => 1,
            'reversalOf' => '',
            'status' => CashierV3CheckoutFactPlanV1::STATUS_EFFECTIVE,
            'sourceLineId' => $actualSourceLineId,
            'performanceType' => CashierV3CheckoutFactPlanV1::ACTUAL_PERFORMANCE,
            'employeeId' => 0,
            'employeeNameSnapshot' => '',
            'employeeTypeSnapshot' => '',
            'employeeTypeAuthorityVersion' => 0,
            'roleSnapshot' => 'checkout_result',
            'allocationWeightNumerator' => 1,
            'allocationWeightDenominator' => 1,
            'allocationBaseAmountCents' => $cashPerformanceAmount,
            'amountCents' => $actualPerformanceAmount,
            'ruleCodeSnapshot' => $salespeopleByCheckoutLine
                ? 'ACTUAL-NET-EXTERNAL-SALES-V1'
                : 'ACTUAL-NO-SALESPERSON-V1',
            'ruleNameSnapshot' => $salespeopleByCheckoutLine
                ? '实际业绩等于现金业绩扣除外部销售分配'
                : '暂无销售人分配，实际业绩等于现金业绩',
            'ruleVersionSnapshot' => 'v1',
        ];

        return CashierV3CheckoutFactPlanV1::fromInternalAuthority([
            'contractVersion' => CashierV3CheckoutFactPlanV1::CONTRACT_VERSION,
            'commandIdempotencyKey' => $finalCommandIdempotencyKey,
            'context' => [
                'tenantId' => (string)$order['tenant_id'],
                'tenantNameSnapshot' => '',
                'organizationId' => (string)$order['organization_id'],
                'organizationNameSnapshot' => (string)$order['organization_name_snapshot'],
                'organizationPathSnapshot' => (string)$order['organization_path_snapshot'],
                'storeId' => (int)$order['store_id'],
                'storeNameSnapshot' => (string)$order['store_name_snapshot'],
                'memberId' => (int)$order['member_id'],
                'memberNameSnapshot' => (string)$order['member_name_snapshot'],
                'operatorId' => (int)$order['operator_id'],
                'operatorNameSnapshot' => (string)$order['operator_name_snapshot'],
                'businessDate' => (string)$event['business_date'],
                'businessTimezone' => (string)$order['business_timezone'],
                'occurredAt' => (int)$event['occurred_at'],
                'settledAt' => (int)$event['settled_at'],
                'recordedAt' => (int)$event['recorded_at'],
                'checkoutRequestId' => (string)$order['checkout_request_id'],
                'orderId' => (string)$order['order_id'],
                'orderNoSnapshot' => (string)$order['order_no'],
                'sourceDocumentType' => (string)$order['source_document_type'],
                'businessEventNo' => (string)$event['event_no'],
                'businessSourcePrimaryId' => (int)$order['business_source_primary_id'],
                'businessSourcePrimaryNameSnapshot' => (string)$order['business_source_primary_name_snapshot'],
                'businessSourceSecondaryId' => (int)$order['business_source_secondary_id'],
                'businessSourceSecondaryNameSnapshot' => (string)$order['business_source_secondary_name_snapshot'],
                'businessSourceLabelSnapshot' => (string)$order['business_source_label_snapshot'],
            ],
            'saleFacts' => $saleFacts,
            'paymentFacts' => $paymentFacts,
            'balanceFacts' => $balanceFact === null ? [] : [$balanceFact],
            'performanceFacts' => $performanceFacts,
        ]);
    }

    private static function balanceFact(
        array $request,
        array $order,
        ?array $mutation,
        string $serverNamespaceSecret
    ): ?array {
        $amount = (int)$request['balance_deduction_amount_cents'];
        if ($amount === 0) {
            if ($mutation !== null) {
                throw self::failure('sale_only_fact_unexpected_balance_mutation');
            }
            return null;
        }
        if ($mutation === null) {
            throw self::failure('sale_only_fact_balance_mutation_missing');
        }
        foreach ([
            'authorityKey', 'accountId', 'memberId', 'accountVersionBefore',
            'accountVersionAfter', 'before', 'change', 'after', 'deductedTotalCents', 'ledgerId',
        ] as $key) {
            if (!array_key_exists($key, $mutation)) {
                throw self::failure('sale_only_fact_balance_mutation_incomplete', ['field' => $key]);
            }
        }
        if (!hash_equals((string)$request['balance_authority_key'], (string)$mutation['authorityKey'])
            || !hash_equals((string)$request['balance_account_id'], (string)$mutation['accountId'])
            || (int)$request['member_id'] !== (int)$mutation['memberId']
            || (int)$request['balance_account_version'] !== (int)$mutation['accountVersionBefore']
            || (int)$mutation['accountVersionAfter'] <= (int)$mutation['accountVersionBefore']
            || (int)$mutation['deductedTotalCents'] !== $amount
            || (int)$mutation['ledgerId'] <= 0
            || !is_array($mutation['change'])
            || !is_array($mutation['after'])) {
            throw self::failure('sale_only_fact_balance_mutation_mismatch');
        }
        $principalDelta = (int)($mutation['change']['principalCents'] ?? 0);
        $bonusDelta = (int)($mutation['change']['giftCents'] ?? 0);
        if ($principalDelta > 0
            || $bonusDelta > 0
            || $principalDelta + $bonusDelta !== -$amount) {
            throw self::failure('sale_only_fact_balance_delta_mismatch');
        }
        $ids = new CashierV3CheckoutFactIdFactory($serverNamespaceSecret);
        $ledgerId = (string)(int)$mutation['ledgerId'];
        return [
            'factId' => $ids->balanceFactId(
                (string)$order['tenant_id'],
                (string)$order['order_id'],
                $ledgerId
            ),
            'naturalKey' => $ids->balanceNaturalKey(
                (string)$order['tenant_id'],
                (string)$order['order_id'],
                $ledgerId
            ),
            'factVersion' => 1,
            'reversalOf' => '',
            'status' => CashierV3CheckoutFactPlanV1::STATUS_EFFECTIVE,
            'sourceLineId' => $ledgerId,
            'balanceChangeType' => 'order_payment',
            'balanceAccountId' => (string)$mutation['accountId'],
            'accountVersion' => (int)$mutation['accountVersionAfter'],
            'principalDeltaCents' => $principalDelta,
            'bonusDeltaCents' => $bonusDelta,
            'principalAfterCents' => (int)($mutation['after']['principalCents'] ?? -1),
            'bonusAfterCents' => (int)($mutation['after']['giftCents'] ?? -1),
        ];
    }

    private static function assertLockedAggregate(array $aggregate): array
    {
        self::assertExactKeys($aggregate, self::AGGREGATE_KEYS, 'sale_only_fact_locked_aggregate_shape_invalid');
        if (!is_array($aggregate['request'])
            || !is_array($aggregate['lines'])
            || !is_array($aggregate['payments'])
            || !is_array($aggregate['sources'])
            || !is_array($aggregate['currentRequest'])
            || !($aggregate['verifiedSources'] instanceof CashierV3CheckoutVerifiedSourceSet)) {
            throw self::failure('sale_only_fact_locked_aggregate_shape_invalid');
        }
        self::assertExactKeys($aggregate['request'], self::REQUEST_KEYS, 'sale_only_fact_request_shape_invalid');
        self::assertExactKeys($aggregate['currentRequest'], self::CURRENT_REQUEST_KEYS, 'sale_only_fact_current_request_shape_invalid');
        $request = $aggregate['request'];
        if ((string)$request['request_status'] !== 'ready_for_submit'
            || (string)$request['last_operation'] !== 'prepare_submission') {
            throw self::failure('sale_only_fact_checkout_not_ready');
        }
        $composition = (string)$request['composition'];
        if (!in_array($composition, ['sale_only', 'mixed'], true)) {
            throw self::failure('sale_only_fact_composition_required');
        }
        if ((int)$request['balance_deduction_amount_cents'] === 0
            && ((string)$request['balance_authority_key'] !== ''
                || (string)$request['balance_account_id'] !== ''
                || (int)$request['balance_account_version'] !== 0)) {
            throw self::failure('sale_only_fact_zero_balance_authority_must_be_empty');
        }
        if ((int)$request['balance_deduction_amount_cents'] > 0
            && ((string)$request['balance_authority_key'] === ''
                || (string)$request['balance_account_id'] === ''
                || (int)$request['balance_account_version'] <= 0)) {
            throw self::failure('sale_only_fact_balance_authority_incomplete');
        }
        $debtAmount = (int)$request['debt_amount_cents'];
        if (($debtAmount === 0
                && ((string)$request['debt_authority_key'] !== ''
                    || (int)$request['debt_policy_version'] !== 0))
            || ($debtAmount > 0
                && ((int)$request['member_id'] <= 0
                    || !hash_equals(
                        CashierV3CheckoutDebtAuthorityServices::authorityKey((int)$request['store_id']),
                        (string)$request['debt_authority_key']
                    )
                    || (int)$request['debt_policy_version']
                        !== CashierV3CheckoutDebtAuthorityServices::POLICY_VERSION))) {
            throw self::failure('sale_only_fact_debt_authority_invalid');
        }
        $current = $aggregate['currentRequest'];
        if ((string)$current['requestId'] !== (string)$request['request_id']
            || (string)$current['tenantId'] !== (string)$request['tenant_id']
            || (string)$current['workspaceId'] !== (string)$request['workspace_id']
            || (int)$current['version'] !== (int)$request['request_version']
            || (string)$current['status'] !== (string)$request['request_status']
            || (string)$current['creationIdempotencyKey'] !== (string)$request['creation_idempotency_key']
            || (string)$current['lastIdempotencyKey'] !== (string)$request['last_idempotency_key']
            || (string)$current['lastOperationFingerprint'] !== (string)$request['last_operation_fingerprint']) {
            throw self::failure('sale_only_fact_current_request_mismatch');
        }
        $saleLineCount = 0;
        $entitlementLineCount = 0;
        $entitlementActualAmountCents = 0;
        foreach ($aggregate['lines'] as $index => $line) {
            if (!is_array($line)) {
                throw self::failure('sale_only_fact_line_shape_invalid', ['index' => $index]);
            }
            self::assertExactKeys($line, self::LINE_KEYS, 'sale_only_fact_line_shape_invalid');
            $role = (string)$line['line_role'];
            if ($role === 'sale') {
                if ((int)$line['entitlement_source_detail_id'] !== 0
                    || (int)$line['entitlement_actual_amount_cents'] !== 0) {
                    throw self::failure('sale_only_fact_entitlement_line_forbidden', ['index' => $index]);
                }
                $saleLineCount++;
                continue;
            }
            if ($role !== 'entitlement_service'
                || (string)$line['source_type'] !== 'entitlement_project'
                || (int)$line['entitlement_source_detail_id'] <= 0
                || (int)$line['project_id'] <= 0
                || (int)$line['quantity'] <= 0
                || (int)$line['original_amount_cents'] !== 0
                || (int)$line['discount_amount_cents'] !== 0
                || (int)$line['sale_amount_cents'] !== 0
                || (int)$line['entitlement_actual_amount_cents'] < 0) {
                throw self::failure('sale_only_fact_entitlement_line_forbidden', ['index' => $index]);
            }
            $entitlementLineCount++;
            if ($entitlementActualAmountCents > PHP_INT_MAX - (int)$line['entitlement_actual_amount_cents']) {
                throw self::failure('sale_only_fact_amount_overflow', [
                    'field' => 'entitlement_actual_amount_cents',
                ]);
            }
            $entitlementActualAmountCents += (int)$line['entitlement_actual_amount_cents'];
        }
        if ($saleLineCount <= 0
            || ($composition === 'sale_only' && $entitlementLineCount !== 0)
            || ($composition === 'mixed' && $entitlementLineCount <= 0)
            || $entitlementActualAmountCents !== (int)$request['entitlement_actual_amount_cents']) {
            throw self::failure('sale_only_fact_composition_required');
        }
        foreach ($aggregate['payments'] as $index => $payment) {
            if (!is_array($payment)) {
                throw self::failure('sale_only_fact_payment_shape_invalid', ['index' => $index]);
            }
            self::assertExactKeys($payment, self::PAYMENT_KEYS, 'sale_only_fact_payment_shape_invalid');
        }
        return $request;
    }

    private static function assertSalesOrderPlan(
        array $request,
        array $aggregate,
        CashierV3SalesOrderPlanV1 $plan,
        array $order,
        array $orderLines,
        string $commandKey
    ): void {
        if ($plan->commandIdempotencyKey() !== $commandKey
            || (string)$order['command_idempotency_key'] !== $commandKey
            || (string)$order['checkout_request_id'] !== (string)$request['request_id']
            || (int)$order['checkout_request_version'] !== (int)$request['request_version']
            || (string)$order['tenant_id'] !== (string)$request['tenant_id']
            || (int)$order['store_id'] !== (int)$request['store_id']
            || (int)$order['member_id'] !== (int)$request['member_id']
            || (string)$order['composition'] !== (string)$request['composition']
            || (string)$order['order_note'] !== (string)$request['order_note']
            || (int)$order['supplement_enabled'] !== (int)$request['supplement_enabled']
            || (string)$order['supplement_reason'] !== (string)$request['supplement_reason']
            || (int)$order['supplement_operator_id'] !== (int)$request['supplement_operator_id']
            || (string)$order['supplement_operator_name_snapshot']
                !== (string)$request['supplement_operator_name_snapshot']
            || (int)$order['supplement_operated_at'] !== (int)$request['supplement_operated_at']
            || (string)$order['order_status'] !== 'settled'
            || (string)$order['order_direction'] !== 'forward'
            || (int)$order['order_version'] !== 1
            || (string)$order['reversal_of_order_id'] !== ''
            || (string)$order['immutable_fingerprint'] !== $plan->fingerprint()
            || (string)$order['checkout_source_set_fingerprint']
                !== $aggregate['verifiedSources']->fingerprint()) {
            throw self::failure('sale_only_fact_sales_order_plan_mismatch');
        }
        $saleRows = array_values(array_filter($aggregate['lines'], static function (array $line): bool {
            return (string)($line['line_role'] ?? '') === 'sale';
        }));
        if (count($orderLines) === 0 || count($orderLines) !== count($saleRows)) {
            throw self::failure('sale_only_fact_sales_order_line_count_mismatch');
        }
        // The sales plan is authoritative for the persisted order line. An
        // upgrade converts the source-right value into a separate settlement
        // component, so its formal sale row legitimately differs from the
        // original checkout draft row in amount fields and fingerprint.
        $plannedById = [];
        foreach ($plan->lines() as $line) {
            $plannedById[(string)$line['checkout_line_id']] = $line;
        }
        foreach ($orderLines as $index => $line) {
            $planned = $plannedById[(string)($line['checkout_line_id'] ?? '')] ?? null;
            if (!is_array($planned)
                || (string)$line['checkout_line_fingerprint'] !== (string)$planned['checkout_line_fingerprint']
                || (string)$line['item_type'] !== (string)$planned['item_type']
                || (string)$line['item_id'] !== (string)$planned['item_id']
                || (int)$line['catalog_sku_id'] !== (int)$planned['catalog_sku_id']
                || (int)$line['item_version'] !== (int)$planned['item_version']
                || (int)$line['quantity'] !== (int)$planned['quantity']
                || (int)$line['original_amount_cents'] !== (int)$planned['original_amount_cents']
                || (int)$line['discount_amount_cents'] !== (int)$planned['discount_amount_cents']
                || (int)$line['sale_amount_cents'] !== (int)$planned['sale_amount_cents']
                || (int)$line['debt_amount_cents'] !== (int)$planned['debt_amount_cents']
                || (int)$line['configured_cost_cents'] !== (int)$planned['configured_cost_cents']
                || (string)$line['price_change_reason'] !== (string)$planned['price_change_reason']
                || (int)$line['price_changed_by'] !== (int)$planned['price_changed_by']
                || (string)$line['price_changed_by_name_snapshot']
                    !== (string)$planned['price_changed_by_name_snapshot']
                || (int)$line['price_changed_at'] !== (int)$planned['price_changed_at']
                || (string)$line['item_name_snapshot'] !== (string)$planned['item_name_snapshot']
                || (string)$line['item_code_snapshot'] !== (string)$planned['item_code_snapshot']
                || (string)$line['category_id_snapshot'] !== (string)$planned['category_id_snapshot']
                || (string)$line['category_name_snapshot'] !== (string)$planned['category_name_snapshot']
                || (int)($line['manual_labor_fee_cents'] ?? 0) !== (int)($planned['manual_labor_fee_cents'] ?? 0)
                || (string)$line['line_status'] !== 'settled'
                || (string)$line['line_direction'] !== 'forward'
                || (int)$line['line_version'] !== 1
                || (string)$line['reversal_of_line_id'] !== '') {
                throw self::failure('sale_only_fact_sales_order_line_mismatch', ['index' => $index]);
            }
        }
    }

    private static function assertSalesOrderResult(
        CashierV3SalesOrderPlanV1 $plan,
        array $result,
        array $order,
        array $orderLines
    ): void {
        self::assertExactKeys($result, self::SALES_ORDER_RESULT_KEYS, 'sale_only_fact_sales_order_result_shape_invalid');
        self::assertExactKeys((array)($result['affected'] ?? []), ['headerRows', 'lineRows'], 'sale_only_fact_sales_order_result_shape_invalid');
        self::assertWriterResultMode(
            $result,
            (int)$result['affected']['headerRows'],
            (int)$result['affected']['lineRows'],
            count($orderLines),
            'sale_only_fact_sales_order_result_mode_invalid'
        );
        if ((string)$result['contractVersion'] !== CashierV3SalesOrderPlanV1::CONTRACT_VERSION
            || (string)$result['orderId'] !== (string)$order['order_id']
            || (string)$result['orderNo'] !== (string)$order['order_no']
            || (string)$result['checkoutRequestId'] !== (string)$order['checkout_request_id']
            || (string)$result['orderStatus'] !== 'settled'
            || (int)$result['orderVersion'] !== 1
            || (string)$result['planFingerprint'] !== $plan->fingerprint()) {
            throw self::failure('sale_only_fact_sales_order_result_mismatch');
        }
    }

    private static function assertPaymentCollectionPlan(
        array $request,
        array $aggregate,
        CashierV3PaymentCollectionPlanV1 $plan,
        array $batch,
        array $collections,
        array $order,
        string $commandKey
    ): void {
        if ($plan->commandIdempotencyKey() !== $commandKey
            || (string)$batch['command_idempotency_key'] !== $commandKey
            || (string)$batch['checkout_request_id'] !== (string)$request['request_id']
            || (int)$batch['checkout_request_version'] !== (int)$request['request_version']
            || (string)$batch['sales_order_id'] !== (string)$order['order_id']
            || (string)$batch['sales_order_fingerprint'] !== (string)$order['immutable_fingerprint']
            || (string)$batch['tenant_id'] !== (string)$request['tenant_id']
            || (int)$batch['store_id'] !== (int)$request['store_id']
            || (string)$batch['batch_status'] !== 'settled'
            || (string)$batch['batch_direction'] !== 'forward'
            || (int)$batch['batch_version'] !== 1
            || (string)$batch['reversal_of_batch_id'] !== ''
            || (string)$batch['immutable_fingerprint'] !== $plan->fingerprint()) {
            throw self::failure('sale_only_fact_payment_collection_plan_mismatch');
        }
        $hasNonCollectionSettlement = (int)($request['balance_deduction_amount_cents'] ?? 0) > 0
            || (int)($request['debt_amount_cents'] ?? 0) > 0;
        $positivePaymentCount = 0;
        $hasZeroAmountMethodSelection = false;
        foreach ($aggregate['payments'] as $payment) {
            $amount = (int)($payment['amount_cents'] ?? -1);
            if ($amount < 0) {
                throw self::failure('sale_only_fact_payment_amount_invalid');
            }
            if ($amount === 0) {
                $hasZeroAmountMethodSelection = true;
                continue;
            }
            $positivePaymentCount++;
        }
        $zeroReceivableMethodSelection = $hasZeroAmountMethodSelection
            && (int)($request['selected_payment_amount_cents'] ?? -1) === 0
            && (int)($request['receivable_amount_cents'] ?? -1) === 0;
        if ((count($collections) === 0 && !$hasNonCollectionSettlement && !$zeroReceivableMethodSelection)
            || count($collections) !== $positivePaymentCount
            || count($collections) !== (int)$batch['collection_count']) {
            throw self::failure('sale_only_fact_payment_collection_count_mismatch');
        }
        $lockedById = [];
        foreach ($aggregate['payments'] as $payment) {
            $lockedById[(string)$payment['payment_draft_id']] = $payment;
        }
        foreach ($collections as $index => $collection) {
            $locked = $lockedById[(string)($collection['checkout_payment_draft_id'] ?? '')] ?? null;
            if (!is_array($locked)
                || (string)$collection['checkout_payment_authority_key'] !== (string)$locked['payment_authority_key']
                || (string)$collection['checkout_payment_fingerprint'] !== (string)$locked['payment_fingerprint']
                || (string)$collection['payment_method'] !== (string)$locked['payment_method']
                || (int)$collection['amount_cents'] !== (int)$locked['amount_cents']
                || (string)$collection['collection_status'] !== 'settled'
                || (string)$collection['collection_direction'] !== 'forward'
                || (int)$collection['collection_version'] !== 1
                || (string)$collection['reversal_of_collection_id'] !== '') {
                throw self::failure('sale_only_fact_payment_collection_mismatch', ['index' => $index]);
            }
        }
    }

    private static function assertPaymentCollectionResult(
        CashierV3PaymentCollectionPlanV1 $plan,
        array $result,
        array $batch,
        array $collections
    ): void {
        self::assertExactKeys($result, self::PAYMENT_RESULT_KEYS, 'sale_only_fact_payment_result_shape_invalid');
        self::assertExactKeys((array)($result['affected'] ?? []), ['batchRows', 'collectionRows'], 'sale_only_fact_payment_result_shape_invalid');
        self::assertWriterResultMode(
            $result,
            (int)$result['affected']['batchRows'],
            (int)$result['affected']['collectionRows'],
            count($collections),
            'sale_only_fact_payment_result_mode_invalid'
        );
        $collectionIds = array_values((array)($result['collectionIds'] ?? []));
        if ((string)$result['contractVersion'] !== CashierV3PaymentCollectionPlanV1::CONTRACT_VERSION
            || (string)$result['batchId'] !== (string)$batch['batch_id']
            || (string)$result['salesOrderId'] !== (string)$batch['sales_order_id']
            || (string)$result['checkoutRequestId'] !== (string)$batch['checkout_request_id']
            || $collectionIds !== array_values(array_column($collections, 'collection_id'))
            || (int)$result['collectionCount'] !== count($collections)
            || (int)$result['collectedAmountCents'] !== (int)$batch['collected_amount_cents']
            || (int)$result['cashPerformanceAmountCents'] !== (int)$batch['cash_performance_amount_cents']
            || (string)$result['planFingerprint'] !== $plan->fingerprint()) {
            throw self::failure('sale_only_fact_payment_result_mismatch');
        }
    }

    private static function assertCompletedEvent(
        array $event,
        array $request,
        array $order,
        array $batch,
        array $collections,
        string $commandKey
    ): array {
        self::assertExactKeys($event, self::EVENT_AUTHORITY_KEYS, 'sale_only_fact_event_shape_invalid');
        $expectedKey = self::EVENT_TYPE . ':sales_order:' . (string)$order['order_id'] . ':1';
        if ((int)$event['event_id'] <= 0
            || preg_match('/^EV-[0-9a-f]{32}$/Di', (string)$event['event_no']) !== 1
            || (string)$event['event_key'] !== $expectedKey
            || (string)$event['event_type'] !== self::EVENT_TYPE
            || (string)$event['aggregate_type'] !== 'sales_order'
            || (string)$event['aggregate_id'] !== (string)$order['order_id']
            || (string)$event['aggregate_name_snapshot'] !== (string)$order['order_no']
            || (int)$event['aggregate_version'] !== 1
            || (string)$event['source_type'] !== 'submit-checkout'
            || (string)$event['source_id'] !== (string)$request['request_id']
            || (string)$event['command_idempotency_key'] !== $commandKey
            || (string)$event['business_date'] !== (string)$request['business_date']
            || (int)$event['occurred_at'] <= 0
            || (int)$event['settled_at'] < (int)$event['occurred_at']
            || (int)$event['recorded_at'] < (int)$event['settled_at']
            || (int)$event['occurred_at'] !== (int)$order['occurred_at']
            || (int)$event['settled_at'] !== (int)$order['settled_at']
            || (int)$event['recorded_at'] !== (int)$order['recorded_at']
            || (string)$event['business_date'] !== (string)$batch['business_date']
            || (int)$event['occurred_at'] !== (int)$batch['occurred_at']
            || (int)$event['settled_at'] !== (int)$batch['settled_at']
            || (int)$event['recorded_at'] !== (int)$batch['recorded_at']) {
            throw self::failure('sale_only_fact_event_authority_mismatch');
        }
        foreach ($collections as $collection) {
            if ((string)$collection['business_date'] !== (string)$event['business_date']
                || (int)$collection['occurred_at'] !== (int)$event['occurred_at']
                || (int)$collection['settled_at'] !== (int)$event['settled_at']
                || (int)$collection['recorded_at'] !== (int)$event['recorded_at']) {
                throw self::failure('sale_only_fact_collection_event_time_mismatch');
            }
        }
        return $event;
    }

    private static function assertTotals(
        array $request,
        array $order,
        array $orderLines,
        array $batch,
        array $collections
    ): void {
        $original = self::sum($orderLines, 'original_amount_cents');
        $discount = self::sum($orderLines, 'discount_amount_cents');
        $sale = self::sum($orderLines, 'sale_amount_cents');
        $lineDebt = self::sum($orderLines, 'debt_amount_cents');
        $collected = self::sum($collections, 'amount_cents');
        $balance = (int)$request['balance_deduction_amount_cents'];
        $debt = (int)$request['debt_amount_cents'];
        // A card/project upgrade records the target card's formal sale amount
        // while retaining the old right value as a separate entitlement-credit
        // settlement component. The locked sales plan has already bound that
        // credit to the source operation; do not misclassify it as a discount.
        $entitlementCredit = $sale - (int)$request['sales_amount_cents'];
        if ($sale < 0
            || $entitlementCredit < 0
            || $original - $discount !== $sale
            || $original !== (int)$order['original_amount_cents']
            || $discount !== (int)$order['discount_amount_cents']
            || $sale !== (int)$order['sale_amount_cents']
            || $collected !== (int)$request['selected_payment_amount_cents']
            || $collected !== (int)$request['cash_performance_amount_cents']
            || $collected + $balance + $debt + $entitlementCredit !== $sale
            || $lineDebt !== $debt
            || $collected !== (int)$batch['collected_amount_cents']
            || $collected !== (int)$batch['cash_performance_amount_cents']
            || (int)$batch['receivable_amount_cents'] !== (int)$request['receivable_amount_cents']) {
            throw self::failure('sale_only_fact_total_equation_mismatch');
        }
        foreach ($orderLines as $index => $line) {
            if ((int)$line['sale_amount_cents'] < 0
                || (int)$line['debt_amount_cents'] < 0
                || (int)$line['debt_amount_cents'] > (int)$line['sale_amount_cents']
                || (int)$line['original_amount_cents'] - (int)$line['discount_amount_cents']
                    !== (int)$line['sale_amount_cents']) {
                throw self::failure('sale_only_fact_line_amount_equation_mismatch', ['index' => $index]);
            }
        }
        foreach ($collections as $index => $collection) {
            if ((int)$collection['amount_cents'] <= 0
                || (int)$collection['cash_performance_amount_cents']
                    !== (int)$collection['amount_cents']) {
                throw self::failure('sale_only_fact_collection_amount_invalid', ['index' => $index]);
            }
        }
    }

    private static function assertWriterResultMode(
        array $result,
        int $parentRows,
        int $detailRows,
        int $expectedDetails,
        string $reason
    ): void {
        if (!is_bool($result['replayed'] ?? null)
            || !is_bool($result['businessEffectsWritten'] ?? null)) {
            throw self::failure($reason);
        }
        $inserted = $result['replayed'] === false
            && $result['businessEffectsWritten'] === true
            && $parentRows === 1
            && $detailRows === $expectedDetails;
        $replayed = $result['replayed'] === true
            && $result['businessEffectsWritten'] === false
            && $parentRows === 0
            && $detailRows === 0;
        if (!$inserted && !$replayed) {
            throw self::failure($reason);
        }
    }

    /** @return array<string,int> */
    private static function allocateAcrossOrderLines(int $amount, array $lines): array
    {
        if ($amount === 0) {
            return array_fill_keys(array_column($lines, 'order_line_id'), 0);
        }
        $total = 0;
        foreach ($lines as $line) {
            $total += (int)($line['sale_amount_cents'] ?? 0);
        }
        if ($amount < 0 || $total <= 0 || $amount > $total) {
            throw self::failure('sale_only_fact_line_allocation_total_invalid');
        }

        $result = [];
        $remainders = [];
        $allocated = 0;
        foreach (array_values($lines) as $index => $line) {
            $lineId = trim((string)($line['order_line_id'] ?? ''));
            $lineAmount = (int)($line['sale_amount_cents'] ?? 0);
            if ($lineId === '' || $lineAmount <= 0 || isset($result[$lineId])) {
                throw self::failure('sale_only_fact_line_allocation_line_invalid', ['index' => $index]);
            }
            $product = bcmul((string)$amount, (string)$lineAmount, 0);
            $share = (int)bcdiv($product, (string)$total, 0);
            $result[$lineId] = $share;
            $remainders[] = [
                'lineId' => $lineId,
                'remainder' => (int)bcmod($product, (string)$total),
                'index' => $index,
            ];
            $allocated += $share;
        }
        usort($remainders, static function (array $left, array $right): int {
            return $right['remainder'] <=> $left['remainder']
                ?: $left['index'] <=> $right['index'];
        });
        for ($remaining = $amount - $allocated, $index = 0; $remaining > 0; $remaining--, $index++) {
            $result[$remainders[$index]['lineId']]++;
        }
        return $result;
    }

    /** @return array<int,int> */
    private static function allocateByWeights(int $amount, array $people): array
    {
        $result = [];
        $remainders = [];
        $allocated = 0;
        foreach ($people as $index => $person) {
            $weight = (int)($person['allocationWeight'] ?? 0);
            $product = $amount * $weight;
            $share = intdiv($product, 100);
            $result[$index] = $share;
            $remainders[] = ['index' => $index, 'remainder' => $product % 100];
            $allocated += $share;
        }
        usort($remainders, static function (array $left, array $right): int {
            return $right['remainder'] <=> $left['remainder']
                ?: $left['index'] <=> $right['index'];
        });
        for ($remaining = $amount - $allocated, $index = 0; $remaining > 0; $remaining--, $index++) {
            $result[$remainders[$index]['index']]++;
        }
        ksort($result, SORT_NUMERIC);
        return $result;
    }

    private static function assertFinalCommandKey(string $key): void
    {
        if (strlen($key) > 128
            || preg_match(
                '/^CHECKOUT-[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}'
                . '-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
                $key
            ) !== 1) {
            throw self::failure('sale_only_fact_final_command_key_invalid');
        }
    }

    private static function sum(array $rows, string $field): int
    {
        $total = 0;
        foreach ($rows as $row) {
            if (!array_key_exists($field, $row) || !is_int($row[$field])) {
                throw self::failure('sale_only_fact_integer_amount_required', ['field' => $field]);
            }
            if ($row[$field] > 0 && $total > PHP_INT_MAX - $row[$field]) {
                throw self::failure('sale_only_fact_amount_overflow', ['field' => $field]);
            }
            $total += $row[$field];
        }
        return $total;
    }

    private static function assertExactKeys(array $value, array $required, string $reason): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($required, SORT_STRING);
        if ($actual !== $required) {
            throw self::failure($reason, ['actualKeys' => $actual, 'requiredKeys' => $required]);
        }
    }

    private static function failure(
        string $reason,
        array $detail = []
    ): CashierV3CheckoutFactContractException {
        return new CashierV3CheckoutFactContractException($reason, $detail);
    }
}
