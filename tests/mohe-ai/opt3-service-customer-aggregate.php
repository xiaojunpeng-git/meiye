<?php
// Pure immutable-fact fixtures; never bootstrap the app or connect to a database.
require __DIR__ . '/fixture-autoload.php';
use app\services\query\metric\ServiceCustomerMetricReadServices;
use app\services\query\metric\RegisteredMetricReadServices;

$checks = 0;
function scCheck(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); ++$checks; }
function scRow(int $subject, int $service, int $employee, array $change = []): array {
    return array_replace([
        'id' => $service * 10 + $employee, 'service_store_id' => 23, 'store_id' => 23,
        'business_date' => '2026-10-04', 'checkout_request_id' => 'req-' . $service,
        'source_line_id' => 'line-' . $service, 'service_fact_id' => 'service-' . sprintf('%09d', $service),
        'member_id' => $subject, 'service_object' => 'self', 'friend_counts_as_customer' => 1,
        'craftsmen_snapshot_json' => '[]', 'labor_amount_cents' => 100, 'labor_fee_amount_cents' => 0,
        'fact_id' => 'fact-' . $service . '-' . $employee, 'fact_direction' => 'forward', 'reversal_of' => '',
        'employee_id' => $employee, 'employee_name' => 'Employee-' . $employee, 'role_snapshot' => 'craftsman:round',
    ], $change);
}
function scSortKey(array $row): array {
    $subject = (int)($row['friend_counts_as_customer'] ?? 1) === 1 && strtolower(trim($row['service_object'])) === 'self' && $row['member_id'] > 0
        ? 'member:' . $row['member_id'] : 'record:' . $row['service_fact_id'];
    return [$row['service_store_id'], $subject, $row['service_fact_id'], $row['id']];
}
/** The injected source distinguishes bounded cursor consumption from materialization. */
function scFactory(callable $source, int &$calls): callable {
    return static function (string $table) use ($source, &$calls) {
        if ($table !== 'cashier_v3_entitlement_service_fact') throw new RuntimeException('unexpected source');
        ++$calls;
        return new class($source) {
            private $source;
            public function __construct(callable $source) { $this->source = $source; }
            public function __call(string $name, array $args) { return $this; }
            public function cursor(): Generator { yield from ($this->source)(true); }
            public function select() { return $this; }
            public function toArray(): array { return iterator_to_array(($this->source)(false), false); }
        };
    };
}
function scReader(array $rows): ServiceCustomerMetricReadServices {
    $calls = 0;
    return new ServiceCustomerMetricReadServices(scFactory(static function (bool $stream) use ($rows): Generator {
        usort($rows, $stream ? static fn(array $a, array $b): int => scSortKey($a) <=> scSortKey($b) : static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
        yield from $rows;
    }, $calls), static function () {});
}
$range = ['start' => '2026-10-01', 'end' => '2026-10-31'];
$rows = [];
foreach ([1, 2, 3] as $subject) {
    foreach ([1, 2, 3] as $day) {
        foreach ([1, 2] as $project) {
            $service = $subject * 100 + $day * 10 + $project;
            foreach ([1, 2, 3] as $employee) {
                $rows[] = scRow($subject, $service, $employee, ['business_date' => '2026-10-0' . $day,
                    'role_snapshot' => $employee === 1 ? 'craftsman:point' : 'craftsman:round']);
            }
            $rows[] = scRow($subject, $service, 4, ['business_date' => '2026-10-0' . $day,
                'fact_allocation_group' => 'independent:67', 'fact_position_id' => 67, 'fact_performance_independent' => 1]);
        }
    }
}
// Same member on another store, friend-per-service, and excluded customer snapshots.
$rows[] = scRow(1, 900, 1, ['store_id' => 24, 'service_store_id' => 24]);
foreach ([901, 902] as $id) $rows[] = scRow(1, $id, 1, ['service_object' => 'friend']);
foreach (['friend', 'self'] as $offset => $object) $rows[] = scRow(1, 910 + $offset, 1, ['service_object' => $object, 'friend_counts_as_customer' => 0]);
// Reversal and replacement retain all co-workers; role group belongs to replacement fact.
$rows[] = scRow(4, 920, 1);
$rows[] = scRow(4, 920, 2);
$rows[] = scRow(4, 920, 1, ['id' => 99991, 'fact_id' => 'reverse', 'fact_direction' => 'reversal', 'reversal_of' => 'fact-920-1']);
$rows[] = scRow(4, 920, 1, ['id' => 99992, 'fact_id' => 'replacement', 'fact_allocation_group' => 'independent:67', 'fact_position_id' => 67, 'fact_performance_independent' => 1]);
// Zero-value snapshots count only when no labor fact ever existed.
$snapshot = json_encode([['employeeId' => 1, 'storeId' => 23, 'name' => 'Employee-1', 'isPointCustomer' => true],
    ['employeeId' => 2, 'storeId' => 23, 'name' => 'Employee-2']]);
$rows[] = scRow(5, 930, 1, ['fact_id' => '', 'fact_direction' => '', 'labor_amount_cents' => 0, 'craftsmen_snapshot_json' => $snapshot]);
$rows[] = scRow(6, 931, 1, ['labor_amount_cents' => 0, 'craftsmen_snapshot_json' => $snapshot]);
$rows[] = scRow(6, 931, 1, ['id' => 99993, 'fact_id' => 'reverse-only', 'fact_direction' => 'reversal', 'reversal_of' => 'fact-931-1', 'labor_amount_cents' => 0, 'craftsmen_snapshot_json' => $snapshot]);
$reader = scReader($rows); $full = $reader->employeeMetrics('0', [23, 24], $range); $compact = $reader->aggregateEmployeeMetrics('0', [23, 24], $range);
foreach (['summary_by_store_employee', 'daily_by_store_employee'] as $key) scCheck($full[$key] === $compact[$key], 'compact equals detail allocation: ' . $key);
scCheck(count($compact) === 2 && !isset($compact['detail_context_by_labor_key']), 'aggregate has no retained detail projection');
scCheck($compact['summary_by_store_employee']['23|1']['point_visit_tenths'] === 100, 'point customer counts once per day, including zero value');
scCheck($compact['summary_by_store_employee']['24|1']['people_tenths'] === 10, 'same member in another store keeps separate denominator');
// The compact metrics used by staff dashboards must agree with payroll details.
$excludedSnapshot = json_encode([
    ['employeeId' => 1, 'storeId' => 23, 'serviceCountsExcluded' => true,
        'excludedServicePositionId' => 67, 'isPointCustomer' => true, 'name' => 'Employee-1'],
    ['employeeId' => 2, 'storeId' => 23, 'name' => 'Employee-2'],
], JSON_THROW_ON_ERROR);
$excludedReader = scReader([scRow(9, 932, 2, ['craftsmen_snapshot_json' => $excludedSnapshot])]);
$fullExcluded = $excludedReader->employeeMetrics('0', [23], $range);
$compactExcluded = $excludedReader->aggregateEmployeeMetrics('0', [23], $range);
scCheck($fullExcluded['summary_by_store_employee'] === $compactExcluded['summary_by_store_employee'],
    'role-excluded mixed service yields identical compact and detail summaries');
$excludedSummary = $compactExcluded['summary_by_store_employee'];
scCheck($excludedSummary['23|1']['point_visit_tenths'] === 10 && $excludedSummary['23|1']['visit_tenths'] === 0
    && $excludedSummary['23|2']['visit_tenths'] === 10 && $excludedSummary['23|2']['people_tenths'] === 10,
    'role-excluded service preserves point counts and attributes visits and people to the eligible coworker');
$calls = 0;
$factory = scFactory(static function (bool $stream) use ($rows): Generator { usort($rows, static fn(array $a, array $b): int => scSortKey($a) <=> scSortKey($b)); yield from $rows; }, $calls);
$cached = new RegisteredMetricReadServices($factory, static function () {}, static function () {}, false, true);
$cached->summary('staff_service_num', '0', [23, 24], $range);
$cached->summary('staff_point_visit_num', '0', [24, 23], $range);
$cached->summary('service_people', '0', [23, 24], $range);
scCheck($calls === 1, 'transaction cache shares one scope across all three metrics');
$cached->summary('staff_service_num', '0', [23, 24], ['start' => '2026-10-01', 'end' => '2026-10-30']);
scCheck($calls === 2, 'different period reads again');
$default = new RegisteredMetricReadServices($factory, static function () {}, static function () {}, false);
$default->summary('staff_service_num', '0', [23, 24], $range);
$default->summary('staff_service_num', '0', [23, 24], $range);
scCheck($calls === 4, 'default long-lived reader cannot return transaction-stale cached data');
// Filtering after allocation must not turn employee one's 0.3 into one full visit.
$pair = $cached->personnelTotals('staff_service_num', '0', [23, 24], $range, [['store_id' => 23, 'employee_id' => 3]]);
scCheck(array_sum(array_column($pair, 'metric_value')) === 36, 'selected employee retains all co-worker allocation divisors');
$bad = scRow(1, 1, 1, ['fact_allocation_group' => 'independent:99', 'fact_position_id' => 67, 'fact_performance_independent' => 1]);
try { scReader([$bad])->aggregateEmployeeMetrics('0', [23], $range); throw new RuntimeException('missing rejection'); }
catch (app\services\query\metric\MetricQueryContractException $e) { scCheck($e->getErrorCode() === 'METRIC_SERVICE_SOURCE_INVALID', 'inconsistent adjustment group fails closed'); }
// Subject and service order must not change historical snapshot name or tie rankings.
$nameRows = [scRow(2, 1, 1, ['id' => 1, 'employee_name' => 'Earlier snapshot']),
    scRow(1, 2, 1, ['id' => 2, 'employee_name' => 'Later snapshot'])];
// A group's earliest other employee determines legacy daily-group ordering;
// choosing just MIN(p.id) of the requested employee would still be incorrect.
$nameRows[] = scRow(3, 3, 2, ['id' => 0, 'business_date' => '2026-10-03']);
$nameRows[] = scRow(3, 3, 1, ['id' => 10, 'business_date' => '2026-10-03', 'employee_name' => 'First daily-group snapshot']);
$nameReader = scReader($nameRows); $fullNames = $nameReader->employeeMetrics('0', [23], $range); $compactNames = $nameReader->aggregateEmployeeMetrics('0', [23], $range);
foreach (['summary_by_store_employee', 'daily_by_store_employee'] as $key) scCheck($fullNames[$key] === $compactNames[$key], 'historical snapshot order preserved: ' . $key);
scCheck($compactNames['summary_by_store_employee']['23|1']['employee_name'] === 'First daily-group snapshot', 'name follows legacy daily-group order, not minimum employee id alone');
// Empty snapshots must not hide the first subsequent nonempty source name.
$nameRows[3]['employee_name'] = ''; $nameRows[0]['employee_name'] = '';
$nameReader = scReader($nameRows); $fullNames = $nameReader->employeeMetrics('0', [23], $range); $compactNames = $nameReader->aggregateEmployeeMetrics('0', [23], $range);
scCheck($fullNames['summary_by_store_employee'] === $compactNames['summary_by_store_employee'], 'empty historical snapshots retain next nonempty name');
// One customer with 100,000 services must not retain those service records.
// A second distribution exercises 20,000 separate customers and independent groups.
foreach (['one_customer' => 1, 'many_customers' => 20000] as $label => $subjects) {
    $calls = 0; $count = $subjects === 1 ? 100000 : 5;
    $factory = scFactory(static function (bool $stream) use ($subjects, $count): Generator {
        if (!$stream) throw new RuntimeException('pressure source must never materialize');
        $keys = range(1, $subjects); usort($keys, static fn(int $a, int $b): int => strcmp('member:' . $a, 'member:' . $b));
        foreach ($keys as $subject) for ($service = 1; $service <= $count; ++$service) {
            $id = $subject * 100001 + $service;
            foreach ([1, 2] as $employee) yield scRow($subject, $id, $employee, ['business_date' => $service % 2 ? '2026-10-01' : '2026-10-02']);
        }
    }, $calls);
    $started = microtime(true);
    $actual = (new ServiceCustomerMetricReadServices($factory, static function () {}))->aggregateEmployeeMetrics('0', [23], $range);
    scCheck($actual['summary_by_store_employee']['23|1']['visit_tenths'] === $subjects * 10, $label . ' daily dedup after complete subject union');
    scCheck($actual['summary_by_store_employee']['23|2']['people_tenths'] === $subjects * 5, $label . ' cross-day people dedup');
    scCheck(memory_get_peak_usage(true) < 128 * 1024 * 1024, $label . ' stays below production 128MB');
    echo json_encode(['pressure' => $label, 'services' => $subjects * $count, 'joined_rows' => $subjects * $count * 2,
        'elapsed_ms' => (int)round((microtime(true) - $started) * 1000), 'peak_bytes' => memory_get_peak_usage(true)]) . PHP_EOL;
}
// A pathological single service is rejected as a complete unit, never clipped.
$calls = 0;
$factory = scFactory(static function (bool $stream): Generator {
    for ($i = 1; $i <= 10001; ++$i) yield scRow(1, 1, 1, ['id' => $i]);
}, $calls);
try { (new ServiceCustomerMetricReadServices($factory, static function () {}))->aggregateEmployeeMetrics('0', [23], $range); throw new RuntimeException('missing service bound'); }
catch (app\services\query\metric\MetricQueryContractException $e) { scCheck($e->getErrorCode() === 'METRIC_GROUP_OUTPUT_TOO_LARGE', 'oversized one-service history fails before incomplete allocation'); }
echo json_encode(['suite' => 'OPT3-service-customer-aggregate', 'checks' => $checks, 'pass' => true]) . PHP_EOL;
