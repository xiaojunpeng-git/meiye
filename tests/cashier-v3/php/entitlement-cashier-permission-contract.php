<?php
declare(strict_types=1);

$root = is_dir('/var/www/html/app') ? '/var/www/html' : dirname(__DIR__, 3) . '/后端代码';
$adapter = file_get_contents($root . '/app/services/cashier/v3/checkout/CashierV3DirectSnapshotEntitlementSettlementServices.php');
$kernel = file_get_contents($root . '/app/services/cashier/v3/checkout/CashierV3EntitlementCompletionKernel.php');
$gateway = file_get_contents($root . '/app/services/cashier/v3/CashierV3CommandGatewayServices.php');
if ($adapter === false || $kernel === false || $gateway === false) {
    fwrite(STDERR, "FAIL: 无法读取权益完成权限源码\n");
    exit(1);
}

$checks = [
    '权益服务在收银或独立核销权限下均可执行' =>
        strpos($adapter, 'assertEntitlementCompletionFeature') !== false
        && strpos($adapter, "in_array('cashier.v3.cashier', \$features, true)") !== false
        && strpos($adapter, "in_array('cashier.v3.writeoff', \$features, true)") !== false,
    '权益完成内核使用任一授权组而非不可配置的双重门槛' =>
        strpos($kernel, 'REQUIRED_FEATURES_ANY_OF') !== false
        && strpos($kernel, 'array_intersect(self::REQUIRED_FEATURES_ANY_OF') !== false,
    '项目替换仍由独立核销能力控制' =>
        strpos($root === '' ? '' : file_get_contents(dirname($root) . '/前端代码/cashier-v3/src/layouts/CashierShell.vue'), "featureCode: 'cashier.v3.writeoff'") !== false,
    '未选择手艺人会返回明确前置提示而非资源发现失败' =>
        strpos($gateway, 'CashierV3EntitlementCompletionAuthorityException') !== false
        && strpos($gateway, "authority_service_intent_craftsman_required") !== false
        && strpos($gateway, '请先为本次服务选择手艺人后再确认完成服务。') !== false,
];

$failed = 0;
foreach ($checks as $name => $passed) {
    if ($passed) {
        echo "PASS: {$name}\n";
        continue;
    }
    fwrite(STDERR, "FAIL: {$name}\n");
    $failed++;
}
echo "ENTITLEMENT_CASHIER_PERMISSION_CONTRACT_FAILED={$failed}\n";
exit($failed === 0 ? 0 : 1);
