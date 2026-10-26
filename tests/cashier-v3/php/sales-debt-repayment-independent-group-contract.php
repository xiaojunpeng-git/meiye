<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/settlement/CashierV3DebtRepaymentServices.php');

foreach ([
    "'staff_job_position sjp'",
    'p.performance_independent',
    "'allocationGroupKey'",
    "'debt_repayment_salespeople_group_weight_invalid'",
    'foreach($groups as $groupKey=>$indexes)',
    "'performanceIndependent'=>\$performanceIndependent",
    "'performanceAmountManual'",
    "'performanceAmountCents'",
    'manualPerformanceAmount',
    "'debt_repayment_salespeople_manual_amount_invalid'",
] as $needle) {
    if (strpos($service, $needle) === false) {
        throw new RuntimeException('missing independent salesperson group contract: ' . $needle);
    }
}
if (strpos($service, 'debt_repayment_salespeople_weight_invalid') !== false) {
    throw new RuntimeException('repayment must not restore one global salesperson 100% gate');
}
echo "SALES_DEBT_REPAYMENT_INDEPENDENT_GROUP_CONTRACT_OK\n";
