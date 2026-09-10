<?php
// Local generated report-view fixtures only. No framework boot, model, account or database.
$root = dirname(__DIR__, 2);
require_once $root . '/后端代码/app/services/cashier/v3/fact/CashierV3CheckoutFactPlanV1.php';
$dir = $root . '/后端代码/app/services/query/';
foreach (['UnifiedQueryException', 'UnifiedQueryJson'] as $class) require_once $dir . $class . '.php';
foreach (['MetricQueryContractException', 'GroupPerformanceMetricReadServices', 'MetricReadViewStore', 'MetricGroupedProjection', 'MetricReadViewServices'] as $class) require_once $dir . 'metric/' . $class . '.php';

use app\services\query\metric\MetricQueryContractException;
use app\services\query\metric\GroupPerformanceMetricReadServices;
use app\services\query\metric\MetricReadViewStore;
use app\services\query\metric\MetricReadViewServices;

$checks = 0;
function queryCheck($condition, $label) { global $checks; if (!$condition) throw new RuntimeException($label); ++$checks; }
function queryReject(callable $call, $code) { try { $call(); } catch (MetricQueryContractException $e) { queryCheck($e->getErrorCode() === $code, 'wrong error ' . $e->getErrorCode()); return; } throw new RuntimeException('expected ' . $code); }
class ReadViewFixtureQuery {
    public $calls = [];
    private $row;
    private $table;
    public function __construct(array $row, string $table='') { $this->row = $row; $this->table=$table; }
    public function __call($name, $args) { $this->calls[] = [$name, $args]; if ($name === 'whereExists') $args[0]($this); return $this; }
    public function find() { return $this->row; }
}
$seen = [];
$reader = new GroupPerformanceMetricReadServices(function ($table) use (&$seen) {
    $amount=$table==='cashier_v3_payment_sale_allocation_fact'?'12345':($table==='cashier_v3_performance_fact'?'1001':'0');
    $query = new ReadViewFixtureQuery(['amount_cents' => $amount], $table);
    $seen[] = [$table, $query]; return $query;
}, function ($query, $tenant, $order) { $query->scopeNormal($tenant, $order); });
$range = ['start' => '2026-09-08', 'end' => '2026-09-08'];
queryCheck($reader->cashTotals('0', [1], $range) === ['gross_cents' => 12345, 'refund_cents' => -12345], 'registered cash and refund share the same signed fact source');
queryCheck($reader->metricTotal('0', [1], $range, 'consume_amount') === 1001, 'consumption exact cents');
$cashCalls = json_encode($seen[0][1]->calls); $consumptionCalls = json_encode($seen[count($seen)-1][1]->calls);
foreach (['p.tenant_id', 'p.store_id', 'p.business_date', 'effective', 'scopeNormal', 'COALESCE'] as $needle) queryCheck(strpos($cashCalls, $needle) !== false, 'cash source contract ' . $needle);
queryCheck(strpos($consumptionCalls, "service_status='completed'") !== false, 'consumption requires completed service');
queryCheck(strpos($cashCalls, 'limit') === false && strpos($consumptionCalls, 'limit') === false, 'no fact limit hidden in metric totals');
$capabilities = MetricReadViewServices::metricCapabilities();
queryCheck($capabilities['consume_amount']['ai_query_ready'] === true && count($capabilities['consume_amount']['query_shapes']) === 4, 'consumption implemented contracts registered');
    queryCheck($capabilities['cash_performance']['ai_query_ready'] === true && count($capabilities['cash_performance']['query_shapes']) === 4 && $capabilities['cash_performance']['readiness_reasons'] === [], 'cash recharge-inclusive contract registered');
    queryCheck($capabilities['actual_performance']['ai_query_ready'] === true, 'confirmed actual formula is registered');
    queryCheck($capabilities['completed_service_item_count']['ai_query_ready'] === true
        && $capabilities['customer_active']['ai_query_ready'] === true,
        'registered count contracts are query-ready without becoming cents');
foreach ([[], [0], ['1'], [1, 1], [1 => 1]] as $stores) queryReject(function () use ($reader, $range, $stores) { $reader->cashTotals('0', $stores, $range); }, 'METRIC_SOURCE_SCOPE_INVALID');
queryReject(function () use ($reader, $range) { $reader->metricTotal('0', [1], $range, 'invented'); }, 'METRIC_NOT_REGISTERED');
foreach (['1.00', '1e3', '9223372036854775808', 1.5, null] as $bad) {
    if ($bad === null) continue; // absent DB sum is the established empty zero path.
    $badReader = new GroupPerformanceMetricReadServices(function () use ($bad) { return new ReadViewFixtureQuery(['amount_cents' => $bad]); }, function () {});
    queryReject(function () use ($badReader, $range) { $badReader->cashTotals('0', [1], $range); }, 'METRIC_SOURCE_AMOUNT_INVALID');
}

$temp = sys_get_temp_dir() . '/mohe-query-fixture-' . bin2hex(random_bytes(10));
$now = 1788830000;
$clock = function () use (&$now) { return $now; };
$store = new MetricReadViewStore($temp, str_repeat('fixture-only-', 4), $clock);
$binding = ['instance_id' => 'fixture-instance', 'subject_ref' => 'fixture-person', 'terminal' => 'platform', 'tenant_id' => '0', 'permission_version' => 'fixture-v1', 'report_capability_code' => 'group_management_dashboard', 'scope_provider_code' => 'fixture-report-scope', 'scope_mode' => 'stores', 'store_ids' => [2, 1]];
$authCalls = 0; $reads = 0;
$authorize = function ($principal) use (&$binding, &$authCalls) { ++$authCalls; if ($principal !== ['fixture' => true]) throw new RuntimeException('untrusted principal'); return $binding; };
$transaction = function ($callback) use ($reader, &$reads) { ++$reads; return $callback($reader); };
$service = new MetricReadViewServices($store, $authorize, $transaction, $clock);
$query = ['query_shape' => 'summary', 'metric_codes' => ['cash_performance', 'consume_amount'], 'start_date' => $range['start'], 'end_date' => $range['end'], 'compare_range' => null, 'store_ids' => [], 'business_filters' => []];
$principal = ['fixture' => true];
try {
    $view = $service->create($principal, $query);
    queryCheck($reads === 1 && $authCalls === 3, 'single batch read with before/after/replay authorization');
    queryCheck(count($view['results']) === 2 && $view['results'][0]['amount_cents'] === 12345, 'view exact totals');
    queryCheck($view['ai_query_ready'] === true && isset($view['metric_versions']['cash_performance'], $view['metric_versions']['consume_amount']), 'cash and consumption keep separate canonical versions');
    queryCheck($view['read_view_kind'] === 'materialized_report_projection' && !isset($view['fact_watermark']), 'not an invented commit watermark');
    queryCheck($view['binding']['store_ids'] === [1, 2], 'canonical scope');
    queryCheck($service->replay($principal, $query, $view['read_consistency_ref']) === $view && $reads === 1, 'replay never fetches latest data');
    $path = $temp . '/' . $view['read_consistency_ref'] . '.json';
    queryCheck((fileperms($path) & 0777) === 0600, 'view file owner-only');
    queryReject(function () use ($service, $principal, $query, $view) { $q = $query; $q['start_date'] = '2026-09-07'; $service->replay($principal, $q, $view['read_consistency_ref']); }, 'METRIC_READ_BINDING_MISMATCH');
    foreach (['instance_id', 'subject_ref', 'terminal', 'permission_version', 'scope_provider_code'] as $key) {
        $original = $binding[$key]; $binding[$key] = $key === 'terminal' ? 'merchant' : 'other';
        queryReject(function () use ($service, $principal, $query, $view) { $service->replay($principal, $query, $view['read_consistency_ref']); }, 'METRIC_READ_BINDING_MISMATCH');
        $binding[$key] = $original;
    }
    $binding['scope_mode'] = 'self_participant';
    queryReject(function () use ($service, $principal, $query) { $service->create($principal, $query); }, 'METRIC_PERMISSION_GRAIN_UNAVAILABLE');
    $binding['scope_mode'] = 'stores';
    foreach (['drill', 'invented'] as $shape) queryReject(function () use ($service, $principal, $query, $shape) { $q = $query; $q['query_shape'] = $shape; $service->create($principal, $q); }, 'METRIC_QUERY_SHAPE_UNAVAILABLE');
    foreach (['consumption_performance', 'refund_amount', 'invented'] as $metric) queryReject(function () use ($service, $principal, $query, $metric) { $q = $query; $q['metric_codes'] = [$metric]; $service->create($principal, $q); }, 'METRIC_NOT_REGISTERED');
    queryReject(function () use ($service, $principal, $query) { $q = $query; $q['business_filters'] = ['person' => 1]; $service->create($principal, $q); }, 'METRIC_QUERY_SHAPE_UNAVAILABLE');
    queryReject(function () use ($service, $principal, $query) { $q = $query; $q['store_ids'] = [3]; $service->create($principal, $q); }, 'METRIC_PERMISSION_DENIED');
    queryReject(function () use ($service, $principal, $query) { $service->create($principal, $query + ['sql' => 'forbidden']); }, 'METRIC_QUERY_SCHEMA_INVALID');
    queryReject(function () use ($service, $principal, $query) { $q = $query; $q['start_date'] = '2026-08-09'; $service->create($principal, $q); }, 'METRIC_QUERY_COVERAGE_UNAVAILABLE');
    $compare = $query; $compare['query_shape'] = 'comparison'; $compare['compare_range'] = ['start' => '2026-09-07', 'end' => '2026-09-07'];
    $comparison = $service->create($principal, $compare);
    queryCheck(count($comparison['results']) === 4 && $reads === 2, 'both comparison periods inside one transaction');
    $shrunk = $query; $shrunk['store_ids'] = [1];
    $narrow = $service->create($principal, $shrunk);
    queryCheck($narrow['binding']['store_ids'] === [1], 'requested scope narrows');
    $countQuery = $query; $countQuery['metric_codes'] = ['completed_service_item_count', 'customer_active'];
    $countView = $service->create($principal, $countQuery);
    queryCheck($countView['ai_query_ready'] === true && array_column($countView['results'], 'count') === [0, 0]
        && array_column($countView['results'], 'storage_unit') === ['count', 'count'],
        'count result preserves registered units through the immutable read view');
    $binding['store_ids'] = [2];
    queryReject(function () use ($service, $principal, $shrunk, $narrow) { $service->replay($principal, $shrunk, $narrow['read_consistency_ref']); }, 'METRIC_PERMISSION_DENIED');
    $binding['store_ids'] = [1, 2];
    $wrongKeyStore = new MetricReadViewStore($temp, str_repeat('wrong-test-key', 4), $clock);
    queryReject(function () use ($wrongKeyStore, $view) { $wrongKeyStore->get($view['read_consistency_ref']); }, 'METRIC_READ_VIEW_UNAVAILABLE');
    queryReject(function () use ($store) { $store->get('../outside'); }, 'METRIC_READ_VIEW_UNAVAILABLE');
    $now += 86400;
    queryReject(function () use ($service, $principal, $query, $view) { $service->replay($principal, $query, $view['read_consistency_ref']); }, 'METRIC_READ_VIEW_UNAVAILABLE');
    queryCheck($store->cleanup() === 4, 'all expired views physically removed');
    queryCheck(iterator_count(new FilesystemIterator($temp)) === 0, 'no fixture content retained');
} finally {
    foreach (new DirectoryIterator($temp) as $file) if (!$file->isDot() && $file->isFile() && !$file->isLink()) unlink($file->getPathname());
    rmdir($temp);
}
echo 'query-read-view: PASS (' . $checks . " checks; fixture DB, no production readiness claimed)\n";
