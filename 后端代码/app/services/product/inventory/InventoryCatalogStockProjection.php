<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\product\inventory\query\InventoryBatchStockQueryContract;
use think\facade\Db;

/** 仅装饰已获授权仓的目录页：读取批次库存体系的 SKU 良品总余额，不泄露别仓数量。 */
final class InventoryCatalogStockProjection
{
    /** 调用方先完成仓权限校验；只补传入目录页的 available_quantity，零库存返回字符串 0。 */
    public function decorate(string $tenantId, int $locationId, array $rows): array
    {
        if (!$rows) return [];
        if ($tenantId === '' || $locationId <= 0) throw new \RuntimeException('inventory_catalog_source_scope_denied');
        $balances = Db::name('inventory_stock')->where('tenant_id', $tenantId)->where('location_id', $locationId)
            ->where('stock_status', 'GOOD')->whereIn('consumable_product_id', array_column($rows, 'product_id'))
            ->whereIn('sku_id', array_column($rows, 'sku_id'))
            ->field('consumable_product_id,sku_id,available_quantity_units,quantity_scale')->select()->toArray();
        $quantities = [];
        foreach ($balances as $balance) {
            $quantities[(int)$balance['consumable_product_id'] . ':' . (int)$balance['sku_id']] =
                InventoryBatchStockQueryContract::unitsToDecimal((int)$balance['available_quantity_units'], (int)$balance['quantity_scale']);
        }
        foreach ($rows as &$row) $row['available_quantity'] = $quantities[(int)$row['product_id'] . ':' . (int)$row['sku_id']] ?? '0';
        unset($row);
        return $rows;
    }
}
