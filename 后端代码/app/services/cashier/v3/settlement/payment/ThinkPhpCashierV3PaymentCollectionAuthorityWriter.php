<?php

namespace app\services\cashier\v3\settlement\payment;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/**
 * Transaction-only collection-set and detail writer.
 *
 * The final checkout orchestrator owns the transaction and must persist the
 * matching sales order first. This writer does not activate submit-checkout,
 * open transaction boundaries, or publish facts/events/outbox by itself.
 */
final class ThinkPhpCashierV3PaymentCollectionAuthorityWriter
    implements CashierV3PaymentCollectionAuthorityWriter
{
    public const BATCH_TABLE = 'cashier_v3_payment_collection_batch';
    public const COLLECTION_TABLE = 'cashier_v3_payment_collection';
    public const SALES_ORDER_TABLE = 'cashier_v3_sales_order';

    public function persistInTx(
        CashierV3PaymentCollectionPlanV1 $plan,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('paymentCollectionAuthority.persistInTx');
        $batch = $plan->batch();
        $this->assertDataScope($batch, $operatorScope, $dataScope);
        $this->assertPersistedSalesOrderIdentity($batch, $plan->composition());

        // A request-level batch is the concurrency guard for its 0..7 detail
        // rows. Missing-key FOR UPDATE is avoided; the unique request key lets
        // one creator win, while every loser verifies an immutable replay.
        $discovered = Db::name(self::BATCH_TABLE)
            ->where('tenant_id', $batch['tenant_id'])
            ->where('checkout_request_id', $batch['checkout_request_id'])
            ->field('batch_id')
            ->find();
        $existing = $discovered
            ? $this->lockBatchById(
                (string)$batch['tenant_id'],
                (string)$discovered['batch_id']
            )
            : null;
        if ($existing) {
            $this->assertImmutableReplay($plan, $existing);
            return $this->result($plan, $existing, true, 0, 0);
        }

        $batchRow = $batch;
        $batchRow['add_time'] = (int)$batch['recorded_at'];
        $batchRow['update_time'] = (int)$batch['recorded_at'];
        try {
            $batchAffected = (int)Db::name(self::BATCH_TABLE)->insert($batchRow);
        } catch (\Throwable $exception) {
            if (!$this->isDuplicateKey($exception)) {
                throw $exception;
            }
            $existing = $this->lockBatchByCheckoutRequest(
                (string)$batch['tenant_id'],
                (string)$batch['checkout_request_id']
            );
            if (!$existing) {
                throw self::failure('payment_collection_batch_identity_conflict');
            }
            $this->assertImmutableReplay($plan, $existing);
            return $this->result($plan, $existing, true, 0, 0);
        }
        if ($batchAffected !== 1) {
            throw self::failure('payment_collection_batch_affected_rows_invalid', [
                'affected' => $batchAffected,
            ]);
        }

        $collectionRows = [];
        foreach ($plan->collections() as $collection) {
            $collection['add_time'] = (int)$batch['recorded_at'];
            $collection['update_time'] = (int)$batch['recorded_at'];
            $collectionRows[] = $collection;
        }
        $collectionAffected = $collectionRows
            ? (int)Db::name(self::COLLECTION_TABLE)->insertAll($collectionRows)
            : 0;
        if ($collectionAffected !== count($collectionRows)) {
            throw self::failure('payment_collection_detail_affected_rows_invalid', [
                'expected' => count($collectionRows),
                'affected' => $collectionAffected,
            ]);
        }

        $persisted = $this->lockBatchById(
            (string)$batch['tenant_id'],
            (string)$batch['batch_id']
        );
        if (!$persisted) {
            throw self::failure('payment_collection_batch_readback_missing');
        }
        $this->assertImmutableReplay($plan, $persisted);
        return $this->result(
            $plan,
            $persisted,
            false,
            $batchAffected,
            $collectionAffected
        );
    }

    private function assertDataScope(
        array $batch,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): void {
        if ($operatorScope->tenantId() === ''
            || !hash_equals($operatorScope->tenantId(), $dataScope->tenantId())
            || !hash_equals($operatorScope->tenantId(), (string)$batch['tenant_id'])
            || !hash_equals($operatorScope->organizationId(), $dataScope->organizationId())
            || !hash_equals(
                $operatorScope->organizationId(),
                (string)$batch['organization_id']
            )
            || $operatorScope->storeId() !== $dataScope->forcedStoreId()
            || $operatorScope->storeId() !== (int)$batch['store_id']
            || $operatorScope->operatorId() !== $dataScope->operatorId()
            || $operatorScope->operatorId() !== (int)$batch['operator_id']
            || !$dataScope->allowsStore($operatorScope->storeId())) {
            throw self::failure('payment_collection_data_scope_denied');
        }
    }

    private function assertPersistedSalesOrderIdentity(
        array $batch,
        string $expectedComposition
    ): void
    {
        if (!in_array($expectedComposition, ['sale_only', 'mixed'], true)) {
            throw self::failure('payment_collection_composition_invalid');
        }
        $order = Db::name(self::SALES_ORDER_TABLE)
            ->where('tenant_id', $batch['tenant_id'])
            ->where('order_id', $batch['sales_order_id'])
            ->lock(true)
            ->find();
        if (!$order
            || (string)($order['order_no'] ?? '') !== (string)$batch['sales_order_no_snapshot']
            || (string)($order['immutable_fingerprint'] ?? '')
                !== (string)$batch['sales_order_fingerprint']
            || (string)($order['checkout_request_id'] ?? '')
                !== (string)$batch['checkout_request_id']
            || (int)($order['checkout_request_version'] ?? 0)
                !== (int)$batch['checkout_request_version']
            || (int)($order['store_id'] ?? 0) !== (int)$batch['store_id']
            || (int)($order['operator_id'] ?? 0) !== (int)$batch['operator_id']
            || (string)($order['organization_id'] ?? '') !== (string)$batch['organization_id']
            || (int)($order['member_id'] ?? -1) !== (int)$batch['member_id']
            || (string)($order['command_idempotency_key'] ?? '')
                !== (string)$batch['command_idempotency_key']
            || (string)($order['composition'] ?? '') !== $expectedComposition
            || (string)($order['business_date'] ?? '') !== (string)$batch['business_date']
            || (int)($order['sale_amount_cents'] ?? -1)
                !== (int)$batch['receivable_amount_cents']
            || (string)($order['order_status'] ?? '') !== 'settled'
            || (string)($order['order_direction'] ?? '') !== 'forward') {
            throw self::failure('payment_collection_persisted_sales_order_mismatch');
        }
    }

    private function assertImmutableReplay(
        CashierV3PaymentCollectionPlanV1 $plan,
        array $existingBatch
    ): void {
        $expectedBatch = $plan->batch();
        foreach ($expectedBatch as $column => $value) {
            if (!array_key_exists($column, $existingBatch)
                || (string)$existingBatch[$column] !== (string)$value) {
                throw self::failure('payment_collection_batch_payload_conflict', [
                    'checkoutRequestId' => $expectedBatch['checkout_request_id'],
                    'column' => $column,
                ]);
            }
        }

        $actualCollections = $this->rows(Db::name(self::COLLECTION_TABLE)
            ->where('tenant_id', $expectedBatch['tenant_id'])
            ->where('batch_id', $expectedBatch['batch_id'])
            ->order('payment_line_no asc,id asc')
            ->lock(true)
            ->select());
        $expectedCollections = $plan->collections();
        if (count($actualCollections) !== count($expectedCollections)) {
            throw self::failure('payment_collection_replay_detail_count_conflict');
        }
        foreach ($expectedCollections as $index => $expected) {
            $actual = $actualCollections[$index] ?? [];
            foreach ($expected as $column => $value) {
                if (!array_key_exists($column, $actual)
                    || (string)$actual[$column] !== (string)$value) {
                    throw self::failure('payment_collection_replay_detail_payload_conflict', [
                        'checkoutRequestId' => $expectedBatch['checkout_request_id'],
                        'paymentDraftId' => $expected['checkout_payment_draft_id'],
                        'column' => $column,
                    ]);
                }
            }
        }
    }

    private function lockBatchById(string $tenantId, string $batchId)
    {
        return Db::name(self::BATCH_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('batch_id', $batchId)
            ->lock(true)
            ->find();
    }

    private function lockBatchByCheckoutRequest(string $tenantId, string $checkoutRequestId)
    {
        return Db::name(self::BATCH_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('checkout_request_id', $checkoutRequestId)
            ->lock(true)
            ->find();
    }

    private function result(
        CashierV3PaymentCollectionPlanV1 $plan,
        array $batch,
        bool $replayed,
        int $batchRows,
        int $collectionRows
    ): array {
        return [
            'contractVersion' => CashierV3PaymentCollectionPlanV1::CONTRACT_VERSION,
            'batchId' => (string)$batch['batch_id'],
            'salesOrderId' => (string)$batch['sales_order_id'],
            'checkoutRequestId' => (string)$batch['checkout_request_id'],
            'collectionIds' => array_column($plan->collections(), 'collection_id'),
            'collectionCount' => (int)$batch['collection_count'],
            'collectedAmountCents' => (int)$batch['collected_amount_cents'],
            'cashPerformanceAmountCents' => (int)$batch['cash_performance_amount_cents'],
            'planFingerprint' => $plan->fingerprint(),
            'replayed' => $replayed,
            'affected' => [
                'batchRows' => $batchRows,
                'collectionRows' => $collectionRows,
            ],
            'businessEffectsWritten' => $batchRows === 1,
        ];
    }

    private function rows($result): array
    {
        if (is_array($result)) {
            return array_values($result);
        }
        if (is_object($result) && method_exists($result, 'toArray')) {
            return array_values($result->toArray());
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
    ): CashierV3PaymentCollectionAuthorityException {
        return new CashierV3PaymentCollectionAuthorityException($reason, $detail);
    }
}
