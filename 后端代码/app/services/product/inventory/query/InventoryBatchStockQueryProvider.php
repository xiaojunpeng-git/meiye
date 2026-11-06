<?php
declare(strict_types=1);

namespace app\services\product\inventory\query;

use app\services\product\inventory\InventoryCurrentStockUnitProjection;
use app\services\query\UnifiedQueryExternalRows;
use think\facade\Db;

/**
 * Inventory-owned read provider. UQ-GEN later adapts this provider without
 * moving inventory SQL or DataScope into the shared query framework.
 */
final class InventoryBatchStockQueryProvider
{
    // 库存事实保存的是仓内商品 ID；门店映射必须先核实归属，独立商品继续使用自身 ID。
    private const CATALOG_PRODUCT_ID = 'CASE WHEN p.type=1 AND p.relation_id=s.store_id AND base.id IS NOT NULL THEN base.id ELSE s.consumable_product_id END';

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
        $rows = $this->balanceQuery($request, $scope)
            ->limit(self::MAX_SOURCE_ROWS + 1)
            ->select()
            ->toArray();
        if (count($rows) > self::MAX_SOURCE_ROWS) {
            throw new \RuntimeException('inventory_query_source_window_too_large');
        }
        return $this->projectRows($rows, $scope, $request['queryCutoffDate']);
    }

    /** 从授权仓现有的库存档案分页选品。同一商品只出现一次，数量统计仍读取结存事实。 */
    public function productOptions(InventoryBatchStockDataScope $scope, string $keyword, int $page, int $limit): array
    {
        $identity = self::CATALOG_PRODUCT_ID;
        $query = $this->joinCatalogProduct(Db::name('inventory_stock')->alias('s'))
            ->where('s.tenant_id', $scope->tenantId())
            ->whereIn('s.location_id', $scope->locationIds());
        if ($keyword !== '') {
            $query->leftJoin('store_product_attr_value sku', 'sku.id=s.sku_id AND sku.product_id=s.consumable_product_id')
                ->whereLike('p.store_name|p.code|p.bar_code|base.store_name|base.code|base.bar_code|sku.code|sku.bar_code|sku.suk', '%' . $keyword . '%');
        }
        $count = (clone $query)->fieldRaw('COUNT(DISTINCT ' . $identity . ') AS total')->find();
        $total = (int)($count['total'] ?? 0);
        $page = min($page, max(1, (int)ceil($total / $limit)));
        $list = $query->fieldRaw($identity . ' AS product_id, MAX(COALESCE(base.store_name, p.store_name)) AS product_name')
            ->group($identity)
            ->order('product_id desc')
            ->page($page, $limit)
            ->select()->toArray();
        foreach ($list as &$row) {
            if (trim((string)$row['product_name']) === '') $row['product_name'] = '商品 #' . $row['product_id'];
        }
        unset($row);
        return compact('list', 'total', 'page');
    }

    /** 确认上级是平台商品后才按总部商品归并，失去目录关系的历史库存保留原商品身份。 */
    private function joinCatalogProduct($query)
    {
        return $query->leftJoin('store_product p', 'p.id=s.consumable_product_id')
            ->leftJoin('store_product base', 'base.id=p.pid AND base.type=0 AND base.relation_id=0 AND p.type=1 AND p.relation_id=s.store_id');
    }

    /**
     * 大范围统一查询从授权仓库事实流读取，不截断在 10,000 个批次。
     * 原始行暂存于私有临时文件以固定同一次查询的快照，再按相同投影口径分批交给统一执行器。
     */
    public function sourceBatches(array $request, InventoryBatchStockDataScope $scope): \Generator
    {
        $request = $this->normalizeRequest($request, $scope);
        $rawRows = new UnifiedQueryExternalRows();
        $totals = [];
        $missingStoreIds = [];
        foreach ($this->balanceQuery($request, $scope)->cursor() as $row) {
            if (!is_array($row)) $row = (array)$row;
            $rawRows->append($row);
            $this->addQuantityTotal($totals, $row);
            $storeId = (int)$row['store_id'];
            if ($storeId > 0 && trim((string)$row['store_name']) === '') $missingStoreIds[$storeId] = $storeId;
        }
        $names = $this->currentStoreNames($missingStoreIds);
        $batch = [];
        foreach ($rawRows as $row) {
            $batch[] = $row;
            if (count($batch) === 200) {
                yield $this->projectBatch($batch, $scope, $request['queryCutoffDate'], $names, $totals);
                $batch = [];
            }
        }
        if ($batch) yield $this->projectBatch($batch, $scope, $request['queryCutoffDate'], $names, $totals);
    }

    /** 权限仓库、截止日、事实状态与批次聚合只在这一处定义，列表和大范围查询一致。 */
    private function balanceQuery(array $request, InventoryBatchStockDataScope $scope)
    {
        $signedQuantity = "SUM(IF(f.direction=1,f.quantity_units,-CAST(f.quantity_units AS SIGNED)))";
        $query = Db::name('inventory_batch_movement_fact')
            ->alias('f')
            ->join('inventory_batch b', 'b.id=f.batch_id')
            ->join('inventory_stock s', 's.id=f.stock_id')
            ->join('inventory_location l', 'l.id=f.location_id')
            ->where('f.tenant_id', $scope->tenantId())
            ->whereIn('f.location_id', $request['locationIds'])
            ->where('f.business_date', '<=', $request['queryCutoffDate'])
            ->where('f.fact_status', 'SETTLED');
        $this->joinCatalogProduct($query);
        if ($request['catalogProductIds']) {
            $placeholders = implode(',', array_fill(0, count($request['catalogProductIds']), '?'));
            $query->whereRaw(self::CATALOG_PRODUCT_ID . " IN ({$placeholders})", $request['catalogProductIds']);
        }
        return $query
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
                Db::raw(self::CATALOG_PRODUCT_ID . ' AS catalog_product_id'),
                's.sku_id',
                // 领用选品必须沿用库存事实的 SKU 身份；已下架商品不能改用同名新目录 SKU。
                's.product_unique' => 'sku_unique',
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
                'l.store_name_snapshot', 's.consumable_product_id', 'catalog_product_id', 's.sku_id', 's.product_unique',
                'b.product_name_snapshot', 'b.sku_name_snapshot', 'b.product_code_snapshot',
                'b.barcode_snapshot', 'b.brand_name_snapshot', 'b.category_name_snapshot',
                's.stock_unit', 's.quantity_scale', 's.stock_status', 'b.batch_no',
                'b.received_business_date', 'b.manufactured_date', 'b.expire_date',
                'b.unit_cost_cents', 'b.source_order_no_snapshot', 'b.data_quality',
            ])
            ->having($request['includeZero'] ? $signedQuantity . '>=0' : $signedQuantity . '>0')
            ->order('b.id asc');
    }

    /** 兼容仍需小范围数组的库存首页等调用方，投影值与流式路径共用同一计算。 */
    private function projectRows(array $rows, InventoryBatchStockDataScope $scope, string $cutoffDate): array
    {
        $totals = [];
        // 旧库位缺少门店名称快照时，按库位的门店 ID 一次性补读当前名称；不按仓名猜门店。
        $missingStoreIds = [];
        foreach ($rows as $row) {
            $storeId = (int)$row['store_id'];
            if ($storeId > 0 && trim((string)$row['store_name']) === '') $missingStoreIds[$storeId] = $storeId;
            $this->addQuantityTotal($totals, $row);
        }
        $names = $this->currentStoreNames($missingStoreIds);
        return $this->projectBatch($rows, $scope, $cutoffDate, $names, $totals);
    }

    private function currentStoreNames(array $storeIds): array
    {
        return $storeIds ? Db::name('system_store')->whereIn('id', array_values($storeIds))->column('name', 'id') : [];
    }

    /** 数量事实按原商品/SKU 身份累计；不得先按商品名筛选而漏掉改名前的批次。 */
    private function addQuantityTotal(array &$totals, array $row): void
    {
        $key = (int)$row['product_id'] . ':' . (int)$row['sku_id'];
        $scale = (int)$row['quantity_scale'];
        if ($scale < 0 || $scale > 4 || (isset($totals[$key]) && (int)$totals[$key]['scale'] !== $scale)) {
            throw new \RuntimeException('inventory_product_quantity_scale_mismatch');
        }
        $totals[$key] = ['scale' => $scale, 'units' => (int)($totals[$key]['units'] ?? 0) + (int)$row['balance_quantity_units']];
    }

    private function projectBatch(array $rows, InventoryBatchStockDataScope $scope, string $cutoffDate, array $names, array $totals): array
    {
        $projected = array_map(function (array $row) use ($scope, $cutoffDate, $names): array {
            return $this->projectRow($row, $scope, $cutoffDate, $names);
        }, $rows);
        // 当前商品单位只改标签，不改数量；按批次查询目录避免全量商品目录载入内存。
        $projected = (new InventoryCurrentStockUnitProjection())->decorate($projected);
        foreach ($projected as &$row) {
            $key = (int)$row['product_id'] . ':' . (int)$row['sku_id'];
            $row['available_quantity'] = InventoryBatchStockQueryContract::unitsToDecimal((int)$totals[$key]['units'], (int)$totals[$key]['scale']);
        }
        unset($row);
        return $projected;
    }

    private function normalizeRequest(array $request, InventoryBatchStockDataScope $scope): array
    {
        $catalogProductIds = $request['catalogProductIds'] ?? [];
        unset($request['catalogProductIds']);
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
            'catalogProductIds' => $catalogProductIds,
        ];
    }

    private function projectRow(
        array $row,
        InventoryBatchStockDataScope $scope,
        string $cutoffDate,
        array $currentStoreNames
    ): array {
        $scale = (int)$row['quantity_scale'];
        $status = (string)$row['quality_status_code'];
        $expireDate = $this->nullableDate($row['expire_date'] ?? null);
        if ($status === InventoryBatchStockQueryContract::STATUS_GOOD
            && $expireDate !== null && $expireDate < $cutoffDate) {
            $status = InventoryBatchStockQueryContract::STATUS_EXPIRED;
        }
        $storeId = (int)$row['store_id'];
        $storeName = trim((string)$row['store_name']);
        if ($storeId > 0 && $storeName === '') $storeName = trim((string)($currentStoreNames[$storeId] ?? ''));
        // 查询、筛选与导出共用此展示名；总部仍保留真实仓名，门店不再重复显示“默认门店仓”。
        $locationDisplayName = $storeId > 0
            ? ($storeName !== '' ? $storeName : '门店' . $storeId)
            : (string)$row['location_name'];
        return [
            'batch_balance_id' => (int)$row['batch_balance_id'],
            'tenant_id' => (string)$row['tenant_id'],
            'organization_id' => (string)$row['organization_id'],
            'organization_path' => (string)$row['organization_path'],
            'organization_name' => (string)$row['organization_name'],
            'location_id' => (int)$row['location_id'],
            'location_name' => $locationDisplayName,
            'store_id' => $storeId,
            'store_name' => $storeName,
            'product_id' => (int)$row['product_id'],
            'catalog_product_id' => (int)($row['catalog_product_id'] ?? $row['product_id']),
            'sku_id' => (int)$row['sku_id'],
            'sku_unique' => (string)$row['sku_unique'],
            'product_name' => (string)$row['product_name'],
            'sku_name' => (string)$row['sku_name'],
            'product_code' => (string)$row['product_code'],
            'barcode' => (string)$row['barcode'],
            'brand_name' => (string)$row['brand_name'],
            'category_name' => (string)$row['category_name'],
            'stock_unit' => (string)$row['stock_unit'],
            // Read-model consumers need the authoritative scale to aggregate decimal quantities.
            // It is not a user-configurable UQ field and is therefore intentionally not registered.
            'quantity_scale' => $scale,
            'batch_no' => (string)$row['batch_no'],
            'quality_status' => InventoryBatchStockQueryContract::statusLabel($status),
            'received_date' => $this->nullableDate($row['received_date'] ?? null),
            'manufactured_date' => $this->nullableDate($row['manufactured_date'] ?? null),
            'expire_date' => $expireDate,
            // 列表、合计及导出使用同一个后端截止日；当天到期仍属于未过期。
            'expiry_status' => $expireDate === null ? '到期日未知' : ($expireDate < $cutoffDate ? '已过期' : '未过期'),
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
            // Internal summary-only value: the UQ executor aggregates it after permission and filters.
            'inventory_amount_total' => $scope->canViewCost()
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
