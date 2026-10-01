<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/settlement/CashierV3DebtRepaymentServices.php');
$module = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/cashier/CashierV3CashierModule.php');
$manifest = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/manifest/CashierV3ActionManifest.php');
$bridge = (string)file_get_contents($root . '/前端代码/cashier-v3/src/services/cashierV3Bridge.js');
require $root . '/后端代码/vendor/autoload.php';

use app\services\cashier\v3\settlement\CashierV3DebtRepaymentServices;

// 补交只在最终事务记账；静态契约防止旧准备命令和版本草稿入口复活。
foreach (['prepare-debt-repayment', 'prepare-recharge-debt-repayment'] as $action) {
    foreach ([$service, $module, $manifest, $bridge] as $source) {
        if (str_contains($source, $action)) throw new RuntimeException('obsolete prepare command: ' . $action);
    }
}
foreach (['cashier_v3_debt_repayment_draft', 'cashier_v3_checkout_line_draft',
    'lockAggregateForEditInTx', 'persistDraftInTx', 'lockReusableEditingDraft',
    'submitRechargeDebtThroughCheckout'] as $obsolete) {
    if (str_contains($service, $obsolete)) throw new RuntimeException('obsolete repayment draft branch: ' . $obsolete);
}
foreach (['checkoutSnapshot', 'lockDebtAuthority', 'cashier_v3_debt_repayment_collection',
    'debt.repaid', 'proportionalCumulativeAllocation', 'command_idempotency_key',
    'paymentLinesFromCheckoutSnapshot'] as $required) {
    if (!str_contains($service, $required)) throw new RuntimeException('missing final settlement guard: ' . $required);
}
if (!str_contains($module, "registerCommand('submit-debt-repayment'")
    || !str_contains($module, "registerCommand('submit-recharge-debt-repayment'")) {
    throw new RuntimeException('final repayment commands not registered');
}
$items = [['id' => 11, 'debt_amount' => '3.00'], ['id' => 12, 'debt_amount' => '7.00']];
if (CashierV3DebtRepaymentServices::proportionalCumulativeAllocation(500, $items) !== [11 => 150, 12 => 350]) {
    throw new RuntimeException('cumulative repayment allocation changed');
}
echo "SALES_DEBT_REPAYMENT_CONTRACT_OK\n";
