<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

// This probe is deliberately read-only and localhost-only. It compiles the
// real ThinkPHP predicates and asks MySQL to execute a bounded SELECT.
$database = trim((string)getenv('ROUND3_TEST_DATABASE'));
$password = (string)getenv('ROUND3_TEST_PASSWORD');
if ($database === '' || $password === '') {
    fwrite(STDERR, "Set ROUND3_TEST_DATABASE and ROUND3_TEST_PASSWORD for the local MySQL probe.\n");
    exit(2);
}
if (!function_exists('swoole_cpu_num')) {
    function swoole_cpu_num(): int { return 1; }
}
if (!class_exists('Swoole\\Table')) {
    eval('namespace Swoole; final class Table { public const TYPE_STRING = 7; public const TYPE_INT = 1; }');
}

$backend = dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';

final class RoundThreeLocalApp extends \think\App
{
    // PHP 8.5 reports deprecations in older vendor code as fatal errors when
    // the production error initializer is installed; this test changes none
    // of the application's database/query classes.
    protected $initializers = [\think\initializer\RegisterService::class, \think\initializer\BootService::class];
}

$app = new RoundThreeLocalApp($backend . '/');
foreach ([
    'cache.driver' => 'file', 'CACHE_DRIVER' => 'file',
    'database.hostname' => '127.0.0.1', 'DATABASE_HOSTNAME' => '127.0.0.1',
    'database.hostport' => '3307', 'DATABASE_HOSTPORT' => '3307',
    'database.database' => $database, 'DATABASE_DATABASE' => $database,
    'database.username' => 'root', 'DATABASE_USERNAME' => 'root',
    'database.password' => $password, 'DATABASE_PASSWORD' => $password,
] as $key => $value) $app->env->set($key, $value);
$envName = new ReflectionProperty($app, 'envName');
$envName->setAccessible(true);
$envName->setValue($app, 'round_three_local_mysql_no_dotenv');
$app->initialize();
\think\facade\Config::set(['default' => 'file'], 'cache');

if ((string)\think\facade\Db::query('SELECT DATABASE() AS db')[0]['db'] !== $database) {
    throw new RuntimeException('Local database identity mismatch.');
}

$scope = new \app\services\report\StoreReportNormalDataScopeServices();
$queries = [];
$payments = \think\facade\Db::name('cashier_v3_payment_fact')->alias('p')->where('p.tenant_id', '0');
$scope->excludeVoidedSalesOrderFacts($payments, 'p.tenant_id', 'p.order_id');
$scope->excludeVoidedRechargeOrderFacts($payments, 'p.tenant_id', 'p.order_id');
$scope->excludeVoidedSupplementPaymentFacts($payments, 'p.tenant_id', 'p.store_id', 'p.order_id');
$queries['payment'] = $payments->field('p.fact_id')->limit(1);

$allocations = \think\facade\Db::name('cashier_v3_payment_sale_allocation_fact')->alias('a')->where('a.tenant_id', '0');
$scope->excludeVoidedSalesOrderFacts($allocations, 'a.tenant_id', 'a.order_id');
$scope->excludeVoidedSupplementAllocations($allocations, 'a');
$queries['allocation'] = $allocations->field('a.allocation_fact_id')->limit(1);

$performance = \think\facade\Db::name('cashier_v3_performance_fact')->alias('p')->where('p.tenant_id', '0');
$scope->excludeVoidedSalesOrderFacts($performance, 'p.tenant_id', 'p.order_id');
$scope->excludeVoidedRechargeOrderFacts($performance, 'p.tenant_id', 'p.order_id');
$scope->excludeVoidedSupplementPaymentFacts($performance, 'p.tenant_id', 'p.store_id', 'p.order_id');
$scope->excludeVoidedSalesOrderServices($performance, 'p');
$queries['performance'] = $performance->field('p.fact_id')->limit(1);

$services = \think\facade\Db::name('cashier_v3_entitlement_service_fact')->alias('s')->where('s.tenant_id', '0');
$scope->excludeVoidedSalesOrderServices($services, 's');
$queries['service'] = $services->field('s.service_fact_id')->limit(1);

$balance = \think\facade\Db::name('cashier_v3_balance_fact')->alias('b')->where('b.tenant_id', '0');
$scope->excludeVoidedSalesOrderFacts($balance, 'b.tenant_id', 'b.order_id');
$scope->excludeVoidedRechargeOrderFacts($balance, 'b.tenant_id', 'b.order_id');
$scope->excludeVoidedSupplementPaymentFacts($balance, 'b.tenant_id', 'b.store_id', 'b.order_id');
$queries['balance'] = $balance->field('b.fact_id')->limit(1);

foreach ($queries as $name => $query) {
    $query->select()->toArray();
    echo 'PASS local MySQL normal scope: ' . $name . PHP_EOL;
}

$storeId = (int)\think\facade\Db::name('system_store')->where('is_del', 0)->order('id', 'asc')->value('id');
if ($storeId <= 0) throw new RuntimeException('Local fixture needs one active store.');
$reader = new \app\services\query\metric\RegisteredMetricReadServices();
$range = ['start' => '2026-10-01', 'end' => '2026-10-03'];
foreach (['actual_performance', 'consume_amount', 'staff_sales_yeji'] as $metric) {
    $reader->summary($metric, '0', [$storeId], $range);
    echo 'PASS local MySQL registered metric: ' . $metric . PHP_EOL;
}

$reports = new \app\services\report\StoreUnifiedReportServices();
foreach (['sales', 'service', 'customers', 'channels'] as $report) {
    $result = $reports->query([$storeId], [
        'report' => $report, 'start_date' => $range['start'], 'end_date' => $range['end'],
        '_report_scope' => ['mode' => 'stores'],
    ]);
    if (!isset($result['records']) || !is_array($result['records'])) {
        throw new RuntimeException('Report response missing rows: ' . $report);
    }
    echo 'PASS local MySQL store report: ' . $report . PHP_EOL;
}

// A voided first-course projection is deliberately retained, but its old
// order/source must not re-enter the current customer-stage read model.
$voidedCourses = \think\facade\Db::name('cashier_v3_customer_lifecycle_projection')->alias('life')
    ->join('cashier_v3_order_lifecycle_operation op',
        "op.tenant_id=life.tenant_id AND op.source_order_id=life.first_course_order_id")
    ->where('life.tenant_id', '0')->where('op.source_type', 'sales')
    ->where('op.operation_type', 'void')->where('op.status', 'succeeded')
    ->field('life.member_id,life.first_course_order_id')->limit(100)->select()->toArray();
if ($voidedCourses !== []) {
    $normalStages = (new \app\services\report\CustomerLifecycleNormalReadServices())->forMembers(
        '0', array_column($voidedCourses, 'member_id')
    );
    foreach ($voidedCourses as $course) {
        $normalOrderId = (string)($normalStages[(int)$course['member_id']]['first_course_order_id'] ?? '');
        if ($normalOrderId === (string)$course['first_course_order_id']) {
            throw new RuntimeException('Voided first-course order leaked into normal lifecycle.');
        }
    }
    echo 'PASS local MySQL voided first-course projection isolation: ' . count($voidedCourses) . PHP_EOL;
}

if (getenv('ROUND3_SMOKE_ALL') === '1') {
    $input = ['start_date' => $range['start'], 'end_date' => $range['end'], '_report_scope' => ['mode' => 'stores']];
    $cases = [];
    foreach ($reports->catalog() as $entry) {
        $code = (string)$entry['code'];
        $cases['store:' . $code] = static fn() => $reports->query([$storeId], $input + ['report' => $code]);
    }
    foreach ([
        'phase3' => new \app\services\report\StoreUnifiedReportPhaseThreeServices(),
        'phase4' => new \app\services\report\StoreUnifiedReportPhaseFourServices(),
        'phase6' => new \app\services\report\StoreUnifiedReportPhaseSixServices(),
    ] as $family => $service) {
        foreach ($service::reportCodes() as $code) {
            $caseRange = $code === 'six_dimension_performance_distribution'
                ? ['start' => '2026-09-01', 'end' => '2026-09-30'] : $range;
            $cases[$family . ':' . $code] = static fn() => $service->query($code, [$storeId], $caseRange, $input);
        }
    }
    $customerReports = new \app\services\report\CustomerAnalyticsServices();
    foreach ($customerReports::reportCodes() as $code) {
        $cases['customer:' . $code] = static fn() => $customerReports->read($code, [$storeId], $range, $input);
    }
    // These Vue3 dashboard entry points use the same authorized store/date
    // boundary as list reports, but retain their own fact query graphs.
    $dashboardContext = ['tenant_id' => '0', 'store_ids' => [$storeId], 'cost_visible' => true];
    $cases['dashboard:member'] = static fn() => (new \app\services\report\MemberManagementDashboardServices())->dashboard($dashboardContext, $input);
    $cases['dashboard:product'] = static fn() => (new \app\services\report\ProductManagementDashboardServices())->dashboard($dashboardContext, $input);
    $cases['dashboard:group'] = static fn() => (new \app\services\report\GroupManagementDashboardServices())->dashboard($dashboardContext, $input);
    $failures = [];
    foreach ($cases as $name => $run) {
        try {
            $result = $run();
            if (!is_array($result)) throw new RuntimeException('Response is not an array.');
            echo 'PASS Vue3 report smoke: ' . $name . PHP_EOL;
        } catch (Throwable $error) {
            $failures[$name] = $error->getMessage();
            echo 'FAIL Vue3 report smoke: ' . $name . ' | ' . $error->getMessage() . PHP_EOL;
        }
    }
    echo 'Vue3 report smoke totals: ' . (count($cases) - count($failures)) . '/' . count($cases) . PHP_EOL;
    if ($failures !== []) exit(1);
}
