<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$source = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3SupplementSalespersonAdjustmentServices.php');

foreach ([
    "'performanceAmountCents' => abs((int)(\$row['amount_cents'] ?? 0))",
    "'performanceAmountLocked' => true",
    "'performanceBaseAmountCents' => (int)\$source['repaymentAmountCents']",
    "p.performance_independent",
    "'supplement_personnel_group_weight_invalid'",
    "\$groups[(string)\$person['allocationGroupKey']][] = \$index",
    "\$baseAmount = (int)(\$source['repaymentAmountCents'] ?? 0)",
    "'repaymentAmountCents' => max(0, (int)(\$row[\$definition[1]] ?? 0))",
    "'performanceAmountManual' => \$performanceAmountManual",
    "'performanceAmountCents' => \$performanceAmountCents",
    'manualPerformanceAmount',
    "'supplement_personnel_manual_amount_invalid'",
] as $needle) {
    if (strpos($source, $needle) === false) {
        throw new RuntimeException('missing supplement salesperson independent-group contract: ' . $needle);
    }
}

echo "SUPPLEMENT_SALESPERSON_INDEPENDENT_GROUP_CONTRACT_OK\n";
