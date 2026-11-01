<?php
declare(strict_types=1);

/** Local read-only reconciliation: no production connections, fixture writes or historical changes. */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
use app\services\statistics\PlatformBusinessDashboardServices;
use app\services\statistics\BusinessDashboardServices;
use app\services\query\metric\RegisteredMetricReadServices;
use think\facade\Db;
if (config('database.connections.mysql.hostname') !== 'mysql' || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('local database only');
}
$count = 0;
$check = static function (bool $ok, string $label) use (&$count) { if (!$ok) throw new RuntimeException($label); ++$count; echo "PASS $label\n"; };
$cents = static function ($v): int { return (int)bcmul((string)$v, '100', 0); };
Db::execute('SET TRANSACTION READ ONLY'); Db::startTrans();
try {
    $service = new PlatformBusinessDashboardServices(); $reader = new RegisteredMetricReadServices();
    $stores = [145, 13, 108]; $start = strtotime('2026-10-08'); $end = strtotime('2026-10-11 23:59:59');
    $range = ['start' => '2026-10-08', 'end' => '2026-10-11'];
    $overview = $service->overview($stores, $start, $end, '');
    $cards = array_column($overview['cards'], null, 'metric_code');
    $check(array_keys($cards) === BusinessDashboardServices::METRIC_CODES, 'all original ten cards retained');
    $maps = $service->collectStoreMetricMaps($stores, $start, $end, '');
    foreach (PlatformBusinessDashboardServices::MONEY_CODES as $metric => $registered) {
        $expected = $reader->summary($registered, '0', $stores, $range);
        $check($cents($cards[$metric]['value']) === $expected, "$metric summary matches store/merchant Reader");
        $check(array_sum(array_map($cents, $maps[$metric])) === $expected, "$metric store totals conserve cents");
        $trend = $service->trend($stores, $metric, $start, $end);
        $check(array_sum(array_map($cents, $trend['series'])) === $expected, "$metric daily totals conserve cents");
        $export = $service->exportMoneyDetail($stores, $metric, $start, $end);
        $check(count($export['list']) === $export['list_count'] && array_sum(array_column($export['list'], 'amount_cents')) === $expected,
            "$metric complete detail/export reconciles");
        foreach ($export['list'] as $row) $check(in_array($row['store_id'], $stores, true), "$metric detail remains scoped");
    }
    $old = new BusinessDashboardServices();
    $legacy = $old->collectStoreMetricMaps($stores, $start, $end, '');
    foreach (['new_profile_count', 'casual_customer_count', 'new_customer_count', 'reservation_customer'] as $metric) {
        $check($legacy[$metric] === $maps[$metric] && $cards[$metric]['source_label'] === '原客数口径', "$metric unchanged and labelled legacy");
    }
    $cash = $reader->summary('cash_performance', '0', $stores, $range);
    $refund = $reader->summary('refund_performance', '0', $stores, $range);
    $check($refund > 0 && $cash !== 0, 'real cash and refund facts exercised');
    $check($cents($cards['actual_performance']['value']) === $cash - $refund, 'actual uses cash minus refund without split deduction');
    $first = $service->moneyMetricDetail($stores, 'cash_performance', $start, $end, '', 1, 20);
    $second = $service->moneyMetricDetail($stores, 'cash_performance', $start, $end, '', 2, 20);
    $export = $service->exportMoneyDetail($stores, 'cash_performance', $start, $end);
    $check($first['list_count'] > 40 && count($first['list']) === 20 && count($second['list']) === 20, 'real pagination first and second page contain twenty');
    $check(array_slice($export['list'], 0, 20) === $first['list'] && array_slice($export['list'], 20, 20) === $second['list'], 'export uses exact same stable pagination order');
    $lastPage = (int)ceil($first['list_count']/20);
    $last = $service->moneyMetricDetail($stores, 'cash_performance', $start, $end, '', $lastPage, 20);
    $check(count($last['list']) === (($first['list_count']-1)%20)+1, 'last page remainder exact');
    $negative = $service->moneyMetricDetail([145], 'actual_performance', $start, $start+86399, '', 1, 20);
    $check($cents($negative['total']) < 0, 'refund-only day keeps negative actual performance');
    $single = $service->exportMoneyDetail([145], 'actual_performance', $start, $end);
    $check(count(array_filter($single['list'], static function ($r) { return $r['store_id'] !== 145; })) === 0, 'narrow store scope cannot leak other store');
    $emptyStart = strtotime('2001-01-01');
    $empty = $service->moneyMetricDetail([145], 'cash_performance', $emptyStart, $emptyStart+86399, '', 1, 20);
    $check($empty['list'] === [] && $empty['list_count'] === 0 && $empty['total'] === '0.00', 'empty query returns explicit zero total');
    foreach ([[], [0], [-1]] as $badStores) {
        try { $service->trend($badStores, 'cash_performance', $start, $end); $ok = false; } catch (InvalidArgumentException $e) { $ok = true; }
        $check($ok, 'invalid scope rejected before Reader');
    }
    try { $service->moneyMetricDetail([145], 'not_registered', $start, $end, '', 1, 20); $ok = false; } catch (InvalidArgumentException $e) { $ok = true; }
    $check($ok, 'arbitrary money code rejected');
    echo "SUCCESS $count checks\n";
} finally { Db::rollback(); }
