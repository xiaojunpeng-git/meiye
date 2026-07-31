<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\product\inventory\completion\InventoryEntitlementCompletionContract;
use think\facade\Db;

/**
 * Request documents reserve no quantity. They are a traceable demand signal
 * for a later batch transfer, which owns the inventory-changing transaction.
 */
final class InventoryStockRequestServices
{
    public function apply(int $storeId, int $operatorId, array $input): array
    {
        $command = $this->normalize($input);
        if ($storeId <= 0 || $operatorId <= 0) throw new \InvalidArgumentException('inventory_stock_request_scope_invalid');
        return Db::transaction(function () use ($storeId, $operatorId, $command): array {
            $scope = $this->lockScope($storeId, $operatorId);
            $location = $this->lockDefaultLocation($scope);
            $existing = Db::name('inventory_stock_request_document')->where('tenant_id', $scope['tenantId'])->where('idempotency_key', $command['idempotencyKey'])->lock(true)->find();
            if ($existing) return $this->replay($existing, $scope, $location, $command);

            $now = $command['recordedAt'];
            $documentId = Db::name('inventory_stock_request_document')->insertGetId([
                'request_no' => 'ISR-' . $scope['storeId'] . '-' . $command['idempotencyKey'],
                'idempotency_key' => $command['idempotencyKey'], 'request_fingerprint' => $command['fingerprint'],
                'tenant_id' => $scope['tenantId'], 'organization_id' => $scope['organizationId'], 'organization_path' => $scope['organizationPath'],
                'location_id' => (int)$location['id'], 'store_id' => $scope['storeId'], 'operator_id' => $scope['operatorId'],
                'document_status' => 'APPLIED', 'remark' => $command['remark'], 'business_date' => $command['businessDate'],
                'applied_at' => $now, 'recorded_at' => $now,
            ]);
            foreach ($command['lines'] as $line) {
                $catalog = $this->lockCatalogSku($scope['storeId'], $line);
                $reference = $this->lockReferenceCost($location, $catalog);
                Db::name('inventory_stock_request_line')->insert([
                    'document_id' => $documentId, 'line_no' => $line['index'], 'product_id' => (int)$catalog['product_id'], 'sku_id' => (int)$catalog['sku_id'], 'sku_unique' => $catalog['sku_unique'],
                    'product_name_snapshot' => mb_substr((string)$catalog['product_name'], 0, 120), 'sku_name_snapshot' => mb_substr((string)$catalog['sku_name'], 0, 120),
                    'product_code_snapshot' => mb_substr((string)$catalog['product_code'], 0, 64), 'barcode_snapshot' => mb_substr((string)$catalog['barcode'], 0, 64),
                    'stock_unit_snapshot' => mb_substr((string)$catalog['stock_unit'], 0, 32), 'quantity_scale' => (int)$catalog['quantity_scale'],
                    'requested_quantity_units' => InventoryEntitlementCompletionContract::decimalToUnits($line['quantity'], (int)$catalog['quantity_scale']),
                    'reference_unit_cost_cents' => $reference['cents'], 'reference_cost_status' => $reference['status'], 'created_at' => $now,
                ]);
            }
            return ['request_id' => $documentId, 'request_no' => 'ISR-' . $scope['storeId'] . '-' . $command['idempotencyKey'], 'document_status' => 'APPLIED', 'idempotent' => false];
        });
    }

    public function cancel(int $storeId, int $operatorId, int $requestId): array
    {
        if ($storeId <= 0 || $operatorId <= 0 || $requestId <= 0) throw new \InvalidArgumentException('inventory_stock_request_cancel_input_invalid');
        return Db::transaction(function () use ($storeId, $operatorId, $requestId): array {
            $scope = $this->lockScope($storeId, $operatorId);
            $document = Db::name('inventory_stock_request_document')->where('id', $requestId)->lock(true)->find();
            if (!$document || (string)$document['tenant_id'] !== $scope['tenantId'] || (string)$document['organization_id'] !== $scope['organizationId'] || (string)$document['organization_path'] !== $scope['organizationPath'] || (int)$document['store_id'] !== $scope['storeId']) throw new \RuntimeException('inventory_stock_request_not_found');
            if ((string)$document['document_status'] === 'CANCELLED') return ['request_id' => $requestId, 'document_status' => 'CANCELLED', 'idempotent' => true];
            if ((string)$document['document_status'] !== 'APPLIED') throw new \RuntimeException('inventory_stock_request_cancel_state_invalid');
            if (Db::name('inventory_stock_request_document')->where('id', $requestId)->where('document_status', 'APPLIED')->update(['document_status' => 'CANCELLED', 'cancelled_at' => time(), 'cancelled_by_operator_id' => $scope['operatorId']]) !== 1) throw new \RuntimeException('inventory_stock_request_changed');
            return ['request_id' => $requestId, 'document_status' => 'CANCELLED', 'idempotent' => false];
        });
    }

    private function normalize(array $input): array
    {
        if (array_keys($input) !== ['idempotency_key', 'business_date', 'remark', 'lines'] || !is_array($input['lines']) || !$input['lines'] || count($input['lines']) > 100) throw new \InvalidArgumentException('inventory_stock_request_input_invalid');
        $key = trim((string)$input['idempotency_key']);
        if (preg_match('/^[A-Za-z0-9:._-]{8,96}$/D', $key) !== 1) throw new \InvalidArgumentException('inventory_stock_request_idempotency_invalid');
        $lines = [];
        foreach (array_values($input['lines']) as $index => $line) {
            if (!is_array($line) || array_keys($line) !== ['product_id', 'sku_id', 'sku_unique', 'quantity'] || !is_int($line['product_id']) || !is_int($line['sku_id']) || $line['product_id'] <= 0 || $line['sku_id'] <= 0) throw new \InvalidArgumentException('inventory_stock_request_line_invalid');
            $unique = trim((string)$line['sku_unique']); $quantity = trim((string)$line['quantity']);
            if ($unique === '' || strlen($unique) > 64 || preg_match('/^\d+(?:\.\d{1,4})?$/D', $quantity) !== 1 || (float)$quantity <= 0) throw new \InvalidArgumentException('inventory_stock_request_line_invalid');
            $lines[] = ['index' => $index, 'productId' => $line['product_id'], 'skuId' => $line['sku_id'], 'skuUnique' => $unique, 'quantity' => $quantity];
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim((string)$input['business_date'])); $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d') !== trim((string)$input['business_date'])) throw new \InvalidArgumentException('inventory_stock_request_date_invalid');
        $fingerprint = hash('sha256', json_encode([$date->format('Y-m-d'), trim((string)$input['remark']), $lines], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return ['idempotencyKey' => $key, 'businessDate' => $date->format('Y-m-d'), 'remark' => mb_substr(trim((string)$input['remark']), 0, 500), 'lines' => $lines, 'fingerprint' => $fingerprint, 'recordedAt' => time()];
    }

    private function lockScope(int $storeId, int $operatorId): array
    {
        $store = Db::name('system_store')->where('id', $storeId)->where('is_del', 0)->where('is_show', 1)->field('id,name')->lock(true)->find();
        $operator = Db::name('system_store_staff')->where('id', $operatorId)->where('store_id', $storeId)->where('status', 1)->where('is_del', 0)->field('id')->lock(true)->find();
        $binding = Db::name('organization_store')->where('store_id', $storeId)->field('org_id')->lock(true)->find();
        if (!$store || !$operator || !$binding || (int)$binding['org_id'] <= 0) throw new \RuntimeException('inventory_stock_request_scope_denied');
        $path = []; $seen = []; $current = (int)$binding['org_id']; $name = '';
        for ($depth = 0; $depth < 64; $depth++) { if (isset($seen[$current])) throw new \RuntimeException('inventory_stock_request_organization_invalid'); $seen[$current] = true; $node = Db::name('organization')->where('id', $current)->where('is_del', 0)->field('id,pid,name')->lock(true)->find(); if (!$node || trim((string)$node['name']) === '') throw new \RuntimeException('inventory_stock_request_organization_invalid'); if ($name === '') $name = (string)$node['name']; $path[] = (int)$node['id']; if ((int)$node['pid'] === 0) break; $current = (int)$node['pid']; }
        if (!$path || end($path) !== $current) throw new \RuntimeException('inventory_stock_request_organization_invalid');
        return ['tenantId' => CashierV3ScopeResolver::TENANT_SCOPE_ID, 'organizationId' => (string)$binding['org_id'], 'organizationPath' => '/' . implode('/', array_reverse($path)) . '/', 'storeId' => $storeId, 'operatorId' => $operatorId];
    }

    private function lockDefaultLocation(array $scope): array
    {
        $rows = Db::name('inventory_location')->where('tenant_id', $scope['tenantId'])->where('store_id', $scope['storeId'])->where('location_type', 'STORE')->where('is_default', 1)->where('location_status', 'ACTIVE')->limit(2)->lock(true)->select()->toArray();
        if (count($rows) !== 1 || (string)$rows[0]['organization_id'] !== $scope['organizationId'] || (string)$rows[0]['organization_path'] !== $scope['organizationPath'] || (int)$rows[0]['owner_id'] !== $scope['storeId']) throw new \RuntimeException('inventory_stock_request_location_invalid');
        return (array)$rows[0];
    }

    private function lockCatalogSku(int $storeId, array $line): array
    {
        $catalog = Db::name('store_product_attr_value')->alias('a')->join('store_product p', 'p.id=a.product_id')->where('p.id', $line['productId'])->where('p.type', 1)->where('p.relation_id', $storeId)->where('p.is_del', 0)->where('p.is_inventory', 1)->where('a.id', $line['skuId'])->where('a.unique', $line['skuUnique'])->where('a.type', 0)->field('p.id product_id,p.store_name product_name,p.code product_code,p.salon_stock_enabled,a.id sku_id,a.unique sku_unique,a.suk sku_name,a.bar_code barcode,a.stock_unit')->lock(true)->find();
        if (!$catalog) throw new \RuntimeException('inventory_stock_request_sku_not_found');
        $catalog['quantity_scale'] = (int)$catalog['salon_stock_enabled'] === 1 ? 2 : 0;
        return (array)$catalog;
    }

    private function lockReferenceCost(array $location, array $catalog): array
    {
        $stock = Db::name('inventory_stock')->where('tenant_id', (string)$location['tenant_id'])->where('location_id', (int)$location['id'])->where('consumable_product_id', (int)$catalog['product_id'])->where('sku_id', (int)$catalog['sku_id'])->where('stock_status', InventoryEntitlementCompletionContract::STOCK_STATUS_GOOD)->lock(true)->find();
        if (!$stock || (int)$stock['estimated_unit_cost_cents'] <= 0) return ['cents' => 0, 'status' => 'UNKNOWN'];
        return ['cents' => (int)$stock['estimated_unit_cost_cents'], 'status' => 'SNAPSHOT'];
    }

    private function replay(array $document, array $scope, array $location, array $command): array
    {
        if ((string)$document['request_fingerprint'] !== $command['fingerprint'] || (string)$document['organization_id'] !== $scope['organizationId'] || (string)$document['organization_path'] !== $scope['organizationPath'] || (int)$document['location_id'] !== (int)$location['id'] || (int)$document['store_id'] !== $scope['storeId']) throw new \RuntimeException('inventory_stock_request_idempotency_conflict');
        return ['request_id' => (int)$document['id'], 'request_no' => (string)$document['request_no'], 'document_status' => (string)$document['document_status'], 'idempotent' => true];
    }
}
