<?php

declare(strict_types=1);

// Run against the local RH replica inside mohe-app. The checkout preparation
// transaction is rolled back; this test never creates a customer order.
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\checkout\CashierV3ServiceProjectCategorySnapshotServices;
use app\services\cashier\v3\settlement\CashierV3CheckoutPreparationServices;
use app\services\report\StoreReportServiceCategorySnapshotServices;
use app\services\report\StoreUnifiedReportServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->initialize();

function assertCategoryCheckout(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

$resolver = new CashierV3ServiceProjectCategorySnapshotServices();
$outsideRejected = false;
try {
    $resolver->resolveInTx(79586, 179);
} catch (CashierV3CommandException $exception) {
    $outsideRejected = true;
}
assertCategoryCheckout($outsideRejected, 'category snapshot refuses checkout outside a transaction');

// These two RH-replica projects reproduce the actual checkout boundary:
// 79586 has a configured category; FW092200045 uses project 372012 whose
// historical category 21560 has been removed. Never infer its replacement.
assertCategoryCheckout((int)Db::name('store_product')->where('id', 79586)->value('relation_id') === 179,
    'classified checkout fixture belongs to its authorized store');
assertCategoryCheckout((int)Db::name('store_product')->where('id', 372012)->value('relation_id') === 133
    && (int)Db::name('store_product_category')->where('id', 21560)->count() === 0,
    'FW092200045 project reproduces the deleted-category case');

$preparationClass = new ReflectionClass(CashierV3CheckoutPreparationServices::class);
$preparation = $preparationClass->newInstanceWithoutConstructor();
$prepareLine = $preparationClass->getMethod('entitlementSnapshotLine');
$prepareLine->setAccessible(true);
$line = [
    'id' => 'test-line', 'projectId' => 79586, 'name' => '背部spa',
    'quantity' => 1, 'actualAmount' => '71.00',
    // A browser-supplied category is intentionally ignored by preparation.
    'category_id_snapshot' => 999999, 'category_name_snapshot' => '伪造分类',
];

Db::startTrans();
try {
    $classified = $prepareLine->invoke($preparation, $line, 179);
    assertCategoryCheckout((int)$classified['projectCategoryIdSnapshot'] === 26332
        && $classified['projectCategoryNameSnapshot'] === '卡项'
        && (int)$classified['actualEntitlementAmountCents'] === 7100,
        'checkout preparation freezes the project category and 71 yuan amount, not browser input');
    $finalCategory = $resolver->resolvePreparedLineInTx([
        'project_id' => 79586,
        'category_id_snapshot' => $classified['projectCategoryIdSnapshot'],
        'category_name_snapshot' => $classified['projectCategoryNameSnapshot'],
    ], 179);
    assertCategoryCheckout($finalCategory === ['id' => 26332, 'name' => '卡项'],
        'final checkout keeps the prepared category for its service fact');
    $legacyFinalCategory = $resolver->resolvePreparedLineInTx(['project_id' => 79586], 179);
    assertCategoryCheckout($legacyFinalCategory === ['id' => 26332, 'name' => '卡项'],
        'final checkout repairs a legacy prepared line before service-fact persistence');
    $categoryPath = (new StoreReportServiceCategorySnapshotServices())
        ->resolveInTx($finalCategory['id'], $finalCategory['name']);
    assertCategoryCheckout(str_contains($categoryPath['project_category_path_snapshot'], '卡项'),
        'service-fact category path is frozen from the checkout category');

    $line['projectId'] = 372012;
    $line['name'] = '新背部SPA(手工)';
    $unclassified = $prepareLine->invoke($preparation, $line, 133);
    assertCategoryCheckout((int)$unclassified['projectCategoryIdSnapshot'] === 0
        && $unclassified['projectCategoryNameSnapshot'] === '未分类',
        'checkout preparation keeps deleted historical categories explicitly unclassified');

    $wrongStore = $resolver->resolveInTx(79586, 133);
    assertCategoryCheckout($wrongStore === ['id' => 0, 'name' => '未分类'],
        'checkout does not borrow a project category from another store');

    // The product manager has confirmed this RH project belongs to 生美/卡项.
    // Simulate the narrowly scoped configuration repair only in this rolled-
    // back transaction; the actual RH instance is not touched by this test.
    Db::name('store_product')->where('id', 372012)->where('relation_id', 133)
        ->where('cate_id', '21560')->update(['cate_id' => '26332']);
    $corrected = $prepareLine->invoke($preparation, $line, 133);
    assertCategoryCheckout((int)$corrected['projectCategoryIdSnapshot'] === 26332
        && $corrected['projectCategoryNameSnapshot'] === '卡项',
        'after the confirmed product-category repair, checkout freezes 生美/卡项');

    $reportClass = new ReflectionClass(StoreUnifiedReportServices::class);
    $report = $reportClass->newInstanceWithoutConstructor();
    $definitionsMethod = $reportClass->getMethod('itemAnalysisCategoryDefinitions');
    $definitionsMethod->setAccessible(true);
    $definitions = $definitionsMethod->invoke($report, [133]);
    $matchMethod = $reportClass->getMethod('itemAnalysisConfiguredCategory');
    $matchMethod->setAccessible(true);
    assertCategoryCheckout(isset($definitions['0'])
        && $matchMethod->invoke($report, $definitions, 0, '') === ['id' => '0', 'label' => '未分类']
        && $matchMethod->invoke($report, $definitions, 21560, '') === ['id' => '0', 'label' => '未分类'],
        'report keeps uncategorized consumption visible without inventing a current category');
    $reportInput = ['report' => 'store_item_analysis', 'start_date' => '2026-09-22', 'end_date' => '2026-09-22'];
    $queried = (new StoreUnifiedReportServices())->query([133], $reportInput);
    $exported = (new StoreUnifiedReportServices())->export([133], $reportInput);
    assertCategoryCheckout(in_array('未分类', array_column($queried['column_groups'], 'label'), true)
        && array_column($queried['columns'], 'key') === array_column($exported['columns'], 'key')
        && isset($queried['summary_row']['item_analysis_category_0_consume']),
        'local report query and export both expose the same unclassified amount column');
    // The modal prioritizes source_explanation over logic; test what users
    // actually see, not only the backend's unused fallback field.
    $explanations = array_column($queried['columns'], 'source_explanation', 'key');
    $unclassifiedExplanation = (string)($explanations['item_analysis_category_0_consume'] ?? '');
    assertCategoryCheckout(str_contains($unclassifiedExplanation, '以后改项目分类不会改动历史记录')
        && str_contains($unclassifiedExplanation, '冲销按发生日扣回')
        && str_contains((string)($explanations['item_analysis_consume_today'] ?? ''), '查询结束日当天')
        && str_contains((string)($explanations['store_name'] ?? ''), '当前账号可以查看')
        && !str_contains(implode(' ', $explanations), '口径'),
        'column-source text explains historical categories, reversals, and date range in plain language');
} finally {
    Db::rollback();
}

echo "category checkout integration: PASS\n";
