<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = (string)file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportServices.php');
$metricReader = (string)file_get_contents($root . '/后端代码/app/services/query/metric/RegisteredMetricReadServices.php');
$view = (string)file_get_contents($root . '/前端代码/cashier-v3/src/views/StoreBusinessReportView.vue');
$serviceSnapshot = (string)file_get_contents($root . '/后端代码/app/services/report/StoreReportServiceCategorySnapshotServices.php');

function itemAnalysisAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$message}\n");
}

itemAnalysisAssert(
    str_contains($service, "['cash', '现金业绩'")
        && str_contains($service, "['share', '分成业绩'")
        && str_contains($service, "['actual', '分成后业绩'")
        && str_contains($service, "['consume', '消耗业绩'"),
    'summary area distinguishes cash, share, after-split and consumption metrics'
);
itemAnalysisAssert(
    str_contains($service, "['today', '当日']")
        && str_contains($service, "['cumulative', '累计']")
        && str_contains($service, "'start' => self::COVERAGE_START"),
    'summary metrics separate cutoff-day and cumulative ranges'
);
itemAnalysisAssert(
    str_contains($service, '$this->money($cash - $share)')
        && str_contains($service, "['share', '分成业绩'")
        && str_contains($service, '该列不是实际业绩')
        && str_contains($service, 'array_slice($parts, 0, 2)'),
    'after-split performance subtracts share and is not mislabeled as actual performance'
);
itemAnalysisAssert(
    str_contains($service, "['after_split', '分成后业绩'")
        && str_contains($service, "['cash', 'after_split', 'consume']")
        && str_contains($service, "_after_split'] = \$this->money(\$this->itemAnalysisAfterSplitCents(")
        && !str_contains($service, "['share', '现金分成业绩', '本分类")
        && str_contains($view, "key.includes('_after_split_') || key.endsWith('_after_split')"),
    'dynamic category result shows cash after frozen partner share, not the share amount itself'
);
itemAnalysisAssert(
    str_contains($service, '$definitions = $this->itemAnalysisCategoryDefinitions($storeId);')
        && str_contains($service, "Db::name('store_product_category')->where('type', 0)->where('relation_id', 0)")
        && str_contains($service, "field('id,pid,cate_name,is_show')")
        && str_contains($service, '(int)($category[\'is_show\'] ?? 0) === 1')
        && str_contains($service, 'all currently visible product categories')
        && str_contains($service, 'CashierV3ScopeResolver::TENANT_SCOPE_ID'),
    'category headers come from all visible product categories even without period facts'
);
itemAnalysisAssert(
    str_contains($metricReader, 'cashier_v3_card_sale_category_allocation_fact')
        && str_contains($metricReader, 'cashier_v3_entitlement_service_fact')
        && str_contains($metricReader, 'completedServicePerformanceCategoryRows')
        && str_contains($service, "->categoryReportBuckets('cash_performance'")
        && str_contains($service, "->categoryReportBuckets('consume_amount'")
        && str_contains($service, "['cash_cents'] = (int)\$entry['store_metric_value']")
        && str_contains($service, "['consume_cents'] = (int)\$entry['store_metric_value']"),
    'cash and consumption use persisted category facts rather than browser calculation'
);
itemAnalysisAssert(
    str_contains($service, 'COALESCE(c.category_id_snapshot,d.category_id_snapshot,0) AS category_id_snapshot')
        && str_contains($service, 'COALESCE(c.partner_share_amount_cents,d.partner_share_amount_cents,0) AS share_cents')
        && str_contains($service, '(int)($entry[\'category_id_snapshot\'] ?? 0)'),
    'category grouping uses the product snapshot while partner amounts stay in frozen share cents'
);
itemAnalysisAssert(
    str_contains($service, "'rowspan' => 2")
        && str_contains($service, "'tone' => 'category'")
        && str_contains($view, 'headerRows')
        && str_contains($view, 'store-business-report__header-group--consumption'),
    'two-line header supports a fixed summary region and semantic colors'
);
itemAnalysisAssert(
    str_contains($serviceSnapshot, 'project_category_path_snapshot')
        && str_contains($serviceSnapshot, 'CashierV3TransactionGuard::assertInTransaction'),
    'new service facts freeze their category path within the successful transaction'
);

echo "store item analysis contract: PASS\n";
