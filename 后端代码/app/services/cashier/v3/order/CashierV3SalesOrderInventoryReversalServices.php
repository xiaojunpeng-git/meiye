<?php
declare(strict_types=1);

namespace app\services\cashier\v3\order;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\product\inventory\completion\InventoryBatchMovementFactServices;
use app\services\product\inventory\completion\InventoryCompletionContractException;
use think\facade\Db;

/**
 * Restores the exact physical batches deducted by a settled product sale.
 *
 * This service is deliberately used by void only. A financial refund has no
 * inventory meaning: it must not make goods saleable again. A void is a full
 * reversal and therefore appends +1 movement facts linked to the original
 * cashier_sale facts instead of rewriting either the original receipt or
 * movement ledger.
 */
final class CashierV3SalesOrderInventoryReversalServices
{
    private const RECEIPT_TABLE = 'cashier_v3_sale_inventory_receipt';

    /** @return array{restoredAllocationCount:int,restoredQuantityUnits:int} */
    public function reverseForVoidInTx(
        array $source,
        string $operationId,
        CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $scope,
        int $now
    ): array {
        CashierV3TransactionGuard::assertInTransaction('salesOrderInventoryReversal.reverseForVoidInTx');

        $receipt = (array)Db::name(self::RECEIPT_TABLE)
            ->where('tenant_id', $scope->tenantId())
            ->where('sales_order_id', (string)$source['sourceId'])
            ->lock(true)
            ->find();
        if (!$receipt) {
            // The order may contain non-inventory products only. No receipt is
            // authoritative evidence of no stock having been deducted.
            return ['restoredAllocationCount' => 0, 'restoredQuantityUnits' => 0];
        }
        if ((string)$receipt['checkout_request_id'] !== (string)$source['checkoutRequestId']
            || (string)$receipt['organization_id'] !== $scope->organizationId()
            || (int)$receipt['store_id'] !== $operator->storeId()) {
            throw self::failure('sales_void_inventory_receipt_scope_invalid');
        }

        $facts = Db::name('inventory_batch_movement_fact')
            ->where('tenant_id', $scope->tenantId())
            ->where('source_type', 'cashier_sale')
            ->where('source_id', (string)$source['sourceId'])
            ->where('direction', -1)
            ->where('reversal_of', 0)
            ->order('stock_id asc,batch_id asc,id asc')
            ->lock(true)
            ->select()
            ->toArray();
        if (!$facts || count($facts) !== (int)$receipt['allocation_count']) {
            throw self::failure('sales_void_inventory_receipt_incomplete');
        }

        $factIds = array_map(static function (array $fact): int { return (int)$fact['id']; }, $facts);
        $alreadyReversed = (int)Db::name('inventory_batch_movement_fact')
            ->where('tenant_id', $scope->tenantId())
            ->whereIn('reversal_of', $factIds)
            ->lock(true)
            ->count();
        if ($alreadyReversed > 0) {
            throw self::failure('sales_void_inventory_already_reversed');
        }

        $stockQuantities = [];
        $batchQuantities = [];
        foreach ($facts as $fact) {
            if ((string)$fact['organization_id'] !== $scope->organizationId()
                || (int)$fact['store_id'] !== $operator->storeId()
                || (int)$fact['quantity_units'] <= 0
                || (int)$fact['unit_cost_cents'] < 0
                || (int)$fact['cost_amount_cents'] < 0) {
                throw self::failure('sales_void_inventory_fact_invalid');
            }
            $stockId = (int)$fact['stock_id'];
            $batchId = (int)$fact['batch_id'];
            $quantity = (int)$fact['quantity_units'];
            $stockQuantities[$stockId] = self::safeAdd($stockQuantities[$stockId] ?? 0, $quantity);
            $batchQuantities[$batchId] = self::safeAdd($batchQuantities[$batchId] ?? 0, $quantity);
        }

        ksort($stockQuantities, SORT_NUMERIC);
        ksort($batchQuantities, SORT_NUMERIC);
        $stocks = [];
        foreach (array_keys($stockQuantities) as $stockId) {
            $stock = (array)Db::name('inventory_stock')->where('id', $stockId)->lock(true)->find();
            if (!$stock || (string)$stock['tenant_id'] !== $scope->tenantId()
                || (string)$stock['organization_id'] !== $scope->organizationId()
                || (int)$stock['store_id'] !== $operator->storeId()
                || (int)$stock['version'] <= 0) {
                throw self::failure('sales_void_inventory_stock_changed');
            }
            $stocks[$stockId] = $stock;
        }
        $batches = [];
        foreach (array_keys($batchQuantities) as $batchId) {
            $batch = (array)Db::name('inventory_batch')->where('id', $batchId)->lock(true)->find();
            if (!$batch || !isset($stocks[(int)$batch['stock_id']])
                || (int)$batch['version'] <= 0
                || (int)$batch['cost_allocated_quantity_units'] < $batchQuantities[$batchId]) {
                throw self::failure('sales_void_inventory_batch_changed');
            }
            $batches[$batchId] = $batch;
        }

        foreach ($stockQuantities as $stockId => $quantity) {
            $stock = $stocks[$stockId];
            $updated = Db::name('inventory_stock')
                ->where('id', $stockId)
                ->where('version', (int)$stock['version'])
                ->update([
                    'available_quantity_units' => self::safeAdd((int)$stock['available_quantity_units'], $quantity),
                    'version' => (int)$stock['version'] + 1,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) throw self::failure('sales_void_inventory_stock_cas_failed');
        }
        foreach ($batchQuantities as $batchId => $quantity) {
            $batch = $batches[$batchId];
            $updated = Db::name('inventory_batch')
                ->where('id', $batchId)
                ->where('version', (int)$batch['version'])
                ->update([
                    'available_quantity_units' => self::safeAdd((int)$batch['available_quantity_units'], $quantity),
                    'cost_allocated_quantity_units' => (int)$batch['cost_allocated_quantity_units'] - $quantity,
                    'version' => (int)$batch['version'] + 1,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) throw self::failure('sales_void_inventory_batch_cas_failed');
        }

        $movementWriter = new InventoryBatchMovementFactServices();
        foreach ($facts as $fact) {
            try {
                $movementWriter->append([
                    'factKey' => 'invsv:' . hash('sha256', $operationId . ':' . (int)$fact['id']),
                    'tenantId' => $scope->tenantId(),
                    'organizationId' => $scope->organizationId(),
                    'organizationPath' => (string)$fact['organization_path'],
                    'storeId' => $operator->storeId(),
                    'stockId' => (int)$fact['stock_id'],
                    'batchId' => (int)$fact['batch_id'],
                    'direction' => 1,
                    'quantityUnits' => (int)$fact['quantity_units'],
                    'unitCostCents' => (int)$fact['unit_cost_cents'],
                    'costAmountCents' => (int)$fact['cost_amount_cents'],
                    'sourceType' => 'cashier_sale_void',
                    'sourceId' => $operationId,
                    'sourceDetailId' => (string)$fact['source_detail_id'],
                    'reversalOf' => (int)$fact['id'],
                    'businessDate' => date('Y-m-d', $now),
                    'occurredAt' => $now,
                    'settledAt' => $now,
                    'recordedAt' => $now,
                ]);
            } catch (InventoryCompletionContractException $exception) {
                throw self::failure('sales_void_inventory_movement_write_failed');
            }
        }

        return [
            'restoredAllocationCount' => count($facts),
            'restoredQuantityUnits' => array_sum($batchQuantities),
        ];
    }

    private static function safeAdd(int $left, int $right): int
    {
        if ($left < 0 || $right <= 0 || $left > PHP_INT_MAX - $right) {
            throw self::failure('sales_void_inventory_quantity_overflow');
        }
        return $left + $right;
    }

    private static function failure(string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            '商品库存或批次状态已变化，本次作废未提交。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason]
        );
    }
}
