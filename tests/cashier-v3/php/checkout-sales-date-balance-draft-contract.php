<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$read = static function (string $relative) use ($root): string {
    $source = file_get_contents($root . '/' . $relative);
    if ($source === false) throw new RuntimeException('cannot read ' . $relative);
    return $source;
};

$manifest = $read('后端代码/app/services/cashier/v3/manifest/CashierV3ActionManifest.php');
$c2Manifest = $read('后端代码/app/services/cashier/v3/manifest/CashierV3C2CashierModule.php');
$module = $read('后端代码/app/services/cashier/v3/cashier/CashierV3CashierModule.php');
$frontend = $read('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue');

$legacyActions = [
    'add-payment-method', 'update-payment-line', 'remove-payment-line',
    'apply-balance-payment', 'remove-balance-payment', 'update-balance-payment',
    'update-checkout-business-source', 'update-checkout-sales-date',
    'return-to-payment-edit',
];
foreach ($legacyActions as $action) {
    if (strpos($manifest, "'{$action}' =>") !== false
        || strpos($c2Manifest, "'{$action}'") !== false
        || strpos($module, "registerCommand('{$action}'") !== false) {
        throw new RuntimeException("legacy checkout draft/version action remains: {$action}");
    }
}
foreach ([
    '结账编辑只保留在前端，必须通过最终快照一次性确认收款。',
    "if (action === 'submit-checkout' && isRecord(payload?.checkoutSnapshot))",
    'localCheckoutPreview.value?.localDraftPreview === true',
] as $needle) {
    if (strpos($frontend, $needle) === false) {
        throw new RuntimeException("local snapshot guard missing: {$needle}");
    }
}

echo "PASS local snapshot has no legacy checkout draft/version actions\n";
