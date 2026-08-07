<?php

$root = dirname(__DIR__, 3);
$read = static function (string $relative) use ($root): string {
    $source = file_get_contents($root . '/' . $relative);
    if ($source === false) throw new RuntimeException('cannot read ' . $relative);
    return $source;
};
$ok = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$discovery = $read('后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutBalanceAuthorityDiscovery.php');
$drafts = $read('后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutBalanceDraftServices.php');
$module = $read('后端代码/app/services/cashier/v3/cashier/CashierV3CashierModule.php');
$manifest = $read('后端代码/app/services/cashier/v3/manifest/CashierV3ActionManifest.php');
$c2Manifest = $read('后端代码/app/services/cashier/v3/manifest/CashierV3C2CashierModule.php');

$ok(
    strpos($discovery, "'accessMode' => 'read'") !== false,
    'balance draft discovery must be read-only before final submission preparation'
);
$ok(
    strpos($module, "'touched' => ['cashier_workspace', 'checkout_request']") !== false,
    'balance and sales-date drafts must touch only workspace and checkout request'
);
$ok(
    strpos($module, "registerCommand('update-checkout-sales-date'") !== false
        && strpos($module, 'registerCheckoutSalesDatePolicy($dispatcher)') !== false,
    'sales-date command handler and policy must both be registered'
);
$ok(
    strpos($manifest, "'update-checkout-sales-date' => \$eventless(\$checkoutPreparation)") !== false,
    'sales-date action must be registered before the production graph installs its handler'
);
$ok(
    strpos($c2Manifest, "'update-checkout-sales-date'") !== false,
    'sales-date action must be present in the canonical C2 command manifest'
);
$ok(
    strpos($drafts, "private const ACTION_UPDATE_SALES_DATE = 'update-checkout-sales-date'") !== false
        && strpos($drafts, "\$snapshot['businessDate'] = \$businessDate") !== false
        && strpos($drafts, "\$snapshot['paymentDetails'][\$index]['businessDate'] = \$businessDate") !== false,
    'sales date must rebuild both checkout and payment draft dates'
);
$ok(
    strpos($drafts, '历史销售日期必须填写补单原因。') !== false
        && strpos($drafts, '销售日期无效或晚于今天。') !== false,
    'sales date must reject future dates and require historical audit reason'
);

echo "PASS checkout sales-date and balance-draft contract\n";
