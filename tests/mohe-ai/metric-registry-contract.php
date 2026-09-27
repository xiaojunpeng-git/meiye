<?php

/** Offline architecture contract for the round-three registered metric layer. */
require_once __DIR__ . '/fixture-autoload.php';
$root = dirname(__DIR__, 2);
$metricDir = $root . '/后端代码/app/services/query/metric/';
require_once $metricDir . 'MetricQueryContractException.php';
require_once $metricDir . 'MetricDefinitionRegistry.php';

use app\services\query\metric\MetricDefinitionRegistry;
use app\services\query\metric\MetricSemanticCatalog;

$checks = 0;
function metricRegistryCheck(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    ++$checks;
}

$required = [
    'cash_performance', 'refund_performance', 'actual_performance', 'consume_amount',
    'staff_sales_yeji', 'staff_labor_yeji', 'staff_project_num', 'staff_service_num', 'service_people',
    'sales_amount', 'sales_collected_amount', 'member_service_visit_count', 'sales_quantity', 'sales_record_count', 'balance_deduction_amount',
    'recharge_amount', 'completed_service_item_count', 'customer_active',
];
$definitions = MetricDefinitionRegistry::all();
metricRegistryCheck(array_diff($required, array_keys($definitions)) === [],
    'all currently approved V3 metrics remain registered');
foreach ($definitions as $definition) {
    metricRegistryCheck(!array_key_exists('name', $definition), 'registry never duplicates user-visible metric names');
}
metricRegistryCheck(MetricDefinitionRegistry::get('actual_performance')['derivation'] === [
    'operator' => 'subtract', 'left_metric' => 'cash_performance', 'right_metric' => 'refund_performance',
], 'actual performance formula is immutable registry metadata');
metricRegistryCheck(MetricDefinitionRegistry::get('actual_performance')['reader_strategy'] === 'derived_subtract',
    'actual performance executes the registered subtraction rather than an unrelated snapshot');
metricRegistryCheck(MetricDefinitionRegistry::classification('actual_performance') === [
    'metric_kind' => 'derived', 'time_semantics' => 'period_flow',
], 'derived classification is projected from the registered derivation');
metricRegistryCheck(MetricDefinitionRegistry::classification('member_remaining_project_times') === [
    'metric_kind' => 'atomic', 'time_semantics' => 'current_state',
] && MetricDefinitionRegistry::classification('member_days_since_last_visit')['time_semantics'] === 'as_of_date',
    'state and as-of metrics retain their existing registered time semantics');
metricRegistryCheck(MetricDefinitionRegistry::get('refund_performance')['reader_strategy'] === 'cash_refund', 'refund uses signed cash facts');
metricRegistryCheck(MetricDefinitionRegistry::canonical('service_count') === 'completed_service_item_count', 'page alias resolves without a second formula');
metricRegistryCheck(MetricDefinitionRegistry::get('completed_service_item_count')['storage_unit'] === 'count'
    && MetricDefinitionRegistry::get('sales_quantity')['storage_unit'] === 'count'
    && MetricDefinitionRegistry::get('customer_active')['storage_unit'] === 'count', 'count metrics never masquerade as cents');
metricRegistryCheck(MetricDefinitionRegistry::capabilities()['completed_service_item_count']['ai_query_ready'] === true
    && MetricDefinitionRegistry::capabilities()['sales_quantity']['ai_query_ready'] === true
    && MetricDefinitionRegistry::capabilities()['customer_active']['ai_query_ready'] === true,
    'registered quantity and people-count metrics are AI query-ready with their own unit');
metricRegistryCheck(MetricDefinitionRegistry::get('staff_project_num')['storage_unit'] === 'project_count_micro'
    && MetricDefinitionRegistry::get('staff_project_num')['source']['filters']['performance_type'] === 'labor_performance_allocated'
    && MetricDefinitionRegistry::capabilities()['staff_project_num']['ai_query_ready'] === true,
    'staff project count is an exact labor-allocation metric, not sales quantity');
metricRegistryCheck(MetricDefinitionRegistry::capabilities()['staff_sales_yeji']['analysis_default_rank_object_kinds'] === ['person']
    && MetricDefinitionRegistry::capabilities()['sales_amount']['analysis_default_rank_object_kinds'] === ['card', 'project', 'product']
    && MetricDefinitionRegistry::capabilities()['cash_performance']['analysis_default_rank_object_kinds'] === ['store', 'member'],
    'broad ranking defaults are declared by registered object contracts, not gateway question branches');
metricRegistryCheck(MetricDefinitionRegistry::get('staff_service_num')['storage_unit'] === 'customer_tenth'
    && MetricDefinitionRegistry::get('service_people')['storage_unit'] === 'customer_tenth'
    && MetricDefinitionRegistry::capabilities()['staff_service_num']['ai_query_ready'] === true
    && MetricDefinitionRegistry::capabilities()['service_people']['ai_query_ready'] === true,
    'registered service visits and people are AI-ready without losing exact tenth allocations');
metricRegistryCheck(MetricSemanticCatalog::uniqueCodeForTerms(
    ['销售业绩'], ['staff_sales_yeji', 'staff_labor_yeji', 'staff_project_num']
) === 'staff_sales_yeji', 'an exact personnel metric term resolves inside the active registered capability boundary');
metricRegistryCheck(MetricSemanticCatalog::uniqueCodeForTerms(
    ['销售人业绩'], ['staff_sales_yeji', 'sales_collected_amount']
) === 'staff_sales_yeji', 'personnel sales performance no longer conflicts with the distinct sales-collection fact');
metricRegistryCheck(MetricSemanticCatalog::uniqueTermInText(
    '按服务人次看呢？', ['staff_service_num', 'staff_project_num']
) === ['metric_code'=>'staff_service_num','term'=>'服务人次'],
    'an exact registered measurement is recovered from a natural follow-up without a question-specific branch');
metricRegistryCheck(MetricSemanticCatalog::uniqueTermInText(
    '今天劳动业绩第一名是谁？', ['staff_labor_yeji', 'staff_sales_yeji', 'staff_project_num', 'staff_service_num', 'service_people']
) === ['metric_code'=>'staff_labor_yeji','term'=>'劳动业绩'],
    'a natural self-contained question binds its longest exact registered measurement without a topic-switch phrase rule');
metricRegistryCheck(MetricSemanticCatalog::uniqueCodeForTerms(
    ['服务次数'], ['staff_service_num','staff_project_num']
) === 'staff_service_num',
    'a user-ready dictionary alias resolves through its canonical registered metric owner');
metricRegistryCheck(MetricSemanticCatalog::uniqueTermInText(
    '服务项目数', ['staff_service_num','staff_project_num']
) === ['metric_code'=>'staff_project_num','term'=>'项目数'],
    'a longer natural condition term may contain one uniquely registered metric title');
metricRegistryCheck(MetricSemanticCatalog::uniqueTermInText(
    '销售人业绩怎么样？', ['staff_sales_yeji', 'sales_collected_amount']
) === ['metric_code'=>'staff_sales_yeji','term'=>'销售人业绩'],
    'the personnel metric title remains exact after the store sales-collection alias was removed');
// The answer-row object must narrow a cross-object customer expression before
// the metric catalogue decides which registered fact owns that expression.
metricRegistryCheck(MetricSemanticCatalog::uniqueTermInText(
    '本月完成服务项目数量最多的员工是谁？', ['staff_project_num','staff_sales_yeji']
) === ['metric_code'=>'staff_project_num','term'=>'完成服务项目数量'],
    'the same natural measurement resolves to allocated wage projects inside the personnel boundary');
metricRegistryCheck(MetricSemanticCatalog::uniqueTermInText(
    '本月完成服务项目数量最多的项目是什么？', ['completed_service_item_count','sales_amount']
) === ['metric_code'=>'completed_service_item_count','term'=>'完成服务项目数量'],
    'the same natural measurement resolves to completed quantity inside the project boundary');
metricRegistryCheck(MetricSemanticCatalog::uniqueTermInText(
    '本月完成服务项目数量最多的是谁？', ['staff_project_num','completed_service_item_count']
) === null, 'without an answer-row object the cross-object measurement remains controlled ambiguity');
// R51: latest approved business meaning reuses wage project counts, without
// introducing an independent order-count reader or changing its fact grain.
metricRegistryCheck(MetricSemanticCatalog::uniqueTermInText(
    '这个门店技师做的单数最多的是哪个，最低的是哪个', ['staff_project_num','staff_service_num','staff_sales_yeji']
) === ['metric_code'=>'staff_project_num','term'=>'做的单数'],
    'technician colloquial order count resolves to the approved project-count metric');
metricRegistryCheck(!isset($definitions['staff_service_order_count']),
    'R51 does not introduce a separate completed-order metric');
$objectAliases=MetricDefinitionRegistry::analysisObjectAliases();
metricRegistryCheck(in_array('员工',$objectAliases['person']??[],true)
    && in_array('手艺人',$objectAliases['person']??[],true),
    'ordinary personnel object names are published by the registry instead of gateway phrase branches');
$memberThreshold=(new app\services\query\metric\AnalysisCapabilityCatalogFactory())->make()->discover([
    'metric_codes'=>['sales_collected_amount'],'object_kind'=>'member','operation'=>'threshold_count',
    'filter_keys'=>[],'relation_role'=>'member_sales_collection_total',
],static function(array $binding):bool{return true;});
metricRegistryCheck(($memberThreshold['complete_request_supported']??false)===true
    && (($memberThreshold['items'][0]['binding']['metric_code']??null)==='sales_collected_amount'),
    'the registered member threshold contract is discoverable without a question-specific capability branch');
$memberCondition=(new app\services\query\metric\AnalysisCapabilityCatalogFactory())->make()->discover([
    'metric_codes'=>['sales_collected_amount'],'object_kind'=>'member','operation'=>'condition_count',
    'filter_keys'=>[],'relation_role'=>'member_sales_collection_total',
],static function(array $binding):bool{return true;});
metricRegistryCheck(($memberCondition['complete_request_supported']??false)===true
    && (($memberCondition['items'][0]['binding']['metric_code']??null)==='sales_collected_amount'),
    'generic member conditions are discoverable from the registered metric shape and aggregate dimension');
$storeCondition=(new app\services\query\metric\AnalysisCapabilityCatalogFactory())->make()->discover([
    'metric_codes'=>['actual_performance'],'object_kind'=>'store','operation'=>'condition_list',
    'filter_keys'=>[],'relation_role'=>'store_total',
],static function(array $binding):bool{return true;});
metricRegistryCheck(($storeCondition['complete_request_supported']??false)===true
    && (($storeCondition['items'][0]['binding']['metric_code']??null)==='actual_performance')
    && MetricDefinitionRegistry::get('actual_performance')['condition_subjects']===['store'],
    'store conditions require an explicit subject contract and remain discoverable through the shared catalog');
metricRegistryCheck((MetricDefinitionRegistry::get('sales_amount')['category_reader']['strategy'] ?? '') === 'sale_completed_allocation',
    'sales amount declares its category reader instead of leaving reports to sum sale facts');
metricRegistryCheck(MetricDefinitionRegistry::get('sales_collected_amount')['reader_strategy'] === 'sales_payment_collected'
    && MetricDefinitionRegistry::get('sales_collected_amount')['storage_unit'] === 'fen'
    // The shared dimension contract also exposes breakdown; this does not
    // change the sales-payment fact source or its stored fen precision.
    && MetricDefinitionRegistry::get('sales_collected_amount')['query_shapes'] === ['summary', 'comparison', 'trend', 'ranking', 'threshold_count', 'condition_count', 'condition_list', 'breakdown']
    && MetricDefinitionRegistry::get('sales_collected_amount')['source']['threshold_count'] === [
        'subject_dimension' => 'member', 'aggregation' => 'period_total', 'operators' => ['gte', 'gt', 'lte', 'lt', 'eq'],
    ],
    'actual sales collection stays a separate registered sales-payment metric rather than reusing recharge-inclusive cash performance');
metricRegistryCheck(
    isset(MetricDefinitionRegistry::get('sales_quantity')['source']['dimensions']['project'], MetricDefinitionRegistry::get('sales_quantity')['source']['dimensions']['product'])
    && !isset(MetricDefinitionRegistry::get('sales_quantity')['source']['dimensions']['guide'], MetricDefinitionRegistry::get('sales_quantity')['source']['dimensions']['sales_manager']),
    'sold quantity is registered for item analysis without inventing guide or sales-manager quantity attribution'
);
metricRegistryCheck(MetricDefinitionRegistry::get('sales_record_count')['source']['amount']==='1'
    && MetricDefinitionRegistry::get('sales_record_count')['source']['filters']===['status'=>'effective','fact_direction'=>'forward']
    && isset(MetricDefinitionRegistry::get('sales_record_count')['source']['dimensions']['project'])
    && MetricDefinitionRegistry::capabilities()['sales_record_count']['ai_query_ready']===true,
    'sales record count owns one effective forward sale-line fact and remains distinct from sold quantity');

$read = static function (string $relative) use ($root): string {
    $source = file_get_contents($root . '/' . $relative);
    if ($source === false) throw new RuntimeException('missing source ' . $relative);
    return $source;
};
$view = $read('后端代码/app/services/query/metric/MetricReadViewServices.php');
$reader = $read('后端代码/app/services/query/metric/RegisteredMetricReadServices.php');
$catalog = $read('后端代码/app/services/query/metric/MetricRegistryCatalogServices.php');
metricRegistryCheck(strpos($view, "\$metric === 'cash_performance' ?") === false, 'old metric ternary is removed');
metricRegistryCheck(strpos($view, '->metricTotal(') !== false, 'AI read view delegates to the registered reader facade');
metricRegistryCheck(strpos($reader, "'cash_refund' =>") !== false && strpos($reader, "'derived_subtract' =>") !== false,
    'registered strategies execute refund and the fixed subtraction derivation');
metricRegistryCheck(strpos($reader, 'public function thresholdCount(') !== false
    && strpos($reader, 'public function thresholdMembers(') !== false
    && strpos($reader, "->group('s.member_id')") !== false
    && strpos($reader, 'member_period_totals') !== false,
    'member threshold count is database-side registered aggregation rather than a PHP detail scan');
metricRegistryCheck(strpos($reader, "balance_restored") === false, 'member balance restoration is not a refund metric source');
metricRegistryCheck(strpos($catalog, 'MetricDefinitionRegistry::all()') !== false
    && strpos($catalog, 'MetricDictionaryServices') !== false
    && strpos($catalog, "'source'") === false,
    'administrator catalog projects registry and dictionary without exposing fact source details');
$catalogProjection = \app\services\query\metric\MetricRegistryCatalogServices::catalog();
$catalogItems = array_column($catalogProjection['items'], null, 'metric_code');
metricRegistryCheck(($catalogItems['actual_performance']['metric_kind'] ?? null) === 'derived'
    && ($catalogItems['actual_performance']['time_semantics'] ?? null) === 'period_flow'
    && ($catalogItems['actual_performance']['ai_query_ready'] ?? null) === true,
    'management catalog separates derivation, time semantics and basic query readiness');
metricRegistryCheck(($catalogItems['member_days_since_last_visit']['metric_kind'] ?? null) === 'atomic'
    && ($catalogItems['member_days_since_last_visit']['time_semantics'] ?? null) === 'as_of_date'
    && ($catalogItems['member_days_since_last_visit']['ai_query_ready'] ?? true) === false
    && in_array('HISTORICAL_SERVICE_COVERAGE_INCOMPLETE', $catalogItems['member_days_since_last_visit']['readiness_reasons'] ?? [], true),
    'catalog keeps a registered but history-limited metric visible with its real reason');
metricRegistryCheck(in_array(['kind' => 'person', 'label' => '人员'], $catalogItems['staff_sales_yeji']['analysis_objects'] ?? [], true)
    && !in_array(['kind' => 'store', 'label' => '门店'], $catalogItems['staff_sales_yeji']['analysis_objects'] ?? [], true)
    && in_array(['kind' => 'project', 'label' => '项目'], $catalogItems['sales_amount']['analysis_objects'] ?? [], true),
    'catalog exposes the registered grain and dimensions without inventing store analysis for person metrics');
metricRegistryCheck(strpos($reader, 'public function categorySummary(') !== false
    && strpos($reader, 'public function categoryDailyStoreTotals(') !== false
    && strpos($reader, 'public function categoryStoreTotals(') !== false,
    'category-filtered card, trend and ranking totals are owned by Reader');
metricRegistryCheck(strpos($reader, 'public function personnelDailyTotals(') !== false
    && strpos($reader, 'public function personnelDetailResult(') !== false,
    'personnel report aggregation and detail projections are fixed Reader outputs');
$compatibility = $read('后端代码/app/services/query/metric/GroupPerformanceMetricReadServices.php');
metricRegistryCheck(strpos($compatibility, 'consumption_performance_recorded') === false
    && strpos($compatibility, 'sales_performance_allocated') === false
    && strpos($compatibility, 'labor_performance_allocated') === false,
    'compatibility facade does not maintain a second fact-type metric map');

$paths = [
    '后端代码/app/services/report/GroupManagementDashboardServices.php',
    '后端代码/app/services/cashier/v3/dashboard/CashierV3BusinessDashboardReadModel.php',
    '后端代码/app/services/report/StoreUnifiedReportServices.php',
    '后端代码/app/services/report/EmployeeDashboardServices.php',
    '后端代码/app/services/mobile/warehouse/MobileWarehouseServices.php',
];
foreach ($paths as $path) {
    $source = $read($path);
    metricRegistryCheck(strpos($source, 'RegisteredMetricReadServices') !== false
        || strpos($source, 'GroupPerformanceMetricReadServices') !== false, basename($path) . ' consumes the registered layer');
    metricRegistryCheck(strpos($source, "actual_performance_recorded") === false,
        basename($path) . ' never treats retired split performance as actual performance');
}

$group = $read($paths[0]);
metricRegistryCheck(strpos($group, "Db::name('cashier_v3_payment_sale_allocation_fact')") === false
    && strpos($group, "Db::name('cashier_v3_performance_fact')") === false
    && strpos($group, "Db::name('cashier_v3_entitlement_service_fact')") === false,
    'group dashboard has no parallel registered-metric fact reader');
$cashier = $read($paths[1]);
metricRegistryCheck(strpos($cashier, 'private function cashDetailSource') === false
    && strpos($cashier, 'private function factDetailSource') === false,
    'cashier detail and ranking reuse the registered source contract');
metricRegistryCheck(strpos($cashier, '->detailSource(') === false,
    'cashier dashboard cannot fall back to a mutable registered source query');
$store = $read($paths[2]);
$partnerStart = strpos($store, 'private function partnerItemSummary');
$partnerEnd = strpos($store, 'private function memberConsumptionDetail');
$partnerSlice = $partnerStart === false || $partnerEnd === false ? '' : substr($store, $partnerStart, $partnerEnd - $partnerStart);
metricRegistryCheck($partnerSlice !== '' && strpos($partnerSlice, "performance_type='consumption_performance_recorded'") === false
    && strpos($partnerSlice, "metricSourceLineCategoryTotals('consume_amount'") !== false
    && strpos($partnerSlice, "metricSourceLineCategoryTotals('sales_amount'") !== false
    && strpos($partnerSlice, 'SUM(COALESCE(c.sale_amount_cents,s.sale_amount_cents))') === false,
    'partner reports cannot own a second consumption or sales amount sum');
$itemStart = strpos($store, 'private function itemAnalysisMetricRows');
$itemEnd = strpos($store, 'private function itemAnalysisStore');
$itemSlice = $itemStart === false || $itemEnd === false ? '' : substr($store, $itemStart, $itemEnd - $itemStart);
metricRegistryCheck($itemSlice !== '' && strpos($itemSlice, "Db::name('cashier_v3_performance_fact')") === false
    && substr_count($itemSlice, '->categoryReportBuckets(') >= 2
    && substr_count($itemSlice, '->categoryReportBucketsForParticipant(') >= 2
    && strpos($itemSlice, "participant_employee_id']") === false && strpos($itemSlice, '$participantCheckouts') === false,
    'item analysis uses Reader-owned category buckets and does not post-filter participant facts');

$mobile = $read($paths[4]);
metricRegistryCheck(strpos($mobile, "Db::name('cashier_v3_order_lifecycle_operation')") === false,
    'mobile warehouse no longer owns a second refund sum');
metricRegistryCheck(strpos($mobile, "'actual_performance_recorded'") === false,
    'mobile warehouse no longer reads the retired actual snapshot');
metricRegistryCheck(strpos($mobile, '->groupedStoreTotals(') !== false && strpos($mobile, 'rankingCents +=') === false,
    'mobile organization rankings delegate metric aggregation to the registered Reader');
$mobileEmployeeStart = strpos($mobile, 'public function employeePerformance');
$mobileEmployeeEnd = strpos($mobile, 'public function dashboardProjection');
$mobileEmployeeSlice = $mobileEmployeeStart === false || $mobileEmployeeEnd === false ? '' : substr($mobile, $mobileEmployeeStart, $mobileEmployeeEnd - $mobileEmployeeStart);
metricRegistryCheck($mobileEmployeeSlice !== '' && strpos($mobileEmployeeSlice, "Db::name('cashier_v3_performance_fact')") === false
    && strpos($mobileEmployeeSlice, 'personnelFactCounts(') !== false,
    'mobile employee detail does not reopen the registered personnel fact source');
$employee = $read($paths[3]);
metricRegistryCheck(strpos($employee, "['sales_performance_allocated'=>'staff_sales_yeji'") === false,
    'employee dashboard no longer maintains a fact-type-to-metric switch');
$semantic = $read('后端代码/app/services/ai/semantic/AiSemanticVocabulary.php');
$planner = $read('后端代码/app/services/ai/execution/AiWorkflowPlanner.php');
$gateway = $read('后端代码/app/services/ai/AiGatewayServices.php');
$semanticCatalog = $read('后端代码/app/services/query/metric/MetricSemanticCatalog.php');
metricRegistryCheck(strpos($semantic, "'unavailable_metric'") === false
    && strpos($semantic, "'unavailable_count'") === false
    && strpos($semantic, "'unavailable_domain'") === false,
    'AI semantic layer has no handwritten unavailable metric blacklist');
metricRegistryCheck(strpos($semantic, 'MetricSemanticCatalog::unambiguousPatterns()') !== false,
    'AI metric vocabulary is projected from registry and dictionary');
metricRegistryCheck(strpos($planner, "private \$names = ['cash_performance'") === false
    && strpos($planner, 'MetricSemanticCatalog::names') !== false,
    'workflow planner has no second hardcoded metric list');
metricRegistryCheck(strpos($gateway, 'MetricSemanticCatalog::stripTerms') === false
    && strpos($semanticCatalog, "preg_quote(\$term,'/')") !== false,
    'person intent is model-structured and no longer re-parses a phrase-stripped question');
metricRegistryCheck(strpos($gateway, "str_replace(['劳动业绩','销售人业绩','销售业绩']") === false
    && strpos($gateway, "preg_match('/劳动业绩|销售业绩|销售人业绩/u'") === false,
    'person intent has no handwritten metric phrase list');
metricRegistryCheck(strpos($gateway, '$metric=$explicitMetrics[0]??') === false
    && strpos($gateway, "\$projection['signals']=array_values(array_unique(array_merge(\$intent['metric_codes']") !== false,
    'the model supplies a semantic metric candidate and the registry remains the execution authority');
metricRegistryCheck(!preg_match('/\\$metric\\s*===\\s*[\'\"][a-z0-9_]+[\'\"]|switch\\s*\\(\\s*\\$metric\\s*\\)/', $reader.$view),
    'registered execution layer has no metric-code if or switch branch');

echo 'metric-registry-contract: PASS (' . $checks . " checks; static architecture only)\n";
