<?php

declare(strict_types=1);

use app\services\query\metric\RegisteredMetricReadServices;
use app\services\report\ProductManagementDashboardServices;
use think\facade\Db;

$backend = dirname(__DIR__, 3) . '/后端代码/';
if (!is_file($backend . 'vendor/autoload.php')) $backend = '/var/www/html/';
require $backend . 'vendor/autoload.php';
(new think\App($backend))->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('Read-only check requires the local ruihao database');
}

Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
try {
    $tenant = '0';
    $stores = [68];
    $range = ['start' => '2026-10-14', 'end' => '2026-10-14'];
    $context = ['tenant_id' => $tenant, 'store_ids' => $stores, 'cost_visible' => false];
    $input = ['start_date' => $range['start'], 'end_date' => $range['end']];
    $reader = RegisteredMetricReadServices::withCurrentReportProductCategory();
    $service = new ProductManagementDashboardServices();
    $all = $service->dashboard($context, $input);
    $expected = $reader->summary('actual_performance', $tenant, $stores, $range);
    if ($expected <= 0) throw new RuntimeException('Expected a nonzero local sales fixture');
    if ((int)$all['summary']['sales']['value_cents'] !== $expected) {
        throw new RuntimeException('Product sales card does not equal registered actual performance');
    }
    if (($all['metric_version'] ?? '') !== ProductManagementDashboardServices::METRIC_VERSION) {
        throw new RuntimeException('Product metric version is stale');
    }
    echo 'product sales actual_performance_cents=' . $expected . PHP_EOL;
} finally {
    Db::rollback();
}
