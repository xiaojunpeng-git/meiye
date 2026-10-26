<?php

declare(strict_types=1);

$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require dirname(__DIR__) . '/lib/_lib.php';
require rtrim($backend, '/\\') . '/vendor/autoload.php';

use app\services\report\StoreUnifiedReportServices;
use think\facade\Db;

c1aBootThinkApp(rtrim($backend, '/\\') . '/');

// Read an existing local partner-card fixture only. The report query and its
// drilldown must agree on each full frozen category path without writing data.
$seedRows = Db::query(
    "SELECT s.store_id,s.business_date FROM eb_cashier_v3_sale_fact s "
    . "JOIN eb_cashier_v3_card_sale_category_allocation_fact c ON c.sale_fact_id=s.fact_id AND c.status='effective' "
    . "WHERE s.status='effective' AND c.partner_name_snapshot<>'' "
    . "AND s.business_date BETWEEN '2026-09-01' AND '2026-09-30' "
    . "GROUP BY s.store_id,s.business_date,s.source_line_id "
    . "HAVING COUNT(DISTINCT c.category_path_snapshot)>1 LIMIT 1"
);
$seed = $seedRows[0] ?? null;
if (!is_array($seed)) {
    fwrite(STDERR, "FAIL no local split-category partner-card fixture in September 2026\n");
    exit(1);
}

$month = substr((string)$seed['business_date'], 0, 7);
$storeId = (int)$seed['store_id'];
$baseInput = [
    'start_date' => $month . '-01',
    'end_date' => date('Y-m-t', strtotime($month . '-01')),
    'page' => 1,
    'limit' => 100,
];
$service = new StoreUnifiedReportServices();
$summary = $service->query([$storeId], $baseInput + ['report' => 'partner_item_summary']);
$rows = $summary['records'] ?? [];
if (!$rows) throw new RuntimeException('partner summary returned no existing fixture');

$keys = [];
$categoryTotals = [];
foreach ($rows as $row) {
    $key = json_encode([
        $row['month'], $row['store_id'], $row['company_dimension_id'],
        $row['city_manager_dimension_id'], $row['category_path_snapshot'],
    ], JSON_UNESCAPED_UNICODE);
    if (isset($keys[$key])) throw new RuntimeException('same full category still has duplicate visible rows');
    $keys[$key] = true;
    if ((string)$row['performance_type'] !== (string)$row['category_path_snapshot']) {
        throw new RuntimeException('summary concealed part of the category path');
    }
    $categoryTotals[] = (string)$row['category_path_snapshot'] . '=' . (string)$row['sale_amount'];

    $detail = $service->query([$storeId], $baseInput + [
        'report' => 'partner_item_detail',
        'store_ids' => (string)$row['store_id'],
        'category_path_exact' => (string)$row['category_path_snapshot'],
        'company_dimension_id' => (string)$row['company_dimension_id'],
        'city_manager_dimension_id' => (string)$row['city_manager_dimension_id'],
    ]);
    if ((string)($detail['summary_row']['deal_amount'] ?? '') !== (string)$row['sale_amount']) {
        throw new RuntimeException('exact category detail total differs from its summary row');
    }
    foreach ($detail['records'] ?? [] as $item) {
        if ((string)$item['category_path_snapshot'] !== (string)$row['category_path_snapshot']) {
            throw new RuntimeException('exact drilldown included a sibling category');
        }
    }
}

echo 'partner category full path local MySQL integration: PASS (' . count($rows) . ' rows: '
    . implode(', ', $categoryTotals) . ")\n";
