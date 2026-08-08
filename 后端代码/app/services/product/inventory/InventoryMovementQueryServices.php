<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\cashier\v3\CashierV3ScopeResolver;
use think\facade\Db;

/**
 * Read model for the immutable batch movement ledger.  It deliberately does
 * not read the legacy inbound/outbound tables, so a newly saved V3 document
 * and its list view always use the same source of truth.
 */
final class InventoryMovementQueryServices
{
    public function list(int $storeId, int $operatorId, string $kind, string $keyword, int $page = 1, int $limit = 20, bool $canViewCost = false): array
    {
        $location = $this->defaultLocation($storeId, $operatorId);
        $kind = trim($kind);
        if (!in_array($kind, ['inbound', 'outbound', 'movement'], true)) {
            throw new \InvalidArgumentException('inventory_movement_query_kind_invalid');
        }
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        $keyword = trim($keyword);

        if ($kind === 'movement') {
            return $this->movementRows($this->activeStoreLocationIds($storeId), $keyword, $page, $limit, $canViewCost);
        }
        return $this->documentRows((int)$location['id'], $kind, $keyword, $page, $limit, $canViewCost);
    }

    private function defaultLocation(int $storeId, int $operatorId): array
    {
        $staff = Db::name('system_store_staff')
            ->where('id', $operatorId)->where('store_id', $storeId)
            ->where('status', 1)->where('is_del', 0)->find();
        $locations = Db::name('inventory_location')
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('store_id', $storeId)->where('location_type', 'STORE')
            ->where('is_default', 1)->where('location_status', 'ACTIVE')
            ->limit(2)->select()->toArray();
        if (!$staff || count($locations) !== 1) {
            throw new \RuntimeException('inventory_movement_query_scope_denied');
        }
        return (array)$locations[0];
    }

    private function documentRows(int $locationId, string $kind, string $keyword, int $page, int $limit, bool $canViewCost): array
    {
        $types = $kind === 'inbound' ? ['manual_inbound'] : ['manual_outbound'];
        $base = Db::name('inventory_batch_movement_fact')->alias('f')
            ->leftJoin('inventory_batch b', 'b.id=f.batch_id')
            ->leftJoin('inventory_business_document_no n', 'n.tenant_id=f.tenant_id AND n.source_type=f.source_type AND n.source_id=f.source_id')
            ->where('f.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('f.location_id', $locationId)->where('f.fact_status', 'SETTLED')
            ->whereIn('f.source_type', $types);
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $base->whereLike('f.source_id|n.document_no|b.product_name_snapshot|b.sku_name_snapshot|b.batch_no', $like);
        }
        $rows = $base->field([
                'f.source_id', 'COALESCE(n.document_no,f.source_id)' => 'order_sn', 'f.source_type', 'f.business_date',
                'MAX(f.recorded_at)' => 'add_time',
                'MAX(COALESCE(NULLIF(f.occurred_at, 0), f.recorded_at))' => 'operation_at',
                'COUNT(f.id)' => 'detail_count',
                'SUM(f.quantity_units)' => 'quantity_units', 'SUM(f.cost_amount_cents)' => 'cost_amount_cents',
                'GROUP_CONCAT(DISTINCT CONCAT(IFNULL(b.product_name_snapshot, \'商品\'), \' / \', IFNULL(b.sku_name_snapshot, \'默认规格\')) SEPARATOR \'、\')' => 'product_summary',
            ])
            ->group('f.source_id,n.document_no,f.source_type,f.business_date')
            ->order('add_time desc')->page($page, $limit)->select()->toArray();
        $count = count((clone $base)->field('f.source_id')->group('f.source_id,n.document_no,f.source_type,f.business_date')->select()->toArray());
        foreach ($rows as &$row) {
            if (!$canViewCost) $row['cost_amount_cents'] = null;
            $row['order_type_name'] = $kind === 'inbound' ? '手工入库' : '手工出库';
            $row['location_name'] = '当前门店默认仓';
        }
        unset($row);
        $rows = (new InventoryManualDocumentReversalProjectionServices())->apply(
            $rows,
            CashierV3ScopeResolver::TENANT_SCOPE_ID,
            $types[0],
            [$locationId]
        );
        return ['count' => $count, 'list' => $rows];
    }

    /** @param int[] $locationIds */
    private function movementRows(array $locationIds, string $keyword, int $page, int $limit, bool $canViewCost): array
    {
        if (!$locationIds) throw new \RuntimeException('inventory_movement_query_scope_denied');
        $base = Db::name('inventory_batch_movement_fact')->alias('f')
            ->leftJoin('inventory_batch b', 'b.id=f.batch_id')
            ->leftJoin('inventory_stock s', 's.id=f.stock_id')
            ->leftJoin('inventory_location l', 'l.id=f.location_id')
            ->leftJoin('inventory_business_document_no n', 'n.tenant_id=f.tenant_id AND n.source_type=f.source_type AND n.source_id=f.source_id')
            ->where('f.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('f.location_id', $locationIds)->where('f.fact_status', 'SETTLED');
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $base->whereLike('f.source_id|n.document_no|b.product_name_snapshot|b.sku_name_snapshot|b.batch_no', $like);
        }
        $count = (clone $base)->count();
        $rows = $base->field([
                'f.id', 'f.source_id', 'COALESCE(n.document_no,f.source_id)' => 'order_sn', 'f.source_type', 'f.business_date',
                'f.occurred_at', 'f.recorded_at',
                'f.direction', 'f.quantity_units', 'f.cost_amount_cents', 's.quantity_scale',
                'b.product_name_snapshot' => 'product_name', 'b.sku_name_snapshot' => 'sku_name',
                'b.batch_no', 'l.location_name', 'l.location_code',
            ])->order('f.id desc')->page($page, $limit)->select()->toArray();
        foreach ($rows as &$row) {
            if (!$canViewCost) $row['cost_amount_cents'] = null;
            $row['movement_type_name'] = $this->movementLabel((string)$row['source_type']);
            $row['operation_at'] = (int)$row['occurred_at'] > 0 ? (int)$row['occurred_at'] : (int)$row['recorded_at'];
            $scale = max(0, min(4, (int)($row['quantity_scale'] ?? 0)));
            $quantity = (int)$row['quantity_units'] / (10 ** $scale);
            $row['quantity_display'] = ((int)$row['direction'] > 0 ? '+' : '-') . rtrim(rtrim(number_format($quantity, $scale, '.', ''), '0'), '.');
        }
        unset($row);
        return ['count' => $count, 'list' => $rows];
    }

    private function movementLabel(string $sourceType): string
    {
        return [
            'manual_inbound' => '手工入库', 'manual_outbound' => '手工出库',
            'batch_transfer_in' => '调拨入库', 'batch_transfer_out' => '调拨出库',
            'stock_count_gain' => '盘盈', 'stock_count_loss' => '盘亏',
            'salon_usage_issue' => '院装领用', 'salon_usage_return' => '院装退回',
            'completion_batch' => '项目耗材核销',
        ][$sourceType] ?? $sourceType;
    }

    /** @return int[] */
    private function activeStoreLocationIds(int $storeId): array
    {
        return array_map('intval', Db::name('inventory_location')
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('store_id', $storeId)->where('location_type', 'STORE')
            ->where('location_status', 'ACTIVE')->column('id'));
    }
}
