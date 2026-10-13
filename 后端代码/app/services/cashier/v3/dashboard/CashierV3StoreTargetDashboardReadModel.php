<?php

declare(strict_types=1);

namespace app\services\cashier\v3\dashboard;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\query\metric\RegisteredMetricReadServices;
use app\services\report\StoreReportNormalDataScopeServices;
use app\services\report\StoreUnifiedReportPhaseSixServices;
use app\services\target\TargetSchemeServices;
use think\facade\Db;

/**
 * 门店端目标看板只读模型。
 *
 * 目标进度读取关联“门店目标”的生效方案；员工排行复用员工薪资汇总的
 * 事实与人员分摊口径。门店范围始终由当前 operator 强制
 * 收窄，客户端传入的 store_id/store_ids 不参与扩大权限。
 */
final class CashierV3StoreTargetDashboardReadModel
{
    public const CONTRACT_VERSION = 'cashier-v3-store-target-dashboard-v1';
    public const METRIC_VERSION = 'cashier-v3-target-salary-parity-v1';

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
        $salaryRows = (new StoreUnifiedReportPhaseSixServices())->salaryRankingRows([$storeId], $range);

        return [
            'availability' => ['contractVersion' => self::CONTRACT_VERSION, 'status' => 'active', 'dataLoaded' => true],
            'mode' => 'store',
            'scope' => ['storeId' => $storeId, 'storeName' => $this->storeName($storeId), 'forced' => true],
            'range' => $range,
            'goals' => [
                'month' => $this->goal('本月目标进度', $monthTarget, $monthActual),
                'year' => $this->goal('本年目标进度', $yearTarget, $yearActual),
            ],
            'cash_ranking' => $this->cashRanking($tenantId, $storeId, $range, $salaryRows),
            'consumption_ranking' => $this->consumptionRanking($salaryRows),
            'source_explanation' => [
                'goals' => '门店目标读取关联门店目标的生效方案；完成值使用统一事实层“实际业绩”指标，未设置目标不回退旧表；本页只读。',
                'cash_ranking' => '现金业绩与员工薪资汇总一致：结账和补交分配计入，成功退款按退款日扣减；销售数量取有效销售明细，成交人数按顾客去重。',
                'consumption_ranking' => '消耗业绩、手工、项目数和服务客数与员工薪资汇总一致；已作废服务不计入，服务人次按同日去重、服务人数按所选期间去重，并沿用员工分摊和不计客数设置。',
            ],
            'metricVersion' => self::METRIC_VERSION,
            'dataAsOf' => date('Y-m-d H:i:s'),
        ];
    }

    /** @return array<string,mixed> */
    private function cashRanking(string $tenantId, int $storeId, array $range, array $salaryRows): array
    {
        $query = Db::name('cashier_v3_performance_fact')->alias('p')
            ->leftJoin('cashier_v3_sale_fact s', 's.tenant_id=p.tenant_id AND s.source_line_id=p.source_line_id AND s.fact_direction=\'forward\' AND s.status=\'effective\'')
            ->where('p.tenant_id', $tenantId)->where('p.store_id', $storeId)
            ->whereBetween('p.business_date', [$range['start'], $range['end']])->where('p.status', 'effective')
            ->where('p.performance_type', 'sales_performance_allocated')->where('p.employee_id', '>', 0);
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderFacts($query, 'p.tenant_id', 'p.order_id');
        // Counts describe effective sales activity only. Money comes from the
        // salary projection so refunds and reversals cannot diverge here.
        $activity = $query
            ->fieldRaw("p.employee_id,SUM((CASE WHEN p.fact_direction='reversal' THEN -1 ELSE 1 END) * COALESCE(s.quantity,0) * COALESCE(p.allocation_weight_numerator,1) / NULLIF(COALESCE(p.allocation_weight_denominator,1),0)) AS sales_quantity,COUNT(DISTINCT NULLIF(p.member_id,0)) AS customer_count")
            ->group('p.employee_id')->select()->toArray();
        $byEmployee = [];
        foreach ($activity as $row) $byEmployee[(int)$row['employee_id']] = $row;
        $records = [];
        foreach ($salaryRows as $row) {
            $employeeId = (int)$row['employee_id'];
            $cents = (int)$row['cash_cents'];
            if ($employeeId <= 0 || $cents === 0) continue;
            $counts = $byEmployee[$employeeId] ?? [];
            $records[] = [
                'employee_id' => $employeeId, 'employee' => (string)$row['employee_name'],
                'cash_performance_cents' => $cents, 'cash_performance' => $this->money($cents),
                'sales_quantity' => (int)($counts['sales_quantity'] ?? 0), 'customer_count' => (int)($counts['customer_count'] ?? 0),
            ];
        }
        usort($records, static fn(array $a, array $b): int => $b['cash_performance_cents'] <=> $a['cash_performance_cents'] ?: $a['employee_id'] <=> $b['employee_id']);
        $records = array_slice($records, 0, 100);
        return ['title' => '员工现金业绩排行', 'sortBy' => 'cash_performance', 'sortOrder' => 'desc', 'columns' => [
            ['key' => 'employee', 'label' => '员工'], ['key' => 'cash_performance', 'label' => '现金业绩', 'type' => 'money'],
            ['key' => 'sales_quantity', 'label' => '销售数量', 'type' => 'count'], ['key' => 'customer_count', 'label' => '成交人数', 'type' => 'count'],
        ], 'records' => $records, 'total' => count($records)];
    }

    /** @return array<string,mixed> */
    private function consumptionRanking(array $salaryRows): array
    {
        $records = [];
        foreach ($salaryRows as $row) {
            $employeeId = (int)$row['employee_id'];
            $consumption = (int)$row['consumption_cents'];
            $labor = (int)$row['labor_fee_cents'];
            $projects = (int)$row['project_count_micros'];
            $visits = (int)$row['service_visit_tenths'];
            $people = (int)$row['service_people_tenths'];
            if ($employeeId <= 0 || ($consumption === 0 && $labor === 0 && $projects === 0 && $visits === 0 && $people === 0)) continue;
            $records[] = [
                'employee_id' => $employeeId, 'employee' => (string)$row['employee_name'],
                'consumption_performance_cents' => $consumption, 'consumption_performance' => $this->money($consumption),
                'labor_cents' => $labor, 'labor' => $this->money($labor),
                'project_count' => $projects / 1000000,
                'service_count' => $visits / 10,
                'customer_count' => $people / 10,
            ];
        }
        usort($records, static fn(array $a, array $b): int => $b['consumption_performance_cents'] <=> $a['consumption_performance_cents'] ?: $a['employee_id'] <=> $b['employee_id']);
        $records = array_slice($records, 0, 100);
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
