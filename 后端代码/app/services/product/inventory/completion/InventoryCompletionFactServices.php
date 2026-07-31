<?php
declare(strict_types=1);

namespace app\services\product\inventory\completion;

use think\facade\Db;

/**
 * Persists only the inventory part of an already planned C2 completion.
 * All methods require the caller's final transaction and never commit it.
 */
final class InventoryCompletionFactServices
{
    public function persistCompletion(
        array $command,
        array $completionPlan,
        array $providerSnapshot,
        InventoryCompletionDataScope $scope
    ): array {
        $this->assertTransaction();
        $command = $this->normalizeCommand($command, $scope);
        $this->assertProviderSnapshot($providerSnapshot, $command);
        $fingerprint = InventoryEntitlementCompletionContract::requestFingerprint([
            'command' => $command,
            'inventoryPlan' => $this->inventoryPlanFingerprintInput($completionPlan),
        ]);
        $existing = $this->lockReceiptForCommand(
            $command['tenantId'],
            $command['receiptKey'],
            $command['idempotencyKey']
        );
        if ($existing) {
            return $this->replayReceipt(
                $existing,
                $command['receiptKey'],
                $command['idempotencyKey'],
                $fingerprint
            );
        }

        $actions = $this->buildActions($command, $completionPlan, $providerSnapshot);
        $this->applyStockActions($actions['stocks'], $command['recordedAt']);
        $this->applyBatchActions($actions['batches'], $command['recordedAt']);
        $this->applyShortageCursorActions(
            $actions['shortageCursors'],
            $command['tenantId'],
            $command['storeId'],
            $command['recordedAt']
        );

        $single = count($actions['lines']) === 1 ? $actions['lines'][0] : null;
        $receiptId = (int)Db::name('inventory_consumption_receipt')->insertGetId([
            'receipt_key' => $command['receiptKey'],
            'idempotency_key' => $command['idempotencyKey'],
            'request_fingerprint' => $fingerprint,
            'contract_version' => InventoryEntitlementCompletionContract::CONTRACT_VERSION,
            'operation_type' => 'CONSUME',
            'reversal_of_receipt_id' => 0,
            'tenant_id' => $command['tenantId'],
            'organization_id' => $scope->organizationId(),
            'organization_path' => $scope->organizationPath(),
            'organization_name_snapshot' => $command['organizationNameSnapshot'],
            'store_id' => $command['storeId'],
            'store_name_snapshot' => $command['storeNameSnapshot'],
            'operator_id' => $scope->operatorId(),
            'source_type' => $command['sourceType'],
            'source_id' => $command['sourceId'],
            'source_detail_id' => $command['sourceDetailId'],
            'project_id' => $single ? $single['projectId'] : 0,
            'project_name_snapshot' => $single ? $single['projectNameSnapshot'] : '',
            'policy_value' => $single ? $single['policy'] : 'MULTI',
            'policy_version' => $single ? $single['policyVersion'] : 0,
            'recipe_id' => $single ? $single['recipeId'] : 0,
            'recipe_version' => $single ? $single['recipeVersion'] : 0,
            'recipe_formula_hash' => $single ? $single['recipeFormulaHash'] : '',
            'actual_cost_cents' => $actions['actualCostCents'],
            'estimated_shortage_cost_cents' => $actions['estimatedShortageCostCents'],
            'cost_complete_at_settlement' => $actions['costComplete'] ? 1 : 0,
            'result_snapshot' => '',
            'business_date' => $command['businessDate'],
            'occurred_at' => $command['occurredAt'],
            'settled_at' => $command['settledAt'],
            'recorded_at' => $command['recordedAt'],
        ]);

        $this->insertBatchFacts($receiptId, $command, $scope, $actions['batchFacts']);
        $this->insertShortageFacts($receiptId, $command, $scope, $actions['shortageFacts']);
        $result = [
            'contractVersion' => InventoryEntitlementCompletionContract::CONTRACT_VERSION,
            'receiptId' => $receiptId,
            'receiptKey' => $command['receiptKey'],
            'replayed' => false,
            'actualCostCents' => $actions['actualCostCents'],
            'estimatedShortageCostCents' => $actions['estimatedShortageCostCents'],
            'costComplete' => $actions['costComplete'],
            'batchFactCount' => count($actions['batchFacts']),
            'shortageFactCount' => count($actions['shortageFacts']),
        ];
        Db::name('inventory_consumption_receipt')->where('id', $receiptId)->update([
            'result_snapshot' => $this->encode($result),
        ]);
        return $result;
    }

    public function reverseCompletion(
        array $command,
        string $originalReceiptKey,
        InventoryCompletionDataScope $scope
    ): array {
        $this->assertTransaction();
        $command = $this->normalizeCommand($command, $scope);
        $originalReceiptKey = $this->token($originalReceiptKey, 'original_receipt_key', 96);
        $fingerprint = InventoryEntitlementCompletionContract::requestFingerprint([
            'command' => $command,
            'originalReceiptKey' => $originalReceiptKey,
        ]);
        $existing = $this->lockReceiptForCommand(
            $command['tenantId'],
            $command['receiptKey'],
            $command['idempotencyKey']
        );
        if ($existing) {
            return $this->replayReceipt(
                $existing,
                $command['receiptKey'],
                $command['idempotencyKey'],
                $fingerprint
            );
        }
        $original = $this->lockReceipt($command['tenantId'], $originalReceiptKey);
        if (!$original || (string)$original['operation_type'] !== 'CONSUME'
            || (int)$original['store_id'] !== $command['storeId']) {
            throw $this->failure('inventory_original_receipt_not_found');
        }
        if (Db::name('inventory_consumption_receipt')
            ->where('tenant_id', $command['tenantId'])
            ->where('reversal_of_receipt_id', (int)$original['id'])
            ->lock(true)
            ->find()) {
            throw $this->failure('inventory_original_receipt_already_reversed');
        }

        $allocations = Db::name('inventory_batch_consumption_fact')
            ->where('receipt_id', (int)$original['id'])
            ->where('direction', 1)
            ->order('id asc')
            ->lock(true)
            ->select()
            ->toArray();
        $shortages = Db::name('inventory_shortage_fact')
            ->where('receipt_id', (int)$original['id'])
            ->where('direction', 1)
            ->order('id asc')
            ->lock(true)
            ->select()
            ->toArray();

        $stockQty = [];
        $batchQty = [];
        foreach ($allocations as $fact) {
            $stockId = (int)$fact['stock_id'];
            $batchId = (int)$fact['batch_id'];
            $qty = (int)$fact['quantity_units'];
            $stockQty[$stockId] = ($stockQty[$stockId] ?? 0) + $qty;
            $batchQty[$batchId] = ($batchQty[$batchId] ?? 0) + $qty;
        }
        $this->sortResourceMap($stockQty);
        foreach ($stockQty as $stockId => $qty) {
            $stock = Db::name('inventory_stock')->where('id', $stockId)->lock(true)->find();
            if (!$stock || (string)$stock['tenant_id'] !== $command['tenantId']
                || (int)$stock['store_id'] !== $command['storeId']) {
                throw $this->failure('inventory_reversal_stock_not_found', ['stockId' => $stockId]);
            }
            Db::name('inventory_stock')->where('id', $stockId)->update([
                'available_quantity_units' => (int)$stock['available_quantity_units'] + $qty,
                'version' => (int)$stock['version'] + 1,
                'updated_at' => $command['recordedAt'],
            ]);
        }
        $this->sortResourceMap($batchQty);
        foreach ($batchQty as $batchId => $qty) {
            $batch = Db::name('inventory_batch')->where('id', $batchId)->lock(true)->find();
            if (!$batch) {
                throw $this->failure('inventory_reversal_batch_not_found', ['batchId' => $batchId]);
            }
            Db::name('inventory_batch')->where('id', $batchId)->update([
                'available_quantity_units' => (int)$batch['available_quantity_units'] + $qty,
                'version' => (int)$batch['version'] + 1,
                'updated_at' => $command['recordedAt'],
            ]);
        }

        $receiptId = (int)Db::name('inventory_consumption_receipt')->insertGetId([
            'receipt_key' => $command['receiptKey'],
            'idempotency_key' => $command['idempotencyKey'],
            'request_fingerprint' => $fingerprint,
            'contract_version' => InventoryEntitlementCompletionContract::CONTRACT_VERSION,
            'operation_type' => 'REVERSAL',
            'reversal_of_receipt_id' => (int)$original['id'],
            'tenant_id' => $command['tenantId'],
            'organization_id' => $scope->organizationId(),
            'organization_path' => $scope->organizationPath(),
            'organization_name_snapshot' => $command['organizationNameSnapshot'],
            'store_id' => $command['storeId'],
            'store_name_snapshot' => $command['storeNameSnapshot'],
            'operator_id' => $scope->operatorId(),
            'source_type' => $command['sourceType'],
            'source_id' => $command['sourceId'],
            'source_detail_id' => $command['sourceDetailId'],
            'project_id' => (int)$original['project_id'],
            'project_name_snapshot' => (string)$original['project_name_snapshot'],
            'policy_value' => (string)$original['policy_value'],
            'policy_version' => (int)$original['policy_version'],
            'recipe_id' => (int)$original['recipe_id'],
            'recipe_version' => (int)$original['recipe_version'],
            'recipe_formula_hash' => (string)$original['recipe_formula_hash'],
            'actual_cost_cents' => (int)$original['actual_cost_cents'],
            'estimated_shortage_cost_cents' => (int)$original['estimated_shortage_cost_cents'],
            'cost_complete_at_settlement' => (int)$original['cost_complete_at_settlement'],
            'result_snapshot' => '',
            'business_date' => $command['businessDate'],
            'occurred_at' => $command['occurredAt'],
            'settled_at' => $command['settledAt'],
            'recorded_at' => $command['recordedAt'],
        ]);
        foreach ($allocations as $fact) {
            $copy = $fact;
            unset($copy['id']);
            $copy['fact_key'] = 'invbr:' . hash('sha256', $command['receiptKey'] . ':' . $fact['id']);
            $copy['receipt_id'] = $receiptId;
            $copy['reversal_of'] = (int)$fact['id'];
            $copy['direction'] = -1;
            $copy['business_date'] = $command['businessDate'];
            $copy['occurred_at'] = $command['occurredAt'];
            $copy['settled_at'] = $command['settledAt'];
            $copy['recorded_at'] = $command['recordedAt'];
            $reversalFactId = (int)Db::name('inventory_batch_consumption_fact')->insertGetId($copy);
            $movementFacts = new InventoryBatchMovementFactServices();
            $originalMovement = $movementFacts->findConsumptionMovement(
                $command['tenantId'],
                (int)$original['id'],
                (int)$fact['id']
            );
            $this->appendBatchMovement(
                $movementFacts,
                $receiptId,
                $reversalFactId,
                $command,
                $scope,
                (int)$fact['stock_id'],
                (int)$fact['batch_id'],
                1,
                (int)$fact['quantity_units'],
                (int)$fact['unit_cost_cents'],
                (int)$fact['actual_cost_cents'],
                $originalMovement ? (int)$originalMovement['id'] : 0
            );
        }
        foreach ($shortages as $fact) {
            $copy = $fact;
            unset($copy['id']);
            $copy['fact_key'] = 'invsr:' . hash('sha256', $command['receiptKey'] . ':' . $fact['id']);
            $copy['receipt_id'] = $receiptId;
            $copy['reversal_of'] = (int)$fact['id'];
            $copy['direction'] = -1;
            $copy['business_date'] = $command['businessDate'];
            $copy['occurred_at'] = $command['occurredAt'];
            $copy['settled_at'] = $command['settledAt'];
            $copy['recorded_at'] = $command['recordedAt'];
            Db::name('inventory_shortage_fact')->insert($copy);
        }
        $this->reverseShortageAdjustments($shortages, $receiptId, $command);
        $result = [
            'contractVersion' => InventoryEntitlementCompletionContract::CONTRACT_VERSION,
            'receiptId' => $receiptId,
            'receiptKey' => $command['receiptKey'],
            'reversalOfReceiptId' => (int)$original['id'],
            'replayed' => false,
            'batchFactCount' => count($allocations),
            'shortageFactCount' => count($shortages),
        ];
        Db::name('inventory_consumption_receipt')->where('id', $receiptId)->update([
            'result_snapshot' => $this->encode($result),
        ]);
        return $result;
    }

    public function recordShortageCostAdjustment(
        array $command,
        int $shortageFactId,
        int $quantityUnits,
        int $actualUnitCostCents,
        InventoryCompletionDataScope $scope
    ): array {
        $this->assertTransaction();
        $command = $this->normalizeAdjustmentCommand($command, $scope);
        if ($shortageFactId <= 0 || $quantityUnits <= 0 || $actualUnitCostCents < 0) {
            throw $this->failure('inventory_cost_adjustment_invalid');
        }
        $shortage = Db::name('inventory_shortage_fact')->where('id', $shortageFactId)->lock(true)->find();
        if (!$shortage || (string)$shortage['tenant_id'] !== $command['tenantId']
            || (int)$shortage['store_id'] !== $command['storeId'] || (int)$shortage['direction'] !== 1) {
            throw $this->failure('inventory_shortage_fact_not_found');
        }
        $existing = Db::name('inventory_shortage_cost_adjustment')
            ->where('tenant_id', $command['tenantId'])
            ->where('adjustment_key', $command['adjustmentKey'])
            ->lock(true)
            ->find();
        $fingerprint = InventoryEntitlementCompletionContract::requestFingerprint([
            'command' => $command,
            'shortageFactId' => $shortageFactId,
            'quantityUnits' => $quantityUnits,
            'actualUnitCostCents' => $actualUnitCostCents,
        ]);
        if ($existing) {
            if ((string)$existing['request_fingerprint'] !== $fingerprint) {
                throw $this->failure('inventory_cost_adjustment_idempotency_conflict');
            }
            return ['adjustmentId' => (int)$existing['id'], 'replayed' => true];
        }
        $netAdjusted = (int)Db::name('inventory_shortage_cost_adjustment')
            ->where('shortage_fact_id', $shortageFactId)
            ->sum(Db::raw("IF(direction=1,allocated_quantity_units,-CAST(allocated_quantity_units AS SIGNED))"));
        if ($netAdjusted < 0 || $quantityUnits > (int)$shortage['shortage_quantity_units'] - $netAdjusted) {
            throw $this->failure('inventory_cost_adjustment_quantity_exceeded');
        }
        $sameCostCursor = (int)Db::name('inventory_shortage_cost_adjustment')
            ->where('shortage_fact_id', $shortageFactId)
            ->where('actual_unit_cost_cents', $actualUnitCostCents)
            ->sum(Db::raw("IF(direction=1,allocated_quantity_units,-CAST(allocated_quantity_units AS SIGNED))"));
        $actualCost = $this->scaledCostDelta(
            $sameCostCursor,
            $quantityUnits,
            $actualUnitCostCents,
            (int)$shortage['quantity_scale']
        );
        $version = (int)Db::name('inventory_shortage_cost_adjustment')
            ->where('shortage_fact_id', $shortageFactId)
            ->max('adjustment_version') + 1;
        $id = (int)Db::name('inventory_shortage_cost_adjustment')->insertGetId([
            'adjustment_key' => $command['adjustmentKey'],
            'shortage_fact_id' => $shortageFactId,
            'reversal_of' => 0,
            'direction' => 1,
            'tenant_id' => $command['tenantId'],
            'store_id' => $command['storeId'],
            'adjustment_version' => max(1, $version),
            'allocated_quantity_units' => $quantityUnits,
            'actual_unit_cost_cents' => $actualUnitCostCents,
            'actual_cost_cents' => $actualCost,
            'source_type' => $command['sourceType'],
            'source_id' => $command['sourceId'],
            'request_fingerprint' => $fingerprint,
            'occurred_at' => $command['occurredAt'],
            'recorded_at' => $command['recordedAt'],
        ]);
        return [
            'adjustmentId' => $id,
            'replayed' => false,
            'actualCostCents' => $actualCost,
            'costComplete' => $netAdjusted + $quantityUnits === (int)$shortage['shortage_quantity_units'],
        ];
    }

    private function buildActions(array $command, array $plan, array $providerSnapshot): array
    {
        if (!isset($plan['linePlans']) || !is_array($plan['linePlans']) || !$plan['linePlans']) {
            throw $this->failure('inventory_completion_plan_invalid');
        }
        $persistStocks = $providerSnapshot['persistenceSnapshot'];
        $stocks = [];
        $batches = [];
        $shortageCursors = [];
        $batchFacts = [];
        $shortageFacts = [];
        $lines = [];
        $actualCost = 0;
        $estimatedCost = 0;
        $costComplete = true;
        foreach ($plan['linePlans'] as $line) {
            if (!is_array($line) || !isset($line['lineId'], $line['source']['projectId'], $line['inventory'])) {
                throw $this->failure('inventory_completion_line_plan_invalid');
            }
            $inventory = $line['inventory'];
            $lineId = $this->token((string)$line['lineId'], 'line_id', 64);
            $projectId = (int)$line['source']['projectId'];
            $lineSnapshot = [
                'lineId' => $lineId,
                'projectId' => $projectId,
                'projectNameSnapshot' => (string)($providerSnapshot['projectNameSnapshotByLineId'][$lineId] ?? ''),
                'policy' => (string)$inventory['policy'],
                'policyVersion' => (int)$inventory['policyVersion'],
                'recipeId' => (int)$inventory['recipeId'],
                'recipeVersion' => (int)$inventory['recipeVersion'],
                'recipeFormulaHash' => (string)$inventory['recipeFormulaHash'],
            ];
            $lines[] = $lineSnapshot;
            foreach ((array)$inventory['consumables'] as $consumable) {
                $stockId = (string)$consumable['stockId'];
                if (!isset($persistStocks[$stockId]) || !ctype_digit($stockId)) {
                    throw $this->failure('inventory_persistence_stock_snapshot_missing', ['stockId' => $stockId]);
                }
                $meta = $persistStocks[$stockId];
                $providerCursor = $this->providerCursorSnapshot($providerSnapshot, $lineId, $stockId);
                $shortageCursorId = (string)($consumable['shortageCursorId'] ?? '');
                $shortageCursorVersion = (int)($consumable['shortageCursorVersion'] ?? 0);
                $expectedCursorId = InventoryEntitlementCompletionContract::shortageCursorResourceId(
                    (int)$stockId,
                    $lineSnapshot['recipeId'],
                    (int)$consumable['shortageEstimatedUnitCostCents']
                );
                if ($shortageCursorId !== $expectedCursorId
                    || $shortageCursorVersion <= 0
                    || $providerCursor['shortageCursorId'] !== $shortageCursorId
                    || $providerCursor['shortageCursorVersion'] !== $shortageCursorVersion) {
                    throw $this->failure('inventory_shortage_cursor_resource_mismatch', [
                        'lineId' => $lineId,
                        'stockId' => $stockId,
                    ]);
                }
                $required = (int)$consumable['requiredQuantityUnits'];
                $actual = (int)$consumable['actualQuantityUnits'];
                $shortage = (int)$consumable['shortageQuantityUnits'];
                if ($required <= 0 || $actual < 0 || $shortage < 0 || $actual + $shortage !== $required) {
                    throw $this->failure('inventory_consumable_quantity_plan_invalid');
                }
                if ($shortage > 0 && $inventory['policy'] === InventoryEntitlementCompletionContract::POLICY_DENY) {
                    throw $this->failure('inventory_shortage_denied');
                }
                if ($shortage > 0 && $inventory['policy'] !== InventoryEntitlementCompletionContract::POLICY_ALLOW) {
                    throw $this->failure('inventory_shortage_policy_invalid');
                }
                if (!isset($stocks[$stockId])) {
                    $stocks[$stockId] = [
                        'id' => (int)$stockId,
                        'expectedVersion' => (int)$consumable['stockVersion'],
                        'quantityUnits' => 0,
                    ];
                } elseif ($stocks[$stockId]['expectedVersion'] !== (int)$consumable['stockVersion']) {
                    throw $this->failure('inventory_stock_version_plan_inconsistent');
                }
                $stocks[$stockId]['quantityUnits'] += $actual;
                foreach ((array)$consumable['batchAllocations'] as $allocation) {
                    $batchId = (int)$allocation['batchId'];
                    $quantity = (int)$allocation['quantityUnits'];
                    if ($batchId <= 0 || $quantity <= 0 || (int)$allocation['actualCostCents'] < 0) {
                        throw $this->failure('inventory_batch_allocation_invalid');
                    }
                    if (!isset($batches[$batchId])) {
                        $batches[$batchId] = [
                            'id' => $batchId,
                            'stockId' => (int)$stockId,
                            'expectedVersion' => (int)$allocation['batchVersion'],
                            'quantityUnits' => 0,
                            'cursorBefore' => (int)$allocation['costAllocatedQuantityUnitsBefore'],
                            'cursorAfter' => (int)$allocation['costAllocatedQuantityUnitsBefore'],
                        ];
                    }
                    $batchAction = &$batches[$batchId];
                    if ($batchAction['stockId'] !== (int)$stockId
                        || $batchAction['expectedVersion'] !== (int)$allocation['batchVersion']
                        || $batchAction['cursorAfter'] !== (int)$allocation['costAllocatedQuantityUnitsBefore']) {
                        throw $this->failure('inventory_batch_cursor_plan_inconsistent', ['batchId' => $batchId]);
                    }
                    $batchAction['quantityUnits'] += $quantity;
                    $batchAction['cursorAfter'] = (int)$allocation['costAllocatedQuantityUnitsAfter'];
                    unset($batchAction);
                    $batchFacts[] = compact('lineSnapshot', 'meta', 'stockId', 'batchId', 'allocation');
                }
                if ($shortage > 0) {
                    $cursorKey = $shortageCursorId;
                    if (!isset($shortageCursors[$cursorKey])) {
                        if ($providerCursor['shortageCostAllocatedQuantityUnitsBefore']
                            !== (int)$consumable['shortageCostAllocatedQuantityUnitsBefore']) {
                            throw $this->failure('inventory_shortage_cursor_snapshot_mismatch');
                        }
                        $shortageCursors[$cursorKey] = [
                            'id' => $shortageCursorId,
                            'stockId' => (int)$stockId,
                            'recipeId' => $lineSnapshot['recipeId'],
                            'estimatedUnitCostCents' => (int)$consumable['shortageEstimatedUnitCostCents'],
                            'expectedVersion' => $shortageCursorVersion,
                            'cursorBefore' => (int)$consumable['shortageCostAllocatedQuantityUnitsBefore'],
                            'cursorAfter' => (int)$consumable['shortageCostAllocatedQuantityUnitsBefore'],
                        ];
                    }
                    if ($shortageCursors[$cursorKey]['expectedVersion'] !== $shortageCursorVersion
                        || $shortageCursors[$cursorKey]['cursorAfter']
                            !== (int)$consumable['shortageCostAllocatedQuantityUnitsBefore']) {
                        throw $this->failure('inventory_shortage_cursor_plan_inconsistent');
                    }
                    $shortageCursors[$cursorKey]['cursorAfter'] = (int)$consumable['shortageCostAllocatedQuantityUnitsAfter'];
                    $shortageFacts[] = compact('lineSnapshot', 'meta', 'stockId', 'consumable');
                }
                $actualCost += (int)$consumable['actualCostCents'];
                $estimatedCost += (int)$consumable['estimatedShortageCostCents'];
                $costComplete = $costComplete && (bool)$consumable['costComplete'];
            }
        }
        return [
            'stocks' => $stocks,
            'batches' => $batches,
            'shortageCursors' => $shortageCursors,
            'batchFacts' => $batchFacts,
            'shortageFacts' => $shortageFacts,
            'lines' => $lines,
            'actualCostCents' => $actualCost,
            'estimatedShortageCostCents' => $estimatedCost,
            'costComplete' => $costComplete,
        ];
    }

    private function applyStockActions(array $actions, int $recordedAt): void
    {
        $this->sortResourceMap($actions);
        foreach ($actions as $action) {
            $stock = Db::name('inventory_stock')->where('id', $action['id'])->lock(true)->find();
            if (!$stock || (int)$stock['version'] !== $action['expectedVersion']
                || (int)$stock['available_quantity_units'] < $action['quantityUnits']) {
                throw $this->failure('inventory_stock_version_or_quantity_changed', ['stockId' => $action['id']]);
            }
            Db::name('inventory_stock')->where('id', $action['id'])->update([
                'available_quantity_units' => (int)$stock['available_quantity_units'] - $action['quantityUnits'],
                'version' => (int)$stock['version'] + 1,
                'updated_at' => $recordedAt,
            ]);
        }
    }

    private function applyBatchActions(array $actions, int $recordedAt): void
    {
        $this->sortResourceMap($actions);
        foreach ($actions as $action) {
            $batch = Db::name('inventory_batch')->where('id', $action['id'])->lock(true)->find();
            if (!$batch || (int)$batch['stock_id'] !== $action['stockId']
                || (int)$batch['version'] !== $action['expectedVersion']
                || (int)$batch['available_quantity_units'] < $action['quantityUnits']
                || (int)$batch['cost_allocated_quantity_units'] !== $action['cursorBefore']
                || $action['cursorAfter'] - $action['cursorBefore'] !== $action['quantityUnits']) {
                throw $this->failure('inventory_batch_version_quantity_or_cursor_changed', ['batchId' => $action['id']]);
            }
            Db::name('inventory_batch')->where('id', $action['id'])->update([
                'available_quantity_units' => (int)$batch['available_quantity_units'] - $action['quantityUnits'],
                'cost_allocated_quantity_units' => $action['cursorAfter'],
                'version' => (int)$batch['version'] + 1,
                'updated_at' => $recordedAt,
            ]);
        }
    }

    private function applyShortageCursorActions(
        array $actions,
        string $tenantId,
        int $storeId,
        int $recordedAt
    ): void
    {
        $this->sortResourceMap($actions);
        foreach ($actions as $action) {
            $expectedResourceId = InventoryEntitlementCompletionContract::shortageCursorResourceId(
                $action['stockId'],
                $action['recipeId'],
                $action['estimatedUnitCostCents']
            );
            if ($action['id'] !== $expectedResourceId) {
                throw $this->failure('inventory_shortage_cursor_identity_changed');
            }
            $query = Db::name('inventory_shortage_cost_cursor')
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->where('stock_id', $action['stockId'])
                ->where('recipe_id', $action['recipeId'])
                ->where('estimated_unit_cost_cents', $action['estimatedUnitCostCents']);
            $row = $query->lock(true)->find();
            if (!$row || (int)$row['version'] !== $action['expectedVersion']
                || (int)$row['allocated_quantity_units'] !== $action['cursorBefore']
                || $action['cursorAfter'] <= $action['cursorBefore']) {
                throw $this->failure('inventory_shortage_cost_cursor_changed');
            }
            $updated = Db::name('inventory_shortage_cost_cursor')
                ->where('id', (int)$row['id'])
                ->where('version', $action['expectedVersion'])
                ->update([
                    'allocated_quantity_units' => $action['cursorAfter'],
                    'version' => (int)$row['version'] + 1,
                    'updated_at' => $recordedAt,
                ]);
            if ($updated !== 1) {
                throw $this->failure('inventory_shortage_cost_cursor_cas_failed');
            }
        }
    }

    private function insertBatchFacts(int $receiptId, array $command, InventoryCompletionDataScope $scope, array $facts): void
    {
        $movementFacts = new InventoryBatchMovementFactServices();
        foreach ($facts as $index => $fact) {
            $line = $fact['lineSnapshot'];
            $meta = $fact['meta'];
            $allocation = $fact['allocation'];
            $batchId = $fact['batchId'];
            $consumptionFactId = (int)Db::name('inventory_batch_consumption_fact')->insertGetId([
                'fact_key' => 'invb:' . hash('sha256', $command['receiptKey'] . ':' . $line['lineId'] . ':' . $fact['stockId'] . ':' . $batchId . ':' . $index),
                'receipt_id' => $receiptId,
                'reversal_of' => 0,
                'direction' => 1,
                'tenant_id' => $command['tenantId'],
                'organization_path' => $scope->organizationPath(),
                'store_id' => $command['storeId'],
                'source_type' => $command['sourceType'],
                'source_id' => $command['sourceId'],
                'source_detail_id' => $command['sourceDetailId'],
                'line_id' => $line['lineId'],
                'project_id' => $line['projectId'],
                'project_name_snapshot' => $line['projectNameSnapshot'],
                'recipe_id' => $line['recipeId'],
                'recipe_version' => $line['recipeVersion'],
                'recipe_formula_hash' => $line['recipeFormulaHash'],
                'policy_value' => $line['policy'],
                'policy_version' => $line['policyVersion'],
                'consumable_product_id' => $meta['consumableId'],
                'consumable_name_snapshot' => $meta['consumableNameSnapshot'],
                'sku_id' => $meta['skuId'],
                'sku_name_snapshot' => $meta['skuNameSnapshot'],
                'stock_id' => (int)$fact['stockId'],
                'batch_id' => $batchId,
                'batch_no_snapshot' => (string)($meta['batchNumberById'][(string)$batchId] ?? ''),
                'quantity_scale' => $meta['quantityScale'],
                'quantity_units' => (int)$allocation['quantityUnits'],
                'unit_cost_cents' => (int)$allocation['unitCostCents'],
                'actual_cost_cents' => (int)$allocation['actualCostCents'],
                'cost_cursor_before' => (int)$allocation['costAllocatedQuantityUnitsBefore'],
                'cost_cursor_after' => (int)$allocation['costAllocatedQuantityUnitsAfter'],
                'batch_version_before' => (int)$allocation['batchVersion'],
                'batch_version_after' => (int)$allocation['batchVersion'] + 1,
                'business_date' => $command['businessDate'],
                'occurred_at' => $command['occurredAt'],
                'settled_at' => $command['settledAt'],
                'recorded_at' => $command['recordedAt'],
            ]);
            $this->appendBatchMovement(
                $movementFacts,
                $receiptId,
                $consumptionFactId,
                $command,
                $scope,
                (int)$fact['stockId'],
                $batchId,
                -1,
                (int)$allocation['quantityUnits'],
                (int)$allocation['unitCostCents'],
                (int)$allocation['actualCostCents'],
                0
            );
        }
    }

    private function appendBatchMovement(
        InventoryBatchMovementFactServices $movementFacts,
        int $receiptId,
        int $consumptionFactId,
        array $command,
        InventoryCompletionDataScope $scope,
        int $stockId,
        int $batchId,
        int $direction,
        int $quantityUnits,
        int $unitCostCents,
        int $costAmountCents,
        int $reversalOf
    ): void {
        $movementFacts->append([
            'factKey' => 'invm:' . hash('sha256', $command['receiptKey'] . ':' . $consumptionFactId),
            'tenantId' => $command['tenantId'],
            'organizationId' => $scope->organizationId(),
            'organizationPath' => $scope->organizationPath(),
            'storeId' => $command['storeId'],
            'stockId' => $stockId,
            'batchId' => $batchId,
            'direction' => $direction,
            'quantityUnits' => $quantityUnits,
            'unitCostCents' => $unitCostCents,
            'costAmountCents' => $costAmountCents,
            'sourceType' => 'completion_batch',
            'sourceId' => (string)$receiptId,
            'sourceDetailId' => (string)$consumptionFactId,
            'reversalOf' => $reversalOf,
            'businessDate' => $command['businessDate'],
            'occurredAt' => $command['occurredAt'],
            'settledAt' => $command['settledAt'],
            'recordedAt' => $command['recordedAt'],
        ]);
    }

    private function insertShortageFacts(int $receiptId, array $command, InventoryCompletionDataScope $scope, array $facts): void
    {
        foreach ($facts as $index => $fact) {
            $line = $fact['lineSnapshot'];
            $meta = $fact['meta'];
            $consumable = $fact['consumable'];
            Db::name('inventory_shortage_fact')->insert([
                'fact_key' => 'invs:' . hash('sha256', $command['receiptKey'] . ':' . $line['lineId'] . ':' . $fact['stockId'] . ':' . $index),
                'receipt_id' => $receiptId,
                'reversal_of' => 0,
                'direction' => 1,
                'tenant_id' => $command['tenantId'],
                'organization_path' => $scope->organizationPath(),
                'store_id' => $command['storeId'],
                'source_type' => $command['sourceType'],
                'source_id' => $command['sourceId'],
                'source_detail_id' => $command['sourceDetailId'],
                'line_id' => $line['lineId'],
                'project_id' => $line['projectId'],
                'project_name_snapshot' => $line['projectNameSnapshot'],
                'recipe_id' => $line['recipeId'],
                'recipe_version' => $line['recipeVersion'],
                'recipe_formula_hash' => $line['recipeFormulaHash'],
                'consumable_product_id' => $meta['consumableId'],
                'consumable_name_snapshot' => $meta['consumableNameSnapshot'],
                'sku_id' => $meta['skuId'],
                'sku_name_snapshot' => $meta['skuNameSnapshot'],
                'stock_id' => (int)$fact['stockId'],
                'quantity_scale' => $meta['quantityScale'],
                'shortage_quantity_units' => (int)$consumable['shortageQuantityUnits'],
                'estimated_unit_cost_cents' => (int)$consumable['shortageEstimatedUnitCostCents'],
                'estimated_cost_cents' => (int)$consumable['estimatedShortageCostCents'],
                'cost_cursor_before' => (int)$consumable['shortageCostAllocatedQuantityUnitsBefore'],
                'cost_cursor_after' => (int)$consumable['shortageCostAllocatedQuantityUnitsAfter'],
                'policy_value' => $line['policy'],
                'policy_version' => $line['policyVersion'],
                'business_date' => $command['businessDate'],
                'occurred_at' => $command['occurredAt'],
                'settled_at' => $command['settledAt'],
                'recorded_at' => $command['recordedAt'],
            ]);
        }
    }

    private function reverseShortageAdjustments(array $shortages, int $receiptId, array $command): void
    {
        foreach ($shortages as $shortage) {
            $adjustments = Db::name('inventory_shortage_cost_adjustment')
                ->where('shortage_fact_id', (int)$shortage['id'])
                ->where('direction', 1)
                ->order('id asc')
                ->lock(true)
                ->select()
                ->toArray();
            foreach ($adjustments as $adjustment) {
                $copy = $adjustment;
                unset($copy['id']);
                $copy['adjustment_key'] = 'invcar:' . hash('sha256', $command['receiptKey'] . ':' . $receiptId . ':' . $adjustment['id']);
                $copy['reversal_of'] = (int)$adjustment['id'];
                $copy['direction'] = -1;
                $copy['adjustment_version'] = (int)$adjustment['adjustment_version'] + 1000000000;
                $copy['source_type'] = 'inventory_reversal';
                $copy['source_id'] = (string)$receiptId;
                $copy['occurred_at'] = $command['occurredAt'];
                $copy['recorded_at'] = $command['recordedAt'];
                Db::name('inventory_shortage_cost_adjustment')->insert($copy);
            }
        }
    }

    private function normalizeCommand(array $command, InventoryCompletionDataScope $scope): array
    {
        $this->assertExactKeys($command, [
            'contractVersion', 'receiptKey', 'idempotencyKey', 'tenantId', 'storeId',
            'organizationNameSnapshot', 'storeNameSnapshot', 'sourceType', 'sourceId',
            'sourceDetailId', 'businessDate', 'occurredAt', 'settledAt', 'recordedAt',
        ]);
        if ($command['contractVersion'] !== InventoryEntitlementCompletionContract::CONTRACT_VERSION) {
            throw $this->failure('inventory_contract_version_mismatch');
        }
        $tenantId = $this->token((string)$command['tenantId'], 'tenant_id', 32);
        $storeId = is_int($command['storeId']) ? $command['storeId'] : 0;
        $scope->assertTenantAndStore($tenantId, $storeId);
        $businessDate = (string)$command['businessDate'];
        if (!$this->validDate($businessDate)) {
            throw $this->failure('inventory_business_date_invalid');
        }
        foreach (['occurredAt', 'settledAt', 'recordedAt'] as $timeKey) {
            if (!is_int($command[$timeKey]) || $command[$timeKey] <= 0) {
                throw $this->failure('inventory_time_invalid', ['field' => $timeKey]);
            }
        }
        if ($command['settledAt'] < $command['occurredAt'] || $command['recordedAt'] < $command['occurredAt']) {
            throw $this->failure('inventory_time_order_invalid');
        }
        return [
            'contractVersion' => $command['contractVersion'],
            'receiptKey' => $this->token((string)$command['receiptKey'], 'receipt_key', 96),
            'idempotencyKey' => $this->token((string)$command['idempotencyKey'], 'idempotency_key', 96),
            'tenantId' => $tenantId,
            'storeId' => $storeId,
            'organizationNameSnapshot' => $this->text((string)$command['organizationNameSnapshot'], 100),
            'storeNameSnapshot' => $this->text((string)$command['storeNameSnapshot'], 100),
            'sourceType' => $this->token((string)$command['sourceType'], 'source_type', 32),
            'sourceId' => $this->token((string)$command['sourceId'], 'source_id', 64),
            'sourceDetailId' => $this->token((string)$command['sourceDetailId'], 'source_detail_id', 64),
            'businessDate' => $businessDate,
            'occurredAt' => $command['occurredAt'],
            'settledAt' => $command['settledAt'],
            'recordedAt' => $command['recordedAt'],
        ];
    }

    private function normalizeAdjustmentCommand(array $command, InventoryCompletionDataScope $scope): array
    {
        $this->assertExactKeys($command, [
            'contractVersion', 'adjustmentKey', 'tenantId', 'storeId', 'sourceType', 'sourceId',
            'occurredAt', 'recordedAt',
        ]);
        if ($command['contractVersion'] !== InventoryEntitlementCompletionContract::CONTRACT_VERSION) {
            throw $this->failure('inventory_contract_version_mismatch');
        }
        $tenantId = $this->token((string)$command['tenantId'], 'tenant_id', 32);
        $storeId = is_int($command['storeId']) ? $command['storeId'] : 0;
        $scope->assertTenantAndStore($tenantId, $storeId);
        if (!is_int($command['occurredAt']) || $command['occurredAt'] <= 0
            || !is_int($command['recordedAt']) || $command['recordedAt'] < $command['occurredAt']) {
            throw $this->failure('inventory_time_invalid');
        }
        return [
            'contractVersion' => $command['contractVersion'],
            'adjustmentKey' => $this->token((string)$command['adjustmentKey'], 'adjustment_key', 128),
            'tenantId' => $tenantId,
            'storeId' => $storeId,
            'sourceType' => $this->token((string)$command['sourceType'], 'source_type', 32),
            'sourceId' => $this->token((string)$command['sourceId'], 'source_id', 64),
            'occurredAt' => $command['occurredAt'],
            'recordedAt' => $command['recordedAt'],
        ];
    }

    private function assertProviderSnapshot(array $snapshot, array $command): void
    {
        if (($snapshot['contractVersion'] ?? '') !== InventoryEntitlementCompletionContract::CONTRACT_VERSION
            || ($snapshot['shortageCursorLockGate'] ?? '')
                !== InventoryEntitlementCompletionContract::SHORTAGE_CURSOR_GATE
            || ($snapshot['tenantId'] ?? '') !== $command['tenantId']
            || ($snapshot['storeId'] ?? 0) !== $command['storeId']
            || !isset($snapshot['persistenceSnapshot']) || !is_array($snapshot['persistenceSnapshot'])
            || !isset($snapshot['projectNameSnapshotByLineId'])
            || !is_array($snapshot['projectNameSnapshotByLineId'])) {
            throw $this->failure('inventory_provider_snapshot_mismatch');
        }
        $this->assertShortageCursorResourceCoverage($snapshot);
    }

    private function assertShortageCursorResourceCoverage(array $snapshot): void
    {
        $expected = [];
        foreach ((array)($snapshot['lineInventoryByLineId'] ?? []) as $lineId => $inventory) {
            foreach ((array)($inventory['consumables'] ?? []) as $consumable) {
                $stockId = (string)($consumable['stockId'] ?? '');
                $resourceId = (string)($consumable['shortageCursorId'] ?? '');
                $version = (int)($consumable['shortageCursorVersion'] ?? 0);
                if ($lineId === '' || $stockId === '' || $resourceId === '' || $version <= 0) {
                    throw $this->failure('inventory_shortage_cursor_snapshot_invalid');
                }
                $role = 'inventory_shortage_cursor:' . $lineId . ':' . $stockId;
                if (!isset($expected[$resourceId])) {
                    $expected[$resourceId] = ['version' => $version, 'roles' => []];
                } elseif ($expected[$resourceId]['version'] !== $version) {
                    throw $this->failure('inventory_shortage_cursor_snapshot_version_inconsistent');
                }
                $expected[$resourceId]['roles'][] = $role;
            }
        }
        foreach ($expected as &$row) {
            sort($row['roles'], SORT_STRING);
        }
        unset($row);
        ksort($expected, SORT_STRING);

        $actual = [];
        foreach ((array)($snapshot['lockedInventoryResources'] ?? []) as $resource) {
            if (!is_array($resource) || ($resource['kind'] ?? '') !== 'inventory_shortage_cursor') {
                continue;
            }
            $resourceId = (string)($resource['id'] ?? '');
            $roles = (array)($resource['roles'] ?? []);
            sort($roles, SORT_STRING);
            if (isset($actual[$resourceId])) {
                throw $this->failure('inventory_shortage_cursor_resource_duplicate');
            }
            $actual[$resourceId] = [
                'version' => (int)($resource['lockedVersion'] ?? 0),
                'roles' => $roles,
            ];
        }
        ksort($actual, SORT_STRING);
        if ($actual !== $expected) {
            throw $this->failure('inventory_shortage_cursor_resource_coverage_mismatch');
        }
    }

    private function providerCursorSnapshot(array $snapshot, string $lineId, string $stockId): array
    {
        $inventory = $snapshot['lineInventoryByLineId'][$lineId] ?? null;
        if (!is_array($inventory)) {
            throw $this->failure('inventory_provider_line_snapshot_missing', ['lineId' => $lineId]);
        }
        foreach ((array)($inventory['consumables'] ?? []) as $consumable) {
            if ((string)($consumable['stockId'] ?? '') === $stockId) {
                return [
                    'shortageCursorId' => (string)($consumable['shortageCursorId'] ?? ''),
                    'shortageCursorVersion' => (int)($consumable['shortageCursorVersion'] ?? 0),
                    'shortageCostAllocatedQuantityUnitsBefore' => (int)(
                        $consumable['shortageCostAllocatedQuantityUnitsBefore'] ?? -1
                    ),
                ];
            }
        }
        throw $this->failure('inventory_provider_consumable_snapshot_missing', [
            'lineId' => $lineId,
            'stockId' => $stockId,
        ]);
    }

    private function inventoryPlanFingerprintInput(array $plan): array
    {
        $lines = [];
        foreach ((array)($plan['linePlans'] ?? []) as $line) {
            $lines[] = [
                'lineId' => $line['lineId'] ?? '',
                'source' => $line['source'] ?? [],
                'inventory' => $line['inventory'] ?? [],
            ];
        }
        return ['contractVersion' => $plan['contractVersion'] ?? '', 'linePlans' => $lines];
    }

    private function lockReceipt(string $tenantId, string $receiptKey): array
    {
        $row = Db::name('inventory_consumption_receipt')
            ->where('tenant_id', $tenantId)
            ->where('receipt_key', $receiptKey)
            ->lock(true)
            ->find();
        return $row ?: [];
    }

    private function lockReceiptForCommand(string $tenantId, string $receiptKey, string $idempotencyKey): array
    {
        $byReceipt = $this->lockReceipt($tenantId, $receiptKey);
        if ($byReceipt) {
            return $byReceipt;
        }
        $byIdempotency = Db::name('inventory_consumption_receipt')
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $idempotencyKey)
            ->lock(true)
            ->find();
        return $byIdempotency ?: [];
    }

    private function replayReceipt(
        array $receipt,
        string $receiptKey,
        string $idempotencyKey,
        string $fingerprint
    ): array
    {
        if ((string)$receipt['receipt_key'] !== $receiptKey
            || (string)$receipt['idempotency_key'] !== $idempotencyKey
            || (string)$receipt['request_fingerprint'] !== $fingerprint) {
            throw $this->failure('inventory_idempotency_conflict');
        }
        $result = json_decode((string)$receipt['result_snapshot'], true);
        if (!is_array($result)) {
            throw $this->failure('inventory_receipt_snapshot_invalid');
        }
        $result['replayed'] = true;
        return $result;
    }

    private function scaledCostDelta(int $before, int $quantity, int $unitCostCents, int $scale): int
    {
        if ($before < 0 || $quantity < 0 || $unitCostCents < 0 || $scale < 0 || $scale > 4) {
            throw $this->failure('inventory_cost_arguments_invalid');
        }
        $factor = 1;
        for ($i = 0; $i < $scale; $i++) {
            $factor *= 10;
        }
        $cost = static function (int $units) use ($unitCostCents, $factor): int {
            $numerator = $units * $unitCostCents;
            return intdiv($numerator, $factor) + ((($numerator % $factor) * 2 >= $factor) ? 1 : 0);
        };
        return $cost($before + $quantity) - $cost($before);
    }

    private function sortResourceMap(array &$resources): void
    {
        uksort($resources, static function ($left, $right): int {
            return InventoryEntitlementCompletionContract::compareResourceIds(
                (string)$left,
                (string)$right
            );
        });
    }

    private function assertTransaction(): void
    {
        $pdo = Db::connect()->getPdo();
        if (!$pdo || !$pdo->inTransaction()) {
            throw $this->failure('inventory_provider_transaction_required');
        }
    }

    private function assertExactKeys(array $value, array $expected): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw $this->failure('inventory_command_shape_invalid');
        }
    }

    private function token(string $value, string $field, int $max): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $max || preg_match('/^[A-Za-z0-9:._-]+$/D', $value) !== 1) {
            throw $this->failure('inventory_token_invalid', ['field' => $field]);
        }
        return $value;
    }

    private function text(string $value, int $max): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $max) {
            throw $this->failure('inventory_snapshot_text_invalid');
        }
        return $value;
    }

    private function validDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    private function encode(array $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw $this->failure('inventory_snapshot_encode_failed');
        }
        return $encoded;
    }

    private function failure(string $reason, array $detail = []): InventoryCompletionContractException
    {
        return new InventoryCompletionContractException($reason, $detail);
    }
}
