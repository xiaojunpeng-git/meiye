<?php

declare(strict_types=1);

// Isolated aggregated-source fixtures exercise the real registered Reader.
// SQL success/normal-data scope and real amounts are checked separately against
// the local read-only database; this double is not a second SQL implementation.
$root = dirname(__DIR__, 3);
require $root . '/后端代码/app/services/query/metric/MetricDefinitionRegistry.php';
require $root . '/后端代码/app/services/query/metric/RegisteredMetricReadServices.php';
require $root . '/后端代码/app/services/cashier/v3/fact/CashierV3CheckoutFactPlanV1.php';
require $root . '/后端代码/app/services/report/StoreReportNormalDataScopeServices.php';
require $root . '/后端代码/app/services/report/BasicFinancialReportServices.php';

use app\services\query\metric\RegisteredMetricReadServices;
use app\services\report\BasicFinancialReportServices;

final class SummaryMetricFixtureQuery
{
    private string $table;
    private bool $cash;
    private string $expression = '';
    private string $performance = '';
    public function __construct(string $table, bool $cash) { $this->table = $table; $this->cash = $cash; }
    public function __call(string $method, array $args) { return $this; }
    public function where($field, ...$values) {
        if ($field === 'p.performance_type') $this->performance = $values[0];
        return $this;
    }
    public function fieldRaw(string $expression) { $this->expression = $expression; return $this; }
    public function select() { return $this; }
    public function column(...$args): array { return [1 => 'Store']; }
    public function toArray(): array {
        $values = [];
        if ($this->table === 'cashier_v3_performance_fact') {
            if ($this->performance === 'labor_performance_allocated') {
                $values = strpos($this->expression, 'labor_fee_amount_cents') !== false ? [123, 77] : [12345, 55];
            } elseif ($this->performance === 'consumption_performance_recorded') $values = [15000, 5000];
        } elseif ($this->table === 'cashier_v3_entitlement_service_fact') $values = [137, 3];
        elseif ($this->table === 'cashier_v3_payment_sale_allocation_fact' && $this->cash) {
            $values = strpos($this->expression, 'p.amount_cents < 0') !== false ? [0, 5000] : [20000, 10000];
        }
        $out = [];
        foreach ($values as $i => $value) $out[] = ['store_id' => 1, 'business_date' => '2026-10-' . (10 + $i), 'amount_cents' => $value];
        return $out;
    }
}
function summaryExpect($actual, $expected, string $label): void
{
    if ($actual !== $expected) throw new RuntimeException($label . ': ' . var_export($actual, true));
    $GLOBALS['summary_checks'] = ($GLOBALS['summary_checks'] ?? 0) + 1;
}
$make = static function (bool $cash): BasicFinancialReportServices {
    return new BasicFinancialReportServices(new RegisteredMetricReadServices(
        static fn($table) => new SummaryMetricFixtureQuery($table, $cash)
    ), static fn($table) => new SummaryMetricFixtureQuery('no_balance_facts', false));
};
$input = ['start_date' => '2026-10-01', 'end_date' => '2026-10-23', '_report_scope' => ['mode' => 'stores']];
$service = $make(true);
$result = $service->read('basic_store_summary', [1], $input);
$row = $result['rows'][0];
summaryExpect($row['staff_labor_yeji'], 124, 'registered labour cents converted to yuan');
summaryExpect($row['staff_labor_fee'], 2, 'handwork fee is independently registered amount');
summaryExpect($row['completed_service_item_count'], 140, 'completed projects are NOT divided by 100');
summaryExpect($row['actual_performance'], 250, 'actual remains cash 300 minus cash refund 50, not labour or fee');
summaryExpect(abs($row['consumption_cash_ratio'] - 200 / 300 * 100) < 0.0000001, true, 'ratio of period totals, not daily mean');
summaryExpect($make(false)->read('basic_store_summary', [1], $input)['rows'][0]['consumption_cash_ratio'], null, 'zero cash denominator is null');
$defs = array_column($service->definitions('basic_store_summary'), null, 'key');
foreach (['staff_labor_yeji', 'staff_labor_fee'] as $field) {
    summaryExpect($defs[$field]['type'], 'decimal', $field . ' money decimal');
    summaryExpect($defs[$field]['money'], true, $field . ' monetary');
    summaryExpect($defs[$field]['summable'], true, $field . ' summable');
}
summaryExpect($defs['completed_service_item_count']['type'], 'integer', 'service quantity integer metadata');
summaryExpect($defs['completed_service_item_count']['money'], false, 'count is not money');
summaryExpect($defs['completed_service_item_count']['summable'], true, 'count is summable');
summaryExpect(in_array('completed_service_item_count', $result['sum_fields'], true), true, 'provider can sum counts');
summaryExpect($defs['consumption_cash_ratio']['money'], false, 'percentage is not money');
summaryExpect($defs['consumption_cash_ratio']['summable'], false, 'percentage cannot be summed');
summaryExpect(in_array('consumption_cash_ratio', $result['sum_fields'], true), false, 'provider cannot sum ratios');
summaryExpect(isset($defs['service_visit']), false, 'staff share is not advertised as store visits');
summaryExpect($result['data_gaps'][0]['code'], 'R51_STORE_SERVICE_VISIT_UNAVAILABLE', 'explicit independent store visit gap');
foreach ($service->codes() as $code) {
    if ($code === 'basic_store_summary') continue;
    $keys = array_column($service->definitions($code), 'key');
    foreach (['staff_labor_yeji', 'staff_labor_fee', 'completed_service_item_count', 'consumption_cash_ratio'] as $field) {
        summaryExpect(in_array($field, $keys, true), false, $code . ' does not acquire store-only field');
    }
}
$daily = $service->read('basic_daily_reconciliation', [1], $input);
summaryExpect(isset($daily['rows'][0]['staff_labor_yeji']), false, 'daily rows have no store-only labour metric');
summaryExpect($daily['data_gaps'], [], 'daily has no new visit gap');
echo 'PASS store-summary contracts checks=' . $GLOBALS['summary_checks'] . "\n";
