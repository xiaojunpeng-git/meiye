<?php
declare(strict_types=1);

// Isolated regression probes: production projection methods, no DB, .env or model.
namespace app\services { class BaseServices {} }
namespace {
    require __DIR__ . '/fixture-autoload.php';
    $failures = [];
    $check = static function (string $name, bool $ok) use (&$failures): void {
        echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
        if (!$ok) $failures[] = $name;
    };
    $class = new \ReflectionClass(\app\services\report\StoreUnifiedReportServices::class);
    $service = $class->newInstanceWithoutConstructor();
    $filter = $class->getMethod('itemAnalysisMetricFilters');
    if (PHP_VERSION_ID < 80100) $filter->setAccessible(true);
    $expectedFilters = [
        'category_path' => '生美', 'product_type' => 'project', 'partner_name' => '合作方',
        'salesperson_id' => 17, 'guide_id' => 18, 'sales_manager_id' => 19, 'is_experience' => 1,
    ];
    $check('complete_metric_filters_forwarded', $filter->invoke($service, $expectedFilters) === $expectedFilters);
    $project = $class->getMethod('itemAnalysisStore');
    if (PHP_VERSION_ID < 80100) $project->setAccessible(true);
    $stores = [];
    $entry = ['store_id' => 1, 'store_name' => 'audit-store']; // Reader cashCategoryRows schema
    $key = $project->invokeArgs($service, [&$stores, $entry]);
    $check('reader_store_name_projection', ($stores[$key]['store_name'] ?? '') === 'audit-store');

    $storeSource = (string)file_get_contents(dirname(__DIR__, 2) . '/后端代码/app/services/report/StoreUnifiedReportServices.php');
    $readerSource = (string)file_get_contents(dirname(__DIR__, 2) . '/后端代码/app/services/query/metric/RegisteredMetricReadServices.php');
    $dashboardSource = (string)file_get_contents(dirname(__DIR__, 2) . '/后端代码/app/services/cashier/v3/dashboard/CashierV3BusinessDashboardReadModel.php');
    $groupSource = (string)file_get_contents(dirname(__DIR__, 2) . '/后端代码/app/services/report/GroupManagementDashboardServices.php');
    $dashboardModule = (string)file_get_contents(dirname(__DIR__, 2) . '/后端代码/app/services/cashier/v3/dashboard/CashierV3BusinessDashboardModule.php');
    $dashboardView = (string)file_get_contents(dirname(__DIR__, 2) . '/前端代码/cashier-v3/src/views/BusinessDashboardView.vue');
    $check('mutable_reader_source_removed', !method_exists(\app\services\query\metric\RegisteredMetricReadServices::class, 'detailSource'));
    $check('personnel_detail_preserves_display_names', strpos($storeSource, "\$row['store_name'] = (string)(\$row['store_name_snapshot'] ?? '');") !== false
        && strpos($storeSource, "\$row['employee_name'] = (string)(\$row['employee_name_snapshot'] ?? '');") !== false);
    $dailyWindow = substr($groupSource, strpos($groupSource, 'private function dailyCashPerformance'), strpos($groupSource, 'private function trend') - strpos($groupSource, 'private function dailyCashPerformance'));
    $check('registered_reader_owns_day_and_person_matrices', strpos($readerSource, 'public function dailyTotals(') !== false
        && strpos($readerSource, 'public function categoryDailyTotals(') !== false
        && strpos($readerSource, 'public function personnelDayMatrix(') !== false
        && strpos($readerSource, 'public function personnelDetailResult(') !== false
        && strpos($dailyWindow, '->dailyStoreTotals(') === false
        && strpos($dailyWindow, '->categoryDailyStoreTotals(') === false);
    $craftsmanDetailStart = strpos($storeSource, 'private function craftsmanConsumptionDetail');
    $craftsmanDetailEnd = strpos($storeSource, 'private function salespersonPerformance');
    $craftsmanDetail = $craftsmanDetailStart === false || $craftsmanDetailEnd === false ? '' : substr($storeSource, $craftsmanDetailStart, $craftsmanDetailEnd - $craftsmanDetailStart);
    $check('personnel_detail_totals_remain_in_reader', $craftsmanDetail !== ''
        && strpos($craftsmanDetail, '->personnelDetailResult(') !== false
        && strpos($craftsmanDetail, 'summaryConsumption +=') === false
        && strpos($craftsmanDetail, "['amount_cents']") === false);
    $check('dashboard_detail_does_not_recalculate_registered_values', strpos($dashboardSource, 'private function rowAmount(') === false
        && strpos($dashboardSource, "['metric_value']") !== false);
    $check('item_analysis_uses_registered_buckets_and_participant_scope', strpos($storeSource, "->categoryReportBucketsForParticipant('consume_amount', CashierV3ScopeResolver::TENANT_SCOPE_ID, \$authorizedStores, \$range, \$categoryIds, \$metricFilters, \$this->participantEmployeeId)") !== false
        && strpos($readerSource, 'public function categoryReportBucketsForParticipant(') !== false
        && strpos($storeSource, '$participantCheckouts') === false && strpos($storeSource, '$allowedSaleCategories') === false);
    $check('source_line_category_aggregation_owned_by_reader', strpos($readerSource, 'public function sourceLineCategoryTotals(') !== false
        && strpos($storeSource, "metricSourceLineCategoryTotals('consume_amount'") !== false
        && strpos($storeSource, 'metricSourceLineTotals(') === false
        && strpos($storeSource, "->fieldRaw('p.source_line_id,COALESCE(SUM('") === false);
    $check('csv_export_removed', strpos($dashboardSource . $dashboardModule . $dashboardView, 'export-business-dashboard') === false
        && strpos($dashboardView, 'exportDashboard') === false && strpos($dashboardView, 'csvCell') === false);
    echo 'AUDIT_FAILURES=' . count($failures) . PHP_EOL;
    exit($failures === [] ? 0 : 1);
}
