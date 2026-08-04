<?php

namespace app\services\cashier\v3\checkout\persistence;

use app\services\cashier\v3\card\CashierV3CardRuleEntitlementAuthorityServices;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/**
 * Final transaction-only authority writer for entitlement service completion.
 *
 * The Gateway owns the only business transaction. This writer never starts,
 * commits or rolls it back. A thrown exception must escape to that owner so
 * deduction, writeoff, service and performance facts roll back together.
 */
final class ThinkPhpCashierV3EntitlementCompletionWriter
{
    public const RECEIPT_TABLE = 'cashier_v3_entitlement_completion_receipt';
    public const WRITEOFF_TABLE = 'cashier_v3_entitlement_writeoff_fact';
    public const SERVICE_TABLE = 'cashier_v3_entitlement_service_fact';
    public const PERFORMANCE_TABLE = 'cashier_v3_performance_fact';
    public const HOLDER_TABLE = 'user_card_holder';
    public const DETAIL_TABLE = 'store_order_cart_info';
    public const ORDER_TABLE = 'store_order';
    public const VERSION_TABLE = 'cashier_v3_entitlement_resource_version';

    /** @var CashierV3EntitlementCompletionOccupationWriter|null */
    private $occupationWriter;

    /** @var CashierV3CardRuleEntitlementAuthorityServices|null */
    private $cardRules;

    public function __construct(
        ?CashierV3EntitlementCompletionOccupationWriter $occupationWriter = null,
        ?CashierV3CardRuleEntitlementAuthorityServices $cardRules = null
    ) {
        $this->occupationWriter = $occupationWriter;
        $this->cardRules = $cardRules;
    }

    public function persistInTx(CashierV3EntitlementCompletionPlanV1 $plan): array
    {
        CashierV3TransactionGuard::assertInTransaction('entitlementCompletion.persistInTx');
        $context = $plan->context();
        $existing = $this->findReceipt($context, true);
        if ($existing) {
            return $this->replayResult($plan, $existing);
        }

        $receipt = $this->insertProcessingReceipt($plan);
        if (!empty($receipt['replayed'])) {
            return $this->replayResult($plan, $receipt['row']);
        }

        $authorities = $this->loadAndAssertAuthorities($plan);
        $occupation = $this->convertOccupationInTx($plan);
        $ruleDeductions = $this->cardRules()->applyDeductionsInTx($plan->deductions(), $plan->context());
        $versions = $this->applyDeductions($plan, $authorities, $ruleDeductions);
        $inserted = [
            'writeoff' => $this->persistRows(self::WRITEOFF_TABLE, 'writeoff', $plan->writeoffRows()),
            'service' => $this->persistRows(self::SERVICE_TABLE, 'service', $plan->serviceRows()),
            'performance' => $this->persistRows(self::PERFORMANCE_TABLE, 'performance', $plan->performanceRows()),
        ];

        $result = [
            'contractVersion' => CashierV3EntitlementCompletionPlanV1::CONTRACT_VERSION,
            'receiptId' => CashierV3EntitlementCompletionPlanV1::receiptId(
                $context['tenant_id'],
                $context['checkout_request_id'],
                $context['command_idempotency_key']
            ),
            'checkoutRequestId' => $context['checkout_request_id'],
            'commandIdempotencyKey' => $context['command_idempotency_key'],
            'planFingerprint' => $plan->fingerprint(),
            'persistenceStatus' => 'persisted',
            'replayed' => false,
            'totals' => $this->presentTotals($plan->totals()),
            'occupation' => $occupation,
            'versions' => $versions,
            'inserted' => $inserted,
        ];
        $encoded = $this->encode($result);
        $affected = (int)Db::name(self::RECEIPT_TABLE)
            ->where('id', (int)$receipt['row']['id'])
            ->where('status', 'processing')
            ->where('plan_fingerprint', $plan->fingerprint())
            ->update([
                'status' => 'completed',
                'result_json' => $encoded,
                'completed_at' => $context['recorded_at'],
                'updated_at' => $context['recorded_at'],
            ]);
        if ($affected !== 1) {
            throw self::failure('completion_receipt_finalize_conflict');
        }
        return $result;
    }

    private function insertProcessingReceipt(CashierV3EntitlementCompletionPlanV1 $plan): array
    {
        $context = $plan->context();
        $row = [
            'receipt_id' => CashierV3EntitlementCompletionPlanV1::receiptId(
                $context['tenant_id'],
                $context['checkout_request_id'],
                $context['command_idempotency_key']
            ),
            'tenant_id' => $context['tenant_id'],
            'checkout_request_id' => $context['checkout_request_id'],
            'command_idempotency_key' => $context['command_idempotency_key'],
            'plan_fingerprint' => $plan->fingerprint(),
            'business_event_no' => $context['business_event_no'],
            'workspace_id' => $context['workspace_id'],
            'state_context_id' => $context['state_context_id'],
            'organization_id' => $context['organization_id'],
            'store_id' => $context['store_id'],
            'member_id' => $context['member_id'],
            'operator_id' => $context['operator_id'],
            'business_date' => $context['business_date'],
            'business_timezone' => $context['business_timezone'],
            'occurred_at' => $context['occurred_at'],
            'settled_at' => $context['settled_at'],
            'recorded_at' => $context['recorded_at'],
            'line_count' => $plan->totals()['line_count'],
            'service_quantity' => $plan->totals()['service_quantity'],
            'actual_entitlement_amount_cents' => $plan->totals()['actual_entitlement_amount_cents'],
            'consumption_performance_cents' => $plan->totals()['consumption_performance_cents'],
            'labor_performance_cents' => $plan->totals()['labor_performance_cents'],
            'status' => 'processing',
            'result_json' => '',
            'created_at' => $context['recorded_at'],
            'updated_at' => $context['recorded_at'],
            'completed_at' => 0,
        ];
        try {
            $row['id'] = (int)Db::name(self::RECEIPT_TABLE)->insertGetId($row);
        } catch (\Throwable $exception) {
            if (!$this->isDuplicateKey($exception)) {
                throw $exception;
            }
            $existing = $this->findReceipt($context, true);
            if (!$existing) {
                throw self::failure('completion_receipt_identity_conflict');
            }
            return ['row' => $existing, 'replayed' => true];
        }
        if ($row['id'] <= 0) {
            throw self::failure('completion_receipt_insert_failed');
        }
        return ['row' => $row, 'replayed' => false];
    }

    private function findReceipt(array $context, bool $lock)
    {
        $query = Db::name(self::RECEIPT_TABLE)
            ->where('tenant_id', $context['tenant_id'])
            ->where(function ($query) use ($context) {
                $query->where('checkout_request_id', $context['checkout_request_id'])
                    ->whereOr('command_idempotency_key', $context['command_idempotency_key']);
            });
        if ($lock) {
            $query->lock(true);
        }
        return $this->row($query->find());
    }

    private function replayResult(CashierV3EntitlementCompletionPlanV1 $plan, array $receipt): array
    {
        $context = $plan->context();
        foreach ([
            'tenant_id' => $context['tenant_id'],
            'checkout_request_id' => $context['checkout_request_id'],
            'command_idempotency_key' => $context['command_idempotency_key'],
            'plan_fingerprint' => $plan->fingerprint(),
            'business_event_no' => $context['business_event_no'],
        ] as $column => $expected) {
            if (!array_key_exists($column, $receipt)
                || (string)$receipt[$column] !== (string)$expected) {
                throw self::failure('completion_idempotency_payload_conflict', ['column' => $column]);
            }
        }
        if ((string)($receipt['status'] ?? '') !== 'completed') {
            throw self::failure('completion_receipt_incomplete', [
                'status' => (string)($receipt['status'] ?? ''),
            ]);
        }
        $result = json_decode((string)($receipt['result_json'] ?? ''), true);
        if (!is_array($result)
            || (string)($result['planFingerprint'] ?? '') !== $plan->fingerprint()
            || (string)($result['persistenceStatus'] ?? '') !== 'persisted') {
            throw self::failure('completion_receipt_result_invalid');
        }
        $result['replayed'] = true;
        $result['inserted'] = ['writeoff' => 0, 'service' => 0, 'performance' => 0];
        return $result;
    }

    private function loadAndAssertAuthorities(CashierV3EntitlementCompletionPlanV1 $plan): array
    {
        $context = $plan->context();
        $deductions = $plan->deductions();
        $holderIds = [];
        $detailIds = [];
        $orderIds = [];
        $holderExpectation = [];
        foreach ($deductions as $deduction) {
            $holderIds[$deduction['holder_id']] = $deduction['holder_id'];
            $detailIds[$deduction['source_detail_id']] = $deduction['source_detail_id'];
            $orderIds[$deduction['origin_order_id']] = $deduction['origin_order_id'];
            $holderId = $deduction['holder_id'];
            if (isset($holderExpectation[$holderId])
                && ($holderExpectation[$holderId]['origin_order_id'] !== $deduction['origin_order_id']
                    || $holderExpectation[$holderId]['source_version'] !== $deduction['source_version'])) {
                throw self::failure('completion_holder_snapshot_inconsistent', ['holderId' => $holderId]);
            }
            $holderExpectation[$holderId] = [
                'origin_order_id' => $deduction['origin_order_id'],
                'source_version' => $deduction['source_version'],
                'deduct_times' => (int)($holderExpectation[$holderId]['deduct_times'] ?? 0)
                    + $deduction['deduct_physical_times'],
            ];
        }
        sort($holderIds, SORT_NUMERIC);
        sort($detailIds, SORT_NUMERIC);
        sort($orderIds, SORT_NUMERIC);

        $holders = $this->rowsById(Db::name(self::HOLDER_TABLE)
            ->whereIn('id', array_values($holderIds))->order('id asc')->lock(true)->select());
        $details = $this->rowsById(Db::name(self::DETAIL_TABLE)
            ->whereIn('id', array_values($detailIds))->order('id asc')->lock(true)->select());
        $orders = $this->rowsById(Db::name(self::ORDER_TABLE)
            ->whereIn('id', array_values($orderIds))->order('id asc')->lock(true)->select());
        $versions = $this->versionRows(array_values($holderIds), array_values($detailIds));
        $ruleAuthorities = [];
        foreach ($deductions as $deduction) {
            $ruleAuthorities[$deduction['source_detail_id']] = $this->cardRules()->authorityForDetail(
                $context['tenant_id'],
                $deduction['holder_id'],
                $deduction['source_detail_id'],
                false
            );
        }

        foreach ($holderExpectation as $holderId => $expected) {
            $holder = $holders[$holderId] ?? null;
            $order = $orders[$expected['origin_order_id']] ?? null;
            $version = $versions['card_holder:' . $holderId] ?? null;
            if (!$holder || !$order || !$version
                || (int)($holder['uid'] ?? 0) !== $context['member_id']
                || (int)($holder['oid'] ?? 0) !== $expected['origin_order_id']
                || (int)($holder['is_del'] ?? 0) !== 0
                || (int)($holder['write_surplus_times'] ?? -1) < $expected['deduct_times']
                || !$this->activeOrder($order, $context['member_id'])
                || (int)($version['member_id'] ?? 0) !== $context['member_id']
                || (int)($version['current_version'] ?? 0) !== $expected['source_version']) {
                throw self::failure('completion_holder_authority_changed', ['holderId' => $holderId]);
            }
            $preFingerprint = $this->holderFingerprint($holder, $order, $context['tenant_id']);
            if (!hash_equals((string)$version['source_fingerprint'], $preFingerprint)) {
                throw self::failure('completion_holder_fingerprint_changed', ['holderId' => $holderId]);
            }
            $holderExpectation[$holderId]['remaining_before'] = (int)$holder['write_surplus_times'];
        }

        foreach ($deductions as $deduction) {
            $detailId = $deduction['source_detail_id'];
            $detail = $details[$detailId] ?? null;
            $order = $orders[$deduction['origin_order_id']] ?? null;
            $version = $versions['member_benefit_pool:' . $detailId] ?? null;
            $ruleAuthority = $ruleAuthorities[$detailId] ?? null;
            $legacyRemainingMustMatch = !is_array($ruleAuthority)
                || in_array((string)($ruleAuthority['ruleType'] ?? ''), ['normal', 'choice_kind'], true);
            if (!$detail || !$order || !$version
                || (int)($detail['oid'] ?? 0) !== $deduction['origin_order_id']
                || (int)($detail['product_id'] ?? 0) !== $deduction['project_id']
                || (int)($detail['cart_type'] ?? 0) !== 2
                || (int)($detail['product_type'] ?? 0) !== 6
                || ($legacyRemainingMustMatch
                    && (int)($detail['write_surplus_times'] ?? -1)
                        !== $deduction['expected_physical_remaining_times'])
                || (int)($version['member_id'] ?? 0) !== $context['member_id']
                || (int)($version['current_version'] ?? 0) !== $deduction['detail_version']) {
                throw self::failure('completion_detail_authority_changed', ['sourceDetailId' => $detailId]);
            }
            $preFingerprint = $this->detailFingerprint(
                $detail,
                $order,
                $holders[$deduction['holder_id']] ?? [],
                $context['member_id'],
                $context['tenant_id']
            );
            if (!hash_equals((string)$version['source_fingerprint'], $preFingerprint)) {
                throw self::failure('completion_detail_fingerprint_changed', ['sourceDetailId' => $detailId]);
            }
        }
        return compact('holders', 'details', 'orders', 'versions', 'holderExpectation', 'ruleAuthorities');
    }

    private function applyDeductions(
        CashierV3EntitlementCompletionPlanV1 $plan,
        array $authorities,
        array $ruleDeductions
    ): array {
        $context = $plan->context();
        $detailVersionsAfter = [];
        foreach ($plan->deductions() as $deduction) {
            $rule = $ruleDeductions[$deduction['source_detail_id']] ?? null;
            $legacyBefore = (int)($authorities['details'][$deduction['source_detail_id']]['write_surplus_times'] ?? -1);
            $remainingAfter = $legacyBefore - $deduction['deduct_physical_times'];
            if (is_array($rule) && (string)($rule['legacyMode'] ?? '') === 'keep') {
                $remainingAfter = $legacyBefore;
            }
            if ($legacyBefore < 0 || $remainingAfter < 0) {
                throw self::failure('completion_detail_projection_invalid', [
                    'sourceDetailId' => $deduction['source_detail_id'],
                ]);
            }
            $affected = (int)Db::name(self::DETAIL_TABLE)
                ->where('id', $deduction['source_detail_id'])
                ->where('oid', $deduction['origin_order_id'])
                ->where('product_id', $deduction['project_id'])
                ->where('cart_type', 2)
                ->where('product_type', 6)
                ->where('write_surplus_times', $legacyBefore)
                ->update([
                    'write_surplus_times' => $remainingAfter,
                    'is_writeoff' => $remainingAfter === 0 ? 1 : 0,
                ]);
            if ($affected !== 1 && $remainingAfter !== $legacyBefore) {
                throw self::failure('completion_detail_deduction_conflict', [
                    'sourceDetailId' => $deduction['source_detail_id'],
                ]);
            }
            $detailVersionsAfter[$deduction['source_detail_id']] = $deduction['detail_version'] + 1;
        }

        $holderVersionsAfter = [];
        foreach ($authorities['holderExpectation'] as $holderId => $expected) {
            $keepProjection = false;
            foreach ($ruleDeductions as $rule) {
                if ((int)($rule['holderId'] ?? 0) === (int)$holderId
                    && (string)($rule['legacyMode'] ?? '') === 'keep') {
                    $keepProjection = true;
                    break;
                }
            }
            $remainingAfter = $keepProjection
                ? $expected['remaining_before']
                : $expected['remaining_before'] - $expected['deduct_times'];
            $affected = (int)Db::name(self::HOLDER_TABLE)
                ->where('id', $holderId)
                ->where('uid', $context['member_id'])
                ->where('oid', $expected['origin_order_id'])
                ->where('is_del', 0)
                ->where('write_surplus_times', $expected['remaining_before'])
                ->update(['write_surplus_times' => $remainingAfter]);
            if ($affected !== 1 && !$keepProjection) {
                throw self::failure('completion_holder_deduction_conflict', ['holderId' => $holderId]);
            }
            $holderVersionsAfter[$holderId] = $expected['source_version'] + 1;
        }

        $orders = $authorities['orders'];
        foreach ($plan->deductions() as $deduction) {
            $detail = $this->row(Db::name(self::DETAIL_TABLE)
                ->where('id', $deduction['source_detail_id'])->find());
            $holder = $this->row(Db::name(self::HOLDER_TABLE)
                ->where('id', $deduction['holder_id'])->find());
            $fingerprint = $this->detailFingerprint(
                $detail ?: [],
                $orders[$deduction['origin_order_id']],
                $holder ?: [],
                $context['member_id'],
                $context['tenant_id']
            );
            $this->advanceVersion(
                'member_benefit_pool',
                $deduction['source_detail_id'],
                $context['member_id'],
                $deduction['detail_version'],
                $fingerprint,
                $context['recorded_at']
            );
        }
        foreach ($authorities['holderExpectation'] as $holderId => $expected) {
            $holder = $this->row(Db::name(self::HOLDER_TABLE)->where('id', $holderId)->find());
            $fingerprint = $this->holderFingerprint(
                $holder ?: [],
                $orders[$expected['origin_order_id']],
                $context['tenant_id']
            );
            $this->advanceVersion(
                'card_holder',
                $holderId,
                $context['member_id'],
                $expected['source_version'],
                $fingerprint,
                $context['recorded_at']
            );
        }
        ksort($detailVersionsAfter, SORT_NUMERIC);
        ksort($holderVersionsAfter, SORT_NUMERIC);
        return [
            'cardHolder' => $holderVersionsAfter,
            'memberBenefitPool' => $detailVersionsAfter,
        ];
    }

    private function convertOccupationInTx(
        CashierV3EntitlementCompletionPlanV1 $plan
    ): array {
        $context = $plan->context();
        $expected = 0;
        foreach ($plan->deductions() as $deduction) {
            $expected += (int)$deduction['convert_current_source_occupied_times'];
        }
        if ($context['source_type'] === 'direct') {
            if ($expected !== 0) {
                throw self::failure('direct_completion_occupation_conversion_forbidden');
            }
            return [
                'contractVersion' => CashierV3EntitlementCompletionOccupationWriter::CONTRACT_VERSION,
                'sourceType' => 'direct',
                'sourceId' => 0,
                'convertedTimes' => 0,
            ];
        }
        if ($expected <= 0 || !$this->occupationWriter) {
            throw self::failure('completion_occupation_writer_required', [
                'sourceType' => $context['source_type'],
                'expectedConvertedTimes' => $expected,
            ]);
        }
        $result = $this->occupationWriter->convertInTx($context, $plan->deductions());
        if (!is_array($result)) {
            throw self::failure('completion_occupation_result_invalid');
        }
        $keys = array_keys($result);
        sort($keys, SORT_STRING);
        if ($keys !== ['contractVersion', 'convertedTimes', 'sourceId', 'sourceType']
            || ($result['contractVersion'] ?? null)
                !== CashierV3EntitlementCompletionOccupationWriter::CONTRACT_VERSION
            || ($result['sourceType'] ?? null) !== $context['source_type']
            || !is_int($result['sourceId'] ?? null)
            || !is_int($result['convertedTimes'] ?? null)
            || $result['sourceId'] !== ($context['source_type'] === 'service_order'
                ? $context['service_order_id']
                : $context['reservation_id'])
            || $result['convertedTimes'] !== $expected) {
            throw self::failure('completion_occupation_result_invalid');
        }
        return $result;
    }

    private function advanceVersion(
        string $kind,
        int $resourceId,
        int $memberId,
        int $expectedVersion,
        string $fingerprint,
        int $recordedAt
    ): void {
        $affected = (int)Db::name(self::VERSION_TABLE)
            ->where('resource_kind', $kind)
            ->where('resource_id', (string)$resourceId)
            ->where('member_id', $memberId)
            ->where('current_version', $expectedVersion)
            ->update([
                'source_fingerprint' => $fingerprint,
                'current_version' => $expectedVersion + 1,
                'last_action' => 'complete_entitlement_service',
                'update_time' => $recordedAt,
            ]);
        if ($affected !== 1) {
            throw self::failure('completion_resource_version_conflict', [
                'kind' => $kind,
                'resourceId' => $resourceId,
            ]);
        }
    }

    private function cardRules(): CashierV3CardRuleEntitlementAuthorityServices
    {
        if (!$this->cardRules) {
            $this->cardRules = new CashierV3CardRuleEntitlementAuthorityServices();
        }
        return $this->cardRules;
    }

    private function persistRows(string $table, string $domain, array $rows): int
    {
        $inserted = 0;
        foreach ($rows as $row) {
            $existing = $this->row(Db::name($table)
                ->where('tenant_id', $row['tenant_id'])
                ->where('natural_key', $row['natural_key'])
                ->lock(true)
                ->find());
            if ($existing) {
                $this->assertImmutableReplay($domain, $row, $existing);
                continue;
            }
            try {
                $affected = (int)Db::name($table)->insert($row);
            } catch (\Throwable $exception) {
                if (!$this->isDuplicateKey($exception)) {
                    throw $exception;
                }
                $existing = $this->row(Db::name($table)
                    ->where('tenant_id', $row['tenant_id'])
                    ->where('natural_key', $row['natural_key'])
                    ->lock(true)
                    ->find());
                if (!$existing) {
                    throw self::failure('completion_fact_identity_conflict', ['domain' => $domain]);
                }
                $this->assertImmutableReplay($domain, $row, $existing);
                continue;
            }
            if ($affected !== 1) {
                throw self::failure('completion_fact_insert_failed', ['domain' => $domain]);
            }
            $inserted++;
        }
        return $inserted;
    }

    private function assertImmutableReplay(string $domain, array $expected, array $existing): void
    {
        foreach ($expected as $column => $value) {
            if (!array_key_exists($column, $existing)
                || (string)$existing[$column] !== (string)$value) {
                throw self::failure('completion_fact_payload_conflict', [
                    'domain' => $domain,
                    'naturalKey' => (string)$expected['natural_key'],
                    'column' => $column,
                ]);
            }
        }
    }

    private function versionRows(array $holderIds, array $detailIds): array
    {
        $result = [];
        $rows = Db::name(self::VERSION_TABLE)
            ->where(function ($query) use ($holderIds, $detailIds) {
                $query->where(function ($query) use ($holderIds) {
                    $query->where('resource_kind', 'card_holder')->whereIn('resource_id', $holderIds);
                })->whereOr(function ($query) use ($detailIds) {
                    $query->where('resource_kind', 'member_benefit_pool')->whereIn('resource_id', $detailIds);
                });
            })
            ->order('resource_kind asc,resource_id asc')
            ->lock(true)
            ->select();
        foreach ($this->rows($rows) as $row) {
            $result[(string)$row['resource_kind'] . ':' . (string)$row['resource_id']] = $row;
        }
        return $result;
    }

    private function holderFingerprint(array $holder, array $order, string $tenantId): string
    {
        $holderFields = [
            'id', 'uid', 'oid', 'card_name', 'card_no', 'store_id', 'product_type',
            'write_times', 'write_surplus_times', 'write_start', 'write_end', 'is_del',
        ];
        $snapshot = [];
        foreach ($holderFields as $field) {
            if (!array_key_exists($field, $holder)) {
                throw self::failure('completion_holder_snapshot_incomplete', ['field' => $field]);
            }
            $snapshot[$field] = $holder[$field];
        }
        return $this->authorityFingerprint([
            'holder' => $snapshot,
            'order' => $this->orderFingerprintFields($order),
            // This must exactly match CashierV3EntitlementResourceVersionProvider.
            // Otherwise a fresh shadow version can never pass final persistence.
            'card_state' => $this->cardOperationState((int)$holder['id']),
            'card_rule_state' => $this->cardRules()->fingerprintSnapshotForHolder(
                $tenantId,
                (int)$holder['id'],
                false
            ),
        ]);
    }

    /**
     * The card-operation state is optional before its migration is installed.
     * A present state is nevertheless part of the holder authority fingerprint.
     */
    private function cardOperationState(int $holderId): ?array
    {
        try {
            $row = Db::name('cashier_v3_card_state')
                ->where('tenant_id', '0')
                ->where('card_holder_id', $holderId)
                ->find();
        } catch (\Throwable $exception) {
            $message = strtolower($exception->getMessage());
            if (strpos($message, 'cashier_v3_card_state') !== false
                && (strpos($message, 'doesn\'t exist') !== false || strpos($message, 'not found') !== false)) {
                return null;
            }
            throw $exception;
        }
        return $this->row($row);
    }

    private function detailFingerprint(
        array $cart,
        array $order,
        array $holder,
        int $memberId,
        string $tenantId
    ): string
    {
        $cartFields = [
            'id', 'oid', 'cart_id', 'product_id', 'cart_type', 'product_type',
            'cart_info', 'write_times', 'write_surplus_times', 'is_writeoff',
            'write_start', 'write_end', 'pay_price', 'debt_amount',
            'repaid_debt_amount', 'is_gift',
        ];
        $cartSnapshot = [];
        foreach ($cartFields as $field) {
            if (!array_key_exists($field, $cart)) {
                throw self::failure('completion_detail_snapshot_incomplete', ['field' => $field]);
            }
            $cartSnapshot[$field] = $cart[$field];
        }
        $holderFields = [
            'id', 'uid', 'oid', 'store_id', 'product_type',
            'write_times', 'write_surplus_times', 'write_start', 'write_end', 'is_del',
        ];
        $holderSnapshot = [];
        foreach ($holderFields as $field) {
            if (!array_key_exists($field, $holder)) {
                throw self::failure('completion_detail_holder_snapshot_incomplete', ['field' => $field]);
            }
            $holderSnapshot[$field] = $holder[$field];
        }
        $reservationRows = Db::name('store_reservation_order')
            ->field('id')
            ->where('cart_info_id', (int)$cart['id'])
            ->whereIn('status', [0, 1, 3])
            ->where('is_del', 0)
            ->where('is_system_del', 0)
            ->order('id asc')
            ->select();
        $reservationIds = [];
        foreach ($this->rows($reservationRows) as $row) {
            $reservationIds[] = (int)$row['id'];
        }
        return $this->authorityFingerprint([
            'cart' => $cartSnapshot,
            'order' => $this->orderFingerprintFields($order),
            'holder' => $holderSnapshot,
            'card_state' => $this->cardOperationState((int)$holder['id']),
            'card_rule_state' => $this->cardRules()->fingerprintSnapshotForDetail(
                $tenantId,
                (int)$holder['id'],
                (int)$cart['id'],
                false
            ),
            'reservation_ids' => $reservationIds,
            'pending_debt' => $this->pendingDebt($order, $memberId),
        ]);
    }

    private function pendingDebt(array $order, int $memberId): string
    {
        if ((int)($order['uid'] ?? 0) !== $memberId) {
            throw self::failure('completion_order_member_mismatch');
        }
        $rows = $this->rows(Db::name('store_debt')
            ->field('id,order_id,status,total_debt,repaid_debt')
            ->where('order_id', (int)$order['id'])
            ->select());
        if (count($rows) > 1) {
            throw self::failure('completion_order_debt_duplicate');
        }
        if ($rows) {
            if ((int)$rows[0]['status'] !== 0) {
                return '0.00';
            }
            return $this->positiveMoneyDifference($rows[0]['total_debt'], $rows[0]['repaid_debt']);
        }
        return $this->positiveMoneyDifference($order['debt_amount'], $order['repaid_debt_amount']);
    }

    private function positiveMoneyDifference($left, $right): string
    {
        if (function_exists('bcsub') && function_exists('bccomp')) {
            $difference = bcsub((string)$left, (string)$right, 2);
            return bccomp($difference, '0', 2) > 0 ? $difference : '0.00';
        }
        $cents = $this->moneyCents($left) - $this->moneyCents($right);
        if ($cents <= 0) {
            return '0.00';
        }
        return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private function moneyCents($value): int
    {
        $value = trim((string)$value);
        if (preg_match('/^(\d+)(?:\.(\d{1,}))?$/D', $value, $matches) !== 1) {
            throw self::failure('completion_money_snapshot_invalid');
        }
        $fraction = substr(str_pad($matches[2] ?? '', 2, '0'), 0, 2);
        return (int)$matches[1] * 100 + (int)$fraction;
    }

    private function orderFingerprintFields(array $order): array
    {
        foreach ([
            'id', 'uid', 'store_id', 'paid', 'is_del', 'is_system_del',
            'is_user_del', 'refund_status', 'terminal_action', 'card_upgrade_use_oid',
            'debt_amount', 'repaid_debt_amount', 'pay_price', 'cash_pay_price',
            'yue_pay_price',
        ] as $field) {
            if (!array_key_exists($field, $order)) {
                throw self::failure('completion_order_snapshot_incomplete', ['field' => $field]);
            }
        }
        return [
            'id' => (int)$order['id'],
            'uid' => (int)$order['uid'],
            'store_id' => (int)$order['store_id'],
            'paid' => (int)$order['paid'],
            'is_del' => (int)$order['is_del'],
            'is_system_del' => (int)$order['is_system_del'],
            'is_user_del' => (int)$order['is_user_del'],
            'refund_status' => (int)$order['refund_status'],
            'terminal_action' => (int)$order['terminal_action'],
            'card_upgrade_use_oid' => (int)$order['card_upgrade_use_oid'],
            'mark' => (string)($order['mark'] ?? ''),
            'debt_amount' => (string)$order['debt_amount'],
            'repaid_debt_amount' => (string)$order['repaid_debt_amount'],
            'pay_price' => (string)$order['pay_price'],
            'cash_pay_price' => (string)$order['cash_pay_price'],
            'yue_pay_price' => (string)$order['yue_pay_price'],
        ];
    }

    private function activeOrder(array $order, int $memberId): bool
    {
        return (int)($order['id'] ?? 0) > 0
            && (int)($order['uid'] ?? 0) === $memberId
            && (int)($order['paid'] ?? 0) === 1
            && (int)($order['is_del'] ?? 0) === 0
            && (int)($order['is_system_del'] ?? 0) === 0
            && (int)($order['is_user_del'] ?? 0) === 0
            && (int)($order['refund_status'] ?? 0) === 0
            && (int)($order['terminal_action'] ?? 0) === 0
            && (int)($order['card_upgrade_use_oid'] ?? 0) === 0;
    }

    private function authorityFingerprint(array $value): string
    {
        return hash('sha256', $this->encode($value));
    }

    private function encode($value): string
    {
        $json = json_encode($this->canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw self::failure('completion_persistence_json_failed');
        }
        return $json;
    }

    private function canonicalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }

    private function presentTotals(array $totals): array
    {
        return [
            'lineCount' => $totals['line_count'],
            'serviceQuantity' => $totals['service_quantity'],
            'actualEntitlementAmountCents' => $totals['actual_entitlement_amount_cents'],
            'consumptionPerformanceCents' => $totals['consumption_performance_cents'],
            'laborPerformanceCents' => $totals['labor_performance_cents'],
        ];
    }

    private function rowsById($result): array
    {
        $indexed = [];
        foreach ($this->rows($result) as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0 || isset($indexed[$id])) {
                throw self::failure('completion_authority_result_invalid');
            }
            $indexed[$id] = $row;
        }
        return $indexed;
    }

    private function row($result)
    {
        if (is_object($result) && method_exists($result, 'toArray')) {
            $result = $result->toArray();
        }
        return is_array($result) && $result ? $result : null;
    }

    private function rows($result): array
    {
        if (is_object($result) && method_exists($result, 'toArray')) {
            $result = $result->toArray();
        }
        if (!is_array($result)) {
            throw self::failure('completion_authority_result_invalid');
        }
        return array_values($result);
    }

    private function isDuplicateKey(\Throwable $exception): bool
    {
        for ($cursor = $exception; $cursor instanceof \Throwable; $cursor = $cursor->getPrevious()) {
            $message = strtolower($cursor->getMessage());
            if ((int)$cursor->getCode() === 1062
                || (string)$cursor->getCode() === '23000'
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
    ): CashierV3EntitlementCompletionPersistenceException {
        return new CashierV3EntitlementCompletionPersistenceException($reason, $detail);
    }
}
