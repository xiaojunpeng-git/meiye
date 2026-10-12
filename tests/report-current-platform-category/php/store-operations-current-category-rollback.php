<?php

declare(strict_types=1);

use app\services\report\StoreUnifiedReportPhaseTwoServices;
use app\services\report\StoreUnifiedReportPhaseThreeServices;
use app\services\report\StoreUnifiedReportPhaseFourServices;
use app\services\report\StoreUnifiedReportPhaseSixServices;
use app\services\report\StoreUnifiedReportServices;
use app\services\report\CustomerAnalyticsServices;
use app\services\report\ProductManagementDashboardServices;
use app\services\report\GroupManagementDashboardServices;
use app\services\report\MemberManagementDashboardServices;
use app\services\report\StoreReportCurrentProductCategoryServices;
use think\facade\Db;

// Local-only regression: category metadata changes roll back; sales and award facts never change.
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';
(new think\App(rtrim($backend, '/\\') . '/'))->initialize();

function currentCategoryAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "PASS {$message}\n";
}

$platformIds = [91038, 78777];
$componentIds = [91111, 466474];
$refundReaderCode = (string)file_get_contents(rtrim($backend, '/\\')
    . '/app/services/report/StoreUnifiedReportPhaseTwoServices.php');
currentCategoryAssert(strpos($refundReaderCode, "->where('refund_line.status', 'succeeded')") !== false,
    'sales refund classification uses the writer success status');
$original = Db::name('store_product')->whereIn('id', $platformIds)->order('id')->select()->toArray();
currentCategoryAssert(count($original) === 2, 'linked platform-product fixtures exist');
$awardService = new StoreUnifiedReportPhaseTwoServices();
$awardProjection = new ReflectionMethod($awardService, 'currentBeautyLargeOrderFacts');
$awardProjection->setAccessible(true);
$awardSelection = new ReflectionMethod($awardService, 'beautyLargeOrderMonthlyAwards');
$awardSelection->setAccessible(true);
$refundProjection = new ReflectionMethod($awardService, 'refundLedgerCurrentProductCategories');
$refundProjection->setAccessible(true);
$component = [
    'tenant_id' => '0', 'store_id' => 126, 'organization_id' => 1,
    'organization_path_snapshot' => '', 'division_name' => '测试分公司',
    'store_name' => '测试门店', 'business_date' => '2026-10-11',
    'employee_id' => 663, 'order_id' => 'CURRENT-CATEGORY-ROLLBACK',
    'source_line_id' => 'CURRENT-CATEGORY-LINE', 'sale_fact_id' => 'CURRENT-CATEGORY-FACT',
    'salesperson' => '测试销售', 'member_id' => 1, 'role_snapshot' => 'salesperson',
    'allocated_cash_total_cents' => 100, 'base_amount_cents' => 3000000,
];
$components = [
    $component + ['component_product_id' => 91111, 'component_cash_amount_cents' => 60],
    $component + ['component_product_id' => 466474, 'component_cash_amount_cents' => 40],
];
$refund = [[
    'tenant_id' => '0', 'store_id' => 126, 'source_type' => 'document',
    'operation_id' => 'CURRENT-CATEGORY-REFUND', 'document_item_type' => 'product',
    'document_product_id' => '91111', 'document_order_line_id' => '', 'category' => '',
]];
$factsBefore = Db::name('cashier_v3_card_sale_category_allocation_fact')
    ->where('store_id', 126)->whereIn('component_product_id', $componentIds)
    ->order('id')->select()->toArray();

Db::startTrans();
try {
    Db::name('store_product')->whereIn('id', $platformIds)->order('id')->lock(true)->select();
    Db::name('store_product')->whereIn('id', $platformIds)->update(['cate_id' => '26332']);
    $unified = $awardProjection->invoke($awardService, $components);
    currentCategoryAssert(count($unified) === 1 && $unified[0]['beauty_category'] === '卡项'
        && (int)$unified[0]['amount_cents'] === 3000000,
        'same current category merges component weights before rounding');
    currentCategoryAssert(count($awardSelection->invoke($awardService, $unified)) === 1,
        'historical month reaches the three-wan award under current category');
    $refundProjection->invokeArgs($awardService, [&$refund]);
    currentCategoryAssert($refund[0]['category'] === '生美/卡项',
        'refund category follows the document product platform ID');

    Db::name('store_product')->where('id', 91038)->update(['cate_id' => '26320']);
    $split = $awardProjection->invoke($awardService, $components);
    $amounts = array_column($split, 'amount_cents', 'beauty_category');
    currentCategoryAssert(($amounts['卡项'] ?? null) === 1200000
        && ($amounts['口服'] ?? null) === 1800000
        && array_sum($amounts) === 3000000,
        'historical categories reassign without changing cent-precision performance');
    currentCategoryAssert($awardSelection->invoke($awardService, $split) === [],
        'historical award is recalculated when neither current category reaches threshold');
    $refundProjection->invokeArgs($awardService, [&$refund]);
    currentCategoryAssert($refund[0]['category'] === '生美/口服',
        'refund category changes with the same platform product');

    Db::name('store_product')->where('id', 91038)->update(['cate_id' => '26318']);
    $refundProjection->invokeArgs($awardService, [&$refund]);
    currentCategoryAssert($refund[0]['category'] === '六维/合作/元聚',
        'third-level platform category retains its leaf in the displayed path');

    Db::name('store_product')->where('id', 91038)->update(['cate_id' => '']);
    $refundProjection->invokeArgs($awardService, [&$refund]);
    currentCategoryAssert($refund[0]['category'] === '',
        'missing platform category stays blank without a name-based fallback');
    currentCategoryAssert(Db::name('cashier_v3_card_sale_category_allocation_fact')
        ->where('store_id', 126)->whereIn('component_product_id', $componentIds)
        ->order('id')->select()->toArray() === $factsBefore,
        'immutable card allocation facts remain unchanged');
} finally {
    Db::rollback();
}
currentCategoryAssert(Db::name('store_product')->whereIn('id', $platformIds)->order('id')->select()->toArray() === $original,
    'all local product category edits rolled back');

$reports = new StoreUnifiedReportServices();
$input = ['start_date' => '2026-10-01', 'end_date' => '2026-10-11', 'page' => 1, 'limit' => 20];
foreach (['partner_item_summary', 'partner_item_detail', 'member_consumption_detail', 'store_item_analysis'] as $report) {
    $screen = $reports->query([175], $input + ['report' => $report]);
    $export = $reports->export([175], $input + ['report' => $report]);
    currentCategoryAssert(array_column($screen['columns'], 'key') === array_column($export['columns'], 'key')
        && ($screen['summary_row'] ?? []) === ($export['summary_row'] ?? []),
        "{$report} screen and export share column and total contracts");
}
$partnerSummary = $reports->query([175], $input + ['report' => 'partner_item_summary']);
foreach ($partnerSummary['records'] as $summaryRow) {
    $drill = $reports->query([175], $input + [
        'report' => 'partner_item_detail',
        'store_ids' => (string)$summaryRow['store_id'],
        'category_path_exact' => (string)$summaryRow['category_path_snapshot'],
        'company_dimension_id' => (string)$summaryRow['company_dimension_id'],
        'city_manager_dimension_id' => (string)$summaryRow['city_manager_dimension_id'],
    ]);
    currentCategoryAssert((string)$drill['summary_row']['deal_amount'] === (string)$summaryRow['sale_amount'],
        'current-category partner summary reconciles with exact drilldown');
}
$candidate = Db::name('cashier_v3_sale_fact')->alias('s')
    ->join('cashier_v3_report_sale_dimension_fact d',
        'd.tenant_id=s.tenant_id AND d.sale_fact_id=s.fact_id')
    ->join('store_product sp', 'sp.id=d.item_id AND sp.type=1 AND sp.relation_id=s.store_id AND sp.pid>0')
    ->where('s.store_id', 175)->whereBetween('s.business_date', ['2026-10-01', '2026-10-11'])
    ->where('s.status', 'effective')->where('s.source_type', '<>', 'card')
    ->field('s.source_line_id,d.item_id,sp.pid')->limit(1)->find();
currentCategoryAssert(is_array($candidate) && (int)$candidate['pid'] > 0,
    'direct sale has a verifiable linked platform product');
$memberInput = $input + ['report' => 'member_consumption_detail', '_internal_all' => true];
$memberBefore = $reports->query([175], $memberInput);
$rowForLine = static function(array $records, string $line): array {
    foreach ($records as $row) if ((string)$row['source_line_id'] === $line) return $row;
    return [];
};
$line = (string)$candidate['source_line_id'];
$originalMember = $rowForLine($memberBefore['records'], $line);
currentCategoryAssert($originalMember !== [], 'selected direct sale is visible in member consumption detail');
$phaseThree = new StoreUnifiedReportPhaseThreeServices();
$phaseFour = new StoreUnifiedReportPhaseFourServices();
$phaseSix = new StoreUnifiedReportPhaseSixServices();
$dateRange = ['start' => '2026-10-01', 'end' => '2026-10-11'];
$threeScope = new ReflectionMethod($phaseThree, 'categoryScope');
$threeScope->setAccessible(true);
$fourScope = new ReflectionMethod($phaseFour, 'categoryIds');
$fourScope->setAccessible(true);
currentCategoryAssert(in_array(26332, $threeScope->invoke($phaseThree, ['category_path' => '生美/卡项']), true)
    && $threeScope->invoke($phaseThree, ['category_path' => '生美/卡项'])
        === $threeScope->invoke($phaseThree, ['category_path' => '生美 / 卡项']),
    'third-phase category tree accepts the displayed and spaced paths');
currentCategoryAssert(in_array(26332, $fourScope->invoke($phaseFour, '', ['category_path' => '生美/卡项']), true)
    && $fourScope->invoke($phaseFour, '', ['category_path' => '生美/卡项'])
        === $fourScope->invoke($phaseFour, '', ['category_path' => '生美 / 卡项']),
    'fourth-phase category tree accepts the displayed and spaced paths');
$phaseThreePurchases = new ReflectionMethod($phaseThree, 'purchaseItemRows');
$phaseThreePurchases->setAccessible(true);
$phaseFourCash = new ReflectionMethod($phaseFour, 'cashRows');
$phaseFourCash->setAccessible(true);
$phaseSixCash = new ReflectionMethod($phaseSix, 'cashFacts');
$phaseSixCash->setAccessible(true);
$otherCollectionBefore = $phaseSix->query('phase_six_other_multi_payment', [175], $dateRange, []);
$otherForLine = static function(array $records, string $sourceLineId): array {
    foreach ($records as $record) if ((string)($record['source_line_id'] ?? '') === $sourceLineId) return $record;
    return [];
};
$otherBefore = $otherForLine((array)($otherCollectionBefore['records'] ?? []), $line);
currentCategoryAssert($otherBefore !== [], 'other-collection report includes the selected product');
$productRows = static function(array $rows, int $itemId): array {
    return array_values(array_filter($rows, static fn(array $row): bool => (int)($row['item_id'] ?? 0) === $itemId));
};
$cashItemId = 118399;
$cashPlatformId = 78777;
$cashPlatformBefore = Db::name('store_product')->where('id', $cashPlatformId)->find();
currentCategoryAssert((int)Db::name('store_product')->where('id', $cashItemId)->value('pid') === $cashPlatformId,
    'paid-sale fixture links to the expected platform product');
$threeBefore = $productRows($phaseThreePurchases->invoke($phaseThree, [175], $dateRange, []), $cashItemId);
$fourBefore = $productRows($phaseFourCash->invoke($phaseFour, [175], $dateRange, []), $cashItemId);
$sixBefore = $productRows($phaseSixCash->invoke($phaseSix, [175], $dateRange), $cashItemId);
currentCategoryAssert($threeBefore !== [] && $fourBefore !== [] && $sixBefore !== [],
    'same linked product participates in third, fourth and sixth phase reports');
$productDashboard = new ProductManagementDashboardServices();
$revenueRows = new ReflectionMethod($productDashboard, 'productRevenueRows');
$revenueRows->setAccessible(true);
$dashboardScope = ['tenant_id' => '0', 'store_ids' => [175], 'cost_visible' => false];
$revenueBefore = $productRows($revenueRows->invoke($productDashboard, $dashboardScope, $dateRange, []), $cashItemId);
currentCategoryAssert($revenueBefore !== [], 'paid-sale fixture contributes to the platform product dashboard');
$dashboardCategories = new ReflectionMethod($productDashboard, 'categories');
$dashboardCategories->setAccessible(true);
$dashboardCategoryMap = new ReflectionMethod($productDashboard, 'productCategoryMap');
$dashboardCategoryMap->setAccessible(true);
$taxonomy = $dashboardCategories->invoke($productDashboard);
$stockRow = [['store_id' => 175, 'product_id' => $cashItemId]];
$inventoryCategoryBefore = $dashboardCategoryMap->invoke($productDashboard, $taxonomy, $stockRow)['175:' . $cashItemId] ?? 0;
$groupDashboard = new GroupManagementDashboardServices();
$groupInput = ['start_date' => $dateRange['start'], 'end_date' => $dateRange['end'],
    'category_id' => 26332, 'summary_only' => true];
$groupContext = ['tenant_id' => '0', 'store_ids' => [175]];
$groupCash = static function(array $dashboard): int {
    foreach ((array)($dashboard['cards'] ?? []) as $card) {
        if ((string)($card['metric_code'] ?? '') === 'cash_performance') return (int)$card['value_cents'];
    }
    throw new RuntimeException('group dashboard cash card is missing');
};
$groupCashBefore = $groupCash($groupDashboard->dashboard($groupContext, $groupInput));
$memberDashboard = new MemberManagementDashboardServices();
$memberCashRows = new ReflectionMethod($memberDashboard, 'cashRows');
$memberCashRows->setAccessible(true);
$memberFixtureBefore = $productRows($memberCashRows->invoke($memberDashboard, '0', [175], $dateRange, 0), $cashItemId);
currentCategoryAssert($memberFixtureBefore !== [], 'paid product participates in the member dashboard cash facts');
$currentCategoryReader = new StoreReportCurrentProductCategoryServices();
$memberRootBefore = (int)($currentCategoryReader->resolve([['store_id' => 175, 'product_id' => $cashItemId]])[0]['root_id'] ?? 0);
$itemReportInput = ['_report_scope' => ['mode' => 'all'], '_authorized_store_ids' => [175], '_internal_all' => true];
$privateBefore = $phaseFour->query('product_monetization_private_item_performance', [175], $dateRange, $itemReportInput);
currentCategoryAssert($productRows($privateBefore['records'], $cashItemId) !== [],
    'paid-sale fixture starts in the private-item business report');
$platformId = (int)$candidate['pid'];
$platformBefore = Db::name('store_product')->where('id', $platformId)->find();
$nextCategory = (string)$originalMember['category'] === '生美/卡项' ? '26321' : '26332';
Db::startTrans();
try {
    Db::name('store_product')->where('id', $platformId)->lock(true)->find();
    Db::name('store_product')->where('id', $platformId)->update(['cate_id' => $nextCategory]);
    Db::name('store_product')->where('id', $cashPlatformId)->lock(true)->find();
    Db::name('store_product')->where('id', $cashPlatformId)->update(['cate_id' => '26332']);
    $memberAfter = $reports->query([175], $memberInput);
    $changed = $rowForLine($memberAfter['records'], $line);
    $expectedPath = $nextCategory === '26321' ? '花园/花园产品' : '生美/卡项';
    currentCategoryAssert((string)($changed['category'] ?? '') === $expectedPath
        && $memberBefore['summary_row'] === $memberAfter['summary_row'],
        'member report follows current platform category without changing monetary totals');
    $filtered = $reports->query([175], $memberInput + ['category_path' => $expectedPath]);
    currentCategoryAssert($rowForLine($filtered['records'], $line) !== [],
        'current-category filter includes the reclassified sale');
    $otherAfterResult = $phaseSix->query('phase_six_other_multi_payment', [175], $dateRange, []);
    $otherAfter = $otherForLine((array)($otherAfterResult['records'] ?? []), $line);
    currentCategoryAssert((string)($otherAfter['category'] ?? '') === $expectedPath
        && $otherBefore['receivable_amount'] === $otherAfter['receivable_amount']
        && $otherBefore['other_collection_amount'] === $otherAfter['other_collection_amount'],
        'other-collection category follows platform ID while settlement amounts stay fixed');
    $threeAfter = $productRows($phaseThreePurchases->invoke($phaseThree, [175], $dateRange, []), $cashItemId);
    $fourAfter = $productRows($phaseFourCash->invoke($phaseFour, [175], $dateRange, []), $cashItemId);
    $sixAfter = $productRows($phaseSixCash->invoke($phaseSix, [175], $dateRange), $cashItemId);
    foreach ([['third', $threeBefore, $threeAfter], ['fourth', $fourBefore, $fourAfter], ['sixth', $sixBefore, $sixAfter]] as [$phase, $before, $after]) {
        currentCategoryAssert(count($before) === count($after)
            && (string)$after[0]['category_path'] === '生美/卡项'
            && array_sum(array_column($before, 'amount_cents')) === array_sum(array_column($after, 'amount_cents')),
            "{$phase}-phase report category follows the platform product without changing facts");
    }
    $threeFiltered = $phaseThreePurchases->invoke($phaseThree, [175], $dateRange, [26332]);
    $fourFiltered = $phaseFourCash->invoke($phaseFour, [175], $dateRange, [26332]);
    currentCategoryAssert($productRows($threeFiltered, $cashItemId) !== [] && $productRows($fourFiltered, $cashItemId) !== [],
        'third and fourth phase category filters use projected current category');
    $revenueAfter = $productRows($revenueRows->invoke($productDashboard, $dashboardScope, $dateRange, [26332]), $cashItemId);
    currentCategoryAssert(count($revenueAfter) === count($revenueBefore)
        && array_sum(array_column($revenueAfter, 'amount_cents')) === array_sum(array_column($revenueBefore, 'amount_cents')),
        'product dashboard filtered revenue follows current category with unchanged cents');
    $inventoryCategoryAfter = $dashboardCategoryMap->invoke($productDashboard, $taxonomy, $stockRow)['175:' . $cashItemId] ?? 0;
    currentCategoryAssert($inventoryCategoryBefore > 0 && $inventoryCategoryAfter > 0
        && $inventoryCategoryAfter !== $inventoryCategoryBefore,
        'inventory dashboard grouping follows the platform category rather than the store copy');
    $groupCashAfter = $groupCash($groupDashboard->dashboard($groupContext, $groupInput));
    currentCategoryAssert($groupCashAfter > $groupCashBefore,
        'group dashboard category cash card follows reclassified paid product');
    $memberRootAfter = (int)($currentCategoryReader->resolve([['store_id' => 175, 'product_id' => $cashItemId]])[0]['root_id'] ?? 0);
    $memberFixtureAfter = $productRows($memberCashRows->invoke($memberDashboard, '0', [175], $dateRange, $memberRootAfter), $cashItemId);
    $oldMemberCategory = $productRows($memberCashRows->invoke($memberDashboard, '0', [175], $dateRange, $memberRootBefore), $cashItemId);
    currentCategoryAssert($memberRootBefore > 0 && $memberRootAfter > 0 && $memberRootBefore !== $memberRootAfter
        && count($memberFixtureAfter) === count($memberFixtureBefore) && $oldMemberCategory === []
        && array_sum(array_column($memberFixtureBefore, 'amount_cents')) === array_sum(array_column($memberFixtureAfter, 'amount_cents')),
        'member dashboard first-level filter follows current platform category without changing cash facts');
    $beautyAfter = $phaseFour->query('product_monetization_beauty_item', [175], $dateRange, $itemReportInput);
    $privateAfter = $phaseFour->query('product_monetization_private_item_performance', [175], $dateRange, $itemReportInput);
    currentCategoryAssert($productRows($beautyAfter['records'], $cashItemId) !== []
        && $productRows($privateAfter['records'], $cashItemId) === [],
        'business reports move the same paid item between current category groups');
} finally {
    Db::rollback();
}
currentCategoryAssert(Db::name('store_product')->where('id', $platformId)->find() === $platformBefore,
    'direct-sale platform category edit rolled back');
currentCategoryAssert(Db::name('store_product')->where('id', $cashPlatformId)->find() === $cashPlatformBefore,
    'paid-sale platform category edit rolled back');
$unconsumedProductId = 392505;
$unconsumedPlatformId = 79585;
currentCategoryAssert((int)Db::name('store_product')->where('id', $unconsumedProductId)->value('pid') === $unconsumedPlatformId,
    'unconsumed entitlement fixture links to its platform product');
$unconsumedPlatformBefore = Db::name('store_product')->where('id', $unconsumedPlatformId)->find();
$customerReports = new CustomerAnalyticsServices();
$unconsumedInput = ['_report_scope' => ['mode' => 'all'], '_authorized_store_ids' => [175],
    '_internal_all' => true, 'item_id' => $unconsumedProductId];
$unconsumedBefore = $customerReports->read('customer_unconsumed_analysis', [175], $dateRange,
    $unconsumedInput + ['category_path' => '生美/卡项']);
currentCategoryAssert(count($unconsumedBefore['records']) === 1,
    'unconsumed project is selected by its original platform category');
Db::startTrans();
try {
    Db::name('store_product')->where('id', $unconsumedPlatformId)->lock(true)->find();
    Db::name('store_product')->where('id', $unconsumedPlatformId)->update(['cate_id' => '26321']);
    $oldCategory = $customerReports->read('customer_unconsumed_analysis', [175], $dateRange,
        $unconsumedInput + ['category_path' => '生美 / 卡项']);
    $newCategory = $customerReports->read('customer_unconsumed_analysis', [175], $dateRange,
        $unconsumedInput + ['category_path' => '花园/花园产品']);
    currentCategoryAssert($oldCategory['records'] === [] && count($newCategory['records']) === 1
        && $newCategory['records'][0]['remaining_count'] === $unconsumedBefore['records'][0]['remaining_count'],
        'unconsumed category filter moves the same entitlement without changing remaining count');
} finally {
    Db::rollback();
}
currentCategoryAssert(Db::name('store_product')->where('id', $unconsumedPlatformId)->find() === $unconsumedPlatformBefore,
    'unconsumed platform category edit rolled back');
echo "STORE_OPERATIONS_CURRENT_CATEGORY=PASS\n";
