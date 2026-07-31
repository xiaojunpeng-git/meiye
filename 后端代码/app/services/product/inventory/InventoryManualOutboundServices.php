<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\product\inventory\completion\InventoryBatchMovementFactServices;
use app\services\product\inventory\completion\InventoryEntitlementCompletionContract;
use think\facade\Db;

/**
 * Manual outbound consumes only real, positive batch balances. Legacy product
 * stock counters are deliberately outside this authority.
 */
final class InventoryManualOutboundServices
{
    public function create(int $storeId, int $operatorId, array $input): array
    {
        $command = $this->normalize($input);
        if ($storeId <= 0 || $operatorId <= 0) {
            throw new \InvalidArgumentException('inventory_manual_outbound_scope_invalid');
        }

        return Db::transaction(function () use ($storeId, $operatorId, $command): array {
            $scope = $this->lockScope($storeId, $operatorId);
            $location = $this->lockDefaultLocation($scope);
            $existing = Db::name('inventory_batch_movement_fact')
                ->where('tenant_id', $scope['tenantId'])
                ->where('source_type', 'manual_outbound')
                ->where('source_id', $command['idempotencyKey'])
                ->order('id asc')
                ->lock(true)
                ->select()
                ->toArray();
            if ($existing) {
                return $this->replay($existing, $scope, $location, $command);
            }

            // A stable SKU order prevents opposite multi-line requests from
            // acquiring aggregate stock locks in different orders.
            $ordered = $command['lines'];
            usort($ordered, static function (array $left, array $right): int {
                return [$left['productId'], $left['skuId'], $left['skuUnique'], $left['index']]
                    <=> [$right['productId'], $right['skuId'], $right['skuUnique'], $right['index']];
            });
            $resultByIndex = [];
            foreach ($ordered as $line) {
                $stock = $this->lockStock($location, $line);
                $units = InventoryEntitlementCompletionContract::decimalToUnits($line['quantity'], (int)$stock['quantity_scale']);
                $batches = $this->lockFefoBatches((int)$stock['id']);
                $allocations = $this->allocate($batches, $units);
                $this->decreaseBalances($stock, $allocations, $units, $command['recordedAt']);
                $facts = [];
                foreach ($allocations as $allocationIndex => $allocation) {
                    $facts[] = $this->appendMovement($scope, $location, $stock, $allocation, $line, $command, $allocationIndex);
                }
                $resultByIndex[$line['index']] = ['movement_fact_ids' => $facts, 'idempotent' => false];
            }
            ksort($resultByIndex, SORT_NUMERIC);
            return ['idempotency_key' => $command['idempotencyKey'], 'lines' => array_values($resultByIndex)];
        });
    }

    private function normalize(array $input): array
    {
        if (array_keys($input) !== ['idempotency_key', 'business_date', 'remark', 'lines']
            || !is_array($input['lines']) || !$input['lines'] || count($input['lines']) > 100) {
            throw new \InvalidArgumentException('inventory_manual_outbound_input_invalid');
        }
        $key = trim((string)$input['idempotency_key']);
        if (preg_match('/^[A-Za-z0-9:._-]{8,96}$/D', $key) !== 1) {
            throw new \InvalidArgumentException('inventory_manual_outbound_idempotency_invalid');
        }
        $lines = [];
        foreach (array_values($input['lines']) as $index => $line) {
            if (!is_array($line) || array_keys($line) !== ['product_id', 'sku_id', 'sku_unique', 'quantity']) {
                throw new \InvalidArgumentException('inventory_manual_outbound_line_invalid');
            }
            foreach (['product_id', 'sku_id'] as $field) {
                if (!is_int($line[$field]) || $line[$field] <= 0) {
                    throw new \InvalidArgumentException('inventory_manual_outbound_identifier_invalid');
                }
            }
            $unique = trim((string)$line['sku_unique']);
            $quantity = trim((string)$line['quantity']);
            if ($unique === '' || strlen($unique) > 64 || !preg_match('/^\d+(?:\.\d{1,4})?$/D', $quantity) || (float)$quantity <= 0) {
                throw new \InvalidArgumentException('inventory_manual_outbound_line_invalid');
            }
            $lines[] = ['index' => $index, 'productId' => $line['product_id'], 'skuId' => $line['sku_id'], 'skuUnique' => $unique, 'quantity' => $quantity];
        }
        return [
            'idempotencyKey' => $key,
            'businessDate' => $this->date((string)$input['business_date']),
            'remark' => mb_substr(trim((string)$input['remark']), 0, 500),
            'lines' => $lines,
            'recordedAt' => time(),
        ];
    }

    private function lockScope(int $storeId, int $operatorId): array
    {
        $store = Db::name('system_store')->where('id', $storeId)->where('is_del', 0)->where('is_show', 1)->field('id,name')->lock(true)->find();
        $operator = Db::name('system_store_staff')->where('id', $operatorId)->where('store_id', $storeId)->where('status', 1)->where('is_del', 0)->field('id')->lock(true)->find();
        $binding = Db::name('organization_store')->where('store_id', $storeId)->field('org_id')->lock(true)->find();
        $organizationId = (int)($binding['org_id'] ?? 0);
        if (!$store || trim((string)($store['name'] ?? '')) === '' || !$operator || $organizationId <= 0) {
            throw new \RuntimeException('inventory_manual_outbound_scope_denied');
        }
        $path = [];
        $seen = [];
        $current = $organizationId;
        $name = '';
        for ($depth = 0; $depth < 64; $depth++) {
            if (isset($seen[$current])) throw new \RuntimeException('inventory_manual_outbound_organization_scope_invalid');
            $seen[$current] = true;
            $node = Db::name('organization')->where('id', $current)->where('is_del', 0)->field('id,pid,name')->lock(true)->find();
            if (!$node || trim((string)($node['name'] ?? '')) === '') throw new \RuntimeException('inventory_manual_outbound_organization_scope_invalid');
            if ($name === '') $name = trim((string)$node['name']);
            $path[] = (int)$node['id'];
            $parent = (int)$node['pid'];
            if ($parent === 0) break;
            if ($parent < 0) throw new \RuntimeException('inventory_manual_outbound_organization_scope_invalid');
            $current = $parent;
        }
        if (!$path || (int)end($path) !== $current) throw new \RuntimeException('inventory_manual_outbound_organization_scope_invalid');
        return [
            'tenantId' => CashierV3ScopeResolver::TENANT_SCOPE_ID,
            'organizationId' => (string)$organizationId,
            'organizationPath' => '/' . implode('/', array_reverse($path)) . '/',
            'storeId' => (int)$store['id'],
            'operatorId' => $operatorId,
            'organizationName' => mb_substr($name, 0, 100),
        ];
    }

    private function lockDefaultLocation(array $scope): array
    {
        $locations = Db::name('inventory_location')->where('tenant_id', $scope['tenantId'])->where('store_id', $scope['storeId'])
            ->where('location_type', 'STORE')->where('is_default', 1)->where('location_status', 'ACTIVE')->order('id asc')->limit(2)->lock(true)->select()->toArray();
        if (count($locations) !== 1) throw new \RuntimeException('inventory_manual_outbound_default_location_invalid');
        $location = (array)$locations[0];
        if ((string)($location['organization_id'] ?? '') !== $scope['organizationId'] || (string)($location['organization_path'] ?? '') !== $scope['organizationPath'] || (int)($location['owner_id'] ?? 0) !== $scope['storeId']) {
            throw new \RuntimeException('inventory_manual_outbound_location_scope_invalid');
        }
        return $location;
    }

    private function lockStock(array $location, array $line): array
    {
        $stock = Db::name('inventory_stock')->where('tenant_id', (string)$location['tenant_id'])->where('location_id', (int)$location['id'])
            ->where('consumable_product_id', $line['productId'])->where('sku_id', $line['skuId'])->where('product_unique', $line['skuUnique'])
            ->where('stock_status', InventoryEntitlementCompletionContract::STOCK_STATUS_GOOD)->lock(true)->find();
        if (!$stock || (int)$stock['available_quantity_units'] <= 0 || (int)$stock['quantity_scale'] < 0 || (int)$stock['quantity_scale'] > 4) {
            throw new \RuntimeException('inventory_manual_outbound_stock_insufficient');
        }
        return $stock;
    }

    private function lockFefoBatches(int $stockId): array
    {
        return Db::name('inventory_batch')->where('stock_id', $stockId)->where('batch_status', 'ACTIVE')->where('available_quantity_units', '>', 0)
            ->orderRaw('expire_date IS NULL ASC, expire_date ASC, received_business_date IS NULL ASC, received_business_date ASC, id ASC')
            ->lock(true)->select()->toArray();
    }

    private function allocate(array $batches, int $required): array
    {
        $remaining = $required;
        $allocations = [];
        foreach ($batches as $batch) {
            $available = (int)$batch['available_quantity_units'];
            $quantity = min($available, $remaining);
            if ($quantity > 0) $allocations[] = ['batch' => (array)$batch, 'units' => $quantity];
            $remaining -= $quantity;
            if ($remaining === 0) break;
        }
        if ($remaining !== 0) throw new \RuntimeException('inventory_manual_outbound_stock_insufficient');
        return $allocations;
    }

    private function decreaseBalances(array $stock, array $allocations, int $units, int $now): void
    {
        if (Db::name('inventory_stock')->where('id', (int)$stock['id'])->where('version', (int)$stock['version'])->update([
            'available_quantity_units' => (int)$stock['available_quantity_units'] - $units,
            'version' => (int)$stock['version'] + 1, 'updated_at' => $now,
        ]) !== 1) throw new \RuntimeException('inventory_manual_outbound_stock_changed');
        foreach ($allocations as $allocation) {
            $batch = $allocation['batch'];
            if (Db::name('inventory_batch')->where('id', (int)$batch['id'])->where('version', (int)$batch['version'])->where('available_quantity_units', '>=', $allocation['units'])->update([
                'available_quantity_units' => (int)$batch['available_quantity_units'] - $allocation['units'],
                'version' => (int)$batch['version'] + 1, 'updated_at' => $now,
            ]) !== 1) throw new \RuntimeException('inventory_manual_outbound_batch_changed');
        }
    }

    private function appendMovement(array $scope, array $location, array $stock, array $allocation, array $line, array $command, int $allocationIndex): int
    {
        $batch = $allocation['batch'];
        $factor = 10 ** (int)$stock['quantity_scale'];
        return (new InventoryBatchMovementFactServices())->append([
            'factKey' => 'manual-out:' . hash('sha256', $command['idempotencyKey'] . ':' . $line['index'] . ':' . $allocationIndex),
            'tenantId' => $scope['tenantId'], 'organizationId' => $scope['organizationId'], 'organizationPath' => $scope['organizationPath'],
            'storeId' => $scope['storeId'], 'stockId' => (int)$stock['id'], 'batchId' => (int)$batch['id'], 'direction' => -1,
            'quantityUnits' => $allocation['units'], 'unitCostCents' => (int)$batch['unit_cost_cents'],
            'costAmountCents' => intdiv($allocation['units'] * (int)$batch['unit_cost_cents'], $factor),
            'sourceType' => 'manual_outbound', 'sourceId' => $command['idempotencyKey'], 'sourceDetailId' => (string)$line['index'],
            'reversalOf' => 0, 'businessDate' => $command['businessDate'], 'occurredAt' => $command['recordedAt'],
            'settledAt' => $command['recordedAt'], 'recordedAt' => $command['recordedAt'],
        ]);
    }

    private function replay(array $facts, array $scope, array $location, array $command): array
    {
        $lineFacts = [];
        foreach ($facts as $fact) {
            $index = ctype_digit((string)$fact['source_detail_id']) ? (int)$fact['source_detail_id'] : -1;
            if ($index < 0 || !isset($command['lines'][$index]) || (string)$fact['tenant_id'] !== $scope['tenantId']
                || (string)$fact['organization_id'] !== $scope['organizationId'] || (string)$fact['organization_path'] !== $scope['organizationPath']
                || (int)$fact['location_id'] !== (int)$location['id'] || (int)$fact['store_id'] !== $scope['storeId']
                || (int)$fact['direction'] !== -1 || (string)$fact['business_date'] !== $command['businessDate'] || (int)$fact['reversal_of'] !== 0) {
                throw new \RuntimeException('inventory_manual_outbound_idempotency_conflict');
            }
            $stock = Db::name('inventory_stock')->where('id', (int)$fact['stock_id'])->lock(true)->find();
            $line = $command['lines'][$index];
            if (!$stock || (int)$stock['location_id'] !== (int)$location['id'] || (int)$stock['consumable_product_id'] !== $line['productId']
                || (int)$stock['sku_id'] !== $line['skuId'] || (string)$stock['product_unique'] !== $line['skuUnique']) {
                throw new \RuntimeException('inventory_manual_outbound_idempotency_conflict');
            }
            $lineFacts[$index]['units'] = ($lineFacts[$index]['units'] ?? 0) + (int)$fact['quantity_units'];
            $lineFacts[$index]['ids'][] = (int)$fact['id'];
            $lineFacts[$index]['scale'] = (int)$stock['quantity_scale'];
        }
        $result = [];
        foreach ($command['lines'] as $line) {
            $replay = $lineFacts[$line['index']] ?? null;
            if (!$replay || $replay['units'] !== InventoryEntitlementCompletionContract::decimalToUnits($line['quantity'], $replay['scale'])) {
                throw new \RuntimeException('inventory_manual_outbound_idempotency_conflict');
            }
            $result[] = ['movement_fact_ids' => $replay['ids'], 'idempotent' => true];
        }
        return ['idempotency_key' => $command['idempotencyKey'], 'lines' => $result];
    }

    private function date(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d') !== trim($value)) {
            throw new \InvalidArgumentException('inventory_manual_outbound_date_invalid');
        }
        return $date->format('Y-m-d');
    }
}
