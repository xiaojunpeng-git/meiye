<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use think\facade\Db;

/** Read-only bridge to the established store product/SKU/barcode authority. */
final class InventoryStoreCatalogServices
{
    public function search(int $storeId, string $keyword, int $limit = 20): array
    {
        $keyword = trim($keyword);
        if ($storeId <= 0 || mb_strlen($keyword) > 64) {
            throw new \InvalidArgumentException('inventory_catalog_search_invalid');
        }
        $limit = max(1, min($limit, 50));
        return Db::name('store_product_attr_value')
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
                        ->whereOr('p.store_name|p.keyword|p.bar_code', 'like', $like);
                });
            })
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
            ->limit($limit)
            ->select()
            ->toArray();
    }
}
