<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = (string)file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportServices.php');
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
        && str_contains($service, "['actual', '实际业绩'")
        && str_contains($service, "['consume', '消耗业绩'"),
    'summary area has cash share actual and consumption metrics'
);
itemAnalysisAssert(
    str_contains($service, "['today', '当日']")
        && str_contains($service, "['cumulative', '累计']")
        && str_contains($service, "'start' => self::COVERAGE_START"),
    'summary metrics separate cutoff-day and cumulative ranges'
);
itemAnalysisAssert(
    str_contains($service, '$this->money($cash - $share)')
        && str_contains($service, "'现金分成业绩'")
        && str_contains($service, 'array_slice($parts, 0, 2)'),
    'actual performance subtracts share and category headers stop at two levels'
);
itemAnalysisAssert(
    str_contains($service, '$definitions = $this->itemAnalysisCategoryDefinitions($storeId);')
        && str_contains($service, 'foreach ($this->partnerPerformanceDefinitions($storeId) as $partnerDefinition)')
        && str_contains($service, 'Header categories come from the current product-category configuration')
        && str_contains($service, 'CashierV3ScopeResolver::TENANT_SCOPE_ID'),
    'category headers come from configured product categories even without period facts'
);
itemAnalysisAssert(
    str_contains($service, 'cashier_v3_card_sale_category_allocation_fact')
        && str_contains($service, 'cashier_v3_entitlement_service_fact es')
        && str_contains($service, 'consumption_performance_recorded')
        && str_contains($service, "whereIn('p.store_id'")
        && str_contains($service, "where('p.store_id'"),
    'cash and consumption use persisted category facts rather than browser calculation'
);
itemAnalysisAssert(
    str_contains($service, "'rowspan' => 2")
        && str_contains($service, "'tone' => 'category'")
        && str_contains($view, 'groupedHeaderColumns')
        && str_contains($view, 'store-business-report__header-group--consumption'),
    'two-line header supports a fixed summary region and semantic colors'
);
itemAnalysisAssert(
    str_contains($serviceSnapshot, 'project_category_path_snapshot')
        && str_contains($serviceSnapshot, 'CashierV3TransactionGuard::assertInTransaction'),
    'new service facts freeze their category path within the successful transaction'
);

echo "store item analysis contract: PASS\n";
