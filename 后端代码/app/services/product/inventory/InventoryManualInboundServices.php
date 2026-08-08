<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\product\inventory\completion\InventoryBatchMovementFactServices;
use app\services\product\inventory\completion\InventoryEntitlementCompletionContract;
use think\facade\Db;

/**
 * Manual inbound writes the new batch authority only. It deliberately never
 * adjusts legacy good/defective SKU balances.
 */
final class InventoryManualInboundServices
{
    /** Excel imports share one visible business document and may contain more lines than interactive entry. */
    private const IMPORT_LINE_LIMIT = 5000;

    public function create(int $storeId, int $operatorId, array $input): array
    {
        return $this->createWithLineLimit($storeId, $operatorId, $input, 100);
    }

    /**
     * Dedicated entry for the V3 Excel importer. It keeps the same authority,
     * transaction, facts, FEFO contract and idempotency semantics as manual entry.
     */
    public function createForImport(int $storeId, int $operatorId, array $input): array
    {
        return $this->createWithLineLimit($storeId, $operatorId, $input, self::IMPORT_LINE_LIMIT);
    }

    private function createWithLineLimit(int $storeId, int $operatorId, array $input, int $lineLimit): array
    {
        $command = $this->normalize($input, $lineLimit);
        if ($storeId <= 0 || $operatorId <= 0) {
            throw new \InvalidArgumentException('inventory_manual_inbound_scope_invalid');
        }
        return Db::transaction(function () use ($storeId, $operatorId, $command): array {
            $scope = $this->lockScope($storeId, $operatorId);
            $location = $this->lockOrCreateDefaultLocation($scope, $command['recordedAt']);
            return $this->createAtLocation($scope, $location, $command);
        });
    }

    /**
     * Platform headquarters inbound. The caller has already resolved the
     * platform permission and an HQ location from trusted server-side scope.
     * The command deliberately shares the same batch and fact write path as a
     * store inbound so HQ stock remains in the V3 authority, not legacy stock.
     */
    public function createForHeadquarters(array $scope, array $location, array $input, int $lineLimit = self::IMPORT_LINE_LIMIT): array
    {
        $command = $this->normalize($input, $lineLimit);
        return Db::transaction(function () use ($scope, $location, $command): array {
            $locked = Db::name('inventory_location')->where('id', (int)($location['id'] ?? 0))->lock(true)->find();
            if (!$locked || (string)($locked['location_type'] ?? '') !== 'HQ'
                || (int)($locked['store_id'] ?? -1) !== 0
                || (string)($locked['location_status'] ?? '') !== 'ACTIVE'
                || (string)($locked['tenant_id'] ?? '') !== (string)($scope['tenantId'] ?? '')) {
                throw new \RuntimeException('inventory_hq_location_invalid');
            }
            return $this->createAtLocation($scope, (array)$locked, $command);
        });
    }

    private function createAtLocation(array $scope, array $location, array $command): array
    {
        $command['documentNo'] = (new InventoryBusinessDocumentNumberServices())->manual(
            (string)$scope['tenantId'],
            'manual_inbound',
            (string)$command['idempotencyKey'],
            (string)$command['businessDate'],
            (int)$command['recordedAt']
        );
        $results = [];
        foreach ($command['lines'] as $index => $line) {
            $factKey = 'manual-in:' . hash('sha256', $command['idempotencyKey'] . ':' . $index);
            $existing = Db::name('inventory_batch_movement_fact')
                ->where('tenant_id', $location['tenant_id'])
                ->where('fact_key', $factKey)
                ->lock(true)
                ->find();
            if ($existing) {
                $this->assertIdempotentFact($existing, $location, $command, $index, $line);
                $results[] = ['movement_fact_id' => (int)$existing['id'], 'idempotent' => true];
                continue;
            }
            $catalog = $this->lockCatalogSku($scope, $line);
            $stock = $this->lockOrCreateStock($location, $catalog, $line, $command['recordedAt']);
            $batch = $this->lockOrCreateBatch($stock, $catalog, $line, $command, $index);
            $this->increaseBalances($stock, $batch, $line, $command['recordedAt']);
            $movementId = $this->appendMovement($factKey, $location, $stock, $batch, $line, $command, $index);
            $results[] = ['movement_fact_id' => $movementId, 'idempotent' => false];
        }
        return ['document_no' => $command['documentNo'], 'idempotency_key' => $command['idempotencyKey'], 'lines' => $results];
    }

    private function normalize(array $input, int $lineLimit = 100): array
    {
        $keys = ['idempotency_key', 'business_date', 'remark', 'lines'];
        if (array_keys($input) !== $keys || !is_array($input['lines']) || !$input['lines'] || count($input['lines']) > $lineLimit) {
            throw new \InvalidArgumentException('inventory_manual_inbound_input_invalid');
        }
        $idempotencyKey = trim((string)$input['idempotency_key']);
        if (preg_match('/^[A-Za-z0-9:._-]{8,96}$/D', $idempotencyKey) !== 1) {
            throw new \InvalidArgumentException('inventory_manual_inbound_idempotency_invalid');
        }
        $businessDate = $this->date((string)$input['business_date']);
        $lines = [];
        foreach (array_values($input['lines']) as $line) {
            if (!is_array($line) || array_keys($line) !== ['product_id', 'sku_id', 'sku_unique', 'batch_no', 'quantity', 'unit_cost', 'manufactured_date', 'expire_date']) {
                throw new \InvalidArgumentException('inventory_manual_inbound_line_invalid');
            }
            $productId = $this->positive($line['product_id']);
            $skuId = $this->positive($line['sku_id']);
            $skuUnique = trim((string)$line['sku_unique']);
            $batchNo = trim((string)$line['batch_no']);
            if ($skuUnique === '' || strlen($skuUnique) > 64 || $batchNo === '' || strlen($batchNo) > 64) {
                throw new \InvalidArgumentException('inventory_manual_inbound_line_invalid');
            }
            $manufacturedDate = $line['manufactured_date'] === '' ? null : $this->date((string)$line['manufactured_date']);
            $expireDate = $line['expire_date'] === '' ? null : $this->date((string)$line['expire_date']);
            if ($manufacturedDate === null || $expireDate === null) {
                throw new \InvalidArgumentException('inventory_manual_inbound_dates_required');
            }
            if ($manufacturedDate > $expireDate) {
                throw new \InvalidArgumentException('inventory_manual_inbound_date_order_invalid');
            }
            $lines[] = [
                'productId' => $productId,
                'skuId' => $skuId,
                'skuUnique' => $skuUnique,
                'batchNo' => $batchNo,
                'quantity' => trim((string)$line['quantity']),
                'unitCostCents' => $this->amountCents((string)$line['unit_cost']),
                'manufacturedDate' => $manufacturedDate,
                'expireDate' => $expireDate,
            ];
        }
        return [
            'idempotencyKey' => $idempotencyKey,
            'businessDate' => $businessDate,
            'remark' => mb_substr(trim((string)$input['remark']), 0, 500),
            'lines' => $lines,
            'recordedAt' => time(),
        ];
    }

    /**
     * Resolves the write scope solely from the authenticated store session's
     * store and operator identifiers. Callers cannot provide tenant or
     * organization dimensions in the inbound payload.
     */
    private function lockScope(int $storeId, int $operatorId): array
    {
        $store = Db::name('system_store')
            ->where('id', $storeId)
            ->where('is_del', 0)
            ->where('is_show', 1)
            ->field('id,name')
            ->lock(true)
            ->find();
        if (!$store || trim((string)($store['name'] ?? '')) === '') {
            throw new \RuntimeException('inventory_manual_inbound_store_scope_invalid');
        }

        $operator = Db::name('system_store_staff')
            ->where('id', $operatorId)
            ->where('store_id', $storeId)
            ->where('status', 1)
            ->where('is_del', 0)
            ->field('id,store_id')
            ->lock(true)
            ->find();
        if (!$operator) {
            throw new \RuntimeException('inventory_manual_inbound_operator_scope_denied');
        }

        $binding = Db::name('organization_store')
            ->where('store_id', $storeId)
            ->field('org_id')
            ->lock(true)
            ->find();
        $organizationId = (int)($binding['org_id'] ?? 0);
        if ($organizationId <= 0) {
            throw new \RuntimeException('inventory_manual_inbound_organization_scope_invalid');
        }

        $organizationName = '';
        $pathIds = [];
        $seen = [];
        $currentId = $organizationId;
        for ($depth = 0; $depth < 64; $depth++) {
            if (isset($seen[$currentId])) {
                throw new \RuntimeException('inventory_manual_inbound_organization_cycle');
            }
            $seen[$currentId] = true;
            $node = Db::name('organization')
                ->where('id', $currentId)
                ->where('is_del', 0)
                ->field('id,pid,name')
                ->lock(true)
                ->find();
            if (!$node || trim((string)($node['name'] ?? '')) === '') {
                throw new \RuntimeException('inventory_manual_inbound_organization_scope_invalid');
            }
            if ($organizationName === '') {
                $organizationName = trim((string)$node['name']);
            }
            $pathIds[] = (int)$node['id'];
            $parentId = (int)($node['pid'] ?? 0);
            if ($parentId === 0) {
                break;
            }
            if ($parentId < 0) {
                throw new \RuntimeException('inventory_manual_inbound_organization_scope_invalid');
            }
            $currentId = $parentId;
        }
        if (!$pathIds || (int)end($pathIds) !== $currentId) {
            throw new \RuntimeException('inventory_manual_inbound_organization_scope_invalid');
        }

        $organizationPath = '/' . implode('/', array_reverse($pathIds)) . '/';
        if (strlen($organizationPath) > 191) {
            throw new \RuntimeException('inventory_manual_inbound_organization_scope_invalid');
        }
        return [
            'tenantId' => CashierV3ScopeResolver::TENANT_SCOPE_ID,
            'organizationId' => (string)$organizationId,
            'organizationPath' => $organizationPath,
            'organizationName' => mb_substr($organizationName, 0, 100),
            'storeId' => (int)$store['id'],
            'storeName' => mb_substr(trim((string)$store['name']), 0, 100),
            'operatorId' => (int)$operator['id'],
        ];
    }

    private function lockOrCreateDefaultLocation(array $scope, int $now): array
    {
        $rows = Db::name('inventory_location')
            ->where('store_id', (int)$scope['storeId'])
            ->where('location_type', 'STORE')
            ->where('is_default', 1)
            ->where('location_status', 'ACTIVE')
            ->order('id asc')
            ->limit(2)
            ->lock(true)
            ->select()
            ->toArray();
        if (count($rows) > 1) {
            throw new \RuntimeException('inventory_manual_inbound_default_location_ambiguous');
        }
        if (count($rows) === 1) {
            $this->assertDefaultLocationScope((array)$rows[0], $scope, 'inventory_manual_inbound_location_scope_invalid');
            return (array)$rows[0];
        }

        $locationCode = 'STORE-' . (int)$scope['storeId'];
        try {
            $locationId = Db::name('inventory_location')->insertGetId([
                'tenant_id' => $scope['tenantId'],
                'organization_id' => $scope['organizationId'],
                'organization_path' => $scope['organizationPath'],
                'organization_name_snapshot' => $scope['organizationName'],
                'location_type' => 'STORE',
                'owner_id' => (int)$scope['storeId'],
                'location_code' => $locationCode,
                'location_name' => '默认门店仓',
                'store_id' => (int)$scope['storeId'],
                'store_name_snapshot' => $scope['storeName'],
                'is_default' => 1,
                'location_status' => 'ACTIVE',
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $exception) {
            $colliding = Db::name('inventory_location')
                ->where('tenant_id', $scope['tenantId'])
                ->where('location_code', $locationCode)
                ->lock(true)
                ->find();
            if (!$colliding) {
                throw $exception;
            }
            $this->assertDefaultLocationScope((array)$colliding, $scope, 'inventory_manual_inbound_default_location_conflict');
            return (array)$colliding;
        }

        $location = Db::name('inventory_location')->where('id', (int)$locationId)->lock(true)->find();
        if (!$location) {
            throw new \RuntimeException('inventory_manual_inbound_default_location_create_failed');
        }
        $this->assertDefaultLocationScope((array)$location, $scope, 'inventory_manual_inbound_location_scope_invalid');
        return (array)$location;
    }

    private function assertDefaultLocationScope(array $location, array $scope, string $reason): void
    {
        if ((int)($location['id'] ?? 0) <= 0
            || (string)($location['tenant_id'] ?? '') !== (string)$scope['tenantId']
            || (string)($location['organization_id'] ?? '') !== (string)$scope['organizationId']
            || (string)($location['organization_path'] ?? '') !== (string)$scope['organizationPath']
            || (string)($location['location_type'] ?? '') !== 'STORE'
            || (int)($location['owner_id'] ?? 0) !== (int)$scope['storeId']
            || (int)($location['store_id'] ?? 0) !== (int)$scope['storeId']
            || trim((string)($location['location_code'] ?? '')) === ''
            || (int)($location['is_default'] ?? 0) !== 1
            || (string)($location['location_status'] ?? '') !== 'ACTIVE'
            || (int)($location['version'] ?? 0) <= 0) {
            throw new \RuntimeException($reason);
        }
    }

    private function lockCatalogSku(array $scope, array $line): array
    {
        $query = Db::name('store_product_attr_value')->alias('a')->join('store_product p', 'p.id=a.product_id')
            ->where('p.id', $line['productId'])->where('p.is_del', 0)->where('p.is_inventory', 1)->where('a.id', $line['skuId'])
            ->where('a.unique', $line['skuUnique'])->where('a.type', 0)->lock(true)
            ->field('p.id product_id,p.store_name product_name,p.code product_code,p.salon_stock_enabled,a.id sku_id,a.unique sku_unique,a.suk sku_name,a.bar_code barcode,a.stock_unit');
        if (($scope['partyType'] ?? 'STORE') === 'HQ') {
            $query->where('p.type', 0)->where('p.relation_id', 0);
        } else {
            $query->where('p.type', 1)->where('p.relation_id', (int)($scope['storeId'] ?? 0));
        }
        $row = $query->find();
        if (!$row) throw new \RuntimeException('inventory_manual_inbound_sku_not_found');
        $row['quantity_scale'] = (int)($row['salon_stock_enabled'] ?? 0) === 1 ? 2 : 0;
        $row['stock_unit'] = trim((string)($row['stock_unit'] ?? ''));
        return $row;
    }

    private function lockOrCreateStock(array $location, array $catalog, array $line, int $now): array
    {
        $query = Db::name('inventory_stock')->where('tenant_id', $location['tenant_id'])->where('location_id', (int)$location['id'])
            ->where('consumable_product_id', (int)$catalog['product_id'])->where('sku_id', (int)$catalog['sku_id'])
            ->where('stock_status', InventoryEntitlementCompletionContract::STOCK_STATUS_GOOD);
        $stock = $query->lock(true)->find();
        if ($stock) return $stock;
        try {
            $id = Db::name('inventory_stock')->insertGetId([
                'tenant_id' => $location['tenant_id'], 'organization_id' => $location['organization_id'], 'organization_path' => $location['organization_path'],
                'location_id' => (int)$location['id'], 'store_id' => (int)$location['store_id'], 'consumable_product_id' => (int)$catalog['product_id'],
                'sku_id' => (int)$catalog['sku_id'], 'product_unique' => $line['skuUnique'], 'stock_status' => InventoryEntitlementCompletionContract::STOCK_STATUS_GOOD,
                'stock_unit' => $catalog['stock_unit'], 'quantity_scale' => (int)$catalog['quantity_scale'], 'available_quantity_units' => 0,
                'estimated_unit_cost_cents' => $line['unitCostCents'], 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $stock = Db::name('inventory_stock')->where('id', $id)->lock(true)->find();
        } catch (\Throwable $exception) {
            $stock = $query->lock(true)->find();
            if (!$stock) throw $exception;
        }
        return $stock;
    }

    private function lockOrCreateBatch(array $stock, array $catalog, array $line, array $command, int $index): array
    {
        $batch = Db::name('inventory_batch')->where('stock_id', (int)$stock['id'])->where('batch_no', $line['batchNo'])->lock(true)->find();
        if ($batch) {
            if ((string)$batch['batch_status'] !== 'ACTIVE') {
                throw new \RuntimeException('inventory_manual_inbound_batch_not_active');
            }
            if ((int)$batch['unit_cost_cents'] !== $line['unitCostCents']) throw new \RuntimeException('inventory_manual_inbound_batch_cost_conflict');
            return $batch;
        }
        $id = Db::name('inventory_batch')->insertGetId([
            'stock_id' => (int)$stock['id'], 'origin_batch_id' => 0, 'source_batch_id' => 0, 'batch_no' => $line['batchNo'],
            'manufactured_date' => $line['manufacturedDate'], 'expire_date' => $line['expireDate'], 'received_at' => $command['recordedAt'],
            'received_business_date' => $command['businessDate'], 'available_quantity_units' => 0, 'unit_cost_cents' => $line['unitCostCents'],
            'cost_allocated_quantity_units' => 0, 'batch_status' => 'ACTIVE', 'version' => 1, 'product_name_snapshot' => $catalog['product_name'],
            'sku_name_snapshot' => $catalog['sku_name'], 'product_code_snapshot' => $catalog['product_code'], 'barcode_snapshot' => $catalog['barcode'],
            'brand_name_snapshot' => '', 'category_name_snapshot' => '', 'source_order_no_snapshot' => (string)($command['documentNo'] ?: ('MI-' . $command['idempotencyKey'] . '-' . $index)),
            'data_quality' => 'COMPLETE',
            'created_at' => $command['recordedAt'], 'updated_at' => $command['recordedAt'],
        ]);
        Db::name('inventory_batch')->where('id', $id)->update(['origin_batch_id' => $id]);
        return Db::name('inventory_batch')->where('id', $id)->lock(true)->find();
    }

    private function increaseBalances(array $stock, array $batch, array $line, int $now): void
    {
        $units = InventoryEntitlementCompletionContract::decimalToUnits($line['quantity'], (int)$stock['quantity_scale']);
        $stockAvailable = (int)$stock['available_quantity_units'];
        $newAvailable = $stockAvailable + $units;
        $estimated = $newAvailable === 0 ? $line['unitCostCents'] : intdiv($stockAvailable * (int)$stock['estimated_unit_cost_cents'] + $units * $line['unitCostCents'], $newAvailable);
        if (Db::name('inventory_stock')->where('id', (int)$stock['id'])->where('version', (int)$stock['version'])->update([
            'available_quantity_units' => $newAvailable, 'estimated_unit_cost_cents' => $estimated, 'version' => (int)$stock['version'] + 1, 'updated_at' => $now,
        ]) !== 1) throw new \RuntimeException('inventory_manual_inbound_stock_changed');
        if (Db::name('inventory_batch')->where('id', (int)$batch['id'])->where('version', (int)$batch['version'])->update([
            'available_quantity_units' => (int)$batch['available_quantity_units'] + $units, 'version' => (int)$batch['version'] + 1, 'updated_at' => $now,
        ]) !== 1) throw new \RuntimeException('inventory_manual_inbound_batch_changed');
    }

    private function appendMovement(string $factKey, array $location, array $stock, array $batch, array $line, array $command, int $index): int
    {
        $units = InventoryEntitlementCompletionContract::decimalToUnits($line['quantity'], (int)$stock['quantity_scale']);
        $cost = intdiv($units * $line['unitCostCents'], 10 ** (int)$stock['quantity_scale']);
        return (new InventoryBatchMovementFactServices())->append([
            'factKey' => $factKey, 'tenantId' => (string)$location['tenant_id'], 'organizationId' => (string)$location['organization_id'],
            'organizationPath' => (string)$location['organization_path'], 'storeId' => (int)$location['store_id'], 'stockId' => (int)$stock['id'],
            'batchId' => (int)$batch['id'], 'direction' => 1, 'quantityUnits' => $units, 'unitCostCents' => $line['unitCostCents'],
            'costAmountCents' => $cost, 'sourceType' => 'manual_inbound', 'sourceId' => $command['idempotencyKey'],
            'sourceDetailId' => (string)$index, 'reversalOf' => 0, 'businessDate' => $command['businessDate'],
            'occurredAt' => $command['recordedAt'], 'settledAt' => $command['recordedAt'], 'recordedAt' => $command['recordedAt'],
        ]);
    }

    private function assertIdempotentFact(array $fact, array $location, array $command, int $index, array $line): void
    {
        if ((string)$fact['source_type'] !== 'manual_inbound' || (string)$fact['source_id'] !== $command['idempotencyKey']
            || (string)$fact['source_detail_id'] !== (string)$index || (string)$fact['tenant_id'] !== (string)$location['tenant_id']
            || (string)$fact['organization_id'] !== (string)$location['organization_id']
            || (string)$fact['organization_path'] !== (string)$location['organization_path']
            || (int)$fact['location_id'] !== (int)$location['id']
            || (int)$fact['store_id'] !== (int)$location['store_id']) {
            throw new \RuntimeException('inventory_manual_inbound_idempotency_conflict');
        }
        $stock = Db::name('inventory_stock')->where('id', (int)$fact['stock_id'])->lock(true)->find();
        $batch = Db::name('inventory_batch')->where('id', (int)$fact['batch_id'])->lock(true)->find();
        if (!$stock || !$batch
            || (string)$stock['tenant_id'] !== (string)$location['tenant_id']
            || (string)$stock['organization_id'] !== (string)$location['organization_id']
            || (string)$stock['organization_path'] !== (string)$location['organization_path']
            || (int)$stock['location_id'] !== (int)$location['id']
            || (int)$stock['store_id'] !== (int)$location['store_id']
            || (int)$stock['consumable_product_id'] !== (int)$line['productId']
            || (int)$stock['sku_id'] !== (int)$line['skuId']
            || (string)$stock['product_unique'] !== (string)$line['skuUnique']
            || (int)$batch['stock_id'] !== (int)$stock['id']
            || (string)$batch['batch_no'] !== (string)$line['batchNo']
            || (int)$batch['unit_cost_cents'] !== (int)$line['unitCostCents']) {
            throw new \RuntimeException('inventory_manual_inbound_idempotency_conflict');
        }
        $scale = (int)($stock['quantity_scale'] ?? -1);
        if ($scale < 0 || $scale > 4) {
            throw new \RuntimeException('inventory_manual_inbound_idempotency_conflict');
        }
        try {
            $quantityUnits = InventoryEntitlementCompletionContract::decimalToUnits($line['quantity'], $scale);
        } catch (\Throwable $exception) {
            throw new \RuntimeException('inventory_manual_inbound_idempotency_conflict');
        }
        $costAmount = intdiv($quantityUnits * (int)$line['unitCostCents'], 10 ** $scale);
        if ((int)$fact['direction'] !== 1
            || (int)$fact['quantity_units'] !== $quantityUnits
            || (int)$fact['unit_cost_cents'] !== (int)$line['unitCostCents']
            || (int)$fact['cost_amount_cents'] !== $costAmount
            || (string)$fact['fact_status'] !== 'SETTLED'
            || (int)$fact['reversal_of'] !== 0
            || (string)$fact['business_date'] !== (string)$command['businessDate']) {
            throw new \RuntimeException('inventory_manual_inbound_idempotency_conflict');
        }
    }

    private function date(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d') !== trim($value)) {
            throw new \InvalidArgumentException('inventory_manual_inbound_date_invalid');
        }
        return $date->format('Y-m-d');
    }

    private function positive($value): int { if (!is_int($value) || $value <= 0) throw new \InvalidArgumentException('inventory_manual_inbound_identifier_invalid'); return $value; }

    private function amountCents(string $amount): int
    {
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/D', trim($amount))) throw new \InvalidArgumentException('inventory_manual_inbound_cost_invalid');
        [$whole, $fraction] = array_pad(explode('.', trim($amount), 2), 2, '');
        $cents = (int)$whole * 100 + (int)str_pad($fraction, 2, '0');
        if ($cents < 0) throw new \InvalidArgumentException('inventory_manual_inbound_cost_invalid');
        return $cents;
    }
}
