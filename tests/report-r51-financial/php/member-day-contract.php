<?php

declare(strict_types=1);

// Independent member/day business scenarios; the real registered recharge
// Reader executes against already-scoped fixtures. SQL guards are verified in
// the separate read-only MySQL test, without writing production-like fixtures.
$root = dirname(__DIR__, 3);
require $root . '/后端代码/app/services/query/metric/RegisteredMetricReadServices.php';
require $root . '/后端代码/app/services/cashier/v3/fact/CashierV3CheckoutFactPlanV1.php';
require $root . '/后端代码/app/services/report/StoreReportNormalDataScopeServices.php';
require $root . '/后端代码/app/services/report/BasicFinancialReportServices.php';

use app\services\query\metric\RegisteredMetricReadServices;
use app\services\report\BasicFinancialReportServices;

final class MemberDayFixtureQuery
{
    private array $rows;
    private int $limit = PHP_INT_MAX;
    public function __construct(array $rows) { $this->rows = $rows; }
    public function __call(string $name, array $args) { return $this; }
    public function limit(int $limit) { $this->limit = $limit; return $this; }
    public function select() { return $this; }
    public function toArray(): array { return array_slice($this->rows, 0, $this->limit); }
}

function dayExpect($actual, $expected, string $message): void
{
    if ($actual !== $expected) throw new RuntimeException($message . ': ' . var_export($actual, true));
    $GLOBALS['day_checks'] = ($GLOBALS['day_checks'] ?? 0) + 1;
}
function dayReject(callable $run, string $message): void
{
    try { $run(); } catch (RuntimeException $e) { ++$GLOBALS['day_checks']; return; }
    throw new RuntimeException('Expected refusal: ' . $message);
}

$base = ['store_id' => 1, 'store_name_snapshot' => 'Snapshot store', 'business_date' => '2026-10-10',
    'member_id' => 9, 'member_name_snapshot' => 'Snapshot member', 'order_id' => 'o1',
    'order_no_snapshot' => 'XS-1', 'source_line_id' => 'line-1', 'balance_change_type' => 'order_payment',
    'reversal_of' => '', 'principal_delta_cents' => -10001, 'bonus_delta_cents' => -99, 'fact_id' => 'b1'];
$balance = [$base,
    array_replace($base, ['fact_id' => 'b2', 'order_id' => 'o2', 'principal_delta_cents' => -2000, 'bonus_delta_cents' => -500]),
    array_replace($base, ['fact_id' => 'b3', 'balance_change_type' => 'order_refund', 'reversal_of' => 'b1', 'principal_delta_cents' => 301, 'bonus_delta_cents' => 9]),
    array_replace($base, ['fact_id' => 'b4', 'member_id' => 10, 'principal_delta_cents' => -5000, 'bonus_delta_cents' => 0]),
    array_replace($base, ['fact_id' => 'b5', 'store_id' => 2, 'principal_delta_cents' => -4000, 'bonus_delta_cents' => 0]),
    array_replace($base, ['fact_id' => 'b6', 'business_date' => '2026-10-11', 'principal_delta_cents' => -1000, 'bonus_delta_cents' => 0]),
    array_replace($base, ['fact_id' => 'b7', 'business_date' => '2026-10-13', 'principal_delta_cents' => -1000, 'bonus_delta_cents' => 0]),
    array_replace($base, ['fact_id' => 'b8', 'business_date' => '2026-10-14', 'principal_delta_cents' => -1000, 'bonus_delta_cents' => 0]),
];
$receipt = ['fact_id' => 'r1', 'store_id' => 1, 'member_id' => 9, 'business_date' => '2026-10-10', 'amount_cents' => 5000];
$recharges = [$receipt, $receipt,
    array_replace($receipt, ['fact_id' => 'r2', 'amount_cents' => 2500]),
    array_replace($receipt, ['fact_id' => 'r3', 'amount_cents' => -1000]),
    array_replace($receipt, ['fact_id' => 'r4', 'member_id' => 10, 'amount_cents' => 1500]),
    array_replace($receipt, ['fact_id' => 'r5', 'store_id' => 2, 'amount_cents' => 1234]),
    array_replace($receipt, ['fact_id' => 'r6', 'business_date' => '2026-10-11', 'amount_cents' => 2000]),
    array_replace($receipt, ['fact_id' => 'r7', 'member_id' => 99, 'amount_cents' => 99999]),
    array_replace($receipt, ['fact_id' => 'r8', 'business_date' => '2026-10-12', 'amount_cents' => 10000]),
    array_replace($receipt, ['fact_id' => 'r9', 'business_date' => '2026-10-13', 'amount_cents' => -300]),
];
$makeService = static function (array $debits, array $receipts): BasicFinancialReportServices {
    $reader = new RegisteredMetricReadServices(static fn($table) => new MemberDayFixtureQuery($receipts));
    return new BasicFinancialReportServices($reader, static fn($table) => new MemberDayFixtureQuery($debits));
};
$service = $makeService($balance, $recharges);
$input = ['start_date' => '2026-10-01', 'end_date' => '2026-10-23', '_report_scope' => ['mode' => 'stores']];
$detail = $service->read('basic_balance_order_detail', [1, 2], $input);
$rows = array_column($detail['rows'], null, 'row_id');
dayExpect(count($rows), 6, 'one row per member/store/day, no recharge-only population');
$day = $rows['balance-day:1:9:2026-10-10'];
dayExpect($day['balance_amount'], 122.9, 'same day multiple orders and signed refund aggregated');
dayExpect($day['principal_amount'], 117, 'real principal remains its own immutable net');
dayExpect($day['bonus_amount'], 5.9, 'real bonus remains its own immutable net');
dayExpect($day['recharge_amount'], 65, 'receipt once, split receipts retained, signed cash refund included');
dayExpect($day['balance_minus_recharge'], 57.9, 'explicit date comparison instead of principal attribution');
dayExpect($day['has_recharge'], '是', 'positive same-store member-day recharge');
dayExpect(isset($day['fact_id']), false, 'public detail is not a fact-grain replacement report');
dayExpect($rows['balance-day:1:10:2026-10-10']['recharge_amount'], 15, 'other member isolated');
dayExpect($rows['balance-day:2:9:2026-10-10']['recharge_amount'], 12.34, 'other store isolated with cents');
dayExpect($rows['balance-day:1:9:2026-10-11']['balance_minus_recharge'], -10, 'negative difference is retained, never clamped');
dayExpect($rows['balance-day:1:9:2026-10-13']['has_recharge'], '否', 'refund-only day is not new recharge');
dayExpect($rows['balance-day:1:9:2026-10-13']['balance_minus_recharge'], 13, 'refund net participates on its own business day');
dayExpect($rows['balance-day:1:9:2026-10-14']['recharge_amount'], 0, 'no recharge defaults zero');
dayExpect($rows['balance-day:1:9:2026-10-14']['has_recharge'], '否', 'no recharge flag');
$summary = $service->read('basic_balance_order_summary', [1, 2], $input);
foreach ($summary['rows'] as $store) {
    $days = array_filter($detail['rows'], static fn($r) => $r['store_id'] === $store['store_id']);
    foreach ($summary['sum_fields'] as $field) {
        $cents = array_sum(array_map(static fn($r) => (int)round($r[$field] * 100), $days));
        dayExpect((int)round($store[$field] * 100), $cents, 'store summary equals same member-day population/' . $field);
    }
}
$definitions = array_column($service->definitions('basic_balance_order_detail'), null, 'key');
dayExpect($definitions['balance_minus_recharge']['label'], '余额支付减同日充值', 'business-explicit difference label');
dayExpect(strpos(json_encode($definitions, JSON_UNESCAPED_UNICODE), '实际扣卡金额'), false, 'forbidden legacy label not published');
dayExpect(strpos($detail['field_sources']['balance_minus_recharge'], '不代表余额资金来源') !== false, true, 'source explanation rejects inferred funding attribution');
dayExpect($service->read('basic_balance_order_detail', [1, 2], $input + ['limit' => 1, 'page' => 999])['rows'], $detail['rows'], 'service never paginates');
dayExpect($makeService([], $recharges)->read('basic_balance_order_detail', [1, 2], $input)['rows'], [], 'recharge-only query is empty');
dayReject(fn() => $makeService($balance, [$receipt, array_replace($receipt, ['amount_cents' => 1])])->read('basic_balance_order_detail', [1, 2], $input), 'conflicting receipt identity');
dayReject(fn() => $makeService($balance, [array_replace($receipt, ['store_id' => 3])])->read('basic_balance_order_detail', [1, 2], $input), 'unauthorized recharge store');
dayReject(fn() => $makeService($balance, [array_replace($receipt, ['amount_cents' => '1.1'])])->read('basic_balance_order_detail', [1, 2], $input), 'fractional source cents');
dayReject(fn() => $makeService($balance, array_fill(0, 10001, $receipt))->read('basic_balance_order_detail', [1, 2], $input), 'source overflow never truncates');
echo 'PASS member/store/day comparison contracts checks=' . $GLOBALS['day_checks'] . "\n";
