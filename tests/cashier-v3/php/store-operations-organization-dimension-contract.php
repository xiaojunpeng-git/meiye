<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = (string)file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportServices.php');
$dimensionService = (string)file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportOrganizationDimensionServices.php');

function organizationDimensionAssert(bool $condition, string $message): void
{
    if ($condition) {
        echo "PASS {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function organizationDimensionSection(string $source, string $start, string $end): string
{
    $offset = strpos($source, $start);
    if ($offset === false) throw new RuntimeException("missing {$start}");
    $endOffset = strpos($source, $end, $offset);
    if ($endOffset === false) throw new RuntimeException("missing {$end}");
    return substr($source, $offset, $endOffset - $offset);
}

organizationDimensionAssert(
    str_contains($service, 'StoreUnifiedReportOrganizationDimensionServices')
        && str_contains($service, 'function applyOrganizationDimensions')
        && str_contains($service, 'function organizationDimensionColumns')
        && str_contains($service, 'function organizationDimensionFilterSchema'),
    'first-phase reports reuse the shared historical organization-dimension service'
);

organizationDimensionAssert(
    str_contains($dimensionService, "Db::name('organization_store')")
        && str_contains($dimensionService, "Db::name('organization')->where('is_del', 0)")
        && str_contains($dimensionService, 'private function storePath')
        && str_contains($dimensionService, 'private function resolveCurrentDimension')
        && !str_contains($dimensionService, "->where('valid_from', '<=', \$range['end'])"),
    'organization dimensions resolve from the store ancestor hierarchy without applying the configuration save date as a transaction cutoff'
);

$saleQuery = organizationDimensionSection($service, 'private function operationSaleQuery', 'private function operationFilters');
organizationDimensionAssert(
    str_contains($saleQuery, "->applyFilters(\$query, 's', \$input, \$range)")
        && str_contains($saleQuery, 'before an operation report groups'),
    'sales facts apply company and city-manager filters before aggregation'
);

$summary = organizationDimensionSection($service, 'private function partnerItemSummary', 'private function partnerItemDetail');
organizationDimensionAssert(
    str_contains($summary, 's.organization_id,s.organization_path_snapshot')
        && str_contains($summary, '$this->applyOrganizationDimensions($fact)')
        && str_contains($summary, "(string)\$fact['company_dimension_id']")
        && str_contains($summary, "'company_dimension_id'=>'company_dimension_id'")
        && str_contains($summary, "'city_manager_dimension_id'=>'city_manager_dimension_id'"),
    'partner summary resolves dimensions per fact and carries exact drilldown filters'
);

$detail = organizationDimensionSection($service, 'private function partnerItemDetail', 'private function memberConsumptionDetail');
organizationDimensionAssert(
    str_contains($detail, 's.organization_id,s.organization_path_snapshot')
        && str_contains($detail, '$this->applyOrganizationDimensions($row)')
        && str_contains($detail, '$this->organizationDimensionColumns()')
        && str_contains($detail, "'filter_schema' => \$this->organizationDimensionFilterSchema(\$range)"),
    'partner detail exposes historical company and city-manager columns and filters'
);

$member = organizationDimensionSection($service, 'private function memberConsumptionDetail', 'private function storeItemAnalysis');
organizationDimensionAssert(
    str_contains($member, 's.organization_id,s.organization_path_snapshot')
        && str_contains($member, '$this->applyOrganizationDimensions($row)')
        && str_contains($member, '$this->organizationDimensionColumns()')
        && str_contains($member, "'filter_schema' => \$this->organizationDimensionFilterSchema(\$range)"),
    'member consumption detail exposes historical company and city-manager columns and filters'
);

$analysis = organizationDimensionSection($service, 'private function storeItemAnalysis', 'private function itemAnalysisConsumptionFilters');
organizationDimensionAssert(
    str_contains($analysis, 's.organization_id,s.organization_path_snapshot,s.business_date')
        && str_contains($analysis, "->applyFilters(\$consume, 'p', \$input, \$range)")
        && str_contains($analysis, 'p.organization_id,p.organization_path_snapshot,p.business_date')
        && str_contains($service, "(string)(\$entry['company_dimension_id'] ?? '')")
        && str_contains($analysis, "'filter_schema' => \$this->organizationDimensionFilterSchema(\$range)"),
    'item analysis splits cash and consumption aggregation by historical dimensions'
);

$craftsman = organizationDimensionSection($service, 'private function craftsmanConsumption', 'private function salespersonPerformance');
organizationDimensionAssert(
    str_contains($craftsman, "->applyFilters(\$query, 'p', \$input, \$range)")
        && str_contains($craftsman, 'p.organization_id,p.organization_path_snapshot,p.business_date')
        && str_contains($craftsman, "(string)\$row['company_dimension_id']")
        && str_contains($craftsman, '$this->organizationDimensionColumns()'),
    'craftsman consumption filters and splits performance facts by historical dimensions'
);

$salesperson = organizationDimensionSection($service, 'private function salespersonPerformance', 'private function overview');
organizationDimensionAssert(
    str_contains($salesperson, "->applyFilters(\$query, 'p', \$input, \$range)")
        && str_contains($salesperson, 'p.organization_id,p.organization_path_snapshot,p.business_date')
        && str_contains($salesperson, "(string)\$row['city_manager_dimension_id']")
        && str_contains($salesperson, '$this->organizationDimensionColumns()'),
    'salesperson performance filters and splits performance facts by historical dimensions'
);

organizationDimensionAssert(
    substr_count($service, "'filter_schema' => \$this->organizationDimensionFilterSchema(\$range)") === 7,
    'all six first-phase reports and the craftsman detail expose the same dimension filter metadata'
);
organizationDimensionAssert(
    substr_count($service, '$this->organizationDimensionColumns()') >= 7
        && str_contains($service, "'source_explanation' => \$this->organizationDimensions()->sourceExplanation('company')")
        && !str_contains(organizationDimensionSection($service, 'private function organizationDimensionColumns', 'private function organizationDimensionFilterSchema'), "'key' => 'city_manager'"),
    'all six first-phase reports export the company column and hide the city-manager column'
);

echo "first-phase organization-dimension report contract: PASS\n";
