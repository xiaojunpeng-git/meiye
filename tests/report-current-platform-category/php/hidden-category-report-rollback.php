<?php

declare(strict_types=1);

use app\services\report\MemberManagementDashboardServices;
use app\services\report\GroupManagementDashboardServices;
use app\services\report\ProductManagementDashboardServices;
use app\services\report\StoreReportCurrentProductCategoryServices;
use app\services\report\StoreUnifiedReportPhaseFourServices;
use app\services\report\StoreUnifiedReportPhaseSixServices;
use app\services\report\StoreUnifiedReportPhaseThreeServices;
use app\services\report\StoreUnifiedReportServices;
use think\facade\Db;

// Local transaction only: hiding a category must not erase its report identity.
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';
(new think\App(rtrim($backend, '/\\') . '/'))->initialize();

function hiddenCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo 'PASS ', $message, PHP_EOL;
}

$ids = [26302, 26332];
$before = Db::name('store_product_category')->whereIn('id', $ids)->order('id')->select()->toArray();
hiddenCheck(count($before) === 2, 'local platform-category fixtures exist');
Db::startTrans();
$error = null;
try {
    Db::name('store_product_category')->whereIn('id', $ids)->update(['is_show' => 0]);
    Db::name('store_product')->where('id', 91038)->update(['cate_id' => '26332']);

    $category = (new StoreReportCurrentProductCategoryServices())->resolve([
        ['store_id' => 126, 'product_id' => 91111],
    ]);
    hiddenCheck(($category[0]['path'] ?? '') === '生美/卡项',
        'product ID still resolves the hidden platform category');

    $phaseThree = new ReflectionMethod(StoreUnifiedReportPhaseThreeServices::class, 'categoryScope');
    $phaseThree->setAccessible(true);
    hiddenCheck(in_array(26332, $phaseThree->invoke(new StoreUnifiedReportPhaseThreeServices(),
        ['category_path' => '生美/卡项']), true), 'hidden category remains selectable in report scope');

    $phaseFour = new ReflectionMethod(StoreUnifiedReportPhaseFourServices::class, 'descendantsByPath');
    $phaseFour->setAccessible(true);
    hiddenCheck(in_array(26332, $phaseFour->invoke(new StoreUnifiedReportPhaseFourServices(),
        '生美/卡项'), true), 'hidden category remains in report descendants');

    $analysis = new ReflectionMethod(StoreUnifiedReportServices::class, 'itemAnalysisCategoryDefinitions');
    $analysis->setAccessible(true);
    hiddenCheck(isset($analysis->invoke(new StoreUnifiedReportServices(), 126)['26332']),
        'item analysis includes the hidden platform category column');
    $reportInput = ['report' => 'store_item_analysis', 'start_date' => '2026-10-01',
        'end_date' => '2026-10-11', 'page' => 1, 'limit' => 20];
    $report = new StoreUnifiedReportServices();
    $screen = $report->query([126], $reportInput);
    $export = $report->export([126], $reportInput);
    $screenKeys = array_column($screen['columns'], 'key');
    hiddenCheck(count(array_filter($screenKeys, static fn(string $key): bool =>
        strpos($key, 'item_analysis_category_26332_') === 0)) > 0
        && $screenKeys === array_column($export['columns'], 'key'),
        'hidden category has the same column in report query and export');

    $salary = new ReflectionMethod(StoreUnifiedReportPhaseSixServices::class, 'salaryCategoryDefinitions');
    $salary->setAccessible(true);
    $salaryColumns = $salary->invoke(new StoreUnifiedReportPhaseSixServices(), [26332 => '生美/卡项']);
    hiddenCheck(($salaryColumns['targets'][26332] ?? '') === 'salary_category_cash_26332',
        'salary detail uses the ordinary current-category column');

    $productCategories = new ReflectionMethod(ProductManagementDashboardServices::class, 'categories');
    $productCategories->setAccessible(true);
    $productRoots = $productCategories->invoke(new ProductManagementDashboardServices())['roots'];
    hiddenCheck(in_array(26302, array_column($productRoots, 'id'), true),
        'product dashboard retains the hidden platform root');

    $groupCategories = new ReflectionMethod(GroupManagementDashboardServices::class, 'categories');
    $groupCategories->setAccessible(true);
    $groupRoots = $groupCategories->invoke(new GroupManagementDashboardServices())['roots'];
    hiddenCheck(in_array(26302, array_column($groupRoots, 'id'), true),
        'group dashboard retains the hidden platform root');

    $memberCategories = new ReflectionMethod(MemberManagementDashboardServices::class, 'categories');
    $memberCategories->setAccessible(true);
    hiddenCheck(in_array(26302, array_column($memberCategories->invoke(new MemberManagementDashboardServices()), 'id'), true),
        'member dashboard retains the hidden platform root');
} catch (Throwable $failure) {
    $error = $failure;
} finally {
    Db::rollback();
}
if ($error !== null) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
hiddenCheck(Db::name('store_product_category')->whereIn('id', $ids)->order('id')->select()->toArray() === $before,
    'local category visibility edits rolled back');
