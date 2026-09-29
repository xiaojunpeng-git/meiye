<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use think\facade\Db;

/** 将已确认盘点单的不可变明细投影为门店端和平台端共用的只读详情。 */
final class InventoryStockCountDetailProjectionServices
{
    /**
     * 调用方必须先按已认证主体限定单据及仓库；本类只读取该单明细，
     * 商品目录关联仅用于补充当前展示名称，不改变历史盘点数量或成本事实。
     */
    public function project(array $document, array $location, bool $canViewCost): array
    {
        $lines = Db::name('inventory_stock_count_line')->alias('l')
            ->leftJoin('inventory_stock s', 's.id=l.stock_id')
            ->leftJoin('store_product p', 'p.id=l.product_id')
            ->leftJoin('store_product_attr_value a', 'a.id=l.sku_id')
            ->where('l.document_id', (int)$document['id'])
            ->field('l.id,l.line_no,l.product_id,l.sku_id,l.sku_unique,l.quantity_scale,l.book_quantity_units,l.counted_quantity_units,l.difference_quantity_units,l.surplus_batch_no,l.surplus_unit_cost_cents,l.surplus_manufactured_date,l.surplus_expire_date,p.store_name product_name,p.code product_code,a.suk sku_name,a.bar_code barcode,s.stock_unit')
            ->order('l.line_no asc')->select()->toArray();

        foreach ($lines as &$line) {
            $scale = (int)$line['quantity_scale'];
            $line['book_quantity'] = $this->quantity((int)$line['book_quantity_units'], $scale);
            $line['counted_quantity'] = $this->quantity((int)$line['counted_quantity_units'], $scale);
            $line['difference_quantity'] = $this->signedQuantity((int)$line['difference_quantity_units'], $scale);
            $line['product_name'] = (string)($line['product_name'] ?: '商品已停用');
            $line['sku_name'] = (string)($line['sku_name'] ?: '默认规格');
            $line['stock_unit'] = (string)($line['stock_unit'] ?: '');
            // 只有盘盈明细存在新批次；无成本权限时保留批次追溯但隐藏单价。
            if ((int)$line['difference_quantity_units'] <= 0) {
                $line['surplus_batch_no'] = '';
                $line['surplus_unit_cost_cents'] = null;
                $line['surplus_manufactured_date'] = null;
                $line['surplus_expire_date'] = null;
            } elseif (!$canViewCost) {
                $line['surplus_unit_cost_cents'] = null;
            }
        }
        unset($line);

        return [
            'document' => [
                'id' => (int)$document['id'],
                'order_sn' => (string)$document['count_no'],
                'business_date' => (string)$document['business_date'],
                'count_date' => InventoryStockCountDate::fromConfirmedAt((int)$document['confirmed_at']),
                'recorded_at' => (int)$document['recorded_at'],
                'operation_at' => (int)$document['confirmed_at'],
                'location_name' => (string)($location['location_name'] ?? ((string)($location['location_type'] ?? '') === 'HQ' ? '总部仓' : '库存仓')),
                'status_name' => ['CONFIRMED' => '已确认', 'CANCELLED' => '已取消'][(string)$document['document_status']] ?? (string)$document['document_status'],
                'remark' => (string)$document['remark'],
                'detail_count' => count($lines),
                'can_view_cost' => $canViewCost,
            ],
            'lines' => $lines,
        ];
    }

    private function quantity(int $units, int $scale): string
    {
        $scale = max(0, min(4, $scale));
        if ($scale === 0) return (string)$units;
        $digits = str_pad((string)abs($units), $scale + 1, '0', STR_PAD_LEFT);
        $value = substr($digits, 0, -$scale) . '.' . substr($digits, -$scale);
        return rtrim(rtrim($value, '0'), '.');
    }

    private function signedQuantity(int $units, int $scale): string
    {
        return ($units > 0 ? '+' : ($units < 0 ? '-' : '')) . $this->quantity(abs($units), $scale);
    }
}
