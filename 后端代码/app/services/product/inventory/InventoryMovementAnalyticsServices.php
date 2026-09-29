<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\cashier\v3\CashierV3ScopeResolver;
use think\facade\Db;

/** Read-only item statistics built from immutable batch movement facts. */
final class InventoryMovementAnalyticsServices
{
    public function list(int $storeId, int $operatorId, string $kind, string $from, string $to, bool $canViewCost = false): array
    {
        $locationIds = $this->locationIds($storeId, $operatorId);
        return $this->listForLocations($locationIds, $kind, $from, $to, $canViewCost);
    }

    /** 使用已认证的门店/总部仓范围；默认逐日返回，周期模式在事实层跨日汇总且不改变原列表调用方。 */
    public function listForLocations(array $locationIds, string $kind, string $from, string $to, bool $canViewCost = false, bool $groupByPeriod = false): array
    {
        $locationIds = array_values(array_unique(array_filter(array_map('intval', $locationIds))));
        if (!$locationIds) throw new \RuntimeException('inventory_movement_statistics_scope_denied');
        $direction = ['inbound' => 1, 'outbound' => -1][$kind] ?? null;
        if ($direction === null) throw new \InvalidArgumentException('inventory_movement_statistics_kind_invalid');
        $from = $this->date($from); $to = $this->date($to);
        if ($from > $to) throw new \InvalidArgumentException('inventory_movement_statistics_date_range_invalid');
        // 旧列表仍按日分组；统一统计页传入已验证周期后，直接在事实层跨日合并，避免客户端相加造成单据数漂移。
        $dimensions = 'f.source_type,s.consumable_product_id,s.sku_id,s.stock_unit,s.quantity_scale,b.product_name_snapshot,b.sku_name_snapshot';
        $fields = [
            'f.source_type', 's.consumable_product_id' => 'product_id', 's.sku_id', 's.stock_unit', 's.quantity_scale',
            'b.product_name_snapshot' => 'product_name', 'b.sku_name_snapshot' => 'sku_name',
            'COUNT(DISTINCT f.source_id)' => 'document_count', 'COUNT(f.id)' => 'movement_count',
            'SUM(f.quantity_units)' => 'quantity_units', 'SUM(f.cost_amount_cents)' => 'cost_amount_cents',
        ];
        if (!$groupByPeriod) array_unshift($fields, 'f.business_date');
        $query = Db::name('inventory_batch_movement_fact')->alias('f')
            ->join('inventory_batch b', 'b.id=f.batch_id')->join('inventory_stock s', 's.id=f.stock_id')
            ->where('f.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)->whereIn('f.location_id', $locationIds)
            ->leftJoin('cashier_v3_presale_claim pc', 'pc.tenant_id=f.tenant_id AND pc.claim_id=f.source_id AND f.source_type=\'presale_claim_outbound\'')
            ->where('f.fact_status', 'SETTLED')->where('f.direction', $direction)->whereBetween('f.business_date', [$from, $to])
            ->where(function ($query): void {
                $query->where('f.source_type', '<>', 'presale_claim_outbound')
                    ->whereOr('pc.claim_status', '<>', 'VOIDED')
                    ->whereOr(function ($or): void {
                        $or->whereNull('pc.claim_status');
                    });
            })
            ->field($fields);
        $rows = $query->group(($groupByPeriod ? '' : 'f.business_date,') . $dimensions)
            ->order($groupByPeriod ? 'product_id asc,sku_id asc' : 'f.business_date desc,product_id asc,sku_id asc')->select()->toArray();
        foreach ($rows as &$row) {
            // 周期行只用终点日期供旧查询契约识别；真实业务日期已经在 SQL whereBetween 内精确筛选。
            if ($groupByPeriod) $row['business_date'] = $to;
            $scale = max(0, min(4, (int)$row['quantity_scale']));
            $row['quantity'] = $this->decimal((int)$row['quantity_units'], $scale);
            $row['source_type_name'] = $this->sourceTypeName((string)$row['source_type']);
            if (!$canViewCost) $row['cost_amount_cents'] = null;
            $row['cost_amount'] = $canViewCost ? $this->amount((int)$row['cost_amount_cents']) : null;
        }
        unset($row);
        return ['list' => $rows, 'count' => count($rows), 'from' => $from, 'to' => $to];
    }

    /** @return int[] */
    private function locationIds(int $storeId, int $operatorId): array
    {
        $staff = Db::name('system_store_staff')->where('id', $operatorId)->where('store_id', $storeId)->where('status', 1)->where('is_del', 0)->find();
        $locations = Db::name('inventory_location')->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)->where('store_id', $storeId)
            ->where('location_type', 'STORE')->where('location_status', 'ACTIVE')->field('id')->select()->toArray();
        if (!$staff || !$locations) throw new \RuntimeException('inventory_movement_statistics_scope_denied');
        return array_map(static fn(array $location): int => (int)$location['id'], $locations);
    }

    private function date(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        if (!$date || $date->format('Y-m-d') !== trim($value)) throw new \InvalidArgumentException('inventory_movement_statistics_date_invalid');
        return $date->format('Y-m-d');
    }

    private function decimal(int $units, int $scale): string
    {
        // 仅小数位允许去尾零；整数 100 不得被 rtrim 截成 1。
        if ($scale === 0) return (string)$units;
        return rtrim(rtrim(number_format($units / (10 ** $scale), $scale, '.', ''), '0'), '.');
    }

    private function amount(int $cents): string { return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT); }

    private function sourceTypeName(string $type): string
    {
        // 业务类型代码是库存事实的稳定来源标识；仅在查询展示层翻译，不改写事实、筛选键或汇总口径。
        return [
            'manual_inbound' => '手工入库', 'manual_outbound' => '手工出库',
            'cashier_sale' => '收银销售出库',
            'stock_count_gain' => '盘盈', 'stock_count_loss' => '盘亏',
            'batch_transfer_in' => '调拨入库', 'batch_transfer_out' => '调拨出库',
            'salon_usage_issue' => '院装领用', 'salon_usage_return' => '院装退回',
            'completion_batch' => '项目耗材核销', 'presale_claim_outbound' => '预售领用出库',
        ][$type] ?? $type;
    }
}
