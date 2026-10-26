<?php

declare(strict_types=1);

// Pure fixture execution requires neither a framework boot nor a database.
$root = dirname(__DIR__, 3);
require $root . '/后端代码/app/services/query/metric/RegisteredMetricReadServices.php';
require $root . '/后端代码/app/services/cashier/v3/fact/CashierV3CheckoutFactPlanV1.php';
require $root . '/后端代码/app/services/report/StoreReportNormalDataScopeServices.php';
require $root . '/后端代码/app/services/report/BasicFinancialReportServices.php';

use app\services\report\BasicFinancialReportServices;
use app\services\query\metric\RegisteredMetricReadServices;

function check($actual, $expected, string $message): void
{
    if ($actual !== $expected) throw new RuntimeException($message . ': ' . var_export($actual, true));
    echo 'PASS ' . $message . "\n";
}

function rejects(callable $run, string $message, string $contains = ''): void
{
    try { $run(); } catch (InvalidArgumentException | RuntimeException $e) {
        if ($contains !== '' && strpos($e->getMessage(), $contains) === false) throw $e;
        echo 'PASS ' . $message . "\n";
        return;
    }
    throw new RuntimeException('Expected refusal: ' . $message);
}

// This double is deliberately not a SQL oracle. It supplies already selected
// fixtures and records that the real service attaches normal-data predicates.
// The independent read-only MySQL test verifies actual query semantics.
final class FinancialFixtureQuery
{
    private array $rows;
    public array $guards = [];
    private int $limit = PHP_INT_MAX;
    private ?array $fields;
    public function __construct(array $rows, ?array $fields = null) { $this->rows = $rows; $this->fields = $fields; }
    public function __call(string $name, array $args) { return $this; }
    public function getTableFields(): array { return $this->fields ?? ['id', 'name', 'salary_status', 'order_id', 'uid', 'store_id', 'kua_store', 'order_type', 'paid']; }
    public function whereNotExists(callable $predicate) { $this->guards[] = $predicate; return $this; }
    public function limit(int $limit) { $this->limit = $limit; return $this; }
    public function select() { return $this; }
    public function toArray(): array { return array_slice($this->rows, 0, $this->limit); }
}

$service = new BasicFinancialReportServices();
check(count($service::codes()), 5, 'five explicit codes');
foreach ($service::codes() as $code) {
    $definitions = $service->definitions($code);
    check(count($definitions) > 0, true, $code . ' metadata without DB');
    foreach ($definitions as $field) {
        check(array_keys($field), ['key', 'label', 'type', 'defaultVisible', 'defaultQuick', 'width', 'fixed', 'money', 'summable'], $code . '/' . $field['key'] . ' full definition');
        if (!in_array($field['type'], ['text', 'date', 'integer', 'decimal'], true)) throw new RuntimeException('Bad field type');
    }
}
$input = ['start_date' => '2026-10-01', 'end_date' => '2026-10-23', '_report_scope' => ['mode' => 'stores']];
rejects(fn() => $service->read('unknown', [], $input), 'unknown report denied');
foreach (['none', 'self_participant', 'unexpected'] as $mode) {
    rejects(fn() => $service->read('basic_store_summary', [1], array_replace($input, ['_report_scope' => ['mode' => $mode]])), $mode . ' cannot widen store finance');
}
rejects(fn() => $service->read('basic_store_summary', ['1'], $input), 'string store identity denied');
rejects(fn() => $service->read('basic_store_summary', [], array_replace($input, ['start_date' => '2026-02-30'])), 'invalid calendar date denied');
check($service->read('basic_store_summary', [], $input)['rows'], [], 'empty authorized stores read no DB');
check($service->read('basic_store_summary', [1], $input + ['as_of_date' => '2026-09-30'])['rows'], [], 'cutoff before period reads no DB');

$base = ['store_id' => 1, 'store_name_snapshot' => 'Historic store', 'business_date' => '2026-10-10',
    'member_id' => 9, 'member_name_snapshot' => 'Historic member', 'order_id' => 'order-1',
    'order_no_snapshot' => 'XS-1', 'source_line_id' => 'line-1', 'balance_change_type' => 'order_payment',
    'reversal_of' => '', 'principal_delta_cents' => -10001, 'bonus_delta_cents' => -99, 'fact_id' => 'f1'];
$fixtureRows = [$base, array_replace($base, ['fact_id' => 'refund', 'balance_change_type' => 'order_refund',
    'business_date' => '2026-10-11', 'reversal_of' => 'f1', 'principal_delta_cents' => 301, 'bonus_delta_cents' => 9]),
    array_replace($base, ['fact_id' => 'repayment', 'balance_change_type' => 'debt_repayment',
        'principal_delta_cents' => -200, 'bonus_delta_cents' => 0])];
$query = new FinancialFixtureQuery($fixtureRows);
$emptyMetrics = new RegisteredMetricReadServices(static fn($table) => new FinancialFixtureQuery([]));
$service = new BasicFinancialReportServices($emptyMetrics, static fn($table) => $query);
// Keep raw fact-reader guard/identity coverage while the public detail now has
// the corrected member/store/day grain, verified in member-day-contract.php.
$rawMethod = new ReflectionMethod($service, 'balanceRows');
if (PHP_VERSION_ID < 80100) $rawMethod->setAccessible(true);
$raw = $rawMethod->invoke($service, [1], ['start' => $input['start_date'], 'end' => $input['end_date']]);
check(count($query->guards), 3, 'sales/recharge/supplement normal guards attached');
check(count($raw), 3, 'internal reader retains separate refund and repayment identities');
check($raw[0]['principal_amount'], 10001, 'historical cents retained before presentation');
check($raw[1]['balance_amount'], -310, 'refund restoration is signed negative debit');
check($raw[1]['reversal_of'], 'f1', 'refund retains original fact link');
$summary = $service->read('basic_balance_order_summary', [1], $input);
check($summary['rows'][0]['balance_paid'], 103, 'payment plus later balance debt repayment');
check($summary['rows'][0]['balance_refunded'], 3.1, 'refund separated from debit');
check($summary['rows'][0]['balance_amount'], 99.9, 'principal and bonus conserved to cent');
check($summary['rows'][0]['store_name'], 'Historic store', 'historical identity preserved');

foreach ([array_replace($base, ['principal_delta_cents' => '1.1']), array_replace($base, ['store_id' => 2]),
    array_replace($base, ['business_date' => '2026-11-01'])] as $bad) {
    $reader = new BasicFinancialReportServices(null, static fn($table) => new FinancialFixtureQuery([$bad]));
    rejects(fn() => $reader->read('basic_balance_order_detail', [1], $input), 'invalid amount or out-of-scope source fails closed');
}
$oversized = new BasicFinancialReportServices(null, static fn($table) => new FinancialFixtureQuery(array_fill(0, 10001, $base)));
rejects(fn() => $oversized->read('basic_balance_order_detail', [1], $input), '10001 source rows refuse, never truncate', 'R51_SOURCE_ROWS_EXCEEDED');
$exactRows = [];
for ($i = 1; $i <= 10000; ++$i) $exactRows[] = array_replace($base, ['fact_id' => 'f' . $i]);
$exact = new BasicFinancialReportServices($emptyMetrics, static fn($table) => new FinancialFixtureQuery($exactRows));
$exactResult = $exact->read('basic_balance_order_detail', [1], $input);
check(count($exactResult['rows']), 1, 'exact 10000 facts aggregate to one complete member-day');
check($exactResult['rows'][0]['balance_amount'], 1010000, 'all 10000 facts conserved without truncation');
$duplicate = new BasicFinancialReportServices(null, static fn($table) => new FinancialFixtureQuery([$base, $base]));
rejects(fn() => $duplicate->read('basic_balance_order_detail', [1], $input), 'duplicate stable fact denied');

// Directly exercise the card projection without calling storeNames: no DB is
// available in this test, and an empty accepted cross-store population is valid.
$card = ['writeoff_id' => 'w1', 'store_id' => 1, 'business_date' => '2026-10-10', 'is_gift' => 0,
    'entitlement_instance_type' => 'card_holder', 'kua_store' => 0,
    'holder_id' => 10, 'verified_holder_id' => 10, 'actual_entitlement_amount_cents' => 10001];
$settings = [['id' => 1, 'name' => 'Transaction store', 'salary_status' => 1],
    ['id' => 2, 'name' => 'Counterparty', 'salary_status' => 1]];
$factory = static fn(array $rows) => static fn(string $table) => new FinancialFixtureQuery(
    $table === 'system_store' ? $settings : ($table === 'cashier_v3_balance_fact' ? [] : $rows));
$sameStore = new BasicFinancialReportServices(null, $factory([$card]));
check($sameStore->read('basic_cross_store_settlement', [1], $input)['rows'], [], 'same-store card is not cross settlement');
$unauthorized = new BasicFinancialReportServices(null, $factory([array_replace($card, ['kua_store' => 2])]));
check($unauthorized->read('basic_cross_store_settlement', [1], $input)['rows'], [], 'unauthorized other endpoint is not exposed');
$missing = new BasicFinancialReportServices(null, $factory([array_replace($card, ['verified_holder_id' => null, 'kua_store' => 2])]));
$gap = $missing->read('basic_cross_store_settlement', [1, 2], $input);
check($gap['data_gaps'][0]['code'], 'R51_CARD_IDENTITY_MISSING', 'missing original holder is an explicit gap');
check($gap['complete'], false, 'gapped settlements cannot claim complete');
$cross = new BasicFinancialReportServices(null, static fn(string $table) => new FinancialFixtureQuery([
    'system_store' => $settings,
    'cashier_v3_balance_fact' => $fixtureRows,
    'store_order' => [['id' => 10, 'order_id' => 'order-1', 'uid' => 9, 'store_id' => 1, 'kua_store' => 2]],
    'cashier_v3_entitlement_writeoff_fact' => [array_replace($card, ['kua_store' => 2])],
][$table] ?? []));
$pair = $cross->read('basic_cross_store_settlement', [1, 2], $input);
check($pair['complete'], true, 'explicit successful transaction dimensions need no funding lots');
check($pair['rows'][0]['store_ids'], [2, 1], 'stable payable-to-receivable direction');
check($pair['rows'][0]['stored_value_payable'], 99.9, 'balance facts include signed refunds in the same pair');
check($pair['rows'][0]['stored_value_receivable'], 99.9, 'stored-value pool conserves every cent');
check($pair['rows'][0]['card_payable'], 100.01, 'card pool uses immutable actual entitlement cents');
check($pair['rows'][0]['card_receivable'], 100.01, 'card pool separately conserves every cent');
$unmapped = new BasicFinancialReportServices(null, static fn(string $table) => new FinancialFixtureQuery([
    'system_store' => $settings, 'cashier_v3_balance_fact' => $fixtureRows,
    'store_order' => [], 'cashier_v3_entitlement_writeoff_fact' => [array_replace($card, ['kua_store' => 2])],
][$table] ?? []));
$partial = $unmapped->read('basic_cross_store_settlement', [1, 2], $input);
check(count($partial['rows']), 1, 'unmapped balance transactions do not hide verified card pair');
check($partial['data_gaps'][0]['source_count'], 3, 'unmapped transaction count is explicit');
check($partial['data_gaps'][0]['amount'], 99.9, 'missing-direction amount retains cents and signs');
$disabled = new BasicFinancialReportServices(null, static fn(string $table) => new FinancialFixtureQuery([
    'system_store' => [array_replace($settings[0], ['salary_status' => 0]), $settings[1]],
    'cashier_v3_entitlement_writeoff_fact' => [array_replace($card, ['kua_store' => 2])],
][$table] ?? []));
check($disabled->read('basic_cross_store_settlement', [1, 2], $input)['rows'], [], 'salary-disabled endpoint excluded');
$localOnly = new BasicFinancialReportServices(null, static fn(string $table) => new FinancialFixtureQuery([
    'system_store' => $settings, 'cashier_v3_balance_fact' => $fixtureRows,
    'store_order' => [['id' => 10, 'order_id' => 'order-1', 'uid' => 9, 'store_id' => 1, 'kua_store' => 0]],
][$table] ?? []));
$localResult = $localOnly->read('basic_cross_store_settlement', [1, 2], $input);
check($localResult['rows'], [], 'unmarked local balance orders are not cross-store');
check($localResult['complete'], true, 'ordinary local debit does not make settlement unavailable');
$schemaGap = new BasicFinancialReportServices(null, static fn($table) => new FinancialFixtureQuery([], []));
check($schemaGap->read('basic_cross_store_settlement', [1], $input)['data_gaps'][0]['code'],
    'R51_CROSS_DIMENSION_FIELD_MISSING', 'missing source fields produce named coverage gap');
echo "PASS R51 financial pure contracts\n";
