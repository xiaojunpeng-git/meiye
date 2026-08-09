<?php

$root = dirname(__DIR__, 3);
$read = static function (string $path) use ($root): string {
    $source = file_get_contents($root . $path);
    if ($source === false) {
        fwrite(STDERR, "missing source: {$path}\n");
        exit(1);
    }
    return $source;
};

$reader = $read('/后端代码/app/services/cashier/v3/dashboard/CashierV3BusinessDashboardReadModel.php');
$module = $read('/后端代码/app/services/cashier/v3/dashboard/CashierV3BusinessDashboardModule.php');
$bootstrap = $read('/后端代码/app/services/cashier/v3/bootstrap/CashierV3Bootstrap.php');
$management = $read('/前端代码/cashier-v3/src/views/ManagementCenterView.vue');

$requiredFacts = [
    'cashier_v3_sale_fact', 'cashier_v3_payment_fact', 'cashier_v3_balance_fact',
    'cashier_v3_performance_fact', 'cashier_v3_entitlement_service_fact',
    'cashier_v3_recharge_debt_authority',
];
$requiredActions = [
    'query-business-dashboard-summary', 'query-business-dashboard-trend',
    'query-business-dashboard-ranking', 'open-business-dashboard-detail', 'export-business-dashboard',
];
$requiredMetrics = [
    'sales_amount', 'cash_performance', 'actual_performance', 'balance_deduction',
    'recharge_amount', 'debt_amount', 'service_count', 'consumption_performance', 'labor_performance',
];

$failures = [];
foreach ($requiredFacts as $fact) if (strpos($reader, $fact) === false) $failures[] = 'missing fact authority: ' . $fact;
foreach ($requiredActions as $action) if (strpos($module, $action) === false) $failures[] = 'missing projection action: ' . $action;
foreach ($requiredMetrics as $metric) if (strpos($reader, "'" . $metric . "'") === false) $failures[] = 'missing metric: ' . $metric;
if (strpos($reader, 'use app\\services\\statistics\\BusinessDashboardServices') !== false
    || strpos($reader, 'use app\\services\\order\\agent\\AgentOrderServices') !== false) $failures[] = 'legacy dashboard source leaked into V3 reader';
if (strpos($reader, "fieldRaw(\$sql)->value('amount')") !== false) $failures[] = 'dashboard aggregate must not replace its SELECT alias through value(amount)';
if (strpos($reader, "fieldRaw(\$sql)->find()") === false || strpos($reader, "(int)(\$row['amount'] ?? 0)") === false) $failures[] = 'dashboard aggregate must read the amount alias from its aggregate row';
if (strpos($reader, 'sd.total_debt * 100 AS debt_amount') === false || strpos($reader, 'sd.debt_amount * 100 AS debt_amount') !== false) $failures[] = 'dashboard debt metric must use store_debt.total_debt authority';
if (strpos($reader, "'amount' => 'sd.total_debt * 100'") === false || strpos($reader, "SUM(debt_amount)") !== false) $failures[] = 'dashboard debt aggregate must not depend on a SELECT alias';
if (strpos($reader, "'value' => \$definition['unit'] === '元' ? \$this->centsToMoney(\$rawValue) : \$rawValue") === false) $failures[] = 'dashboard non-money metrics must not be converted from cents';
if (strpos($reader, "->group(\$dateColumn)->orderRaw(\$dateColumn . ' ASC')") === false) $failures[] = 'dashboard trend must allow its static debt date expression in ORDER BY';
if (strpos($reader, "? \"0 AS operator_id, '' AS operator_name\"") === false) $failures[] = 'dashboard debt ranking must not query an absent operator column';
if (strpos($reader, "CASE WHEN fact_direction = 'reversal'") !== false
    || strpos($reader, "fact_direction'] ?? 'forward') === 'reversal'") !== false) $failures[] = 'signed fact amounts must not be inverted again by fact_direction';
if (strpos($reader, 'COALESCE(SUM({$amount}),0) AS amount') === false
    || substr_count($reader, '"SUM({$amount})"') < 2) $failures[] = 'dashboard totals, trends and rankings must sum signed fact amounts directly';
if (strpos($reader, 'return (string)intdiv($cents, 100);') === false || strpos($reader, 'number_format($cents / 100, 2') !== false) $failures[] = 'dashboard money display must preserve the cashier whole-yuan rule';
if (strpos($bootstrap, 'CashierV3BusinessDashboardModule::install($dispatcher, $assembler);') === false) $failures[] = 'C4 module is not installed by production bootstrap';
if (strpos($management, "id: 'business-dashboard-v3'") === false || strpos($management, "cashier-v3-business-dashboard") === false) $failures[] = 'management entry is not routed to dashboard';

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "[FAIL] {$failure}\n");
    exit(1);
}
echo "[PASS] business-dashboard-fact-contract\n";
