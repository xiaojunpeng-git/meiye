<?php

$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require dirname(__DIR__) . '/lib/_lib.php';
require rtrim($backend, '/\\') . '/vendor/autoload.php';

use app\services\report\StoreUnifiedReportServices;
use think\facade\Db;

c1aBootThinkApp(rtrim($backend, '/\\') . '/');
date_default_timezone_set('Asia/Shanghai');

$reports = [
    'market_performance', 'market_detail', 'member_visit_analysis',
    'member_visit_annual_summary', 'field_acquisition_detail',
    'field_acquisition_summary', 'cross_industry_customer_detail',
    'cross_industry_customer_summary', 'new_customer_analysis',
    'new_customer_analysis_summary', 'salesperson_large_order_statistics',
    'store_refund_ledger',
];

$storeIds = array_values(array_map('intval', Db::name('cashier_v3_payment_fact')
    ->where('store_id', '>', 0)->group('store_id')->limit(5)->column('store_id')));
if (!$storeIds) {
    fwrite(STDERR, "FAIL: local fixture has no report stores\n");
    exit(1);
}

$service = new StoreUnifiedReportServices();
$failed = 0;
foreach ($reports as $report) {
    try {
        $result = $service->query($storeIds, [
            'report' => $report,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'page' => 1,
            'limit' => 20,
        ]);
        $ok = is_array($result)
            && trim((string)($result['title'] ?? '')) !== ''
            && is_array($result['columns'] ?? null)
            && is_array($result['records'] ?? null)
            && isset($result['total'], $result['page'], $result['page_size'])
            && is_array($result['filter_schema'] ?? null)
            && is_array($result['editable_fields'] ?? null)
            && is_array($result['top_summaries'] ?? null)
            && is_array($result['drilldown'] ?? null);
        echo ($ok ? 'PASS ' : 'FAIL ') . $report . "\n";
        if (!$ok) $failed++;
    } catch (Throwable $throwable) {
        echo 'FAIL ' . $report . ': ' . get_class($throwable) . ': ' . $throwable->getMessage() . "\n";
        $failed++;
    }
}

exit($failed === 0 ? 0 : 1);
