<?php

declare(strict_types=1);

use app\services\query\metric\RegisteredMetricReadServices;
use app\services\report\GroupManagementDashboardServices;
use think\facade\Db;

$backend = dirname(__DIR__, 3) . '/后端代码/';
if (!is_file($backend . 'vendor/autoload.php')) $backend = '/var/www/html/';
require $backend . 'vendor/autoload.php';
(new think\App($backend))->initialize();

// This is a fixed local business case: one card payment contains five projects.
// Keep the test read-only, and never let a production connection run it.
if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('This check requires the local ruihao database');
}
Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
try {
    $range = ['start' => '2026-10-14', 'end' => '2026-10-14'];
    $storeIds = [68];
    $saleFactId = 'CFS-7077175fbe1cddc2e63e171795f9ffd6ce66ba72';
    $reader = RegisteredMetricReadServices::withCurrentReportProductCategory();
    $rows = $reader->categoryRows('cash_performance', '0', $storeIds, $range);
    $originalRows = (new RegisteredMetricReadServices())->categoryRows('cash_performance', '0', $storeIds, $range);
    $cardRows = array_values(array_filter($rows, static fn(array $row): bool => ($row['sale_fact_id'] ?? '') === $saleFactId));
    $names = array_column($cardRows, 'item_name');
    sort($names, SORT_STRING);
    $expected = ['仪器放松', '指尖胶原', '桃花灸', '舒缓灸', '面部莹润'];
    sort($expected, SORT_STRING);
    if ($names !== $expected || array_sum(array_column($cardRows, 'amount_cents')) !== 150000) {
        throw new RuntimeException('Card payment was not split into five named projects and exact cents');
    }
    if (array_sum(array_column($rows, 'amount_cents')) !== array_sum(array_column($originalRows, 'amount_cents'))) {
        throw new RuntimeException('Named project projection changed the registered cash total');
    }

    $cards = $reader->categoryDashboardCards('cash_performance', '0', $storeIds, $range,
        [['id' => 26302, 'name' => '生美']], [26302 => [26332]]);
    $beauty = $cards[0] ?? [];
    $rankingNames = array_column($beauty['project_rankings'] ?? [], 'name');
    sort($rankingNames, SORT_STRING);
    if (($beauty['cash_performance_cents'] ?? null) !== 150000 || $rankingNames !== $expected
        || ($beauty['product_rankings'] ?? []) !== []) {
        throw new RuntimeException('Dashboard ranking labels or category amount are inconsistent');
    }

    // The same registered category rows back the user-visible drilldown.
    $drilldown = (new GroupManagementDashboardServices())->drilldown(
        ['tenant_id' => '0', 'store_ids' => $storeIds],
        ['start_date' => $range['start'], 'end_date' => $range['end'], 'category_id' => 26302,
            'metric_code' => 'cash_performance']
    );
    if (array_sum(array_column($drilldown['records'] ?? [], 'amount_cents')) !== 150000
        || count($drilldown['records'] ?? []) !== 5) {
        throw new RuntimeException('Dashboard drilldown does not reconcile with ranked item rows');
    }

    $productRows = $reader->categoryRows('cash_performance', '0', [22], $range);
    $product = null;
    foreach ($productRows as $row) {
        if (($row['product_type_snapshot'] ?? '') === 'product'
            && ($row['item_name'] ?? '') === '花园SPA清洁套') $product = $row;
    }
    if ($product === null || (int)$product['category_id'] <= 0) {
        throw new RuntimeException('Direct product sale name is missing from the registered population');
    }
    $productCards = $reader->categoryDashboardCards('cash_performance', '0', [22], $range,
        [['id' => (int)$product['category_id'], 'name' => '产品验证']], []);
    if (!in_array('花园SPA清洁套', array_column($productCards[0]['product_rankings'] ?? [], 'name'), true)) {
        throw new RuntimeException('Product ranking did not use the direct sale product name');
    }
    echo json_encode(['status' => 'PASS', 'card_sale_fact_id' => $saleFactId,
        'project_count' => count($cardRows), 'amount_cents' => 150000,
        'project_names' => $names, 'drilldown_rows' => count($drilldown['records']),
        'product_rank_name' => '花园SPA清洁套'],
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} finally {
    Db::rollback();
}
