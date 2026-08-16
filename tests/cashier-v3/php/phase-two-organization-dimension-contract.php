<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = (string)file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php');

function phaseTwoDimensionAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL {$message}\n");
        exit(1);
    }
    echo "PASS {$message}\n";
}

phaseTwoDimensionAssert(
    str_contains($service, 'StoreUnifiedReportOrganizationDimensionServices')
    && str_contains($service, "'company_dimension_id','city_manager_dimension_id'"),
    'phase-two reports reuse the shared company and city-manager dimension contract'
);

phaseTwoDimensionAssert(
    str_contains($service, "\$this->organizationDimensions()->filterSchema(\$this->activeRange)")
    && str_contains($service, "\$metadata['filter_schema']=array_merge"),
    'all phase-two result payloads expose the same two server-owned filter options'
);

phaseTwoDimensionAssert(
    str_contains($service, "\$this->applyOrganizationFilters(\$query, \$alias, \$this->activeInput, \$this->activeRange)")
    && str_contains($service, "'sv', \$input, \$range")
    && str_contains($service, "'member_visit_service', \$input, \$range")
    && str_contains($service, "'annual_visit_service', \$input, \$range")
    && str_contains($service, "'cross_sale',\$input"),
    'payment, service and sale facts narrow by historical dimensions before aggregation'
);

phaseTwoDimensionAssert(
    str_contains($service, 'MAX(p.organization_id) organization_id,MAX(p.organization_path_snapshot) organization_path_snapshot')
    && str_contains($service, 's.organization_id,s.organization_path_snapshot')
    && str_contains($service, 'p.organization_id,p.organization_path_snapshot'),
    'event-level detail projections retain the fact organization path and business date'
);

phaseTwoDimensionAssert(
    str_contains($service, 'o.organization_id,o.organization_path_snapshot')
    && str_contains($service, 'projectOrganizationRows')
    && str_contains($service, 'selectedDimensionMatches'),
    'refund ledger resolves its dimension from the linked immutable sales-order snapshot without querying missing lifecycle columns'
);

phaseTwoDimensionAssert(
    str_contains($service, 'source_explanation')
    && str_contains($service, 'withDimensionFilterParams')
    && str_contains($service, 'appendDimensionFiltersToRows'),
    'columns, drilldowns and exports carry the same dimension explanation and narrowing parameters'
);

phaseTwoDimensionAssert(
    str_contains($service, "['key'=>'company','label'=>'分公司'")
    && !str_contains($service, "['key'=>'city_manager','label'=>'城市经理'"),
    'phase-two result columns hide city manager while keeping dimension filters server-owned'
);

echo "phase-two organization-dimension report contract: PASS\n";
