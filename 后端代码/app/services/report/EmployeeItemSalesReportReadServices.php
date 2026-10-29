<?php
namespace app\services\report;

use think\facade\Db;

/**
 * 员工项目销售数：以 V3 原销售行和最终有效销售参与人为准。
 * 行筛选只缩小输出，原订单完整参与人数始终是销售数量的分母。
 */
final class EmployeeItemSalesReportReadServices
{
    /** 当前门店任职构成 007 同款结果全集；历史事实不因人员离职而删除。 */
    public function read(array $stores, array $range, array $employeeIds = []): array
    {
        if (!$stores) return [];
        $rosterQuery = Db::name('system_store_staff')->alias('ss')
            ->leftJoin('system_store st', 'st.id=ss.store_id')
            ->whereIn('ss.store_id', $stores)->where('ss.is_del', 0)->where('ss.employee_id', '>', 0);
        if ($employeeIds) $rosterQuery->whereIn('ss.employee_id', $employeeIds);
        $roster = $rosterQuery->field('ss.store_id,ss.employee_id,ss.staff_name employee_name,st.name store_name')
            ->limit(10001)->select()->toArray();
        $this->checkSize($roster);
        $people = [];
        foreach ($roster as $person) {
            $key = (int)$person['store_id'].':'.(int)$person['employee_id'];
            $people[$key] = ['store_id'=>(int)$person['store_id'], 'store_name'=>(string)$person['store_name'],
                'employee_id'=>(int)$person['employee_id'], 'employee_name'=>(string)$person['employee_name']];
        }
        if (!$people) return [];

        // 先界定授权门店与日期内的正式销售行，再对这些原订单读取全体参与人。
        $salesQuery = Db::name('cashier_v3_sale_fact')->alias('s')
            ->where('s.tenant_id', '0')->whereIn('s.store_id', $stores)
            ->whereBetween('s.business_date', [$range['start'], $range['end']])
            ->where('s.fact_direction', 'forward')->where('s.status', 'effective')
            ->whereNotExists(function ($reversal): void {
                $reversal->name('cashier_v3_sale_fact')->alias('sale_reversal')
                    ->whereRaw('sale_reversal.tenant_id=s.tenant_id')
                    ->whereRaw('sale_reversal.reversal_of=s.fact_id')
                    ->where('sale_reversal.fact_direction', 'reversal')
                    ->where('sale_reversal.status', 'effective');
            });
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderFacts($salesQuery, 's.tenant_id', 's.order_id');
        $sales = $salesQuery->field('s.fact_id,s.store_id,s.order_id,s.source_line_id,s.item_id,s.item_name_snapshot,s.quantity')
            ->limit(10001)->select()->toArray();
        $this->checkSize($sales);
        $orders = array_values(array_unique(array_map('strval', array_column($sales, 'order_id'))));
        $participants = [];
        foreach (array_chunk($orders, 500) as $orderChunk) {
            $query = Db::name('cashier_v3_performance_fact')->alias('p')
                ->where('p.tenant_id', '0')->whereIn('p.store_id', $stores)->whereIn('p.order_id', $orderChunk)
                ->where('p.performance_type', 'sales_performance_allocated')
                ->where('p.fact_direction', 'forward')->where('p.status', 'effective')->where('p.employee_id', '>', 0)
                ->whereNotExists(function ($reversal): void {
                    $reversal->name('cashier_v3_performance_fact')->alias('performance_reversal')
                        ->whereRaw('performance_reversal.tenant_id=p.tenant_id')
                        ->whereRaw('performance_reversal.reversal_of=p.fact_id')
                        ->where('performance_reversal.fact_direction', 'reversal')
                        ->where('performance_reversal.status', 'effective');
                });
            (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderFacts($query, 'p.tenant_id', 'p.order_id');
            $facts = $query->field('p.store_id,p.order_id,p.source_line_id,p.employee_id')->limit(10001)->select()->toArray();
            $this->checkSize($facts);
            foreach ($facts as $person) {
                $orderKey = (int)$person['store_id'].':'.(string)$person['order_id'];
                $participants[$orderKey]['all'][(int)$person['employee_id']] = true;
                $participants[$orderKey]['lines'][(string)$person['source_line_id']][(int)$person['employee_id']] = true;
            }
            // 每个订单分片必须完整；limit 截断不可作为部分经营结果。
            if (count($participants) > 10000) throw new \InvalidArgumentException('基础报表源订单超过10000行，请缩小范围');
        }

        $grouped = [];
        foreach ($sales as $sale) {
            $orderKey = (int)$sale['store_id'].':'.(string)$sale['order_id'];
            $all = $participants[$orderKey]['all'] ?? [];
            $line = $participants[$orderKey]['lines'][(string)$sale['source_line_id']] ?? [];
            if (!$all || !$line) continue;
            $share = (int)$sale['quantity'] / count($all);
            foreach ($line as $employeeId => $_) {
                $personKey = (int)$sale['store_id'].':'.$employeeId;
                if (!isset($people[$personKey])) continue;
                $itemId = (string)$sale['item_id'];
                $itemName = (string)$sale['item_name_snapshot'];
                $key = hash('sha256', json_encode([$personKey, $itemId, $itemName], JSON_UNESCAPED_UNICODE));
                if (!isset($grouped[$key])) $grouped[$key] = $people[$personKey] + [
                    'row_id'=>'employee_item:'.$key, 'item_id'=>$itemId, 'item_name'=>$itemName, 'sales_quantity'=>0.0];
                $grouped[$key]['sales_quantity'] += $share;
            }
        }
        $this->checkSize($grouped);
        // 007 以当前员工为主表，未参与销售的员工仍有空项目、0.00 行。
        $withSales = [];
        foreach ($grouped as &$row) {
            $withSales[$row['store_id'].':'.$row['employee_id']] = true;
            $row['sales_quantity'] = round($row['sales_quantity'], 2, PHP_ROUND_HALF_UP);
        }
        unset($row);
        foreach ($people as $personKey => $person) if (!isset($withSales[$personKey])) {
            $grouped['empty:'.$personKey] = $person + ['row_id'=>'employee_item:empty:'.$personKey,
                'item_id'=>0, 'item_name'=>'', 'sales_quantity'=>0.0];
        }
        $this->checkSize($grouped);
        return array_values($grouped);
    }

    /** A capped source is a failed query, never a partial page or misleading total. */
    private function checkSize(array $rows): void
    {
        if (count($rows) > 10000) throw new \InvalidArgumentException('基础报表源数据超过10000行，请缩小范围');
    }
}
