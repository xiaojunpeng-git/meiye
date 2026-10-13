<?php

namespace app\services\report;

use app\services\cashier\v3\CashierV3ScopeResolver;
use think\facade\Db;

/**
 * 门店销售人净业绩只读投影。正向销售和有效退款各读一次人员事实；
 * 作废退款、被人员改派冲销的旧退款事实不进入正常报表。
 */
final class StoreSalespersonNetReadServices
{
    private function facts(array $stores, array $range, array $employeeIds = [])
    {
        $query = Db::name('cashier_v3_performance_fact')->alias('p')
            ->leftJoin('cashier_v3_order_lifecycle_operation refund_operation',
                "refund_operation.tenant_id=p.tenant_id AND refund_operation.store_id=p.store_id"
                . " AND refund_operation.operation_id=p.order_id AND refund_operation.operation_type='refund'")
            ->where('p.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('p.store_id', $stores)
            ->whereBetween('p.business_date', [$range['start'], $range['end']])
            ->where('p.status', 'effective')->where('p.employee_id', '>', 0)
            ->where(function ($kind): void {
                $kind->where('p.performance_type', 'sales_performance_allocated')
                    ->whereOr(function ($refund): void {
                        $refund->where('p.performance_type', 'refund_sales_deduction')
                            ->where('p.fact_direction', 'forward')
                            ->where('refund_operation.status', 'succeeded')
                            ->whereNotExists(function ($reversal): void {
                                $reversal->name('cashier_v3_performance_fact')->alias('refund_reversal')
                                    ->whereRaw('refund_reversal.tenant_id=p.tenant_id')
                                    ->whereRaw('refund_reversal.reversal_of=p.fact_id')
                                    ->where('refund_reversal.fact_direction', 'reversal')
                                    ->where('refund_reversal.status', 'effective');
                            });
                    });
            });
        if ($employeeIds !== []) $query->whereIn('p.employee_id', $employeeIds);
        $normal = new StoreReportNormalDataScopeServices();
        $normal->excludeVoidedSalesOrderFacts($query, 'p.tenant_id', 'p.order_id');
        $normal->excludeVoidedRechargeOrderFacts($query, 'p.tenant_id', 'p.order_id');
        $normal->excludeVoidedSupplementPaymentFacts($query, 'p.tenant_id', 'p.store_id', 'p.order_id');
        return $query;
    }

    /** 全量授权事实先按人/业务日聚合，页面与合计共用同一分精度结果。 */
    public function matrix(array $stores, array $range, array $employeeIds = []): array
    {
        if ($stores === []) return ['records' => [], 'summary' => ['day_metric_values' => [], 'total_metric_value' => 0]];
        $daily = $this->facts($stores, $range, $employeeIds)
            ->fieldRaw('p.store_id,p.employee_id,p.business_date,MAX(p.employee_name_snapshot) employee_name,'
                . 'MAX(p.store_name_snapshot) store_name,MAX(p.organization_id) organization_id,'
                . 'MAX(p.organization_path_snapshot) organization_path_snapshot,SUM(p.amount_cents) metric_value')
            ->group('p.store_id,p.employee_id,p.business_date')->select()->toArray();
        $records = [];
        $summary = ['day_metric_values' => [], 'total_metric_value' => 0];
        foreach ($daily as $fact) {
            $key = (int)$fact['store_id'] . ':' . (int)$fact['employee_id'];
            $day = (int)substr((string)$fact['business_date'], -2);
            if (!isset($records[$key])) {
                $records[$key] = [
                    'store_id' => (int)$fact['store_id'], 'employee_id' => (int)$fact['employee_id'],
                    'employee_name' => (string)$fact['employee_name'], 'store_name' => (string)$fact['store_name'],
                    'organization_id' => (string)$fact['organization_id'],
                    'organization_path_snapshot' => (string)$fact['organization_path_snapshot'],
                    'business_date' => $range['end'], 'day_metric_values' => [], 'total_metric_value' => 0,
                ];
            }
            $amount = (int)$fact['metric_value'];
            $records[$key]['day_metric_values'][$day] = (int)($records[$key]['day_metric_values'][$day] ?? 0) + $amount;
            $records[$key]['total_metric_value'] += $amount;
            $summary['day_metric_values'][$day] = (int)($summary['day_metric_values'][$day] ?? 0) + $amount;
            $summary['total_metric_value'] += $amount;
        }
        return ['records' => array_values($records), 'summary' => $summary];
    }

    /** 明细与汇总共享事实谓词；日期序号沿用汇总在跨月范围的分组方式。 */
    public function detail(array $stores, array $range, array $employeeIds, int $day, int $page, int $limit,
        bool $exportAll = false): array
    {
        if ($stores === []) return ['records' => [], 'total' => 0, 'amount_cents' => 0];
        $query = $this->facts($stores, $range, $employeeIds);
        if ($day > 0) $query->whereRaw('DAY(p.business_date)=?', [$day]);
        $total = (int)(clone $query)->count('p.id');
        $summary = (clone $query)->fieldRaw('COALESCE(SUM(p.amount_cents),0) amount_cents')->find() ?: [];
        $rowsQuery = $query->fieldRaw('p.fact_id,p.store_id,p.organization_id,p.organization_path_snapshot,'
                . 'p.business_date,p.employee_id,p.employee_name_snapshot employee_name,p.member_name_snapshot member_name,'
                . 'p.order_id,p.order_no_snapshot,p.source_line_id,p.performance_type,p.amount_cents,'
                . 'refund_operation.operation_no refund_no,refund_operation.source_order_no_snapshot original_no')
            ->order('p.business_date', 'desc')->order('p.id', 'desc');
        if (!$exportAll) $rowsQuery->page($page, $limit);
        $records = $rowsQuery->select()->toArray();
        return ['records' => $records, 'total' => $total, 'amount_cents' => (int)($summary['amount_cents'] ?? 0)];
    }
}
