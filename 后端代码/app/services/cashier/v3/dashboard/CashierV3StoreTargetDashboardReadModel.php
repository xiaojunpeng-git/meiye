<?php

declare(strict_types=1);

namespace app\services\cashier\v3\dashboard;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\query\metric\ServiceCustomerMetricReadServices;
use app\services\query\metric\RegisteredMetricReadServices;
use app\services\report\StoreReportNormalDataScopeServices;
use app\services\target\TargetSchemeServices;
use think\facade\Db;

/**
 * 门店端目标看板只读模型。
 *
 * 目标进度读取关联“门店目标”的生效方案；员工排行直接读取 V3
 * immutable performance/service facts。门店范围始终由当前 operator 强制
 * 收窄，客户端传入的 store_id/store_ids 不参与扩大权限。
 */
final class CashierV3StoreTargetDashboardReadModel
{
    public const CONTRACT_VERSION = 'cashier-v3-store-target-dashboard-v1';
    public const METRIC_VERSION = 'cashier-v3-facts-v2';

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
        $bound = (new TargetSchemeServices())->effective(
            ['tenant_id' => $tenantId, 'store_ids' => [$storeId]], 'store_target',
            array_map(static fn($m) => sprintf('%04d-%02d', $year, $m), range(1, 12))
        );
        // 现有进度卡只展示“全店实际业绩”目标；其他已设置指标仍在目标分析中分别展示，绝不相加。
        $monthTarget = 0; $yearTarget = 0;
        foreach ($bound['lines'] as $line) {
            if ($line['metric_code'] !== 'actual_performance' || $line['scope_type'] !== 'store') continue;
            $yearTarget += (int)$line['target_value'];
            if ($line['month_key'] === $month) $monthTarget += (int)$line['target_value'];
        }
        $metrics = new RegisteredMetricReadServices();
        $monthActual = $metrics->summary('actual_performance', $tenantId, [$storeId], ['start' => $monthStart, 'end' => $range['end']]);
        $yearActual = $metrics->summary('actual_performance', $tenantId, [$storeId], ['start' => $yearStart, 'end' => $range['end']]);

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
                'goals' => '门店目标读取关联门店目标的生效方案；完成值使用统一事实层“实际业绩”指标，未设置目标不回退旧表；本页只读。',
                'cash_ranking' => '现金业绩读取有效 sales_performance_allocated 事实；销售数量关联有效销售明细数量，成交人数按顾客去重。',
                'consumption_ranking' => '消耗业绩、手工和项目数保留实际完成服务的有效业绩；服务人次对会员本人按同日去重，服务人数对会员本人按周期去重，朋友算和游客逐条计数；普通岗位共同服务时均分，独立核算岗位按发生时职位另组计算；本人或朋友选择“不算人数人次”不计入这两列。',
            ],
            'metricVersion' => self::METRIC_VERSION,
            'dataAsOf' => date('Y-m-d H:i:s'),
        ];
    }

    /** @return array<string,mixed> */
    private function cashRanking(string $tenantId, int $storeId, array $range): array
    {
        $query = Db::name('cashier_v3_performance_fact')->alias('p')
            ->leftJoin('cashier_v3_sale_fact s', 's.tenant_id=p.tenant_id AND s.source_line_id=p.source_line_id AND s.fact_direction=\'forward\' AND s.status=\'effective\'')
            ->where('p.tenant_id', $tenantId)->where('p.store_id', $storeId)
            ->whereBetween('p.business_date', [$range['start'], $range['end']])->where('p.status', 'effective')
            ->where('p.performance_type', 'sales_performance_allocated')->where('p.employee_id', '>', 0);
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderFacts($query, 'p.tenant_id', 'p.order_id');
        $rows = $query
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
        // 客数只复用统一服务客数读层；劳动金额与项目数保留完整有效事实。
        // 不能在此处给整条事实加“不算人数人次”过滤，否则会误删真实消耗和手工。
        $customerMetrics = (new ServiceCustomerMetricReadServices())->employeeMetrics($tenantId, [$storeId], $range)['summary_by_store_employee'];
        $query = Db::name('cashier_v3_performance_fact')->alias('p')
            ->leftJoin('cashier_v3_entitlement_service_fact sv', 'sv.tenant_id=p.tenant_id AND sv.checkout_request_id=p.checkout_request_id AND sv.source_line_id=p.source_line_id AND sv.service_status=\'completed\'')
            ->leftJoin('cashier_v3_entitlement_reversal_fact er', 'er.tenant_id=sv.tenant_id AND er.reversal_of=sv.service_fact_id')
            ->where('p.tenant_id', $tenantId)->where('p.store_id', $storeId)->whereBetween('p.business_date', [$range['start'], $range['end']])
            ->where('p.status', 'effective')->whereNull('er.id')
            ->where('p.performance_type', 'labor_performance_allocated')->where('p.employee_id', '>', 0);
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderServices($query, 'sv');
        $rows = $query
            // This is the employee-level allocation snapshot.  New records
            // carry an exact decimal count; the half-unit field is retained
            // only for historical rows.
            ->fieldRaw("p.employee_id,MAX(p.employee_name_snapshot) AS employee_name,SUM(p.amount_cents) AS consumption_performance_cents,SUM(COALESCE(p.labor_fee_amount_cents,0)) AS labor_cents,SUM(CASE WHEN p.project_count_decimal IS NULL THEN COALESCE(p.project_count_half_units,0) / 2 ELSE p.project_count_decimal END) AS project_count")
            ->group('p.employee_id')->orderRaw('consumption_performance_cents DESC, p.employee_id ASC')->limit(100)->select()->toArray();
        $records = [];
        foreach ($rows as $row) {
            $consumption = (int)$row['consumption_performance_cents'];
            $customer = $customerMetrics[$storeId . '|' . (int)$row['employee_id']] ?? [];
            $records[] = [
                'employee_id' => (int)$row['employee_id'], 'employee' => (string)$row['employee_name'],
                'consumption_performance_cents' => $consumption, 'consumption_performance' => $this->money($consumption),
                'labor_cents' => (int)$row['labor_cents'], 'labor' => $this->money((int)$row['labor_cents']),
                'project_count' => (float)$row['project_count'],
                // 读层以十分之一客为整数分配，转成展示数值时不能截断多人服务的 0.1 精度。
                'service_count' => (int)($customer['visit_tenths'] ?? 0) / 10,
                'customer_count' => (int)($customer['people_tenths'] ?? 0) / 10,
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
