<?php

namespace app\services\cashier\v3\fact;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\report\CustomerLifecycleFactServices;
use app\services\report\StoreOperationsReportDimensionServices;
use app\services\report\StoreReportPaymentSaleAllocationFactServices;
use think\facade\Db;

/**
 * Transaction-only immutable fact writer. The final checkout orchestrator owns
 * the transaction and must call this after every business authority is locked.
 */
final class ThinkPhpCashierV3CheckoutFactRepository
{
    public const SALE_TABLE = 'cashier_v3_sale_fact';
    public const PAYMENT_TABLE = 'cashier_v3_payment_fact';
    public const BALANCE_TABLE = 'cashier_v3_balance_fact';
    public const PERFORMANCE_TABLE = 'cashier_v3_performance_fact';
    public const EVENT_TABLE = 'cashier_v3_business_event';

    private const TABLES = [
        'sale' => self::SALE_TABLE,
        'payment' => self::PAYMENT_TABLE,
        'balance' => self::BALANCE_TABLE,
        'performance' => self::PERFORMANCE_TABLE,
    ];

    public function persistInTx(
        CashierV3CheckoutFactPlanV1 $plan,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('checkoutFacts.persistInTx');
        $this->assertDataScope($plan, $operatorScope, $dataScope);
        $this->assertBusinessEventAuthority($plan);

        $inserted = ['sale' => 0, 'payment' => 0, 'balance' => 0, 'performance' => 0];
        $replayed = $inserted;
        foreach ($plan->rows() as $domain => $rows) {
            $table = self::TABLES[$domain];
            foreach ($rows as $row) {
                $row['source_attribution_type_snapshot'] = $this->sourceAttributionType($table, $row, $plan->context());
                $result = $this->persistRow($table, $domain, $row);
                $result === 'inserted' ? $inserted[$domain]++ : $replayed[$domain]++;
            }
        }

        // Each successful payment is frozen against every sale line before
        // report dimensions are projected, so item rows never repeat an
        // order-level payment total.
        (new StoreReportPaymentSaleAllocationFactServices())->persistInTx($plan);

        // Category/experience/partner dimensions are written in the same
        // transaction as the immutable sale facts, so reports never need to
        // infer them from mutable product configuration later.
        (new StoreOperationsReportDimensionServices())->persistInTx($plan);

        // Lifecycle is a reporting fact derived from the same final, locked
        // checkout authority. Its own natural keys make partial replays safe.
        (new CustomerLifecycleFactServices())->recordCheckoutInTx($plan);

        return [
            'contractVersion' => CashierV3CheckoutFactPlanV1::CONTRACT_VERSION,
            'planFingerprint' => $plan->fingerprint(),
            'inserted' => $inserted,
            'replayed' => $replayed,
            'businessEffectsWritten' => array_sum($inserted) > 0,
        ];
    }

    private function persistRow(string $table, string $domain, array $row): string
    {
        // Do not FOR UPDATE a missing unique key. Concurrent creators would
        // otherwise hold compatible gap locks and can deadlock on insert.
        $discovered = Db::name($table)
            ->where('tenant_id', $row['tenant_id'])
            ->where('natural_key', $row['natural_key'])
            ->field('fact_id')
            ->find();
        $existing = $discovered
            ? $this->lockByNaturalKey($table, $row['tenant_id'], $row['natural_key'])
            : null;
        if ($existing) {
            $this->assertImmutableReplay($domain, $row, $existing);
            return 'replayed';
        }

        if ($row['fact_direction'] === CashierV3CheckoutFactPlanV1::DIRECTION_REVERSAL) {
            $this->assertReversalTarget($table, $domain, $row);
        }

        try {
            $affected = (int)Db::name($table)->insert($row);
        } catch (\Throwable $exception) {
            if (!$this->isDuplicateKey($exception)) {
                throw $exception;
            }
            $existing = $this->lockByNaturalKey($table, $row['tenant_id'], $row['natural_key']);
            if (!$existing) {
                throw self::failure('fact_identity_conflict', [
                    'domain' => $domain,
                    'factId' => $row['fact_id'],
                    'naturalKey' => $row['natural_key'],
                ]);
            }
            $this->assertImmutableReplay($domain, $row, $existing);
            return 'replayed';
        }
        if ($affected !== 1) {
            throw self::failure('fact_insert_affected_rows_invalid', [
                'domain' => $domain,
                'affected' => $affected,
            ]);
        }
        return 'inserted';
    }

    /** The configured source type is resolved while the checkout authority is locked. */
    private function sourceAttributionType(string $table, array $row, array $context): string
    {
        if ((string)$row['fact_direction'] === CashierV3CheckoutFactPlanV1::DIRECTION_REVERSAL && (string)$row['reversal_of'] !== '') {
            $original = Db::name($table)->where('tenant_id', (string)$context['tenant_id'])->where('fact_id', (string)$row['reversal_of'])
                ->lock(true)->field('source_attribution_type_snapshot')->find();
            return (string)($original['source_attribution_type_snapshot'] ?? 'other');
        }
        $ids = array_filter([(int)$context['business_source_primary_id'], (int)$context['business_source_secondary_id']]);
        if (!$ids) return 'other';
        $rows = Db::name('cashier_v3_business_source')->whereIn('id', $ids)->lock(true)
            ->field('id,attribution_type')->select()->toArray();
        foreach ($rows as $row) if ((string)$row['attribution_type'] === 'guide') return 'guide';
        $secondaryId = (int)$context['business_source_secondary_id'];
        foreach ($rows as $row) if ((int)$row['id'] === $secondaryId) return (string)$row['attribution_type'];
        return (string)($rows[0]['attribution_type'] ?? 'other');
    }

    private function assertDataScope(
        CashierV3CheckoutFactPlanV1 $plan,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): void {
        $context = $plan->context();
        if ($operatorScope->tenantId() === ''
            || !hash_equals($operatorScope->tenantId(), $dataScope->tenantId())
            || !hash_equals($operatorScope->tenantId(), (string)$context['tenant_id'])
            || !hash_equals($operatorScope->organizationId(), $dataScope->organizationId())
            || !hash_equals($operatorScope->organizationId(), (string)$context['organization_id'])
            || $operatorScope->storeId() !== $dataScope->forcedStoreId()
            || $operatorScope->storeId() !== (int)$context['store_id']
            || $operatorScope->operatorId() !== $dataScope->operatorId()
            || $operatorScope->operatorId() !== (int)$context['operator_id']
            || !$dataScope->allowsStore($operatorScope->storeId())) {
            throw self::failure('checkout_fact_data_scope_denied');
        }
    }

    private function assertBusinessEventAuthority(CashierV3CheckoutFactPlanV1 $plan): void
    {
        $context = $plan->context();
        $event = Db::name(self::EVENT_TABLE)
            ->where('event_no', $context['business_event_no'])
            ->where('tenant_id', $context['tenant_id'])
            ->where('store_id', $context['store_id'])
            ->lock(true)
            ->find();
        if (!$event) {
            throw self::failure('checkout_fact_business_event_missing');
        }
        $sourceDocumentType = (string)$context['source_document_type'];
        $isRecharge = $sourceDocumentType === 'recharge';
        $isRechargeDebtRepayment = $sourceDocumentType === 'recharge_debt_repayment';
        $isSalesDebtRepayment = $sourceDocumentType === 'debt_repayment';
        $expected = [
            'event_type' => 'checkout.completed',
            'aggregate_type' => 'sales_order',
            'aggregate_id' => $context['order_id'],
            'source_type' => 'submit-checkout',
            'source_id' => $context['checkout_request_id'],
            'command_idempotency_key' => $plan->commandIdempotencyKey(),
            'organization_id' => $context['organization_id'],
            'member_id' => $context['member_id'],
            'operator_id' => $context['operator_id'],
            'business_date' => $context['business_date'],
            'occurred_at' => $context['occurred_at'],
            'settled_at' => $context['settled_at'],
            'recorded_at' => $context['recorded_at'],
        ];
        if ($isRecharge) {
            $expected['event_type'] = 'recharge.completed';
            $expected['aggregate_type'] = 'recharge_order';
            $expected['source_type'] = 'submit-recharge';
        } elseif ($isRechargeDebtRepayment) {
            $expected['event_type'] = 'debt.repaid';
            $expected['aggregate_type'] = 'recharge_debt_repayment';
            $expected['source_type'] = 'submit-recharge-debt-repayment';
        } elseif ($isSalesDebtRepayment) {
            $expected['event_type'] = 'debt.repaid';
            $expected['aggregate_type'] = 'debt_repayment';
            $expected['source_type'] = 'submit-debt-repayment';
        }
        foreach ($expected as $column => $value) {
            if ((string)($event[$column] ?? '') !== (string)$value) {
                throw self::failure('checkout_fact_business_event_mismatch', [
                    'column' => $column,
                ]);
            }
        }
    }

    private function assertReversalTarget(string $table, string $domain, array $row): void
    {
        $original = Db::name($table)
            ->where('tenant_id', $row['tenant_id'])
            ->where('store_id', $row['store_id'])
            ->where('fact_id', $row['reversal_of'])
            ->lock(true)
            ->find();
        if (!$original) {
            throw self::failure('reversal_target_not_found', [
                'domain' => $domain,
                'reversalOf' => $row['reversal_of'],
            ]);
        }
        if ((string)$original['fact_type'] !== (string)$row['fact_type']
            || (string)$original['fact_direction'] !== CashierV3CheckoutFactPlanV1::DIRECTION_FORWARD
            || (string)$original['status'] !== CashierV3CheckoutFactPlanV1::STATUS_EFFECTIVE
            || (string)$original['source_line_id'] !== (string)$row['source_line_id']
            || !$this->sameReversalIdentity($domain, $original, $row)) {
            throw self::failure('reversal_target_incompatible', ['domain' => $domain]);
        }
        $columns = $this->amountColumns($domain);
        $totals = $this->lockedReversalAmounts(
            $table,
            $row['tenant_id'],
            $row['reversal_of'],
            $columns
        );
        foreach ($columns as $column) {
            $originalAmount = (int)$original[$column];
            $newAmount = (int)$row[$column];
            if (($originalAmount === 0 && $newAmount !== 0)
                || ($originalAmount > 0 && $newAmount > 0)
                || ($originalAmount < 0 && $newAmount < 0)
                || abs($totals[$column] + $newAmount) > abs($originalAmount)) {
                throw self::failure('reversal_amount_exceeds_original', [
                    'domain' => $domain,
                    'amountColumn' => $column,
                ]);
            }
        }
    }

    private function lockedReversalAmounts(
        string $table,
        string $tenantId,
        string $originalFactId,
        array $columns
    ): array {
        $rows = Db::name($table)
            ->where('tenant_id', $tenantId)
            ->where('reversal_of', $originalFactId)
            ->field(implode(',', $columns))
            ->order('id asc')
            ->lock(true)
            ->select();
        $totals = array_fill_keys($columns, 0);
        foreach ($this->rows($rows) as $row) {
            foreach ($columns as $column) {
                $totals[$column] += (int)$row[$column];
            }
        }
        return $totals;
    }

    private function amountColumns(string $domain): array
    {
        if ($domain === 'sale') {
            return ['original_amount_cents', 'discount_amount_cents', 'coupon_discount_cents', 'sale_amount_cents'];
        }
        if ($domain === 'balance') {
            return ['principal_delta_cents', 'bonus_delta_cents'];
        }
        if ($domain === 'performance') {
            return ['allocation_base_amount_cents', 'amount_cents', 'labor_fee_amount_cents'];
        }
        return ['amount_cents'];
    }

    private function sameReversalIdentity(string $domain, array $original, array $reversal): bool
    {
        $columns = [
            'tenant_id', 'tenant_name_snapshot', 'organization_id',
            'organization_name_snapshot', 'organization_path_snapshot', 'store_id',
            'store_name_snapshot', 'member_id', 'member_name_snapshot',
            'checkout_request_id', 'order_id', 'order_no_snapshot',
            'source_document_type', 'source_line_id',
        ];
        if ($domain === 'sale') {
            $columns = array_merge($columns, [
                'source_type', 'item_id', 'item_code_snapshot', 'item_name_snapshot',
                'category_id_snapshot', 'category_name_snapshot', 'quantity',
            ]);
        } elseif ($domain === 'payment') {
            $columns = array_merge($columns, [
                'payment_method', 'payment_authority_key', 'collection_reference',
            ]);
        } elseif ($domain === 'balance') {
            $columns = array_merge($columns, ['balance_change_type', 'balance_account_id']);
        } elseif ($domain === 'performance') {
            $columns = array_merge($columns, [
                'performance_type', 'employee_id', 'employee_name_snapshot',
                'employee_type_snapshot', 'employee_type_authority_version',
                'role_snapshot', 'allocation_weight_numerator',
                'allocation_weight_denominator', 'rule_code_snapshot',
                'rule_name_snapshot', 'rule_version_snapshot',
            ]);
        }
        foreach ($columns as $column) {
            if ((string)$original[$column] !== (string)$reversal[$column]) {
                return false;
            }
        }
        return true;
    }

    private function lockByNaturalKey(string $table, string $tenantId, string $naturalKey)
    {
        return Db::name($table)
            ->where('tenant_id', $tenantId)
            ->where('natural_key', $naturalKey)
            ->lock(true)
            ->find();
    }

    private function assertImmutableReplay(string $domain, array $expected, array $existing): void
    {
        foreach ($expected as $column => $value) {
            if (!array_key_exists($column, $existing)
                || (string)$existing[$column] !== (string)$value) {
                throw self::failure('fact_natural_key_payload_conflict', [
                    'domain' => $domain,
                    'naturalKey' => $expected['natural_key'],
                    'column' => $column,
                ]);
            }
        }
    }

    private function rows($result): array
    {
        if (is_array($result)) {
            return $result;
        }
        if (is_object($result) && method_exists($result, 'toArray')) {
            return $result->toArray();
        }
        return [];
    }

    private function isDuplicateKey(\Throwable $exception): bool
    {
        for ($cursor = $exception; $cursor instanceof \Throwable; $cursor = $cursor->getPrevious()) {
            $message = strtolower($cursor->getMessage());
            if ((int)$cursor->getCode() === 1062
                || strpos($message, '1062') !== false
                || strpos($message, 'duplicate entry') !== false) {
                return true;
            }
        }
        return false;
    }

    private static function failure(
        string $reason,
        array $detail = []
    ): CashierV3CheckoutFactContractException {
        return new CashierV3CheckoutFactContractException($reason, $detail);
    }
}
