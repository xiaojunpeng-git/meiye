<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3RechargeDebtReversalServices.php');
$lifecycle = file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3RechargeOrderLifecycleServices.php');
$failures = [];

foreach ([
    "CashierV3TransactionGuard::assertInTransaction('rechargeDebtReversal.prepare')",
    "cashier_v3_recharge_debt_authority",
    "cashier_v3_recharge_debt_repayment",
    "store_debt_item",
    "where('repaid_debt', '0.00')",
    "'status' => 2",
    "cashier_v3_order_lifecycle_debt_reversal",
    "'reversal_type' => 'void'",
    "recharge_void_debt_repayment_exists",
] as $needle) {
    if (strpos($service, $needle) === false) $failures[] = 'missing debt reversal contract: ' . $needle;
}

if (strpos($service, "if (\$action === 'refund-recharge-order')") === false
    || strpos($service, "return ['debt' => [], 'cancelledDebtCents' => 0]") === false) {
    $failures[] = 'refund must preserve recharge debt';
}
if (strpos($service, "where('id', (int)\$authorityHint['debt_id'])->lock(true)") === false) {
    $failures[] = 'void must follow repayment lock order by locking store_debt first';
}
if (strpos($lifecycle, 'CashierV3RechargeDebtReversalServices') === false
    || strpos($lifecycle, 'recharge_lifecycle_debt_reversal_not_available') !== false) {
    $failures[] = 'recharge lifecycle must delegate debt handling to the dedicated authority';
}
if (strpos($service, "Db::name('user_recharge')->update") !== false
    || strpos($service, "Db::name('store_debt_item')->update") !== false
    || strpos($service, "Db::name('cashier_v3_recharge_debt_repayment')->update") !== false) {
    $failures[] = 'debt reversal must retain original recharge, debt item, and repayment records';
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo "recharge debt reversal contract: PASS\n";
