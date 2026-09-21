<?php

declare(strict_types=1);

namespace app\services {
    class BaseServices {}
}

namespace {
    $root = dirname(__DIR__, 3);
    $serviceSource = (string)file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportServices.php');
    $cashierController = (string)file_get_contents($root . '/后端代码/app/controller/cashier/v3/Report.php');
    $storeController = (string)file_get_contents($root . '/后端代码/app/controller/store/report/UnifiedReport.php');
    $adminController = (string)file_get_contents($root . '/后端代码/app/controller/admin/v1/report/UnifiedReport.php');

    // Grouping and display use the same frozen full path. The exact-path
    // drilldown is a separate narrowing filter, not the prefix search input.
    foreach ([
        'group by frozen full path' => '$this->partnerSummaryGroupKey($month, $fact, $categoryPath)',
        'show frozen full path' => "'performance_type' => \$categoryPath",
        'exact category drilldown' => "'category_path_exact'=>'category_path_snapshot'",
        'exact query comparison' => "COALESCE(c.category_path_snapshot,d.category_path_snapshot)=?",
        'detail shows full path' => "\$row['performance_type'] = (string)\$row['category_path_snapshot']",
    ] as $name => $needle) {
        if (strpos($serviceSource, $needle) === false) throw new RuntimeException("missing {$name}");
    }
    if (strpos($serviceSource, "'category_path_exact'=>'category_path_snapshot', 'partner_name'=>'partner_name_snapshot'") !== false) {
        throw new RuntimeException('hidden partner label still splits category drilldown');
    }
    foreach ([$cashierController, $storeController, $adminController] as $controllerSource) {
        if (strpos($controllerSource, "['category_path_exact'") === false) {
            throw new RuntimeException('report controller dropped exact drilldown path');
        }
    }

    require_once $root . '/后端代码/app/services/report/StoreUnifiedReportServices.php';
    $reflection = new \ReflectionClass(\app\services\report\StoreUnifiedReportServices::class);
    $service = $reflection->newInstanceWithoutConstructor();
    $groupKey = $reflection->getMethod('partnerSummaryGroupKey');
    $sameVisibleGroup = ['store_id' => 7, 'company_dimension_id' => '2', 'city_manager_dimension_id' => '4'];
    $otherPartner = $sameVisibleGroup + ['partner_name_snapshot' => '另一合作方'];
    if ($groupKey->invoke($service, '2026-09', $sameVisibleGroup, '六维 / 自营')
        !== $groupKey->invoke($service, '2026-09', $otherPartner, '六维 / 自营')
        || $groupKey->invoke($service, '2026-09', $sameVisibleGroup, '六维 / 自营')
        === $groupKey->invoke($service, '2026-09', $sameVisibleGroup, '六维 / 自营 / 自营ks')) {
        throw new RuntimeException('identical full categories did not merge, or different paths were mixed');
    }
    $sum = $reflection->getMethod('selectedCategoryMetricTotal');
    $amounts = ['sale-1|11' => 150000, 'sale-1|12' => 50000];
    $firstCategory = [['source_line_id' => 'sale-1', 'category_id_snapshot' => 11]];
    $secondCategory = [['source_line_id' => 'sale-1', 'category_id_snapshot' => 12]];
    if ($sum->invoke($service, $firstCategory, $amounts) !== 150000
        || $sum->invoke($service, $secondCategory, $amounts) !== 50000
        || $sum->invoke($service, array_merge($firstCategory, $firstCategory, $secondCategory), $amounts) !== 200000) {
        throw new RuntimeException('detail total leaked a sibling card category or counted one twice');
    }

    echo "partner category full path and drilldown contract: PASS\n";
}
