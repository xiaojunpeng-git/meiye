<?php

declare(strict_types=1);

use app\services\query\metric\RegisteredMetricReadServices;
use app\services\report\BasicFinancialReportServices;
use app\services\report\StoreReportNormalDataScopeServices;
use think\facade\Db;

// Run via stdin in the existing local mohe-app container. No business, schema,
// annotation, cache, job or model writes are performed by this test.
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
$config = config('database.connections.mysql');
if (($config['hostname'] ?? '') !== 'mysql' || ($config['database'] ?? '') !== 'ruihao') {
    throw new RuntimeException('Only the local mysql/ruihao database is allowed');
}
function verify(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
    $GLOBALS['financial_checks'] = ($GLOBALS['financial_checks'] ?? 0) + 1;
}
function cents($yuan): int { return (int)round($yuan * 100); }

Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
try {
    $service = new BasicFinancialReportServices();
    $metrics = new RegisteredMetricReadServices();
    $stores = [23, 79, 89, 118, 175, 177];
    $input = ['start_date' => '2026-10-01', 'end_date' => '2026-10-23', '_report_scope' => ['mode' => 'stores']];
    $results = [];
    foreach (['basic_store_summary', 'basic_balance_order_detail', 'basic_balance_order_summary', 'basic_daily_reconciliation'] as $code) {
        $results[$code] = $service->read($code, $stores, $input);
        echo $code . ' rows=' . count($results[$code]['rows']) . "\n";
        $ids = array_column($results[$code]['rows'], 'row_id');
        verify(count($ids) === count(array_unique($ids)), $code . ' stable unique rows: ' . count($ids));
        foreach ($results[$code]['rows'] as $row) {
            verify(in_array($row['store_id'], $stores, true), $code . ' scoped row');
            foreach ($results[$code]['sum_fields'] as $field) verify(is_numeric($row[$field]), $code . '/' . $field . ' numeric yuan');
        }
        foreach ($service->definitions($code) as $field) {
            verify(isset($results[$code]['field_sources'][$field['key']]), $code . '/' . $field['key'] . ' source explanation');
        }
    }
    $detail = $results['basic_balance_order_detail']['rows'];
    verify(count($detail) > 0, 'real nonempty balance population exercised');
    foreach (['principal_amount', 'bonus_amount', 'balance_amount'] as $field) {
        $expected = array_sum(array_map(static fn($row) => cents($row[$field]), $detail));
        foreach (['basic_store_summary', 'basic_balance_order_summary', 'basic_daily_reconciliation'] as $code) {
            verify(array_sum(array_map(static fn($row) => cents($row[$field]), $results[$code]['rows'])) === $expected, $code . '/' . $field . ' reconciles every detail cent');
        }
    }
    // Independently read the authoritative debit population using the shared
    // normal guard, to catch accidental multiplication or forgotten refunds.
    $query = Db::name('cashier_v3_balance_fact')->alias('p')->where('p.tenant_id', '0')
        ->whereIn('p.store_id', $stores)->where('p.status', 'effective')
        ->whereBetween('p.business_date', [$input['start_date'], $input['end_date']])
        ->whereIn('p.balance_change_type', ['order_payment', 'order_refund', 'debt_repayment']);
    $normal = new StoreReportNormalDataScopeServices();
    $normal->excludeVoidedSalesOrderFacts($query, 'p.tenant_id', 'p.order_id');
    $normal->excludeVoidedRechargeOrderFacts($query, 'p.tenant_id', 'p.order_id');
    $normal->excludeVoidedSupplementPaymentFacts($query, 'p.tenant_id', 'p.store_id', 'p.order_id');
    $sum = $query->fieldRaw('COUNT(*) n,COALESCE(SUM(-(p.principal_delta_cents+p.bonus_delta_cents)),0) amount')->find();
    $rawMethod = new ReflectionMethod($service, 'balanceRows');
    if (PHP_VERSION_ID < 80100) $rawMethod->setAccessible(true);
    $rawBalance = $rawMethod->invoke($service, $stores, ['start' => $input['start_date'], 'end' => $input['end_date']]);
    verify((int)$sum['n'] === count($rawBalance), 'independent SQL has exactly one internal row per balance fact');
    verify((int)$sum['amount'] === array_sum(array_map(static fn($r) => cents($r['balance_amount']), $detail)), 'independent fact net equals detail');
    $voided = Db::name('cashier_v3_balance_fact')->alias('p')
        ->join('cashier_v3_order_lifecycle_operation v', 'v.tenant_id=p.tenant_id AND v.source_order_id=p.order_id')
        ->where('p.tenant_id', '0')->whereIn('p.store_id', $stores)
        ->where('v.source_type', 'sales')->where('v.operation_type', 'void')->where('v.status', 'succeeded')
        ->where('p.balance_change_type', 'order_payment')
        ->whereBetween('p.business_date', [$input['start_date'], $input['end_date']])->column('p.fact_id');
    verify($voided !== [], 'actual successfully voided original debit exercised');
    verify(array_intersect($voided, array_column($rawBalance, 'fact_id')) === [], 'successfully voided originals absent on all business days');

    foreach (['sales_amount', 'cash_performance', 'refund_performance', 'actual_performance', 'consume_amount', 'recharge_amount'] as $metric) {
        $registered = $metrics->summary($metric, '0', $stores, ['start' => $input['start_date'], 'end' => $input['end_date']]);
        foreach (['basic_store_summary', 'basic_daily_reconciliation'] as $code) {
            verify(array_sum(array_map(static fn($row) => cents($row[$metric]), $results[$code]['rows'])) === $registered, $code . '/' . $metric . ' equals registered metric');
        }
    }
    foreach ($results['basic_daily_reconciliation']['rows'] as $row) {
        verify(cents($row['actual_performance']) === cents($row['cash_performance']) - cents($row['refund_performance']), 'daily actual cash minus successful cash refunds');
        verify(cents($row['balance_amount']) === cents($row['balance_paid']) - cents($row['balance_refunded']), 'daily balance payment minus restoration');
    }
    $cut = $service->read('basic_balance_order_detail', $stores, $input + ['as_of_date' => '2026-10-10']);
    verify(count($cut['rows']) < count($detail), 'as-of cutoff shrinks actual population');
    foreach ($cut['rows'] as $row) verify($row['business_date'] <= '2026-10-10', 'cutoff uses business date');
    $repeated = $service->read('basic_balance_order_detail', $stores, $input + ['page' => 999, 'limit' => 1]);
    verify($repeated['rows'] === $detail, 'provider pagination inputs cannot truncate service result');
    $cross = $service->read('basic_cross_store_settlement', $stores, $input);
    foreach ($cross['rows'] as $store) {
        verify(cents($store['settlement_total']) === cents($store['stored_value_settlement']) + cents($store['card_settlement']), 'store net combines both pools');
    }
    echo 'basic_cross_store_settlement rows=' . count($cross['rows'])
        . ' complete=' . ($cross['complete'] ? 'true' : 'false')
        . ' gaps=' . json_encode($cross['data_gaps'], JSON_UNESCAPED_UNICODE) . "\n";

    // Execute the real card SQL on an empty scope date too: catches wrong table
    // names/columns without inserting any fixtures into the business database.
    $empty = $service->read('basic_cross_store_settlement', [23], [
        'start_date' => '2000-01-01', 'end_date' => '2000-01-01', '_report_scope' => ['mode' => 'stores']]);
    verify($empty['rows'] === [], 'cross-store identity SQL executes on real schema');
    echo 'PASS R51 local read-only fact reconciliation checks=' . $GLOBALS['financial_checks'] . "\n";
} finally {
    Db::rollBack();
}
