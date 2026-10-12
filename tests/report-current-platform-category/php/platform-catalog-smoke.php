<?php

declare(strict_types=1);

use app\services\report\CustomerAnalyticsServices;
use app\services\report\StoreUnifiedReportPhaseFourServices;
use app\services\report\StoreUnifiedReportPhaseSixServices;
use app\services\report\StoreUnifiedReportPhaseThreeServices;
use app\services\report\ProductManagementDashboardServices;
use app\services\report\GroupManagementDashboardServices;
use app\services\report\MemberManagementDashboardServices;

// Read-only catalog smoke across two stores; category and financial facts are never mutated here.
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';
(new think\App(rtrim($backend, '/\\') . '/'))->initialize();

$stores = [126, 175];
$range = ['start' => '2026-10-01', 'end' => '2026-10-31'];
$input = [
    'page' => 1, 'limit' => 20, '_authorized_store_ids' => $stores,
    '_report_scope' => ['mode' => 'all'],
];
$families = [
    [new StoreUnifiedReportPhaseThreeServices(), StoreUnifiedReportPhaseThreeServices::reportCodes(), 'query'],
    [new StoreUnifiedReportPhaseFourServices(), StoreUnifiedReportPhaseFourServices::reportCodes(), 'query'],
    [new StoreUnifiedReportPhaseSixServices(), StoreUnifiedReportPhaseSixServices::reportCodes(), 'query'],
    [new CustomerAnalyticsServices(), CustomerAnalyticsServices::reportCodes(), 'read'],
];
$count = 0;
$failures = [];
foreach ($families as [$service, $codes, $method]) {
    foreach ($codes as $code) {
        try {
            $result = $service->{$method}($code, $stores, $range, $input);
            if (!is_array($result['columns'] ?? null) || !is_array($result['records'] ?? null)
                || trim((string)($result['metric_version'] ?? '')) === '') {
                throw new RuntimeException('missing columns, records or metric version');
            }
            $count++;
            echo "PASS {$code}\n";
        } catch (Throwable $error) {
            $failures[] = $code . ': ' . $error->getMessage();
            echo 'FAIL ' . end($failures) . "\n";
        }
    }
}
$dashboardContext = ['tenant_id' => '0', 'store_ids' => $stores, 'cost_visible' => false];
$dashboardInput = ['start_date' => $range['start'], 'end_date' => $range['end'], 'category_id' => 26332];
foreach ([
    'product_management_dashboard' => new ProductManagementDashboardServices(),
    'group_management_dashboard' => new GroupManagementDashboardServices(),
    'member_management_dashboard' => new MemberManagementDashboardServices(),
] as $code => $service) {
    try {
        $result = $service->dashboard($dashboardContext, $dashboardInput);
        if (!is_array($result) || trim((string)($result['metric_version'] ?? '')) === '') {
            throw new RuntimeException('missing dashboard metric version');
        }
        $count++;
        echo "PASS {$code}\n";
    } catch (Throwable $error) {
        $failures[] = $code . ': ' . $error->getMessage();
        echo 'FAIL ' . end($failures) . "\n";
    }
}
echo 'PLATFORM_CATALOG_SMOKE=' . ($failures === [] ? 'PASS' : 'FAIL') . ' count=' . $count . "\n";
if ($failures !== []) exit(1);
