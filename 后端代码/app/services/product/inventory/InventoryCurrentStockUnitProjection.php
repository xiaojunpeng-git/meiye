<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use think\facade\Db;

/** 仅修正当前库存读模型的单位标签；不改数量、历史单据或已结算事实。 */
final class InventoryCurrentStockUnitProjection
{
    /** 输入必须先由调用方按仓库权限裁剪；失效目录保留库存主体原有单位。 */
    public function decorate(array $rows): array
    {
        if (!$rows) return [];
        $productSkus = [];
        foreach ($rows as $row) {
            $productId = (int)($row['product_id'] ?? 0);
            $skuId = (int)($row['sku_id'] ?? 0);
            if ($productId > 0 && $skuId > 0) $productSkus[$productId][$skuId] = true;
        }
        if (!$productSkus) return $rows;

        $currentUnits = [];
        foreach (array_chunk(array_keys($productSkus), 200) as $productIds) {
            $skuIds = [];
            foreach ($productIds as $productId) $skuIds += $productSkus[$productId];
            $catalog = Db::name('store_product_attr_value')->alias('a')
                ->join('store_product p', 'p.id=a.product_id')
                ->whereIn('p.id', $productIds)->whereIn('a.id', array_keys($skuIds))
                ->where('p.is_del', 0)->where('p.is_inventory', 1)->where('a.type', 0)
                ->fieldRaw('p.id AS product_id,a.id AS sku_id,' . InventoryProductUnitPolicy::selectSql())
                ->select()->toArray();
            foreach ($catalog as $item) {
                $key = (int)$item['product_id'] . ':' . (int)$item['sku_id'];
                $unit = trim((string)$item['stock_unit']);
                if ($unit !== '') $currentUnits[$key] = $unit;
            }
        }
        foreach ($rows as &$row) {
            $key = (int)($row['product_id'] ?? 0) . ':' . (int)($row['sku_id'] ?? 0);
            if (isset($currentUnits[$key])) $row['stock_unit'] = $currentUnits[$key];
        }
        unset($row);
        return $rows;
    }
}
