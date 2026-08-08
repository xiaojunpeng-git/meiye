<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use think\facade\Db;

/**
 * Read-only trace of effective outbound movements for batches created by one
 * manual inbound document. The caller resolves the authenticated location.
 */
final class InventoryInboundOutboundTraceReadServices
{
    private const OUTBOUND_TYPES = [
        'manual_outbound' => '手工出库',
        'batch_transfer_out' => '仓库调拨出库',
        'cross_transfer_out' => '门店调拨出库',
        'salon_usage_issue' => '院装领用',
        'completion_batch' => '项目耗材核销',
    ];

    public function detail(array $location, int $storeId, string $sourceId, bool $canViewCost): array
    {
        $sourceId = trim($sourceId);
        if ($sourceId === '' || strlen($sourceId) > 96) {
            throw new \InvalidArgumentException('inventory_inbound_outbound_trace_invalid');
        }

        $base = Db::name('inventory_batch_movement_fact')->alias('f')
            ->join('inventory_batch b', 'b.id=f.batch_id')
            ->leftJoin('inventory_business_document_no n', 'n.tenant_id=f.tenant_id AND n.source_type=f.source_type AND n.source_id=f.source_id')
            ->where('f.tenant_id', (string)$location['tenant_id'])
            ->where('f.store_id', $storeId)
            ->where('f.location_id', (int)$location['id'])
            ->where('f.fact_status', 'SETTLED')
            ->where('f.source_type', 'manual_inbound')
            ->where('f.source_id', $sourceId);
        $inbound = (clone $base)
            ->fieldRaw('f.batch_id, b.origin_batch_id, n.document_no')
            ->select()
            ->toArray();
        if (!$inbound) {
            throw new \InvalidArgumentException('inventory_inbound_outbound_trace_missing');
        }

        $batchIds = array_values(array_unique(array_map(static fn (array $row): int => (int)$row['batch_id'], $inbound)));
        $originIds = array_values(array_unique(array_filter(array_map(static fn (array $row): int => (int)$row['origin_batch_id'], $inbound))));
        $tablePrefix = (string)(config('database.connections.mysql.prefix') ?: 'eb_');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $tablePrefix)) {
            throw new \RuntimeException('inventory_database_prefix_invalid');
        }
        $movementFactTable = '`' . $tablePrefix . 'inventory_batch_movement_fact`';

        $facts = Db::name('inventory_batch_movement_fact')->alias('f')
            ->join('inventory_batch b', 'b.id=f.batch_id')
            ->join('inventory_stock s', 's.id=f.stock_id')
            ->leftJoin('store_product p', 'p.id=s.consumable_product_id')
            ->leftJoin('inventory_business_document_no n', 'n.tenant_id=f.tenant_id AND n.source_type=f.source_type AND n.source_id=f.source_id')
            ->where('f.tenant_id', (string)$location['tenant_id'])
            ->where('f.store_id', $storeId)
            ->where('f.location_id', (int)$location['id'])
            ->where('f.fact_status', 'SETTLED')
            ->where('f.direction', -1)
            ->whereIn('f.source_type', array_keys(self::OUTBOUND_TYPES))
            ->whereRaw($this->batchLineagePredicate($batchIds, $originIds))
            // A reversed outbound remains in the immutable ledger but is no
            // longer an effective outbound for this operational projection.
            ->whereRaw("NOT EXISTS (SELECT 1 FROM {$movementFactTable} reversed WHERE reversed.tenant_id=f.tenant_id AND reversed.reversal_of=f.id AND reversed.fact_status='SETTLED')")
            ->fieldRaw("f.id AS movement_fact_id, n.document_no, f.source_type, f.business_date, f.occurred_at, f.settled_at, f.recorded_at, b.batch_no, b.product_name_snapshot AS product_name, b.sku_name_snapshot AS sku_name, COALESCE(NULLIF(s.stock_unit, ''), NULLIF(p.unit_name, ''), '件') AS stock_unit, s.quantity_scale, f.quantity_units, f.unit_cost_cents, f.cost_amount_cents")
            ->order('f.occurred_at asc')
            ->order('f.id asc')
            ->select()
            ->toArray();

        foreach ($facts as &$fact) {
            $fact['order_sn'] = $this->documentNumber($fact['document_no'] ?? null, '历史出库记录');
            unset($fact['document_no']);
            $fact['outbound_type_name'] = self::OUTBOUND_TYPES[(string)$fact['source_type']] ?? '库存扣减';
            $fact['quantity'] = $this->quantity((int)$fact['quantity_units'], (int)$fact['quantity_scale']);
            $fact['operation_at'] = (int)($fact['occurred_at'] ?: $fact['recorded_at']);
            if (!$canViewCost) {
                $fact['unit_cost_cents'] = null;
                $fact['cost_amount_cents'] = null;
            }
        }
        unset($fact);

        return [
            'document' => [
                'order_sn' => $this->documentNumber($inbound[0]['document_no'] ?? null, '历史入库记录'),
                'location_name' => (string)($location['location_name'] ?? ''),
                'trace_scope_name' => '当前库存仓',
                'outbound_count' => count($facts),
            ],
            'lines' => $facts,
        ];
    }

    private function documentNumber($documentNo, string $fallback): string
    {
        $documentNo = trim((string)$documentNo);
        return $documentNo !== '' ? $documentNo : $fallback;
    }

    private function quantity(int $units, int $scale): string
    {
        $scale = max(0, min(4, $scale));
        if ($scale === 0) {
            return (string)$units;
        }
        $digits = str_pad((string)$units, $scale + 1, '0', STR_PAD_LEFT);
        return rtrim(rtrim(substr($digits, 0, -$scale) . '.' . substr($digits, -$scale), '0'), '.');
    }

    private function batchLineagePredicate(array $batchIds, array $originIds): string
    {
        $batchIds = array_values(array_filter(array_map('intval', $batchIds), static fn (int $id): bool => $id > 0));
        $originIds = array_values(array_filter(array_map('intval', $originIds), static fn (int $id): bool => $id > 0));
        if (!$batchIds) {
            throw new \InvalidArgumentException('inventory_inbound_outbound_trace_missing');
        }
        $parts = ['b.id IN (' . implode(',', $batchIds) . ')'];
        if ($originIds) {
            $parts[] = 'b.origin_batch_id IN (' . implode(',', $originIds) . ')';
        }
        return '(' . implode(' OR ', $parts) . ')';
    }
}
