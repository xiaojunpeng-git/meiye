<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\product\inventory\query\InventoryBatchStockDataScope;
use app\services\product\inventory\query\InventoryBatchStockQueryContract;
use app\services\product\inventory\query\InventoryBatchStockQueryProvider;
use think\facade\Db;

/** Read-only bridge to the established store product/SKU/barcode authority. */
final class InventoryStoreCatalogServices
{
    /** Resolve one SKU by the scanned barcode without pagination or fuzzy matching. */
    public function barcode(int $storeId, string $barcode): array
    {
        $barcode = trim($barcode);
        if ($storeId <= 0 || $barcode === '' || mb_strlen($barcode) > 64) {
            throw new \InvalidArgumentException('inventory_catalog_barcode_invalid');
        }

        $rows = Db::name('store_product_attr_value')
            ->alias('a')
            ->join('store_product p', 'p.id=a.product_id')
            ->where('p.type', 1)->where('p.relation_id', $storeId)
            ->where('p.is_del', 0)->where('p.is_inventory', 1)->where('a.type', 0)
            ->where(function ($query) use ($barcode): void {
                // Physical scanners may send the product code or SKU code when
                // the source barcode field has not been populated.
                $query->where('a.bar_code', $barcode)
                    ->whereOr('p.bar_code', $barcode)
                    ->whereOr('a.code', $barcode)
                    ->whereOr('p.code', $barcode);
            })
            ->fieldRaw("p.id AS product_id,p.store_name AS product_name,p.code AS product_code,a.id AS sku_id,a.unique AS sku_unique,a.suk AS sku_name,COALESCE(NULLIF(a.bar_code, ''), p.bar_code) AS barcode,a.code AS sku_code,a.stock_unit")
            ->group('a.id')->order('a.id asc')->limit(2)->select()->toArray();
        if (!$rows) throw new \RuntimeException('inventory_catalog_barcode_not_found');
        if (count($rows) !== 1) throw new \RuntimeException('inventory_catalog_barcode_ambiguous');
        return $this->withAvailableQuantity($storeId, $rows)[0];
    }

    /**
     * The store ID is always derived from the authenticated store session. Client
     * category and page inputs can only narrow a catalog already scoped to it.
     */
    public function search(int $storeId, string $keyword, int $categoryId = 0, int $page = 1, int $limit = 20, bool $forCount = false): array
    {
        $keyword = trim($keyword);
        if ($storeId <= 0 || $categoryId < 0 || mb_strlen($keyword) > 64) {
            throw new \InvalidArgumentException('inventory_catalog_search_invalid');
        }
        $page = max(1, $page);
        $limit = max(1, min($limit, 50));
        $query = Db::name('store_product_attr_value')
            ->alias('a')
            ->join('store_product p', 'p.id=a.product_id')
            ->where('p.type', 1)
            ->where('p.relation_id', $storeId)
            ->where('p.is_del', 0)
            ->where('p.is_inventory', 1)
            ->where('a.type', 0)
            ->when($keyword !== '', function ($query) use ($keyword) {
                $like = '%' . $keyword . '%';
                $query->where(function ($keywordQuery) use ($like) {
                    $keywordQuery->whereLike('a.bar_code|a.code|a.unique', $like)
                        ->whereOr('p.store_name|p.keyword|p.bar_code|p.code', 'like', $like);
                });
            })
            ->when($categoryId > 0, function ($query) use ($categoryId) {
                $query->whereIn('p.id', function ($categoryQuery) use ($categoryId) {
                    $categoryQuery->name('store_product_relation')
                        ->where('type', 1)
                        ->where('status', 1)
                        ->where('relation_id', $categoryId)
                        ->field('product_id')
                        ->select();
                });
            });

        $total = (int)(clone $query)->count('DISTINCT a.id');
        $list = $query
            ->field([
                'p.id' => 'product_id',
                'p.store_name' => 'product_name',
                'p.code' => 'product_code',
                'a.id' => 'sku_id',
                'a.unique' => 'sku_unique',
                'a.suk' => 'sku_name',
                'a.bar_code' => 'barcode',
                'a.code' => 'sku_code',
                'a.stock_unit',
            ])
            ->group('a.id')
            ->order('p.sort desc,p.id desc,a.id asc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        $categories = Db::name('store_product_category')
            ->alias('c')
            ->join('store_product_relation r', 'r.relation_id=c.id AND r.type=1 AND r.status=1')
            ->join('store_product p', 'p.id=r.product_id')
            ->where('p.type', 1)
            ->where('p.relation_id', $storeId)
            ->where('p.is_del', 0)
            ->where('p.is_inventory', 1)
            ->where('c.is_show', 1)
            ->field(['c.id' => 'id', 'c.cate_name' => 'name', 'c.pid' => 'parent_id'])
            ->group('c.id')
            ->order('c.sort desc,c.id asc')
            ->select()
            ->toArray();

        // Count confirmation compares the current stock projection under lock; only count callers use that same book basis.
        $list = $forCount ? $this->withCountBookQuantity($storeId, $list) : $this->withAvailableQuantity($storeId, $list);
        return compact('list', 'total', 'page', 'limit', 'categories');
    }

    /** Read only the requested page's SKU balances, so loading all SKUs does not hit the batch-report 10k-row window. */
    private function withCountBookQuantity(int $storeId, array $rows): array
    {
        if (!$rows) return [];
        $location = Db::name('inventory_location')
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('store_id', $storeId)->where('location_type', 'STORE')
            ->where('is_default', 1)->where('location_status', 'ACTIVE')->field('id')->find();
        if (!$location) throw new \RuntimeException('inventory_catalog_source_scope_denied');
        $stockRows = Db::name('inventory_stock')->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('location_id', (int)$location['id'])->where('store_id', $storeId)
            ->where('stock_status', 'GOOD')
            ->whereIn('sku_id', array_map(static fn(array $row): int => (int)$row['sku_id'], $rows))
            ->field('consumable_product_id,sku_id,available_quantity_units,quantity_scale')->select()->toArray();
        $available = [];
        foreach ($stockRows as $stock) {
            // SKU ID 是库存唯一键；历史规格编码更新不能把非零账面数误读为 0。
            $key = (int)$stock['consumable_product_id'] . ':' . (int)$stock['sku_id'];
            $available[$key] = InventoryBatchStockQueryContract::unitsToDecimal((int)$stock['available_quantity_units'], (int)$stock['quantity_scale']);
        }
        foreach ($rows as &$row) {
            $key = (int)$row['product_id'] . ':' . (int)$row['sku_id'];
            $row['available_quantity'] = $available[$key] ?? '0';
        }
        unset($row);
        return $rows;
    }

    /** Store-session catalog inventory is projected from settled batch facts. */
    private function withAvailableQuantity(int $storeId, array $rows): array
    {
        if (!$rows) return [];
        $location = Db::name('inventory_location')
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('store_id', $storeId)
            ->where('location_type', 'STORE')
            ->where('is_default', 1)
            ->where('location_status', 'ACTIVE')
            ->field('id')
            ->find();
        if (!$location) throw new \RuntimeException('inventory_catalog_source_scope_denied');
        $locationId = (int)$location['id'];
        $scope = new InventoryBatchStockDataScope(
            CashierV3ScopeResolver::TENANT_SCOPE_ID,
            [$locationId],
            [InventoryBatchStockQueryContract::PERMISSION_VIEW]
        );
        $stockRows = (new InventoryBatchStockQueryProvider())->sourceRows([
            'queryCutoffDate' => date('Y-m-d'),
            'locationIds' => [$locationId],
            'includeZero' => false,
        ], $scope);
        $available = [];
        foreach ($stockRows as $stockRow) {
            $available[(int)$stockRow['product_id'] . ':' . (int)$stockRow['sku_id']] = (string)$stockRow['available_quantity'];
        }
        foreach ($rows as &$row) {
            $key = (int)$row['product_id'] . ':' . (int)$row['sku_id'];
            $row['available_quantity'] = $available[$key] ?? '0';
        }
        unset($row);
        return $rows;
    }
}
