<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\product\inventory\completion\InventoryBatchMovementFactServices;
use think\facade\Db;

/** Reverses a settled manual inbound/outbound without overwriting its facts. */
final class InventoryManualDocumentReversalServices
{
    public const INBOUND = 'manual_inbound';
    public const OUTBOUND = 'manual_outbound';

    public function reverseForStore(int $storeId, int $operatorId, string $sourceType, array $input): array
    {
        $command = $this->normalize($sourceType, $input);
        if ($storeId <= 0 || $operatorId <= 0) {
            throw new \InvalidArgumentException('inventory_manual_reversal_scope_invalid');
        }

        return Db::transaction(function () use ($storeId, $operatorId, $command): array {
            $staff = Db::name('system_store_staff')->where('id', $operatorId)->where('store_id', $storeId)
                ->where('status', 1)->where('is_del', 0)->lock(true)->find();
            $locations = Db::name('inventory_location')->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->where('store_id', $storeId)->where('location_type', 'STORE')->where('is_default', 1)
                ->where('location_status', 'ACTIVE')->order('id asc')->limit(2)->lock(true)->select()->toArray();
            if (!$staff || count($locations) !== 1) {
                throw new \RuntimeException('inventory_manual_reversal_scope_denied');
            }
            $location = (array)$locations[0];
            if ((int)($location['owner_id'] ?? 0) !== $storeId || (int)($location['store_id'] ?? 0) !== $storeId) {
                throw new \RuntimeException('inventory_manual_reversal_scope_denied');
            }
            $scope = [
                'tenantId' => (string)$location['tenant_id'],
                'organizationId' => (string)$location['organization_id'],
                'organizationPath' => (string)$location['organization_path'],
                'storeId' => $storeId,
                'operatorId' => $operatorId,
                'operatorType' => 'STORE_STAFF',
            ];
            return $this->reverseAtLocation($scope, $location, $command);
        });
    }

    /** The caller must resolve the administrator grant and HQ location server-side. */
    public function reverseForHeadquarters(array $scope, array $location, string $sourceType, array $input): array
    {
        $command = $this->normalize($sourceType, $input);
        return Db::transaction(function () use ($scope, $location, $command): array {
            $locked = Db::name('inventory_location')->where('id', (int)($location['id'] ?? 0))->lock(true)->find();
            if (!$locked || (string)($locked['location_type'] ?? '') !== 'HQ'
                || (int)($locked['store_id'] ?? -1) !== 0
                || (int)($locked['owner_id'] ?? 0) <= 0
                || (string)($locked['location_status'] ?? '') !== 'ACTIVE'
                || (string)($locked['tenant_id'] ?? '') !== (string)($scope['tenantId'] ?? '')
                || (string)($locked['organization_id'] ?? '') !== (string)($scope['organizationId'] ?? '')
                || (string)($locked['organization_path'] ?? '') !== (string)($scope['organizationPath'] ?? '')
                || (int)($scope['operatorId'] ?? 0) <= 0) {
                throw new \RuntimeException('inventory_manual_reversal_scope_denied');
            }
            $scope['storeId'] = 0;
            $scope['operatorType'] = 'PLATFORM_ADMIN';
            return $this->reverseAtLocation($scope, (array)$locked, $command);
        });
    }

    private function reverseAtLocation(array $scope, array $location, array $command): array
    {
        $fingerprint = hash('sha256', json_encode([
            'source_type' => $command['sourceType'],
            'source_id' => $command['sourceId'],
            'reason' => $command['reason'],
            'location_id' => (int)$location['id'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $receipt = Db::name('inventory_manual_document_reversal')
            ->where('tenant_id', (string)$scope['tenantId'])
            ->where('idempotency_key', $command['idempotencyKey'])->lock(true)->find();
        if ($receipt) {
            return $this->replay((array)$receipt, $scope, $location, $command, $fingerprint);
        }
        $prior = Db::name('inventory_manual_document_reversal')
            ->where('tenant_id', (string)$scope['tenantId'])->where('source_type', $command['sourceType'])
            ->where('source_id', $command['sourceId'])->lock(true)->find();
        if ($prior) {
            throw new \RuntimeException('inventory_manual_reversal_already_voided');
        }

        $originals = Db::name('inventory_batch_movement_fact')
            ->where('tenant_id', (string)$scope['tenantId'])->where('location_id', (int)$location['id'])
            ->where('store_id', (int)$scope['storeId'])->where('source_type', $command['sourceType'])
            ->where('source_id', $command['sourceId'])->where('fact_status', 'SETTLED')->where('reversal_of', 0)
            ->order('stock_id asc,batch_id asc,id asc')->lock(true)->select()->toArray();
        if (!$originals) {
            throw new \RuntimeException('inventory_manual_reversal_document_missing');
        }
        $originalIds = array_map('intval', array_column($originals, 'id'));
        if (Db::name('inventory_batch_movement_fact')->where('tenant_id', (string)$scope['tenantId'])
            ->whereIn('reversal_of', $originalIds)->where('fact_status', 'SETTLED')->lock(true)->find()) {
            throw new \RuntimeException('inventory_manual_reversal_already_voided');
        }

        $receiptId = (int)Db::name('inventory_manual_document_reversal')->insertGetId([
            'tenant_id' => (string)$scope['tenantId'], 'source_type' => $command['sourceType'],
            'source_id' => $command['sourceId'], 'location_id' => (int)$location['id'],
            'store_id' => (int)$scope['storeId'], 'idempotency_key' => $command['idempotencyKey'],
            'request_fingerprint' => $fingerprint, 'reason' => $command['reason'],
            'operator_type' => (string)$scope['operatorType'], 'operator_id' => (int)$scope['operatorId'],
            'reversal_status' => 'PROCESSING', 'result_snapshot' => '{}',
            'business_date' => $command['businessDate'], 'occurred_at' => $command['recordedAt'],
            'settled_at' => 0, 'recorded_at' => $command['recordedAt'],
        ]);

        $stocks = [];
        $batches = [];
        $stockDeltas = [];
        $stockCostDeltas = [];
        $batchDeltas = [];
        foreach ($originals as $fact) {
            $stockDeltas[(int)$fact['stock_id']] = ($stockDeltas[(int)$fact['stock_id']] ?? 0)
                + ((int)$fact['direction'] * (int)$fact['quantity_units']);
            $stockCostDeltas[(int)$fact['stock_id']] = ($stockCostDeltas[(int)$fact['stock_id']] ?? 0)
                + ((int)$fact['direction'] * (int)$fact['cost_amount_cents']);
            $batchDeltas[(int)$fact['batch_id']] = ($batchDeltas[(int)$fact['batch_id']] ?? 0)
                + ((int)$fact['direction'] * (int)$fact['quantity_units']);
        }
        foreach (array_keys($stockDeltas) as $stockId) {
            $stock = Db::name('inventory_stock')->where('id', $stockId)->lock(true)->find();
            if (!$stock || (string)$stock['tenant_id'] !== (string)$scope['tenantId']
                || (int)$stock['location_id'] !== (int)$location['id'] || (int)$stock['store_id'] !== (int)$scope['storeId']) {
                throw new \RuntimeException('inventory_manual_reversal_scope_denied');
            }
            $stocks[$stockId] = (array)$stock;
        }
        foreach (array_keys($batchDeltas) as $batchId) {
            $batch = Db::name('inventory_batch')->where('id', $batchId)->lock(true)->find();
            if (!$batch || !isset($stocks[(int)$batch['stock_id']]) || (string)$batch['batch_status'] !== 'ACTIVE') {
                throw new \RuntimeException('inventory_manual_reversal_batch_invalid');
            }
            $batches[$batchId] = (array)$batch;
        }

        $this->mutateBalances($stocks, $batches, $stockDeltas, $stockCostDeltas, $batchDeltas, $command['recordedAt']);

        $movementIds = [];
        foreach ($originals as $original) {
            $movementIds[] = (new InventoryBatchMovementFactServices())->append([
                'factKey' => 'manual-reversal:' . hash('sha256', $command['idempotencyKey'] . ':' . (int)$original['id']),
                'tenantId' => (string)$original['tenant_id'], 'organizationId' => (string)$original['organization_id'],
                'organizationPath' => (string)$original['organization_path'], 'storeId' => (int)$original['store_id'],
                'stockId' => (int)$original['stock_id'], 'batchId' => (int)$original['batch_id'],
                'direction' => -(int)$original['direction'], 'quantityUnits' => (int)$original['quantity_units'],
                'unitCostCents' => (int)$original['unit_cost_cents'], 'costAmountCents' => (int)$original['cost_amount_cents'],
                'sourceType' => $command['sourceType'] . '_reversal', 'sourceId' => (string)$receiptId,
                'sourceDetailId' => (string)$original['id'], 'reversalOf' => (int)$original['id'],
                'businessDate' => $command['businessDate'], 'occurredAt' => $command['recordedAt'],
                'settledAt' => $command['recordedAt'], 'recordedAt' => $command['recordedAt'],
            ]);
        }

        $documentNo = (new InventoryBusinessDocumentNumberServices())->manualExisting(
            (string)$scope['tenantId'], $command['sourceType'], $command['sourceId']
        );
        $result = [
            'reversal_id' => $receiptId, 'source_type' => $command['sourceType'],
            'source_id' => $command['sourceId'], 'document_no' => $documentNo ?: $command['sourceId'],
            'status' => 'VOIDED', 'reason' => $command['reason'], 'movement_fact_ids' => $movementIds,
            'idempotent' => false,
        ];
        Db::name('inventory_manual_document_reversal')->where('id', $receiptId)->where('reversal_status', 'PROCESSING')->update([
            'reversal_status' => 'SETTLED', 'result_snapshot' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'settled_at' => $command['recordedAt'],
        ]);
        return $result;
    }

    private function mutateBalances(array $stocks, array $batches, array $stockDeltas, array $stockCostDeltas, array $batchDeltas, int $now): void
    {
        foreach ($batches as $batchId => $batch) {
            $ledger = Db::name('inventory_batch_movement_fact')->where('batch_id', $batchId)
                ->where('fact_status', 'SETTLED')
                ->fieldRaw('COALESCE(SUM(CASE WHEN direction=1 THEN quantity_units ELSE -quantity_units END),0) AS quantity_units')
                ->find();
            if ((int)($ledger['quantity_units'] ?? 0) !== (int)$batch['available_quantity_units']) {
                throw new \RuntimeException('inventory_manual_reversal_batch_ledger_mismatch');
            }
            $new = (int)$batch['available_quantity_units'] - (int)$batchDeltas[$batchId];
            if ($new < 0) {
                throw new \RuntimeException('inventory_manual_reversal_inbound_batch_insufficient');
            }
            if (Db::name('inventory_batch')->where('id', $batchId)->where('version', (int)$batch['version'])
                ->where('available_quantity_units', (int)$batch['available_quantity_units'])->update([
                    'available_quantity_units' => $new, 'version' => (int)$batch['version'] + 1, 'updated_at' => $now,
                ]) !== 1) {
                throw new \RuntimeException('inventory_manual_reversal_batch_changed');
            }
        }
        foreach ($stocks as $stockId => $stock) {
            $new = (int)$stock['available_quantity_units'] - (int)$stockDeltas[$stockId];
            if ($new < 0) {
                throw new \RuntimeException('inventory_manual_reversal_inbound_stock_insufficient');
            }
            $scale = max(0, min(4, (int)$stock['quantity_scale']));
            $net = Db::name('inventory_batch_movement_fact')->where('tenant_id', (string)$stock['tenant_id'])
                ->where('stock_id', $stockId)->where('fact_status', 'SETTLED')
                ->fieldRaw('SUM(CASE WHEN direction=1 THEN quantity_units ELSE -quantity_units END) AS quantity_units, SUM(CASE WHEN direction=1 THEN cost_amount_cents ELSE -cost_amount_cents END) AS cost_amount_cents')
                ->find();
            if ((int)($net['quantity_units'] ?? 0) !== (int)$stock['available_quantity_units']) {
                throw new \RuntimeException('inventory_manual_reversal_stock_ledger_mismatch');
            }
            $newCost = (int)($net['cost_amount_cents'] ?? 0) - (int)$stockCostDeltas[$stockId];
            if ($newCost < 0) {
                throw new \RuntimeException('inventory_manual_reversal_stock_ledger_mismatch');
            }
            $newEstimated = $new === 0 ? 0 : intdiv($newCost * (10 ** $scale), $new);
            if (Db::name('inventory_stock')->where('id', $stockId)->where('version', (int)$stock['version'])
                ->where('available_quantity_units', (int)$stock['available_quantity_units'])->update([
                    'available_quantity_units' => $new, 'estimated_unit_cost_cents' => $newEstimated,
                    'version' => (int)$stock['version'] + 1, 'updated_at' => $now,
                ]) !== 1) {
                throw new \RuntimeException('inventory_manual_reversal_stock_changed');
            }
        }
    }

    private function normalize(string $sourceType, array $input): array
    {
        if (!in_array($sourceType, [self::INBOUND, self::OUTBOUND], true)
            || array_keys($input) !== ['source_id', 'idempotency_key', 'reason']) {
            throw new \InvalidArgumentException('inventory_manual_reversal_input_invalid');
        }
        $sourceId = trim((string)$input['source_id']);
        $key = trim((string)$input['idempotency_key']);
        $reason = trim((string)$input['reason']);
        if ($sourceId === '' || strlen($sourceId) > 96
            || preg_match('/^[A-Za-z0-9:._-]{8,96}$/D', $key) !== 1
            || mb_strlen($reason) < 2 || mb_strlen($reason) > 500
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason) === 1) {
            throw new \InvalidArgumentException('inventory_manual_reversal_input_invalid');
        }
        $now = time();
        return [
            'sourceType' => $sourceType, 'sourceId' => $sourceId, 'idempotencyKey' => $key,
            'reason' => $reason, 'businessDate' => date('Y-m-d', $now), 'recordedAt' => $now,
        ];
    }

    private function replay(array $receipt, array $scope, array $location, array $command, string $fingerprint): array
    {
        if ((string)$receipt['request_fingerprint'] !== $fingerprint
            || (string)$receipt['source_type'] !== $command['sourceType'] || (string)$receipt['source_id'] !== $command['sourceId']
            || (int)$receipt['location_id'] !== (int)$location['id'] || (int)$receipt['store_id'] !== (int)$scope['storeId']
            || (string)$receipt['operator_type'] !== (string)$scope['operatorType'] || (int)$receipt['operator_id'] !== (int)$scope['operatorId']
            || (string)$receipt['reversal_status'] !== 'SETTLED') {
            throw new \RuntimeException('inventory_manual_reversal_idempotency_conflict');
        }
        $result = json_decode((string)$receipt['result_snapshot'], true);
        if (!is_array($result) || (int)($result['reversal_id'] ?? 0) !== (int)$receipt['id']) {
            throw new \RuntimeException('inventory_manual_reversal_receipt_invalid');
        }
        $result['idempotent'] = true;
        return $result;
    }
}
