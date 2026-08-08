<?php
declare(strict_types=1);

namespace app\services\product\inventory\completion;

use think\facade\Db;

/** Appends the batch quantity ledger inside the caller's inventory transaction. */
final class InventoryBatchMovementFactServices
{
    public function append(array $movement): int
    {
        $this->assertTransaction();
        $movement = $this->normalize($movement);
        $stock = Db::name('inventory_stock')->where('id', $movement['stockId'])->find();
        $batch = Db::name('inventory_batch')->where('id', $movement['batchId'])->find();
        $location = $stock ? Db::name('inventory_location')->where('id', (int)($stock['location_id'] ?? 0))->find() : null;
        if (!$stock || !$batch || !$location || (int)$batch['stock_id'] !== $movement['stockId']
            || (string)$stock['tenant_id'] !== $movement['tenantId']
            || (string)$stock['organization_id'] !== $movement['organizationId']
            || (string)$stock['organization_path'] !== $movement['organizationPath']
            || (int)$stock['store_id'] !== $movement['storeId']
            || (int)($stock['location_id'] ?? 0) <= 0
            || (string)$location['tenant_id'] !== $movement['tenantId']
            || (string)$location['organization_id'] !== $movement['organizationId']
            || (string)$location['organization_path'] !== $movement['organizationPath']
            || (string)$location['location_status'] !== 'ACTIVE'
            || !$this->locationOwnsStore((array)$location, $movement['storeId'])) {
            throw new InventoryCompletionContractException('inventory_batch_movement_scope_mismatch');
        }
        $movement['locationId'] = (int)$stock['location_id'];

        if ($movement['reversalOf'] > 0) {
            $original = Db::name('inventory_batch_movement_fact')
                ->where('id', $movement['reversalOf'])
                ->lock(true)
                ->find();
            if (!$original || (string)$original['tenant_id'] !== $movement['tenantId']
                || (int)$original['stock_id'] !== $movement['stockId']
                || (int)$original['batch_id'] !== $movement['batchId']
                || (int)$original['direction'] !== -$movement['direction']
                || (int)$original['quantity_units'] !== $movement['quantityUnits']
                || (int)$original['unit_cost_cents'] !== $movement['unitCostCents']
                || (int)$original['cost_amount_cents'] !== $movement['costAmountCents']) {
                throw new InventoryCompletionContractException('inventory_batch_movement_reversal_mismatch');
            }
        }

        $row = $this->databaseRow($movement);
        $existing = Db::name('inventory_batch_movement_fact')
            ->where('tenant_id', $movement['tenantId'])
            ->where('fact_key', $movement['factKey'])
            ->lock(true)
            ->find();
        if ($existing) {
            foreach ($row as $key => $value) {
                if ((string)$existing[$key] !== (string)$value) {
                    throw new InventoryCompletionContractException('inventory_batch_movement_idempotency_conflict');
                }
            }
            return (int)$existing['id'];
        }
        return (int)Db::name('inventory_batch_movement_fact')->insertGetId($row);
    }

    public function findConsumptionMovement(
        string $tenantId,
        int $receiptId,
        int $consumptionFactId
    ): array {
        $row = Db::name('inventory_batch_movement_fact')
            ->where('tenant_id', $tenantId)
            ->where('source_type', 'completion_batch')
            ->where('source_id', (string)$receiptId)
            ->where('source_detail_id', (string)$consumptionFactId)
            ->where('direction', -1)
            ->lock(true)
            ->find();
        return $row ?: [];
    }

    private function normalize(array $movement): array
    {
        $keys = [
            'factKey', 'tenantId', 'organizationId', 'organizationPath', 'storeId',
            'stockId', 'batchId', 'direction', 'quantityUnits', 'unitCostCents',
            'costAmountCents', 'sourceType', 'sourceId', 'sourceDetailId', 'reversalOf',
            'businessDate', 'occurredAt', 'settledAt', 'recordedAt',
        ];
        $actual = array_keys($movement);
        sort($keys, SORT_STRING);
        sort($actual, SORT_STRING);
        if ($actual !== $keys) {
            throw new InventoryCompletionContractException('inventory_batch_movement_shape_invalid');
        }
        foreach (['storeId', 'stockId', 'batchId', 'quantityUnits', 'unitCostCents',
                     'costAmountCents', 'reversalOf', 'occurredAt', 'settledAt', 'recordedAt'] as $key) {
            if (!is_int($movement[$key])) {
                throw new InventoryCompletionContractException('inventory_batch_movement_type_invalid');
            }
        }
        if (!is_int($movement['direction']) || !in_array($movement['direction'], [-1, 1], true)
            || $movement['storeId'] < 0 || $movement['stockId'] <= 0 || $movement['batchId'] <= 0
            || $movement['quantityUnits'] <= 0 || $movement['unitCostCents'] < 0
            || $movement['costAmountCents'] < 0 || $movement['reversalOf'] < 0
            || $movement['occurredAt'] <= 0 || $movement['settledAt'] < $movement['occurredAt']
            || $movement['recordedAt'] < $movement['settledAt']) {
            throw new InventoryCompletionContractException('inventory_batch_movement_value_invalid');
        }
        foreach ([
            'factKey' => 128, 'tenantId' => 32, 'organizationId' => 32,
            'organizationPath' => 191, 'sourceType' => 32, 'sourceId' => 96,
            'sourceDetailId' => 96,
        ] as $key => $maxLength) {
            $movement[$key] = trim((string)$movement[$key]);
            if ($movement[$key] === '' || strlen($movement[$key]) > $maxLength) {
                throw new InventoryCompletionContractException('inventory_batch_movement_token_invalid');
            }
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string)$movement['businessDate']);
        if (!$date || $date->format('Y-m-d') !== $movement['businessDate']) {
            throw new InventoryCompletionContractException('inventory_batch_movement_date_invalid');
        }
        return $movement;
    }

    private function databaseRow(array $movement): array
    {
        return [
            'fact_key' => $movement['factKey'],
            'tenant_id' => $movement['tenantId'],
            'organization_id' => $movement['organizationId'],
            'organization_path' => $movement['organizationPath'],
            'location_id' => $movement['locationId'],
            'store_id' => $movement['storeId'],
            'stock_id' => $movement['stockId'],
            'batch_id' => $movement['batchId'],
            'direction' => $movement['direction'],
            'quantity_units' => $movement['quantityUnits'],
            'unit_cost_cents' => $movement['unitCostCents'],
            'cost_amount_cents' => $movement['costAmountCents'],
            'fact_status' => 'SETTLED',
            'source_type' => $movement['sourceType'],
            'source_id' => $movement['sourceId'],
            'source_detail_id' => $movement['sourceDetailId'],
            'reversal_of' => $movement['reversalOf'],
            'business_date' => $movement['businessDate'],
            'occurred_at' => $movement['occurredAt'],
            'settled_at' => $movement['settledAt'],
            'recorded_at' => $movement['recordedAt'],
        ];
    }

    /**
     * A store ID of zero is valid only for the physical HQ location owned by
     * an organization root. The check happens after loading the persisted
     * location, so callers cannot manufacture a headquarters movement merely
     * by passing zero in a command payload.
     */
    private function locationOwnsStore(array $location, int $storeId): bool
    {
        if ((string)($location['location_type'] ?? '') === 'STORE') {
            return $storeId > 0 && (int)($location['store_id'] ?? 0) === $storeId && (int)($location['owner_id'] ?? 0) === $storeId;
        }
        return (string)($location['location_type'] ?? '') === 'HQ'
            && $storeId === 0
            && (int)($location['store_id'] ?? -1) === 0
            && (int)($location['owner_id'] ?? 0) > 0
            && (int)($location['owner_id'] ?? 0) === (int)($location['organization_id'] ?? 0)
            && (string)($location['organization_path'] ?? '') === '/' . (int)($location['owner_id'] ?? 0) . '/'
            && (int)($location['is_default'] ?? 0) === 1;
    }

    private function assertTransaction(): void
    {
        $pdo = Db::connect()->getPdo();
        if (!$pdo || !$pdo->inTransaction()) {
            throw new InventoryCompletionContractException('inventory_batch_movement_transaction_required');
        }
    }
}
