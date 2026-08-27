<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = (string)file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportServices.php');

function partnerProjectionAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$message}\n");
}

partnerProjectionAssert(
    str_contains($service, 'private function partnerPerformanceDefinitions($storeId): array')
        && str_contains($service, "'key' => 'partner_category_' . \$effectiveId")
        && !str_contains($service, 'fixedPartnerPerformanceDefinitions'),
    'partner columns are configuration-driven and keyed by category id'
);
partnerProjectionAssert(
    str_contains($service, 'count($chain) === 1')
        && str_contains($service, 'hasEnabledPartnerSecondLevel')
        && str_contains($service, 'elseif ((int)$chain[1][\'id\'] === (int)$configuredId)'),
    'enabled second-level categories replace their root and no third level is projected'
);
partnerProjectionAssert(
    str_contains($service, 'd.partner_category_id_snapshot')
        && str_contains($service, 'd.partner_share_amount_cents')
        && str_contains($service, 'cc.partner_category_id_snapshot')
        && str_contains($service, 'cc.partner_share_amount_cents'),
    'ordinary rows and card allocations read frozen partner share facts rather than live ratios'
);
partnerProjectionAssert(
    str_contains($service, 'private function cardPartnerSharesBySaleFact')
        && str_contains($service, "->field('sale_fact_id,partner_category_id_snapshot,partner_share_amount_cents')")
        && str_contains($service, "\$this->operationSaleQuery(\$storeId, \$range, \$input, false)"),
    'card rows stay one row per sold card and aggregate their component snapshots by sale fact'
);
partnerProjectionAssert(
    str_contains($service, "\$row['partner_performance'] = \$this->money(\$partnerPerformanceCents);")
        && str_contains($service, "\$row['actual_cash_performance'] = \$this->money(\$receiptTotal - \$partnerPerformanceCents);")
        && !str_contains($service, 'max(0, $receiptTotal - $partnerPerformanceCents)'),
    'partner performance is the dynamic share sum and actual cash follows the exact subtraction rule'
);

echo "partner share projection contract: PASS\n";
