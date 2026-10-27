<?php

declare(strict_types=1);

use app\services\query\metric\RegisteredMetricReadServices;
use app\services\report\BasicFinancialReportServices;

// Existing local database only; transaction-level read-only protects business
// data while normal source facts and actual registered recharge receipts are
// independently grouped to verify the member/store/day comparison.
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
$config = config('database.connections.mysql');
if (($config['hostname'] ?? '') !== 'mysql' || ($config['database'] ?? '') !== 'ruihao') {
    throw new RuntimeException('Only local mysql/ruihao is allowed');
}
function comparisonCheck(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
    $GLOBALS['comparison_checks'] = ($GLOBALS['comparison_checks'] ?? 0) + 1;
}
function comparisonCents($yuan): int { return (int)round($yuan * 100); }

think\facade\Db::execute('SET TRANSACTION READ ONLY');
think\facade\Db::startTrans();
try {
    $stores = [23, 79, 89, 118, 175, 177];
    $input = ['start_date' => '2026-10-01', 'end_date' => '2026-10-23', '_report_scope' => ['mode' => 'stores']];
    $range = ['start' => $input['start_date'], 'end' => $input['end_date']];
    $service = new BasicFinancialReportServices();
    $rawMethod = new ReflectionMethod($service, 'balanceRows');
    if (PHP_VERSION_ID < 80100) $rawMethod->setAccessible(true);
    $raw = $rawMethod->invoke($service, $stores, $range);
    $expected = [];
    foreach ($raw as $fact) {
        $key = $fact['store_id'] . ':' . $fact['member_id'] . ':' . $fact['business_date'];
        if (!isset($expected[$key])) $expected[$key] = ['balance_amount' => 0, 'principal_amount' => 0,
            'bonus_amount' => 0, 'recharge_amount' => 0, 'has_recharge' => '否', 'source_count' => 0];
        foreach (['balance_amount', 'principal_amount', 'bonus_amount'] as $field) $expected[$key][$field] += $fact[$field];
        ++$expected[$key]['source_count'];
    }
    $receipts = (new RegisteredMetricReadServices())->rechargeCashFacts('0', $stores, $range);
    $seen = [];
    $matchedReceipts = 0;
    foreach ($receipts as $receipt) {
        $id = (string)$receipt['fact_id'];
        if (isset($seen[$id])) continue;
        $seen[$id] = true;
        $key = $receipt['store_id'] . ':' . $receipt['member_id'] . ':' . $receipt['business_date'];
        if (!isset($expected[$key])) continue;
        $amount = (int)$receipt['amount_cents'];
        $expected[$key]['recharge_amount'] += $amount;
        if ($amount > 0) $expected[$key]['has_recharge'] = '是';
        ++$matchedReceipts;
    }
    $detail = $service->read('basic_balance_order_detail', $stores, $input);
    comparisonCheck(count($detail['rows']) === count($expected), 'exact member/store/day population');
    comparisonCheck(count($raw) > count($expected), 'real multi-order member-day exercised');
    foreach ($detail['rows'] as $row) {
        $key = $row['store_id'] . ':' . $row['member_id'] . ':' . $row['business_date'];
        comparisonCheck(isset($expected[$key]), 'stable identities match expected day');
        foreach (['balance_amount', 'principal_amount', 'bonus_amount', 'recharge_amount'] as $field) {
            comparisonCheck(comparisonCents($row[$field]) === $expected[$key][$field], 'per-day source cents/' . $field);
        }
        comparisonCheck($row['has_recharge'] === $expected[$key]['has_recharge'], 'actual same-store member-day recharge flag');
        comparisonCheck(comparisonCents($row['balance_minus_recharge']) === $expected[$key]['balance_amount'] - $expected[$key]['recharge_amount'], 'per-day difference');
    }
    $summary = $service->read('basic_balance_order_summary', $stores, $input);
    foreach ($summary['rows'] as $store) {
        foreach ($summary['sum_fields'] as $field) {
            $sum = 0;
            foreach ($detail['rows'] as $day) if ($day['store_id'] === $store['store_id']) $sum += comparisonCents($day[$field]);
            comparisonCheck(comparisonCents($store[$field]) === $sum, 'summary uses only matched member-day population/' . $field);
        }
    }
    // The cross-store projection intentionally changed to per-store net rows.
    // The unrelated daily reconciliation keeps its exact prior snapshot.
    foreach ([
        'basic_daily_reconciliation' => 'a44610c2707782d95489475a204416bcafc5b2bcd714278ac40acf43de760730',
    ] as $code => $baseline) {
        $result = $service->read($code, $stores, $input);
        unset($result['data_as_of']);
        comparisonCheck(hash('sha256', json_encode([$service->definitions($code), $result])) === $baseline, 'other report unchanged/' . $code);
    }
    echo 'PASS member-day local read-only checks=' . $GLOBALS['comparison_checks']
        . ' balance_facts=' . count($raw) . ' member_days=' . count($expected)
        . ' registered_recharge_receipts=' . count($receipts) . ' matched_receipts=' . $matchedReceipts . "\n";
} finally {
    think\facade\Db::rollBack();
}
