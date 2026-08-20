<?php

namespace app\services\cashier\v3\fact;

/**
 * Internal transaction hand-off for final checkout facts.
 *
 * This is deliberately independent from the unfinished HTTP/orchestrator DTO.
 * Its input must be assembled from locked business authorities, never directly
 * from a client request. Every returned row is complete and immutable.
 */
final class CashierV3CheckoutFactPlanV1
{
    public const CONTRACT_VERSION = 'cashier-v3-checkout-fact-plan-v1';

    public const SALE_COMPLETED = 'sale_completed';
    public const PAYMENT_COLLECTED = 'payment_collected';
    public const BALANCE_CHANGED = 'balance_changed';
    public const SALES_PERFORMANCE = 'sales_performance_allocated';
    public const ACTUAL_PERFORMANCE = 'actual_performance_recorded';
    public const CONSUMPTION_PERFORMANCE = 'consumption_performance_recorded';
    public const LABOR_PERFORMANCE = 'labor_performance_allocated';

    public const STATUS_EFFECTIVE = 'effective';
    public const DIRECTION_FORWARD = 'forward';
    public const DIRECTION_REVERSAL = 'reversal';

    private const PAYMENT_METHODS = [
        'unionpay',
        'wechat',
        'alipay',
        'dianping_voucher',
        'douyin_voucher',
        'partner_collection',
        'other_collection',
    ];

    private const PERFORMANCE_TYPES = [
        self::SALES_PERFORMANCE,
        self::ACTUAL_PERFORMANCE,
        self::CONSUMPTION_PERFORMANCE,
        self::LABOR_PERFORMANCE,
    ];

    private const EMPLOYEE_TYPES = ['internal', 'partner', 'outsourced'];

    private const CONTEXT_KEYS = [
        'tenantId', 'tenantNameSnapshot', 'organizationId',
        'organizationNameSnapshot', 'organizationPathSnapshot', 'storeId',
        'storeNameSnapshot', 'memberId', 'memberNameSnapshot', 'operatorId',
        'operatorNameSnapshot', 'businessDate', 'businessTimezone', 'occurredAt',
        'settledAt', 'recordedAt', 'checkoutRequestId', 'orderId',
        'orderNoSnapshot', 'sourceDocumentType', 'businessEventNo',
        'businessSourcePrimaryId', 'businessSourcePrimaryNameSnapshot',
        'businessSourceSecondaryId', 'businessSourceSecondaryNameSnapshot',
        'businessSourceLabelSnapshot',
    ];

    private const COMMON_FACT_KEYS = [
        'factId', 'naturalKey', 'factVersion', 'reversalOf', 'status', 'sourceLineId',
    ];

    private const SALE_KEYS = [
        'sourceType', 'itemId', 'itemCodeSnapshot', 'itemNameSnapshot',
        'categoryIdSnapshot', 'categoryNameSnapshot', 'quantity',
        'friendCountsAsCustomer',
        'isPresale', 'inventoryOutboundRequired',
        'originalAmountCents', 'discountAmountCents', 'couponUserId',
        'couponNameSnapshot', 'couponDiscountCents', 'saleAmountCents', 'debtAmountCents',
    ];

    private const PAYMENT_KEYS = [
        'paymentMethod', 'paymentAuthorityKey', 'collectionReference', 'amountCents',
    ];

    private const BALANCE_KEYS = [
        'balanceChangeType', 'balanceAccountId', 'accountVersion',
        'principalDeltaCents', 'bonusDeltaCents', 'principalAfterCents',
        'bonusAfterCents',
    ];

    private const PERFORMANCE_KEYS = [
        'performanceType', 'employeeId', 'employeeNameSnapshot',
        'employeeTypeSnapshot', 'employeeTypeAuthorityVersion', 'roleSnapshot',
        'allocationWeightNumerator', 'allocationWeightDenominator',
        'allocationBaseAmountCents', 'amountCents', 'ruleCodeSnapshot',
        'ruleNameSnapshot', 'ruleVersionSnapshot',
    ];

    private $commandIdempotencyKey;
    private $context;
    private $rows;
    private $fingerprint;

    private function __construct(
        string $commandIdempotencyKey,
        array $context,
        array $rows,
        string $fingerprint
    ) {
        $this->commandIdempotencyKey = $commandIdempotencyKey;
        $this->context = $context;
        $this->rows = $rows;
        $this->fingerprint = $fingerprint;
    }

    public static function fromInternalAuthority(array $plan): self
    {
        self::assertExactKeys($plan, [
            'contractVersion', 'commandIdempotencyKey', 'context', 'saleFacts',
            'paymentFacts', 'balanceFacts', 'performanceFacts',
        ], 'plan');
        if ($plan['contractVersion'] !== self::CONTRACT_VERSION) {
            throw self::failure('fact_plan_version_invalid');
        }

        $commandKey = self::requiredToken(
            $plan['commandIdempotencyKey'],
            128,
            'command_idempotency_key_invalid'
        );
        if (!is_array($plan['context'])) {
            throw self::failure('fact_context_invalid');
        }
        $context = self::normalizeContext($plan['context']);

        $domainInput = [
            'sale' => $plan['saleFacts'],
            'payment' => $plan['paymentFacts'],
            'balance' => $plan['balanceFacts'],
            'performance' => $plan['performanceFacts'],
        ];
        $rows = [];
        $seenFactIds = [];
        $seenNaturalKeys = [];
        foreach ($domainInput as $domain => $facts) {
            if (!is_array($facts)) {
                throw self::failure('fact_rows_invalid', ['domain' => $domain]);
            }
            $rows[$domain] = [];
            foreach (array_values($facts) as $index => $fact) {
                if (!is_array($fact)) {
                    throw self::failure('fact_row_invalid', ['domain' => $domain, 'index' => $index]);
                }
                $row = self::normalizeFact($domain, $fact, $context, $commandKey);
                $factId = $row['fact_id'];
                $naturalIdentity = $domain . '|' . $row['natural_key'];
                if (isset($seenFactIds[$factId])) {
                    throw self::failure('fact_id_duplicate', ['factId' => $factId]);
                }
                if (isset($seenNaturalKeys[$naturalIdentity])) {
                    throw self::failure('fact_natural_key_duplicate', ['naturalKey' => $row['natural_key']]);
                }
                $seenFactIds[$factId] = true;
                $seenNaturalKeys[$naturalIdentity] = true;
                $rows[$domain][] = $row;
            }
        }

        self::assertActualPerformanceIsMaterialized($rows['payment'], $rows['performance']);

        $fingerprint = self::canonicalFingerprint([
            'contractVersion' => self::CONTRACT_VERSION,
            'commandIdempotencyKey' => $commandKey,
            'context' => $context,
            'rows' => $rows,
        ]);
        return new self($commandKey, $context, $rows, $fingerprint);
    }

    public static function paymentMethods(): array
    {
        return self::PAYMENT_METHODS;
    }

    public static function performanceTypes(): array
    {
        return self::PERFORMANCE_TYPES;
    }

    public function commandIdempotencyKey(): string
    {
        return $this->commandIdempotencyKey;
    }

    public function context(): array
    {
        return $this->context;
    }

    public function rows(): array
    {
        return $this->rows;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    private static function normalizeContext(array $context): array
    {
        self::assertExactKeys($context, self::CONTEXT_KEYS, 'context');
        $normalized = [
            'tenant_id' => self::requiredToken($context['tenantId'], 32, 'tenant_id_invalid'),
            'tenant_name_snapshot' => self::text($context['tenantNameSnapshot'], 128, 'tenant_name_snapshot_invalid'),
            'organization_id' => self::requiredToken($context['organizationId'], 32, 'organization_id_invalid'),
            'organization_name_snapshot' => self::text($context['organizationNameSnapshot'], 128, 'organization_name_snapshot_invalid'),
            'organization_path_snapshot' => self::text($context['organizationPathSnapshot'], 512, 'organization_path_snapshot_invalid'),
            'store_id' => self::positiveInt($context['storeId'], 'store_id_invalid'),
            'store_name_snapshot' => self::text($context['storeNameSnapshot'], 128, 'store_name_snapshot_invalid'),
            'member_id' => self::nonNegativeInt($context['memberId'], 'member_id_invalid'),
            'member_name_snapshot' => self::text($context['memberNameSnapshot'], 128, 'member_name_snapshot_invalid'),
            'operator_id' => self::positiveInt($context['operatorId'], 'operator_id_invalid'),
            'operator_name_snapshot' => self::text($context['operatorNameSnapshot'], 128, 'operator_name_snapshot_invalid'),
            'business_date' => self::businessDate($context['businessDate']),
            'business_timezone' => self::timezone($context['businessTimezone']),
            'occurred_at' => self::positiveInt($context['occurredAt'], 'occurred_at_invalid'),
            'settled_at' => self::positiveInt($context['settledAt'], 'settled_at_invalid'),
            'recorded_at' => self::positiveInt($context['recordedAt'], 'recorded_at_invalid'),
            'checkout_request_id' => self::requiredToken($context['checkoutRequestId'], 64, 'checkout_request_id_invalid'),
            'order_id' => self::requiredToken($context['orderId'], 64, 'order_id_invalid'),
            'order_no_snapshot' => self::text($context['orderNoSnapshot'], 64, 'order_no_snapshot_invalid'),
            'source_document_type' => self::requiredToken($context['sourceDocumentType'], 32, 'source_document_type_invalid'),
            'business_event_no' => self::requiredToken($context['businessEventNo'], 64, 'business_event_no_invalid'),
            'business_source_primary_id' => self::nonNegativeInt($context['businessSourcePrimaryId'], 'business_source_primary_id_invalid'),
            'business_source_primary_name_snapshot' => self::text($context['businessSourcePrimaryNameSnapshot'], 64, 'business_source_primary_name_invalid'),
            'business_source_secondary_id' => self::nonNegativeInt($context['businessSourceSecondaryId'], 'business_source_secondary_id_invalid'),
            'business_source_secondary_name_snapshot' => self::text($context['businessSourceSecondaryNameSnapshot'], 64, 'business_source_secondary_name_invalid'),
            'business_source_label_snapshot' => self::text($context['businessSourceLabelSnapshot'], 140, 'business_source_label_invalid'),
        ];
        // Customer source is attribution metadata from the browser snapshot.
        // Preserve whatever was captured without requiring the live source
        // projection or name fields to agree at settlement time.
        if ($normalized['settled_at'] < $normalized['occurred_at']
            || $normalized['recorded_at'] < $normalized['occurred_at']) {
            throw self::failure('fact_time_order_invalid');
        }
        return $normalized;
    }

    private static function normalizeFact(
        string $domain,
        array $fact,
        array $context,
        string $commandKey
    ): array {
        $specificKeys = self::specificKeys($domain);
        $requiredKeys = array_merge(self::COMMON_FACT_KEYS, $specificKeys);
        if ($domain === 'performance') {
            self::assertAllowedKeys(
                $fact,
                $requiredKeys,
                array_merge($requiredKeys, ['laborFeeAmountCents']),
                $domain
            );
        } else {
            self::assertExactKeys($fact, $requiredKeys, $domain);
        }
        $reversalOf = self::optionalToken($fact['reversalOf'], 64, 'reversal_of_invalid');
        $direction = $reversalOf === '' ? self::DIRECTION_FORWARD : self::DIRECTION_REVERSAL;
        if ($fact['status'] !== self::STATUS_EFFECTIVE) {
            throw self::failure('fact_status_invalid', ['domain' => $domain]);
        }
        $base = array_merge([
            'fact_id' => self::requiredToken($fact['factId'], 64, 'fact_id_invalid'),
            'business_event_no' => $context['business_event_no'],
            'fact_type' => self::factType($domain, $fact),
            'fact_direction' => $direction,
            'natural_key' => self::requiredToken($fact['naturalKey'], 160, 'natural_key_invalid'),
            'command_idempotency_key' => $commandKey,
            'fact_version' => self::positiveInt($fact['factVersion'], 'fact_version_invalid'),
            'reversal_of' => $reversalOf,
            'status' => self::STATUS_EFFECTIVE,
        ], $context, [
            'source_line_id' => self::requiredToken($fact['sourceLineId'], 64, 'source_line_id_invalid'),
        ]);

        if ($base['fact_id'] === $base['reversal_of']) {
            throw self::failure('fact_self_reversal_forbidden');
        }
        $specific = self::normalizeSpecific($domain, $fact, $direction);
        $row = array_merge($base, $specific);
        $row['immutable_fingerprint'] = self::canonicalFingerprint([
            'domain' => $domain,
            'row' => $row,
        ]);
        return $row;
    }

    private static function normalizeSpecific(string $domain, array $fact, string $direction): array
    {
        if ($domain === 'sale') {
            $row = [
                'source_type' => self::requiredToken($fact['sourceType'], 32, 'sale_source_type_invalid'),
                'item_id' => self::requiredToken($fact['itemId'], 64, 'sale_item_id_invalid'),
                'item_code_snapshot' => self::text($fact['itemCodeSnapshot'], 64, 'sale_item_code_invalid'),
                'item_name_snapshot' => self::text($fact['itemNameSnapshot'], 255, 'sale_item_name_invalid'),
                'category_id_snapshot' => self::optionalToken($fact['categoryIdSnapshot'], 64, 'sale_category_id_invalid'),
                'category_name_snapshot' => self::text($fact['categoryNameSnapshot'], 128, 'sale_category_name_invalid'),
                'quantity' => self::positiveInt($fact['quantity'], 'sale_quantity_invalid'),
                'friend_counts_as_customer' => self::nonNegativeInt(
                    $fact['friendCountsAsCustomer'],
                    'sale_friend_counts_as_customer_invalid'
                ),
                'is_presale' => self::nonNegativeInt($fact['isPresale'] ?? 0, 'sale_is_presale_invalid'),
                'inventory_outbound_required' => self::nonNegativeInt($fact['inventoryOutboundRequired'] ?? 1, 'sale_inventory_outbound_required_invalid'),
                'original_amount_cents' => self::signedMoney($fact['originalAmountCents'], $direction, 'sale_original_amount_invalid'),
                'discount_amount_cents' => self::signedMoney($fact['discountAmountCents'], $direction, 'sale_discount_amount_invalid', true),
                'coupon_user_id' => self::nonNegativeInt($fact['couponUserId'], 'sale_coupon_user_invalid'),
                'coupon_name_snapshot' => self::text($fact['couponNameSnapshot'], 128, 'sale_coupon_name_invalid'),
                'coupon_discount_cents' => self::signedMoney($fact['couponDiscountCents'], $direction, 'sale_coupon_discount_invalid', true),
                'sale_amount_cents' => self::signedMoney($fact['saleAmountCents'], $direction, 'sale_amount_invalid'),
                'debt_amount_cents' => self::signedMoney($fact['debtAmountCents'], $direction, 'sale_debt_amount_invalid', true),
            ];
            if ($row['friend_counts_as_customer'] > 1 || $row['is_presale'] > 1 || $row['inventory_outbound_required'] > 1 || ($row['is_presale'] === 1 && $row['inventory_outbound_required'] === 1)
                || $row['original_amount_cents'] - $row['discount_amount_cents'] !== $row['sale_amount_cents']
                || abs($row['debt_amount_cents']) > abs($row['sale_amount_cents'])
                || abs($row['coupon_discount_cents']) > abs($row['discount_amount_cents'])
                || ($row['coupon_user_id'] === 0
                    ? ($row['coupon_name_snapshot'] !== '' || $row['coupon_discount_cents'] !== 0)
                    : ($row['coupon_name_snapshot'] === '' || $row['coupon_discount_cents'] === 0))) {
                throw self::failure('sale_amount_equation_invalid');
            }
            return $row;
        }
        if ($domain === 'payment') {
            $method = self::requiredToken($fact['paymentMethod'], 32, 'payment_method_invalid');
            if ($method === 'old_card_entry') {
                throw self::failure('old_card_entry_payment_fact_forbidden');
            }
            if (!in_array($method, self::PAYMENT_METHODS, true)) {
                throw self::failure('payment_method_invalid', ['paymentMethod' => $method]);
            }
            return [
                'payment_method' => $method,
                'payment_authority_key' => self::requiredToken($fact['paymentAuthorityKey'], 96, 'payment_authority_key_invalid'),
                'collection_reference' => self::text($fact['collectionReference'], 128, 'collection_reference_invalid'),
                'amount_cents' => self::signedMoney($fact['amountCents'], $direction, 'payment_amount_invalid'),
            ];
        }
        if ($domain === 'balance') {
            $principalDelta = self::signedInteger($fact['principalDeltaCents'], 'principal_delta_invalid');
            $bonusDelta = self::signedInteger($fact['bonusDeltaCents'], 'bonus_delta_invalid');
            if ($principalDelta === 0 && $bonusDelta === 0) {
                throw self::failure('balance_delta_empty');
            }
            if ($direction === self::DIRECTION_FORWARD && $principalDelta + $bonusDelta === 0) {
                throw self::failure('balance_net_delta_zero');
            }
            return [
                'balance_change_type' => self::requiredToken($fact['balanceChangeType'], 32, 'balance_change_type_invalid'),
                'balance_account_id' => self::requiredToken($fact['balanceAccountId'], 64, 'balance_account_id_invalid'),
                'account_version' => self::positiveInt($fact['accountVersion'], 'balance_account_version_invalid'),
                'principal_delta_cents' => $principalDelta,
                'bonus_delta_cents' => $bonusDelta,
                'principal_after_cents' => self::nonNegativeInt($fact['principalAfterCents'], 'principal_after_invalid'),
                'bonus_after_cents' => self::nonNegativeInt($fact['bonusAfterCents'], 'bonus_after_invalid'),
            ];
        }

        $type = (string)$fact['performanceType'];
        if (!in_array($type, self::PERFORMANCE_TYPES, true)) {
            throw self::failure('performance_type_invalid');
        }
        $employeeId = self::nonNegativeInt($fact['employeeId'], 'performance_employee_id_invalid');
        $employeeType = self::optionalToken($fact['employeeTypeSnapshot'], 16, 'performance_employee_type_invalid');
        $employeeTypeVersion = self::nonNegativeInt($fact['employeeTypeAuthorityVersion'], 'performance_employee_type_version_invalid');
        if (in_array($type, [self::SALES_PERFORMANCE, self::LABOR_PERFORMANCE], true)) {
            if ($employeeId <= 0
                || !in_array($employeeType, self::EMPLOYEE_TYPES, true)
                || $employeeTypeVersion <= 0
                || self::text($fact['employeeNameSnapshot'], 128, 'performance_employee_name_invalid') === '') {
                throw self::failure('performance_employee_snapshot_required', ['performanceType' => $type]);
            }
        } elseif ($employeeId !== 0 || $employeeType !== '' || $employeeTypeVersion !== 0) {
            throw self::failure('aggregate_performance_employee_must_be_empty', ['performanceType' => $type]);
        }
        $numerator = self::nonNegativeInt($fact['allocationWeightNumerator'], 'performance_weight_numerator_invalid');
        $denominator = self::positiveInt($fact['allocationWeightDenominator'], 'performance_weight_denominator_invalid');
        if ($numerator > $denominator) {
            throw self::failure('performance_weight_invalid');
        }
        return [
            'performance_type' => $type,
            'employee_id' => $employeeId,
            'employee_name_snapshot' => self::text($fact['employeeNameSnapshot'], 128, 'performance_employee_name_invalid'),
            'employee_type_snapshot' => $employeeType,
            'employee_type_authority_version' => $employeeTypeVersion,
            'role_snapshot' => self::text($fact['roleSnapshot'], 64, 'performance_role_invalid'),
            'allocation_weight_numerator' => $numerator,
            'allocation_weight_denominator' => $denominator,
            'allocation_base_amount_cents' => self::signedMoney($fact['allocationBaseAmountCents'], $direction, 'performance_base_amount_invalid', true),
            'amount_cents' => self::signedMoney($fact['amountCents'], $direction, 'performance_amount_invalid', true),
            'labor_fee_amount_cents' => self::signedMoney($fact['laborFeeAmountCents'] ?? 0, $direction, 'performance_labor_fee_amount_invalid', true),
            'rule_code_snapshot' => self::requiredToken($fact['ruleCodeSnapshot'], 64, 'performance_rule_code_invalid'),
            'rule_name_snapshot' => self::text($fact['ruleNameSnapshot'], 128, 'performance_rule_name_invalid'),
            'rule_version_snapshot' => self::requiredToken($fact['ruleVersionSnapshot'], 64, 'performance_rule_version_invalid'),
        ];
    }

    private static function assertActualPerformanceIsMaterialized(array $payments, array $performance): void
    {
        foreach ([self::DIRECTION_FORWARD, self::DIRECTION_REVERSAL] as $direction) {
            $cash = 0;
            foreach ($payments as $row) {
                if ($row['fact_direction'] === $direction) {
                    $cash += $row['amount_cents'];
                }
            }
            $external = 0;
            $actual = 0;
            $actualRows = 0;
            $salesRows = 0;
            foreach ($performance as $row) {
                if ($row['fact_direction'] !== $direction) {
                    continue;
                }
                if ($row['performance_type'] === self::SALES_PERFORMANCE) {
                    $salesRows++;
                    if (in_array($row['employee_type_snapshot'], ['partner', 'outsourced'], true)) {
                        $external += $row['amount_cents'];
                    }
                }
                if ($row['performance_type'] === self::ACTUAL_PERFORMANCE) {
                    $actual += $row['amount_cents'];
                    $actualRows++;
                }
            }
            if (($cash !== 0 || $salesRows > 0 || $actualRows > 0) && $actualRows === 0) {
                throw self::failure('actual_performance_fact_required', ['direction' => $direction]);
            }
            if ($actualRows > 0 && $actual !== $cash - $external) {
                throw self::failure('actual_performance_materialized_amount_invalid', [
                    'direction' => $direction,
                    'cashAmountCents' => $cash,
                    'externalAmountCents' => $external,
                    'actualAmountCents' => $actual,
                ]);
            }
        }
    }

    private static function factType(string $domain, array $fact): string
    {
        if ($domain === 'sale') {
            return self::SALE_COMPLETED;
        }
        if ($domain === 'payment') {
            return self::PAYMENT_COLLECTED;
        }
        if ($domain === 'balance') {
            return self::BALANCE_CHANGED;
        }
        return (string)$fact['performanceType'];
    }

    private static function specificKeys(string $domain): array
    {
        if ($domain === 'sale') {
            return self::SALE_KEYS;
        }
        if ($domain === 'payment') {
            return self::PAYMENT_KEYS;
        }
        if ($domain === 'balance') {
            return self::BALANCE_KEYS;
        }
        return self::PERFORMANCE_KEYS;
    }

    private static function signedMoney($value, string $direction, string $reason, bool $zeroAllowed = false): int
    {
        $amount = self::signedInteger($value, $reason);
        if (!$zeroAllowed && $amount === 0) {
            throw self::failure($reason);
        }
        if ($direction === self::DIRECTION_FORWARD && $amount < 0) {
            throw self::failure($reason);
        }
        if ($direction === self::DIRECTION_REVERSAL && $amount > 0) {
            throw self::failure($reason);
        }
        return $amount;
    }

    private static function signedInteger($value, string $reason): int
    {
        if (!is_int($value) || abs($value) > 1000000000000000) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function positiveInt($value, string $reason): int
    {
        if (!is_int($value) || $value <= 0) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function nonNegativeInt($value, string $reason): int
    {
        if (!is_int($value) || $value < 0) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function requiredToken($value, int $maxLength, string $reason): string
    {
        $value = self::optionalToken($value, $maxLength, $reason);
        if ($value === '') {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function optionalToken($value, int $maxLength, string $reason): string
    {
        if (!is_string($value)) {
            throw self::failure($reason);
        }
        $value = trim($value);
        if (strlen($value) > $maxLength
            || ($value !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:\/-]*$/D', $value) !== 1)) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function text($value, int $maxLength, string $reason): string
    {
        if (!is_string($value) || mb_strlen($value, 'UTF-8') > $maxLength) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function businessDate($value): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            throw self::failure('business_date_invalid');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw self::failure('business_date_invalid');
        }
        return $value;
    }

    private static function timezone($value): string
    {
        if (!is_string($value) || strlen($value) > 64) {
            throw self::failure('business_timezone_invalid');
        }
        try {
            new \DateTimeZone($value);
        } catch (\Throwable $exception) {
            throw self::failure('business_timezone_invalid');
        }
        return $value;
    }

    private static function assertExactKeys(array $value, array $required, string $path): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($required, SORT_STRING);
        if ($actual !== $required) {
            throw self::failure('fact_shape_invalid', [
                'path' => $path,
                'actualKeys' => $actual,
                'requiredKeys' => $required,
            ]);
        }
    }

    private static function assertAllowedKeys(array $value, array $required, array $allowed, string $path): void
    {
        $actual = array_keys($value);
        $missing = array_diff($required, $actual);
        $extra = array_diff($actual, $allowed);
        if ($missing !== [] || $extra !== []) {
            throw self::failure('fact_shape_invalid', [
                'path' => $path,
                'actualKeys' => $actual,
                'requiredKeys' => $required,
            ]);
        }
    }

    private static function canonicalFingerprint(array $value): string
    {
        $canonical = self::canonicalize($value);
        $encoded = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw self::failure('fact_fingerprint_encoding_failed');
        }
        return hash('sha256', $encoded);
    }

    private static function canonicalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }

    private static function failure(string $reason, array $detail = []): CashierV3CheckoutFactContractException
    {
        return new CashierV3CheckoutFactContractException($reason, $detail);
    }
}
