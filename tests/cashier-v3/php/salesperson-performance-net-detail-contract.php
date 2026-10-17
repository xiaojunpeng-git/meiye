<?php

declare(strict_types=1);

/**
 * 汇总、分页明细和导出共用最终有效销售分配。历史冲销链留在事实表审计，
 * 但被冲销的旧销售分配和冲销行均不得在经营报表重复展示。
 */
$root = dirname(__DIR__, 3);
$reader = file_get_contents($root . '/后端代码/app/services/report/StoreSalespersonNetReadServices.php');
$report = file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportServices.php');
$xlsx = file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportDetailXlsxExporter.php');
$view = file_get_contents($root . '/前端代码/cashier-v3/src/views/StoreBusinessReportView.vue');
foreach ([$reader, $report, $xlsx, $view] as $source) {
    if (!is_string($source) || $source === '') throw new RuntimeException('source missing');
}

foreach ([
    'sales reads forward facts' => "where('p.fact_direction', 'forward')",
    'sales reversal lookup ignores report date' => "whereRaw('sales_reversal.reversal_of=p.fact_id')",
    'only effective reversals supersede sales facts' => "where('sales_reversal.status', 'effective')",
    'summary uses shared terminal facts' => 'SUM(p.amount_cents) metric_value',
    'detail total uses shared terminal facts' => 'COALESCE(SUM(p.amount_cents),0) amount_cents',
    'paged and export use same detail reader' => 'bool $exportAll = false, array $filters = []',
    'member filter narrows authorized cohort' => "whereLike('p.member_name_snapshot', \$pattern)",
    'document filter narrows authorized cohort' => "whereLike('p.order_no_snapshot', \$pattern)",
] as $name => $needle) {
    if (strpos($reader, $needle) === false) throw new RuntimeException($name);
}
foreach ([
    'detail displays signed amount' => "\$this->money((int)\$row['amount_cents'])",
    'detail filter passed to reader' => "'member' => \$input['detail_member'] ?? '', 'order' => \$input['detail_order_no'] ?? ''",
    'detail has backend summary' => "'performance_amount'=>\$this->money((int)\$details['amount_cents'])",
] as $name => $needle) {
    if (strpos($report, $needle) === false) throw new RuntimeException($name);
}
if (strpos($xlsx, "'store_salesperson_performance_detail' => '销售人业绩明细'") === false
    || strpos($xlsx, "'performance_amount'") === false) {
    throw new RuntimeException('unified XLSX detail support missing');
}
require $root . '/后端代码/vendor/autoload.php';
$exporter = new \app\services\report\StoreUnifiedReportDetailXlsxExporter();
$workbook = $exporter->render('store_salesperson_performance_detail', [
    'columns' => [
        ['key' => 'business_date', 'label' => '业务日期'],
        ['key' => 'performance_amount', 'label' => '销售业绩', 'value_type' => 'integer'],
    ],
    'records' => [['business_date' => '2026-10-03', 'performance_amount' => -500]],
    'summary_row' => ['business_date' => '合计', 'performance_amount' => -500],
]);
if (substr($workbook, 0, 2) !== 'PK') throw new RuntimeException('detail XLSX bytes invalid');
foreach ([
    'unified popup opener' => 'store_salesperson_performance_detail: openSalespersonPerformanceDetail',
    'clicked employee identity' => 'salespersonId: String(row.employee_id)',
    'clicked store identity' => 'storeId: String(row.store_id)',
    'same scoped detail export' => 'exportStoreBusinessReportXlsx(params, reportRuntime.value)',
    'amount shown in popup' => "'performance_amount'].includes(column.key)",
] as $name => $needle) {
    if (strpos($view, $needle) === false) throw new RuntimeException($name);
}

if (strpos($report, "? '退款冲减' : '销售分配'") === false) {
    throw new RuntimeException('detail still exposes personnel adjustment audit rows');
}
echo "salesperson performance net detail contract: PASS\n";
