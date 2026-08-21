<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$module = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/member/CashierV3RechargeCheckoutModule.php'
);
$bootstrap = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/bootstrap/CashierV3Bootstrap.php'
);
$manifestModule = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/manifest/CashierV3C5MemberOrderModule.php'
);
$manifest = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/manifest/CashierV3ActionManifest.php'
);
$migration = (string)file_get_contents(
    $root . '/后端代码/database/upgrades/2026-08-05-收银V3充值统一结账/02-正式升级.sql'
);

$checks = [
    'recharge checkout has prepare edit and submit commands' => strpos($module, "'prepare-recharge-checkout'") !== false
        && strpos($module, "'update-recharge-checkout-payment-line'") !== false
        && strpos($module, "'reload-recharge-checkout'") !== false
        && strpos($module, "'submit-recharge-checkout'") !== false,
    'selected active members may recharge across historical store relations' => strpos($module, "where('store_id',\$operator->storeId())") === false
        && strpos($module, '充值就按当前登录门店归属创建') !== false,
    'draft writes are eventless while final submit reuses recharge authority' => strpos($module, 'eventless; only submit-recharge-checkout') !== false
        && strpos($module, '(new CashierV3RechargeModule())->submitInTx(') !== false,
    'payment edits lock the request version and reject totals above receivable' => strpos($module, 'private function lockEditing') !== false
        && strpos($module, 'recharge_checkout_payment_exceeds_due') !== false
        && strpos($module, 'recharge_checkout_payment_total_mismatch') !== false,
    'payment projection captures request status explicitly' => strpos($module, 'function(array $p) use ($accounting, $request):array') !== false,
    'recharge payment methods cannot be duplicated' => strpos($module, 'recharge_checkout_payment_method_duplicate') !== false
        && strpos($module, "'canAdd'=>!isset(\$selectedMethods[\$code])") !== false
        && strpos($module, 'private function hasDuplicatePaymentMethods') !== false,
    'recharge payment line identity stays stable across draft versions' => strpos($module, 'private function publicPaymentLineId') !== false
        && strpos($module, "'id'=>\$this->publicPaymentLineId") !== false
        && strpos($module, "hash_equals(\$this->publicPaymentLineId") !== false,
    'recharge source selection advances workspace only' => strpos($module, "self::SOURCE, ['cashier_workspace','member','member_balance','recharge_checkout_request'], ['cashier_workspace']") !== false
        && strpos($module, "'touched' => ['cashier_workspace']") !== false,
    'new context version provider and action module are installed' => strpos($bootstrap, 'CashierV3RechargeCheckoutRequestVersionProvider') !== false
        && strpos($bootstrap, 'CashierV3RechargeCheckoutModule::install') !== false,
    'reload command is registered with an explicit eventless contract' => strpos($manifestModule, "'reload-recharge-checkout'") !== false
        && strpos($manifest, "'reload-recharge-checkout' => \$eventless(\$checkoutPreparation)") !== false,
    'migration creates only dedicated recharge checkout draft tables' => strpos($migration, 'eb_cashier_v3_recharge_checkout_request') !== false
        && strpos($migration, 'eb_cashier_v3_recharge_checkout_payment_draft') !== false
        && stripos($migration, 'UPDATE ') === false
        && stripos($migration, 'DELETE ') === false,
];

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$passed) $failed++;
}
exit($failed === 0 ? 0 : 1);
