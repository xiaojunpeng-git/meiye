<?php

$root = dirname(__DIR__, 3);
$kernel = file_get_contents($root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutSettlementKernel.php');
$repository = file_get_contents($root . '/后端代码/app/services/cashier/v3/settlement/ThinkPhpCashierV3CheckoutRequestRepository.php');
$projection = file_get_contents($root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutProjectionServices.php');
$salesPlan = file_get_contents($root . '/后端代码/app/services/cashier/v3/order/settlement/CashierV3SalesOrderPlanV1.php');
$salesQuery = file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php');
$saleService = file_get_contents($root . '/后端代码/app/services/cashier/v3/settlement/CashierV3SaleProjectServiceCompletionServices.php');
$directEntitlement = file_get_contents($root . '/后端代码/app/services/cashier/v3/checkout/CashierV3DirectSnapshotEntitlementSettlementServices.php');
$entitlementKernel = file_get_contents($root . '/后端代码/app/services/cashier/v3/checkout/CashierV3EntitlementCompletionKernel.php');
$entitlementPlan = file_get_contents($root . '/后端代码/app/services/cashier/v3/checkout/persistence/CashierV3EntitlementCompletionPlanV1.php');
$serviceQuery = file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php');
$migration = file_get_contents($root . '/后端代码/database/upgrades/2026-09-16-收银V3购物车明细备注唯一快照/02-正式升级.sql');

$checks = [
    'checkout_accepts_optional_detail_remark' => strpos($kernel, "'detailRemark'], 'saleLines['") !== false
        && strpos($kernel, "'manualLaborFeeCents', 'detailRemark'") !== false
        && substr_count($kernel, "'detailRemarkSnapshot' => (string)(\$line['detailRemark'] ?? ''),") === 2,
    'checkout_draft_persists_snapshot' => strpos($repository, "'detailRemarkSnapshot' => 'detail_remark_snapshot'") !== false
        && strpos($repository, "'detail_remark_snapshot' => (string)(\$row['detailRemarkSnapshot'] ?? ''),") !== false,
    'projection_rechecks_fingerprint_and_exposes_snapshot' => substr_count($projection, "'detailRemark' => \$detailRemark,") === 2
        && substr_count($projection, "\$fingerprintInput['detailRemark'] = \$detailRemark;") === 2,
    'sales_order_persists_and_reads_snapshot' => strpos($salesPlan, "'detail_remark_snapshot' => \$line['detail_remark_snapshot'],") !== false
        && strpos($salesQuery, 'detail_remark_snapshot,line_version') !== false
        && strpos($salesQuery, "'detailRemark' => (string)(\$line['detail_remark_snapshot'] ?? ''),") !== false,
    'sale_project_service_fact_carries_snapshot' => strpos($saleService, "'detail_remark_snapshot' => (string)(\$line['detail_remark_snapshot'] ?? ''),") !== false,
    'entitlement_service_fact_carries_snapshot' => strpos($directEntitlement, "'detailRemark' => (string)(\$line['detail_remark_snapshot'] ?? ''),") !== false
        && strpos($entitlementKernel, "'detailRemark' => (string)(\$intentLine['detailRemark'] ?? ''),") !== false
        && strpos($entitlementPlan, "'detail_remark_snapshot' => \$line['detail_remark_snapshot'],") !== false,
    'service_record_detail_reads_snapshot' => strpos($serviceQuery, "'sf.detail_remark_snapshot',") !== false
        && strpos($serviceQuery, "'detailRemark' => (string)(\$row['detail_remark_snapshot'] ?? ''),") !== false,
    'migration_adds_three_immutable_projection_columns' => substr_count($migration, 'ADD COLUMN `detail_remark_snapshot` mediumtext NULL') === 3
        && strpos($migration, 'eb_cashier_v3_checkout_line_draft') !== false
        && strpos($migration, 'eb_cashier_v3_sales_order_line') !== false
        && strpos($migration, 'eb_cashier_v3_entitlement_service_fact') !== false,
];

$failed = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$ok) $failed++;
}
echo $failed === 0 ? "CART_LINE_DETAIL_REMARK_BACKEND_CONTRACT=PASS\n" : "CART_LINE_DETAIL_REMARK_BACKEND_CONTRACT=FAIL\n";
exit($failed === 0 ? 0 : 1);
