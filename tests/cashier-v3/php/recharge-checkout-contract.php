<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$read = static function (string $path) use ($root): string {
    return (string)file_get_contents($root . '/' . $path);
};
$module = $read('后端代码/app/services/cashier/v3/member/CashierV3RechargeCheckoutModule.php');
$recharge = $read('后端代码/app/services/cashier/v3/member/CashierV3RechargeModule.php');
$recordQuery = $read('后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php');
$bootstrap = $read('后端代码/app/services/cashier/v3/bootstrap/CashierV3Bootstrap.php');
$manifestModule = $read('后端代码/app/services/cashier/v3/manifest/CashierV3C5MemberOrderModule.php');
$manifest = $read('后端代码/app/services/cashier/v3/manifest/CashierV3ActionManifest.php');
$dispatcher = $read('后端代码/app/services/cashier/v3/CashierV3ActionDispatcher.php');
$source = $read('后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutBusinessSourceSelectionServices.php');
$contextPolicy = $read('后端代码/app/services/cashier/v3/registry/CashierV3ContextPolicy.php');
$migration = $read('后端代码/database/upgrades/2026-08-05-收银V3充值统一结账/02-正式升级.sql');
$removedActions = [
    'prepare-recharge-checkout', 'add-recharge-checkout-payment-method',
    'update-recharge-checkout-payment-line', 'remove-recharge-checkout-payment-line',
    'reload-recharge-checkout',
];

// 历史迁移文件是已经执行过的账务记录，不因删除运行时代码而改写或删库。
$checks = [
    '充值只注册最终快照命令' => str_contains($module, "'submit-recharge-checkout'")
        && str_contains($module, 'submitLocalSnapshotInTx')
        && str_contains($bootstrap, 'CashierV3RechargeCheckoutModule::install'),
    '充值旧草稿命令已从运行时移除' => array_reduce($removedActions, static function (bool $ok, string $action) use ($module, $manifestModule, $manifest, $dispatcher): bool {
        return $ok && !str_contains($module, $action) && !str_contains($manifestModule, $action)
            && !str_contains($manifest, $action) && !str_contains($dispatcher, $action);
    }, true),
    '充值提交不读写旧草稿表或请求版本' => !str_contains($module, 'Db::name(self::REQUEST_TABLE)')
        && !str_contains($module, 'Db::name(self::PAYMENT_TABLE)')
        && !str_contains($module, 'lockEditing')
        && !str_contains($module, 'checkoutRequestVersion')
        && !str_contains($bootstrap, 'CashierV3RechargeCheckoutRequestVersionProvider'),
    '最终事务锁定权威余额来源并校验收款合计' => str_contains($module, 'lockSnapshotInTx')
        && str_contains($module, 'resolveSourceSnapshot')
        && str_contains($module, 'paymentTotal($payments)')
        && str_contains($module, '(new CashierV3RechargeModule())->submitInTx('),
    '纯赠金充值无收款且保留必填备注' => str_contains($module, "\$principal + \$bonus <= 0")
        && str_contains($module, "\$principal === 0 && \$remark === ''")
        && str_contains($module, "'rechargeRemark' => \$input['rechargeRemark']")
        && str_contains($recharge, "'remarks' => \$input['rechargeRemark']")
        && str_contains($recharge, "'recharge_type' => \$input['principalCents'] === 0 ? 'bonus_only'")
        && str_contains($recordQuery, "'actualReceivedAmount'")
        && str_contains($recordQuery, "'bonus_only' ? '0.00'"),
    // 余额写入适配器采用封闭字段合同；审计备注只归属充值主单，不得塞进余额命令。
    '充值备注不污染余额写入合同' => preg_match('/creditRechargeInTx\(\[([\s\S]*?)\], \$operator, \$dataScope\)/', $recharge, $creditRequest) === 1
        && !str_contains((string)($creditRequest[1] ?? ''), "'rechargeRemark'"),
    '充值来源不再读取服务端编辑草稿' => !str_contains($source, 'cashier_v3_recharge_checkout_request')
        && !str_contains($source, 'captureRechargePreparationInTx'),
    '空上下文仅由充值最终快照放行' => str_contains($contextPolicy, "is_array(\$payload['checkoutSnapshot'] ?? null)")
        && str_contains($module, "'allows_empty_contexts' => true"),
    '历史数据库升级文件原样保留' => str_contains($migration, 'eb_cashier_v3_recharge_checkout_request')
        && str_contains($migration, 'eb_cashier_v3_recharge_checkout_payment_draft'),
];

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$passed) $failed++;
}
exit($failed === 0 ? 0 : 1);
