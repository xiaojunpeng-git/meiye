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
    // The sale-mix row is server-owned and ordered; every count and receipt
    // must stay inside the registered sale/cash populations for this scope.
    $saleMix = (array)($dashboard['sale_type_cards'] ?? []);
    if (array_column($saleMix, 'code') !== ['normal_card', 'experience_card', 'project', 'product']) {
        throw new RuntimeException('Sale-type card order or population is invalid');
    }
    $saleQuantity = $reader->summary('sales_quantity', '0', $stores, $range);
    $mixQuantity = array_sum(array_column($saleMix, 'sales_quantity'));
    $mixCash = array_sum(array_column($saleMix, 'cash_performance_cents'));
    if ($mixQuantity < 0 || $mixQuantity > $saleQuantity || $mixCash < 0
        || $mixCash > $reader->summary('cash_performance', '0', $stores, $range)) {
        throw new RuntimeException('Sale-type totals escaped registered sale or cash facts');
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
    // Use the same enabled platform category population exposed by the dashboard.
    $categoriesMethod = new ReflectionMethod($service, 'categories');
    $categoriesMethod->setAccessible(true);
    $visibleCategories = $categoriesMethod->invoke($service);
    $categoryId = (int)($visibleCategories['roots'][0]['id'] ?? 0);
    if ($categoryId > 0) {
        $filtered = $service->dashboard(['tenant_id' => '0', 'store_ids' => [$stores[0]]], [
            'start_date' => $range['start'], 'end_date' => $range['end'],
            'category_id' => $categoryId,
        ]);
        if ($filtered['aggregation_health']['status_code'] !== 'not_applicable'
            || $filtered['scope']['store_ids'] !== [$stores[0]]) {
            throw new RuntimeException('Category read changed scope or used an unfiltered aggregate');
        }
        if (array_column($filtered['sale_type_cards'] ?? [], 'code') !== array_column($saleMix, 'code')) {
            throw new RuntimeException('Category scope changed sale-type card contract');
        }
        foreach ($filtered['sale_type_cards'] as $index => $card) {
            if ((int)$card['sales_quantity'] > (int)$saleMix[$index]['sales_quantity']
                || (int)$card['cash_performance_cents'] > (int)$saleMix[$index]['cash_performance_cents']) {
                throw new RuntimeException('Category/store filter widened a sale-type total');
            }
        }
    }
    echo json_encode([
        'result' => 'PASS', 'elapsed_seconds' => $elapsed, 'store_count' => count($stores),
        'actual_performance_cents' => $actual, 'month_goal_cents' => $dashboard['goals']['month']['target_amount_cents'],
        'ranked_store_count' => count($rankings), 'category_checked' => $categoryId > 0,
        'sale_mix_quantity' => $mixQuantity, 'sale_mix_cash_cents' => $mixCash,
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
} finally {
    Db::rollback();
}
