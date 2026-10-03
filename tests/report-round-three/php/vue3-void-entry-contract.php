<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$serviceRoot = $root . '/后端代码/app/services/';

// These are the shared backend entries reached by Vue3 store reports and
// dashboards. The test protects wiring; the SQLite test protects predicates.
$required = [
    'cashier/v3/order/CashierV3OrderCenterVoidServices.php' => [
        "'recharge_debt_repayment'", 'supplement_payment_fact_amount_mismatch',
        'appendSalesRepaymentAllocationReversals', 'appendRechargeRepaymentBalanceReversal',
        'supplement_allocation_amount_mismatch', 'supplement_balance_fact_conflict',
    ],
    'query/metric/RegisteredMetricReadServices.php' => [
        'excludeVoidedRechargeOrderFacts', 'excludeVoidedSupplementPaymentFacts',
        'excludeVoidedSupplementAllocations', 'excludeVoidedSalesOrderServices',
    ],
    'report/StoreUnifiedReportServices.php' => [
        'excludeVoidedRechargeOrderFacts', 'excludeVoidedSupplementPaymentFacts',
        'excludeVoidedSupplementAllocations', 'excludeVoidedSalesOrderServices',
        '$validOrders', '$group[\'member_count\'] = count($members)',
        'CustomerLifecycleNormalReadServices',
    ],
    'report/CustomerAnalyticsServices.php' => ['CustomerLifecycleNormalReadServices', 'unconsumed_sale_void'],
    'report/CustomerLifecycleNormalReadServices.php' => [
        'first_course_completed', 'pending_conversion', 'service_completed',
        'excludeVoidedSalesOrderFacts', 'excludeVoidedSalesOrderServices',
    ],
    'report/StoreUnifiedReportPhaseTwoServices.php' => [
        'excludeVoidedRechargeOrderFacts', 'excludeVoidedSupplementPaymentFacts',
        'excludeVoidedSalesOrderServices', 'remaining_card_sale_void',
    ],
    'report/StoreUnifiedReportPhaseThreeServices.php' => [
        'excludeVoidedSupplementAllocations', 'excludeVoidedSalesOrderServices',
    ],
    'report/StoreUnifiedReportPhaseFourServices.php' => [
        'excludeVoidedSupplementAllocations', 'excludeVoidedSalesOrderServices',
    ],
    'report/StoreUnifiedReportPhaseSixServices.php' => [
        'excludeVoidedSupplementAllocations', 'excludeVoidedSalesOrderServices',
        'excludeVoidedSupplementPaymentFacts',
    ],
    'report/GroupManagementDashboardDailyAggregateServices.php' => [
        'excludeVoidedRechargeOrderFacts', 'excludeVoidedSupplementPaymentFacts',
        'excludeVoidedSalesOrderServices',
    ],
    'report/MemberManagementDashboardServices.php' => [
        'excludeVoidedSupplementAllocations', 'excludeVoidedSalesOrderServices',
    ],
    'report/ProductManagementDashboardServices.php' => [
        'excludeVoidedSupplementAllocations', 'excludeVoidedSalesOrderServices',
    ],
    'mobile/warehouse/MobileWarehouseServices.php' => [
        'excludeVoidedSalesOrderServices',
    ],
];

$failures = [];
foreach ($required as $path => $tokens) {
    $source = file_get_contents($serviceRoot . $path);
    if (!is_string($source)) {
        $failures[] = 'cannot read ' . $path;
        continue;
    }
    foreach ($tokens as $token) {
        if (!str_contains($source, $token)) $failures[] = $path . ' misses ' . $token;
    }
}

$audit = (string)file_get_contents($root . '/tests/report-round-three/sql/void-history-audit.sql');
if (preg_match('/\b(UPDATE|DELETE|INSERT|REPLACE|TRUNCATE|ALTER|DROP|CREATE)\b/i', preg_replace('/--[^\n]*/', '', $audit))) {
    $failures[] = 'history audit must remain read-only';
}
if (substr_count($audit, 'AS issue') !== 8) $failures[] = 'history audit must cover seven reversal types and first-course drift';

if ($failures !== []) {
    fwrite(STDERR, "FAIL\n" . implode("\n", $failures) . "\n");
    exit(1);
}

echo 'PASS Vue3 normal-void report entry contract' . PHP_EOL;
