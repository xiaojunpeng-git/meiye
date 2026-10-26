<?php
declare(strict_types=1);

// A sealed 007 batch is a report read model; no MySQL or application boot is
// needed to prove the per-store sign, scope and date boundary.
$root = dirname(__DIR__, 3);
require $root . '/后端代码/app/services/query/metric/RegisteredMetricReadServices.php';
require $root . '/后端代码/app/services/report/BasicFinancialReportServices.php';
function config($key, $default = null) { return $key === 'basic_report.cross_store_legacy_batch_id' ? 'test-batch' : $default; }

final class SealedQuery
{
    private array $rows;
    private array $filters = [];
    public function __construct(array $rows) { $this->rows = $rows; }
    public function whereIn(string $field, array $values) { $this->filters[] = static fn($row) => in_array($row[$field] ?? null, $values); return $this; }
    public function where(string $field, $value) { $this->filters[] = static fn($row) => ($row[$field] ?? null) == $value; return $this; }
    public function whereBetween(string $field, array $range) { $this->filters[] = static fn($row) => ($row[$field] ?? '') >= $range[0] && ($row[$field] ?? '') <= $range[1]; return $this; }
    public function __call(string $name, array $arguments) { return $this; }
    public function find() { return $this->toArray()[0] ?? null; }
    public function select() { return $this; }
    public function toArray(): array { return array_values(array_filter($this->rows, function ($row) {
        foreach ($this->filters as $filter) if (!$filter($row)) return false;
        return true;
    })); }
}
function expectSame($actual, $expected, string $label): void {
    if ($actual !== $expected) throw new RuntimeException($label . ': ' . var_export($actual, true));
}
$batch = ['batch_id' => 'test-batch', 'status' => 'sealed', 'range_start' => '2026-01-01',
    'range_end' => '2026-10-26', 'captured_at' => '2026-10-26 17:08:10'];
$orders = [
    ['batch_id' => 'test-batch', 'source_order_id' => 1, 'store_id' => 1, 'kua_store' => 2, 'order_type' => 0, 'amount_cents' => 1812400, 'business_date' => '2026-10-01'],
    ['batch_id' => 'test-batch', 'source_order_id' => 2, 'store_id' => 2, 'kua_store' => 1, 'order_type' => 2, 'amount_cents' => 5774519, 'business_date' => '2026-10-01'],
];
$sources = ['basic_cross_store_legacy_batch' => [$batch],
    'system_store' => [['id' => 1, 'name' => '甲店', 'salary_status' => 1], ['id' => 2, 'name' => '乙店', 'salary_status' => 1]],
    'basic_cross_store_legacy_order' => $orders];
$service = new \app\services\report\BasicFinancialReportServices(null,
    static fn(string $table) => new SealedQuery($sources[$table] ?? []));
$input = ['start_date' => '2026-01-01', 'end_date' => '2026-10-26', '_report_scope' => ['mode' => 'all']];
$result = $service->read('basic_cross_store_settlement', [1, 2], $input);
expectSame(array_column($result['rows'], 'store_id'), [2, 1], 'descending store order');
expectSame($result['rows'][1]['stored_value_settlement'], 18124, 'transaction store receives stored balance');
expectSame($result['rows'][1]['card_settlement'], -57745.19, 'card payer store owes the other store');
expectSame($result['rows'][1]['settlement_total'], -39621.19, 'two pools add with cents');
expectSame(array_sum(array_column($result['rows'], 'settlement_total')), 0.0, 'complete population conserves net');
expectSame($result['source_kind'], 'sealed_legacy_settlement_snapshot', 'historical provenance returned');
expectSame($result['coverage_start'], '2026-01-01', 'historical coverage begins in January');
$single = $service->read('basic_cross_store_settlement', [1], $input);
expectSame(count($single['rows']), 1, 'store viewer receives one row');
expectSame($single['rows'][0]['store_name'], '甲店', 'counterparty identity is not returned');
expectSame($single['rows'][0]['stored_value_settlement'], 18124, 'store filter retains signed counterparty transactions');
try {
    $service->read('basic_cross_store_settlement', [1], array_replace($input, ['end_date' => '2026-10-27']));
    throw new RuntimeException('Expected outside-snapshot refusal');
} catch (InvalidArgumentException $expected) {}
echo "PASS sealed cross-store projection, scope and cutoff\n";

// Optional full private 007 snapshot verification. The file stays outside Git;
// the expected values below were transcribed independently from 007's report.
if (isset($argv[1])) {
    $meta = null; $actualStores = []; $actualOrders = [];
    foreach (file($argv[1], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if ($record['kind'] === 'meta') $meta = $record;
        elseif ($record['kind'] === 'store') $actualStores[] = ['id' => (int)$record['id'], 'name' => $record['name'], 'salary_status' => 1];
        elseif ($record['kind'] === 'order') {
            $money = $record['order_type'] == 0 ? $record['yue_pay_price'] : $record['pay_price'];
            $parts = explode('.', (string)$money);
            $cents = (int)$parts[0] * 100 + (int)str_pad($parts[1] ?? '', 2, '0');
            $actualOrders[] = ['batch_id' => 'test-batch', 'source_order_id' => (int)$record['id'],
                'store_id' => (int)$record['store_id'], 'kua_store' => (int)$record['kua_store'],
                'order_type' => (int)$record['order_type'],
                'business_date' => (new DateTimeImmutable('@' . $record['add_time']))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d'),
                'amount_cents' => $cents];
        }
    }
    expectSame(count($actualOrders), 7371, 'full snapshot order count');
    expectSame($meta['captured_at'], '2026-10-26 17:08:10', 'fixed source cutoff');
    $fullSources = ['basic_cross_store_legacy_batch' => [$batch], 'system_store' => $actualStores,
        'basic_cross_store_legacy_order' => $actualOrders];
    $fullService = new \app\services\report\BasicFinancialReportServices(null,
        static fn(string $table) => new SealedQuery($fullSources[$table] ?? []));
    $full = $fullService->read('basic_cross_store_settlement', array_column($actualStores, 'id'), $input);
    $expectedCents = [
        11 => [1812400, 5774519], 10 => [-354900, 6718877], 9 => [-1712856, 226350],
        8 => [-129100, 2235778], 7 => [-1252000, 6492227], 6 => [859300, -232538],
        4 => [881100, 2939626], 3 => [-656100, -6381347], 2 => [-984544, -7627491],
        1 => [1536700, -10146001],
    ];
    expectSame(count($full['rows']), 10, 'ten legacy stores');
    foreach ($full['rows'] as $row) {
        $id = $row['store_id'];
        expectSame((int)round($row['stored_value_settlement'] * 100), $expectedCents[$id][0], '007 stored balance/' . $id);
        expectSame((int)round($row['card_settlement'] * 100), $expectedCents[$id][1], '007 card balance/' . $id);
        expectSame((int)round($row['settlement_total'] * 100), array_sum($expectedCents[$id]), '007 combined balance/' . $id);
    }
    $oneStore = $fullService->read('basic_cross_store_settlement', [11], $input);
    expectSame(count($oneStore['rows']), 1, 'single-store scope on full snapshot');
    expectSame((int)round($oneStore['rows'][0]['settlement_total'] * 100), 7586919, 'single-store amount matches 007');
    echo "PASS full sealed 007 snapshot reconciles 10 stores / 7371 orders\n";
}
