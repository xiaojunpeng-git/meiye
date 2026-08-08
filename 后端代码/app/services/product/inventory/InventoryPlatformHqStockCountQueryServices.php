<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use think\facade\Db;

/** Read-only projection of one confirmed headquarters stock count document. */
final class InventoryPlatformHqStockCountQueryServices
{
    /**
     * The count document and its lines are immutable snapshots.  The catalog
     * joins only provide the current display name, specification and unit for
     * a line that did not persist those descriptive fields at confirmation.
     */
    public function detail(array $adminInfo, int $locationId, int $documentId): array
    {
        if ($documentId <= 0) throw new \InvalidArgumentException('inventory_hq_count_detail_invalid');

        $resolved = (new InventoryHqLocationServices())->readableLocation($adminInfo, $locationId);
        $access = (array)$resolved['access'];
        $location = (array)$resolved['location'];
        $document = Db::name('inventory_stock_count_document')
            ->where('tenant_id', (string)$location['tenant_id'])
            ->where('location_id', (int)$location['id'])
            ->where('id', $documentId)
            ->find();
        if (!$document) throw new \InvalidArgumentException('inventory_hq_count_detail_missing');

        $lines = Db::name('inventory_stock_count_line')->alias('l')
            ->leftJoin('inventory_stock s', 's.id=l.stock_id')
            ->leftJoin('store_product p', 'p.id=l.product_id')
            ->leftJoin('store_product_attr_value a', 'a.id=l.sku_id')
            ->where('l.document_id', (int)$document['id'])
            ->field('l.id,l.line_no,l.product_id,l.sku_id,l.sku_unique,l.quantity_scale,l.book_quantity_units,l.counted_quantity_units,l.difference_quantity_units,l.surplus_batch_no,l.surplus_unit_cost_cents,l.surplus_manufactured_date,l.surplus_expire_date,p.store_name product_name,p.code product_code,a.suk sku_name,a.bar_code barcode,s.stock_unit')
            ->order('l.line_no asc')->select()->toArray();

        $canViewCost = in_array('inventory.cost.view', (array)($access['features'] ?? []), true);
        foreach ($lines as &$line) {
            $line['book_quantity'] = $this->quantity((int)$line['book_quantity_units'], (int)$line['quantity_scale']);
            $line['counted_quantity'] = $this->quantity((int)$line['counted_quantity_units'], (int)$line['quantity_scale']);
            $line['difference_quantity'] = $this->signedQuantity((int)$line['difference_quantity_units'], (int)$line['quantity_scale']);
            $line['product_name'] = (string)($line['product_name'] ?: '商品已停用');
            $line['sku_name'] = (string)($line['sku_name'] ?: '默认规格');
            $line['stock_unit'] = (string)($line['stock_unit'] ?: '');
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
                'recorded_at' => (int)$document['recorded_at'],
                'operation_at' => (int)$document['confirmed_at'],
                'location_name' => (string)($location['location_name'] ?? '总部仓'),
                'status_name' => $this->statusName((string)$document['document_status']),
                'remark' => (string)$document['remark'],
                'detail_count' => count($lines),
                'can_view_cost' => $canViewCost,
            ],
            'lines' => $lines,
        ];
    }

    private function statusName(string $status): string
    {
        return ['CONFIRMED' => '已确认', 'CANCELLED' => '已取消'][$status] ?? $status;
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
