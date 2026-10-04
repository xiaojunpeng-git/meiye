<?php
declare(strict_types=1);

$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\query\metric\ServiceCustomerMetricReadServices;

/** A completed service joins once to each immutable labor allocation. */
function serviceRows(string $line, array $people, string $snapshot, string $object = 'self', int $friendCounts = 1): array
{
    $rows = [];
    foreach ($people as $employeeId => $name) {
        $rows[] = [
            'service_store_id' => 23, 'store_id' => 23, 'business_date' => '2026-10-04',
            'checkout_request_id' => 'CKR-test', 'source_line_id' => $line,
            'service_fact_id' => 'SSF-' . $line, 'member_id' => 1130058,
            'service_object' => $object, 'friend_counts_as_customer' => $friendCounts,
            'craftsmen_snapshot_json' => $snapshot, 'labor_amount_cents' => 16800,
            'labor_fee_amount_cents' => 0, 'fact_id' => 'PF-' . $line . '-' . $employeeId,
            'fact_direction' => 'forward', 'reversal_of' => '',
            'employee_id' => $employeeId, 'employee_name' => $name,
            'role_snapshot' => 'craftsman:round',
        ];
    }
    return $rows;
}

/** Keep the shared reader's query contract while supplying immutable local facts. */
function serviceMetrics(array $rows): array
{
    $factory = static function (string $table) use ($rows) {
        if ($table !== 'cashier_v3_entitlement_service_fact') throw new RuntimeException('unexpected source');
        return new class($rows) {
            private $rows;
            public function __construct(array $rows) { $this->rows = $rows; }
            public function __call(string $name, array $arguments) { return $this; }
            public function select() { return $this; }
            public function toArray(): array { return $this->rows; }
        };
    };
    return (new ServiceCustomerMetricReadServices($factory, static function ($query, string $alias): void {}))
        ->employeeMetrics('0', [23], ['start' => '2026-10-04', 'end' => '2026-10-04']);
}

function expectShares(array $actual, array $expected, string $label): void
{
    if ($actual !== $expected) throw new RuntimeException($label . ': ' . json_encode($actual));
    echo 'PASS ' . $label . PHP_EOL;
}

$snapshot = json_encode([
    ['employeeId' => 101, 'storeId' => 23, 'positionId' => 69],
    ['employeeId' => 102, 'storeId' => 23, 'positionId' => 69],
    ['employeeId' => 201, 'storeId' => 23, 'positionId' => 67,
        'performanceIndependent' => true, 'allocationGroupKey' => 'independent:67'],
], JSON_THROW_ON_ERROR);
$people = [101 => '普通甲', 102 => '普通乙', 201 => '店长'];
$rows = array_merge(serviceRows('A', $people, $snapshot), serviceRows('B', $people, $snapshot));
$metrics = serviceMetrics($rows);
$summary = $metrics['summary_by_store_employee'];
expectShares(array_map(static function (int $id) use ($summary): int {
    return $summary['23|' . $id]['visit_tenths'];
}, array_keys($people)), [5, 5, 10], 'normal pair shares one visit; independent manager owns another');
expectShares(array_map(static function (int $id) use ($summary): int {
    return $summary['23|' . $id]['people_tenths'];
}, array_keys($people)), [5, 5, 10], 'period people use the same frozen role groups');
expectShares(array_map(static function (int $id) use ($metrics): int {
    return array_sum(array_filter($metrics['detail_by_labor_key'], static function (string $key) use ($id): bool {
        return strpos($key, '23|' . $id . '|') === 0;
    }, ARRAY_FILTER_USE_KEY));
}, array_keys($people)), [5, 5, 10], 'daily visits attach to one stable detail line per employee');

$excluded = serviceMetrics(serviceRows('C', $people, $snapshot, 'friend', 0));
expectShares($excluded['summary_by_store_employee'], [], 'friend not counted remains excluded');
$selfExcluded = serviceMetrics(serviceRows('C-self', $people, $snapshot, 'self', 0));
expectShares($selfExcluded['summary_by_store_employee'], [], 'member self marked not counted is excluded for all staff groups');

$threeNormal = [101 => '普通甲', 102 => '普通乙', 103 => '普通丙'];
$normalSnapshot = json_encode(array_map(static function (int $id): array {
    return ['employeeId' => $id, 'storeId' => 23, 'positionId' => 69];
}, array_keys($threeNormal)), JSON_THROW_ON_ERROR);
$normal = serviceMetrics(serviceRows('D', $threeNormal, $normalSnapshot));
expectShares(array_map(static function (int $id) use ($normal): int {
    return $normal['summary_by_store_employee']['23|' . $id]['visit_tenths'];
}, array_keys($threeNormal)), [3, 3, 4], 'normal group keeps stable tenth-unit remainder');

$twoManagers = [201 => '店长甲', 202 => '店长乙'];
$managerSnapshot = json_encode([
    ['employeeId' => 201, 'storeId' => 23, 'positionId' => 67,
        'performanceIndependent' => true, 'allocationGroupKey' => 'independent:67'],
    ['employeeId' => 202, 'storeId' => 23, 'positionId' => 67,
        'performanceIndependent' => true, 'allocationGroupKey' => 'independent:67'],
], JSON_THROW_ON_ERROR);
$samePosition = serviceMetrics(serviceRows('E', $twoManagers, $managerSnapshot));
expectShares(array_map(static function (int $id) use ($samePosition): int {
    return $samePosition['summary_by_store_employee']['23|' . $id]['visit_tenths'];
}, array_keys($twoManagers)), [5, 5], 'employees in one independent position share its single visit');
