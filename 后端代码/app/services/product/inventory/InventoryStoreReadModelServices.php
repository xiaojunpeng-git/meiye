<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\product\inventory\query\InventoryBatchStockDataScope;
use app\services\product\inventory\query\InventoryBatchStockQueryProvider;
use app\services\product\inventory\query\InventoryStoreBatchScopeResolver;
use think\facade\Db;

/**
 * Store-facing projections sourced exclusively from settled batch facts.
 * The summary deliberately groups by product_id: the current release has one
 * sellable specification per product, while batch detail remains available for traceability.
 */
final class InventoryStoreReadModelServices
{
    public function dashboard(int $storeId, int $operatorId, bool $canViewCost): array
    {
        $cutoffDate = date('Y-m-d');
        $rows = $this->rows($storeId, $operatorId, $canViewCost, $cutoffDate);
        $totalCents = 0;
        foreach ($rows as $row) {
            $totalCents += $this->amountCents($row['inventory_amount'] ?? null);
        }
        $expiryRisk = (new InventoryExpiryRiskProjectionServices())->project($rows, $cutoffDate);

        $locationId = $this->defaultLocationId($storeId);
        $countPending = $locationId > 0 ? (int)Db::name('inventory_stock_count_document')
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)->where('location_id', $locationId)
            ->whereNotIn('document_status', ['CONFIRMED', 'CANCELLED'])->count() : 0;
        $requestPending = $locationId > 0 ? (int)Db::name('inventory_stock_request_document')
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)->where('location_id', $locationId)
            ->whereIn('document_status', ['APPLIED', 'PARTIAL'])->count() : 0;

        return [
            'stock_product_count' => count(array_unique(array_column($rows, 'product_id'))),
            'stock_amount_cents' => $canViewCost ? $totalCents : null,
            'expiring_90_batch_count' => $expiryRisk['expiring_90_batch_count'],
            'expiry_risk_buckets' => $expiryRisk['expiry_risk_buckets'],
            'count_pending_count' => $countPending,
            'request_pending_count' => $requestPending,
            'data_as_of' => time(),
        ];
    }

    public function productSummary(int $storeId, int $operatorId, string $keyword, int $page, int $limit, bool $canViewCost): array
    {
        $groups = [];
        foreach ($this->rows($storeId, $operatorId, $canViewCost) as $row) {
            $productId = (int)$row['product_id'];
            if ($productId <= 0) continue;
            if (!isset($groups[$productId])) {
                $groups[$productId] = [
                    'product_id' => $productId, 'sku_id' => (int)$row['sku_id'],
                    'product_name' => (string)$row['product_name'], 'sku_name' => (string)$row['sku_name'],
                    'product_code' => (string)$row['product_code'], 'barcode' => (string)$row['barcode'],
                    'stock_unit' => (string)$row['stock_unit'], 'quantity_scale' => (int)$row['quantity_scale'],
                    'location_name' => (string)$row['location_name'], 'quantity_units' => 0,
                    'inventory_amount_cents' => $canViewCost ? 0 : null, 'batch_count' => 0,
                ];
            }
            $groups[$productId]['quantity_units'] += $this->decimalUnits((string)$row['batch_balance_quantity'], (int)$groups[$productId]['quantity_scale']);
            $groups[$productId]['batch_count']++;
            if ($canViewCost) $groups[$productId]['inventory_amount_cents'] += $this->amountCents($row['inventory_amount'] ?? null);
        }
        $keyword = mb_strtolower(trim($keyword));
        $items = array_values(array_filter($groups, static function (array $row) use ($keyword): bool {
            return $keyword === '' || str_contains(mb_strtolower(implode(' ', [(string)$row['product_name'], (string)$row['product_code'], (string)$row['barcode']])), $keyword);
        }));
        usort($items, static fn(array $a, array $b): int => [$a['product_name'], $a['product_id']] <=> [$b['product_name'], $b['product_id']]);
        $count = count($items);
        $page = max(1, $page); $limit = max(1, min(100, $limit));
        $items = array_slice($items, ($page - 1) * $limit, $limit);
        foreach ($items as &$item) {
            $item['available_quantity'] = $this->unitsDecimal((int)$item['quantity_units'], (int)$item['quantity_scale']);
            unset($item['quantity_units'], $item['quantity_scale']);
        }
        unset($item);
        return ['list' => $items, 'count' => $count, 'page' => $page, 'limit' => $limit];
    }

    public function productDetail(int $storeId, int $operatorId, int $productId, bool $canViewCost): array
    {
        if ($productId <= 0) throw new \InvalidArgumentException('inventory_product_id_invalid');
        $scope = $this->scope($storeId, $operatorId, $canViewCost);
        $rows = array_values(array_filter($this->rowsForScope($scope), static fn(array $row): bool => (int)$row['product_id'] === $productId));
        if (!$rows) throw new \RuntimeException('inventory_product_stock_not_found');
        $first = $rows[0];
        $reconciliation = $this->reconcileProductBalance($scope, $productId, $rows);
        $batches = array_map(function (array $row) use ($canViewCost): array {
            return [
                'batch_id' => (int)$row['batch_balance_id'], 'batch_no' => (string)$row['batch_no'],
                'location_name' => (string)$row['location_name'], 'available_quantity' => (string)$row['batch_balance_quantity'],
                'stock_unit' => (string)$row['stock_unit'], 'manufactured_date' => $row['manufactured_date'],
                'expire_date' => $row['expire_date'], 'received_date' => $row['received_date'],
                'unit_cost' => $canViewCost ? $row['batch_unit_cost'] : null,
                'inventory_amount_cents' => $canViewCost ? $this->amountCents($row['inventory_amount'] ?? null) : null,
            ];
        }, $rows);
        $batchIds = array_map(static fn(array $row): int => (int)$row['batch_id'], $batches);
        $facts = Db::name('inventory_batch_movement_fact')->alias('f')->leftJoin('inventory_location l', 'l.id=f.location_id')
            ->leftJoin('inventory_business_document_no n', 'n.tenant_id=f.tenant_id AND n.source_type=f.source_type AND n.source_id=f.source_id')
            ->where('f.tenant_id', $scope->tenantId())->whereIn('f.location_id', $scope->locationIds())
            ->whereIn('f.batch_id', $batchIds)->where('f.fact_status', 'SETTLED')
            ->leftJoin('inventory_stock s', 's.id=f.stock_id')
            ->field('f.id,COALESCE(n.document_no,f.source_id) order_sn,f.source_type,f.source_id,f.source_detail_id,f.reversal_of,f.business_date,f.direction,f.quantity_units,s.quantity_scale,s.stock_unit,f.unit_cost_cents,f.cost_amount_cents,f.recorded_at,l.location_name')
            ->order('f.id desc')->limit(200)->select()->toArray();
        foreach ($facts as &$fact) {
            if (!$canViewCost) {
                $fact['unit_cost_cents'] = null;
                $fact['cost_amount_cents'] = null;
            }
        }
        unset($fact);
        return [
            'product' => ['product_id' => $productId, 'product_name' => $first['product_name'], 'sku_name' => $first['sku_name'], 'stock_unit' => $first['stock_unit']],
            'total_available_quantity' => $reconciliation['batch_total_available_quantity'],
            'reconciliation' => $reconciliation,
            'batches' => $batches, 'movements' => $facts, 'data_as_of' => time(),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function rows(int $storeId, int $operatorId, bool $canViewCost, ?string $cutoffDate = null): array
    {
        return $this->rowsForScope($this->scope($storeId, $operatorId, $canViewCost), $cutoffDate);
    }

    /** @return array<int,array<string,mixed>> */
    private function rowsForScope(InventoryBatchStockDataScope $scope, ?string $cutoffDate = null): array
    {
        return (new InventoryBatchStockQueryProvider())->sourceRows(['queryCutoffDate' => $cutoffDate ?: date('Y-m-d'), 'locationIds' => [], 'includeZero' => false], $scope);
    }

    /** @param array<int,array<string,mixed>> $batchRows */
    private function reconcileProductBalance(InventoryBatchStockDataScope $scope, int $productId, array $batchRows): array
    {
        $scale = (int)$batchRows[0]['quantity_scale'];
        $batchUnits = 0;
        foreach ($batchRows as $row) {
            if ((int)$row['quantity_scale'] !== $scale) throw new \RuntimeException('inventory_product_quantity_scale_mismatch');
            $batchUnits += $this->decimalUnits((string)$row['batch_balance_quantity'], $scale);
        }

        $stocks = Db::name('inventory_stock')
            ->where('tenant_id', $scope->tenantId())
            ->whereIn('location_id', $scope->locationIds())
            ->where('consumable_product_id', $productId)
            ->field('id,quantity_scale,available_quantity_units')
            ->select()->toArray();
        $stockUnits = 0;
        foreach ($stocks as $stock) {
            if ((int)$stock['quantity_scale'] !== $scale) throw new \RuntimeException('inventory_product_quantity_scale_mismatch');
            $stockUnits += (int)$stock['available_quantity_units'];
        }
        if ($stockUnits !== $batchUnits) throw new \RuntimeException('inventory_stock_batch_balance_mismatch');

        $total = $this->unitsDecimal($batchUnits, $scale);
        return [
            'status' => 'MATCHED',
            'batch_total_available_quantity' => $total,
            'stock_total_available_quantity' => $this->unitsDecimal($stockUnits, $scale),
        ];
    }

    private function scope(int $storeId, int $operatorId, bool $canViewCost): InventoryBatchStockDataScope
    {
        $locations = Db::name('inventory_location')->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('store_id', $storeId)->where('location_status', 'ACTIVE')->field('id,tenant_id,store_id,location_status')->select()->toArray();
        return (new InventoryStoreBatchScopeResolver())->resolve($storeId, $locations, $canViewCost);
    }

    private function defaultLocationId(int $storeId): int
    {
        return (int)Db::name('inventory_location')->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('store_id', $storeId)->where('location_type', 'STORE')->where('is_default', 1)->where('location_status', 'ACTIVE')->value('id');
    }

    private function decimalUnits(string $quantity, int $scale): int
    {
        [$whole, $fraction] = array_pad(explode('.', $quantity, 2), 2, '');
        $fraction = substr(str_pad($fraction, $scale, '0'), 0, $scale);
        return (int)$whole * (10 ** $scale) + (int)($fraction === '' ? '0' : $fraction);
    }
    private function unitsDecimal(int $units, int $scale): string { $sign = $units < 0 ? '-' : ''; $digits = str_pad((string)abs($units), $scale + 1, '0', STR_PAD_LEFT); return $scale === 0 ? $sign . $digits : $sign . rtrim(rtrim(substr($digits, 0, -$scale) . '.' . substr($digits, -$scale), '0'), '.'); }
    private function amountCents($value): int { if ($value === null || !preg_match('/^(\d+)\.(\d{2})$/D', (string)$value, $m)) return 0; return (int)$m[1] * 100 + (int)$m[2]; }
}
