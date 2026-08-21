<?php

declare(strict_types=1);

namespace app\services\report;

use think\facade\Db;

/**
 * 商品看板统一读取服务。
 *
 * 经营金额复用集团看板的统一销售/消耗事实；库存和临期只读取库存批次
 * movement fact。浏览器不参与任何指标计算，成本权限不足时返回 null。
 */
final class ProductManagementDashboardServices
{
    public const DASHBOARD_CODE = 'product_management_dashboard';
    public const METRIC_VERSION = 'product-management-dashboard-facts-v1';

    public function dashboard(array $context, array $input): array
    {
        $scope = $this->scope($context, $input);
        $range = $this->range($input);
        $categories = $this->categories();
        $categoryId = max(0, (int)($input['category_id'] ?? 0));
        $categoryIds = $categoryId > 0 ? $this->descendants($categoryId, $categories['children']) : [];
        if ($categoryId > 0 && $categoryIds === []) throw new \InvalidArgumentException('商品分类不存在或已停用');
        $productType = trim((string)($input['product_type'] ?? 'all'));
        if (!in_array($productType, ['all', 'project', 'product'], true)) throw new \InvalidArgumentException('商品类型无效');

        $periods = [
            'current' => $range,
            'yoy' => $this->shiftRange($range, -1, 'year'),
            'mom' => $this->previousRange($range),
        ];
        $group = [];
        foreach ($periods as $key => $period) {
            $payload = (new GroupManagementDashboardServices())->dashboard($scope, [
                'start_date' => $period['start'], 'end_date' => $period['end'],
                'category_id' => $categoryId, 'store_ids' => implode(',', $scope['store_ids']),
                // The product dashboard only needs the three summary cards.
                // Avoid retaining each full group-dashboard projection
                // (trend, targets, sources and rankings) for all periods.
                'summary_only' => true,
            ]);
            $group[$key] = [
                'cards' => (array)($payload['cards'] ?? []),
                'aggregation_caught_up' => (bool)($payload['aggregation_caught_up'] ?? false),
            ];
            unset($payload);
        }
        $summary = $this->summary($group, $periods, $scope, $range, $categoryIds);
        $profit = $this->profitCategories($categories, $scope, $range, $categoryIds, $productType);
        $inventory = $this->inventory($scope, $categories, $categoryId);
        $expiry = $this->expiry($scope, $categories, $categoryId);

        return [
            'dashboard_code' => self::DASHBOARD_CODE,
            'title' => '商品看板',
            'scope' => ['store_ids' => $scope['store_ids'], 'label' => '当前权限范围'],
            'filter_schema' => [
                'periods' => ['today', 'month', 'year', 'custom'],
                'categories' => array_map(static fn(array $row): array => ['id' => (int)$row['id'], 'name' => (string)$row['name']], $categories['roots']),
                'product_types' => [['id' => 'all', 'name' => '全部'], ['id' => 'project', 'name' => '项目'], ['id' => 'product', 'name' => '产品']],
            ],
            'summary' => $summary,
            'profit_categories' => $profit,
            'product_inventory_categories' => $inventory,
            'expiry_alerts' => $expiry,
            'data_as_of' => date('Y-m-d H:i:s'),
            'metric_version' => self::METRIC_VERSION,
            'aggregation_caught_up' => (bool)($group['current']['aggregation_caught_up'] ?? false),
            'field_explanations' => $this->fieldExplanations(),
            'drilldown' => ['enabled' => false, 'reason' => '商品看板下钻契约在统一报表验收阶段单独确认。'],
        ];
    }

    private function summary(array $group, array $periods, array $scope, array $range, array $categoryIds): array
    {
        $read = static function (array $payload, string $code): int {
            foreach ((array)($payload['cards'] ?? []) as $card) {
                $cardCode = (string)($card['metric_code'] ?? $card['code'] ?? '');
                if ($cardCode !== $code) continue;
                return array_key_exists('value_cents', $card)
                    ? (int)$card['value_cents']
                    : (int)($card['value'] ?? 0);
            }
            return 0;
        };
        $currentSales = $read($group['current'], 'cash_performance');
        $currentConsumption = $read($group['current'], 'consumption_performance');
        $yoySales = $read($group['yoy'], 'cash_performance');
        $momSales = $read($group['mom'], 'cash_performance');
        $yoyConsumption = $read($group['yoy'], 'consumption_performance');
        $momConsumption = $read($group['mom'], 'consumption_performance');
        // 项目成本必须来自核销时的冻结成本快照。当前历史事实尚未完整覆盖
        // 卡项/赠送项目，因此只要本期存在项目消耗，毛利总额就明确返回 null，
        // 由前端显示“-”，绝不回读当前商品设置成本价。
        $currentCost = $currentConsumption === 0 ? $this->salesCost($scope, $range, $categoryIds, 'product') : null;
        $yoyCost = $yoyConsumption === 0 ? $this->salesCost($scope, $periods['yoy'], $categoryIds, 'product') : null;
        $momCost = $momConsumption === 0 ? $this->salesCost($scope, $periods['mom'], $categoryIds, 'product') : null;
        $currentProductIncome = $this->sum($this->productRevenueRows($scope, $range, $categoryIds), 'amount_cents');
        $yoyProductIncome = $this->sum($this->productRevenueRows($scope, $periods['yoy'], $categoryIds), 'amount_cents');
        $momProductIncome = $this->sum($this->productRevenueRows($scope, $periods['mom'], $categoryIds), 'amount_cents');
        $gross = $currentCost === null ? null : $currentProductIncome - $currentCost;
        $yoyGross = $yoyCost === null ? null : $yoyProductIncome - $yoyCost;
        $momGross = $momCost === null ? null : $momProductIncome - $momCost;
        return [
            'sales' => $this->metric($currentSales, $yoySales, $momSales),
            'consumption' => $this->metric($currentConsumption, $yoyConsumption, $momConsumption),
            'gross_profit' => $this->metric($gross, $yoyGross, $momGross),
        ];
    }

    private function metric(?int $value, ?int $yoy, ?int $mom): array
    {
        return [
            'value_cents' => $value,
            'yoy' => ['value_cents' => $yoy, 'delta_cents' => $this->delta($value, $yoy), 'rate' => $this->rate($value, $yoy)],
            'mom' => ['value_cents' => $mom, 'delta_cents' => $this->delta($value, $mom), 'rate' => $this->rate($value, $mom)],
        ];
    }

    private function profitCategories(array $categories, array $scope, array $range, array $categoryIds, string $type): array
    {
        $rows = [];
        if ($type !== 'product') {
            foreach ($this->projectProfitRows($scope, $range, $categoryIds) as $row) {
                // 项目成本在事实层缺少完整的冻结成本快照，明确标记为缺失。
                $row['cost_cents'] = null;
                $rows[] = $row;
            }
        }
        if ($type !== 'project') {
            $costByLine = [];
            foreach ($this->saleCostRows($scope, $range, $categoryIds, 'product') as $row) {
                $lineId = (string)$row['item_id'];
                if ($lineId !== '') $costByLine[$lineId] = ($costByLine[$lineId] ?? 0) + (int)$row['cost_cents'];
            }
            foreach ($this->productRevenueRows($scope, $range, $categoryIds) as $row) {
                $lineId = (string)($row['item_line_id'] ?? '');
                $row['cost_cents'] = $lineId !== '' && array_key_exists($lineId, $costByLine)
                    ? (int)$costByLine[$lineId]
                    : null;
                $rows[] = $row;
            }
        }

        $byCategory = [];
        foreach ($rows as $row) {
            $rootId = $this->rootCategory((int)($row['category_id'] ?? 0), $categories['parents']);
            $key = (string)$rootId;
            if (!isset($byCategory[$key])) {
                $byCategory[$key] = ['income' => 0, 'cost' => 0, 'projects' => [], 'products' => []];
            }
            $income = (int)($row['amount_cents'] ?? 0);
            $cost = $row['cost_cents'];
            $byCategory[$key]['income'] += $income;
            if ($cost === null) {
                $byCategory[$key]['cost'] = null;
            } elseif ($byCategory[$key]['cost'] !== null) {
                $byCategory[$key]['cost'] += (int)$cost;
            }
            $kind = (string)($row['product_type'] ?? '') === 'project' ? 'projects' : 'products';
            $name = trim((string)($row['item_name'] ?? '')) ?: '-';
            if (!isset($byCategory[$key][$kind][$name])) {
                $byCategory[$key][$kind][$name] = ['income' => 0, 'cost' => 0, 'complete' => true];
            }
            $byCategory[$key][$kind][$name]['income'] += $income;
            if ($cost === null) {
                $byCategory[$key][$kind][$name]['complete'] = false;
            } else {
                $byCategory[$key][$kind][$name]['cost'] += (int)$cost;
            }
        }

        $result = [];
        foreach ($categories['roots'] as $category) {
            $id = (int)$category['id'];
            $data = $byCategory[(string)$id] ?? ['income' => 0, 'cost' => 0, 'projects' => [], 'products' => []];
            $cost = $data['cost'];
            $profit = $cost === null ? null : (int)$data['income'] - $cost;
            $result[] = [
                'category_id' => $id,
                'name' => (string)$category['name'],
                'income_cents' => (int)$data['income'],
                'cost_cents' => $cost,
                'gross_profit_cents' => $profit,
                'gross_margin_rate' => $this->rate($profit, (int)$data['income']),
                'project_rankings' => $this->ranking($data['projects']),
                'product_rankings' => $this->ranking($data['products']),
            ];
        }
        return $result;
    }

    private function ranking(array $groups): array
    {
        $rows = [];
        foreach ($groups as $name => $row) { $profit = $row['complete'] ? $row['income'] - $row['cost'] : null; $rows[] = ['name' => $name, 'revenue_cents' => $row['income'], 'cost_cents' => $row['complete'] ? $row['cost'] : null, 'gross_profit_cents' => $profit, 'gross_margin_rate' => $this->rate($profit, $row['income'])]; }
        usort($rows, static fn(array $a, array $b): int => ($b['gross_profit_cents'] ?? PHP_INT_MIN) <=> ($a['gross_profit_cents'] ?? PHP_INT_MIN));
        return array_slice($rows, 0, 5);
    }

    private function inventory(array $scope, array $categories, int $selected): array
    {
        $rows = $this->inventoryRows($scope); $map = $this->productCategoryMap($categories, $scope['store_ids']);
        $groups = [];
        foreach ($rows as $row) { $cat = $map[(string)$row['store_id'] . ':' . (int)$row['product_id']] ?? $map[(int)$row['product_id']] ?? 0; if ($selected > 0 && !in_array($cat, $this->descendants($selected, $categories['children']), true)) continue; $key = (string)$cat; if (!isset($groups[$key])) $groups[$key] = ['cost' => $this->costVisible($scope) ? 0 : null, 'rows' => []]; if ($this->costVisible($scope)) $groups[$key]['cost'] += (int)$row['inventory_cost_cents']; $itemKey = (string)$row['product_id']; if (!isset($groups[$key]['rows'][$itemKey])) $groups[$key]['rows'][$itemKey] = ['product_name' => $row['product_name'], 'available_quantity' => 0, 'inventory_cost_cents' => $this->costVisible($scope) ? 0 : null]; $groups[$key]['rows'][$itemKey]['available_quantity'] += (float)$row['quantity']; if ($this->costVisible($scope)) $groups[$key]['rows'][$itemKey]['inventory_cost_cents'] += (int)$row['inventory_cost_cents']; }
        $result = [];
        foreach ($categories['roots'] as $category) { $key = (string)$category['id']; $data = $groups[$key] ?? ['cost' => 0, 'rows' => []]; $items = array_values($data['rows']); usort($items, static fn(array $a, array $b): int => $b['inventory_cost_cents'] <=> $a['inventory_cost_cents']); $result[] = ['category_id' => (int)$category['id'], 'name' => (string)$category['name'], 'inventory_cost_cents' => $this->costVisible($scope) ? (int)$data['cost'] : null, 'rows' => array_slice($items, 0, 10)]; }
        return $result;
    }

    private function expiry(array $scope, array $categories, int $selected): array
    {
        $rows = $this->inventoryRows($scope);
        $map = $this->productCategoryMap($categories, $scope['store_ids']);
        $categoryNames = [];
        foreach ($categories['roots'] as $category) $categoryNames[(int)$category['id']] = (string)$category['name'];
        $cutoff = date('Y-m-d');
        $allowed = $selected > 0 ? array_fill_keys($this->descendants($selected, $categories['children']), true) : [];
        foreach ($rows as &$row) {
            $row['category_id'] = $map[(string)$row['store_id'] . ':' . (int)$row['product_id']] ?? $map[(int)$row['product_id']] ?? 0;
        }
        unset($row);
        $rows = array_values(array_filter($rows, static function (array $row) use ($allowed): bool {
            return $allowed === [] || isset($allowed[(int)$row['category_id']]);
        }));
        $labels = ['OVERDUE' => '已过期', 'WITHIN_30' => '30天内临期', 'DAYS_31_60' => '31至60天', 'DAYS_61_90' => '61至90天', 'SAFE_OVER_90' => '90天以上', 'UNKNOWN' => '到期日未知'];
        $bucketTotals = array_fill_keys(array_keys($labels), ['count' => 0, 'quantity' => 0]);
        foreach ($rows as $row) {
            $days = $row['expire_date'] ? (int)((new \DateTimeImmutable($cutoff))->diff(new \DateTimeImmutable($row['expire_date']))->format('%r%a')) : null;
            $key = $days === null ? 'UNKNOWN' : ($days < 0 ? 'OVERDUE' : ($days <= 30 ? 'WITHIN_30' : ($days <= 60 ? 'DAYS_31_60' : ($days <= 90 ? 'DAYS_61_90' : 'SAFE_OVER_90'))));
            $bucketTotals[$key]['count']++;
            $bucketTotals[$key]['quantity'] += (float)$row['quantity'];
        }
        $buckets = [];
        foreach ($labels as $key => $label) $buckets[] = ['key' => $key, 'label' => $label, 'count' => $bucketTotals[$key]['count'], 'quantity' => $bucketTotals[$key]['quantity']];
        $detail = [];
        foreach ($rows as $row) {
            $days = $row['expire_date'] ? (int)((new \DateTimeImmutable($cutoff))->diff(new \DateTimeImmutable($row['expire_date']))->format('%r%a')) : null;
            if ($days !== null && $days > 90) continue;
            $detail[] = [
                'product_name' => $row['product_name'], 'category_name' => $categoryNames[(int)$row['category_id']] ?? '未分类',
                'batch_no' => $row['batch_no'], 'current_quantity' => $row['quantity'], 'expire_date' => $row['expire_date'],
                'days' => $days, 'status' => $days === null ? '到期日未知' : ($days < 0 ? '已过期' : ($days <= 30 ? '30天内临期' : ($days <= 60 ? '31至60天' : '61至90天'))),
                'inventory_cost_cents' => $this->costVisible($scope) ? $row['inventory_cost_cents'] : null,
            ];
        }
        return ['buckets' => $buckets, 'rows' => $detail];
    }

    private function inventoryRows(array $scope): array
    {
        $locations = Db::name('inventory_location')->where('tenant_id', $scope['tenant_id'])->whereIn('store_id', $scope['store_ids'])->where('location_status', 'ACTIVE')->column('id'); if (!$locations) return [];
        $signed = 'SUM(IF(f.direction=1,f.quantity_units,-CAST(f.quantity_units AS SIGNED)))';
        $rows = Db::name('inventory_batch_movement_fact')->alias('f')->join('inventory_batch b', 'b.id=f.batch_id')->join('inventory_stock s', 's.id=f.stock_id')->join('inventory_location l', 'l.id=f.location_id')->where('f.tenant_id', $scope['tenant_id'])->whereIn('f.location_id', $locations)->where('f.business_date', '<=', date('Y-m-d'))->where('f.fact_status', 'SETTLED')->fieldRaw("l.store_id,b.id batch_id,s.consumable_product_id product_id,b.product_name_snapshot product_name,b.category_name_snapshot category_name,b.batch_no,b.expire_date,b.unit_cost_cents,s.quantity_scale,{$signed} quantity_units")->group('l.store_id,b.id,s.consumable_product_id,b.product_name_snapshot,b.category_name_snapshot,b.batch_no,b.expire_date,b.unit_cost_cents,s.quantity_scale')->having($signed . '>0')->select()->toArray();
        return array_map(function (array $row) use ($scope): array { $scale = max(0, (int)$row['quantity_scale']); $units = (int)$row['quantity_units']; return ['batch_id' => (int)$row['batch_id'], 'store_id' => (int)$row['store_id'], 'product_id' => (int)$row['product_id'], 'category_id' => 0, 'product_name' => (string)$row['product_name'], 'category_name' => (string)$row['category_name'], 'batch_no' => (string)$row['batch_no'], 'expire_date' => $row['expire_date'] ?: null, 'quantity' => $units / (10 ** $scale), 'inventory_cost_cents' => $this->costVisible($scope) ? (int)floor($units * (int)$row['unit_cost_cents'] / (10 ** $scale)) : null]; }, $rows);
    }

    private function costVisible(array $scope): bool { return (bool)($scope['cost_visible'] ?? false); }
    private function productCategoryMap(array $categories, array $stores): array { $all = Db::name('store_product')->where('is_del', 0)->field('id,cate_id,type,relation_id')->select()->toArray(); $map = []; foreach ($all as $row) { if ((int)$row['type'] === 1 && !in_array((int)$row['relation_id'], $stores, true)) continue; foreach (explode(',', (string)$row['cate_id']) as $id) { $root = $this->rootCategory((int)$id, $categories['parents']); if ($root > 0) { $key = (int)$row['type'] === 1 ? (int)$row['relation_id'] . ':' . (int)$row['id'] : (string)(int)$row['id']; $map[$key] = $root; if (!isset($map[(string)(int)$row['id']])) $map[(string)(int)$row['id']] = $root; break; } } } return $map; }
    private function rootCategory(int $id, array $parents): int { $root = $id; $guard = 0; while ($root > 0 && isset($parents[$root]) && (int)$parents[$root] > 0 && $guard++ < 32) $root = (int)$parents[$root]; return $root; }
    private function categories(): array { $rows = Db::name('store_product_category')->where('type', 0)->where('relation_id', 0)->where('is_show', 1)->field('id,pid,cate_name')->order('pid')->order('id')->select()->toArray(); $parents = []; $children = []; $roots = []; foreach ($rows as $row) { $id = (int)$row['id']; $pid = (int)$row['pid']; $parents[$id] = $pid; $children[$pid][] = $id; if ($pid <= 0) $roots[] = ['id' => $id, 'name' => (string)$row['cate_name']]; } return compact('roots', 'children', 'parents'); }
    private function descendants(int $root, array $children): array { $out = []; $queue = [$root]; while ($queue) { $id = (int)array_shift($queue); if ($id <= 0 || isset($out[$id])) continue; $out[$id] = $id; foreach ((array)($children[$id] ?? []) as $child) $queue[] = (int)$child; } return array_values($out); }
    private function scope(array $context, array $input): array { $allowed = array_values(array_unique(array_filter(array_map('intval', (array)($context['store_ids'] ?? []))))); $requested = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($input['store_ids'] ?? '')))))); if ($requested) $allowed = array_values(array_intersect($allowed, $requested)); if (!$allowed) throw new \InvalidArgumentException('当前账号没有可查看的门店范围'); sort($allowed); return ['tenant_id' => (string)($context['tenant_id'] ?? '0'), 'store_ids' => $allowed, 'cost_visible' => (bool)($context['cost_visible'] ?? false)]; }
    private function range(array $input): array { $start = trim((string)($input['start_date'] ?? '')) ?: date('Y-m-01'); $end = trim((string)($input['end_date'] ?? '')) ?: date('Y-m-d'); if (!$this->validDate($start) || !$this->validDate($end) || $start > $end || strtotime($end) - strtotime($start) > 366 * 86400) throw new \InvalidArgumentException('统计日期范围无效'); return ['start' => $start, 'end' => $end]; }
    private function validDate(string $date): bool { $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date); return $parsed !== false && $parsed->format('Y-m-d') === $date; }
    private function shiftRange(array $range, int $amount, string $unit): array { return ['start' => date('Y-m-d', strtotime($range['start'] . " {$amount} {$unit}")), 'end' => date('Y-m-d', strtotime($range['end'] . " {$amount} {$unit}"))]; }
    private function previousRange(array $range): array { $days = (int)((new \DateTimeImmutable($range['start']))->diff(new \DateTimeImmutable($range['end']))->format('%a')) + 1; return ['start' => date('Y-m-d', strtotime($range['start'] . ' -' . $days . ' days')), 'end' => date('Y-m-d', strtotime($range['start'] . ' -1 day'))]; }
    private function delta(?int $current, ?int $previous): ?int { return $current === null || $previous === null ? null : $current - $previous; }
    private function rate(?int $current, ?int $previous): ?float { return $current === null || !$previous ? null : round(($current - $previous) / abs($previous) * 100, 1); }
    private function salesCost(array $scope, array $range, array $categoryIds, string $type): ?int
    {
        if (!$this->costVisible($scope) || $type !== 'product') return null;
        $saleRows = $this->productRevenueRows($scope, $range, $categoryIds);
        if ($saleRows === []) return 0;
        $byLine = [];
        foreach ($this->saleCostRows($scope, $range, $categoryIds, $type) as $row) {
            $lineId = (string)$row['item_id'];
            if ($lineId !== '') $byLine[$lineId] = ($byLine[$lineId] ?? 0) + (int)$row['cost_cents'];
        }
        $sum = 0;
        foreach ($saleRows as $row) {
            $lineId = (string)($row['item_line_id'] ?? '');
            if ($lineId === '' || !array_key_exists($lineId, $byLine)) return null;
            $sum += (int)$byLine[$lineId];
        }
        return $sum;
    }

    /** Actual batch cost is attached to a settled sales-order line, never to a current product setting. */
    private function saleCostRows(array $scope, array $range, array $categoryIds, string $type): array
    {
        if (!$this->costVisible($scope) || $type !== 'product') return [];
        $rows = Db::name('cashier_v3_sale_inventory_receipt')
            ->where('tenant_id', $scope['tenant_id'])->whereIn('store_id', $scope['store_ids'])
            ->whereBetween('business_date', [$range['start'], $range['end']])
            ->field('result_snapshot')->select()->toArray();
        $out = [];
        foreach ($rows as $row) {
            $snapshot = json_decode((string)$row['result_snapshot'], true);
            foreach ((array)($snapshot['eventAllocations'] ?? []) as $allocation) {
                $lineId = trim((string)($allocation['salesOrderLineId'] ?? ''));
                if ($lineId !== '') $out[] = ['item_id' => $lineId, 'cost_cents' => (int)($allocation['actualCostCents'] ?? 0)];
            }
        }
        return $out;
    }

    /** Direct product payment allocations. Project revenue uses service completion facts instead. */
    private function productRevenueRows(array $scope, array $range, array $categoryIds): array
    {
        $query = Db::name('cashier_v3_payment_sale_allocation_fact')->alias('p')
            ->leftJoin('cashier_v3_payment_sale_allocation_fact original', 'original.tenant_id=p.tenant_id AND original.allocation_fact_id=p.reversal_of')
            ->join('cashier_v3_sale_fact s', 's.tenant_id=p.tenant_id AND s.fact_id=COALESCE(original.sale_fact_id,p.sale_fact_id)')
            ->join('cashier_v3_report_sale_dimension_fact d', 'd.tenant_id=s.tenant_id AND d.sale_fact_id=s.fact_id')
            ->where('p.tenant_id', $scope['tenant_id'])->whereIn('p.store_id', $scope['store_ids'])
            ->whereBetween('p.business_date', [$range['start'], $range['end']])
            ->where('p.status', 'effective')->where('s.source_type', '<>', 'card')
            ->whereIn('d.product_type_snapshot', ['product', 'goods']);
        if ($categoryIds !== []) $query->whereIn('d.category_id_snapshot', $categoryIds);
        return $query->fieldRaw('p.id,p.source_line_id item_line_id,p.amount_cents,d.item_id,d.item_name_snapshot item_name,d.category_id_snapshot category_id')
            ->group('p.id')->select()->toArray();
    }

    /** Project revenue is recognised when a service is completed, not when a card is sold. */
    private function projectProfitRows(array $scope, array $range, array $categoryIds): array
    {
        $query = Db::name('cashier_v3_performance_fact')->alias('p')
            ->join('cashier_v3_entitlement_service_fact sv', "sv.tenant_id=p.tenant_id AND sv.checkout_request_id=p.checkout_request_id AND sv.source_line_id=p.source_line_id AND sv.service_status='completed'")
            ->where('p.tenant_id', $scope['tenant_id'])->whereIn('p.store_id', $scope['store_ids'])
            ->whereBetween('p.business_date', [$range['start'], $range['end']])
            ->where('p.status', 'effective')->where('p.performance_type', 'consumption_performance_recorded');
        if ($categoryIds !== []) $query->whereIn('sv.project_category_id_snapshot', $categoryIds);
        return $query->fieldRaw("p.fact_id,p.source_line_id item_line_id,p.amount_cents,sv.project_id item_id,sv.project_name_snapshot item_name,sv.project_category_id_snapshot category_id,'project' product_type")
            ->group('p.fact_id')->select()->toArray();
    }

    private function sum(array $rows, string $field): int
    {
        $total = 0;
        foreach ($rows as $row) $total += (int)($row[$field] ?? 0);
        return $total;
    }
    private function fieldExplanations(): array { return ['summary.sales' => '统一收款销售分摊事实，按业务日汇总', 'summary.consumption' => '有效服务消耗业绩事实，按核销日汇总', 'profit.project_cost' => '销售/核销时锁定的商品设置成本；缺失返回 -', 'profit.product_cost' => '实际出库批次成本，来自 cashier_sale movement fact', 'inventory.cost' => '有效批次剩余数量×批次入库进价', 'expiry' => 'settled 批次余额按到期日固定六档']; }
}
