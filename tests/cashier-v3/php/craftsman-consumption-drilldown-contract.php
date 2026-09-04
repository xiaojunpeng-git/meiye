<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportServices.php');
$cashierController = file_get_contents($root . '/后端代码/app/controller/cashier/v3/Report.php');
$storeController = file_get_contents($root . '/后端代码/app/controller/store/report/UnifiedReport.php');
$adminController = file_get_contents($root . '/后端代码/app/controller/admin/v1/report/UnifiedReport.php');

function section(string $source, string $start, string $end): string
{
    $from = strpos($source, $start);
    $to = strpos($source, $end, $from === false ? 0 : $from);
    if ($from === false || $to === false) throw new RuntimeException("missing section {$start}");
    return substr($source, $from, $to - $from);
}

$summary = section($service, 'private function craftsmanConsumption', 'private function craftsmanConsumptionDrilldown');
$detail = section($service, 'private function craftsmanConsumptionDetail', 'private function salespersonPerformance');

foreach ([
    'summary stores drilldown source store id' => "'store_id'=>(int)\$row['store_id']",
    'summary declares daily drilldown' => "'_drilldown']['day_'.\$day.'_consume']",
    'summary declares total drilldown' => "'_drilldown']['total_consume']",
    'drilldown targets hidden detail report' => "'report' => 'store_craftsman_consumption_detail'",
    'drilldown passes trusted row dimensions only' => "'param_map' => ['craftsman_id' => 'employee_id', 'store_ids' => 'store_id']",
] as $name => $needle) {
    if (strpos($service, $needle) === false) throw new RuntimeException($name);
}

foreach ([
    'same effective performance fact source' => "Db::name('cashier_v3_performance_fact')->alias('p')",
    'same labor fact classification' => "->where('p.performance_type', 'labor_performance_allocated')",
    'same effective lifecycle filter' => "->where('p.status', 'effective')",
    'drilldown keeps authorized craftsman filter' => "->where('p.employee_id', (int)\$input['craftsman_id'])",
    'daily drilldown follows the day bucket, including across months' => "DAY(p.business_date)=?",
    'detail joins the frozen sales order line identity' => 'sol.tenant_id=p.tenant_id AND sol.order_id=p.order_id AND sol.order_line_id=p.source_line_id',
    'detail retains signed fact direction' => 'p.fact_direction',
    'detail summarizes consumption from exact detail facts' => '$summaryConsumption += (int)($row[\'amount_cents\'] ?? 0);',
    'detail summarizes labor fee from exact detail facts' => '$summaryLabor += (int)($row[\'labor_fee_amount_cents\'] ?? 0);',
    'detail renders reversal as business status' => "=== 'reversal' ? '冲销' : '正常'",
    'export retrieves the same full detail set' => "!empty(\$input['_internal_all'])",
] as $name => $needle) {
    if (strpos($detail, $needle) === false) throw new RuntimeException($name);
}

foreach ([$cashierController, $storeController, $adminController] as $controller) {
    if (strpos($controller, "['day_of_month', 0]") === false && strpos($controller, "['day_of_month',0]") === false) {
        throw new RuntimeException('all report controllers must accept the declared drilldown day');
    }
}
if (strpos($adminController, "'store_craftsman_consumption_detail'") === false
    || strpos($adminController, "? 'store_craftsman_consumption' : \$report") === false) {
    throw new RuntimeException('detail must inherit the parent menu permission');
}

echo "craftsman consumption drilldown contract: PASS\n";
