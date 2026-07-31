<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\cashier\v3\CashierV3ScopeResolver;
use think\facade\Db;

/** Read model for the V3 request authority; legacy request drafts are excluded. */
final class InventoryStockRequestQueryServices
{
    public function list(int $storeId, int $operatorId, string $keyword, int $page = 1, int $limit = 20, bool $canViewCost = false): array
    {
        $staff = Db::name('system_store_staff')->where('id', $operatorId)->where('store_id', $storeId)->where('status', 1)->where('is_del', 0)->find();
        $location = Db::name('inventory_location')->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)->where('store_id', $storeId)->where('location_type', 'STORE')->where('is_default', 1)->where('location_status', 'ACTIVE')->find();
        if (!$staff || !$location) throw new \RuntimeException('inventory_stock_request_query_scope_denied');
        $query = Db::name('inventory_stock_request_document')->alias('d')->leftJoin('inventory_stock_request_line l', 'l.document_id=d.id')
            ->where('d.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)->where('d.store_id', $storeId)->where('d.location_id', (int)$location['id']);
        $keyword = trim($keyword);
        if ($keyword !== '') $query->whereLike('d.request_no|d.remark', '%' . $keyword . '%');
        $count = (clone $query)->group('d.id')->count();
        $list = $query->field('d.id,d.request_no order_sn,d.document_status status_name,d.business_date request_date,d.recorded_at add_time,COUNT(l.id) detail_count,SUM((l.requested_quantity_units*l.reference_unit_cost_cents)/POW(10,l.quantity_scale)) estimated_amount_cents')
            ->group('d.id')->order('d.id desc')->page(max(1, $page), max(1, min(100, $limit)))->select()->toArray();
        if (!$canViewCost) foreach ($list as &$row) $row['estimated_amount_cents'] = null;
        unset($row);
        return ['count' => $count, 'list' => $list];
    }
}
