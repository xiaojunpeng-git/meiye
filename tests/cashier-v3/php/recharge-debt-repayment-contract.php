<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3RechargeDebtRepaymentServices.php';
$debtService = $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3DebtRepaymentServices.php';
$migration = $root . '/后端代码/database/upgrades/2026-08-02-收银V3充值欠款补交权威/02-正式升级.sql';
$atomic = $root . '/后端代码/app/services/user/UserBalanceAtomicServices.php';
$bridge = $root . '/前端代码/cashier-v3/src/services/cashierV3Bridge.js';
$snapshotMigration = $root . '/后端代码/database/upgrades/2026-08-06-收银V3补交销售人统一快照/02-正式升级.sql';
foreach ([$service, $debtService, $migration, $atomic, $bridge] as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "missing: {$file}\n");
        exit(1);
    }
}
$source = file_get_contents($service);
$debtSource = file_get_contents($debtService);
$sql = file_get_contents($migration);
$balance = file_get_contents($atomic);
$bridgeSource = file_get_contents($bridge);
$required = [
    'cashier_v3_recharge_debt_authority',
    "order_id'] ?? -1) !== 0",
    'cashier_v3_recharge_debt_repayment',
    'cashier_v3_recharge_debt_repayment_payment',
    "'debt.repaid'",
    "'recharge_debt_repayment'",
    "'repaid_debt_amount'",
    "'repaid_debt'",
    "'member_balance'",
    "'sales_performance_allocated'",
    "'actual_performance_recorded'",
    'cashier_salesperson_enabled',
    '$result[\'data\'][\'debtRepayment\'] = array_merge',
    "'status' => 'succeeded'",
];
foreach ($required as $needle) {
    if (strpos($source, $needle) === false && strpos($debtSource, $needle) === false) {
        fwrite(STDERR, "missing service contract: {$needle}\n");
        exit(1);
    }
}
foreach (["'allocationWeight'", "'allocationWeightDenominator' => 100", "'salespeople_snapshot_json'"] as $needle) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "missing unified salesperson allocation contract: {$needle}\n");
        exit(1);
    }
}
foreach (["'couponUserId' => 0", "'couponNameSnapshot' => ''", "'couponDiscountCents' => 0"] as $needle) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "recharge repayment kernel sale-line coupon snapshot missing: {$needle}\n");
        exit(1);
    }
}
if (!is_file($snapshotMigration) || strpos((string)file_get_contents($snapshotMigration), 'eb_cashier_v3_recharge_debt_repayment') === false) {
    fwrite(STDERR, "missing recharge repayment salesperson snapshot migration\n");
    exit(1);
}
if (strpos($source, 'use app\\services\\order\\StoreDebtServices') !== false
    || strpos($source, 'new StoreDebtServices') !== false) {
    fwrite(STDERR, "legacy StoreDebtServices reuse is forbidden\n");
    exit(1);
}
foreach (['CREATE TABLE IF NOT EXISTS `eb_cashier_v3_recharge_debt_repayment`', 'UNIQUE KEY `uk_tenant_command`'] as $needle) {
    if (strpos($sql, $needle) === false) {
        fwrite(STDERR, "missing migration contract: {$needle}\n");
        exit(1);
    }
}
if (strpos($balance, "'recharge_debt_repayment'") === false) {
    fwrite(STDERR, "missing atomic balance ledger type\n");
    exit(1);
}
if (strpos($bridgeSource, "submit-recharge-debt-repayment", strpos($bridgeSource, 'debtRecordId')) === false
    || strpos($bridgeSource, "prepare-recharge-debt-repayment") === false) {
    fwrite(STDERR, "recharge repayment must not require an undeclared debt_record context\n");
    exit(1);
}
echo "RECHARGE_DEBT_REPAYMENT_CONTRACT_OK\n";
