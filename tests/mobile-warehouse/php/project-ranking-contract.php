<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/后端代码/app/services/query/metric/MetricMoneyFormatter.php';
require_once dirname(__DIR__, 3) . '/后端代码/app/services/mobile/warehouse/MobileWarehouseServices.php';

use app\services\mobile\warehouse\MobileWarehouseServices;

function verifyProjectRanking(bool $condition, string $reason): void
{
    if (!$condition) throw new RuntimeException($reason);
}

$ranking = new ReflectionMethod(MobileWarehouseServices::class, 'serviceProjectRankingRows');
$cash = [
    ['product_type' => 6, 'category_path' => '护理/面部', 'item_name' => '补水', 'amount_cents' => 10040],
    ['product_type' => 6, 'category_path' => '护理/面部', 'item_name' => '补水', 'amount_cents' => 5070],
    // 退款冲减原项目现金业绩，但不抹掉已经完成的服务次数。
    ['product_type' => 6, 'category_path' => '护理/面部', 'item_name' => '补水', 'amount_cents' => -5100],
    ['product_type' => 6, 'category_path' => '护理/身体', 'item_name' => '补水', 'amount_cents' => 22000],
    ['product_type' => 0, 'category_path' => '护理/面部', 'item_name' => '补水', 'amount_cents' => 990000],
];
$services = [
    ['category_path' => '护理/面部', 'project_name_snapshot' => '补水', 'quantity' => 2],
    ['category_path' => '护理/面部', 'project_name_snapshot' => '补水', 'quantity' => 3],
    ['category_path' => '护理/身体', 'project_name_snapshot' => '补水', 'quantity' => 1],
    ['category_path' => '护理/面部', 'project_name_snapshot' => '仅服务', 'quantity' => 4],
];
$cashDescending = $ranking->invoke(null, $cash, $services, 'cash', 'desc');
verifyProjectRanking(count($cashDescending) === 3, 'same category and name must merge across facts, other categories stay separate');
verifyProjectRanking(array_column($cashDescending, 'categoryPath') === ['护理/身体', '护理/面部', '护理/面部'], 'cash ranking must use signed cents before display rounding');
verifyProjectRanking($cashDescending[0]['cashPerformance'] === 220 && $cashDescending[0]['quantity'] === 1, 'body row cash and completed quantity');
verifyProjectRanking($cashDescending[1]['name'] === '补水' && $cashDescending[1]['cashPerformance'] === 100 && $cashDescending[1]['quantity'] === 5, 'cross-store project must combine signed cash and completed service quantity');
verifyProjectRanking($cashDescending[2]['name'] === '仅服务' && $cashDescending[2]['cashPerformance'] === 0, 'completed service without a receipt remains visible');
verifyProjectRanking(!array_key_exists('cashCents', $cashDescending[1]), 'raw cents must remain server-side');

$quantityDescending = $ranking->invoke(null, $cash, $services, 'quantity', 'desc');
verifyProjectRanking(array_column($quantityDescending, 'quantity') === [5, 4, 1], 'quantity switch must reorder the same groups');
$quantityAscending = $ranking->invoke(null, $cash, $services, 'quantity', 'asc');
verifyProjectRanking(array_column($quantityAscending, 'quantity') === [1, 4, 5], 'ascending switch must reverse the selected measure');
verifyProjectRanking(array_column($quantityDescending, 'entityId') === array_reverse(array_column($quantityAscending, 'entityId')), 'switching order cannot change group identity');

echo "mobile warehouse service project ranking contract: PASS\n";
