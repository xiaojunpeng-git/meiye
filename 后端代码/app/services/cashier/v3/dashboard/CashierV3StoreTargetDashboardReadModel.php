<?php

declare(strict_types=1);

namespace app\services\cashier\v3\dashboard;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\report\GroupManagementDashboardTargetServices;
use think\facade\Db;

/**
 * 门店端目标看板只读模型。
 *
 * 目标进度复用集团看板的门店月度目标事实；员工排行直接读取 V3
 * immutable performance/service facts。门店范围始终由当前 operator 强制
 * 收窄，客户端传入的 store_id/store_ids 不参与扩大权限。
 */
final class CashierV3StoreTargetDashboardReadModel
{
    public const CONTRACT_VERSION = 'cashier-v3-store-target-dashboard-v1';
    public const METRIC_VERSION = 'cashier-v3-facts-v1';

    /** @return array<string,mixed> */
    public function dashboard(array $payload, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        $this->assertScope($operator, $scope);
        $range = $this->range($payload);
        $tenantId = $operator->tenantId();
        $storeId = $operator->storeId();
        $month = substr($range['end'], 0, 7);
        $year = (int)substr($range['end'], 0, 4);
        $monthStart = $month . '-01';
        $yearStart = sprintf('%04d-01-01', $year);
        $targets = new GroupManagementDashboardTargetServices();
        $monthTotals = $targets->totals(['tenant_id' => $tenantId, 'store_ids' => [$storeId]], $year, [(int)substr($month, 5, 2)]);
        $yearTotals = $targets->totals(['tenant_id' => $tenantId, 'store_ids' => [$storeId]], $year, range(1, 12));
        $monthTarget = (int)($monthTotals[$storeId] ?? 0);
        $yearTarget = (int)($yearTotals[$storeId] ?? 0);
        $monthActual = $this->cashTotal($tenantId, $storeId, $monthStart, $range['end']);
        $yearActual = $this->cashTotal($tenantId, $storeId, $yearStart, $range['end']);

        return [
            'availability' => ['contractVersion' => self::CONTRACT_VERSION, 'status' => 'active', 'dataLoaded' => true],
            'mode' => 'store',
            'scope' => ['storeId' => $storeId, 'storeName' => $this->storeName($storeId), 'forced' => true],
            'range' => $range,
            'goals' => [
                'month' => $this->goal('本月目标进度', $monthTarget, $monthActual),
                'year' => $this->goal('本年目标进度', $yearTarget, $yearActual),
            ],
            'cash_ranking' => $this->cashRanking($tenantId, $storeId, $range),
            'consumption_ranking' => $this->consumptionRanking($tenantId, $storeId, $range),
            'source_explanation' => [
                'goals' => '门店目标读取 cashier_v3_group_dashboard_target 的门店月度目标事实；实际现金按有效 payment_fact 的正负签名净额汇总；本页只读。',
                'cash_ranking' => '现金业绩读取有效 sales_performance_allocated 事实；销售数量关联有效销售明细数量，成交人数按顾客去重。',
                'consumption_ranking' => '消耗业绩、手工、项目数、服务人次和服务人数读取有效 labor_performance_allocated 与已完成服务事实。',
            ],
            'metricVersion' => self::METRIC_VERSION,
            'dataAsOf' => date('Y-m-d H:i:s'),
        ];
    }

    private function cashTotal(string $tenantId, int $storeId, string $start, string $end): int
    {
        $row = Db::name('cashier_v3_payment_fact')->where('tenant_id', $tenantId)->where('store_id', $storeId)
            ->whereBetween('business_date', [$start, $end])->where('status', 'effective')
            // Reversal facts carry their signed negative amount and must remain
            // in the same sum so partial refunds produce the correct net value.
            ->fieldRaw('COALESCE(SUM(amount_cents),0) AS amount_cents')->find();
        return (int)($row['amount_cents'] ?? 0);
    }

    /** @return array<string,mixed> */
    private function cashRanking(string $tenantId, int $storeId, array $range): array
    {
        $rows = Db::name('cashier_v3_performance_fact')->alias('p')
            ->leftJoin('cashier_v3_sale_fact s', 's.tenant_id=p.tenant_id AND s.source_line_id=p.source_line_id AND s.fact_direction=\'forward\' AND s.status=\'effective\'')
            ->where('p.tenant_id', $tenantId)->where('p.store_id', $storeId)
            ->whereBetween('p.business_date', [$range['start'], $range['end']])->where('p.status', 'effective')
            ->where('p.performance_type', 'sales_performance_allocated')->where('p.employee_id', '>', 0)
            ->fieldRaw("p.employee_id,MAX(p.employee_name_snapshot) AS employee_name,SUM(p.amount_cents) AS cash_performance_cents,SUM((CASE WHEN p.fact_direction='reversal' THEN -1 ELSE 1 END) * COALESCE(s.quantity,0) * COALESCE(p.allocation_weight_numerator,1) / NULLIF(COALESCE(p.allocation_weight_denominator,1),0)) AS sales_quantity,COUNT(DISTINCT NULLIF(p.member_id,0)) AS customer_count")
            ->group('p.employee_id')->orderRaw('cash_performance_cents DESC, p.employee_id ASC')->limit(100)->select()->toArray();
        $records = [];
        foreach ($rows as $row) {
            $cents = (int)$row['cash_performance_cents'];
            $records[] = [
                'employee_id' => (int)$row['employee_id'], 'employee' => (string)$row['employee_name'],
                'cash_performance_cents' => $cents, 'cash_performance' => $this->money($cents),
                'sales_quantity' => (int)$row['sales_quantity'], 'customer_count' => (int)$row['customer_count'],
            ];
        }
        return ['title' => '员工现金业绩排行', 'sortBy' => 'cash_performance', 'sortOrder' => 'desc', 'columns' => [
            ['key' => 'employee', 'label' => '员工'], ['key' => 'cash_performance', 'label' => '现金业绩', 'type' => 'money'],
            ['key' => 'sales_quantity', 'label' => '销售数量', 'type' => 'count'], ['key' => 'customer_count', 'label' => '成交人数', 'type' => 'count'],
        ], 'records' => $records, 'total' => count($records)];
    }

    /** @return array<string,mixed> */
    private function consumptionRanking(string $tenantId, int $storeId, array $range): array
    {
        $rows = Db::name('cashier_v3_performance_fact')->alias('p')
            ->leftJoin('cashier_v3_entitlement_service_fact sv', 'sv.tenant_id=p.tenant_id AND sv.checkout_request_id=p.checkout_request_id AND sv.source_line_id=p.source_line_id AND sv.service_status=\'completed\'')
            ->leftJoin('cashier_v3_entitlement_reversal_fact er', 'er.tenant_id=sv.tenant_id AND er.reversal_of=sv.service_fact_id')
            ->where('p.tenant_id', $tenantId)->where('p.store_id', $storeId)->whereBetween('p.business_date', [$range['start'], $range['end']])
            ->where('p.status', 'effective')->whereNull('er.id')
            ->where('p.performance_type', 'labor_performance_allocated')->where('p.employee_id', '>', 0)
            // project_count_half_units is the employee-level allocation
            // snapshot (it supports 0.5); service_fact.project_count is the
            // source line total and would duplicate a split project for each
            // craftsman.
            ->fieldRaw("p.employee_id,MAX(p.employee_name_snapshot) AS employee_name,SUM(p.amount_cents) AS consumption_performance_cents,SUM(COALESCE(p.labor_fee_amount_cents,0)) AS labor_cents,SUM(COALESCE(p.project_count_half_units,0)) AS project_count_half_units,SUM((CASE WHEN p.fact_direction='reversal' THEN -1 ELSE 1 END) * COALESCE(sv.quantity,0) * COALESCE(p.allocation_weight_numerator,1) / NULLIF(COALESCE(p.allocation_weight_denominator,1),0)) AS service_count,COUNT(DISTINCT NULLIF(p.member_id,0)) AS customer_count")
            ->group('p.employee_id')->orderRaw('consumption_performance_cents DESC, p.employee_id ASC')->limit(100)->select()->toArray();
        $records = [];
        foreach ($rows as $row) {
            $consumption = (int)$row['consumption_performance_cents'];
            $records[] = [
                'employee_id' => (int)$row['employee_id'], 'employee' => (string)$row['employee_name'],
                'consumption_performance_cents' => $consumption, 'consumption_performance' => $this->money($consumption),
                'labor_cents' => (int)$row['labor_cents'], 'labor' => $this->money((int)$row['labor_cents']),
                'project_count' => ((int)$row['project_count_half_units']) / 2, 'service_count' => (int)$row['service_count'],
                'customer_count' => (int)$row['customer_count'],
            ];
        }
        return ['title' => '员工消耗业绩排行', 'sortBy' => 'consumption_performance', 'sortOrder' => 'desc', 'columns' => [
            ['key' => 'employee', 'label' => '员工'], ['key' => 'consumption_performance', 'label' => '消耗业绩', 'type' => 'money'],
            ['key' => 'labor', 'label' => '手工', 'type' => 'money'], ['key' => 'project_count', 'label' => '项目数', 'type' => 'count'],
            ['key' => 'service_count', 'label' => '服务人次', 'type' => 'count'], ['key' => 'customer_count', 'label' => '服务人数', 'type' => 'count'],
        ], 'records' => $records, 'total' => count($records)];
    }

    private function goal(string $title, int $target, int $actual): array
    {
        return ['title' => $title, 'target_amount_cents' => $target, 'actual_performance_cents' => $actual, 'remaining_amount_cents' => max(0, $target - $actual), 'achievement_rate' => $target > 0 ? round($actual / $target * 100, 1) : null];
    }

    /** @return array{start:string,end:string} */
    private function range(array $payload): array
    {
        $start = trim((string)($payload['startDate'] ?? $payload['start_date'] ?? '')) ?: date('Y-m-01');
        $end = trim((string)($payload['endDate'] ?? $payload['end_date'] ?? '')) ?: date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || $start > $end || strtotime($end) - strtotime($start) > 366 * 86400) {
            throw new CashierV3CommandException(CashierV3ResultCode::INVALID_COMMAND_CONTEXT, '统计日期范围无效，请选择不超过 366 天的开始和结束日期。');
        }
        return ['start' => $start, 'end' => $end];
    }

    private function assertScope(CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): void
    {
        if ($operator->storeId() <= 0 || !$scope->allowsStore($operator->storeId())) throw new CashierV3CommandException(CashierV3ResultCode::PERMISSION_DENIED, '当前账号无权查看该门店目标。');
    }

    private function storeName(int $storeId): string { return (string)(Db::name('system_store')->where('id', $storeId)->value('name') ?: '当前门店'); }

    private function money(int $cents): string { return (string)intdiv($cents, 100); }
}
