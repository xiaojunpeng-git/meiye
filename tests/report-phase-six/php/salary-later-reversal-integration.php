<?php

declare(strict_types=1);

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\report\StoreUnifiedReportPhaseSixServices;
use think\facade\Db;

// Run inside the local PHP container.  The fixture is read-only: this test
// locates performance reversed after its original business date and proves
// that neither salary report revives the original row.
require '/var/www/html/vendor/autoload.php';
$app = new \think\App('/var/www/html/');
$app->initialize();

try {
$targets = Db::name('cashier_v3_performance_fact')->alias('f')
    ->join('cashier_v3_performance_fact r',
        'r.tenant_id=f.tenant_id AND r.reversal_of=f.fact_id'
        . " AND r.fact_direction='reversal' AND r.status='effective'", 'INNER')
    ->where('f.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
    ->where('f.fact_direction', 'forward')->where('f.status', 'effective')
    ->whereIn('f.performance_type', ['sales_performance_allocated', 'labor_performance_allocated'])
    ->whereRaw('r.business_date > f.business_date')
    ->field('f.fact_id,f.store_id,f.business_date,f.employee_name_snapshot,f.amount_cents')
    ->order('f.business_date', 'asc')->order('f.id', 'asc')->select()->toArray();
if (!$targets) {
    echo "SKIP no cross-date performance reversals in local fixture\n";
    exit(0);
}

$groups = [];
foreach ($targets as $target) {
    $key = (int)$target['store_id'] . '|' . (string)$target['business_date'];
    $groups[$key]['store_id'] = (int)$target['store_id'];
    $groups[$key]['business_date'] = (string)$target['business_date'];
    $groups[$key]['fact_ids'][(string)$target['fact_id']] = true;
}

$toCents = static function ($value): int {
    $text = trim((string)$value);
    if ($text === '' || $text === '-') return 0;
    $negative = strpos($text, '-') === 0;
    $parts = explode('.', ltrim($text, '-'), 2);
    $cents = (int)($parts[0] ?? 0) * 100
        + (int)str_pad(substr((string)($parts[1] ?? ''), 0, 2), 2, '0');
    return $negative ? -$cents : $cents;
};

$service = new StoreUnifiedReportPhaseSixServices();
$verifiedDrilldowns = 0;
foreach ($groups as $group) {
    $range = ['start' => $group['business_date'], 'end' => $group['business_date']];
    $detail = $service->query('phase_six_salary_detail', [$group['store_id']], $range, ['_internal_all' => true]);
    $summary = $service->query('phase_six_salary_summary', [$group['store_id']], $range, ['_internal_all' => true]);
    foreach ([$detail, $summary] as $salaryReport) {
        $employeeColumn = null;
        foreach ($salaryReport['columns'] as $column) {
            if (($column['key'] ?? '') === 'employee_name') { $employeeColumn = $column; break; }
        }
        if (($employeeColumn['label'] ?? '') !== '员工' || ($employeeColumn['fixed'] ?? '') !== 'left') {
            throw new RuntimeException('salary employee column must be named 员工 and frozen on the left');
        }
    }
    foreach ([$detail, $summary] as $salaryReport) {
        foreach ((array)($salaryReport['columns'] ?? []) as $column) {
            $explanation = trim((string)($column['source_explanation'] ?? ''));
            if ($explanation === '') throw new RuntimeException('salary column source description is empty');
            // 页面说明面向工资核对人员，不暴露底层存储、查询或数据建模用语。
            if (preg_match('/事实|快照|投影|口径/u', $explanation)) {
                throw new RuntimeException('salary column source description leaked technical wording: ' . $explanation);
            }
        }
    }
    $detailExplanations = implode("\n", array_column((array)$detail['columns'], 'source_explanation'));
    $summaryExplanations = implode("\n", array_column((array)$summary['columns'], 'source_explanation'));
    if (!str_contains($detailExplanations, '后来已作废的记录不再统计')
        || !str_contains($summaryExplanations, '页面可输入姓名中的任意文字进行筛选')) {
        throw new RuntimeException('salary column source description misses the current business rules');
    }
    $rowKeys = array_map(static fn(array $row): string => (string)($row['row_key'] ?? ''), $detail['records']);
    if (array_intersect(array_keys($group['fact_ids']), $rowKeys)) {
        throw new RuntimeException('later-reversed salary fact leaked into original-date detail');
    }
    $detailCash = $toCents($detail['summary_row']['cash_amount'] ?? 0);
    $summaryCash = 0;
    foreach ($summary['columns'] as $column) {
        $key = (string)($column['key'] ?? '');
        if (strpos($key, 'salary_category_cash_') === 0) {
            $summaryCash += $toCents($summary['summary_row'][$key] ?? 0);
        }
    }
    if ($summaryCash !== $detailCash) {
        throw new RuntimeException('salary summary cash no longer reconciles with detail after reversal filtering');
    }
    // Follow one nonzero salary category exactly as the page does: stable store/employee IDs and
    // the server-declared category key must produce only matching rows and the clicked amount.
    $checkedDrilldown = false;
    foreach ($summary['records'] as $summaryRow) {
        if ((int)($summaryRow['employee_id'] ?? 0) <= 0) continue;
        foreach ($summary['columns'] as $column) {
            $columnKey = (string)($column['key'] ?? '');
            if (!str_starts_with($columnKey, 'salary_category_cash_') || $toCents($summaryRow[$columnKey] ?? 0) <= 0) continue;
            $drilldown = (array)($column['drilldown'] ?? []);
            if (($drilldown['report'] ?? '') !== 'phase_six_salary_detail') {
                throw new RuntimeException('salary category amount has no detail drilldown');
            }
            $input = [
                '_internal_all' => true,
                'salary_employee_id' => (int)$summaryRow['employee_id'],
                'salary_store_id' => (int)$summaryRow['store_id'],
                'salary_category_key' => $columnKey,
            ];
            $drilled = $service->query('phase_six_salary_detail', [$group['store_id']], $range, $input);
            foreach ($drilled['records'] as $drilledRow) {
                if ((int)($drilledRow['store_id'] ?? 0) !== (int)$summaryRow['store_id']
                    || (int)($drilledRow['employee_id'] ?? 0) !== (int)$summaryRow['employee_id']) {
                    throw new RuntimeException('salary drilldown leaked another store or employee');
                }
            }
            if ($toCents($drilled['summary_row']['cash_amount'] ?? 0) !== $toCents($summaryRow[$columnKey])) {
                throw new RuntimeException('salary category drilldown cash differs from the clicked summary amount');
            }
            $checkedDrilldown = true;
            $verifiedDrilldowns++;
            break;
        }
        if ($checkedDrilldown) break;
    }
    $firstEmployee = trim((string)($detail['records'][0]['employee_name'] ?? ''));
    if ($firstEmployee !== '') {
        $nameFilter = mb_substr($firstEmployee, 0, 1);
        $input = ['_internal_all' => true, 'employee_name' => $nameFilter];
        $filteredDetail = $service->query('phase_six_salary_detail', [$group['store_id']], $range, $input);
        $filteredSummary = $service->query('phase_six_salary_summary', [$group['store_id']], $range, $input);
        foreach (array_merge($filteredDetail['records'], $filteredSummary['records']) as $row) {
            if (mb_stripos((string)($row['employee_name'] ?? ''), $nameFilter) === false) {
                throw new RuntimeException('salary employee-name filter leaked another employee');
            }
        }
        foreach ([$filteredDetail, $filteredSummary] as $filteredReport) {
            if (($filteredReport['filter_schema'][0]['key'] ?? '') !== 'employee_name'
                || ($filteredReport['filter_schema'][0]['label'] ?? '') !== '员工'
                || ($filteredReport['filter_schema'][0]['show_label'] ?? null) !== false
                || ($filteredReport['filter_schema'][0]['aria_label'] ?? '') !== '员工姓名'
                || ($filteredReport['filter_schema'][0]['placeholder'] ?? '') !== '输入员工姓名'
                || ($filteredReport['filters']['employee_name'] ?? '') !== $nameFilter) {
                throw new RuntimeException('salary employee-name filter contract is incomplete');
            }
        }
        $filteredDetailCash = $toCents($filteredDetail['summary_row']['cash_amount'] ?? 0);
        $filteredSummaryCash = 0;
        foreach ($filteredSummary['columns'] as $column) {
            $key = (string)($column['key'] ?? '');
            if (strpos($key, 'salary_category_cash_') === 0) {
                $filteredSummaryCash += $toCents($filteredSummary['summary_row'][$key] ?? 0);
            }
        }
        if ($filteredSummaryCash !== $filteredDetailCash) {
            throw new RuntimeException('employee-filtered salary summary cash differs from detail');
        }
    }
}

if ($verifiedDrilldowns === 0) {
    throw new RuntimeException('local fixture had no nonzero category amount to exercise the salary drilldown');
}
$sample = reset($groups);
try {
    $service->query('phase_six_salary_detail', [(int)$sample['store_id']],
        ['start' => $sample['business_date'], 'end' => $sample['business_date']],
        ['salary_employee_id' => 1, 'salary_store_id' => 999999, 'salary_category_key' => 'salary_category_cash_unclassified']);
    throw new RuntimeException('salary drilldown accepted a store outside the authorized scope');
} catch (InvalidArgumentException $expected) {
    if (!str_contains($expected->getMessage(), '无权限')) throw $expected;
}

echo 'PASS salary reports exclude ', count($targets), ' cross-date reversed facts and reconcile summary/detail; ', $verifiedDrilldowns, " category drilldowns verified\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, 'FAIL salary later-reversal integration: ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
