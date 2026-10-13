<?php

declare(strict_types=1);

/** Read-only local Ruihao regression for the registered-fact dashboard path. */
require '/var/www/html/vendor/autoload.php';

use app\services\query\metric\RegisteredMetricReadServices;
use app\services\report\GroupManagementDashboardServices;
use think\facade\Db;

(new think\App('/var/www/html/'))->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('Only the local ruihao Docker database is allowed');
}

Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
try {
    $stores = array_map('intval', Db::name('system_store')->where('is_del', 0)
        ->where('name', '<>', '总部')->column('id'));
    $context = ['tenant_id' => '0', 'store_ids' => $stores];
    $range = ['start' => '2026-10-01', 'end' => '2026-10-13'];
    $started = microtime(true);
    $service = new GroupManagementDashboardServices();
    $dashboard = $service->dashboard($context, [
        'start_date' => $range['start'], 'end_date' => $range['end'],
    ]);
    $elapsed = round(microtime(true) - $started, 3);
    $reader = new RegisteredMetricReadServices();
    $cards = array_column($dashboard['cards'], 'value_cents', 'metric_code');
    $actual = $reader->summary('actual_performance', '0', $stores, $range);
    if ((int)$cards['actual_performance'] !== $actual) {
        throw new RuntimeException('Actual-performance card differs from the registered Reader');
    }
    $rankings = $dashboard['rankings']['store']['records'];
    $rankedActual = array_sum(array_column($rankings, 'actual_performance_cents'));
    if ($rankedActual !== (int)$dashboard['goals']['month']['actual_performance_cents']) {
        throw new RuntimeException('Store rankings and monthly goal use different actuals');
    }
    if ($dashboard['aggregation_health']['status_code'] !== 'realtime'
        || $dashboard['aggregation_caught_up'] !== false) {
        throw new RuntimeException('Legacy aggregate was incorrectly presented as current');
    }
    $denied = false;
    try {
        $service->dashboard(['tenant_id' => '0', 'store_ids' => [$stores[0]]], [
            'start_date' => $range['start'], 'end_date' => $range['end'],
            'store_ids' => (string)$stores[1],
        ]);
    } catch (InvalidArgumentException $exception) {
        $denied = true;
    }
    if (!$denied) throw new RuntimeException('Requested store escaped backend scope');
    $categoryId = (int)Db::name('store_product_category')->where('type', 0)
        ->where('relation_id', 0)->order('id', 'asc')->value('id');
    if ($categoryId > 0) {
        $filtered = $service->dashboard(['tenant_id' => '0', 'store_ids' => [$stores[0]]], [
            'start_date' => $range['start'], 'end_date' => $range['end'],
            'category_id' => $categoryId,
        ]);
        if ($filtered['aggregation_health']['status_code'] !== 'not_applicable'
            || $filtered['scope']['store_ids'] !== [$stores[0]]) {
            throw new RuntimeException('Category read changed scope or used an unfiltered aggregate');
        }
    }
    echo json_encode([
        'result' => 'PASS', 'elapsed_seconds' => $elapsed, 'store_count' => count($stores),
        'actual_performance_cents' => $actual, 'month_goal_cents' => $dashboard['goals']['month']['target_amount_cents'],
        'ranked_store_count' => count($rankings), 'category_checked' => $categoryId > 0,
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
} finally {
    Db::rollback();
}
