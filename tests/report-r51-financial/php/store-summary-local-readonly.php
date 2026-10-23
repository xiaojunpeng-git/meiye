<?php

declare(strict_types=1);

use app\services\query\metric\RegisteredMetricReadServices;
use app\services\report\BasicFinancialReportServices;

// Local-only real fact reconciliation. No fixture insertion, configuration,
// query host change, migration or business-data mutation is permitted here.
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
$config = config('database.connections.mysql');
if (($config['hostname'] ?? '') !== 'mysql' || ($config['database'] ?? '') !== 'ruihao') {
    throw new RuntimeException('Only local mysql/ruihao is allowed');
}
function storeSummaryCheck(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
    $GLOBALS['store_summary_checks'] = ($GLOBALS['store_summary_checks'] ?? 0) + 1;
}
think\facade\Db::execute('SET TRANSACTION READ ONLY');
think\facade\Db::startTrans();
try {
    $stores = [23, 79, 89, 118, 175, 177];
    $range = ['start' => '2026-10-01', 'end' => '2026-10-23'];
    $input = ['start_date' => $range['start'], 'end_date' => $range['end'], '_report_scope' => ['mode' => 'stores']];
    $service = new BasicFinancialReportServices();
    $reader = new RegisteredMetricReadServices();
    $result = $service->read('basic_store_summary', $stores, $input);
    $rows = array_column($result['rows'], null, 'store_id');
    $totals = [];
    foreach (['staff_labor_yeji', 'staff_labor_fee', 'completed_service_item_count'] as $metric) {
        $total = 0;
        foreach ($reader->storeTotals($metric, '0', $stores, $range) as $point) {
            $value = $rows[$point['store_id']][$metric] ?? 0;
            $native = $metric === 'completed_service_item_count' ? $value : (int)round($value * 100);
            storeSummaryCheck($native === $point['metric_value'], 'per-store registered value/' . $metric);
            if ($metric === 'completed_service_item_count') storeSummaryCheck(is_int($value), 'count retains integer unit');
            $total += $native;
        }
        storeSummaryCheck($total === $reader->summary($metric, '0', $stores, $range), 'whole result registered reconciliation/' . $metric);
        $totals[$metric] = $total;
    }
    storeSummaryCheck($totals['completed_service_item_count'] > 0 && $totals['staff_labor_yeji'] > 0, 'real positive service and labour population exercised');
    foreach ($rows as $row) {
        storeSummaryCheck((int)round($row['actual_performance'] * 100)
            === (int)round($row['cash_performance'] * 100) - (int)round($row['refund_performance'] * 100), 'actual remains cash minus refund');
        $cash = (int)round($row['cash_performance'] * 100);
        if ($cash === 0) storeSummaryCheck($row['consumption_cash_ratio'] === null, 'zero denominator null');
        else storeSummaryCheck(abs($row['consumption_cash_ratio'] - (int)round($row['consume_amount'] * 100) / $cash * 100) < 0.0000001, 'period consumption to cash percent');
    }
    storeSummaryCheck($result['data_gaps'][0]['metric_code'] === 'service_visit', 'missing independent store visits disclosed');

    // Project away only this explicitly requested addition to prove old store
    // metrics and balance columns retain their prior definitions and values.
    $added = ['staff_labor_yeji', 'staff_labor_fee', 'completed_service_item_count', 'consumption_cash_ratio'];
    $legacy = $result;
    unset($legacy['data_as_of']);
    $legacy['metric_version'] = 'r51-financial-facts-v1';
    $legacy['complete'] = true;
    $legacy['data_gaps'] = [];
    $legacy['sum_fields'] = array_values(array_diff($legacy['sum_fields'], $added));
    foreach ($added as $field) unset($legacy['field_sources'][$field]);
    foreach ($legacy['rows'] as &$row) foreach ($added as $field) unset($row[$field]);
    unset($row);
    $definitions = array_values(array_filter($service->definitions('basic_store_summary'), static fn($f) => !in_array($f['key'], $added, true)));
    storeSummaryCheck(hash('sha256', json_encode([$definitions, $legacy])) === 'e69c35debd5522884222da6896cc525510857df7f3dcc3774424d00b35a3746c', 'all existing store-summary fields unchanged');
    $daily = $service->read('basic_daily_reconciliation', $stores, $input);
    unset($daily['data_as_of']);
    storeSummaryCheck(hash('sha256', json_encode([$service->definitions('basic_daily_reconciliation'), $daily])) === 'a44610c2707782d95489475a204416bcafc5b2bcd714278ac40acf43de760730', 'daily complete contract and result unchanged');
    echo 'PASS store-summary local read-only checks=' . $GLOBALS['store_summary_checks']
        . ' rows=' . count($rows) . ' registered-native-unit-totals=' . json_encode($totals) . "\n";
} finally {
    think\facade\Db::rollBack();
}
