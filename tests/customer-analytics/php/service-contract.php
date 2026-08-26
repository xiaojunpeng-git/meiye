<?php

declare(strict_types=1);

/**
 * 第九阶段客户分析统一接口静态契约。
 *
 * 该检查只读取源码，不启动应用、不连接数据库、不写入迁移；用于确认
 * 客户分析仍走统一权限/查询/导出契约，而不是由前端各算一套指标。
 */

$root = dirname(__DIR__, 3);
$servicePath = $root . '/后端代码/app/services/report/CustomerAnalyticsServices.php';
$controllerPath = $root . '/后端代码/app/controller/admin/v1/report/UnifiedReport.php';
$routePath = $root . '/后端代码/route/admin.php';
$phaseTwoPath = $root . '/后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php';
$phaseFourPath = $root . '/后端代码/app/services/report/StoreUnifiedReportPhaseFourServices.php';
$frontendApiPath = $root . '/前端代码/cashier-v3/src/services/customerAnalyticsApi.js';
$frontendViewPath = $root . '/前端代码/cashier-v3/src/views/CustomerAnalyticsView.vue';

$service = (string)file_get_contents($servicePath);
$controller = (string)file_get_contents($controllerPath);
$route = (string)file_get_contents($routePath);
$phaseTwo = (string)file_get_contents($phaseTwoPath);
$phaseFour = (string)file_get_contents($phaseFourPath);
$frontendApi = (string)file_get_contents($frontendApiPath);
$frontendView = (string)file_get_contents($frontendViewPath);

function customerAnalyticsAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$message}\n");
}

$reports = [
    'customer_overview' => '客户概况',
    'customer_source_analysis' => '客户开源分析',
    'customer_visit_analysis' => '到店数据分析',
    'customer_store_health' => '门店健康数据分析',
    'customer_consumption_tier' => '消费分级分析',
    'customer_cash_performance' => '现金业绩分析',
    'customer_refund_performance' => '退货业绩分析',
    'customer_item_analysis' => '客户品相分析',
    'customer_unconsumed_analysis' => '客户未耗分析',
];

foreach ($reports as $code => $title) {
    customerAnalyticsAssert(
        str_contains($service, "'{$code}'") && str_contains($service, "'{$title}'"),
        "{$title} has a stable report code and title"
    );
    customerAnalyticsAssert(
        str_contains($controller, "'{$code}'") && str_contains($controller, "admin-customer-analytics"),
        "{$title} is registered behind the independent customer-analysis permission"
    );
}

customerAnalyticsAssert(
    str_contains($controller, 'CustomerAnalyticsServices::catalogEntries()')
        && str_contains($controller, 'customerAnalytics(Request')
        && str_contains($controller, 'customerAnalyticsExport(Request'),
    'catalog, query and export share the customer analysis service'
);
customerAnalyticsAssert(
    str_contains($route, "Route::get('customer-analytics', 'v1.report.UnifiedReport/customerAnalytics')")
        && str_contains($route, "Route::get('customer-analytics/export', 'v1.report.UnifiedReport/customerAnalyticsExport')"),
    'platform routes expose query and export as separate authenticated endpoints'
);
customerAnalyticsAssert(
        str_contains($service, "public function export(string \$report")
        && str_contains($service, "\$this->read(\$report, \$stores, \$range, \$input, true)")
        && str_contains($service, "'summary_row' => \$result['summary_row'] ?? []")
        && str_contains($service, "'pending_metrics' => \$result['pending_metrics'] ?? []")
        && str_contains($service, "tempnam(sys_get_temp_dir(), 'customer-analytics-')")
        && str_contains($controller, "return download(\$file['path'], \$file['filename']);"),
    'export is produced from the same authorized read result and downloads a CSV file'
);
customerAnalyticsAssert(
    str_contains($service, "'source_explanations'")
        && str_contains($service, "'field_explanations'")
        && str_contains($service, 'private function humanSources'),
    'columns expose business-language source explanations'
);
customerAnalyticsAssert(
    str_contains($service, "'fixed' => true")
        && str_contains($service, "'sticky_query' => true")
        && str_contains($service, "'sticky_header' => true")
        && str_contains($service, "'result_scroll' => true"),
    'fixed query, header and result scrolling are declared by the backend'
);
customerAnalyticsAssert(
    str_contains($service, "'aggregation_caught_up'")
        && str_contains($service, "'data_as_of'")
        && str_contains($service, "'metric_version'"),
    'data timestamp, metric version and aggregation status are returned'
);
customerAnalyticsAssert(
    !str_contains($service, 'MemberManagementDashboardServices')
        && str_contains($service, 'consumption_metric'),
    'consumption tiers do not reuse the member-dashboard cash-only shortcut and declare the metric switch'
);
customerAnalyticsAssert(
    str_contains($service, "customer_unconsumed_analysis")
        && str_contains($service, 'private function unconsumedAnalysis')
        && str_contains($service, 'user_card_holder')
        && str_contains($service, 'current_card_entitlement')
        && str_contains($service, "'top_items_top10'")
        && str_contains($service, "'top_stores'")
        && str_contains($service, "'unconsumed_rate'")
        && str_contains($service, "'unconsumed_yoy'"),
    'unconsumed analysis reads current card entitlement facts and leaves unsupported rates explicit'
);
customerAnalyticsAssert(
    str_contains($service, 'UNCONSUMED_BATCH_SIZE')
        && str_contains($service, 'array_chunk(array_keys($oids), self::UNCONSUMED_BATCH_SIZE)')
        && str_contains($service, '$companyByStore'),
    'unconsumed analysis batches entitlement ids and caches organization dimensions per store'
);
customerAnalyticsAssert(
    str_contains($frontendApi, 'CUSTOMER_ANALYTICS_TIMEOUT_MS')
        && str_contains($frontendApi, "客户分析请求超时")
        && str_contains($frontendApi, "{ timeoutMs: CUSTOMER_ANALYTICS_TIMEOUT_MS }"),
    'customer analysis requests have a bounded timeout and actionable failure message'
);
customerAnalyticsAssert(
    str_contains($frontendView, 'function money(cents)')
        && str_contains($frontendView, "Math.round(Number(cents) / 100)")
        && !str_contains($frontendView, 'maximumFractionDigits: 2'),
    'customer analytics money formatter always displays integer yuan while preserving API cents'
);
customerAnalyticsAssert(
    str_contains($service, "\$sourceFilter = (int)(\$input['source_id'] ?? 0)")
        && str_contains($service, "\$itemFilter = trim((string)(\$input['item_id'] ?? ''))")
        && str_contains($service, "\$categoryFilter = trim((string)(\$input['category_path'] ?? ''))"),
    'source and item/category filters are translated into backend query predicates'
);
customerAnalyticsAssert(
    str_contains($service, 'private function sourceAnalysis')
        && str_contains($service, 'customerCashRows($stores, $range,')
        && !str_contains($service, "['report' => 'channels']"),
    'source analysis uses lifecycle source snapshots and signed cash facts instead of the legacy raw channel totals'
);
customerAnalyticsAssert(
    str_contains($service, "(array)(\$input['_report_scope'] ?? [])")
        && str_contains($phaseFour, 'applyCustomerParticipantScope')
        && str_contains($phaseTwo, "['_report_scope']"),
    'customer cash and refund fact readers preserve the authenticated participant scope'
);
customerAnalyticsAssert(
    str_contains($service, 'private function overviewVisuals')
        && str_contains($service, "'trend' => \$trend")
        && str_contains($service, "'age' => \$age")
        && str_contains($service, 'private function visitVisuals')
        && str_contains($service, "'frequency' => \$toRows(\$frequency)")
        && str_contains($service, "'recency' => \$toRows(\$recency)"),
    'overview and visit charts are returned from completed customer facts'
);
customerAnalyticsAssert(
    str_contains($service, "'item_proportions'")
        && str_contains($service, "'manager_records'")
        && str_contains($service, "'store_records'"),
    'refund item composition and manager/store rankings are returned by the backend'
);
customerAnalyticsAssert(
    !str_contains($service, "'process_status'")
        && !str_contains($service, "'rectification_rate'"),
    'refund contract contains real refund fields only; no invented process or rectification fields'
);
customerAnalyticsAssert(
    str_contains($service, "'aggregate_records'")
        && str_contains($service, "'detail_records'")
        && str_contains($service, "'_internal_all' => true"),
    'refund aggregates/details are separated and all authorized facts feed query/export'
);
customerAnalyticsAssert(
    str_contains($controller, 'canAccessCustomerAnalytics')
        && str_contains($controller, "if (!in_array(\$report, self::CUSTOMER_ANALYTICS_REPORTS, true)) return false"),
    'unknown customer report codes fail closed instead of inheriting the generic allow path'
);

fwrite(STDOUT, "Customer analytics service contract: PASS\n");
