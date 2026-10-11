<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\product\inventory\query\InventoryBatchStockQueryContract;
use app\services\product\inventory\completion\InventoryEntitlementCompletionContract;
use think\facade\Db;
use app\services\product\product\PhysicalProductSelectionPolicy;

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
            ->where(function ($query): void { PhysicalProductSelectionPolicy::apply($query); })->where('a.type', 0)
            ->where(function ($query) use ($barcode): void {
                // Physical scanners may send the product code or SKU code when
                // the source barcode field has not been populated.
                $query->where('a.bar_code', $barcode)
                    ->whereOr('p.bar_code', $barcode)
                    ->whereOr('a.code', $barcode)
                    ->whereOr('p.code', $barcode);
            })
            ->fieldRaw("p.id AS product_id,p.no_expiry_management,p.store_name AS product_name,p.code AS product_code,a.id AS sku_id,a.unique AS sku_unique,a.suk AS sku_name,COALESCE(NULLIF(a.bar_code, ''), p.bar_code) AS barcode,a.code AS sku_code,a.stock_unit")
            ->group('a.id')->order('a.id asc')->limit(2)->select()->toArray();
        if (!$rows) throw new \RuntimeException('inventory_catalog_barcode_not_found');
        if (count($rows) !== 1) throw new \RuntimeException('inventory_catalog_barcode_ambiguous');
        return $this->withAvailableQuantity($storeId, $rows)[0];
    }

    /**
     * 门店身份只来自认证会话；普通查询按有效目录筛选，现存库存盘点仅限该门店默认仓。
     */
    public function search(int $storeId, string $keyword, int $categoryId = 0, int $page = 1, int $limit = 20, bool $forCount = false, bool $includeCountBatches = false, bool $canViewCost = false, bool $forExistingStock = false, bool $inventoryOnly = true): array
    {
        $keyword = trim($keyword);
        if ($storeId <= 0 || $categoryId < 0 || mb_strlen($keyword) > 64) {
            throw new \InvalidArgumentException('inventory_catalog_search_invalid');
        }
        $page = max(1, $page);
        $limit = max(1, min($limit, 50));
        if ($forExistingStock) {
            if (!$forCount || !$includeCountBatches || $keyword !== '' || $categoryId !== 0) {
                throw new \InvalidArgumentException('inventory_catalog_existing_stock_invalid');
            }
            return $this->positiveBatchCountCatalog($storeId, $page, $limit, $canViewCost);
        }
        $query = Db::name('store_product_attr_value')
            ->alias('a')
            ->join('store_product p', 'p.id=a.product_id')
            ->where('p.type', 1)
            ->where('p.relation_id', $storeId)
            // 是否要求库存管理由后端业务入口固定，不从浏览器接受该开关。
            ->where(function ($query) use ($inventoryOnly): void { PhysicalProductSelectionPolicy::apply($query, $inventoryOnly); })
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
                'p.no_expiry_management', // 当前商品配置供共享编辑器展示；写入时仍重新锁定读取。
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
            ->where(function ($query) use ($inventoryOnly): void { PhysicalProductSelectionPolicy::apply($query, $inventoryOnly); })
            ->where('c.is_show', 1)
            ->field(['c.id' => 'id', 'c.cate_name' => 'name', 'c.pid' => 'parent_id'])
            ->group('c.id')
            ->order('c.sort desc,c.id asc')
            ->select()
            ->toArray();

        // Count confirmation compares the current stock projection under lock; only count callers use that same book basis.
        $list = $forCount ? $this->withCountBookQuantity($storeId, $list) : $this->withAvailableQuantity($storeId, $list, $inventoryOnly);
        if ($includeCountBatches) {
            // 仓库由会话门店默认仓确定，不接受客户端传入任意仓 ID。
            $locationId = (int)Db::name('inventory_location')->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->where('store_id', $storeId)->where('location_type', 'STORE')->where('is_default', 1)->where('location_status', 'ACTIVE')->value('id');
            if (!$locationId) throw new \RuntimeException('inventory_catalog_source_scope_denied');
            $list = (new InventoryCountBatchCatalog())->attach($list, CashierV3ScopeResolver::TENANT_SCOPE_ID, $locationId, $canViewCost);
        }
        return compact('list', 'total', 'page', 'limit', 'categories');
    }

    /** 导入和清零候选取默认仓正数库存主体；历史下架/缺失目录仍须参与盘点。 */
    private function positiveBatchCountCatalog(int $storeId, int $page, int $limit, bool $canViewCost): array
    {
        $locationId = (int)Db::name('inventory_location')->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('store_id', $storeId)->where('location_type', 'STORE')->where('is_default', 1)
            ->where('location_status', 'ACTIVE')->value('id');
        if (!$locationId) throw new \RuntimeException('inventory_catalog_source_scope_denied');
        // 存在正数库存主体却没有可盘批次时必须阻止“一键全部清零”，不能悄悄漏掉残额。
        $positiveStocks = (int)Db::name('inventory_stock')->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('store_id', $storeId)->where('location_id', $locationId)
            ->where('stock_status', 'GOOD')->where('available_quantity_units', '>', 0)->count();
        $query = Db::name('inventory_stock')->alias('s')->join('inventory_batch b', 'b.stock_id=s.id')
            ->where('s.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('s.store_id', $storeId)->where('s.location_id', $locationId)
            ->where('s.stock_status', 'GOOD')->where('s.available_quantity_units', '>', 0)
            ->where('b.batch_status', 'ACTIVE')->where('b.available_quantity_units', '>', 0);
        $total = (int)(clone $query)->count('DISTINCT s.id');
        if ($total !== $positiveStocks) throw new \RuntimeException('inventory_stock_count_batch_balance_mismatch');
        $list = $query->fieldRaw('s.id AS stock_id,s.consumable_product_id AS product_id,s.sku_id,s.product_unique AS sku_unique,s.stock_unit,s.available_quantity_units,s.quantity_scale,b.product_name_snapshot AS product_name,b.sku_name_snapshot AS sku_name,b.barcode_snapshot AS barcode')
            ->group('s.id')->order('s.id asc')->page($page, $limit)->select()->toArray();
        // 历史批次可来自失效商品；只补当前配置，不因商品失效而漏掉已有库存。
        $expirySettings = $list ? Db::name('store_product')->whereIn('id', array_column($list, 'product_id'))->column('no_expiry_management', 'id') : [];
        // 失效目录仍只允许盘亏既有批次，不能因免录开关把历史批次转成可盘盈的 SKU 行。
        $activeSkus = $list ? Db::name('store_product_attr_value')->alias('a')->join('store_product p', 'p.id=a.product_id')
            ->whereIn('a.id', array_column($list, 'sku_id'))->where('a.type', 0)->where('p.type', 1)
            ->where('p.relation_id', $storeId)->where('p.is_del', 0)->where('p.is_inventory', 1)->column('a.id') : [];
        $activeSkus = array_fill_keys(array_map('intval', $activeSkus), true);
        foreach ($list as &$row) {
            $row['no_expiry_management'] = (int)($expirySettings[(int)$row['product_id']] ?? 0);
            $row['count_requires_batch_identity'] = !isset($activeSkus[(int)$row['sku_id']]);
            $row['available_quantity'] = InventoryBatchStockQueryContract::unitsToDecimal((int)$row['available_quantity_units'], (int)$row['quantity_scale']);
        }
        unset($row);
        $list = (new InventoryCountBatchCatalog())->attach($list, CashierV3ScopeResolver::TENANT_SCOPE_ID, $locationId, $canViewCost);
        foreach ($list as $row) {
            $batchUnits = 0;
            foreach ($row['count_batches'] as $batch) {
                $batchUnits += InventoryEntitlementCompletionContract::decimalToUnits((string)$batch['quantity'], (int)$row['quantity_scale']);
            }
            if ($batchUnits !== (int)$row['available_quantity_units']) throw new \RuntimeException('inventory_stock_count_batch_balance_mismatch');
        }
        return compact('list', 'total', 'page', 'limit') + ['categories' => []];
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

    /** 已认证门店默认仓的 SKU 总余额；只读取当页规格，不全仓加载批次。 */
    private function withAvailableQuantity(int $storeId, array $rows, bool $requireLocation = true): array
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
        if (!$location) {
            if ($requireLocation) throw new \RuntimeException('inventory_catalog_source_scope_denied');
            // 赠送只发权益，没有配置库存仓也可选品；不存在仓库时库存为零，不借用其他仓。
            return array_map(static function (array $row): array { $row['available_quantity'] = '0'; return $row; }, $rows);
        }
        return (new InventoryCatalogStockProjection())->decorate(CashierV3ScopeResolver::TENANT_SCOPE_ID, (int)$location['id'], $rows);
    }
}
