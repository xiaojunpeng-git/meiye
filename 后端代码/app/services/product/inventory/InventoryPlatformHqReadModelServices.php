<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\product\inventory\query\InventoryBatchStockDataScope;
use app\services\product\inventory\query\InventoryBatchStockQueryProvider;
use think\facade\Db;

/** Headquarters stock projections from settled V3 batch facts only. */
final class InventoryPlatformHqReadModelServices
{
    public function productSummary(array $adminInfo, int $locationId, string $keyword, int $page, int $limit): array
    {
        [$location, $canViewCost, $rows] = $this->rows($adminInfo, $locationId);
        $groups = [];
        foreach ($rows as $row) {
            $productId = (int)$row['product_id'];
            if ($productId <= 0) continue;
            if (!isset($groups[$productId])) {
                $groups[$productId] = [
                    'product_id' => $productId, 'sku_id' => (int)$row['sku_id'],
                    'product_name' => (string)$row['product_name'], 'sku_name' => (string)$row['sku_name'],
                    'product_code' => (string)$row['product_code'], 'barcode' => (string)$row['barcode'],
                    'stock_unit' => (string)$row['stock_unit'], 'quantity_scale' => (int)$row['quantity_scale'],
                    'location_id' => (int)$location['id'], 'location_name' => (string)$location['location_name'],
                    'quantity_units' => 0, 'inventory_amount_cents' => $canViewCost ? 0 : null,
                ];
            }
            $groups[$productId]['quantity_units'] += $this->decimalUnits((string)$row['batch_balance_quantity'], (int)$groups[$productId]['quantity_scale']);
            if ($canViewCost) $groups[$productId]['inventory_amount_cents'] += $this->amountCents($row['inventory_amount'] ?? null);
        }
        $keyword = mb_strtolower(trim($keyword));
        $items = array_values(array_filter($groups, static function (array $row) use ($keyword): bool {
            return $keyword === '' || str_contains(mb_strtolower(implode(' ', [(string)$row['product_name'], (string)$row['product_code'], (string)$row['barcode']])), $keyword);
        }));
        usort($items, static fn(array $a, array $b): int => [$a['product_name'], $a['product_id']] <=> [$b['product_name'], $b['product_id']]);
        $page = max(1, $page); $limit = max(1, min(100, $limit)); $count = count($items);
        $items = array_slice($items, ($page - 1) * $limit, $limit);
        foreach ($items as &$item) { $item['available_quantity'] = $this->unitsDecimal((int)$item['quantity_units'], (int)$item['quantity_scale']); unset($item['quantity_units'], $item['quantity_scale']); }
        unset($item);
        return ['list' => $items, 'count' => $count, 'page' => $page, 'limit' => $limit];
    }

    public function productDetail(array $adminInfo, int $locationId, int $productId): array
    {
        if ($productId <= 0) throw new \InvalidArgumentException('inventory_product_id_invalid');
        [$location, $canViewCost, $allRows] = $this->rows($adminInfo, $locationId);
        $rows = array_values(array_filter($allRows, static fn(array $row): bool => (int)$row['product_id'] === $productId));
        if (!$rows) throw new \RuntimeException('inventory_product_stock_not_found');
        $first = $rows[0];
        $reconciliation = $this->reconcileProductBalance($location, $productId, $rows);
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
            ->where('f.tenant_id', (string)$location['tenant_id'])->where('f.location_id', (int)$location['id'])
            ->where('f.store_id', 0)->whereIn('f.batch_id', $batchIds)->where('f.fact_status', 'SETTLED')
            ->leftJoin('inventory_stock s', 's.id=f.stock_id')
            ->field('f.id,COALESCE(n.document_no,f.source_id) order_sn,f.source_type,f.business_date,f.direction,f.quantity_units,s.quantity_scale,s.stock_unit,f.unit_cost_cents,f.cost_amount_cents,f.recorded_at,l.location_name')
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

    /** @param array<int,array<string,mixed>> $batchRows */
    private function reconcileProductBalance(array $location, int $productId, array $batchRows): array
    {
        $scale = (int)$batchRows[0]['quantity_scale'];
        $batchUnits = 0;
        foreach ($batchRows as $row) {
            if ((int)$row['quantity_scale'] !== $scale) throw new \RuntimeException('inventory_product_quantity_scale_mismatch');
            $batchUnits += $this->decimalUnits((string)$row['batch_balance_quantity'], $scale);
        }

        $stocks = Db::name('inventory_stock')
            ->where('tenant_id', (string)$location['tenant_id'])
            ->where('location_id', (int)$location['id'])
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

    /** @return array{0:array,1:bool,2:array} */
    private function rows(array $adminInfo, int $locationId): array
    {
        $resolved = (new InventoryHqLocationServices())->readableLocation($adminInfo, $locationId);
        $location = (array)$resolved['location']; $access = (array)$resolved['access'];
        $canViewCost = in_array('inventory.cost.view', (array)($access['features'] ?? []), true);
        $scope = new InventoryBatchStockDataScope((string)$location['tenant_id'], [(int)$location['id']], (array)$access['features']);
        $rows = (new InventoryBatchStockQueryProvider())->sourceRows([
            'queryCutoffDate' => date('Y-m-d'), 'locationIds' => [(int)$location['id']], 'includeZero' => false,
        ], $scope);
        return [$location, $canViewCost, $rows];
    }

    private function decimalUnits(string $quantity, int $scale): int { [$whole, $fraction] = array_pad(explode('.', $quantity, 2), 2, ''); $fraction = substr(str_pad($fraction, $scale, '0'), 0, $scale); return (int)$whole * (10 ** $scale) + (int)($fraction === '' ? '0' : $fraction); }
    private function unitsDecimal(int $units, int $scale): string { $sign = $units < 0 ? '-' : ''; $digits = str_pad((string)abs($units), $scale + 1, '0', STR_PAD_LEFT); return $scale === 0 ? $sign . $digits : $sign . rtrim(rtrim(substr($digits, 0, -$scale) . '.' . substr($digits, -$scale), '0'), '.'); }
    private function amountCents($value): int { if ($value === null || !preg_match('/^(\d+)\.(\d{2})$/D', (string)$value, $m)) return 0; return (int)$m[1] * 100 + (int)$m[2]; }
}
