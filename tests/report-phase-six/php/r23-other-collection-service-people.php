<?php
declare(strict_types=1);

/** Contract test: an origin paid partly or wholly by other collection excludes only period people. */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\query\metric\ServiceCustomerMetricReadServices;
use app\services\metric\MetricDictionaryServices;

final class R23ServiceRows
{
    private array $rows;
    public string $fields = '';

    public function __construct(array $rows) { $this->rows = $rows; }
    public function __call(string $name, array $arguments): self { return $this; }
    public function field(string $fields): self { $this->fields = $fields; return $this; }
    public function select(): self { return $this; }
    public function toArray(): array { return $this->rows; }
    public function cursor(): \Generator { yield from $this->rows; }
}

function r23Row(string $line, int $member, int $employee, string $date, bool $other, int $amount = 100): array
{
    return [
        'id' => 1, 'service_store_id' => 23, 'store_id' => 23,
        'business_date' => $date, 'checkout_request_id' => 'CKR-' . $line,
        'source_line_id' => $line, 'service_fact_id' => 'SSF-' . $line,
        'member_id' => $member, 'service_object' => 'self',
        'friend_counts_as_customer' => 1,
        'craftsmen_snapshot_json' => json_encode([['employee_id' => $employee, 'store_id' => 23, 'employee_name' => '员工' . $employee]], JSON_THROW_ON_ERROR),
        'labor_amount_cents' => $amount, 'labor_fee_amount_cents' => 0,
        'fact_id' => $amount ? 'PF-' . $line : '', 'fact_direction' => 'forward',
        'reversal_of' => '', 'employee_id' => $employee,
        'employee_name' => '员工' . $employee, 'role_snapshot' => 'craftsman:round',
        'origin_other_collection' => $other ? 1 : 0,
    ];
}

function r23Assert(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
    echo 'PASS ' . $message . PHP_EOL;
}

$rows = [
    r23Row('other-only', 101, 8, '2026-10-13', true),
    r23Row('mixed-tender', 102, 7, '2026-10-13', true),
    r23Row('eligible', 101, 7, '2026-10-14', false),
    r23Row('eligible-second', 103, 7, '2026-10-14', false),
];
$sources = [];
$factory = static function (string $table) use ($rows, &$sources): R23ServiceRows {
    if ($table !== 'cashier_v3_entitlement_service_fact') throw new RuntimeException('wrong source');
    $source = new R23ServiceRows($rows);
    $sources[] = $source;
    return $source;
};
$reader = new ServiceCustomerMetricReadServices($factory, static function ($query, string $alias): void {});
$range = ['start' => '2026-10-13', 'end' => '2026-10-14'];
$detail = $reader->employeeMetrics('0', [23], $range);
$aggregate = $reader->aggregateEmployeeMetrics('0', [23], $range);
r23Assert($detail['summary_by_store_employee']['23|7']['visit_tenths'] === 30
    && $detail['summary_by_store_employee']['23|7']['people_tenths'] === 20,
    'mixed tender keeps employee visits but removes one period person');
r23Assert($detail['summary_by_store_employee']['23|8']['visit_tenths'] === 10
    && $detail['summary_by_store_employee']['23|8']['people_tenths'] === 0,
    'other-only service has a visit but no period person');
r23Assert(($detail['people_by_labor_key']['23|7|CKR-eligible|eligible'] ?? 0) === 10
    && !isset($detail['people_by_labor_key']['23|8|CKR-other-only|other-only']),
    'eligible later service owns the member; excluded coworker does not dilute it');
r23Assert($aggregate['summary_by_store_employee'] === $detail['summary_by_store_employee'],
    'bounded aggregate and drilldown use the same eligibility');
$sql = $sources[0]->fields;
r23Assert(strpos($sql, "origin_order.id=sv.source_document_id") !== false
    && strpos($sql, 'eb_cashier_v3_card_purchase_receipt') !== false
    && strpos($sql, 'eb_cashier_v3_card_operation_settlement') !== false
    && substr_count($sql, "origin_payment.payment_method='other_collection'") === 3
    && substr_count($sql, "origin_payment.draft_status='committed'") === 3
    && substr_count($sql, 'origin_payment.amount_cents>0') === 3,
    'query resolves original settled purchase and positive committed other collection');

$zero = r23Row('zero-labor', 104, 9, '2026-10-14', true, 0);
$zeroReader = new ServiceCustomerMetricReadServices(
    static fn(string $table): R23ServiceRows => new R23ServiceRows([$zero]),
    static function ($query, string $alias): void {}
);
$zeroSummary = $zeroReader->employeeMetrics('0', [23], $range)['summary_by_store_employee']['23|9'];
r23Assert($zeroSummary['visit_tenths'] === 10 && $zeroSummary['people_tenths'] === 0,
    'zero-labor snapshot fallback obeys the same people-only exclusion');

$definitions = array_column((new MetricDictionaryServices())->getDefinitions(), null, 'code');
r23Assert(strpos($definitions['staff_service_num']['exclude'], '仍计实际服务客次') !== false
    && strpos($definitions['service_people']['exclude'], '其他收款的服务') !== false,
    'dictionary distinguishes retained service visits from excluded period people');
