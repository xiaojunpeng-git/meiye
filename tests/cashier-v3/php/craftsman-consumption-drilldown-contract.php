<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportServices.php');
$cashierController = file_get_contents($root . '/后端代码/app/controller/cashier/v3/Report.php');
$storeController = file_get_contents($root . '/后端代码/app/controller/store/report/UnifiedReport.php');
$adminController = file_get_contents($root . '/后端代码/app/controller/admin/v1/report/UnifiedReport.php');
$metricReader = file_get_contents($root . '/后端代码/app/services/query/metric/RegisteredMetricReadServices.php');
$orderCenter = file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php');

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
    'summary declares daily drilldown' => "'_drilldown']['day_'.\$day.'_consume']",
    'summary declares total drilldown' => "'_drilldown']['total_consume']",
    'drilldown targets the order-center service records' => "'report' => 'order_center_service'",
    'drilldown passes trusted row dimensions only' => "'param_map' => ['craftsman_id' => 'employee_id', 'store_ids' => 'store_id']",
] as $name => $needle) {
    if (strpos($service, $needle) === false) throw new RuntimeException($name);
}

if (strpos($summary, "\$this->craftsmanConsumptionDrilldown(\$day)") === false
    || strpos($service, "'store_ids' => 'store_id'") === false) {
    throw new RuntimeException('report cell must carry its own store identity');
}

foreach ([
    'summary uses registered signed labor metric' => "personnelDayMatrix('staff_labor_yeji'",
    'audited fact detail uses the same registered metric' => "personnelDetailResult('staff_labor_yeji'",
    'daily fact reader keeps day buckets across months' => "DAY(p.business_date)=?",
    'service records select exact employee facts' => "->where('pf.employee_id', \$performanceDrilldown['employeeId'])",
    'service records retain reversal direction' => "'direction' => (string)\$fact['fact_direction'] === 'reversal' ? '冲销' : '正常'",
    'service records use fact dates, not current service dates' => "applyPerformanceDrilldownDate(\$fact, \$performanceDrilldown, 'pf.business_date')",
    'service records use the same normal sales-order lifecycle scope' => "excludeVoidedSalesOrderFacts(\$fact, 'pf.tenant_id', 'pf.order_id')",
] as $name => $needle) {
    $source = str_starts_with($name, 'summary') ? $summary
        : (str_starts_with($name, 'audited') ? $detail
        : (str_starts_with($name, 'daily') ? $metricReader : $orderCenter));
    if (strpos($source, $needle) === false) throw new RuntimeException($name);
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
