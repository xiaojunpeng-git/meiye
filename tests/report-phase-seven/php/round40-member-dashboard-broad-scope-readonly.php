<?php

declare(strict_types=1);

/** Read-only local regression for broad-scope member dashboard memory and historical visit parity. */
require '/var/www/html/vendor/autoload.php';

use app\services\query\metric\StoreCustomerVisitEligibility;
use app\services\report\MemberManagementDashboardServices;
use think\facade\Db;

(new think\App('/var/www/html/'))->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('Only the local ruihao Docker database is allowed');
}

ini_set('memory_limit', '128M');
Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
try {
    $stores = array_map('intval', Db::name('system_store')->where('is_del', 0)
        ->where('name', '<>', '总部')->column('id'));
    $service = new MemberManagementDashboardServices();
    $serviceRows = new ReflectionMethod($service, 'serviceRows');
    $serviceRows->setAccessible(true);
    $latestRows = new ReflectionMethod($service, 'historicalServiceLastRows');
    $latestRows->setAccessible(true);
    $end = '2026-10-17';

    // Small-scope old/new comparison protects the 90-day and recency cohorts.
    foreach ([0, 26302] as $categoryId) {
        $old = $serviceRows->invoke($service, '0', [68], ['start' => '1970-01-01', 'end' => $end], $categoryId);
        $expected = [];
        foreach (StoreCustomerVisitEligibility::filter($old) as $row) {
            $member = (int)$row['member_id'];
            if ($member > 0 && (!isset($expected[$member]) || $row['business_date'] > $expected[$member])) {
                $expected[$member] = $row['business_date'];
            }
        }
        $actual = [];
        foreach ($latestRows->invoke($service, '0', [68], $end, $categoryId) as $row) {
            $actual[(int)$row['member_id']] = $row['business_date'];
        }
        ksort($expected);
        ksort($actual);
        if ($actual !== $expected) throw new RuntimeException('Historical last-service dates changed');
        unset($old, $expected, $actual);
    }

    foreach (['2026-10-01', '2026-01-01'] as $start) {
        $dashboard = $service->dashboard(['tenant_id' => '0', 'store_ids' => $stores], [
            'start_date' => $start, 'end_date' => $end,
        ]);
        if (($dashboard['scope']['range']['start'] ?? '') !== $start
            || !isset($dashboard['customer_overview']['member']['service_members']['value'])) {
            throw new RuntimeException('Broad-scope dashboard response incomplete');
        }
        unset($dashboard);
        printf("PASS broad-scope member dashboard: start=%s stores=%d peak_mb=%.1f\n",
            $start, count($stores), memory_get_peak_usage(true) / 1048576);
    }
} finally {
    Db::rollback();
}
