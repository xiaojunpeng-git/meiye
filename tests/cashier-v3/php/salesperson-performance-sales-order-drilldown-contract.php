<?php

declare(strict_types=1);

/**
 * 销售人业绩下钻合同：汇总只声明稳定编号，订单中心由后端按同一业绩事实
 * 精确筛选销售订单；浏览器不得用姓名或页面当前行反推订单范围。
 */
$root = dirname(__DIR__, 3);
$report = file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportServices.php');
$salesQuery = file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php');
$reportView = file_get_contents($root . '/前端代码/cashier-v3/src/views/StoreBusinessReportView.vue');
$orderView = file_get_contents($root . '/前端代码/cashier-v3/src/views/OrderCenterView.vue');

foreach ([$report, $salesQuery, $reportView, $orderView] as $source) {
    if (!is_string($source) || $source === '') throw new RuntimeException('source missing');
}

foreach ([
    'report declares sales-order drilldown' => "'report'=>'order_center_sales'",
    'report maps salesperson stable id' => "'salesperson_id'=>'employee_id'",
    'report maps store stable id' => "'store_ids'=>'store_id'",
    'report carries exact day' => "'params'=>['day_of_month'=>\$day]",
    'daily source copy explains reversals' => '退款或人员调整产生的反向金额按发生日冲减',
    'voided order copy is explicit' => '整单作废后原金额和冲销金额都不显示',
] as $name => $needle) {
    if (strpos($report, $needle) === false) throw new RuntimeException($name);
}

foreach ([
    'browser accepts sales-order target' => "'order_center_sales'",
    'browser opens sales tab' => "tab: 'sales'",
    'browser passes salesperson id' => 'report_salesperson_id: request.params.salesperson_id',
    'browser preserves return location' => 'report_return_to: returnTo',
] as $name => $needle) {
    if (strpos($reportView, $needle) === false) throw new RuntimeException($name);
}

foreach ([
    'order page validates report salesperson' => 'report_salesperson_id',
    'order page sends authority drilldown' => 'salesPerformanceDrilldown',
    'order page uses all lifecycle states' => "dataScope: 'all'",
    'order page identifies sales-order explanation' => '对应销售订单',
    'order page offers return action' => 'returnToSalesReport',
] as $name => $needle) {
    if (strpos($orderView, $needle) === false) throw new RuntimeException($name);
}

foreach ([
    'backend validates drilldown payload' => 'sales_performance_drilldown_invalid',
    'backend matches salesperson fact' => "->where('report_sales_pf.employee_id', \$drilldown['employeeId'])",
    'backend matches fact date' => "DAY(report_sales_pf.business_date)=?",
    'backend matches source order' => "report_sales_pf.order_id=o.order_id",
    'backend excludes voided orders' => 'excludeVoidedSalesOrderFacts(',
    'cursor binds report drilldown' => "'salesPerformanceDrilldown' => \$criteria['salesPerformanceDrilldown']",
] as $name => $needle) {
    if (strpos($salesQuery, $needle) === false) throw new RuntimeException($name);
}

echo "salesperson performance sales-order drilldown contract: PASS\n";
