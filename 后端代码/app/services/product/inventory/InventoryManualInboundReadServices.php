<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\cashier\v3\CashierV3ScopeResolver;
use think\facade\Db;

/** Read-only projection of one settled V3 manual inbound document for its owning store. */
final class InventoryManualInboundReadServices
{
    public function detail(int $storeId, int $operatorId, string $sourceId, bool $canViewCost): array
    {
        $sourceId = trim($sourceId);
        if ($sourceId === '' || strlen($sourceId) > 96) {
            throw new \InvalidArgumentException('inventory_manual_inbound_detail_invalid');
        }

        $location = $this->defaultLocation($storeId, $operatorId);
        $facts = Db::name('inventory_batch_movement_fact')->alias('f')
            ->join('inventory_batch b', 'b.id=f.batch_id')
            ->join('inventory_stock s', 's.id=f.stock_id')
            ->leftJoin('inventory_business_document_no n', 'n.tenant_id=f.tenant_id AND n.source_type=f.source_type AND n.source_id=f.source_id')
            ->where('f.tenant_id', (string)$location['tenant_id'])
            ->where('f.store_id', $storeId)
            ->where('f.location_id', (int)$location['id'])
            ->where('f.source_type', 'manual_inbound')
            ->where('f.source_id', $sourceId)
            ->where('f.fact_status', 'SETTLED')
            ->fieldRaw("COALESCE(n.document_no, f.source_id) AS order_sn, f.business_date, f.occurred_at, f.recorded_at, b.product_name_snapshot AS product_name, b.sku_name_snapshot AS sku_name, b.barcode_snapshot AS barcode, b.batch_no, b.manufactured_date, b.expire_date, s.stock_unit, s.quantity_scale, f.quantity_units, f.unit_cost_cents, f.cost_amount_cents")
            ->order('f.id asc')
            ->select()
            ->toArray();
        if (!$facts) {
            throw new \InvalidArgumentException('inventory_manual_inbound_detail_missing');
        }

        foreach ($facts as &$fact) {
            $fact['quantity'] = $this->quantity((int)$fact['quantity_units'], (int)$fact['quantity_scale']);
            if (!$canViewCost) {
                $fact['unit_cost_cents'] = null;
                $fact['cost_amount_cents'] = null;
            }
        }
        unset($fact);
        $reversal = (new InventoryManualDocumentReversalProjectionServices())->settledIndex(
            (string)$location['tenant_id'], InventoryManualDocumentReversalServices::INBOUND, [$sourceId], [(int)$location['id']]
        );
        $voided = $reversal[$sourceId] ?? null;

        return [
            'document' => [
                'order_sn' => (string)$facts[0]['order_sn'],
                'business_date' => (string)$facts[0]['business_date'],
                'operation_at' => (int)($facts[0]['occurred_at'] ?: $facts[0]['recorded_at']),
                'recorded_at' => (int)$facts[0]['recorded_at'],
                'location_name' => (string)($location['location_name'] ?? '当前门店默认仓'),
                'status_name' => $voided ? '已作废' : '已完成',
                'can_void' => $voided === null,
                'void_reason' => $voided ? (string)$voided['reason'] : '',
                'voided_at' => $voided ? (int)$voided['settled_at'] : 0,
            ],
            'lines' => $facts,
        ];
    }

    /** Effective outbound movements for batches originating in one inbound document. */
    public function outboundDetails(int $storeId, int $operatorId, string $sourceId, bool $canViewCost): array
    {
        return (new InventoryInboundOutboundTraceReadServices())->detail(
            $this->defaultLocation($storeId, $operatorId), $storeId, $sourceId, $canViewCost
        );
    }

    private function defaultLocation(int $storeId, int $operatorId): array
    {
        $staff = Db::name('system_store_staff')
            ->where('id', $operatorId)
            ->where('store_id', $storeId)
            ->where('status', 1)
            ->where('is_del', 0)
            ->field('id')
            ->find();
        $locations = Db::name('inventory_location')
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('store_id', $storeId)
            ->where('location_type', 'STORE')
            ->where('is_default', 1)
            ->where('location_status', 'ACTIVE')
            ->field('id,tenant_id,location_name')
            ->limit(2)
            ->select()
            ->toArray();
        if (!$staff || count($locations) !== 1) {
            throw new \RuntimeException('inventory_manual_inbound_detail_scope_denied');
        }
        return (array)$locations[0];
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
}
