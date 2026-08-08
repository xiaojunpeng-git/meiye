<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\cashier\v3\CashierV3ScopeResolver;
use think\facade\Db;

/** Read model for confirmed count documents and their immutable cost impacts. */
final class InventoryStockCountQueryServices
{
    public function list(int $storeId, int $operatorId, string $keyword, int $page = 1, int $limit = 20, bool $canViewCost = false): array
    {
        $staff = Db::name('system_store_staff')->where('id', $operatorId)->where('store_id', $storeId)->where('status', 1)->where('is_del', 0)->find();
        if (!$staff) throw new \RuntimeException('inventory_stock_count_query_scope_denied');

        $location = Db::name('inventory_location')
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('store_id', $storeId)->where('location_type', 'STORE')
            ->where('is_default', 1)->where('location_status', 'ACTIVE')->find();
        if (!$location) throw new \RuntimeException('inventory_stock_count_query_location_missing');

        $query = Db::name('inventory_stock_count_document')->alias('d')
            ->leftJoin('inventory_stock_count_line l', 'l.document_id=d.id')
            ->where('d.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('d.location_id', (int)$location['id']);
        $keyword = trim($keyword);
        if ($keyword !== '') $query->whereLike('d.count_no|d.remark', '%' . $keyword . '%');

        $count = (clone $query)->group('d.id')->count();
        $rows = $query->field([
                'd.id', 'd.count_no' => 'order_sn', 'd.document_status' => 'status_name',
                'd.business_date', 'd.recorded_at' => 'add_time', 'd.recorded_at' => 'operation_at',
                'd.operator_id' => 'admin_id',
                'COUNT(l.id)' => 'detail_count', 'SUM(ABS(l.difference_quantity_units))' => 'difference_quantity_units',
            ])
            ->group('d.id')->order('d.id desc')->page(max(1, $page), max(1, min(100, $limit)))->select()->toArray();

        $amountByDocument = $canViewCost ? $this->countImpactAmounts($storeId, (int)$location['id'], array_column($rows, 'order_sn')) : [];
        foreach ($rows as &$row) {
            // A count gain is positive and a count loss is negative. The immutable movement ledger
            // is the authority, since a loss may allocate across several FEFO batches.
            $row['change_amount_cents'] = $canViewCost ? (int)($amountByDocument[(string)$row['order_sn']] ?? 0) : null;
        }
        unset($row);

        return ['count' => $count, 'list' => $rows];
    }

    /** @param string[] $countNumbers @return array<string, int> */
    private function countImpactAmounts(int $storeId, int $locationId, array $countNumbers): array
    {
        if ($countNumbers === []) return [];
        $rows = Db::name('inventory_batch_movement_fact')
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('store_id', $storeId)->where('location_id', $locationId)
            ->where('fact_status', 'SETTLED')
            ->whereIn('source_type', ['stock_count_gain', 'stock_count_loss'])
            ->whereIn('source_id', $countNumbers)
            ->field('source_id, SUM(CASE WHEN direction = 1 THEN cost_amount_cents ELSE -cost_amount_cents END) change_amount_cents')
            ->group('source_id')->select()->toArray();
        $result = [];
        foreach ($rows as $row) $result[(string)$row['source_id']] = (int)$row['change_amount_cents'];
        return $result;
    }
}
