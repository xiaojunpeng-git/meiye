<?php
declare(strict_types=1);

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\product\inventory\completion\InventoryBatchMovementFactServices;
use think\facade\Db;

/**
 * Explicit maintenance-only correction of erroneous checkout presale deductions.
 * No HTTP route: the caller must authorize an exact tenant/store/order and own
 * the transaction. Original sale, receipt, payments and movements are immutable.
 */
final class CashierV3PresaleStockRepairServices
{
    public const SOURCE_TYPE = 'cashier_presale_stock_repair';

    /** Full opposite movement identity, also checked by the later order-void path. */
    public static function isExactCorrection(array $original, array $reverse): bool
    {
        if ((string)($reverse['source_type'] ?? '') !== self::SOURCE_TYPE
            || (string)($reverse['fact_key'] ?? '') !== 'invpsr:' . hash('sha256', (string)$original['id'])
            || (int)($reverse['reversal_of'] ?? 0) !== (int)$original['id']
            || (int)($reverse['direction'] ?? 0) !== 1
            || (string)($reverse['fact_status'] ?? '') !== 'SETTLED') return false;
        foreach (['tenant_id', 'organization_id', 'organization_path', 'store_id',
            'location_id', 'stock_id', 'batch_id', 'source_id', 'source_detail_id',
            'quantity_units', 'unit_cost_cents', 'cost_amount_cents'] as $key) {
            if ((string)($original[$key] ?? '') !== (string)($reverse[$key] ?? '')) return false;
        }
        return true;
    }

    /** Return exact restored/replayed quantities; any mismatch aborts the outer transaction. */
    public function repairInTx(string $tenant, int $store, string $orderId, string $reason, int $now): array
    {
        CashierV3TransactionGuard::assertInTransaction('presaleStockRepair.repairInTx');
        if ($tenant === '' || $store <= 0 || $now <= 0 || trim($reason) === '' || mb_strlen($reason) > 400) {
            throw new \RuntimeException('presale_stock_repair_input_invalid');
        }
        // Serialize with lifecycle commands on the same immutable sale and receipt.
        $order = (array)Db::name('cashier_v3_sales_order')->where('tenant_id', $tenant)
            ->where('store_id', $store)->where('order_id', $orderId)->lock(true)->find();
        $receipt = (array)Db::name('cashier_v3_sale_inventory_receipt')->where('tenant_id', $tenant)
            ->where('sales_order_id', $orderId)->lock(true)->find();
        if (!$order || $order['order_status'] !== 'settled' || $order['order_direction'] !== 'forward'
            || !$receipt || (int)$receipt['store_id'] !== $store
            || $now < (int)$order['settled_at'] || $receipt['organization_id'] !== $order['organization_id']
            || $receipt['checkout_request_id'] !== $order['checkout_request_id']) {
            throw new \RuntimeException('presale_stock_repair_source_changed');
        }
        $lines = Db::name('cashier_v3_sales_order_line')->where('tenant_id', $tenant)
            ->where('store_id', $store)->where('order_id', $orderId)->where('item_type', 'product')
            ->where('line_direction', 'forward')->where('line_status', 'settled')
            ->where('is_presale', 1)->where('inventory_outbound_required', 0)->lock(true)->select()->toArray();
        $lineMap = array_column($lines, null, 'order_line_id');
        $originals = Db::name('inventory_batch_movement_fact')->where('tenant_id', $tenant)
            ->where('store_id', $store)->where('source_type', 'cashier_sale')->where('source_id', $orderId)
            ->where('direction', -1)->where('reversal_of', 0)->where('fact_status', 'SETTLED')
            ->order('stock_id,batch_id,id')->lock(true)->select()->toArray();
        if (count($originals) !== (int)$receipt['allocation_count']) {
            throw new \RuntimeException('presale_stock_repair_receipt_incomplete');
        }
        $pending = []; $replayed = 0; $lineQuantities = [];
        foreach ($originals as $fact) {
            if (!isset($lineMap[$fact['source_detail_id']])) continue;
            $quantity = (int)$fact['quantity_units'];
            if ($quantity <= 0 || $fact['organization_id'] !== $order['organization_id']) {
                throw new \RuntimeException('presale_stock_repair_fact_invalid');
            }
            $lineQuantities[$fact['source_detail_id']] = ($lineQuantities[$fact['source_detail_id']] ?? 0) + $quantity;
            $reverse = Db::name('inventory_batch_movement_fact')->where('tenant_id', $tenant)
                ->where('reversal_of', (int)$fact['id'])->lock(true)->select()->toArray();
            if ($reverse) {
                if (count($reverse) !== 1 || !self::isExactCorrection($fact, $reverse[0])) {
                    throw new \RuntimeException('presale_stock_repair_reversal_conflict');
                }
                $replayed += $quantity;
            } else {
                $pending[] = $fact;
            }
        }
        foreach ($lineQuantities as $lineId => $quantity) {
            if ($quantity !== (int)$lineMap[$lineId]['quantity']) {
                throw new \RuntimeException('presale_stock_repair_line_quantity_mismatch');
            }
        }
        $stockQuantities = []; $batchQuantities = [];
        foreach ($pending as $fact) {
            $stockQuantities[$fact['stock_id']] = ($stockQuantities[$fact['stock_id']] ?? 0) + (int)$fact['quantity_units'];
            $batchQuantities[$fact['batch_id']] = ($batchQuantities[$fact['batch_id']] ?? 0) + (int)$fact['quantity_units'];
        }
        ksort($stockQuantities, SORT_NUMERIC); ksort($batchQuantities, SORT_NUMERIC);
        $stocks = []; $batches = [];
        foreach ($stockQuantities as $id => $quantity) {
            $stock = (array)Db::name('inventory_stock')->where('id', $id)->lock(true)->find();
            if (!$stock || $stock['tenant_id'] !== $tenant || (int)$stock['store_id'] !== $store
                || $stock['organization_id'] !== $order['organization_id'] || (int)$stock['version'] <= 0) {
                throw new \RuntimeException('presale_stock_repair_stock_scope_invalid');
            }
            $this->assertLedger('stock_id', (int)$id, (int)$stock['available_quantity_units']);
            $stocks[$id] = $stock;
        }
        foreach ($batchQuantities as $id => $quantity) {
            $batch = (array)Db::name('inventory_batch')->where('id', $id)->lock(true)->find();
            if (!$batch || !isset($stocks[$batch['stock_id']]) || (int)$batch['version'] <= 0
                || $batch['batch_status'] !== 'ACTIVE' || (int)$batch['cost_allocated_quantity_units'] < $quantity) {
                throw new \RuntimeException('presale_stock_repair_batch_changed');
            }
            $this->assertLedger('batch_id', (int)$id, (int)$batch['available_quantity_units']);
            $batches[$id] = $batch;
        }
        foreach ($pending as $fact) {
            $stock = $stocks[$fact['stock_id']];
            $line = $lineMap[$fact['source_detail_id']];
            if ((int)$stock['consumable_product_id'] !== (int)$line['item_id']
                || (int)$stock['sku_id'] !== (int)$line['catalog_sku_id']
                || (int)$stock['location_id'] !== (int)$fact['location_id']
                || (int)$batches[$fact['batch_id']]['stock_id'] !== (int)$stock['id']) {
                throw new \RuntimeException('presale_stock_repair_product_mismatch');
            }
        }
        // CAS and the linked reverse fact commit together; never alter an opening
        // balance, an original deduction, the receipt snapshot or a sales amount.
        foreach ($stockQuantities as $id => $quantity) {
            $this->restore('inventory_stock', $stocks[$id], $quantity, $now, false);
        }
        foreach ($batchQuantities as $id => $quantity) {
            $this->restore('inventory_batch', $batches[$id], $quantity, $now, true);
        }
        $writer = new InventoryBatchMovementFactServices();
        foreach ($pending as $fact) {
            $writer->append([
                'factKey' => 'invpsr:' . hash('sha256', (string)$fact['id']),
                'tenantId' => $tenant, 'organizationId' => (string)$fact['organization_id'],
                'organizationPath' => (string)$fact['organization_path'], 'storeId' => $store,
                'stockId' => (int)$fact['stock_id'], 'batchId' => (int)$fact['batch_id'],
                'direction' => 1, 'quantityUnits' => (int)$fact['quantity_units'],
                'unitCostCents' => (int)$fact['unit_cost_cents'], 'costAmountCents' => (int)$fact['cost_amount_cents'],
                'sourceType' => self::SOURCE_TYPE, 'sourceId' => $orderId,
                'sourceDetailId' => (string)$fact['source_detail_id'], 'reversalOf' => (int)$fact['id'],
                'businessDate' => date('Y-m-d', $now), 'occurredAt' => $now, 'settledAt' => $now, 'recordedAt' => $now,
                // The same maintenance service is used in authorized local and
                // customer-instance repairs; audit wording must not mislabel production as local.
                'operatorType' => 'SYSTEM', 'operatorId' => 0, 'operatorName' => '系统预售库存纠正',
                'detailRemark' => $reason,
            ]);
        }
        return ['orderNo' => (string)$order['order_no'], 'restoredQuantityUnits' => array_sum($batchQuantities),
            'replayedQuantityUnits' => $replayed, 'restoredAllocationCount' => count($pending)];
    }

    /** Fail closed if the current physical balance already disagrees with settled history. */
    private function assertLedger(string $field, int $id, int $available): void
    {
        $sum = Db::name('inventory_batch_movement_fact')->where($field, $id)->where('fact_status', 'SETTLED')
            ->fieldRaw('SUM(IF(direction=1,quantity_units,-CAST(quantity_units AS SIGNED))) balance')->find();
        if ($available < 0 || (int)$sum['balance'] !== $available) {
            throw new \RuntimeException('presale_stock_repair_ledger_mismatch');
        }
    }

    /** Rewind only the erroneous allocated quantity; later legitimate consumption stays intact. */
    private function restore(string $table, array $row, int $quantity, int $now, bool $batch): void
    {
        $available = (int)$row['available_quantity_units'];
        if ($quantity <= 0 || $available > PHP_INT_MAX - $quantity) {
            throw new \RuntimeException('presale_stock_repair_quantity_overflow');
        }
        $changes = ['available_quantity_units' => $available + $quantity,
            'version' => (int)$row['version'] + 1, 'updated_at' => $now];
        if ($batch) $changes['cost_allocated_quantity_units'] = (int)$row['cost_allocated_quantity_units'] - $quantity;
        if (Db::name($table)->where('id', $row['id'])->where('version', $row['version'])->update($changes) !== 1) {
            throw new \RuntimeException('presale_stock_repair_cas_failed');
        }
    }
}
