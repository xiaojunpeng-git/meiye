<?php

namespace app\services\cashier\v3\order\settlement;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/**
 * Transaction-only authoritative header and line writer.
 *
 * The future final checkout orchestrator owns the transaction and calls this
 * after locking the checkout request aggregate. This class deliberately does
 * not open/commit transactions, activate submit-checkout, write legacy
 * store_order, or publish facts/events/outbox on its own.
 */
final class ThinkPhpCashierV3SalesOrderAuthorityWriter implements CashierV3SalesOrderAuthorityWriter
{
    public const HEADER_TABLE = 'cashier_v3_sales_order';
    public const LINE_TABLE = 'cashier_v3_sales_order_line';

    public function persistInTx(
        CashierV3SalesOrderPlanV1 $plan,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('salesOrderAuthority.persistInTx');
        $header = $plan->header();
        $this->assertDataScope($header, $operatorScope, $dataScope);

        // Missing-key FOR UPDATE is intentionally avoided. The unique checkout
        // request key serializes concurrent creators without compatible gap locks.
        $discovered = Db::name(self::HEADER_TABLE)
            ->where('tenant_id', $header['tenant_id'])
            ->where('checkout_request_id', $header['checkout_request_id'])
            ->field('order_id')
            ->find();
        $existing = $discovered
            ? $this->lockHeaderByOrderId($header['tenant_id'], (string)$discovered['order_id'])
            : null;
        if ($existing) {
            $this->assertImmutableReplay($plan, $existing);
            return $this->result($plan, $existing, true, 0, 0);
        }

        $headerRow = $header;
        $headerRow['add_time'] = (int)$header['recorded_at'];
        $headerRow['update_time'] = (int)$header['recorded_at'];
        try {
            $affected = (int)Db::name(self::HEADER_TABLE)->insert($headerRow);
        } catch (\Throwable $exception) {
            if (!$this->isDuplicateKey($exception)) {
                throw $exception;
            }
            $existing = $this->lockHeaderByCheckoutRequest(
                (string)$header['tenant_id'],
                (string)$header['checkout_request_id']
            );
            if (!$existing) {
                throw self::failure('sales_order_identity_conflict');
            }
            $this->assertImmutableReplay($plan, $existing);
            return $this->result($plan, $existing, true, 0, 0);
        }
        if ($affected !== 1) {
            throw self::failure('sales_order_header_affected_rows_invalid', [
                'affected' => $affected,
            ]);
        }

        $lineRows = [];
        foreach ($plan->lines() as $line) {
            $line['add_time'] = (int)$header['recorded_at'];
            $line['update_time'] = (int)$header['recorded_at'];
            $lineRows[] = $line;
        }
        $lineAffected = $lineRows
            ? (int)Db::name(self::LINE_TABLE)->insertAll($lineRows)
            : 0;
        if ($lineAffected !== count($lineRows)) {
            throw self::failure('sales_order_line_affected_rows_invalid', [
                'expected' => count($lineRows),
                'affected' => $lineAffected,
            ]);
        }
        $persisted = $this->lockHeaderByOrderId(
            (string)$header['tenant_id'],
            (string)$header['order_id']
        );
        if (!$persisted) {
            throw self::failure('sales_order_insert_readback_missing');
        }
        $this->assertImmutableReplay($plan, $persisted);
        return $this->result($plan, $persisted, false, 1, $lineAffected);
    }

    /**
     * Resolve the internal numeric row identity for legacy ledgers whose link
     * column cannot store the V3 opaque order id. The caller must still retain
     * the opaque order id in the V3 fact/event chain; this value is only the
     * transaction-local bridge to the existing integer ledger relation.
     */
    public function internalRecordIdInTx(string $tenantId, string $orderId): int
    {
        CashierV3TransactionGuard::assertInTransaction('salesOrderAuthority.internalRecordIdInTx');
        $tenantId = trim($tenantId);
        $orderId = trim($orderId);
        if ($tenantId === '' || $orderId === '') {
            throw self::failure('sales_order_internal_identity_invalid');
        }
        $row = Db::name(self::HEADER_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('order_id', $orderId)
            ->field('id,order_id')
            ->lock(true)
            ->find();
        $recordId = (int)($row['id'] ?? 0);
        if ($recordId <= 0 || !hash_equals($orderId, (string)($row['order_id'] ?? ''))) {
            throw self::failure('sales_order_internal_identity_missing');
        }
        return $recordId;
    }

    private function assertDataScope(
        array $header,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): void {
        if ($operatorScope->tenantId() === ''
            || !hash_equals($operatorScope->tenantId(), $dataScope->tenantId())
            || !hash_equals($operatorScope->tenantId(), (string)$header['tenant_id'])
            || !hash_equals($operatorScope->organizationId(), $dataScope->organizationId())
            || !hash_equals($operatorScope->organizationId(), (string)$header['organization_id'])
            || $operatorScope->storeId() !== $dataScope->forcedStoreId()
            || $operatorScope->storeId() !== (int)$header['store_id']
            || !$dataScope->allowsStore($operatorScope->storeId())) {
            throw self::failure('sales_order_data_scope_denied');
        }
    }

    private function assertImmutableReplay(
        CashierV3SalesOrderPlanV1 $plan,
        array $existing
    ): void {
        $expected = $plan->header();
        foreach ($expected as $column => $value) {
            if (!array_key_exists($column, $existing)
                || (string)$existing[$column] !== (string)$value) {
                throw self::failure('sales_order_natural_key_payload_conflict', [
                    'checkoutRequestId' => $expected['checkout_request_id'],
                    'column' => $column,
                ]);
            }
        }
        $actualLines = $this->rows(Db::name(self::LINE_TABLE)
            ->where('tenant_id', $expected['tenant_id'])
            ->where('order_id', $expected['order_id'])
            ->order('line_no asc,id asc')
            ->lock(true)
            ->select());
        $expectedLines = $plan->lines();
        if (count($actualLines) !== count($expectedLines)) {
            throw self::failure('sales_order_replay_line_count_conflict');
        }
        foreach ($expectedLines as $index => $expectedLine) {
            $actual = $actualLines[$index] ?? [];
            foreach ($expectedLine as $column => $value) {
                if (!array_key_exists($column, $actual)
                    || (string)$actual[$column] !== (string)$value) {
                    throw self::failure('sales_order_replay_line_payload_conflict', [
                        'checkoutRequestId' => $expected['checkout_request_id'],
                        'checkoutLineId' => $expectedLine['checkout_line_id'],
                        'column' => $column,
                    ]);
                }
            }
        }
    }

    private function lockHeaderByOrderId(string $tenantId, string $orderId)
    {
        return Db::name(self::HEADER_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('order_id', $orderId)
            ->lock(true)
            ->find();
    }

    private function lockHeaderByCheckoutRequest(string $tenantId, string $checkoutRequestId)
    {
        return Db::name(self::HEADER_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('checkout_request_id', $checkoutRequestId)
            ->lock(true)
            ->find();
    }

    private function result(
        CashierV3SalesOrderPlanV1 $plan,
        array $header,
        bool $replayed,
        int $headerRows,
        int $lineRows
    ): array {
        return [
            'contractVersion' => CashierV3SalesOrderPlanV1::CONTRACT_VERSION,
            'orderId' => (string)$header['order_id'],
            'orderNo' => (string)$header['order_no'],
            'checkoutRequestId' => (string)$header['checkout_request_id'],
            'orderStatus' => (string)$header['order_status'],
            'orderVersion' => (int)$header['order_version'],
            'planFingerprint' => $plan->fingerprint(),
            'replayed' => $replayed,
            'affected' => [
                'headerRows' => $headerRows,
                'lineRows' => $lineRows,
            ],
            'businessEffectsWritten' => $headerRows === 1,
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
    ): CashierV3SalesOrderAuthorityException {
        return new CashierV3SalesOrderAuthorityException($reason, $detail);
    }
}
