<?php
declare(strict_types=1);

namespace app\services\product\inventory\query;

use think\facade\Db;

/**
 * Inventory-owned read provider. UQ-GEN later adapts this provider without
 * moving inventory SQL or DataScope into the shared query framework.
 */
final class InventoryBatchStockQueryProvider
{
    private const MAX_SOURCE_ROWS = 10000;

    public function pageCode(): string
    {
        return InventoryBatchStockQueryContract::PAGE_CODE;
    }

    public function pageDefinition(): array
    {
        return [
            'pageCode' => $this->pageCode(),
            'label' => '批次库存',
            'stableRowKey' => InventoryBatchStockQueryContract::STABLE_ROW_KEY,
            'keywordFields' => ['product_name', 'sku_name', 'product_code', 'barcode', 'batch_no'],
            'requiredFeature' => InventoryBatchStockQueryContract::PERMISSION_VIEW,
            'exportFeature' => InventoryBatchStockQueryContract::PERMISSION_EXPORT,
            'fields' => InventoryBatchStockQueryContract::fields(),
        ];
    }

    public function sourceRows(array $request, InventoryBatchStockDataScope $scope): array
    {
        $request = $this->normalizeRequest($request, $scope);
        $signedQuantity = "SUM(IF(f.direction=1,f.quantity_units,-CAST(f.quantity_units AS SIGNED)))";
        $rows = Db::name('inventory_batch_movement_fact')
            ->alias('f')
            ->join('inventory_batch b', 'b.id=f.batch_id')
            ->join('inventory_stock s', 's.id=f.stock_id')
            ->join('inventory_location l', 'l.id=f.location_id')
            ->where('f.tenant_id', $scope->tenantId())
            ->whereIn('f.location_id', $request['locationIds'])
            ->where('f.business_date', '<=', $request['queryCutoffDate'])
            ->where('f.fact_status', 'SETTLED')
            ->field([
                'b.id' => 'batch_balance_id',
                'f.tenant_id',
                'l.organization_id',
                'l.organization_path',
                'l.organization_name_snapshot' => 'organization_name',
                'l.id' => 'location_id',
                'l.location_name' => 'location_name',
                'l.store_id',
                'l.store_name_snapshot' => 'store_name',
                's.consumable_product_id' => 'product_id',
                's.sku_id',
                'b.product_name_snapshot' => 'product_name',
                'b.sku_name_snapshot' => 'sku_name',
                'b.product_code_snapshot' => 'product_code',
                'b.barcode_snapshot' => 'barcode',
                'b.brand_name_snapshot' => 'brand_name',
                'b.category_name_snapshot' => 'category_name',
                's.stock_unit',
                's.quantity_scale',
                's.stock_status' => 'quality_status_code',
                'b.batch_no',
                'b.received_business_date' => 'received_date',
                'b.manufactured_date',
                'b.expire_date',
                'b.unit_cost_cents',
                'b.source_order_no_snapshot' => 'source_order_no',
                'b.data_quality',
                Db::raw($signedQuantity . ' AS balance_quantity_units'),
            ])
            ->group([
                'b.id', 'f.tenant_id', 'l.organization_id', 'l.organization_path',
                'l.organization_name_snapshot', 'l.id', 'l.location_name', 'l.store_id',
                'l.store_name_snapshot', 's.consumable_product_id', 's.sku_id',
                'b.product_name_snapshot', 'b.sku_name_snapshot', 'b.product_code_snapshot',
                'b.barcode_snapshot', 'b.brand_name_snapshot', 'b.category_name_snapshot',
                's.stock_unit', 's.quantity_scale', 's.stock_status', 'b.batch_no',
                'b.received_business_date', 'b.manufactured_date', 'b.expire_date',
                'b.unit_cost_cents', 'b.source_order_no_snapshot', 'b.data_quality',
            ])
            ->having($request['includeZero'] ? $signedQuantity . '>=0' : $signedQuantity . '>0')
            ->order('b.id asc')
            ->limit(self::MAX_SOURCE_ROWS + 1)
            ->select()
            ->toArray();
        if (count($rows) > self::MAX_SOURCE_ROWS) {
            throw new \RuntimeException('inventory_query_source_window_too_large');
        }
        return array_map(function (array $row) use ($scope, $request): array {
            return $this->projectRow($row, $scope, $request['queryCutoffDate']);
        }, $rows);
    }

    private function normalizeRequest(array $request, InventoryBatchStockDataScope $scope): array
    {
        $allowed = ['queryCutoffDate', 'locationIds', 'includeZero'];
        $actual = array_keys($request);
        sort($allowed, SORT_STRING);
        sort($actual, SORT_STRING);
        if ($actual !== $allowed || !is_array($request['locationIds'])
            || !is_bool($request['includeZero'])) {
            throw new \InvalidArgumentException('inventory_query_request_invalid');
        }
        return [
            'queryCutoffDate' => InventoryBatchStockQueryContract::assertCutoffDate(
                (string)$request['queryCutoffDate']
            ),
            'locationIds' => $scope->assertRequestedLocations($request['locationIds']),
            'includeZero' => $request['includeZero'],
        ];
    }

    private function projectRow(
        array $row,
        InventoryBatchStockDataScope $scope,
        string $cutoffDate
    ): array {
        $scale = (int)$row['quantity_scale'];
        $status = (string)$row['quality_status_code'];
        $expireDate = $this->nullableDate($row['expire_date'] ?? null);
        if ($status === InventoryBatchStockQueryContract::STATUS_GOOD
            && $expireDate !== null && $expireDate < $cutoffDate) {
            $status = InventoryBatchStockQueryContract::STATUS_EXPIRED;
        }
        return [
            'batch_balance_id' => (int)$row['batch_balance_id'],
            'tenant_id' => (string)$row['tenant_id'],
            'organization_id' => (string)$row['organization_id'],
            'organization_path' => (string)$row['organization_path'],
            'organization_name' => (string)$row['organization_name'],
            'location_id' => (int)$row['location_id'],
            'location_name' => (string)$row['location_name'],
            'store_id' => (int)$row['store_id'],
            'store_name' => (string)$row['store_name'],
            'product_id' => (int)$row['product_id'],
            'sku_id' => (int)$row['sku_id'],
            'product_name' => (string)$row['product_name'],
            'sku_name' => (string)$row['sku_name'],
            'product_code' => (string)$row['product_code'],
            'barcode' => (string)$row['barcode'],
            'brand_name' => (string)$row['brand_name'],
            'category_name' => (string)$row['category_name'],
            'stock_unit' => (string)$row['stock_unit'],
            'batch_no' => (string)$row['batch_no'],
            'quality_status' => InventoryBatchStockQueryContract::statusLabel($status),
            'received_date' => $this->nullableDate($row['received_date'] ?? null),
            'manufactured_date' => $this->nullableDate($row['manufactured_date'] ?? null),
            'expire_date' => $expireDate,
            'batch_balance_quantity' => InventoryBatchStockQueryContract::unitsToDecimal(
                (int)$row['balance_quantity_units'],
                $scale
            ),
            'batch_unit_cost' => $scope->canViewCost()
                ? InventoryBatchStockQueryContract::centsToAmount((int)$row['unit_cost_cents'])
                : null,
            'inventory_amount' => $scope->canViewCost()
                ? InventoryBatchStockQueryContract::centsToAmount(
                    intdiv(
                        (int)$row['balance_quantity_units'] * (int)$row['unit_cost_cents'],
                        10 ** $scale
                    )
                )
                : null,
            'source_order_no' => (string)$row['source_order_no'],
            'data_quality' => (string)$row['data_quality'],
        ];
    }

    private function nullableDate($value): ?string
    {
        if ($value === null || $value === '' || $value === '0000-00-00') {
            return null;
        }
        return InventoryBatchStockQueryContract::assertCutoffDate((string)$value);
    }
}
