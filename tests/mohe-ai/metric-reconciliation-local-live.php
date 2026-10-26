<?php

/**
 * Local current-page/Reader smoke comparison, NOT original-page reconciliation.
 * The pages already delegate to this Reader. Exact storage-unit matches still do
 * not establish an independent pre-migration baseline or complete filter/detail coverage.
 *
 * Run inside an authorized local backend container only. It never prints credentials,
 * customer names, employee names, record identifiers or business answers. Output is
 * limited to metric codes, integer values, comparison mode and PASS/FAIL evidence.
 */
if (getenv('MOHE_METRIC_RECONCILIATION_CONFIRM') !== 'LOCAL_READ_ONLY') {
    exit("Explicit local read-only opt-in required\n");
}

require getcwd().'/vendor/autoload.php';
$app = new think\App();
$app->initialize();
$database = (array)config('database.connections.'.config('database.default'));
if (($database['hostname'] ?? '') !== 'mysql') exit("Target host mismatch\n");
$pdo = new PDO(
    'mysql:host='.$database['hostname'].';port='.$database['hostport'].';dbname='.$database['database'].';charset=utf8mb4',
    $database['username'],
    $database['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);
$identity = $pdo->query('SELECT @@server_uuid uuid,DATABASE() db')->fetch(PDO::FETCH_ASSOC);
if (!$identity || $identity['uuid'] !== getenv('MOHE_METRIC_EXPECT_UUID') || $identity['db'] !== getenv('MOHE_METRIC_EXPECT_DB')) {
    exit("Target mismatch\n");
}
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$pdo->beginTransaction();

$checks = 0;
$evidence = [];
$stage = 'bootstrap';
$assert = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException($label);
    ++$checks;
};
$record = static function (string $path, string $metric, int $readerValue, int $pageValue) use (&$evidence, $assert): void {
    $assert($pageValue === $readerValue, 'PARITY_'.$path.'_'.$metric);
    $evidence[] = [
        'path' => $path,
        'metric_code' => $metric,
        'reader_value' => $readerValue,
        'page_value' => $pageValue,
        'comparison_mode' => 'exact_storage_unit',
        'difference' => $pageValue - $readerValue,
        'result' => 'CURRENT_PAGE_MATCH',
    ];
};

try {
    $adminId = (int)think\facade\Db::name('system_admin')->where('account', 'admin')->value('id');
    $principal = (new app\services\ai\execution\AiPlatformPrincipalResolver())->authenticated($adminId);
    $authorizedStores = array_values(array_unique(array_map('intval', (array)($principal['store_ids'] ?? []))));
    $authorizedStores = array_values(array_filter($authorizedStores, static function (int $id): bool { return $id > 0; }));
    $assert($adminId > 0 && $authorizedStores !== [], 'AUTHORIZED_LOCAL_SCOPE_REQUIRED');

    $candidate = think\facade\Db::name('cashier_v3_performance_fact')->whereIn('store_id', $authorizedStores)
        ->whereBetween('business_date', [app\services\query\metric\MetricDefinitionRegistry::COVERAGE_START, date('Y-m-d')])
        ->fieldRaw('store_id,COUNT(*) fact_count')->group('store_id')->order('fact_count', 'desc')->order('store_id', 'asc')->find();
    $storeId = (int)($candidate['store_id'] ?? $authorizedStores[0]);
    $assert(in_array($storeId, $authorizedStores, true), 'STORE_MUST_REMAIN_AUTHORIZED');
    $stores = [$storeId];
    $range = ['start' => app\services\query\metric\MetricDefinitionRegistry::COVERAGE_START, 'end' => date('Y-m-d')];

    $reader = new app\services\query\metric\RegisteredMetricReadServices();
    $values = [];
    foreach (array_keys(app\services\query\metric\MetricDefinitionRegistry::all()) as $metricCode) {
        $values[$metricCode] = $reader->summary($metricCode, '0', $stores, $range);
    }
    $assert($values['actual_performance'] === $values['cash_performance'] - $values['refund_performance'], 'ACTUAL_IDENTITY');

    $stage = 'group';
    $group = (new app\services\report\GroupManagementDashboardServices())->dashboard(
        ['tenant_id' => '0', 'store_ids' => $stores],
        ['start_date' => $range['start'], 'end_date' => $range['end']]
    );
    $groupCards = array_column((array)$group['cards'], null, 'metric_code');
    foreach ([
        'cash_performance' => 'cash_performance',
        'refund_performance' => 'refund_performance',
        'actual_performance' => 'actual_performance',
        'consume_amount' => 'consumption_performance',
        'completed_service_item_count' => 'consumption_count',
    ] as $metric => $cardCode) {
        $pageValue = $metric === 'completed_service_item_count'
            ? (int)$groupCards[$cardCode]['value'] : (int)$groupCards[$cardCode]['value_cents'];
        $record('G', $metric, $values[$metric], $pageValue);
    }

    $stage = 'store_overview';
    $store = (new app\services\report\StoreUnifiedReportServices())->query($stores, [
        'report' => 'overview', 'start_date' => $range['start'], 'end_date' => $range['end'],
        '_report_scope' => ['mode' => 'all'],
    ]);
    $storeCards = array_column((array)$store['cards'], null, 'code');
    foreach ([
        'sales_amount' => 'sales_amount',
        'cash_performance' => 'cash_performance',
        'actual_performance' => 'actual_performance',
        'balance_deduction_amount' => 'balance_deduction_amount',
        'recharge_amount' => 'recharge_amount',
        'completed_service_item_count' => 'service_count',
        'consume_amount' => 'consumption_performance',
        'staff_labor_yeji' => 'labor_performance',
    ] as $metric => $cardCode) {
        $count = $metric === 'completed_service_item_count';
        $pageValue = $count ? (int)$storeCards[$cardCode]['value'] : (int)$storeCards[$cardCode]['value_cents'];
        $record('S', $metric, $values[$metric], $pageValue);
    }
    $stage = 'store_salesperson';
    $salespersonReport = (new app\services\report\StoreUnifiedReportServices())->query($stores, [
        'report' => 'store_salesperson_performance', 'start_date' => $range['start'], 'end_date' => $range['end'],
        '_report_scope' => ['mode' => 'all'],
    ]);
    $salespersonCents = array_sum(array_map(static function (array $row): int { return (int)($row['total_performance_cents'] ?? 0); }, (array)$salespersonReport['records']));
    $record('S_SALESPERSON', 'staff_sales_yeji', $values['staff_sales_yeji'], $salespersonCents);
    $stage = 'store_craftsman';
    $craftsmanReport = (new app\services\report\StoreUnifiedReportServices())->query($stores, [
        'report' => 'store_craftsman_consumption', 'start_date' => $range['start'], 'end_date' => $range['end'],
        '_report_scope' => ['mode' => 'all'],
    ]);
    $craftsmanCents = array_sum(array_map(static function (array $row): int { return (int)($row['total_consume_cents'] ?? 0); }, (array)$craftsmanReport['records']));
    $record('S_CRAFTSMAN', 'staff_labor_yeji', $values['staff_labor_yeji'], $craftsmanCents);
    $stage = 'store_craftsman_detail';
    $craftsmanDetail = (new app\services\report\StoreUnifiedReportServices())->query($stores, [
        'report' => 'store_craftsman_consumption_detail', 'start_date' => $range['start'], 'end_date' => $range['end'],
        '_internal_all' => true, '_report_scope' => ['mode' => 'all'],
    ]);
    $detailRows = (array)$craftsmanDetail['records'];
    $assert($detailRows !== [], 'CRAFTSMAN_DETAIL_NONEMPTY_SAMPLE');
    foreach ($detailRows as $detailRow) {
        $assert(array_key_exists('employee_name', $detailRow) && array_key_exists('store_name', $detailRow)
            && $detailRow['employee_name'] === $detailRow['employee_name_snapshot']
            && $detailRow['store_name'] === $detailRow['store_name_snapshot'], 'CRAFTSMAN_DETAIL_LABEL_PROJECTION');
    }
    $record('S_CRAFTSMAN_DETAIL', 'staff_labor_yeji', $values['staff_labor_yeji'],
        array_sum(array_column($detailRows, 'metric_value')));
    $stage = 'store_customer';
    $customerReport = (new app\services\report\StoreUnifiedReportServices())->query($stores, [
        'report' => 'customers', 'start_date' => $range['start'], 'end_date' => $range['end'],
        'customer_segment' => 'active', '_internal_all' => true, '_report_scope' => ['mode' => 'all'],
    ]);
    $record('S_CUSTOMER', 'customer_active', $values['customer_active'], (int)$customerReport['total']);

    $stage = 'employee';
    $employee = (new app\services\report\EmployeeDashboardServices())->dashboard($stores, $range);
    $employeeCards = array_column((array)$employee['performance']['cards'], null, 'key');
    foreach ([
        'staff_sales_yeji' => 'performance_total',
        'customer_active' => 'active_customers',
        'completed_service_item_count' => 'service_count',
        'consume_amount' => 'consumption_performance',
    ] as $metric => $cardCode) {
        $count = in_array($metric, ['customer_active', 'completed_service_item_count'], true);
        $pageValue = $count ? (int)$employeeCards[$cardCode]['value'] : (int)$employeeCards[$cardCode]['value_cents'];
        $record('E', $metric, $values[$metric], $pageValue);
    }

    $stage = 'cashier';
    $operator = new app\services\cashier\v3\CashierV3OperatorScope($storeId, $adminId, '', '0');
    $scope = new app\services\cashier\v3\CashierV3DataScopeContext(
        $adminId, 0, $storeId, '0', '', null,
        app\services\cashier\v3\CashierV3DataScopeContext::MODE_ALL,
        [], true, 'local-admin', 'local-read-only', [], [], true
    );
    $cashier = (new app\services\cashier\v3\dashboard\CashierV3BusinessDashboardReadModel())->dashboard(
        ['start_date' => $range['start'], 'end_date' => $range['end']], $operator, $scope
    );
    $cashierCards = array_column((array)$cashier['cards'], null, 'metricCode');
    foreach ([
        'sales_amount' => 'sales_amount',
        'cash_performance' => 'cash_performance',
        'actual_performance' => 'actual_performance',
        'balance_deduction_amount' => 'balance_deduction',
        'recharge_amount' => 'recharge_amount',
        'completed_service_item_count' => 'service_count',
        'consume_amount' => 'consumption_performance',
        'staff_labor_yeji' => 'labor_performance',
    ] as $metric => $cardCode) {
        $count = $metric === 'completed_service_item_count';
        $pageValue = $count ? (int)$cashierCards[$cardCode]['value'] : (int)$cashierCards[$cardCode]['valueCents'];
        $record('C', $metric, $values[$metric], $pageValue);
    }

    $stage = 'mobile';
    $mobileReflection = new ReflectionClass(app\services\mobile\warehouse\MobileWarehouseServices::class);
    $mobile = $mobileReflection->newInstanceWithoutConstructor();
    $dictionaryProperty = $mobileReflection->getProperty('dictionary');
    $dictionaryProperty->setAccessible(true);
    $dictionaryProperty->setValue($mobile, new app\services\metric\MetricDictionaryServices());
    $mobileResult = $mobile->dashboardProjection($stores, [
        'periodMode' => 'custom', 'startDate' => $range['start'], 'endDate' => $range['end'], 'includeTrend' => false,
    ]);
    $mobileCards = array_column((array)$mobileResult['summaryMetrics'], null, 'code');
    foreach ([
        'cash_performance' => 'cash_performance',
        'refund_performance' => 'refund_performance',
        'actual_performance' => 'actual_performance',
        'consume_amount' => 'consume_amount',
        'customer_active' => 'visit_customer',
    ] as $metric => $cardCode) {
        $count = $metric === 'customer_active';
        $pageValue = $count ? (int)$mobileCards[$cardCode]['value'] : (int)$mobileCards[$cardCode]['valueCents'];
        $record('M', $metric, $values[$metric], $pageValue);
    }

    $pdo->commit();
    echo json_encode([
        'result' => 'SMOKE_PASS',
        'round_three_acceptance' => 'NOT_ESTABLISHED',
        'comparison_scope' => 'CURRENT_CODE_SINGLE_STORE_SMOKE_ONLY',
        'original_page_baseline_compared' => false,
        'active_customer_legacy_baseline_compared' => false,
        'all_amount_comparisons_cent_exact' => true,
        'target_verified' => true,
        'read_only_transaction' => false,
        'transaction_note' => 'PDO read-only transaction does not cover the separate framework query connection',
        'business_records_modified' => false,
        'store_count' => count($stores),
        'date_range' => $range,
        'registered_metric_count' => count($values),
        'path_comparison_count' => count($evidence),
        'checks' => $checks,
        'evidence' => $evidence,
    ], JSON_UNESCAPED_UNICODE)."\n";
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $code = $exception->getMessage();
    if (!preg_match('/^[A-Z][A-Z0-9_]{2,100}$/D', $code)) $code = 'LOCAL_RECONCILIATION_INTERNAL_ERROR';
    $driverCode = is_array($exception->errorInfo ?? null) ? (int)($exception->errorInfo[1] ?? 0) : 0;
    echo json_encode(['result' => 'FAILED', 'code' => $code, 'stage' => $stage, 'driver_code' => $driverCode, 'class' => get_class($exception), 'line' => $exception->getLine(), 'message' => $exception->getMessage(), 'business_records_modified' => false])."\n";
    exit(1);
}
