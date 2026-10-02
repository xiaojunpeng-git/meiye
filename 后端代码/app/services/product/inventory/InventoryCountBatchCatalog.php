<?php
declare(strict_types=1);
namespace app\services\product\inventory;

use app\services\product\inventory\query\InventoryBatchStockQueryContract;
use think\facade\Db;

/** 给已授权的目录页补充当前批次；不读取其他仓，不改变库存，成本权限由调用方强制注入。 */
final class InventoryCountBatchCatalog
{
    public function attach(array $rows, string $tenantId, int $locationId, bool $canViewCost): array
    {
        if (!$rows) return [];
        $batches = Db::name('inventory_batch')->alias('b')->join('inventory_stock s', 's.id=b.stock_id')
            ->where('s.tenant_id', $tenantId)->where('s.location_id', $locationId)->where('s.stock_status', 'GOOD')
            ->whereIn('s.sku_id', array_column($rows, 'sku_id'))->where('b.batch_status', 'ACTIVE')
            ->where('b.available_quantity_units', '>', 0)->order('b.id asc')
            ->field('s.consumable_product_id,s.sku_id,s.quantity_scale,b.id,b.batch_no,b.available_quantity_units,b.unit_cost_cents,b.manufactured_date,b.expire_date')
            ->limit(10001)->select()->toArray();
        if (count($batches) > 10000) throw new \RuntimeException('inventory_query_source_window_too_large');
        $grouped = [];
        foreach ($batches as $batch) {
            $grouped[(int)$batch['consumable_product_id'] . ':' . (int)$batch['sku_id']][] = [
                'batch_id' => (int)$batch['id'], 'batch_no' => (string)$batch['batch_no'],
                'quantity' => InventoryBatchStockQueryContract::unitsToDecimal((int)$batch['available_quantity_units'], (int)$batch['quantity_scale']),
                'unit_cost' => $canViewCost ? InventoryBatchStockQueryContract::centsToAmount((int)$batch['unit_cost_cents']) : null,
                'manufactured_date' => (string)($batch['manufactured_date'] ?? ''), 'expire_date' => (string)($batch['expire_date'] ?? ''),
            ];
        }
        foreach ($rows as &$row) $row['count_batches'] = $grouped[(int)$row['product_id'] . ':' . (int)$row['sku_id']] ?? [];
        unset($row);
        return $rows;
    }
}
